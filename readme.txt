=== Optrion ===
Contributors: mt8biz
Tags: options, database, cleanup, performance, autoload
Requires at least: 6.8
Tested up to: 6.9
Requires PHP: 8.3
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Track which plugin or theme accesses each wp_options row, then quarantine or clean orphans.

== Description ==

The `wp_options` table accumulates leftovers from plugins and themes that have been deactivated or deleted but never cleaned up. Those rows bloat the autoload payload on every page load and there is no built-in way to decide which ones are safe to remove.

Optrion observes which options are actually read at runtime, attributes each read to the plugin or theme that caused it, and surfaces the raw signals (accessor, autoload flag, size, last-read timestamp) so administrators can decide what to remove:

1. **Observe** — the tracker records when and by whom each option is read, using the live PHP backtrace to identify the real caller.
2. **Quarantine** — rename the option temporarily so WordPress and the accessing plugin can no longer see it; confirm nothing breaks.
3. **Delete** — the row is removed from both `wp_options` and the tracking table. Use the **Export selected** bulk action first if you want a restore copy; Optrion never writes option_value content to the server filesystem on your behalf (option_value can contain API keys, SMTP credentials, and other secrets that should not leak into backups of `wp-content/`).

Core WordPress options are locked out of destructive operations, on both the deletion and the import side.

== Features ==

* Per-option read tracking via dynamically registered `option_{$name}` filters, so every `get_option()` call is attributed to the real plugin or theme on the call stack.
* Accessor inference (live tracker data → slug prefix → curated core list) with an active / inactive flag so you can filter down to options whose owner is no longer present.
* Sortable options table with individual columns for accessor, autoload, size, and last-read timestamp — no opaque composite score.
* Quarantine mode with automatic expiry (restore / delete / keep) and a manifest table that flags options that are still being accessed.
* **No server-side backup**: JSON exports are browser downloads (or explicit CLI `--output`), never written to disk on the server — so option_value content does not leak into `wp-content/` snapshots.
* REST API under `/wp-json/optrion/v1/*` (requires `manage_options`).
* WP-CLI commands for scripted pipelines, including `--accessor-type` / `--inactive-only` filters on `list`, `export`, and `clean` (`clean` requires an explicit `--i-have-a-backup` acknowledgment).

== Installation ==

1. Upload the plugin directory or install the zip via **Plugins → Add New → Upload**.
2. Activate the plugin.
3. Open the **Optrion** menu in the WordPress admin sidebar and let the tracker run for a few days before acting on the results.

== Frequently Asked Questions ==

= Is tracking safe to leave on in production? =

Yes. Tracking is buffered in memory and flushed once per request at `shutdown`. A sampling-rate option and a 10-minute activation window triggered by admin traffic keep the overhead predictable.

= Will deleting an option break my site? =

Use Quarantine first. A quarantined option is renamed, not deleted; if anything breaks you can restore it with one click. Only permanently delete after you have confirmed nothing is broken during the quarantine window.

= What happens to my data if I uninstall the plugin? =

Uninstalling restores any active quarantines to their original names, drops the custom tracking and quarantine tables, removes plugin-owned options, and clears the scheduled cron job. Your regular `wp_options` data is untouched.

== Changelog ==

= 1.0.2 =
* Options list: pick rows per page (25 / 50 / 100 / 200) and jump directly to a page number.
* Options list: the quarantine / export / delete action bar is now also rendered below the table, so you can act on a selection without scrolling back up.
* Hardened the protected-option check to match the storage layer's collation semantics (case-insensitive, trailing whitespace tolerant), so a non-canonical spelling cannot slip past Optrion's safeguards.

= 1.0.1 =
* Hardened the importer to mirror the cleaner's protected-name set: WordPress core options, Optrion's own internal options (`optrion_*`), and the quarantine rename namespace are skipped instead of being written to `wp_options`. Skipped entries are reported in the import summary and the WP-CLI output.

= 1.0.0 =
* Initial public release.
* Per-option read tracking: every `get_option()` call is attributed to the real plugin or theme on the PHP backtrace.
* Options list with individual signal columns (accessor, autoload, size, last accessed) and inactive-only / autoload-only / accessor-type filters.
* Quarantine workflow with manifest table, automatic expiry (restore / delete / keep), and a still-accessed guard that blocks deletion of options that are still being read.
* No server-side persistence of option_value content: JSON exports are browser downloads only.
* REST API under `/wp-json/optrion/v1/*` (requires `manage_options`).
* WP-CLI commands for scripted pipelines, including `--accessor-type`, `--inactive-only`, and `--autoload-only` filters on `list`, `export`, and `clean`.
* Full Japanese localization.

== License ==

This plugin is licensed under the GPL, version 2 or later.
