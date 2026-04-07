# Optrion — プラグイン設計書

## 1. 概要

WordPress の `wp_options` テーブルには、プラグインやテーマが設定値を書き込むが、それらを停止・削除しても行が残り続ける。本プラグインは「どのオプションが、いつ、誰に読まれたか」を記録し、不要度をスコアリングすることで、管理者が安全にクリーンアップできる仕組みを提供する。

### 解決する課題

- 無効化・削除済みプラグイン/テーマのオプションがゴミとして残留する
- `autoload = yes` の肥大化によるページロード時間の悪化
- どのオプションが安全に消せるか判断する手段がない
- 消してしまった後の復旧手段がない

### 基本方針

- **観察 → 判断 → 検疫 → 削除** の4段階で安全に運用できる設計
- 追跡はサンプリング制御し、本番サイトのパフォーマンスを犠牲にしない
- 削除の前に「検疫（リネームによる一時無効化）」を挟み、影響を事前確認できる
- 削除前に必ず JSON エクスポートを挟むことで復旧可能性を担保

---

## 2. アーキテクチャ全体図

```
┌─────────────────────────────────────────────────────┐
│                    WordPress Core                    │
│                                                     │
│  get_option()  ──→  option_{$name} フィルタ          │
│  alloptions    ──→  alloptions フィルタ              │
│                        │                             │
│                        ▼                             │
│              ┌──────────────────┐                    │
│              │  Tracker モジュール │                   │
│              │  (読み込み追跡)    │                    │
│              └────────┬─────────┘                    │
│                       │ shutdown 時バッチ書込          │
│                       ▼                              │
│         ┌───────────────────────────┐                │
│         │  wp_options_tracking テーブル │               │
│         │  (カスタムテーブル)           │               │
│         └──────────┬────────────────┘                │
│                    │                                 │
│         ┌──────────▼────────────────┐                │
│         │  Scorer モジュール          │                │
│         │  (不要度スコアリング)        │                │
│         └──────────┬────────────────┘                │
│                    │                                 │
│                    ├──────────────────────┐          │
│                    ▼                      ▼          │
│         ┌────────────────┐   ┌─────────────────┐    │
│         │ Cleaner (削除)  │   │ Quarantine (検疫) │   │
│         └────────────────┘   │ リネームで一時隔離  │   │
│                              │ 期限付き自動復元    │   │
│                              └─────────────────┘    │
│                    │                                 │
│         ┌──────────▼────────────────┐                │
│         │  REST API エンドポイント    │                │
│         │  /wp-json/optrion/v1/*       │                │
│         └──────────┬────────────────┘                │
│                    │                                 │
└────────────────────┼────────────────────────────────┘
                     │
          ┌──────────▼────────────────┐
          │   管理画面ダッシュボード     │
          │   (React SPA)              │
          │   一覧 / 検疫 / 削除 /      │
          │   エクスポート / インポート   │
          └────────────────────────────┘
```

---

## 3. データベース設計

### 3.1 カスタムテーブル: `{prefix}_options_tracking`

wp_options 自体は改変せず、追跡情報を別テーブルで管理する。

| カラム | 型 | 説明 |
|---|---|---|
| `option_name` | VARCHAR(191) PK | wp_options の option_name と 1:1 対応 |
| `last_read_at` | DATETIME NULL | 最後に `get_option()` で読み込まれた日時 |
| `read_count` | BIGINT UNSIGNED | 累計読み込み回数（追跡有効期間中） |
| `last_reader` | VARCHAR(255) | 最後に読み込んだプラグイン/テーマのスラッグ |
| `reader_type` | ENUM('plugin','theme','core','unknown') | 読み込み元の種別 |
| `first_seen` | DATETIME | このテーブルに初めて記録された日時 |

インデックス: `last_read_at`, `reader_type`, `read_count`

### 3.2 カスタムテーブル: `{prefix}_options_quarantine`

検疫中オプションの管理テーブル。詳細は「4.5 Quarantine」セクションを参照。

### 3.3 wp_options 側の参照カラム（既存・読み取り専用）

スコアリング時に `wp_options` から直接取得する情報:

- `option_value` → シリアライズ後のバイト数
- `autoload` → yes/no（WordPress 6.6 以降は `auto`, `on`, `off` も）

---

## 4. モジュール設計

### 4.1 Tracker（読み込み追跡モジュール）

#### 目的

`get_option()` が呼ばれるたびに「いつ・誰が」読んだかを記録する。

#### フック戦略

WordPress には全オプション共通の `pre_option` フックが存在しないため、2系統でカバーする。

| 対象 | フック | 説明 |
|---|---|---|
| autoload=yes のオプション群 | `alloptions` フィルタ | WordPress が全 autoload オプションをまとめて読む時点で一括検知 |
| autoload=no の個別オプション | `option_{$name}` フィルタ（動的登録） | admin_init 時に非 autoload オプション名一覧を取得し、ループで動的にフィルタ登録 |

#### 呼び出し元の特定

`debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 15)` で呼び出しスタックをたどり、ファイルパスから所属を判定する。

```
判定ロジック:
  ファイルパスが WP_PLUGIN_DIR 以下  → type=plugin, slug=ディレクトリ名
  ファイルパスが get_theme_root() 以下 → type=theme,  slug=ディレクトリ名
  上記いずれでもない                  → type=core
```

#### パフォーマンス制御

- 追跡はメモリ上にバッファし、`shutdown` アクションで一括 DB 書き込み（1リクエスト1回のI/O）
- `ON DUPLICATE KEY UPDATE` で upsert し、クエリ数を最小化
- 追跡有効/無効は Transient フラグで制御。管理画面アクセス時に自動的に10分間有効化
- WP-CLI やCron では無効化（`DOING_CRON` 定数で判定）
- 本番運用時はサンプリングレート（例: 10%のリクエストのみ追跡）をオプション設定に追加

#### 追跡の限界（設計上の前提）

- 常時追跡ではないため read_count は「追跡期間中の近似値」である
- `last_read_at = NULL` は「読み込みが記録されていない」であり「一度も読まれていない」ではない
- この前提はスコアリングロジックと管理画面の表示の両方に反映する

### 4.2 Scorer（スコアリングモジュール）

#### 目的

各オプション行の「不要である可能性」を 0–100 の数値で表す。

#### スコア算出ルール

| 評価軸 | 最大点 | 条件 |
|---|---|---|
| **アクセス元の状態** | 40 | アクセス元プラグインが無効=40 / アクセス元テーマが無効=40 / アクセス元不明=20 / 有効=0 |
| **最終読み込みの鮮度** | 25 | 90日以上前: 5点/30日ごとに加算（上限25） / 記録なし=25 |
| **Transient 判定** | 10 | `_transient_` or `_site_transient_` プレフィックス=10 |
| **Autoload 浪費** | 15 | `autoload=yes` かつ `read_count=0`（追跡期間中）=15 |
| **データサイズ** | 10 | 100KB超=10 / 10KB超=5 / それ以下=0 |

合計を `min(100, total)` でクリップする。

#### アクセス元推定ロジック

`get_option()` の PHP バックトレースおよび option_name のプレフィックスから、最終アクセス元を推定する。

```
推定の優先順位:
  1. WordPress コアオプションの既知リストとの照合（確定的シグナル）
  2. widget_ プレフィックスによるウィジェット判定（確定的シグナル）
  3. Tracker の last_reader（実測データ。reader_type が plugin/theme の場合のみ採用）
  4. option_name プレフィックスとプラグインスラッグの前方一致
  5. option_name プレフィックスとテーマスラッグの前方一致
  6. いずれにも該当しない → unknown
```

コアオプションの既知リストは WordPress Codex の Options Reference に準拠し、siteurl, home, blogname, active_plugins, template, stylesheet, cron, rewrite_rules 等の約60個をハードコードする。

#### スコア区分ラベル

| スコア範囲 | ラベル | 色 | 推奨アクション |
|---|---|---|---|
| 0–19 | 安全 | 緑 | 放置 |
| 20–49 | 要確認 | 黄 | 定期的に再確認 |
| 50–79 | 不要の可能性が高い | 橙 | まず検疫 → 問題なければ本削除 |
| 80–100 | ほぼ確実に不要 | 赤 | 検疫 or エクスポート後に即削除 |

### 4.3 Export / Import（バックアップモジュール）

#### エクスポート JSON フォーマット

```json
{
  "version": "1.0.0",
  "exported_at": "2026-04-05T12:00:00+09:00",
  "site_url": "https://example.com",
  "wp_version": "6.8",
  "options": [
    {
      "option_name": "some_plugin_setting",
      "option_value": "serialized_or_raw_value",
      "autoload": "yes",
      "tracking": {
        "last_read_at": "2025-12-01 10:30:00",
        "read_count": 42,
        "last_reader": "some-plugin",
        "reader_type": "plugin"
      },
      "score": {
        "total": 75,
        "reasons": [
          "プラグイン「some-plugin」は無効 (+40)",
          "最終読み込み 120 日前 (+20)",
          "autoload=yes だが未読 (+15)"
        ]
      }
    }
  ]
}
```

#### エクスポート仕様

- 対象: 選択したオプション or スコア閾値以上を一括
- ファイル名: `optrion-export-{site}-{date}.json`
- 値はシリアライズされた状態のまま保存（復元時にそのまま INSERT できるように）

#### インポート仕様

- JSON を読み込み、`option_name` が存在しない場合のみ INSERT（既存値は上書きしない）
- 上書きモード（既存値を復元で上書き）はチェックボックスで明示的に選択
- インポート前にドライランを表示（追加/上書き/スキップの件数プレビュー）
- tracking データは参考として表示するが、復元時にはトラッキングテーブルには書き込まない

### 4.4 Cleaner（削除モジュール）

#### 削除フロー

```
管理者が削除対象を選択
        │
        ▼
  自動エクスポート（JSON を wp-content/optrion-backups/ に保存）
        │
        ▼
  確認ダイアログ（対象件数・スコア内訳を表示）
        │
        ▼
  wp_options から DELETE + tracking テーブルからも DELETE
        │
        ▼
  完了通知（削除件数 + バックアップファイルパスを表示）
```

#### 一括削除オプション

- 「スコア N 以上をすべて削除」
- 「期限切れ Transient をすべて削除」
- 「特定プラグイン/テーマに属するオプションをすべて削除」

#### セーフガード

- WordPress コアオプション（既知リスト）は削除ボタンを無効化し、UI にロックアイコンを表示
- autoload 合計サイズの変動を削除前後で表示（「autoload データが 1.2MB → 0.8MB に削減」）
- 直近バックアップ3世代を `wp-content/optrion-backups/` に保持。4世代目以降は古い順に自動削除

### 4.5 Quarantine（検疫モード）

#### 目的

「たぶん不要だが、消すと何が壊れるかわからない」オプションを、削除せずに一時的に無効化して影響を確認する仕組み。問題が出れば即座に復元でき、問題がなければそのまま本削除へ進める。

#### 仕組み

`option_name` をリネームすることで、WordPress コアや該当プラグインからは「存在しない」状態にする。値・autoload 設定はそのまま DB 上に残る。

```
隔離時:
  wpseo_titles  →  _optrion_q__wpseo_titles

復元時:
  _optrion_q__wpseo_titles  →  wpseo_titles
```

リネームと同時に `autoload` を `no` に変更し、隔離中のオプションがメモリを消費しないようにする。元の autoload 値はマニフェストに記録しておき、復元時に戻す。

#### 検疫マニフェスト

隔離中のオプションを管理する専用テーブル `{prefix}_options_quarantine`:

| カラム | 型 | 説明 |
|---|---|---|
| `id` | BIGINT AUTO_INCREMENT PK | 検疫 ID |
| `original_name` | VARCHAR(191) UNIQUE | 元の option_name |
| `original_autoload` | VARCHAR(20) | 隔離前の autoload 値（復元用） |
| `quarantined_at` | DATETIME | 隔離した日時 |
| `expires_at` | DATETIME | 自動復元の期限（デフォルト: 7日後） |
| `quarantined_by` | BIGINT | 操作した管理者の user ID |
| `score_at_quarantine` | INT | 隔離時点のスコア |
| `status` | ENUM('active','restored','deleted') | 現在の状態 |
| `restored_at` | DATETIME NULL | 復元した日時 |
| `deleted_at` | DATETIME NULL | 本削除した日時 |
| `notes` | TEXT | 管理者メモ（任意） |

#### 操作フロー

```
管理者がオプションを選択して「検疫」を実行
        │
        ▼
  wp_options 上で option_name をリネーム
  autoload を 'no' に変更
  マニフェストに記録（元の名前・autoload・期限）
        │
        ▼
  サイトを通常運用（隔離されたオプションは get_option で取得不可）
        │
        ├── 問題が発生した場合 ──→ 「復元」ボタン
        │                          option_name を元に戻す
        │                          autoload を元に戻す
        │                          マニフェストの status を 'restored' に
        │
        ├── 問題なし・確信を得た ──→ 「本削除」ボタン
        │                          リネームされた行を DELETE
        │                          マニフェストの status を 'deleted' に
        │
        └── 期限切れ（何もしなかった場合）
            ↓
           自動復元（安全側に倒す）
           管理画面に通知バナー表示
           「7日間問題なかったので本削除を検討してください」
```

#### 期限と自動復元

- デフォルト検疫期間: **7日間**（設定画面で 1〜30日に変更可能）
- 期限切れ時の動作は選択式:
  - **自動復元**（デフォルト・安全）: 元に戻し、管理者に通知
  - **自動削除**（上級者向け）: JSON バックアップを作成した上で DELETE
  - **放置**（期限を無期限に）: 手動で判断するまで隔離状態を維持
- 自動処理は WordPress Cron（`optrion_quarantine_check`）で日次実行

#### 検疫の制限事項

- WordPress コアオプション（既知リスト）は検疫対象外（ロック表示）
- `active_plugins`, `template`, `stylesheet`, `cron` 等の重要オプションは追加で保護
- 一度に検疫できる上限: **50件**（大量の同時隔離による事故を防止）
- `_optrion_q__` プレフィックスが付いた option_name は合計191文字以内である必要がある。元の名前が178文字を超える場合は検疫不可とし、その旨をUIで表示

#### 検疫一覧の UI 表示

オプション一覧テーブルとは別に、「検疫中」タブを設ける:

| 列 | 内容 |
|---|---|
| 元の option_name | リネーム前の名前 |
| アクセス元 | プラグイン/テーマ名 |
| 隔離日時 | いつ検疫したか |
| 残り期間 | 自動復元/削除までのカウントダウン |
| 隔離時スコア | 検疫実行時のスコア |
| メモ | 管理者が付けたメモ（編集可能） |
| 操作 | 「復元」「本削除」「期間延長」ボタン |

管理画面のヘッダーに常時表示するバッジ: 「検疫中: N件」

#### WP-CLI 対応

```bash
# オプションを検疫（デフォルト7日間）
wp optrion quarantine wpseo_titles wpseo_social --days=14

# 検疫中オプション一覧
wp optrion quarantine list

# 復元
wp optrion quarantine restore wpseo_titles

# 検疫から本削除
wp optrion quarantine delete wpseo_titles --yes

# 期限切れチェック（Cron と同等の手動実行）
wp optrion quarantine check-expiry
```

---

## 5. REST API 設計

ベース: `/wp-json/optrion/v1`

権限: すべてのエンドポイントで `manage_options` 権限を要求。

| メソッド | パス | 説明 | 主なパラメータ |
|---|---|---|---|
| GET | `/options` | オプション一覧（追跡情報・スコア付き） | `page`, `per_page`, `orderby`, `order`, `score_min`, `score_max`, `accessor_type`, `search` |
| GET | `/options/{name}` | 単一オプションの詳細 | — |
| DELETE | `/options` | 一括削除（自動バックアップ付き） | `names[]` |
| GET | `/stats` | サマリー統計（合計件数、autoload サイズ、スコア分布） | — |
| POST | `/export` | 選択オプションを JSON エクスポート | `names[]` or `score_min` |
| POST | `/import` | JSON インポート | `file`（multipart）, `overwrite`（bool） |
| POST | `/import/preview` | インポートのドライラン | `file`（multipart） |
| POST | `/scan` | トラッキングの手動スナップショット実行 | — |
| POST | `/quarantine` | 選択オプションを検疫（リネーム） | `names[]`, `days`（期限日数） |
| GET | `/quarantine` | 検疫中オプション一覧 | `status`（active/restored/deleted） |
| POST | `/quarantine/restore` | 検疫から復元 | `names[]` |
| DELETE | `/quarantine` | 検疫から本削除 | `names[]` |
| PATCH | `/quarantine/{name}` | 期間延長・メモ更新 | `days`, `notes` |

### レスポンス例: `GET /options`

```json
{
  "items": [
    {
      "option_name": "wpseo_titles",
      "autoload": "yes",
      "size": 15234,
      "size_human": "14.9 KB",
      "accessor": {
        "slug": "wordpress-seo",
        "type": "plugin",
        "active": false
      },
      "tracking": {
        "last_read_at": null,
        "read_count": 0,
        "last_reader": "",
        "reader_type": "unknown"
      },
      "score": {
        "total": 80,
        "label": "ほぼ確実に不要",
        "reasons": ["..."]
      }
    }
  ],
  "total": 342,
  "autoload_total_size": 1258000,
  "autoload_total_size_human": "1.2 MB"
}
```

---

## 6. 管理画面 UI 設計

### 6.1 画面構成

WordPress 管理メニューにトップレベル項目として「Optrion」を追加（専用ロゴアイコン付き）。

```
┌──────────────────────────────────────────────────────────────────┐
│  Optrion                                                │
├──────────────┬────────────────┬────────┬─────────────────────────┤
│ ダッシュボード │ オプション一覧 │ 検疫中 │ インポート              │
└──────────────┴────────────────┴────────┴─────────────────────────┘
```

エクスポートは独立タブを持たず、オプション一覧テーブルの一括操作として
「Export selected」ボタンから実行する。

### 6.2 ダッシュボード

サマリーカード5枚を上部に横並び表示:

| カード | 表示内容 |
|---|---|
| 総オプション数 | `wp_options` の総行数 |
| Autoload サイズ | `autoload=yes` の合計バイト数 |
| 不要の可能性（スコア50+） | 該当件数とそのサイズ合計 |
| 期限切れ Transient | 有効期限を過ぎた transient の件数 |
| 検疫中 | 現在隔離中のオプション件数（期限切れ間近のものは警告色） |

下部にチャート2種:

- **スコア分布ヒストグラム**: 横軸=スコア区分、縦軸=件数
- **アクセス元別オプション数**: プラグイン/テーマごとの棒グラフ（有効/無効で色分け）

### 6.3 オプション一覧

データテーブル形式。各行に表示する列:

| 列 | 内容 |
|---|---|
| チェックボックス | 一括操作用 |
| option_name | クリックで値のプレビューモーダルを表示 |
| アクセス元 | プラグイン/テーマ名 + 有効/無効バッジ |
| Autoload | yes/no バッジ |
| サイズ | バイト数（人間可読表記） |
| 最終読み込み | 相対日時（例: 「3ヶ月前」） |
| 読み込み回数 | 数値 |
| スコア | 数値 + 色付きバー |
| 操作 | 検疫ボタン / 削除ボタン / エクスポートボタン |

フィルタバー:
- テキスト検索（option_name 部分一致）
- スコア範囲スライダー
- アクセス元種別（プラグイン / テーマ / コア / 不明）
- Autoload（yes / no / すべて）

一括操作:
- 選択項目を検疫
- 選択項目を削除
- 選択項目をエクスポート

### 6.4 値プレビューモーダル

option_name をクリックすると表示:

- `option_value` の内容（配列/オブジェクトなら整形表示）
- スコアの内訳（reason の一覧）
- 読み込み履歴の概要
- 「削除」「エクスポート」ボタン

### 6.5 エクスポート

エクスポート専用画面は存在しない。オプション一覧テーブルで選択した行を
一括操作バーの「Export selected」ボタンから JSON ファイルとしてダウンロードする。
スコア閾値でのエクスポートは WP-CLI（`wp optrion export --score-min=N`）を利用する。

### 6.6 インポート画面

- JSON ファイルをアップロード
- ドライラン結果をテーブルで表示（追加 / 上書き / スキップの件数と一覧）
- 上書きモードの ON/OFF
- 「インポート実行」→ 結果サマリー表示

---

## 7. セキュリティ設計

| 観点 | 対策 |
|---|---|
| 権限 | 全操作に `manage_options` ケーパビリティ必須 |
| CSRF | REST API は WordPress 標準の nonce 認証（`X-WP-Nonce`） |
| SQL インジェクション | `$wpdb->prepare()` を全クエリで使用 |
| ファイル操作 | バックアップディレクトリに `.htaccess` で直接アクセス禁止 |
| インポート検証 | JSON スキーマバリデーション。version フィールドの存在確認。option_name の文字種チェック（英数字・アンダースコア・ハイフンのみ） |
| コアオプション保護 | 既知のコアオプション約60個はハードコードしたリストで DELETE を拒否 |

---

## 8. パフォーマンス設計

| 懸念点 | 対策 |
|---|---|
| `debug_backtrace` のコスト | フレーム数を15に制限。IGNORE_ARGS フラグで引数コピーを抑制 |
| 毎リクエストの DB 書き込み | メモリバッファ → shutdown で1回の upsert |
| 非 autoload オプションのフック登録 | admin_init 時のみ実行。フロントエンドでは登録しない |
| 大量オプション（数千行）の一覧取得 | REST API でページネーション（デフォルト50件/ページ）。スコア計算はリクエスト時にオンデマンド |
| 追跡のオーバーヘッド | Transient フラグで有効/無効を制御。サンプリングレート設定（設定画面で 1–100% を指定） |

---

## 9. WP-CLI 対応

管理画面を使わない運用向けに、WP-CLI サブコマンドも提供する。

```bash
# オプション一覧（スコア付き、閾値フィルタ）
wp optrion list --score-min=50 --format=table

# 統計サマリー
wp optrion stats

# スコア50以上を JSON エクスポート
wp optrion export --score-min=50 --output=backup.json

# JSON インポート（ドライラン）
wp optrion import backup.json --dry-run

# JSON インポート（実行）
wp optrion import backup.json

# スコア80以上を一括削除（自動バックアップ付き）
wp optrion clean --score-min=80 --yes

# 期限切れ Transient 一括削除
wp optrion clean-transients

# 手動スキャン実行
wp optrion scan
```

---

## 10. ファイル構成

```
optrion/
├── optrion.php          # メインプラグインファイル（ブートストラップ）
├── readme.txt                      # WordPress.org 形式の readme
├── uninstall.php                   # アンインストール時のクリーンアップ
│
├── includes/
│   ├── class-tracker.php           # Tracker モジュール
│   ├── class-scorer.php            # Scorer モジュール
│   ├── class-exporter.php          # Export 機能
│   ├── class-importer.php          # Import 機能
│   ├── class-cleaner.php           # 削除処理
│   ├── class-quarantine.php        # 検疫モード
│   ├── class-rest-controller.php   # REST API 定義
│   ├── class-admin-page.php        # 管理画面の登録・アセット読み込み
│   ├── class-cli-command.php       # WP-CLI サブコマンド
│   └── core-options-list.php       # コアオプションの既知リスト（配列定数）
│
├── assets/
│   ├── js/
│   │   └── admin-app.js            # React ダッシュボード（ビルド済み）
│   └── css/
│       └── admin.css               # 管理画面用スタイル
│
├── src/                            # React ソース（ビルド前）
│   ├── App.jsx
│   ├── pages/
│   │   ├── Dashboard.jsx
│   │   ├── OptionsList.jsx
│   │   ├── Quarantine.jsx
│   │   ├── Export.jsx
│   │   └── Import.jsx
│   └── components/
│       ├── ScoreBadge.jsx
│       ├── AccessorBadge.jsx
│       ├── OptionPreviewModal.jsx
│       ├── ScoreChart.jsx
│       └── AccessorChart.jsx
│
├── languages/
│   └── optrion-ja.po
│
└── tests/
    ├── test-scorer.php
    ├── test-tracker.php
    └── test-exporter.php
```

---

## 11. ライフサイクル

| イベント | 処理内容 |
|---|---|
| **有効化** | カスタムテーブル作成（tracking + quarantine）。全オプションの初回スナップショット |
| **日常運用** | 管理画面アクセス時に追跡を自動有効化。shutdown でバッチ記録 |
| **無効化** | Cron ジョブの解除のみ。テーブル・データは保持 |
| **アンインストール** | カスタムテーブル DROP。バックアップディレクトリ削除。プラグイン自身のオプション削除（皮肉にならないよう確実に） |

---

## 12. 今後の拡張案

- **差分レポートメール**: 週次で「新規に検出された不要オプション」をメール通知
- **マルチサイト対応**: `wp_sitemeta` テーブルの同等スキャン
- **REST API ログ連携**: Query Monitor 等の開発ツールとの統合
- **オプションサイズの時系列推移**: autoload 合計サイズを日次で記録し、肥大化傾向をグラフ化
- **ホワイトリスト管理**: 「このオプションは残す」と明示的にマークし、スコアリング対象外にする機能
