<?php
/**
 * Accessor inference and site-context helpers for wp_options rows.
 *
 * @package Optrion
 */

declare(strict_types=1);

namespace Optrion;

defined( 'ABSPATH' ) || exit;

/**
 * Classifies a wp_options row by its most likely accessor (plugin, theme,
 * WordPress core, widget, or unknown) and resolves display metadata.
 *
 * Behavior is described in docs/DESIGN.md §4.2.
 */
final class Classifier {

	public const ACCESSOR_TYPE_PLUGIN  = 'plugin';
	public const ACCESSOR_TYPE_THEME   = 'theme';
	public const ACCESSOR_TYPE_CORE    = 'core';
	public const ACCESSOR_TYPE_WIDGET  = 'widget';
	public const ACCESSOR_TYPE_UNKNOWN = 'unknown';

	/**
	 * Builds the live site-wide context used as input to {@see self::infer_accessor()}.
	 *
	 * Gathers active + installed plugin/theme slugs plus display-name maps.
	 * Kept separate from the inference method so callers can cache the
	 * context for a bulk pass.
	 *
	 * @return array{active_plugin_slugs:string[],installed_plugin_slugs:string[],active_theme_slugs:string[],installed_theme_slugs:string[],plugin_names:array<string,string>,theme_names:array<string,string>}
	 */
	public static function build_context(): array {
		$active_plugins = array();
		foreach ( (array) get_option( 'active_plugins', array() ) as $entry ) {
			$active_plugins[] = self::plugin_file_to_slug( (string) $entry );
		}

		$installed_plugins = array();
		$plugin_names      = array();
		if ( function_exists( 'get_plugins' ) ) {
			foreach ( get_plugins() as $entry => $data ) {
				$slug                  = self::plugin_file_to_slug( (string) $entry );
				$installed_plugins[]   = $slug;
				$plugin_names[ $slug ] = isset( $data['Name'] ) ? (string) $data['Name'] : $slug;
			}
		}

		$active_theme_slugs    = array();
		$installed_theme_slugs = array();
		$theme_names           = array();
		if ( function_exists( 'wp_get_theme' ) ) {
			$current = wp_get_theme();
			if ( $current && $current->exists() ) {
				$active_theme_slugs[] = $current->get_stylesheet();
				$parent               = $current->parent();
				if ( $parent && $parent->exists() ) {
					$active_theme_slugs[] = $parent->get_stylesheet();
				}
			}
		}
		if ( function_exists( 'wp_get_themes' ) ) {
			foreach ( wp_get_themes() as $stylesheet => $theme_obj ) {
				$installed_theme_slugs[]             = (string) $stylesheet;
				$theme_names[ (string) $stylesheet ] = (string) $theme_obj->get( 'Name' );
			}
		}

		return array(
			'active_plugin_slugs'    => array_values( array_unique( $active_plugins ) ),
			'installed_plugin_slugs' => array_values( array_unique( $installed_plugins ) ),
			'active_theme_slugs'     => array_values( array_unique( $active_theme_slugs ) ),
			'installed_theme_slugs'  => array_values( array_unique( $installed_theme_slugs ) ),
			'plugin_names'           => $plugin_names,
			'theme_names'            => $theme_names,
		);
	}

	/**
	 * Resolves a human-readable display name for an accessor.
	 *
	 * Reads Plugin Name from the plugin header or Theme Name from style.css
	 * via the name maps built by {@see self::build_context()}.
	 *
	 * @param array{type:string,slug:string}                $accessor Inferred accessor.
	 * @param array{plugin_names?:array,theme_names?:array} $context  Site context.
	 *
	 * @return string Display name, or the raw slug if no metadata is available.
	 */
	public static function resolve_accessor_name( array $accessor, array $context ): string {
		if ( 'core' === $accessor['type'] ) {
			return 'WordPress';
		}
		if ( 'plugin' === $accessor['type'] && isset( $context['plugin_names'][ $accessor['slug'] ] ) ) {
			return $context['plugin_names'][ $accessor['slug'] ];
		}
		if ( 'theme' === $accessor['type'] && isset( $context['theme_names'][ $accessor['slug'] ] ) ) {
			return $context['theme_names'][ $accessor['slug'] ];
		}
		return ! empty( $accessor['slug'] ) ? $accessor['slug'] : '';
	}

	/**
	 * Returns true when the accessor is an active plugin or the active theme.
	 *
	 * Core and widget accessors are treated as active (they cannot be
	 * "inactive" in any meaningful sense). Unknown accessors are not active.
	 *
	 * @param array{type:string,slug:string}                                  $accessor Inferred accessor.
	 * @param array{active_plugin_slugs:string[],active_theme_slugs:string[]} $context  Site context.
	 */
	public static function accessor_is_active( array $accessor, array $context ): bool {
		switch ( $accessor['type'] ) {
			case self::ACCESSOR_TYPE_CORE:
			case self::ACCESSOR_TYPE_WIDGET:
				return true;
			case self::ACCESSOR_TYPE_PLUGIN:
				return in_array( $accessor['slug'], $context['active_plugin_slugs'] ?? array(), true );
			case self::ACCESSOR_TYPE_THEME:
				return in_array( $accessor['slug'], $context['active_theme_slugs'] ?? array(), true );
			default:
				return false;
		}
	}

	/**
	 * Infers the last accessor of an option by consulting the core registry,
	 * widget prefix, tracking data, and installed plugin/theme slug prefixes.
	 *
	 * @param string                                                                $option_name Raw option name.
	 * @param array{last_reader:string,reader_type:string}|null                     $tracking    Tracking record or null.
	 * @param array{installed_plugin_slugs:string[],installed_theme_slugs:string[]} $context     Site context.
	 *
	 * @return array{type:string,slug:string}
	 */
	public static function infer_accessor( string $option_name, ?array $tracking, array $context ): array {
		// 1. The core registry is the strongest deterministic signal: an exact match
		// on a curated list of WordPress-shipped option names.
		if ( CoreOptions::contains( $option_name ) ) {
			return array(
				'type' => self::ACCESSOR_TYPE_CORE,
				'slug' => 'wordpress',
			);
		}

		// 2. Widget options: `widget_*` entries are stored by register_widget().
		if ( 0 === strpos( $option_name, 'widget_' ) ) {
			$widget_id = substr( $option_name, 7 ); // strip "widget_" prefix.
			return array(
				'type' => self::ACCESSOR_TYPE_WIDGET,
				'slug' => $widget_id,
			);
		}

		// 3. Tracking data (backtrace-based). The tracker records which
		// plugin/theme directory the get_option() call originated from,
		// which is more reliable than prefix matching (e.g. Yoast SEO
		// uses `wpseo` prefix but lives in `wordpress-seo/`). Trust
		// tracking only when it positively identifies a plugin or theme.
		if (
			null !== $tracking
			&& ! empty( $tracking['last_reader'] )
			&& in_array(
				(string) ( $tracking['reader_type'] ?? '' ),
				array( self::ACCESSOR_TYPE_PLUGIN, self::ACCESSOR_TYPE_THEME ),
				true
			)
		) {
			return array(
				'type' => (string) $tracking['reader_type'],
				'slug' => (string) $tracking['last_reader'],
			);
		}

		// 4. Prefix matches against installed plugin/theme slugs. Prefer plugin
		// over theme when a slug happens to exist in both.
		foreach ( ( $context['installed_plugin_slugs'] ?? array() ) as $slug ) {
			if ( '' !== $slug && self::name_starts_with_slug( $option_name, $slug ) ) {
				return array(
					'type' => self::ACCESSOR_TYPE_PLUGIN,
					'slug' => $slug,
				);
			}
		}

		foreach ( ( $context['installed_theme_slugs'] ?? array() ) as $slug ) {
			if ( '' !== $slug && self::name_starts_with_slug( $option_name, $slug ) ) {
				return array(
					'type' => self::ACCESSOR_TYPE_THEME,
					'slug' => $slug,
				);
			}
		}

		return array(
			'type' => self::ACCESSOR_TYPE_UNKNOWN,
			'slug' => '',
		);
	}

	/**
	 * Checks whether the autoload column value represents "autoloaded".
	 *
	 * Handles legacy ('yes'/'no') and WP 6.6+ ('auto'/'on'/'off'/'auto-on'/'auto-off') values.
	 *
	 * @param string $autoload Raw autoload column value.
	 */
	public static function is_autoloaded( string $autoload ): bool {
		$autoload = strtolower( $autoload );
		return in_array( $autoload, array( 'yes', 'on', 'auto', 'auto-on' ), true );
	}

	/**
	 * Returns true if $option_name's prefix matches $slug.
	 *
	 * Matching is case-insensitive and treats '-' and '_' as interchangeable
	 * so that slug `my-plugin` matches option name `my_plugin_setting`.
	 *
	 * @param string $option_name Option name.
	 * @param string $slug        Plugin or theme slug.
	 */
	private static function name_starts_with_slug( string $option_name, string $slug ): bool {
		$normalize = static function ( string $value ): string {
			return str_replace( '-', '_', strtolower( $value ) );
		};
		$option_n  = $normalize( $option_name );
		$slug_n    = $normalize( $slug );
		if ( '' === $slug_n ) {
			return false;
		}
		if ( 0 !== strpos( $option_n, $slug_n ) ) {
			return false;
		}
		$len = strlen( $slug_n );
		if ( strlen( $option_n ) === $len ) {
			return true;
		}
		$boundary = $option_n[ $len ];
		return '_' === $boundary;
	}

	/**
	 * Derives a plugin slug from `folder/main.php` or `single-file.php`.
	 *
	 * @param string $plugin_file Active plugins entry.
	 */
	private static function plugin_file_to_slug( string $plugin_file ): string {
		$slash = strpos( $plugin_file, '/' );
		if ( false === $slash ) {
			return preg_replace( '/\.php$/', '', $plugin_file ) ?? $plugin_file;
		}
		return substr( $plugin_file, 0, $slash );
	}
}
