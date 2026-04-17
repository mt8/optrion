<p align="center">
  <img src="assets/optrion-icon.svg" width="72" height="72" alt="Optrion">
</p>

<h1 align="center">Optrion</h1>

<p align="center">
  <strong>Track which plugin or theme accesses each <code>wp_options</code> row, then quarantine or clean orphans.</strong>
</p>

<p align="center">
  <a href="https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/mt8/optrion/main/playground.json">
    <img src="https://img.shields.io/badge/▶%20Try%20it%20live-WordPress%20Playground-21759b?style=for-the-badge&logo=wordpress&logoColor=white" alt="Try Optrion in WordPress Playground">
  </a>
</p>

<p align="center">
  <a href="#features">Features</a> •
  <a href="#how-it-works">How It Works</a> •
  <a href="#quarantine-mode">Quarantine</a> •
  <a href="#no-server-side-backup">No Server-Side Backup</a> •
  <a href="#wp-cli">WP-CLI</a> •
  <a href="#rest-api">REST API</a> •
  <a href="#installation">Installation</a> •
  <a href="#faq">FAQ</a> •
  <a href="#contributing">Contributing</a> •
  <a href="#license">License</a>
</p>

---

## Try it live

Click **▶ Try it live** above (or [open the demo](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/mt8/optrion/main/playground.json)). WordPress Playground boots a full WordPress instance entirely in your browser with Optrion and Yoast Duplicate Post pre-installed, and lands you on the Optrion admin screen. No server required.

## The Problem

Every plugin and theme writes settings to the `wp_options` table. When you deactivate or delete them, those rows stay behind — forever.

Over time the table accumulates hundreds of orphaned rows, many with `autoload = yes`, quietly inflating every single page load. There's no built-in way to tell which rows are still in use, which plugin or theme created them, or whether it's safe to remove them.

**Optrion fixes this.** It observes which options are actually read at runtime, identifies the real caller from the live PHP backtrace, and surfaces the raw signals (accessor, autoload flag, size, last-read timestamp) as individual columns. Quarantine an option first to confirm nothing breaks; delete permanently only when you're sure.

## Features

- **Per-option read tracking** — dynamically registers an `option_{$name}` filter for every row so every `get_option()` call is attributed to the real plugin or theme on the PHP backtrace.
- **Accessor inference** — walks the live call stack and falls back to prefix matching. Adds an active / inactive flag so you can filter down to options whose owner is no longer installed.
- **Individual signal columns** — sortable accessor, autoload badge, size, and last-read timestamp. No opaque composite score.
- **Transparent quarantine** — renames the row and registers a `pre_option_{name}` filter that returns the stored value, so your site keeps running during the observation window. Any access during that window is recorded on the manifest and the Quarantine tab flags the row "in use — restore".
- **No server-side backup** — JSON exports are browser downloads only (or operator-directed CLI output). Optrion never writes `option_value` content to the server filesystem, so secrets stored in options don't leak into `wp-content/` snapshots.
- **Dashboard** — React-based admin UI with summary cards and an accessor breakdown.
- **WP-CLI support** — every operation available from the command line.
- **Core protection** — ~60 known WordPress core options are hardcoded as undeletable.
- **i18n** — full Japanese localization included.

## How It Works

```
  get_option()
       │
       ▼
┌─────────────┐     shutdown      ┌──────────────────┐
│   Tracker   │ ──── batch ────▶ │  tracking table   │
│ (in-memory) │     upsert       │  last_read_at     │
└─────────────┘                   │  read_count       │
                                  │  last_reader      │
                                  └────────┬──────────┘
                                           │
                                  ┌────────▼──────────┐
                                  │     Classifier     │
                                  │  accessor + state  │
                                  └────────┬──────────┘
                                           │
                              ┌────────────┼────────────┐
                              ▼            ▼            ▼
                         [ Quarantine ] [ Delete ] [ Export ]
```

Tracking is **sampling-based and batched**. Reads are buffered in memory during a request and flushed to the database once at `shutdown`. Tracking activates automatically for 10 minutes when an admin visits the dashboard — no always-on overhead, no front-end cost.

## Quarantine Mode

Not sure if an option is safe to delete? **Quarantine it first.**

Quarantine renames the row in `wp_options` (e.g. `wpseo_titles` → `_optrion_q__wpseo_titles`) and registers a `pre_option_{name}` filter that transparently returns the stored value from the renamed row. `get_option()` keeps returning the same value it returned before quarantine — **the site does not break the moment you quarantine**. Any access during the window is attributed via backtrace and recorded on the manifest.

```
Quarantine ──▶ Run your site for a few days
                    │
        ┌───────────┴───────────┐
  Something accessed it?   Nothing accessed it?
        │                       │
  [Restore recommended]    [Permanent Delete]
  auto-expiry paused       enabled
```

- Default quarantine period: **7 days** (configurable 1–30 days)
- Maximum simultaneous quarantines: **50 options**
- Core options cannot be quarantined
- **Rows with recorded access are exempt from the expiry sweep** — the cron never auto-restores or auto-deletes a quarantine the site is still relying on

## No Server-Side Backup

Optrion **never writes `option_value` content to the server filesystem.** `wp_options` rows can carry API keys, SMTP credentials, payment gateway secrets, and license tokens that should not be copied into `wp-content/` — even with an `.htaccess` guard, that directory is routinely snapshot by host-level backups, misconfigured web servers, and CI/CD pipelines.

If you want a restore path before deleting, take one explicit action:

- **Admin UI**: Select the rows → **Export selected** → your browser downloads the JSON to your machine.
- **WP-CLI**: `wp optrion export --names=... [--output=<path>]` → stdout by default, or an operator-chosen file path.

`wp optrion clean` refuses to run without the explicit `--i-have-a-backup` flag to acknowledge that the operator has handled the backup step themselves.

## WP-CLI

```bash
# List options with accessor / autoload / size / last_read columns
wp optrion list --format=table

# Show only options owned by inactive plugins / themes
wp optrion list --inactive-only

# Filter by accessor type
wp optrion list --accessor-type=plugin

# Summary stats
wp optrion stats

# Export options owned by inactive plugins/themes to stdout
wp optrion export --inactive-only

# Export by explicit name list to an operator-chosen file
wp optrion export --names=opt_a,opt_b --output=backup.json

# Import JSON (dry run)
wp optrion import backup.json --dry-run

# Import JSON
wp optrion import backup.json

# Bulk-delete options owned by inactive plugins/themes.
# --i-have-a-backup is required: Optrion will not create a server-side backup.
wp optrion clean --inactive-only --i-have-a-backup --yes

# Clean up expired transients
wp optrion clean-transients

# Quarantine specific options for 14 days
wp optrion quarantine wpseo_titles wpseo_social --days=14

# List quarantined options
wp optrion quarantine list

# Restore from quarantine
wp optrion quarantine restore wpseo_titles

# Permanently delete from quarantine
wp optrion quarantine delete wpseo_titles --yes

# Run the expiry check (equivalent of the daily cron job)
wp optrion quarantine check-expiry

# Manual tracking snapshot
wp optrion scan
```

## REST API

Base: `/wp-json/optrion/v1`

All endpoints require the `manage_options` capability.

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/options` | List options with tracking data and accessor. Supports `page`, `per_page`, `orderby`, `order`, `accessor_type`, `inactive_only`, `autoload_only`, `search`. |
| `GET` | `/options/{name}` | Single option detail |
| `DELETE` | `/options` | Bulk delete. **No server-side backup is created** — export first if you need a restore copy. |
| `GET` | `/stats` | Summary statistics |
| `POST` | `/export` | Export selected options as JSON (response body → browser download) |
| `POST` | `/import` | Import from JSON |
| `POST` | `/import/preview` | Dry-run import preview |
| `POST` | `/quarantine` | Quarantine selected options |
| `GET` | `/quarantine` | List quarantined options |
| `POST` | `/quarantine/restore` | Restore from quarantine |
| `DELETE` | `/quarantine` | Permanently delete quarantined options |
| `PATCH` | `/quarantine/{name}` | Extend period or update notes |

## Installation

Download the latest `optrion-1.0.0.zip` from the [GitHub Releases](https://github.com/mt8/optrion/releases) page and install it through **Plugins → Add New → Upload Plugin** in your WordPress admin.

### From source

```bash
git clone https://github.com/mt8/optrion.git
cd optrion
composer install
npm install && npm run build
```

Copy the `optrion` directory to `wp-content/plugins/` and activate from the WordPress admin.

### Requirements

- WordPress 6.8+
- PHP 8.3+
- MySQL 8.0+ or MariaDB 10.3+

## Database

Optrion creates two custom tables on activation. It **never modifies** the `wp_options` table schema — only reads from it and renames rows during quarantine.

| Table | Purpose |
|-------|---------|
| `{prefix}_options_tracking` | Stores read timestamps, counts, and reader identity for each option |
| `{prefix}_options_quarantine` | Manages quarantine lifecycle (original name, autoload, expiry, status, access during window) |

Both tables are dropped on **uninstall** (not deactivation).

## Export Format

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

Legacy `1.0.0` payloads (with an extra `score` object per entry) are still accepted by the importer; the `score` field is ignored.

## Security

- All operations require the `manage_options` capability.
- REST API relies on WordPress nonce authentication (`X-WP-Nonce`).
- Every `$wpdb` query uses `$wpdb->prepare()`; identifiers come from `$wpdb->prefix`-derived constants.
- **Optrion never persists `option_value` content to the server filesystem.** `Cleaner::delete()` does not write a backup; exports are browser downloads or operator-directed CLI output. No `wp-content/optrion-backups/`, no temp files, no cache.
- Import validates JSON schema, the `version` header, and the `option_name` character class (alphanumerics, underscores, hyphens only).
- ~60 core WordPress options are hardcoded as protected and cannot be deleted or quarantined.

## Performance

| Concern | Mitigation |
|---------|------------|
| `debug_backtrace` cost | Limited to 15 frames with `IGNORE_ARGS` |
| Per-request DB writes | Buffered in memory, single upsert at `shutdown` |
| `option_{$name}` hook registration | Once on `plugins_loaded` priority 10; not registered on the front-end (tracking short-circuits when the admin transient is off) |
| Large option tables | Paginated REST API (default 50/page), accessor inference computed on demand |
| Tracking overhead | Controlled via transient flag, optional sampling rate (1–100%) |

## FAQ

**Does Optrion slow down my site?**

Not visibly. Tracking activates automatically for 10 minutes when an admin visits the dashboard — only during that window, and only for admin traffic. All writes are batched into a single upsert at shutdown. Front-end performance is unaffected. That said, Optrion adds a per-`get_option()` filter callback and a backtrace walk; the overhead is measurable on admin requests, so the admin UI shows a persistent warning asking operators to deactivate the plugin once their cleanup round is complete.

**What happens if I quarantine something important?**

The site keeps working. Quarantine renames the row and installs a `pre_option_{name}` filter that transparently returns the original value; `get_option()` behavior is unchanged during the window. If anything reads the option while it is quarantined, Optrion records the access and flags the row "in use — restore". Click "Restore" (or run `wp optrion quarantine restore <name>`) to bring it back instantly.

**Is it safe to permanently delete after the quarantine window?**

If no access was recorded during the window, yes — nothing on the site tried to read the option for the entire period. If any access was recorded, Optrion disables the Delete button and tells you to Restore instead.

**Where are my deleted options backed up?**

They are not. Optrion deliberately does not persist `option_value` content to the server filesystem because `wp_options` rows can carry secrets that should not leak into `wp-content/` snapshots. If you want a restore path, use **Export selected** in the admin UI (a browser download) or `wp optrion export --output=<path>` from the CLI before deleting.

**Does it work with multisite?**

Currently single-site only. Multisite support (`wp_sitemeta`) is on the roadmap.

## Contributing

Contributions are welcome. Please open an issue first to discuss what you'd like to change.

```bash
# Development setup
git clone https://github.com/mt8/optrion.git
cd optrion
composer install
npm install

# Start local WordPress (Docker required)
npm run env:start        # http://localhost:8888  (admin / password)
npm run env:stop
npm run env:clean        # reset both dev & tests environments

# Run tests (inside the tests-wordpress container)
composer test
```

## License

GPL-2.0-or-later — see [LICENSE](LICENSE) for details.
