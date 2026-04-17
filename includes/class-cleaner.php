<?php
/**
 * Cleaner module.
 *
 * @package Optrion
 */

declare(strict_types=1);

namespace Optrion;

defined( 'ABSPATH' ) || exit;

/**
 * Deletes wp_options rows.
 *
 * Optrion deliberately never persists option_value content to the server
 * filesystem. Administrators who want a safety copy export the rows
 * through the admin UI (Options → Export selected → browser download)
 * or via WP-CLI (`wp optrion export --output=<path>` with an explicit
 * operator-owned destination) before calling this module. See
 * docs/DESIGN.md §4.4 / §7.
 */
final class Cleaner {

	/**
	 * Deletes the given option names from `wp_options` and the tracking table.
	 *
	 * Core options (per `CoreOptions`) are always skipped. Callers are
	 * responsible for exporting a backup beforehand if they want a
	 * restore path — Cleaner does not write anything to disk.
	 *
	 * @param string[] $option_names option_name values to delete.
	 *
	 * @return array{deleted:int,skipped:int,errors:string[]}
	 */
	public static function delete( array $option_names ): array {
		$option_names = array_values( array_unique( array_filter( array_map( 'strval', $option_names ) ) ) );

		$deletable = array();
		$skipped   = 0;
		$errors    = array();
		foreach ( $option_names as $name ) {
			if ( CoreOptions::contains( $name ) ) {
				++$skipped;
				$errors[] = sprintf(
					/* translators: %s: option_name that was protected. */
					__( 'Skipped core option: %s', 'optrion' ),
					$name
				);
				continue;
			}
			$deletable[] = $name;
		}

		if ( empty( $deletable ) ) {
			return array(
				'deleted' => 0,
				'skipped' => $skipped,
				'errors'  => $errors,
			);
		}

		global $wpdb;
		$deleted_count = 0;
		$tracking      = Schema::tracking_table();
		foreach ( $deletable as $name ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$deleted = $wpdb->delete(
				$wpdb->options,
				array( 'option_name' => $name ),
				array( '%s' )
			);
			if ( false === $deleted || 0 === $deleted ) {
				$errors[] = sprintf(
					/* translators: %s: option_name that could not be deleted. */
					__( 'Failed to delete %s.', 'optrion' ),
					$name
				);
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete(
				$tracking,
				array( 'option_name' => $name ),
				array( '%s' )
			);
			wp_cache_delete( $name, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			++$deleted_count;
		}

		return array(
			'deleted' => $deleted_count,
			'skipped' => $skipped,
			'errors'  => $errors,
		);
	}
}
