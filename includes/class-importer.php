<?php
/**
 * Import module.
 *
 * @package Optrion
 */

declare(strict_types=1);

namespace Optrion;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Parses an Optrion export JSON payload and inserts / overwrites wp_options rows.
 *
 * See docs/DESIGN.md §4.3.
 */
final class Importer {

	/**
	 * Maximum accepted JSON payload size in bytes (2 MB).
	 */
	private const MAX_PAYLOAD_BYTES = 2 * 1024 * 1024;

	/**
	 * Maximum nesting depth for json_decode.
	 */
	private const MAX_JSON_DEPTH = 64;

	/**
	 * Performs a dry-run analysis of the payload.
	 *
	 * @param string $json Raw JSON string from an Optrion export.
	 *
	 * @return array{add:int,overwrite:int,skip:int,errors:string[]}|WP_Error
	 */
	public static function dry_run( string $json ) {
		$parsed = self::parse( $json );
		if ( $parsed instanceof WP_Error ) {
			return $parsed;
		}

		$summary = array(
			'add'       => 0,
			'overwrite' => 0,
			'skip'      => 0,
			'errors'    => array(),
		);

		global $wpdb;
		foreach ( $parsed['options'] as $entry ) {
			$name = (string) ( $entry['option_name'] ?? '' );
			if ( '' === $name ) {
				$summary['errors'][] = __( 'Entry missing option_name.', 'optrion' );
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$exists = $wpdb->get_var(
				$wpdb->prepare( "SELECT 1 FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name )
			);
			if ( null === $exists ) {
				++$summary['add'];
			} else {
				++$summary['overwrite'];
			}
		}
		return $summary;
	}

	/**
	 * Imports the payload. Returns counts of rows added / overwritten / skipped.
	 *
	 * @param string $json      Raw JSON string.
	 * @param bool   $overwrite When true, existing option_name rows are replaced.
	 *
	 * @return array{added:int,overwritten:int,skipped:int,errors:string[]}|WP_Error
	 */
	public static function import( string $json, bool $overwrite = false ) {
		$parsed = self::parse( $json );
		if ( $parsed instanceof WP_Error ) {
			return $parsed;
		}

		$summary = array(
			'added'       => 0,
			'overwritten' => 0,
			'skipped'     => 0,
			'errors'      => array(),
		);

		global $wpdb;
		foreach ( $parsed['options'] as $entry ) {
			$name = (string) ( $entry['option_name'] ?? '' );
			if ( '' === $name ) {
				$summary['errors'][] = __( 'Entry missing option_name.', 'optrion' );
				continue;
			}
			$value    = (string) ( $entry['option_value'] ?? '' );
			$autoload = (string) ( $entry['autoload'] ?? 'no' );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$exists = $wpdb->get_var(
				$wpdb->prepare( "SELECT 1 FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name )
			);
			if ( null === $exists ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$inserted = $wpdb->insert(
					$wpdb->options,
					array(
						'option_name'  => $name,
						'option_value' => $value,
						'autoload'     => $autoload,
					),
					array( '%s', '%s', '%s' )
				);
				if ( false === $inserted ) {
					$summary['errors'][] = sprintf(
						/* translators: %s: option name. */
						__( 'Failed to insert %s.', 'optrion' ),
						$name
					);
					continue;
				}
				wp_cache_delete( $name, 'options' );
				wp_cache_delete( 'alloptions', 'options' );
				++$summary['added'];
				continue;
			}

			if ( ! $overwrite ) {
				++$summary['skipped'];
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$updated = $wpdb->update(
				$wpdb->options,
				array(
					'option_value' => $value,
					'autoload'     => $autoload,
				),
				array( 'option_name' => $name ),
				array( '%s', '%s' ),
				array( '%s' )
			);
			if ( false === $updated ) {
				$summary['errors'][] = sprintf(
					/* translators: %s: option name. */
					__( 'Failed to overwrite %s.', 'optrion' ),
					$name
				);
				continue;
			}
			wp_cache_delete( $name, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			++$summary['overwritten'];
		}

		return $summary;
	}

	/**
	 * Parses and validates the JSON payload.
	 *
	 * @param string $json Raw JSON string.
	 *
	 * @return array{options:array<int,array<string,mixed>>}|WP_Error
	 */
	private static function parse( string $json ) {
		if ( strlen( $json ) > self::MAX_PAYLOAD_BYTES ) {
			return new WP_Error(
				'optrion_payload_too_large',
				sprintf(
					/* translators: %s: human-readable size limit. */
					__( 'Import payload exceeds the %s size limit.', 'optrion' ),
					size_format( self::MAX_PAYLOAD_BYTES )
				)
			);
		}

		$decoded = json_decode( $json, true, self::MAX_JSON_DEPTH );
		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'optrion_invalid_json', __( 'Export payload is not valid JSON.', 'optrion' ) );
		}
		if ( ! isset( $decoded['options'] ) || ! is_array( $decoded['options'] ) ) {
			return new WP_Error( 'optrion_invalid_payload', __( 'Export payload has no options list.', 'optrion' ) );
		}
		return array( 'options' => $decoded['options'] );
	}
}
