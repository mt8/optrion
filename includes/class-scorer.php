<?php
/**
 * Option unused-likelihood scorer.
 *
 * @package Optrion
 */

declare(strict_types=1);

namespace Optrion;

use DateTimeImmutable;
use DateTimeInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Assigns a 0-100 "probably unused" score to a wp_options row.
 *
 * Rules and label bands come from docs/DESIGN.md §4.2.
 */
final class Scorer {

	public const LABEL_SAFE          = 'safe';
	public const LABEL_REVIEW        = 'review';
	public const LABEL_LIKELY_UNUSED = 'likely_unused';
	public const LABEL_ALMOST_UNUSED = 'almost_unused';
	public const OWNER_TYPE_PLUGIN   = 'plugin';
	public const OWNER_TYPE_THEME    = 'theme';
	public const OWNER_TYPE_CORE     = 'core';
	public const OWNER_TYPE_UNKNOWN  = 'unknown';

	/**
	 * Scores a single option row.
	 *
	 * @param array{option_name:string,size_bytes?:int,option_value?:mixed,autoload:string}                                                  $option   Row data from wp_options (plus computed size).
	 * @param array{last_read_at:?string,read_count:int,last_reader:string,reader_type:string}|null                                          $tracking Tracking row for the option, or null if untracked.
	 * @param array{active_plugin_slugs:string[],installed_plugin_slugs:string[],active_theme_slugs:string[],installed_theme_slugs:string[]} $context Site context.
	 * @param DateTimeInterface|null                                                                                                         $now Reference "now" for freshness math. Defaults to current UTC time.
	 *
	 * @return array{total:int,label:string,owner:array{type:string,slug:string},breakdown:array<string,int>}
	 */
	public static function score( array $option, ?array $tracking, array $context, ?DateTimeInterface $now = null ): array {
		$now   = $now ?? new DateTimeImmutable( 'now' );
		$owner = self::infer_owner( $option['option_name'], $tracking, $context );

		$breakdown = array(
			'owner'          => self::score_owner( $owner, $context ),
			'freshness'      => self::score_freshness( $tracking, $now ),
			'transient'      => self::score_transient( $option['option_name'] ),
			'autoload_waste' => self::score_autoload_waste( $option, $tracking ),
			'size'           => self::score_size( $option ),
		);

		$total = min( 100, array_sum( $breakdown ) );

		return array(
			'total'     => $total,
			'label'     => self::label_for( $total ),
			'owner'     => $owner,
			'breakdown' => $breakdown,
		);
	}

	/**
	 * Maps a numeric score to a labeled band.
	 *
	 * @param int $total Score in 0-100.
	 */
	public static function label_for( int $total ): string {
		if ( $total >= 80 ) {
			return self::LABEL_ALMOST_UNUSED;
		}
		if ( $total >= 50 ) {
			return self::LABEL_LIKELY_UNUSED;
		}
		if ( $total >= 20 ) {
			return self::LABEL_REVIEW;
		}
		return self::LABEL_SAFE;
	}

	/**
	 * Builds the live site-wide context used as input to score().
	 *
	 * Gathers active + installed plugin/theme slugs. Kept separate from the
	 * pure `score()` method so callers can cache the context for a bulk pass.
	 *
	 * @return array{active_plugin_slugs:string[],installed_plugin_slugs:string[],active_theme_slugs:string[],installed_theme_slugs:string[]}
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
	 * Resolves a human-readable display name for an owner.
	 *
	 * Reads Plugin Name from the plugin header or Theme Name from style.css
	 * via the name maps built by build_context().
	 *
	 * @param array{type:string,slug:string}                $owner   Inferred owner.
	 * @param array{plugin_names?:array,theme_names?:array} $context Site context.
	 *
	 * @return string Display name, or the raw slug if no metadata is available.
	 */
	public static function resolve_owner_name( array $owner, array $context ): string {
		if ( 'core' === $owner['type'] ) {
			return 'WordPress';
		}
		if ( 'plugin' === $owner['type'] && isset( $context['plugin_names'][ $owner['slug'] ] ) ) {
			return $context['plugin_names'][ $owner['slug'] ];
		}
		if ( 'theme' === $owner['type'] && isset( $context['theme_names'][ $owner['slug'] ] ) ) {
			return $context['theme_names'][ $owner['slug'] ];
		}
		return ! empty( $owner['slug'] ) ? $owner['slug'] : '';
	}

	/**
	 * Infers the owner of an option by consulting tracking data, prefix matches, and the core registry.
	 *
	 * @param string                                                                $option_name Raw option name.
	 * @param array{last_reader:string,reader_type:string}|null                     $tracking    Tracking record or null.
	 * @param array{installed_plugin_slugs:string[],installed_theme_slugs:string[]} $context     Site context.
	 *
	 * @return array{type:string,slug:string}
	 */
	public static function infer_owner( string $option_name, ?array $tracking, array $context ): array {
		// 1. The core registry is the strongest deterministic signal: an exact match
		// on a curated list of WordPress-shipped option names.
		if ( CoreOptions::contains( $option_name ) ) {
			return array(
				'type' => self::OWNER_TYPE_CORE,
				'slug' => 'wordpress',
			);
		}

		// 2. Prefix matches against installed plugin/theme slugs. Prefer plugin
		// over theme when a slug happens to exist in both.
		foreach ( ( $context['installed_plugin_slugs'] ?? array() ) as $slug ) {
			if ( '' !== $slug && self::name_starts_with_slug( $option_name, $slug ) ) {
				return array(
					'type' => self::OWNER_TYPE_PLUGIN,
					'slug' => $slug,
				);
			}
		}

		foreach ( ( $context['installed_theme_slugs'] ?? array() ) as $slug ) {
			if ( '' !== $slug && self::name_starts_with_slug( $option_name, $slug ) ) {
				return array(
					'type' => self::OWNER_TYPE_THEME,
					'slug' => $slug,
				);
			}
		}

		// 3. Tracking data is a weaker signal: "who read this option" is often
		// WordPress core even for plugin-owned options (core loads alloptions,
		// then plugins consume them via filters without appearing in the
		// backtrace). Trust tracking only when it positively identifies a
		// plugin or theme caller.
		if (
			null !== $tracking
			&& ! empty( $tracking['last_reader'] )
			&& in_array(
				(string) ( $tracking['reader_type'] ?? '' ),
				array( self::OWNER_TYPE_PLUGIN, self::OWNER_TYPE_THEME ),
				true
			)
		) {
			return array(
				'type' => (string) $tracking['reader_type'],
				'slug' => (string) $tracking['last_reader'],
			);
		}

		return array(
			'type' => self::OWNER_TYPE_UNKNOWN,
			'slug' => '',
		);
	}

	/**
	 * Axis 1 — owner state. Max 40 points.
	 *
	 * @param array{type:string,slug:string}                                  $owner   Inferred owner.
	 * @param array{active_plugin_slugs:string[],active_theme_slugs:string[]} $context Site context.
	 */
	private static function score_owner( array $owner, array $context ): int {
		switch ( $owner['type'] ) {
			case self::OWNER_TYPE_CORE:
				return 0;
			case self::OWNER_TYPE_UNKNOWN:
				return 20;
			case self::OWNER_TYPE_PLUGIN:
				return in_array( $owner['slug'], $context['active_plugin_slugs'] ?? array(), true ) ? 0 : 40;
			case self::OWNER_TYPE_THEME:
				return in_array( $owner['slug'], $context['active_theme_slugs'] ?? array(), true ) ? 0 : 40;
			default:
				return 0;
		}
	}

	/**
	 * Axis 2 — freshness of last recorded read. Max 25 points.
	 *
	 * @param array{last_read_at:?string}|null $tracking Tracking record or null.
	 * @param DateTimeInterface                $now      Reference "now".
	 */
	private static function score_freshness( ?array $tracking, DateTimeInterface $now ): int {
		if ( null === $tracking || empty( $tracking['last_read_at'] ) ) {
			return 25;
		}
		$last = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', (string) $tracking['last_read_at'] );
		if ( false === $last ) {
			return 25;
		}
		$days = (int) floor( ( $now->getTimestamp() - $last->getTimestamp() ) / 86400 );
		if ( $days < 90 ) {
			return 0;
		}
		$extra = (int) floor( ( $days - 90 ) / 30 );
		return (int) min( 25, 5 + $extra * 5 );
	}

	/**
	 * Axis 3 — transient prefixes. 10 points if matched.
	 *
	 * @param string $option_name Raw option_name.
	 */
	private static function score_transient( string $option_name ): int {
		if ( 0 === strpos( $option_name, '_transient_' ) || 0 === strpos( $option_name, '_site_transient_' ) ) {
			return 10;
		}
		return 0;
	}

	/**
	 * Axis 4 — autoload waste. 15 points if autoload=yes but never read during tracking.
	 *
	 * @param array{autoload:string}     $option   Option row fragment.
	 * @param array{read_count:int}|null $tracking Tracking record or null.
	 */
	private static function score_autoload_waste( array $option, ?array $tracking ): int {
		if ( ! self::is_autoloaded( (string) $option['autoload'] ) ) {
			return 0;
		}
		$reads = null === $tracking ? 0 : (int) ( $tracking['read_count'] ?? 0 );
		return 0 === $reads ? 15 : 0;
	}

	/**
	 * Axis 5 — serialized size. 10 / 5 / 0.
	 *
	 * @param array{size_bytes?:int,option_value?:mixed} $option Option row fragment.
	 */
	private static function score_size( array $option ): int {
		if ( isset( $option['size_bytes'] ) ) {
			$bytes = (int) $option['size_bytes'];
		} elseif ( array_key_exists( 'option_value', $option ) ) {
			$serialized = is_string( $option['option_value'] ) ? $option['option_value'] : maybe_serialize( $option['option_value'] );
			$bytes      = strlen( (string) $serialized );
		} else {
			$bytes = 0;
		}
		if ( $bytes > 100 * 1024 ) {
			return 10;
		}
		if ( $bytes > 10 * 1024 ) {
			return 5;
		}
		return 0;
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
