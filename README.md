<p align="center">
  <img src="assets/optrion-icon.svg" width="72" height="72" alt="Optrion">
</p>

<h1 align="center">Optrion</h1>

<p align="center">
  <strong>Track, score, quarantine, and clean orphaned options in your WordPress database.</strong>
</p>

<p align="center">
  <a href="#features">Features</a> •
  <a href="#how-it-works">How It Works</a> •
  <a href="#scoring">Scoring</a> •
  <a href="#quarantine-mode">Quarantine</a> •
  <a href="#wp-cli">WP-CLI</a> •
  <a href="#rest-api">REST API</a> •
  <a href="#installation">Installation</a> •
  <a href="#faq">FAQ</a> •
  <a href="#contributing">Contributing</a> •
  <a href="#license">License</a>
</p>

---

## The Problem

Every plugin and theme writes settings to the `wp_options` table. When you deactivate or delete them, those rows stay behind — forever.

Over time your options table accumulates hundreds of orphaned rows, many with `autoload = yes`, quietly inflating every single page load. There's no built-in way to tell which rows are still in use, which plugin created them, or whether it's safe to remove them.

**Optrion fixes this.** It observes which options are actually read, identifies their owner, calculates a staleness score, and lets you safely quarantine or remove the dead weight — with full backup and one-click restore.

## Features

- **Read tracking** — hooks into `alloptions` and `option_{$name}` filters to record when each option was last read and by which plugin or theme.
- **Owner detection** — traces `get_option()` call stacks back to the originating plugin/theme directory. Falls back to prefix-matching against installed plugins.
- **Staleness scoring** — rates every option 0–100 based on owner activity, read recency, transient status, autoload waste, and data size.
- **Quarantine mode** — renames options instead of deleting them. If something breaks, restore with one click. Auto-restores on expiry (safe by default).
- **Bulk cleanup** — delete by score threshold, by owner, or expired transients only. Every delete auto-creates a JSON backup.
- **Export / Import** — full JSON backup with option values, tracking metadata, and scores. Import with dry-run preview and optional overwrite.
- **Dashboard** — React-based admin UI with score distribution charts, autoload size metrics, and owner breakdown.
- **WP-CLI support** — every operation available from the command line.
- **Core protection** — ~60 known WordPress core options are hardcoded as undeletable.

## How It Works

```
  get_option()
       │
       ▼
┌─────────────┐     shutdown      ┌──────────────────┐
│   Tracker    │ ──── batch ────▶ │  tracking table   │
│ (in-memory)  │     upsert       │  last_read_at     │
└─────────────┘                   │  read_count       │
                                  │  last_reader      │
                                  └────────┬──────────┘
                                           │
                                  ┌────────▼──────────┐
                                  │     Scorer         │
                                  │  0–100 staleness   │
                                  └────────┬──────────┘
                                           │
                              ┌────────────┼────────────┐
                              ▼            ▼            ▼
                         [ Quarantine ] [ Delete ] [ Export ]
```

Tracking is **sampling-based and batched**. Reads are buffered in memory during a request and flushed to the database once at `shutdown`. Tracking activates automatically for 10 minutes when an admin visits the dashboard — no always-on overhead.

## Scoring

Each option is scored across five axes:

| Axis | Max Points | Condition |
|------|-----------|-----------|
| **Owner status** | 40 | Owning plugin/theme is deactivated (40) or owner unknown (20) |
| **Read recency** | 25 | Last read 90+ days ago (5 pts per 30 days, capped at 25). No read recorded = 25 |
| **Transient** | 10 | Option name starts with `_transient_` or `_site_transient_` |
| **Autoload waste** | 15 | `autoload = yes` but zero reads during tracking period |
| **Data size** | 10 | Over 100 KB = 10, over 10 KB = 5 |

Total is capped at 100. The UI maps scores to four tiers:

| Score | Label | Recommended Action |
|-------|-------|--------------------|
| 0–19 | Safe | Leave as is |
| 20–49 | Review | Re-check periodically |
| 50–79 | Likely stale | Quarantine first, then delete |
| 80–100 | Almost certainly stale | Quarantine or export + delete |

## Quarantine Mode

Not sure if an option is safe to delete? **Quarantine it first.**

Quarantine renames the option in `wp_options` (e.g. `wpseo_titles` → `_optrion_q__wpseo_titles`) and sets `autoload` to `no`. WordPress and the owning plugin will behave as if the option doesn't exist.

```
Quarantine ──▶ Run your site for a few days
                    │
        ┌───────────┴───────────┐
  Something broke?         Everything fine?
        │                       │
    [Restore]              [Permanent Delete]
    one click               with JSON backup
```

**Safe by default:** if the quarantine period expires without action, Optrion **auto-restores** the option and notifies the admin. You can change this to auto-delete or indefinite hold in settings.

- Default quarantine period: **7 days** (configurable 1–30 days)
- Maximum simultaneous quarantines: **50 options**
- Core options cannot be quarantined

## WP-CLI

```bash
# List options with scores
wp optrion list --score-min=50 --format=table

# Show summary statistics
wp optrion stats

# Export options scoring 50+ to JSON
wp optrion export --score-min=50 --output=backup.json

# Import with dry-run preview
wp optrion import backup.json --dry-run

# Import for real
wp optrion import backup.json

# Delete all options scoring 80+
wp optrion clean --score-min=80 --yes

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

# Run expiry check (same as daily cron)
wp optrion quarantine check-expiry

# Manual tracking snapshot
wp optrion scan
```

## REST API

Base: `/wp-json/optrion/v1`

All endpoints require the `manage_options` capability.

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/options` | List options with tracking data and scores. Supports `page`, `per_page`, `orderby`, `order`, `score_min`, `score_max`, `owner_type`, `search`. |
| `GET` | `/options/{name}` | Single option detail |
| `DELETE` | `/options` | Bulk delete (auto-backup) |
| `GET` | `/stats` | Summary statistics |
| `POST` | `/export` | Export selected options to JSON |
| `POST` | `/import` | Import from JSON |
| `POST` | `/import/preview` | Dry-run import preview |
| `POST` | `/quarantine` | Quarantine selected options |
| `GET` | `/quarantine` | List quarantined options |
| `POST` | `/quarantine/restore` | Restore from quarantine |
| `DELETE` | `/quarantine` | Permanently delete quarantined options |
| `PATCH` | `/quarantine/{name}` | Extend period or update notes |
| `POST` | `/scan` | Trigger manual tracking snapshot |

## Installation

### From source

```bash
git clone https://github.com/your-username/optrion.git
cd optrion
composer install
npm install && npm run build
```

Copy the `optrion` directory to `wp-content/plugins/` and activate from the WordPress admin.

### Requirements

- WordPress 5.8+
- PHP 7.4+
- MySQL 5.7+ or MariaDB 10.3+

## Database

Optrion creates two custom tables on activation. It **never modifies** the `wp_options` table schema — only reads from it and renames rows during quarantine.

| Table | Purpose |
|-------|---------|
| `{prefix}_options_tracking` | Stores read timestamps, counts, and reader identity for each option |
| `{prefix}_options_quarantine` | Manages quarantine lifecycle (original name, autoload, expiry, status) |

Both tables are dropped on **uninstall** (not deactivation). Backup files in `wp-content/optrion-backups/` are also removed.

## Export Format

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
        "reasons": [...]
      }
    }
  ]
}
```

## Security

- All operations require `manage_options` capability
- REST API uses WordPress nonce authentication (`X-WP-Nonce`)
- All database queries use `$wpdb->prepare()`
- Backup directory is protected with `.htaccess`
- Import validates JSON schema, version field, and option name characters
- ~60 core WordPress options are hardcoded as protected and cannot be deleted or quarantined

## Performance

| Concern | Mitigation |
|---------|------------|
| `debug_backtrace` cost | Limited to 15 frames with `IGNORE_ARGS` |
| Per-request DB writes | Buffered in memory, single upsert at `shutdown` |
| Non-autoload option hooks | Registered only on `admin_init`, not on frontend |
| Large option tables | Paginated REST API (default 50/page), on-demand scoring |
| Tracking overhead | Controlled via transient flag, optional sampling rate (1–100%) |

## FAQ

**Does Optrion slow down my site?**

No. Tracking is off by default and only activates for short windows when an admin visits the dashboard. Even when active, all DB writes are batched into a single query at shutdown. Frontend performance is unaffected.

**What happens if I quarantine something important?**

The option is renamed, not deleted. Click "Restore" in the admin panel or run `wp optrion quarantine restore <name>` to bring it back instantly. If you do nothing, it auto-restores after the quarantine period expires.

**Is it safe to delete options with a score of 80+?**

Scores above 80 typically mean the owning plugin is deactivated/deleted AND the option hasn't been read in months. It's very likely safe. Optrion always creates a JSON backup before any deletion, so you can restore via import if needed.

**Does it work with multisite?**

Currently single-site only. Multisite support (`wp_sitemeta`) is planned.

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
