<?php
/**
 * WP-CLI subcommands for Optrion.
 *
 * @package Optrion
 */

declare(strict_types=1);

namespace Optrion;

use WP_CLI;
use WP_CLI\Utils;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Wraps the plugin's domain modules as `wp optrion <subcommand>` entries.
 */
final class CLI_Command {

	/**
	 * List options with scoring data.
	 *
	 * ## OPTIONS
	 *
	 * [--score-min=<int>]
	 * : Minimum score threshold.
	 *
	 * [--score-max=<int>]
	 * : Maximum score threshold.
	 *
	 * [--owner-type=<type>]
	 * : Filter by owner type.
	 * ---
	 * options:
	 *   - plugin
	 *   - theme
	 *   - core
	 *   - unknown
	 * ---
	 *
	 * [--search=<needle>]
	 * : Substring match on option_name.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 *   - ids
	 * ---
	 *
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Named args.
	 */
	public function list( array $args, array $assoc_args ): void {
		unset( $args );
		$score_min  = isset( $assoc_args['score-min'] ) ? (int) $assoc_args['score-min'] : null;
		$score_max  = isset( $assoc_args['score-max'] ) ? (int) $assoc_args['score-max'] : null;
		$owner_type = isset( $assoc_args['owner-type'] ) ? (string) $assoc_args['owner-type'] : '';
		$search     = isset( $assoc_args['search'] ) ? (string) $assoc_args['search'] : '';
		$format     = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';

		$items = self::collect_scored_rows( $score_min, $score_max, $owner_type, $search );

		if ( 'ids' === $format ) {
			WP_CLI::log( implode( "\n", array_column( $items, 'option_name' ) ) );
			return;
		}

		Utils\format_items(
			$format,
			$items,
			array( 'option_name', 'score', 'label', 'owner_type', 'owner_slug', 'autoload', 'size_bytes', 'last_read_at' )
		);
	}

	/**
	 * Show summary statistics.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Named args.
	 */
	public function stats( array $args, array $assoc_args ): void {
		unset( $args );
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total         = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}" );
		$autoload_size = (int) $wpdb->get_var(
			"SELECT COALESCE(SUM(LENGTH(option_value)), 0) FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto','auto-on')"
		);
		// phpcs:enable
		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';

		$rows = array(
			array(
				'metric' => 'total_options',
				'value'  => (string) $total,
			),
			array(
				'metric' => 'autoload_total_size',
				'value'  => size_format( $autoload_size ),
			),
		);
		Utils\format_items( $format, $rows, array( 'metric', 'value' ) );
	}

	/**
	 * Export options to a JSON file.
	 *
	 * ## OPTIONS
	 *
	 * [--score-min=<int>]
	 * : Export only options with score >= value.
	 *
	 * [--names=<list>]
	 * : Comma-separated option_name list.
	 *
	 * [--output=<path>]
	 * : Destination file. Defaults to stdout.
	 *
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Named args.
	 */
	public function export( array $args, array $assoc_args ): void {
		unset( $args );
		$names = isset( $assoc_args['names'] ) ? array_filter( array_map( 'trim', explode( ',', (string) $assoc_args['names'] ) ) ) : array();
		if ( empty( $names ) && isset( $assoc_args['score-min'] ) ) {
			$items = self::collect_scored_rows( (int) $assoc_args['score-min'], null, '', '' );
			$names = array_column( $items, 'option_name' );
		}
		if ( empty( $names ) ) {
			WP_CLI::error( 'Nothing to export. Provide --names or --score-min.' );
		}

		$json = Exporter::to_json( $names );
		if ( isset( $assoc_args['output'] ) ) {
			$written = file_put_contents( (string) $assoc_args['output'], $json ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			if ( false === $written ) {
				WP_CLI::error( 'Could not write to ' . (string) $assoc_args['output'] );
			}
			WP_CLI::success( sprintf( 'Exported %d option(s) to %s.', count( $names ), (string) $assoc_args['output'] ) );
			return;
		}
		WP_CLI::log( $json );
	}

	/**
	 * Import an Optrion JSON export.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the JSON file.
	 *
	 * [--overwrite]
	 * : Replace existing rows.
	 *
	 * [--dry-run]
	 * : Preview counts without touching the database.
	 *
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Named args.
	 */
	public function import( array $args, array $assoc_args ): void {
		$file = (string) ( $args[0] ?? '' );
		if ( '' === $file || ! is_readable( $file ) ) {
			WP_CLI::error( "File not readable: {$file}" );
		}
		$json = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents

		if ( ! empty( $assoc_args['dry-run'] ) ) {
			$result = Importer::dry_run( $json );
			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result->get_error_message() );
			}
			WP_CLI::log( sprintf( 'Add: %d, Overwrite: %d', $result['add'], $result['overwrite'] ) );
			return;
		}

		$result = Importer::import( $json, ! empty( $assoc_args['overwrite'] ) );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		WP_CLI::success(
			sprintf(
				'Added: %d, Overwritten: %d, Skipped: %d',
				$result['added'],
				$result['overwritten'],
				$result['skipped']
			)
		);
	}

	/**
	 * Delete options above a score threshold (with automatic backup).
	 *
	 * ## OPTIONS
	 *
	 * [--score-min=<int>]
	 * : Delete options with score >= value.
	 *
	 * [--names=<list>]
	 * : Comma-separated option_name list to delete instead.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Named args.
	 */
	public function clean( array $args, array $assoc_args ): void {
		unset( $args );
		$names = isset( $assoc_args['names'] ) ? array_filter( array_map( 'trim', explode( ',', (string) $assoc_args['names'] ) ) ) : array();
		if ( empty( $names ) && isset( $assoc_args['score-min'] ) ) {
			$items = self::collect_scored_rows( (int) $assoc_args['score-min'], null, '', '' );
			$names = array_column( $items, 'option_name' );
		}
		if ( empty( $names ) ) {
			WP_CLI::error( 'Nothing to delete. Provide --names or --score-min.' );
		}

		WP_CLI::confirm( sprintf( 'Delete %d option(s) (with automatic backup)?', count( $names ) ), $assoc_args );

		$result = Cleaner::delete( $names );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		WP_CLI::success(
			sprintf(
				'Deleted: %d, Skipped: %d, Backup: %s',
				$result['deleted'],
				$result['skipped'],
				null === $result['backup_path'] ? '—' : (string) $result['backup_path']
			)
		);
		foreach ( $result['errors'] as $err ) {
			WP_CLI::warning( $err );
		}
	}

	/**
	 * Delete expired transient options.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Named args.
	 */
	public function clean_transients( array $args, array $assoc_args ): void {
		unset( $args );
		global $wpdb;
		$now = time();
		// Find expired _transient_timeout_* rows and collect their transient bases.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$expired = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE (option_name LIKE %s OR option_name LIKE %s) AND option_value < %d",
				$wpdb->esc_like( '_transient_timeout_' ) . '%',
				$wpdb->esc_like( '_site_transient_timeout_' ) . '%',
				$now
			)
		);
		// phpcs:enable
		if ( empty( $expired ) ) {
			WP_CLI::success( 'No expired transients found.' );
			return;
		}
		$targets = array();
		foreach ( $expired as $timeout_name ) {
			$targets[] = (string) $timeout_name;
			// The transient value row pairs with the timeout row.
			$base      = (string) preg_replace( '/^(_site_transient|_transient)_timeout_/', '$1_', (string) $timeout_name );
			$targets[] = $base;
		}
		$targets = array_values( array_unique( $targets ) );

		WP_CLI::confirm( sprintf( 'Delete %d expired transient row(s)?', count( $targets ) ), $assoc_args );
		$result = Cleaner::delete( $targets );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		WP_CLI::success( sprintf( 'Deleted: %d, Backup: %s', $result['deleted'], (string) $result['backup_path'] ) );
	}

	/**
	 * Force one tracker flush for the current request.
	 *
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Named args.
	 */
	public function scan( array $args, array $assoc_args ): void {
		unset( $args, $assoc_args );
		Tracker::flush();
		WP_CLI::success( 'Tracker buffer flushed.' );
	}

	/**
	 * Quarantine one or more options.
	 *
	 * ## OPTIONS
	 *
	 * <names>
	 * : Comma-separated option names.
	 *
	 * [--days=<int>]
	 * : Quarantine window in days (default 7).
	 *
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Named args.
	 */
	public function quarantine( array $args, array $assoc_args ): void {
		$names = array_filter( array_map( 'trim', explode( ',', (string) ( $args[0] ?? '' ) ) ) );
		$days  = isset( $assoc_args['days'] ) ? (int) $assoc_args['days'] : 0;
		if ( empty( $names ) ) {
			WP_CLI::error( 'Provide one or more comma-separated option names.' );
		}
		$ok     = 0;
		$errors = 0;
		foreach ( $names as $name ) {
			$id = Quarantine::quarantine( (string) $name, 0, 0, $days );
			if ( is_wp_error( $id ) ) {
				WP_CLI::warning( $name . ': ' . $id->get_error_message() );
				++$errors;
				continue;
			}
			++$ok;
		}
		WP_CLI::success( sprintf( 'Quarantined: %d, Errors: %d', $ok, $errors ) );
	}

	/**
	 * Collects scored option rows matching filters.
	 *
	 * @param int|null $score_min  Minimum score.
	 * @param int|null $score_max  Maximum score.
	 * @param string   $owner_type Owner type filter.
	 * @param string   $search     Substring filter.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function collect_scored_rows( ?int $score_min, ?int $score_max, string $owner_type, string $search ): array {
		global $wpdb;
		$where  = array();
		$params = array();
		// Transients are managed by the Transient API and are out of scope.
		$where[] = "option_name NOT LIKE '\\_transient\\_%' AND option_name NOT LIKE '\\_site\\_transient\\_%'";
		// Quarantined options are managed separately.
		$where[] = "option_name NOT LIKE '\\_optrion\\_q\\_\\_%'";
		if ( '' !== $search ) {
			$where[]  = 'option_name LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}
		$where_sql = empty( $where ) ? '' : ' WHERE ' . implode( ' AND ', $where );
		$sql       = "SELECT option_name, option_value, autoload FROM {$wpdb->options}{$where_sql}";
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.NotPrepared
		$rows          = empty( $params )
			? $wpdb->get_results( $sql, ARRAY_A )
			: $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		$tracking_rows = $wpdb->get_results( 'SELECT * FROM ' . Schema::tracking_table(), ARRAY_A );
		// phpcs:enable
		$tracking_map = array();
		foreach ( (array) $tracking_rows as $tracking_row ) {
			$tracking_row['read_count']                            = (int) $tracking_row['read_count'];
			$tracking_map[ (string) $tracking_row['option_name'] ] = $tracking_row;
		}
		$context = Scorer::build_context();
		$out     = array();
		foreach ( (array) $rows as $row ) {
			$name = (string) $row['option_name'];
			$size = strlen( (string) $row['option_value'] );
			$t    = $tracking_map[ $name ] ?? null;
			$s    = Scorer::score(
				array(
					'option_name' => $name,
					'size_bytes'  => $size,
					'autoload'    => $row['autoload'],
				),
				$t,
				$context
			);
			if ( null !== $score_min && $s['total'] < $score_min ) {
				continue;
			}
			if ( null !== $score_max && $s['total'] > $score_max ) {
				continue;
			}
			if ( '' !== $owner_type && $owner_type !== $s['owner']['type'] ) {
				continue;
			}
			$out[] = array(
				'option_name'  => $name,
				'score'        => $s['total'],
				'label'        => $s['label'],
				'owner_type'   => $s['owner']['type'],
				'owner_slug'   => $s['owner']['slug'],
				'autoload'     => (string) $row['autoload'],
				'size_bytes'   => $size,
				'last_read_at' => (string) ( $t['last_read_at'] ?? '' ),
			);
		}
		return $out;
	}
}

WP_CLI::add_command( 'optrion', CLI_Command::class );
