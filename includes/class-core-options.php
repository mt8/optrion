<?php
/**
 * Known WordPress core option names.
 *
 * @package Optrion
 */

declare(strict_types=1);

namespace Optrion;

defined( 'ABSPATH' ) || exit;

/**
 * Static registry of option names that WordPress core itself manages.
 *
 * The list is used by the Scorer (see docs/DESIGN.md §4.2) to classify
 * an option as `owner=core`, and by the Cleaner / Quarantine modules to
 * lock out destructive operations on core-owned rows.
 *
 * Sourced from the WordPress Codex "Option Reference" page; entries are
 * the stable option names registered by WordPress core on a fresh install.
 */
final class CoreOptions {

	/**
	 * Canonical list of core option names.
	 *
	 * Entries are deliberately lowercase and kept sorted alphabetically to
	 * make review easy. Transients and per-theme `theme_mods_*` entries are
	 * deliberately excluded — those are dynamic names and need a different
	 * matcher.
	 *
	 * @var string[]
	 */
	public const LIST = array(
		'active_plugins',
		'admin_email',
		'admin_email_lifespan',
		'auto_plugin_theme_update_emails',
		'auto_update_core_dev',
		'auto_update_core_major',
		'auto_update_core_minor',
		'avatar_default',
		'avatar_rating',
		'blacklist_keys',
		'blog_charset',
		'blog_public',
		'blogdescription',
		'blogname',
		'category_base',
		'close_comments_days_old',
		'close_comments_for_old_posts',
		'comment_max_links',
		'comment_moderation',
		'comment_order',
		'comment_previously_approved',
		'comment_registration',
		'comments_notify',
		'comments_per_page',
		'cron',
		'current_theme',
		'date_format',
		'db_version',
		'default_category',
		'default_comment_status',
		'default_comments_page',
		'default_email_category',
		'default_link_category',
		'default_ping_status',
		'default_pingback_flag',
		'default_post_format',
		'default_role',
		'disallowed_keys',
		'fresh_site',
		'gmt_offset',
		'hack_file',
		'home',
		'html_type',
		'image_default_align',
		'image_default_link_type',
		'image_default_size',
		'initial_db_version',
		'large_size_h',
		'large_size_w',
		'link_manager_enabled',
		'links_updated_date_format',
		'mailserver_login',
		'mailserver_pass',
		'mailserver_port',
		'mailserver_url',
		'medium_large_size_h',
		'medium_large_size_w',
		'medium_size_h',
		'medium_size_w',
		'moderation_keys',
		'moderation_notify',
		'nav_menu_options',
		'page_comments',
		'page_for_posts',
		'page_on_front',
		'permalink_structure',
		'ping_sites',
		'posts_per_page',
		'posts_per_rss',
		'recently_activated',
		'recently_edited',
		'require_name_email',
		'rewrite_rules',
		'rss_use_excerpt',
		'show_avatars',
		'show_comments_cookies_opt_in',
		'show_on_front',
		'sidebars_widgets',
		'site_icon',
		'siteurl',
		'start_of_week',
		'sticky_posts',
		'stylesheet',
		'tag_base',
		'template',
		'thread_comments',
		'thread_comments_depth',
		'thumbnail_crop',
		'thumbnail_size_h',
		'thumbnail_size_w',
		'time_format',
		'timezone_string',
		'uninstall_plugins',
		'upload_path',
		'upload_url_path',
		'uploads_use_yearmonth_folders',
		'use_balancetags',
		'use_smilies',
		'use_trackback',
		'users_can_register',
		'wp_force_deactivated_plugins',
		'wp_page_for_privacy_policy',
		'wp_user_roles',
	);

	/**
	 * Returns the canonical core option list.
	 *
	 * Wrapped in a filter so that site owners can add custom "never touch"
	 * entries (for example, options from must-use plugins they treat as core).
	 *
	 * @return string[]
	 */
	public static function all(): array {
		/**
		 * Filters the list of option names treated as WordPress core.
		 *
		 * @param string[] $list Canonical option names considered core.
		 */
		$list = apply_filters( 'optrion_core_options', self::LIST );

		return array_values( array_unique( array_map( 'strval', $list ) ) );
	}

	/**
	 * Checks whether the given option name is in the core list.
	 *
	 * @param string $option_name Raw option_name from wp_options.
	 */
	public static function contains( string $option_name ): bool {
		return in_array( $option_name, self::all(), true );
	}
}
