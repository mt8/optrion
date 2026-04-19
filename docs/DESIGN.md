# Optrion — Plugin Design

## 1. Overview

WordPress' `wp_options` table accumulates rows written by plugins and themes, and those rows stay behind even after the owning plugin or theme is deactivated or deleted. Optrion records "who read which option, and when", then surfaces the raw signals — accessor, last read time, `autoload`, and size — so administrators can clean up the table safely.

### Problems it solves

- Orphaned options left by deactivated or deleted plugins and themes.
- Slower page loads caused by `autoload = yes` bloat.
- No built-in way to decide which options are safe to remove.
- No safety net once an option is deleted.

### Guiding principles

- Four-stage workflow for safe operation: **observe → judge → quarantine → delete**.
- Tracking is sampled so production sites keep their performance budget.
- Quarantine (soft-disable via rename) sits in front of permanent deletion so the impact can be verified first.
- Every deletion is preceded by a JSON export so the row can be restored later.

---

## 2. Architecture overview

```
┌─────────────────────────────────────────────────────┐
│                    WordPress Core                    │
│                                                     │
│  get_option()  ──→  option_{$name} filter            │
│  alloptions    ──→  alloptions filter                │
│                        │                             │
│                        ▼                             │
│              ┌──────────────────┐                    │
│              │  Tracker module   │                   │
│              │  (read tracking)  │                   │
│              └────────┬─────────┘                    │
│                       │ batched write on shutdown    │
│                       ▼                              │
│         ┌───────────────────────────┐                │
│         │ wp_options_tracking table │                │
│         │ (custom table)            │                │
│         └──────────┬────────────────┘                │
│                    │                                 │
│         ┌──────────▼────────────────┐                │
│         │  Classifier module        │                │
│         │  (accessor inference)     │                │
│         └──────────┬────────────────┘                │
│                    │                                 │
│                    ├──────────────────────┐          │
│                    ▼                      ▼          │
│         ┌────────────────┐   ┌─────────────────┐    │
│         │ Cleaner (del.) │   │ Quarantine      │    │
│         └────────────────┘   │ rename + expiry │    │
│                              │ auto-restore    │    │
│                              └─────────────────┘    │
│                    │                                 │
│         ┌──────────▼────────────────┐                │
│         │  REST API endpoints       │                │
│         │  /wp-json/optrion/v1/*    │                │
│         └──────────┬────────────────┘                │
│                    │                                 │
└────────────────────┼────────────────────────────────┘
                     │
          ┌──────────▼────────────────┐
          │   Admin dashboard         │
          │   (React SPA)             │
          │   list / quarantine /     │
          │   delete / export / import │
          └────────────────────────────┘
```

---

## 3. Database design

### 3.1 Custom table: `{prefix}_options_tracking`

`wp_options` is never modified; tracking data lives in a separate table.

| Column | Type | Description |
|---|---|---|
| `option_name` | VARCHAR(191) PK | 1:1 with `wp_options.option_name` |
| `last_read_at` | DATETIME NULL | Last time `get_option()` was called for this row |
| `read_count` | BIGINT UNSIGNED | Cumulative read count within the tracking window |
| `last_reader` | VARCHAR(255) | Slug of the plugin or theme that read the option most recently |
| `reader_type` | ENUM('plugin','theme','core','unknown') | Reader kind |
| `first_seen` | DATETIME | When this row was first recorded |

Indexes: `last_read_at`, `reader_type`, `read_count`.

### 3.2 Custom table: `{prefix}_options_quarantine`

Manages quarantined options. See §4.5 Quarantine for details.

### 3.3 Columns read from `wp_options` (existing, read-only)

The admin list and the inference pipeline pull these directly from `wp_options`:

- `option_value` → serialized-byte size.
- `autoload` → yes/no (WordPress 6.6+ also: `auto`, `on`, `off`).

---

## 4. Module design

### 4.1 Tracker (read-tracking module)

#### Purpose

Record "when and by whom" every time `get_option()` is called.

#### Hook strategy

The only hook that can attribute an individual `get_option()` call to its caller is `option_{$name}`, so Optrion **dynamically registers an `option_{$name}` filter for every option**. There is no split between autoload and non-autoload rows.

| Target | Hook | Notes |
|---|---|---|
| Every row in `wp_options` | `option_{$name}` filter (dynamic) | Registered on `plugins_loaded` priority 10 after fetching every option name. The filter fires on each `get_option()`, so the live backtrace points at the real caller (plugin/theme). |

The `alloptions` filter is **not** used. `wp_load_alloptions()` fires many times per request, and the filter attributes every autoloaded row to whichever single caller happens to be on the stack at that moment — so Yoast's stack frame ends up attributed to WooCommerce's options, and so on. Autoload options that are never individually `get_option()`'d simply don't get a tracking row; that is harmless because `Rest_Controller::list_options()` starts from `wp_options` and falls back to prefix matching when `tracking = null`.

The per-name filter is registered at `plugins_loaded` priority 10 (not `admin_init`) so reads coming from plugins that hook `plugins_loaded` at a higher priority (Yoast SEO runs its `wpseo_init` at priority 14, for example) are still captured.

#### Caller attribution

`debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 15)` walks the call stack and classifies by file path:

```
Classification rules:
  file path is under WP_PLUGIN_DIR     → type=plugin, slug=directory name
  file path is under get_theme_root()  → type=theme,  slug=directory name
  neither of the above                 → type=unknown
```

#### Performance controls

- Buffer in memory; flush once on `shutdown` (a single I/O per request).
- `ON DUPLICATE KEY UPDATE` upsert to keep the query count minimal.
- Tracking on/off is gated by a transient flag. Admin requests automatically set it for 10 minutes.
- Disabled under WP-CLI and cron (`DOING_CRON` constant).
- Production sites can lower the sampling rate (e.g. track only 10% of requests) through a plugin option.

#### Tracking limitations (by design)

- Tracking is not continuous, so `read_count` is "approximate for the tracking window".
- `last_read_at = NULL` means "no read has been recorded", not "has never been read".
- The admin UI and the accessor inference both account for that.

### 4.2 Classifier (accessor inference module)

#### Purpose

Infer each option's **accessor** (the plugin, theme, WordPress core routine, or widget that reads/writes it) and present the raw signals to the admin. The module never computes a composite score; the displayed columns (accessor, last read, autoload, size) are enough for the user to make the call themselves.

#### Accessor inference logic

Priority order, using the PHP backtrace from `get_option()` and the `option_name` prefix:

```
  1. Exact match against the curated WordPress core options registry (deterministic).
  2. `widget_` prefix → type=widget, slug=widget id (deterministic).
  3. Tracker's last_reader (live data; only trusted when reader_type is plugin or theme).
  4. Prefix match against installed plugin slugs.
  5. Prefix match against installed theme slugs.
  6. Otherwise → unknown.
```

The core options registry is a hard-coded list of roughly 60 WordPress-shipped option names (`siteurl`, `home`, `blogname`, `active_plugins`, `template`, `stylesheet`, `cron`, `rewrite_rules`, …) based on the WordPress Codex Options Reference.

#### active / inactive flag

When the inferred accessor matches an installed plugin/theme slug, Optrion adds an `active` flag reflecting whether the plugin/theme is currently activated. (`core` and `widget` are always active; `unknown` is always inactive.)

#### Admin UI treatment

- The list table exposes accessor (display name + type + inactive badge), an autoload badge, size, and last-read timestamp as individual columns.
- Sort keys: option_name / accessor (accessor.name → slug → type precedence, ascending on the visible label) / size / last_read. Autoload is a binary badge, so it is not sortable.
- Filters: accessor type, `inactive_only` (only inactive accessors), `autoload_only` (only `autoload=yes`), and `search` (substring match on option_name).
- `_transient_*` / `_site_transient_*` rows and Optrion's own internal options (`optrion_*`, `_optrion_q__*`) are hidden from the list.

### 4.3 Export / Import (backup module)

#### Export JSON format

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

Legacy `1.0.0` payloads (each entry had an extra `score` object) are still accepted by the importer; the `score` field is ignored.

#### Export specification

- Targets: options selected in the UI, or bulk accessor-based filtering via the CLI.
- Filename: `optrion-export-{site}-{date}.json`.
- Values ship as they live in the DB (already serialized), so a restore is a plain `INSERT`.
- **Delivery invariant**: the admin UI returns the payload in the REST response body and saves it through a browser `Blob` download — the server never writes the file. WP-CLI defaults to stdout; `--output=<path>` is the operator's explicit choice to persist. No temp files, no cache, no `wp-content/optrion-*` directory is ever created by Optrion itself.

#### Import specification

- Reads the JSON and only `INSERT`s rows whose `option_name` is missing (existing rows are not overwritten by default).
- Overwrite mode (update existing rows on restore) is an explicit checkbox.
- A dry-run preview shows add / overwrite / skip counts before the real run.
- The `tracking` subobject is displayed for reference only; the importer never writes to the tracking table during restore.
- The importer mirrors the same "do not touch" set as the cleaner: WordPress core options (per `CoreOptions`) are never written, and Optrion's own internal namespaces — its plugin options (`optrion_*`) and the quarantine rename namespace (owned by the manifest table) — are also refused. Skipped entries are reported in the summary.

### 4.4 Cleaner (deletion module)

#### Deletion flow

```
Admin selects rows to delete
        │
        ▼
  Confirmation dialog (row count + reminder to export first if desired)
        │
        ▼
  DELETE from wp_options + DELETE from the tracking table
        │
        ▼
  Completion notice (deleted count)
```

#### No server-side backup (security invariant)

Optrion **never writes `option_value` content to the server filesystem**. `wp_options` rows can contain API keys, SMTP credentials, payment gateway secrets, license tokens, and other values that should not be copied into `wp-content/` — even with an `.htaccess` guard, that directory is routinely snapshot by host-level backups or misconfigured web servers. The plugin therefore does not create a \"pre-delete backup\" directory on its behalf.

Administrators who want a restore path take one explicit action before running a delete:

- **Admin UI**: Select the rows and use **Export selected** to download a JSON file to the browser. The file lands wherever the operator's browser saves it; the server never sees it.
- **WP-CLI**: Run `wp optrion export --names=...` (or `--accessor-type=...` / `--inactive-only`). Default sink is stdout; `--output=<path>` persists to an operator-chosen file path. Either way, the operator is explicitly in control of where the JSON goes.

`wp optrion clean` refuses to run without the explicit `--i-have-a-backup` flag to acknowledge that the operator has taken care of the backup step themselves.

#### Bulk deletion options

- "Delete all options owned by inactive plugins/themes" (`--inactive-only` CLI flag / inactive-only UI filter).
- "Delete all options matching a specific accessor type" (`--accessor-type` / UI filter).
- "Delete all expired transients".

#### Safeguards

- Known WordPress core options have their delete button disabled, and the UI shows a lock icon.
- The autoload total size before/after deletion is surfaced on the UI (e.g. "autoload payload 1.2 MB → 0.8 MB").
- Admin confirmation dialog reminds the operator to export first if they need a restore path.

### 4.5 Quarantine module

#### Purpose

Flag options that "look unused but we are not 100% sure" for observation **without changing the site's behavior**, and record whether they are actually being read. If nothing accesses the option during the window it is safe to delete permanently; if something does access it the row is explicitly flagged as "needs restore".

#### Mechanism

When a row is quarantined, Optrion renames it (`wpseo_titles` → `_optrion_q__wpseo_titles`) AND registers a `pre_option_{original_name}` filter that returns the value from the renamed row. The net effect is that `get_option()` keeps returning **the same value** it returned before quarantine, so the site keeps running as usual. Every time the filter fires, Optrion records the access via the live backtrace and updates the manifest at the end of the request.

```
On quarantine:
  wp_options:   wpseo_titles  →  _optrion_q__wpseo_titles (autoload=no)
  runtime:      add_filter('pre_option_wpseo_titles', closure returning the stored value)

On restore:
  wp_options:   _optrion_q__wpseo_titles  →  wpseo_titles (autoload restored)
  runtime:      drop the cache entry (closure no-ops on the next call)

On permanent delete:
  wp_options:   DELETE _optrion_q__wpseo_titles
  runtime:      drop the cache entry
```

The quarantine rename flips `autoload` to `no` so the renamed row does not ride along in `alloptions`; Optrion serves the value through the `pre_option` filter instead, so autoload is no longer required. The original autoload value is saved on the manifest so restore can put it back.

#### Quarantine manifest

Dedicated table `{prefix}_options_quarantine` tracks quarantined rows:

| Column | Type | Description |
|---|---|---|
| `id` | BIGINT AUTO_INCREMENT PK | Quarantine id |
| `original_name` | VARCHAR(191) UNIQUE | Pre-rename `option_name` |
| `original_autoload` | VARCHAR(20) | Autoload value before quarantine (used on restore) |
| `quarantined_at` | DATETIME | Timestamp of quarantine |
| `expires_at` | DATETIME | Auto-restore deadline (default: 7 days out) |
| `quarantined_by` | BIGINT | `user_id` of the admin who quarantined the row |
| `status` | ENUM('active','restored','deleted') | Current state |
| `restored_at` | DATETIME NULL | Restore timestamp |
| `deleted_at` | DATETIME NULL | Permanent-delete timestamp |
| `last_accessed_at` | DATETIME NULL | Last `get_option()` access during the window (written by the pre_option filter) |
| `accessor_during_quarantine` | VARCHAR(255) | Slug of the plugin/theme that accessed it most recently |
| `accessor_type_during_quarantine` | VARCHAR(20) | `plugin` / `theme` / `core` / `unknown` |
| `access_count_during_quarantine` | BIGINT UNSIGNED | Cumulative access count during the window |
| `notes` | TEXT | Optional admin note |

#### Flow

```
Admin selects rows and runs "Quarantine"
        │
        ▼
  Rename option_name in wp_options
  Flip autoload to 'no'
  Insert a manifest row (original name / autoload / expiry)
        │
        ▼
  Site continues to run normally (pre_option filter returns the stored value)
        │
        ├── Access detected during the window ──→ update manifest
        │                                       UI shows "in use — restore" badge
        │                                       automatic expiry is skipped
        │
        ├── Admin restores manually ───────────→ "Restore" button
        │                                       rename back to original_name / restore autoload
        │                                       status flips to 'restored'
        │
        ├── No access during the window ───────→ "Delete" button is enabled
        │                                       DELETE the renamed row
        │                                       status flips to 'deleted'
        │
        └── Window expires with no access
            ↓
           Auto-restore or auto-delete (per setting)
           Admin screen shows a notice banner
```

#### Expiry and auto-restore

- Default window: **7 days** (configurable, 1–30 days).
- Expiry action is selectable:
  - **Auto-restore** (default, safest): put the row back and notify the admin.
  - **Auto-delete** (power users): write a JSON backup and `DELETE`.
  - **Keep** (no expiry): hold the row as quarantined until the admin decides manually.
- The sweep runs daily on WordPress cron (`optrion_quarantine_check`).
- **Quarantines with recorded access are exempt from auto-processing**: rows with `last_accessed_at IS NOT NULL` are excluded from the cron query. We never auto-restore or auto-delete an option the site is still relying on; the admin must restore it explicitly.

#### Quarantine restrictions

- WordPress core options (the curated list) cannot be quarantined (the UI locks them).
- `active_plugins`, `template`, `stylesheet`, `cron`, and a handful of other critical options get an extra guard.
- Maximum simultaneous quarantines: **50** (prevents accidental mass isolation).
- The renamed `option_name` (`_optrion_q__` prefix + original name) must fit in 191 characters; original names longer than 178 characters are rejected with a clear UI message.

#### Quarantine list UI

A dedicated "Quarantine" tab next to the options list:

| Column | Contents |
|---|---|
| Original name | Pre-rename name. Shows "in use — restore" badge when accessed during the window. |
| Quarantined at | When the row was quarantined |
| Time remaining | Countdown to auto-restore/delete (accessed rows are exempt) |
| Last accessed | Timestamp if `get_option()` ran during the window, otherwise `—` |
| Access count | Cumulative access count during the window |
| Accessor | Plugin/theme and accessor type recorded during the window |
| Actions | "Restore" / "Delete" / "Extend" buttons (Delete is blocked on rows with recorded accesses) |

A header badge "Quarantined: N" is always visible.

#### WP-CLI support

```bash
# Quarantine options (default window: 7 days).
wp optrion quarantine wpseo_titles wpseo_social --days=14

# List quarantined options.
wp optrion quarantine list

# Restore.
wp optrion quarantine restore wpseo_titles

# Permanent delete from quarantine.
wp optrion quarantine delete wpseo_titles --yes

# Run the expiry sweep manually (equivalent of the cron job).
wp optrion quarantine check-expiry
```

---

## 5. REST API

Base: `/wp-json/optrion/v1`

Authorization: every endpoint requires the `manage_options` capability.

| Method | Path | Description | Primary parameters |
|---|---|---|---|
| GET | `/options` | List options (with accessor / tracking / autoload / size) | `page`, `per_page`, `orderby`, `order`, `accessor_type`, `inactive_only`, `autoload_only`, `search` |
| GET | `/options/{name}` | Single-option detail | — |
| DELETE | `/options` | Bulk delete (no server-side backup; export first if needed) | `names[]` |
| GET | `/stats` | Summary stats (total rows, autoload size) | — |
| POST | `/export` | Export selected options as JSON | `names[]` |
| POST | `/import` | Import JSON | `file` (multipart), `overwrite` (bool) |
| POST | `/import/preview` | Import dry-run | `file` (multipart) |
| POST | `/scan` | Manual tracker snapshot | — |
| POST | `/quarantine` | Quarantine selected options (rename) | `names[]`, `days` (expiry) |
| GET | `/quarantine` | List quarantined options | `status` (active/restored/deleted) |
| POST | `/quarantine/restore` | Restore from quarantine | `names[]` |
| DELETE | `/quarantine` | Permanent delete from quarantine | `names[]` |
| PATCH | `/quarantine/{name}` | Extend window / update note | `days`, `notes` |

### Example response: `GET /options`

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

## 6. Admin UI design

### 6.1 Screen layout

Optrion adds a top-level "Optrion" menu (with a dedicated branded icon) to the WordPress admin.

```
┌──────────────────────────────────────────────────────────────────┐
│  Optrion                                                         │
├──────────────┬────────────────┬──────────────┬───────────────────┤
│ Dashboard    │ Options        │ Quarantine   │ Import            │
└──────────────┴────────────────┴──────────────┴───────────────────┘
```

Export has no dedicated tab; it runs from the options list via the "Export selected" bulk action.

### 6.2 Dashboard

Five summary cards laid out side by side:

| Card | Contents |
|---|---|
| Total options | Total row count in `wp_options` |
| Autoload payload | Byte sum of `autoload=yes` rows |
| Inactive accessors | Count of options whose accessor is an inactive plugin/theme |
| Expired transients | Count of transients past their expiry |
| Quarantined | Currently active quarantines (warning color when close to expiry) |

Chart:

- **Options per accessor**: horizontal bar chart per plugin/theme, color-coded by active/inactive.

### 6.3 Options list

Data-table layout. Columns per row:

| Column | Contents |
|---|---|
| Checkbox | For bulk actions (core-accessor rows cannot be selected) |
| option_name | Clicking opens the value preview modal |
| Accessor | Plugin/theme name + type; inactive badge when the accessor is deactivated |
| Autoload | Badge for autoload=yes rows; raw value in muted color otherwise |
| Size | Byte count (human-readable) |
| Last accessed | Timestamp, `—` when untracked |

Filter bar:
- Text search (substring on option_name).
- Accessor type (plugin / theme / widget / core / unknown).
- Inactive only (accessor is an inactive plugin/theme).
- Autoload only.
- Show / hide WordPress-core rows.

Bulk actions:
- Quarantine selected.
- Delete selected.
- Export selected.

### 6.4 Value preview modal

Clicking `option_name` opens a modal with:

- `option_value` contents (pretty-printed for arrays/objects).
- Accessor, autoload, size, last-read timestamp, `read_count`.
- "Delete" / "Export" buttons.

### 6.5 Export

There is no dedicated export screen. Select rows in the options table and use the "Export selected" bulk action to download the JSON. Accessor-based bulk export is available through WP-CLI (`wp optrion export --accessor-type=<type>`, `wp optrion export --inactive-only`).

### 6.6 Import screen

- Upload a JSON file.
- Dry-run result shown as a table (add / overwrite / skip counts).
- Overwrite mode toggle.
- "Run import" → result summary.

---

## 7. Security

| Concern | Mitigation |
|---|---|
| Capability | Every operation requires `manage_options` |
| CSRF | REST API relies on the standard WordPress nonce middleware (`X-WP-Nonce`) |
| SQL injection | `$wpdb->prepare()` on every query |
| Sensitive data at rest | **Optrion never persists `option_value` content to the server filesystem.** `Cleaner::delete()` does not write a backup; exports are browser downloads (admin UI) or operator-directed CLI output. No `wp-content/optrion-backups/`, no temp files, no cache. See §4.3 and §4.4. |
| Import validation | JSON schema validation, `version` header required, `option_name` character check (alphanumerics, underscores, hyphens only) |
| Core option protection | The curated ~60-entry core options list blocks DELETE for those names |

---

## 8. Performance

| Concern | Mitigation |
|---|---|
| `debug_backtrace` cost | Frame limit set to 15; `IGNORE_ARGS` flag to suppress argument copying |
| Per-request DB writes | Memory buffer flushed as one upsert on `shutdown` |
| `option_{$name}` hook registration | Runs once on `plugins_loaded` priority 10; not registered for front-end requests (tracking short-circuits when the transient is off) |
| Large option sets (thousands of rows) | REST pagination (default 50/page); accessor inference is computed on demand per request |
| Tracking overhead | Transient flag gates activation; a sampling rate (1–100%) is configurable via the settings screen |

---

## 9. WP-CLI

WP-CLI subcommands cover operators who run the plugin headless:

```bash
# List options (accessor / autoload / size / last_read columns).
wp optrion list --format=table

# Show only options owned by inactive plugins/themes.
wp optrion list --inactive-only

# Filter by accessor type.
wp optrion list --accessor-type=plugin

# Summary stats.
wp optrion stats

# Export options owned by inactive plugins/themes.
wp optrion export --inactive-only --output=backup.json

# Export by explicit name list.
wp optrion export --names=opt_a,opt_b --output=backup.json

# Import JSON (dry run).
wp optrion import backup.json --dry-run

# Import JSON (for real).
wp optrion import backup.json

# Bulk-delete options owned by inactive plugins/themes (no server-side backup; export first if needed).
wp optrion clean --inactive-only --i-have-a-backup --yes

# Delete expired transients.
wp optrion clean-transients

# Manual scan.
wp optrion scan
```

---

## 10. File layout

```
optrion/
├── optrion.php                    # Main plugin file (bootstrap)
├── readme.txt                     # WordPress.org-flavoured readme
├── uninstall.php                  # Cleanup on plugin uninstall
│
├── includes/
│   ├── class-tracker.php          # Tracker module
│   ├── class-classifier.php       # Classifier (accessor inference)
│   ├── class-exporter.php         # Export
│   ├── class-importer.php         # Import
│   ├── class-cleaner.php          # Deletion
│   ├── class-quarantine.php       # Quarantine mode
│   ├── class-rest-controller.php  # REST API
│   ├── class-admin-page.php       # Admin page registration / asset enqueue
│   ├── class-cli-command.php      # WP-CLI subcommands
│   └── core-options-list.php      # Core options registry (array constant)
│
├── assets/
│   ├── js/
│   │   └── admin-app.js           # Built React dashboard
│   └── css/
│       └── admin.css              # Admin styles
│
├── src/                           # React source
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

## 11. Lifecycle

| Event | Action |
|---|---|
| **Activate** | Create custom tables (tracking + quarantine); snapshot every option on first run |
| **Daily operation** | Tracking auto-enables on admin visits; batches are written on `shutdown` |
| **Deactivate** | Unschedule the cron job; tables and data are preserved |
| **Uninstall** | DROP custom tables; delete the plugin's own options (no irony allowed); clear the cron job |

---

## 12. Future extensions

- **Weekly diff email**: email digest of "newly detected unused options" once a week.
- **Multisite**: the equivalent scan for the `wp_sitemeta` table.
- **REST API log integration**: interop with dev tools like Query Monitor.
- **Autoload size time series**: log the autoload total daily and graph the bloat trend.
- **Whitelist management**: let users explicitly pin / exclude "keep this option" rows from the main list.
