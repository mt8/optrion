<?php
/**
 * REST API controller.
 *
 * @package Optrion
 */

declare(strict_types=1);

namespace Optrion;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the `/wp-json/optrion/v1/*` endpoints described in docs/DESIGN.md §5.
 */
final class Rest_Controller {

	/**
	 * REST namespace used for every route.
	 */
	public const NAMESPACE_V1 = 'optrion/v1';

	/**
	 * Wires all routes on the `rest_api_init` hook.
	 */
	public static function register_routes(): void {
		$auth = array( self::class, 'require_manage_options' );

		register_rest_route(
			self::NAMESPACE_V1,
			'/options',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'list_options' ),
					'permission_callback' => $auth,
					'args'                => array(
						'page'          => array(
							'type'    => 'integer',
							'default' => 1,
						),
						'per_page'      => array(
							'type'    => 'integer',
							'default' => 50,
						),
						'orderby'       => array(
							'type'    => 'string',
							'default' => 'name',
							'enum'    => array( 'name', 'size', 'last_read', 'accessor' ),
						),
						'order'         => array(
							'type'    => 'string',
							'default' => 'asc',
							'enum'    => array( 'asc', 'desc' ),
						),
						'accessor_type' => array(
							'type' => 'string',
							'enum' => array( 'plugin', 'theme', 'core', 'widget', 'unknown' ),
						),
						'inactive_only' => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'autoload_only' => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'search'        => array(
							'type' => 'string',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( self::class, 'delete_options' ),
					'permission_callback' => $auth,
					'args'                => array(
						'names' => array(
							'required' => true,
							'type'     => 'array',
							'items'    => array( 'type' => 'string' ),
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/options/(?P<name>[^/]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get_option_detail' ),
				'permission_callback' => $auth,
				'args'                => array(
					'name' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/stats',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get_stats' ),
				'permission_callback' => $auth,
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/export',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'export' ),
				'permission_callback' => $auth,
				'args'                => array(
					'names' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/import',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'import' ),
				'permission_callback' => $auth,
				'args'                => array(
					'payload'   => array(
						'required' => true,
						'type'     => 'string',
					),
					'overwrite' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/import/preview',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'import_preview' ),
				'permission_callback' => $auth,
				'args'                => array(
					'payload' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/quarantine',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'list_quarantine' ),
					'permission_callback' => $auth,
					'args'                => array(
						'status' => array(
							'type'    => 'string',
							'default' => 'active',
							'enum'    => array( 'active', 'restored', 'deleted' ),
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'create_quarantine' ),
					'permission_callback' => $auth,
					'args'                => array(
						'names' => array(
							'required' => true,
							'type'     => 'array',
							'items'    => array( 'type' => 'string' ),
						),
						'days'  => array( 'type' => 'integer' ),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( self::class, 'delete_quarantine' ),
					'permission_callback' => $auth,
					'args'                => array(
						'ids' => array(
							'required' => true,
							'type'     => 'array',
							'items'    => array( 'type' => 'integer' ),
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/quarantine/restore',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'restore_quarantine' ),
				'permission_callback' => $auth,
				'args'                => array(
					'ids' => array(
						'required' => true,
						'type'     => 'array',
						'items'    => array( 'type' => 'integer' ),
					),
				),
			)
		);
	}

	/**
	 * Permission callback: allow only users with `manage_options`.
	 */
	public static function require_manage_options(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * GET /options — paginated list with tracking and accessor fields.
	 *
	 * @param WP_REST_Request $req Request.
	 */
	public static function list_options( WP_REST_Request $req ): WP_REST_Response {
		global $wpdb;
		$page          = max( 1, (int) $req['page'] );
		$per_page      = min( 200, max( 1, (int) $req['per_page'] ) );
		$offset        = ( $page - 1 ) * $per_page;
		$search        = (string) $req['search'];
		$accessor_type = (string) $req['accessor_type'];
		$inactive_only = (bool) $req['inactive_only'];
		$autoload_only = (bool) $req['autoload_only'];

		$where  = array();
		$params = array();
		// Transients are managed by the Transient API and are out of scope for Optrion.
		$where[] = "option_name NOT LIKE '\\_transient\\_%' AND option_name NOT LIKE '\\_site\\_transient\\_%'";
		// Quarantined options have their own tab; hide them from the main list.
		$where[] = "option_name NOT LIKE '\\_optrion\\_q\\_\\_%'";
		// Optrion's own internal options are not useful to audit.
		$where[] = "option_name NOT LIKE 'optrion\\_%'";
		if ( '' !== $search ) {
			$where[]  = 'option_name LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		$where_sql = empty( $where ) ? '' : ' WHERE ' . implode( ' AND ', $where );
		$sql       = "SELECT option_name, option_value, autoload FROM {$wpdb->options}{$where_sql} ORDER BY option_name ASC";
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.NotPrepared
		$rows = empty( $params )
			? $wpdb->get_results( $sql, ARRAY_A )
			: $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		// phpcs:enable

		$tracking_map = self::tracking_map();
		$context      = Classifier::build_context();
		$items        = array();
		$autoload_sum = 0;
		foreach ( (array) $rows as $row ) {
			$name        = (string) $row['option_name'];
			$size        = strlen( (string) $row['option_value'] );
			$is_autoload = Classifier::is_autoloaded( (string) $row['autoload'] );
			if ( $is_autoload ) {
				$autoload_sum += $size;
			}
			if ( $autoload_only && ! $is_autoload ) {
				continue;
			}
			$tracking = $tracking_map[ $name ] ?? null;
			$accessor = Classifier::infer_accessor( $name, $tracking, $context );
			$active   = Classifier::accessor_is_active( $accessor, $context );
			if ( '' !== $accessor_type && $accessor_type !== $accessor['type'] ) {
				continue;
			}
			if ( $inactive_only && $active ) {
				continue;
			}
			$items[] = array(
				'option_name' => $name,
				'autoload'    => (string) $row['autoload'],
				'is_autoload' => $is_autoload,
				'size'        => $size,
				'size_human'  => size_format( $size ),
				'accessor'    => array_merge(
					$accessor,
					array(
						'active' => $active,
						'name'   => Classifier::resolve_accessor_name( $accessor, $context ),
					)
				),
				'tracking'    => $tracking,
			);
		}

		$total = count( $items );
		self::sort_items( $items, (string) $req['orderby'], (string) $req['order'] );
		$items = array_slice( $items, $offset, $per_page );

		return new WP_REST_Response(
			array(
				'items'                     => $items,
				'total'                     => $total,
				'autoload_total_size'       => $autoload_sum,
				'autoload_total_size_human' => size_format( $autoload_sum ),
			)
		);
	}

	/**
	 * GET /options/{name}.
	 *
	 * @param WP_REST_Request $req Request.
	 */
	public static function get_option_detail( WP_REST_Request $req ) {
		$name = (string) $req['name'];
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $name ),
			ARRAY_A
		);
		// phpcs:enable
		if ( ! is_array( $row ) ) {
			return new WP_Error( 'optrion_not_found', __( 'Option not found.', 'optrion' ), array( 'status' => 404 ) );
		}
		$size     = strlen( (string) $row['option_value'] );
		$tracking = self::tracking_map( array( $name ) )[ $name ] ?? null;
		$context  = Classifier::build_context();
		$accessor = Classifier::infer_accessor( $name, $tracking, $context );
		return new WP_REST_Response(
			array(
				'option_name'  => $name,
				'option_value' => (string) $row['option_value'],
				'autoload'     => (string) $row['autoload'],
				'is_autoload'  => Classifier::is_autoloaded( (string) $row['autoload'] ),
				'size'         => $size,
				'size_human'   => size_format( $size ),
				'accessor'     => array_merge(
					$accessor,
					array(
						'active' => Classifier::accessor_is_active( $accessor, $context ),
						'name'   => Classifier::resolve_accessor_name( $accessor, $context ),
					)
				),
				'tracking'     => $tracking,
			)
		);
	}

	/**
	 * DELETE /options — bulk delete with auto-backup.
	 *
	 * @param WP_REST_Request $req Request.
	 */
	public static function delete_options( WP_REST_Request $req ) {
		$names  = (array) $req['names'];
		$result = Cleaner::delete( $names );
		if ( $result instanceof WP_Error ) {
			return $result;
		}
		return new WP_REST_Response( $result );
	}

	/**
	 * GET /stats.
	 */
	public static function get_stats(): WP_REST_Response {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total         = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}" );
		$autoload_size = (int) $wpdb->get_var(
			"SELECT COALESCE(SUM(LENGTH(option_value)), 0) FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto','auto-on')"
		);
		// phpcs:enable
		return new WP_REST_Response(
			array(
				'total_options'             => $total,
				'autoload_total_size'       => $autoload_size,
				'autoload_total_size_human' => size_format( $autoload_size ),
			)
		);
	}

	/**
	 * POST /export.
	 *
	 * @param WP_REST_Request $req Request.
	 */
	public static function export( WP_REST_Request $req ): WP_REST_Response {
		$names = (array) ( $req['names'] ?? array() );
		return new WP_REST_Response( Exporter::build_export( $names ) );
	}

	/**
	 * POST /import.
	 *
	 * @param WP_REST_Request $req Request.
	 */
	public static function import( WP_REST_Request $req ) {
		$result = Importer::import( (string) $req['payload'], (bool) $req['overwrite'] );
		if ( $result instanceof WP_Error ) {
			return $result;
		}
		return new WP_REST_Response( $result );
	}

	/**
	 * POST /import/preview.
	 *
	 * @param WP_REST_Request $req Request.
	 */
	public static function import_preview( WP_REST_Request $req ) {
		$result = Importer::dry_run( (string) $req['payload'] );
		if ( $result instanceof WP_Error ) {
			return $result;
		}
		return new WP_REST_Response( $result );
	}

	/**
	 * POST /quarantine — create quarantine entries.
	 *
	 * @param WP_REST_Request $req Request.
	 */
	public static function create_quarantine( WP_REST_Request $req ): WP_REST_Response {
		$names = (array) $req['names'];
		$days  = (int) ( $req['days'] ?? 0 );
		$out   = array(
			'quarantined' => array(),
			'errors'      => array(),
		);
		$user  = get_current_user_id();
		foreach ( $names as $name ) {
			$id = Quarantine::quarantine( (string) $name, $user, $days );
			if ( $id instanceof WP_Error ) {
				$out['errors'][] = array(
					'option_name' => (string) $name,
					'message'     => $id->get_error_message(),
				);
				continue;
			}
			$out['quarantined'][] = array(
				'id'          => (int) $id,
				'option_name' => (string) $name,
			);
		}
		return new WP_REST_Response( $out );
	}

	/**
	 * GET /quarantine — list manifest rows.
	 *
	 * @param WP_REST_Request $req Request.
	 */
	public static function list_quarantine( WP_REST_Request $req ): WP_REST_Response {
		global $wpdb;
		$status = (string) $req['status'];
		$table  = Schema::quarantine_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY quarantined_at DESC", $status ),
			ARRAY_A
		);
		// phpcs:enable

		// Enrich each row with tracking data so the UI can flag options that are
		// still being accessed after quarantine.
		$names        = wp_list_pluck( $rows, 'original_name' );
		$tracking_map = empty( $names ) ? array() : self::tracking_map( $names );
		foreach ( $rows as &$row ) {
			$tracking       = $tracking_map[ $row['original_name'] ] ?? null;
			$last_read      = $tracking ? (string) ( $tracking['last_read_at'] ?? '' ) : '';
			$still_accessed = '' !== $last_read
				&& '' !== $row['quarantined_at']
				&& $last_read > $row['quarantined_at'];

			$row['last_read_at']   = $last_read;
			$row['still_accessed'] = $still_accessed;
		}
		unset( $row );

		return new WP_REST_Response( array( 'items' => (array) $rows ) );
	}

	/**
	 * POST /quarantine/restore.
	 *
	 * @param WP_REST_Request $req Request.
	 */
	public static function restore_quarantine( WP_REST_Request $req ): WP_REST_Response {
		$out = array(
			'restored' => array(),
			'errors'   => array(),
		);
		foreach ( (array) $req['ids'] as $id ) {
			$result = Quarantine::restore( (int) $id );
			if ( $result instanceof WP_Error ) {
				$out['errors'][] = array(
					'id'      => (int) $id,
					'message' => $result->get_error_message(),
				);
				continue;
			}
			$out['restored'][] = (int) $id;
		}
		return new WP_REST_Response( $out );
	}

	/**
	 * DELETE /quarantine — permanent delete from quarantine.
	 *
	 * @param WP_REST_Request $req Request.
	 */
	public static function delete_quarantine( WP_REST_Request $req ): WP_REST_Response {
		$out = array(
			'deleted' => array(),
			'errors'  => array(),
		);
		foreach ( (array) $req['ids'] as $id ) {
			$result = Quarantine::delete_permanently( (int) $id );
			if ( $result instanceof WP_Error ) {
				$out['errors'][] = array(
					'id'      => (int) $id,
					'message' => $result->get_error_message(),
				);
				continue;
			}
			$out['deleted'][] = (int) $id;
		}
		return new WP_REST_Response( $out );
	}

	/**
	 * Builds a map {option_name => tracking row}. Optionally limited to a name set.
	 *
	 * @param string[] $names Subset of option names, or empty for "all".
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function tracking_map( array $names = array() ): array {
		global $wpdb;
		$table = Schema::tracking_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		if ( empty( $names ) ) {
			$rows = $wpdb->get_results( "SELECT * FROM {$table}", ARRAY_A );
		} else {
			$placeholders = implode( ',', array_fill( 0, count( $names ), '%s' ) );
			$rows         = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE option_name IN ({$placeholders})", $names ),
				ARRAY_A
			);
		}
		// phpcs:enable
		$map = array();
		foreach ( (array) $rows as $row ) {
			$row['read_count']                   = (int) $row['read_count'];
			$map[ (string) $row['option_name'] ] = $row;
		}
		return $map;
	}

	/**
	 * Sorts the option list in place by the requested column.
	 *
	 * @param array<int,array<string,mixed>> $items   Reference to items list.
	 * @param string                         $orderby Column key.
	 * @param string                         $order   asc|desc.
	 */
	private static function sort_items( array &$items, string $orderby, string $order ): void {
		$sign = 'asc' === $order ? 1 : -1;
		usort(
			$items,
			static function ( array $a, array $b ) use ( $orderby, $sign ): int {
				switch ( $orderby ) {
					case 'size':
						return $sign * ( $a['size'] <=> $b['size'] );
					case 'accessor':
						// Sort on the visible label: the resolved accessor name falls
						// back to the slug, then to the type. Case-insensitive so
						// "WooCommerce" and "wordfence" sort naturally against the
						// lowercase slugs rendered for unknown accessors.
						$label_a = self::accessor_sort_label( $a['accessor'] ?? array() );
						$label_b = self::accessor_sort_label( $b['accessor'] ?? array() );
						return $sign * strcasecmp( $label_a, $label_b );
					case 'last_read':
						$la = (string) ( $a['tracking']['last_read_at'] ?? '' );
						$lb = (string) ( $b['tracking']['last_read_at'] ?? '' );
						return $sign * strcmp( $la, $lb );
					case 'name':
					default:
						return $sign * strcmp( (string) $a['option_name'], (string) $b['option_name'] );
				}
			}
		);
	}

	/**
	 * Returns the string used to sort an accessor cell.
	 *
	 * Matches what the UI displays: `name` when available, otherwise `slug`,
	 * otherwise the accessor type.
	 *
	 * @param array<string,mixed> $accessor Accessor payload from list_options().
	 */
	private static function accessor_sort_label( array $accessor ): string {
		foreach ( array( 'name', 'slug', 'type' ) as $key ) {
			$value = isset( $accessor[ $key ] ) ? (string) $accessor[ $key ] : '';
			if ( '' !== $value ) {
				return $value;
			}
		}
		return '';
	}
}
