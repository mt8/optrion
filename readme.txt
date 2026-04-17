=== Optrion ===
Contributors: mt8biz
Tags: options, database, cleanup, performance, autoload
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Track, score, quarantine, and clean orphaned options in your WordPress database.

== Description ==

The `wp_options` table accumulates leftovers from plugins and themes that have been deactivated or deleted but never cleaned up. Those rows bloat the autoload payload on every page load and there is no built-in way to decide which ones are safe to remove.

Optrion observes which options are actually read at runtime, scores each row on a 0–100 "probably unused" scale, and gives administrators a safe path to delete them:

1. **Observe** — the tracker records when and by whom each option is read.
2. **Score** — a deterministic 5-axis model (accessor state, freshness, transient prefix, autoload waste, size) classifies each option.
3. **Quarantine** — rename the option temporarily so WordPress and the accessing plugin can no longer see it; confirm nothing breaks.
4. **Delete** — a JSON backup is written automatically, and the row is removed from both `wp_options` and the tracking table.

Core WordPress options are locked out of destructive operations.

== Features ==

* Per-option read tracking via the `alloptions` filter and dynamically registered `option_{$name}` filters
* 5-axis scoring with accessor inference (live tracker data → slug prefix → core list)
* Quarantine mode with automatic expiry (restore / delete / keep) and a manifest table

* Automatic JSON backup before any deletion, with rolling 3-generation retention
* REST API under `/wp-json/optrion/v1/*` (requires `manage_options`)
* WP-CLI commands for scripted pipelines

== Installation ==

1. Upload the plugin directory or install the zip via **Plugins → Add New → Upload**.
2. Activate the plugin.
3. Visit **Tools → Optrion** and let the tracker run for a few days before acting on the results.

== Frequently Asked Questions ==

= Is tracking safe to leave on in production? =

Yes. Tracking is buffered in memory and flushed once per request at `shutdown`. A sampling-rate option and a 10-minute activation window triggered by admin traffic keep the overhead predictable.

= Will deleting an option break my site? =

Use Quarantine first. A quarantined option is renamed, not deleted; if anything breaks you can restore it with one click. Only permanently delete after you have confirmed nothing is broken during the quarantine window.

= What happens to my data if I uninstall the plugin? =

Uninstalling restores any active quarantines to their original names, drops the custom tracking and quarantine tables, removes plugin-owned options, and clears the scheduled cron job. Your regular `wp_options` data is untouched.

== Changelog ==

= 0.1.0 =
* Initial release.

== License ==

This plugin is licensed under the GPL, version 2 or later.
