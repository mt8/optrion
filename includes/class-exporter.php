<?php
/**
 * Export module.
 *
 * @package Optrion
 */

declare(strict_types=1);

namespace Optrion;

defined( 'ABSPATH' ) || exit;

/**
 * Builds JSON exports of wp_options rows with their tracking context.
 *
 * See docs/DESIGN.md §4.3.
 */
final class Exporter {

	/**
	 * Version stamp of the export JSON schema.
	 *
	 * 1.1.0 — drops the legacy `score` object from each option entry.
	 *         Import still accepts 1.0.0 payloads (score is ignored).
	 */
	public const FORMAT_VERSION = '1.1.0';

	/**
	 * Builds the export payload for the given option names.
	 *
	 * @param string[] $option_names option_name values to include.
	 *
	 * @return array<string,mixed> Export payload (not yet JSON-encoded).
	 */
	public static function build_export( array $option_names ): array {
		$option_names = array_values( array_unique( array_filter( array_map( 'strval', $option_names ) ) ) );

		$options = array();
		foreach ( $option_names as $name ) {
			$row = self::fetch_option_row( $name );
			if ( null === $row ) {
				continue;
			}
			$options[] = array(
				'option_name'  => $name,
				'option_value' => (string) $row['option_value'],
				'autoload'     => (string) $row['autoload'],
				'tracking'     => self::fetch_tracking_row( $name ),
			);
		}

		return array(
			'version'     => self::FORMAT_VERSION,
			'exported_at' => gmdate( 'c' ),
			'site_url'    => home_url(),
			'wp_version'  => get_bloginfo( 'version' ),
			'options'     => $options,
		);
	}

	/**
	 * Encodes the build_export() result as pretty-printed JSON.
	 *
	 * @param string[] $option_names option_name values to include.
	 */
	public static function to_json( array $option_names ): string {
		$payload = self::build_export( $option_names );
		return (string) wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Resolves a suggested filename for a given export payload.
	 *
	 * @param string $site_url home URL of the exporting site.
	 */
	public static function suggested_filename( string $site_url ): string {
		$host = wp_parse_url( $site_url, PHP_URL_HOST );
		$host = is_string( $host ) && '' !== $host ? $host : 'site';
		$host = sanitize_file_name( $host );
		return sprintf( 'optrion-export-%s-%s.json', $host, gmdate( 'Ymd-His' ) );
	}

	/**
	 * Reads a wp_options row by name.
	 *
	 * @param string $option_name option_name.
	 *
	 * @return array{option_value:string,autoload:string}|null
	 */
	private static function fetch_option_row( string $option_name ): ?array {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $option_name ),
			ARRAY_A
		);
		// phpcs:enable
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Reads a tracking row by option_name.
	 *
	 * @param string $option_name option_name.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function fetch_tracking_row( string $option_name ): ?array {
		global $wpdb;
		$table = Schema::tracking_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT last_read_at, read_count, last_reader, reader_type FROM {$table} WHERE option_name = %s", $option_name ),
			ARRAY_A
		);
		// phpcs:enable
		if ( ! is_array( $row ) ) {
			return null;
		}
		$row['read_count'] = (int) $row['read_count'];
		return $row;
	}
}
