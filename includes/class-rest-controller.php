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
						'page'       => array(
							'type'    => 'integer',
							'default' => 1,
						),
						'per_page'   => array(
							'type'    => 'integer',
							'default' => 50,
						),
						'orderby'    => array(
							'type'    => 'string',
							'default' => 'score',
							'enum'    => array( 'score', 'name', 'size', 'last_read' ),
						),
						'order'      => array(
							'type'    => 'string',
							'default' => 'desc',
							'enum'    => array( 'asc', 'desc' ),
						),
						'score_min'  => array(
							'type' => 'integer',
						),
						'score_max'  => array(
							'type' => 'integer',
						),
						'owner_type' => array(
							'type' => 'string',
							'enum' => array( 'plugin', 'theme', 'core', 'unknown' ),
						),
						'search'     => array(
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
					'names'     => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'score_min' => array( 'type' => 'integer' ),
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
	 * GET /options — paginated list with tracking + score.
	 *
	 * @param WP_REST_Request $req Request.
	 */
	public static function list_options( WP_REST_Request $req ): WP_REST_Response {
		global $wpdb;
		$page       = max( 1, (int) $req['page'] );
		$per_page   = min( 200, max( 1, (int) $req['per_page'] ) );
		$offset     = ( $page - 1 ) * $per_page;
		$search     = (string) $req['search'];
		$score_min  = null !== $req['score_min'] ? (int) $req['score_min'] : null;
		$score_max  = null !== $req['score_max'] ? (int) $req['score_max'] : null;
		$owner_type = (string) $req['owner_type'];

		$where  = array();
		$params = array();
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
		$context      = Scorer::build_context();
		$items        = array();
		$autoload_sum = 0;
		foreach ( (array) $rows as $row ) {
			$name = (string) $row['option_name'];
			$size = strlen( (string) $row['option_value'] );
			if ( Scorer::is_autoloaded( (string) $row['autoload'] ) ) {
				$autoload_sum += $size;
			}
			$tracking = $tracking_map[ $name ] ?? null;
			$score    = Scorer::score(
				array(
					'option_name' => $name,
					'size_bytes'  => $size,
					'autoload'    => $row['autoload'],
				),
				$tracking,
				$context
			);
			if ( null !== $score_min && $score['total'] < $score_min ) {
				continue;
			}
			if ( null !== $score_max && $score['total'] > $score_max ) {
				continue;
			}
			if ( '' !== $owner_type && $owner_type !== $score['owner']['type'] ) {
				continue;
			}
			$items[] = array(
				'option_name' => $name,
				'autoload'    => (string) $row['autoload'],
				'size'        => $size,
				'size_human'  => size_format( $size ),
				'owner'       => array_merge(
					$score['owner'],
					array(
						'active' => self::owner_is_active( $score['owner'], $context ),
					)
				),
				'tracking'    => $tracking,
				'score'       => array(
					'total'     => $score['total'],
					'label'     => $score['label'],
					'breakdown' => $score['breakdown'],
				),
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
		$context  = Scorer::build_context();
		$score    = Scorer::score(
			array(
				'option_name' => $name,
				'size_bytes'  => $size,
				'autoload'    => $row['autoload'],
			),
			$tracking,
			$context
		);
		return new WP_REST_Response(
			array(
				'option_name'  => $name,
				'option_value' => (string) $row['option_value'],
				'autoload'     => (string) $row['autoload'],
				'size'         => $size,
				'size_human'   => size_format( $size ),
				'owner'        => array_merge(
					$score['owner'],
					array(
						'active' => self::owner_is_active( $score['owner'], $context ),
					)
				),
				'tracking'     => $tracking,
				'score'        => $score,
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
		if ( empty( $names ) && null !== $req['score_min'] ) {
			$names = self::collect_names_by_score_min( (int) $req['score_min'] );
		}
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
			$id = Quarantine::quarantine( (string) $name, $user, 0, $days );
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
					case 'name':
						return $sign * strcmp( (string) $a['option_name'], (string) $b['option_name'] );
					case 'size':
						return $sign * ( $a['size'] <=> $b['size'] );
					case 'last_read':
						$la = (string) ( $a['tracking']['last_read_at'] ?? '' );
						$lb = (string) ( $b['tracking']['last_read_at'] ?? '' );
						return $sign * strcmp( $la, $lb );
					case 'score':
					default:
						return $sign * ( $a['score']['total'] <=> $b['score']['total'] );
				}
			}
		);
	}

	/**
	 * Resolves the "active" flag for an inferred owner.
	 *
	 * @param array{type:string,slug:string}                                  $owner   Inferred owner.
	 * @param array{active_plugin_slugs:string[],active_theme_slugs:string[]} $context Live site context.
	 */
	private static function owner_is_active( array $owner, array $context ): bool {
		switch ( $owner['type'] ) {
			case 'core':
				return true;
			case 'plugin':
				return in_array( $owner['slug'], $context['active_plugin_slugs'] ?? array(), true );
			case 'theme':
				return in_array( $owner['slug'], $context['active_theme_slugs'] ?? array(), true );
			default:
				return false;
		}
	}

	/**
	 * Collects all option names whose score is at least `$score_min`.
	 *
	 * @param int $score_min Lower bound.
	 *
	 * @return string[]
	 */
	private static function collect_names_by_score_min( int $score_min ): array {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT option_name, option_value, autoload FROM {$wpdb->options}", ARRAY_A );
		// phpcs:enable
		$tracking = self::tracking_map();
		$context  = Scorer::build_context();
		$names    = array();
		foreach ( (array) $rows as $row ) {
			$name  = (string) $row['option_name'];
			$score = Scorer::score(
				array(
					'option_name' => $name,
					'size_bytes'  => strlen( (string) $row['option_value'] ),
					'autoload'    => $row['autoload'],
				),
				$tracking[ $name ] ?? null,
				$context
			);
			if ( $score['total'] >= $score_min ) {
				$names[] = $name;
			}
		}
		return $names;
	}
}
