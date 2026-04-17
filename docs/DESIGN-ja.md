# Optrion — プラグイン設計書

## 1. 概要

WordPress の `wp_options` テーブルには、プラグインやテーマが設定値を書き込むが、それらを停止・削除しても行が残り続ける。本プラグインは「どのオプションが、いつ、誰に読まれたか」を記録し、アクセス元・最終読み込み日時・autoload・サイズといった生シグナルを提示することで、管理者が安全にクリーンアップできる仕組みを提供する。

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
│         │  Classifier モジュール      │                │
│         │  (アクセス元推定)            │                │
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

一覧表示・判定時に `wp_options` から直接取得する情報:

- `option_value` → シリアライズ後のバイト数
- `autoload` → yes/no（WordPress 6.6 以降は `auto`, `on`, `off` も）

---

## 4. モジュール設計

### 4.1 Tracker（読み込み追跡モジュール）

#### 目的

`get_option()` が呼ばれるたびに「いつ・誰が」読んだかを記録する。

#### フック戦略

`get_option()` 1回ごとの呼び出し元を正確に識別できるのは `option_{$name}` フィルタだけなので、**全オプションに対して動的に `option_{$name}` フィルタを登録**する。autoload/非autoload の区別はしない。

| 対象 | フック | 説明 |
|---|---|---|
| すべての `wp_options` 行 | `option_{$name}` フィルタ（動的登録） | `plugins_loaded` 優先度 10 で全オプション名を取得し、ループで動的にフィルタ登録。`get_option()` のたびに発火するため、バックトレースから真の呼び出し元（プラグイン/テーマ）を正確に特定できる |

`alloptions` フィルタは**使用しない**。`wp_load_alloptions()` は 1 リクエスト中に何度も発火し、かつ「全 autoload オプションに単一の呼び出し元をまとめて attribution する」性質上、ある瞬間の呼び出し元（Yoast 等）が無関係のオプション（WooCommerce 等）にまで上書きされてしまうため。個別に `get_option()` されない autoload オプションは tracking テーブルに行が作られないが、`list_options` REST エンドポイントは `wp_options` を起点にアクセス元を推定するので UI 表示には影響しない（`tracking=null` の場合はプレフィックス照合にフォールバック）。

per-name フィルタを `plugins_loaded` 優先度 10 で登録するのは、Yoast SEO 等が `plugins_loaded` 優先度 14 で `get_option()` を呼ぶケースを取り逃がさないため（`admin_init` まで待つとそれらの読み込みが attribution されない）。

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
- 上記は管理画面の表示とアクセス元推定の両方に反映する

### 4.2 Classifier（アクセス元推定モジュール）

#### 目的

各オプション行の**アクセス元**（そのオプションを読み書きしているプラグイン・テーマ・WordPress コア・ウィジェット）を推定し、管理画面に「素の判断材料」として提示する。合成スコアは計算せず、生シグナル（アクセス元／最終読み込み／autoload／サイズ）を個別カラムで表示し、削除可否の判断はユーザーに委ねる。

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

#### active / inactive フラグ

推定したアクセス元がインストール済みプラグイン/テーマのスラッグと一致した場合、そのプラグイン/テーマが**現在有効化されているか**を判定して `active` フラグとして付加する（`core` と `widget` は常に active、`unknown` は常に inactive 扱い）。

#### 管理画面での扱い

- 一覧テーブルに accessor（表示名＋type＋inactive バッジ）、autoload バッジ、サイズ、最終読み込み日時を個別カラムで表示
- ソート: option_name / accessor（accessor.name → slug → type の優先順で表示ラベル昇順）/ size / last_read。autoload はバイナリバッジのためソート対象外
- フィルタ: accessor type、`inactive_only`（inactive なアクセス元のみ）、`autoload_only`（autoload=yes のみ）、`search`（option_name 部分一致）
- `_transient_*` / `_site_transient_*` と自プラグイン内部オプション（`optrion_*`, `_optrion_q__*`）は一覧から除外

### 4.3 Export / Import（バックアップモジュール）

#### エクスポート JSON フォーマット

```json
{
  "version": "1.1.0",
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
      }
    }
  ]
}
```

レガシーの `1.0.0` 形式（各エントリに `score` オブジェクトを含む）は Importer が互換で受け付け、`score` は無視される。

#### エクスポート仕様

- 対象: 選択したオプション（UI で絞り込み → 選択）または CLI での accessor 条件指定での一括
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
  確認ダイアログ（対象件数・必要なら事前エクスポートを促すリマインダー）
        │
        ▼
  wp_options から DELETE + tracking テーブルからも DELETE
        │
        ▼
  完了通知（削除件数）
```

#### サーバー側バックアップは作成しない（セキュリティ不変条件）

Optrion は **`option_value` の内容をサーバーのファイルシステムに書き出さない**。`wp_options` には API キー・SMTP 認証・決済ゲートウェイのシークレット・ライセンストークン等が入りうるため、`.htaccess` でガードしていても `wp-content/` はホスト全体バックアップや Web サーバー設定ミスで漏えいしやすい。よって削除前の「自動バックアップディレクトリ」を自動生成しない。

復元経路が欲しい場合、管理者が明示的に 1 アクション挟む:

- **管理 UI**: 対象行を選択し「選択項目をエクスポート」で JSON をブラウザダウンロード。ファイルはブラウザの保存先に置かれ、サーバーは一切感知しない。
- **WP-CLI**: `wp optrion export --names=...`（または `--accessor-type=...` / `--inactive-only`）。既定出力は stdout、`--output=<path>` でオペレーター指定のファイルに保存。どちらも**保存先は明示的に操作者が決める**。

`wp optrion clean` は `--i-have-a-backup` フラグなしでは実行を拒否する。オペレーターが事前バックアップを取った旨の明示的な同意。

#### 一括削除オプション

- 「無効化されたプラグイン/テーマに属するオプションをすべて削除」（`--inactive-only` / UI の inactive-only フィルタ）
- 「特定 accessor type に属するオプションをすべて削除」（`--accessor-type` / UI フィルタ）
- 「期限切れ Transient をすべて削除」

#### セーフガード

- WordPress コアオプション（既知リスト）は削除ボタンを無効化し、UI にロックアイコンを表示
- autoload 合計サイズの変動を削除前後で表示（「autoload データが 1.2MB → 0.8MB に削減」）
- 削除確認ダイアログで「復元用コピーが必要なら先にエクスポートしてください」のリマインダーを表示

### 4.5 Quarantine（検疫モード）

#### 目的

「たぶん不要だが、消すと何が壊れるかわからない」オプションを、**サイト挙動を変えずに**観察対象としてマークし、実際にアクセスされているかどうかを記録する仕組み。観察期間中に誰もアクセスしなければ安全に本削除、アクセスがあれば要復元として明示的にフラグを立てる。

#### 仕組み

`option_name` をリネーム（`wpseo_titles` → `_optrion_q__wpseo_titles`）しつつ、`pre_option_{original_name}` フィルタを動的登録してリネーム先から値を返す。結果として `get_option()` は検疫前と**同じ値**を返し、サイトは通常動作を続ける。フィルタ発火時にバックトレースでアクセス元を特定し、リクエスト末尾でマニフェスト行を更新する。

```
隔離時:
  wp_options:   wpseo_titles  →  _optrion_q__wpseo_titles (autoload=no)
  ランタイム:    add_filter('pre_option_wpseo_titles', 値を返すクロージャ)

復元時:
  wp_options:   _optrion_q__wpseo_titles  →  wpseo_titles (autoload 復元)
  ランタイム:    フィルタキャッシュから削除（クロージャは素通り）

本削除時:
  wp_options:   _optrion_q__wpseo_titles を DELETE
  ランタイム:    フィルタキャッシュから削除
```

リネーム時に `autoload=no` に変更するのは、リネーム済み行が alloptions に乗らないようにするため。値の提供は pre_option 経由なので autoload が不要になる。元の autoload 値はマニフェストに記録しておき、復元時に戻す。

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
| `status` | ENUM('active','restored','deleted') | 現在の状態 |
| `restored_at` | DATETIME NULL | 復元した日時 |
| `deleted_at` | DATETIME NULL | 本削除した日時 |
| `last_accessed_at` | DATETIME NULL | 検疫期間中に `get_option()` 経由でアクセスされた最終日時（pre_option フィルタが記録） |
| `accessor_during_quarantine` | VARCHAR(255) | 最後にアクセスしたプラグイン/テーマのスラッグ |
| `accessor_type_during_quarantine` | VARCHAR(20) | `plugin` / `theme` / `core` / `unknown` |
| `access_count_during_quarantine` | BIGINT UNSIGNED | 検疫中の累計アクセス回数 |
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
  サイトを通常運用（get_option は pre_option フィルタ経由で元の値を返す）
        │
        ├── 観察期間中にアクセスがあれば ──→ マニフェストに記録
        │                                  UI に「使用中・要復元」バッジ
        │                                  自動期限切れ処理の対象外となる
        │
        ├── 管理者が手動で復元 ──────────→ 「復元」ボタン
        │                                  option_name / autoload を元に戻す
        │                                  マニフェストの status を 'restored' に
        │
        ├── 観察期間中にアクセスなし ─────→ 「本削除」ボタンが有効
        │                                  リネームされた行を DELETE
        │                                  マニフェストの status を 'deleted' に
        │
        └── 期限切れ（アクセスなし・何もしなかった場合）
            ↓
           自動復元 or 自動削除（設定）
           管理画面に通知バナー表示
```

#### 期限と自動復元

- デフォルト検疫期間: **7日間**（設定画面で 1〜30日に変更可能）
- 期限切れ時の動作は選択式:
  - **自動復元**（デフォルト・安全）: 元に戻し、管理者に通知
  - **自動削除**（上級者向け）: JSON バックアップを作成した上で DELETE
  - **放置**（期限を無期限に）: 手動で判断するまで隔離状態を維持
- 自動処理は WordPress Cron（`optrion_quarantine_check`）で日次実行
- **アクセスがあった検疫は自動処理の対象外**: `last_accessed_at IS NOT NULL` の行は cron の対象クエリに含めない。サイトで使用中のオプションを自動復元/削除して挙動を揺らさないため、管理者が明示的に復元するまで active のまま保持される

#### 検疫の制限事項

- WordPress コアオプション（既知リスト）は検疫対象外（ロック表示）
- `active_plugins`, `template`, `stylesheet`, `cron` 等の重要オプションは追加で保護
- 一度に検疫できる上限: **50件**（大量の同時隔離による事故を防止）
- `_optrion_q__` プレフィックスが付いた option_name は合計191文字以内である必要がある。元の名前が178文字を超える場合は検疫不可とし、その旨をUIで表示

#### 検疫一覧の UI 表示

オプション一覧テーブルとは別に、「検疫中」タブを設ける:

| 列 | 内容 |
|---|---|
| 元の option_name | リネーム前の名前。検疫後のアクセスがあれば「使用中・要復元」バッジを表示 |
| 隔離日時 | いつ検疫したか |
| 残り期間 | 自動復元/削除までのカウントダウン（アクセスがあった行は対象外） |
| 最終アクセス | 検疫中に `get_option()` されていれば日時、なければ `—` |
| アクセス回数 | 検疫中の累計アクセス回数 |
| アクセス元 | 検疫中にアクセスしたプラグイン/テーマ名と type |
| 操作 | 「復元」「本削除」「期間延長」ボタン（アクセスがある行は本削除不可） |

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
| GET | `/options` | オプション一覧（accessor / tracking / autoload / size 付き） | `page`, `per_page`, `orderby`, `order`, `accessor_type`, `inactive_only`, `autoload_only`, `search` |
| GET | `/options/{name}` | 単一オプションの詳細 | — |
| DELETE | `/options` | 一括削除（サーバー側バックアップなし） | `names[]` |
| GET | `/stats` | サマリー統計（合計件数、autoload サイズ） | — |
| POST | `/export` | 選択オプションを JSON エクスポート | `names[]` |
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
      "is_autoload": true,
      "size": 15234,
      "size_human": "14.9 KB",
      "accessor": {
        "type": "plugin",
        "slug": "wordpress-seo",
        "name": "Yoast SEO",
        "active": true
      },
      "tracking": {
        "last_read_at": "2026-04-15 08:12:03",
        "read_count": 42,
        "last_reader": "wordpress-seo",
        "reader_type": "plugin"
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
| inactive アクセス元 | アクセス元が無効化されたプラグイン/テーマの件数 |
| 期限切れ Transient | 有効期限を過ぎた transient の件数 |
| 検疫中 | 現在隔離中のオプション件数（期限切れ間近のものは警告色） |

下部にチャート:

- **アクセス元別オプション数**: プラグイン/テーマごとの棒グラフ（有効/無効で色分け）

### 6.3 オプション一覧

データテーブル形式。各行に表示する列:

| 列 | 内容 |
|---|---|
| チェックボックス | 一括操作用（WordPress コアアクセス元の行は選択不可） |
| option_name | クリックで値のプレビューモーダルを表示 |
| アクセス元 | プラグイン/テーマ名 + type + 無効化されている場合は inactive バッジ |
| Autoload | autoload=yes の行にはバッジ、それ以外は薄色でraw値 |
| サイズ | バイト数（人間可読表記） |
| 最終読み込み | タイムスタンプ（未計測は —） |

フィルタバー:
- テキスト検索（option_name 部分一致）
- アクセス元種別（plugin / theme / widget / core / unknown）
- inactive のみ（アクセス元が無効化されているプラグイン/テーマのみ）
- autoload のみ
- WordPress-Core の行の表示/非表示

一括操作:
- 選択項目を検疫
- 選択項目を削除
- 選択項目をエクスポート

### 6.4 値プレビューモーダル

option_name をクリックすると表示:

- `option_value` の内容（配列/オブジェクトなら整形表示）
- accessor、autoload、サイズ、最終読み込み日時、read_count
- 「削除」「エクスポート」ボタン

### 6.5 エクスポート

エクスポート専用画面は存在しない。オプション一覧テーブルで選択した行を
一括操作バーの「Export selected」ボタンから JSON ファイルとしてダウンロードする。
accessor 条件での一括エクスポートは WP-CLI（`wp optrion export --accessor-type=<type>` や
`wp optrion export --inactive-only`）を利用する。

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
| 機密データの永続化 | **Optrion は `option_value` の内容をサーバーのファイルシステムに書き出さない**。`Cleaner::delete()` はバックアップを作成せず、エクスポートは管理 UI からのブラウザダウンロード or 操作者指定の CLI 出力のみ。`wp-content/optrion-backups/` / 一時ファイル / キャッシュも一切作らない。§4.3 / §4.4 参照。 |
| インポート検証 | JSON スキーマバリデーション。version フィールドの存在確認。option_name の文字種チェック（英数字・アンダースコア・ハイフンのみ） |
| コアオプション保護 | 既知のコアオプション約60個はハードコードしたリストで DELETE を拒否 |

---

## 8. パフォーマンス設計

| 懸念点 | 対策 |
|---|---|
| `debug_backtrace` のコスト | フレーム数を15に制限。IGNORE_ARGS フラグで引数コピーを抑制 |
| 毎リクエストの DB 書き込み | メモリバッファ → shutdown で1回の upsert |
| `option_{$name}` フック登録 | `plugins_loaded` 優先度 10 で一度だけ実行。フロントエンドでは登録しない（tracking 無効時は早期 return） |
| 大量オプション（数千行）の一覧取得 | REST API でページネーション（デフォルト50件/ページ）。accessor 推定はリクエスト時にオンデマンド |
| 追跡のオーバーヘッド | Transient フラグで有効/無効を制御。サンプリングレート設定（設定画面で 1–100% を指定） |

---

## 9. WP-CLI 対応

管理画面を使わない運用向けに、WP-CLI サブコマンドも提供する。

```bash
# オプション一覧（accessor / autoload / size / last_read 列）
wp optrion list --format=table

# 無効化されたプラグイン/テーマが所有するオプションだけ表示
wp optrion list --inactive-only

# accessor タイプで絞り込み
wp optrion list --accessor-type=plugin

# 統計サマリー
wp optrion stats

# 無効化されたプラグイン/テーマのオプションを JSON エクスポート
wp optrion export --inactive-only --output=backup.json

# 指定名の一括エクスポート
wp optrion export --names=opt_a,opt_b --output=backup.json

# JSON インポート（ドライラン）
wp optrion import backup.json --dry-run

# JSON インポート（実行）
wp optrion import backup.json

# 無効化されたプラグイン/テーマのオプションを一括削除（サーバー側バックアップなし、事前に export 必須）
wp optrion clean --inactive-only --i-have-a-backup --yes

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
│   ├── class-classifier.php        # Classifier モジュール（アクセス元推定）
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
│       ├── AccessorBadge.jsx
│       ├── OptionPreviewModal.jsx
│       └── AccessorChart.jsx
│
├── languages/
│   └── optrion-ja.po
│
└── tests/
    ├── test-classifier.php
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
| **アンインストール** | カスタムテーブル DROP、プラグイン自身のオプション削除（皮肉にならないよう確実に）、cron 解除 |

---

## 12. 今後の拡張案

- **差分レポートメール**: 週次で「新規に検出された不要オプション」をメール通知
- **マルチサイト対応**: `wp_sitemeta` テーブルの同等スキャン
- **REST API ログ連携**: Query Monitor 等の開発ツールとの統合
- **オプションサイズの時系列推移**: autoload 合計サイズを日次で記録し、肥大化傾向をグラフ化
- **ホワイトリスト管理**: 「このオプションは残す」と明示的にマークし、一覧からピン留め／除外できる機能
