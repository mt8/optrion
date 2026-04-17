<?php
/**
 * Quarantine module.
 *
 * @package Optrion
 */

declare(strict_types=1);

namespace Optrion;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Soft-disables a wp_options row by renaming it, tracks the manifest,
 * and restores (or permanently deletes) it on demand or on expiry.
 *
 * Behavior is described in docs/DESIGN.md §4.5.
 */
final class Quarantine {

	/**
	 * Rename prefix applied to quarantined option rows.
	 */
	public const RENAME_PREFIX = '_optrion_q__';

	/**
	 * Status of a manifest row when it is actively holding a renamed option.
	 */
	public const STATUS_ACTIVE = 'active';

	/**
	 * Status after the option has been restored to its original name.
	 */
	public const STATUS_RESTORED = 'restored';

	/**
	 * Status after the option has been permanently deleted.
	 */
	public const STATUS_DELETED = 'deleted';

	/**
	 * Maximum length of the original option_name that can safely be prefixed.
	 *
	 * The wp_options.option_name column is VARCHAR(191); minus the 12-char
	 * rename prefix and one underscore separator, 178 chars remain usable.
	 */
	public const MAX_ORIGINAL_LENGTH = 178;

	/**
	 * Hard cap on the number of simultaneously active quarantines.
	 */
	public const MAX_ACTIVE_QUARANTINES = 50;

	/**
	 * Default quarantine window in days.
	 */
	public const DEFAULT_EXPIRY_DAYS = 7;

	/**
	 * Option key: number of days until auto-expiry.
	 */
	public const EXPIRY_DAYS_OPTION = 'optrion_quarantine_expiry_days';

	/**
	 * Option key: action on expiry (`restore`, `delete`, or `keep`).
	 */
	public const EXPIRY_ACTION_OPTION = 'optrion_quarantine_expiry_action';

	/**
	 * In-memory cache of active-quarantine values keyed by original_name.
	 *
	 * Populated once per request in {@see self::register_active_filters()}
	 * so the pre_option filter callback does not hit the DB on every read.
	 *
	 * @var array<string,mixed>
	 */
	private static array $filter_value_cache = array();

	/**
	 * Access accumulator buffered during the request and flushed on shutdown.
	 *
	 * Keyed by original_name. Each entry records the latest accessor, the
	 * access timestamp, and the per-request access count so the shutdown
	 * handler can update the manifest in one pass.
	 *
	 * @var array<string,array{count:int,last:string,reader:string,type:string}>
	 */
	private static array $access_buffer = array();

	/**
	 * Tracks whether the shutdown flush has already been registered.
	 *
	 * @var bool
	 */
	private static bool $flush_registered = false;

	/**
	 * Cron hook that runs the daily expiry sweep.
	 */
	public const CRON_HOOK = 'optrion_quarantine_check';

	/**
	 * Moves an option into quarantine.
	 *
	 * @param string $option_name  Live option_name in wp_options.
	 * @param int    $user_id      Admin user performing the action (0 if unattributed).
	 * @param int    $expires_days Override for the default expiry window (0 → use setting).
	 *
	 * @return int|WP_Error Manifest ID on success, WP_Error otherwise.
	 */
	public static function quarantine( string $option_name, int $user_id = 0, int $expires_days = 0 ) {
		if ( ! self::is_quarantinable( $option_name ) ) {
			return new WP_Error( 'optrion_not_quarantinable', self::reason_cannot_quarantine( $option_name ) );
		}

		if ( self::active_count() >= self::MAX_ACTIVE_QUARANTINES ) {
			return new WP_Error(
				'optrion_quarantine_full',
				sprintf(
					/* translators: %d: maximum number of simultaneous quarantines. */
					__( 'Quarantine limit reached (%d active).', 'optrion' ),
					self::MAX_ACTIVE_QUARANTINES
				)
			);
		}

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$autoload = $wpdb->get_var(
			$wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $option_name )
		);
		if ( null === $autoload ) {
			return new WP_Error( 'optrion_option_missing', __( 'Option not found in wp_options.', 'optrion' ) );
		}

		$renamed = self::RENAME_PREFIX . $option_name;

		// Use wpdb->update so that the row is locked atomically.
		$updated = $wpdb->update(
			$wpdb->options,
			array(
				'option_name' => $renamed,
				'autoload'    => 'no',
			),
			array( 'option_name' => $option_name ),
			array( '%s', '%s' ),
			array( '%s' )
		);
		// phpcs:enable

		if ( false === $updated || 0 === $updated ) {
			return new WP_Error( 'optrion_rename_failed', __( 'Could not rename the option row.', 'optrion' ) );
		}

		wp_cache_delete( $option_name, 'options' );
		wp_cache_delete( 'alloptions', 'options' );

		$days = $expires_days > 0 ? $expires_days : self::configured_expiry_days();
		$now  = current_time( 'mysql', true );
		$exp  = gmdate( 'Y-m-d H:i:s', strtotime( $now . ' +' . $days . ' days' ) );

		// Remove any stale manifest row (restored / deleted) so the UNIQUE KEY
		// on original_name does not block re-quarantine.
		$q_table = Schema::quarantine_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$q_table} WHERE original_name = %s AND status != %s",
				$option_name,
				self::STATUS_ACTIVE
			)
		);
		// phpcs:enable

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->insert(
			Schema::quarantine_table(),
			array(
				'original_name'     => $option_name,
				'original_autoload' => (string) $autoload,
				'quarantined_at'    => $now,
				'expires_at'        => $exp,
				'quarantined_by'    => $user_id,
				'status'            => self::STATUS_ACTIVE,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		if ( false === $inserted ) {
			// Roll back the rename so state does not diverge from the manifest.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->options,
				array(
					'option_name' => $option_name,
					'autoload'    => (string) $autoload,
				),
				array( 'option_name' => $renamed ),
				array( '%s', '%s' ),
				array( '%s' )
			);
			return new WP_Error( 'optrion_manifest_failed', __( 'Could not record the quarantine manifest.', 'optrion' ) );
		}

		// Prime the transparent-read filter for the remainder of the request so
		// code that calls `get_option($option_name)` after this point still
		// sees the original value.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$stored                                   = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $renamed )
		);
		self::$filter_value_cache[ $option_name ] = is_string( $stored ) ? maybe_unserialize( $stored ) : false;
		self::attach_pre_option_filter( $option_name );
		if ( ! self::$flush_registered ) {
			self::$flush_registered = true;
			add_action( 'shutdown', array( self::class, 'flush_access_buffer' ), 1 );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Restores a quarantined option to its original name and autoload value.
	 *
	 * @param int $manifest_id Manifest row ID.
	 *
	 * @return true|WP_Error
	 */
	public static function restore( int $manifest_id ) {
		$manifest = self::get_manifest( $manifest_id );
		if ( null === $manifest ) {
			return new WP_Error( 'optrion_manifest_missing', __( 'Quarantine manifest row not found.', 'optrion' ) );
		}
		if ( self::STATUS_ACTIVE !== $manifest['status'] ) {
			return new WP_Error( 'optrion_not_active', __( 'Quarantine entry is not active.', 'optrion' ) );
		}

		global $wpdb;
		$original = $manifest['original_name'];
		$renamed  = self::RENAME_PREFIX . $original;

		// Check whether the original option was recreated while quarantined
		// (e.g. WordPress core re-creates missing options on the next load).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$original_exists = (bool) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s", $original )
		);

		if ( $original_exists ) {
			// The original was recreated. Drop the quarantined copy and mark as
			// restored since the option is already back in its rightful place.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete(
				$wpdb->options,
				array( 'option_name' => $renamed ),
				array( '%s' )
			);
		} else {
			// Normal path: rename the quarantined row back to its original name.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$updated = $wpdb->update(
				$wpdb->options,
				array(
					'option_name' => $original,
					'autoload'    => $manifest['original_autoload'],
				),
				array( 'option_name' => $renamed ),
				array( '%s', '%s' ),
				array( '%s' )
			);
			if ( false === $updated || 0 === $updated ) {
				return new WP_Error( 'optrion_restore_failed', __( 'Could not rename the option row back.', 'optrion' ) );
			}
		}

		wp_cache_delete( $original, 'options' );
		wp_cache_delete( 'alloptions', 'options' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			Schema::quarantine_table(),
			array(
				'status'      => self::STATUS_RESTORED,
				'restored_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $manifest_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		// Release the transparent-read cache so subsequent get_option()
		// calls in this request hit wp_options directly.
		unset( self::$filter_value_cache[ $original ], self::$access_buffer[ $original ] );

		return true;
	}

	/**
	 * Permanently deletes a quarantined option row.
	 *
	 * @param int $manifest_id Manifest row ID.
	 *
	 * @return true|WP_Error
	 */
	public static function delete_permanently( int $manifest_id ) {
		$manifest = self::get_manifest( $manifest_id );
		if ( null === $manifest ) {
			return new WP_Error( 'optrion_manifest_missing', __( 'Quarantine manifest row not found.', 'optrion' ) );
		}
		if ( self::STATUS_ACTIVE !== $manifest['status'] ) {
			return new WP_Error( 'optrion_not_active', __( 'Quarantine entry is not active.', 'optrion' ) );
		}

		// Block deletion when tracking shows the option was read after quarantine.
		if ( self::is_still_accessed( $manifest ) ) {
			return new WP_Error(
				'optrion_still_accessed',
				__( 'This option is still being accessed. Restore it instead of deleting.', 'optrion' )
			);
		}

		global $wpdb;
		$renamed = self::RENAME_PREFIX . $manifest['original_name'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->delete(
			$wpdb->options,
			array( 'option_name' => $renamed ),
			array( '%s' )
		);
		if ( false === $deleted ) {
			return new WP_Error( 'optrion_delete_failed', __( 'Could not delete the quarantined option row.', 'optrion' ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			Schema::quarantine_table(),
			array(
				'status'     => self::STATUS_DELETED,
				'deleted_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $manifest_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		// Release the transparent-read cache: the renamed row is gone, so
		// any remaining get_option() call in this request should fall
		// through to wp_options and miss, returning the documented default.
		unset( self::$filter_value_cache[ $manifest['original_name'] ], self::$access_buffer[ $manifest['original_name'] ] );

		return true;
	}

	/**
	 * Processes expired quarantines according to the configured expiry action.
	 *
	 * @return array{processed:int,restored:int,deleted:int,kept:int}
	 */
	public static function process_expired(): array {
		$action  = self::configured_expiry_action();
		$summary = array(
			'processed' => 0,
			'restored'  => 0,
			'deleted'   => 0,
			'kept'      => 0,
		);
		if ( 'keep' === $action ) {
			return $summary;
		}

		global $wpdb;
		$table = Schema::quarantine_table();
		// Skip entries that have been accessed during the window. An access
		// means the option is still in use — auto-expiry would either delete
		// a live option or flip-flop the site by restoring it without the
		// admin's knowledge. Hold them until the admin restores manually.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE status = %s AND expires_at <= %s AND last_accessed_at IS NULL",
				self::STATUS_ACTIVE,
				current_time( 'mysql', true )
			),
			ARRAY_A
		);
		// phpcs:enable
		if ( empty( $rows ) ) {
			return $summary;
		}

		foreach ( $rows as $row ) {
			$id = (int) $row['id'];
			++$summary['processed'];
			$result = 'delete' === $action
				? self::delete_permanently( $id )
				: self::restore( $id );
			if ( is_wp_error( $result ) ) {
				++$summary['kept'];
				continue;
			}
			if ( 'delete' === $action ) {
				++$summary['deleted'];
			} else {
				++$summary['restored'];
			}
		}

		return $summary;
	}

	/**
	 * Schedules the daily expiry cron job if it is not already scheduled.
	 */
	public static function schedule_cron(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Removes the expiry cron job.
	 */
	public static function unschedule_cron(): void {
		$next = wp_next_scheduled( self::CRON_HOOK );
		if ( false !== $next ) {
			wp_unschedule_event( $next, self::CRON_HOOK );
		}
	}

	/**
	 * Returns true when the option can be quarantined.
	 *
	 * @param string $option_name Option name to check.
	 */
	public static function is_quarantinable( string $option_name ): bool {
		if ( '' === $option_name ) {
			return false;
		}
		if ( strlen( $option_name ) > self::MAX_ORIGINAL_LENGTH ) {
			return false;
		}
		if ( 0 === strpos( $option_name, self::RENAME_PREFIX ) ) {
			return false;
		}
		if ( CoreOptions::contains( $option_name ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Returns the count of currently active quarantine entries.
	 */
	public static function active_count(): int {
		global $wpdb;
		$table = Schema::quarantine_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", self::STATUS_ACTIVE )
		);
		// phpcs:enable
	}

	/**
	 * Fetches a manifest row as an associative array.
	 *
	 * @param int $manifest_id Manifest row ID.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function get_manifest( int $manifest_id ): ?array {
		global $wpdb;
		$table = Schema::quarantine_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $manifest_id ),
			ARRAY_A
		);
		// phpcs:enable
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Resolves the configured expiry window in days, clamped to 1-30.
	 */
	public static function configured_expiry_days(): int {
		$days = (int) get_option( self::EXPIRY_DAYS_OPTION, self::DEFAULT_EXPIRY_DAYS );
		if ( $days < 1 ) {
			return 1;
		}
		if ( $days > 30 ) {
			return 30;
		}
		return $days;
	}

	/**
	 * Resolves the configured expiry action (`restore`, `delete`, `keep`).
	 */
	public static function configured_expiry_action(): string {
		$action = (string) get_option( self::EXPIRY_ACTION_OPTION, 'restore' );
		return in_array( $action, array( 'restore', 'delete', 'keep' ), true ) ? $action : 'restore';
	}

	/**
	 * Explains why an option cannot be quarantined. Used for error messages.
	 *
	 * @param string $option_name Option name.
	 */
	/**
	 * Checks whether a quarantined option has been accessed during the window.
	 *
	 * Consults the manifest row directly: the pre_option filter set up by
	 * {@see self::register_active_filters()} populates `last_accessed_at`
	 * on every access during the quarantine window.
	 *
	 * @param array{last_accessed_at?:string} $manifest Manifest row.
	 */
	public static function is_still_accessed( array $manifest ): bool {
		return ! empty( $manifest['last_accessed_at'] );
	}

	/**
	 * Installs `pre_option_{name}` filters for every active quarantine.
	 *
	 * Called once per request from {@see Plugin::boot()}. Each filter
	 * returns the value of the renamed `_optrion_q__<name>` row so that
	 * calls to `get_option()` during the window behave as if the option
	 * had never been quarantined. Every hit is buffered and flushed to
	 * the manifest at shutdown, giving us an observational record of who
	 * is still accessing the option.
	 */
	public static function register_active_filters(): void {
		global $wpdb;
		$table  = Schema::quarantine_table();
		$prefix = self::RENAME_PREFIX;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT original_name FROM {$table} WHERE status = %s",
				self::STATUS_ACTIVE
			),
			ARRAY_A
		);
		// phpcs:enable
		if ( empty( $rows ) ) {
			return;
		}

		foreach ( (array) $rows as $row ) {
			$original = (string) $row['original_name'];
			if ( '' === $original ) {
				continue;
			}
			$renamed = $prefix . $original;

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$stored = $wpdb->get_var(
				$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $renamed )
			);
			// phpcs:enable
			self::$filter_value_cache[ $original ] = is_string( $stored ) ? maybe_unserialize( $stored ) : false;

			self::attach_pre_option_filter( $original );
		}

		if ( ! self::$flush_registered ) {
			self::$flush_registered = true;
			add_action( 'shutdown', array( self::class, 'flush_access_buffer' ), 1 );
		}
	}

	/**
	 * Wires a `pre_option_{$original}` filter that returns the quarantined value.
	 *
	 * If the cache entry disappears (for example, the option was restored
	 * or permanently deleted during this request), the callback short-
	 * circuits with the incoming `$pre` so core's normal lookup runs.
	 *
	 * @param string $original_name Original option name.
	 */
	private static function attach_pre_option_filter( string $original_name ): void {
		add_filter(
			"pre_option_{$original_name}",
			static function ( $pre ) use ( $original_name ) {
				if ( false !== $pre ) {
					return $pre;
				}
				if ( ! array_key_exists( $original_name, Quarantine::$filter_value_cache ) ) {
					return $pre;
				}
				Quarantine::record_access( $original_name );
				return Quarantine::$filter_value_cache[ $original_name ];
			}
		);
	}

	/**
	 * Records an access against a quarantined option in the per-request buffer.
	 *
	 * @param string $original_name Original option name.
	 */
	public static function record_access( string $original_name ): void {
		$reader = Tracker::identify_caller();
		$now    = current_time( 'mysql', true );

		if ( ! isset( self::$access_buffer[ $original_name ] ) ) {
			self::$access_buffer[ $original_name ] = array(
				'count'  => 0,
				'last'   => $now,
				'reader' => '',
				'type'   => 'unknown',
			);
		}
		++self::$access_buffer[ $original_name ]['count'];
		self::$access_buffer[ $original_name ]['last'] = $now;
		// Prefer concrete attribution (plugin/theme) over unknown/core reads.
		$incoming = (string) ( $reader['type'] ?? 'unknown' );
		$current  = (string) self::$access_buffer[ $original_name ]['type'];
		$concrete = array( 'plugin', 'theme' );
		if ( in_array( $incoming, $concrete, true ) || ! in_array( $current, $concrete, true ) ) {
			self::$access_buffer[ $original_name ]['reader'] = (string) ( $reader['slug'] ?? '' );
			self::$access_buffer[ $original_name ]['type']   = $incoming;
		}
	}

	/**
	 * Flushes the per-request access buffer into the quarantine manifest.
	 */
	public static function flush_access_buffer(): void {
		if ( empty( self::$access_buffer ) ) {
			return;
		}
		global $wpdb;
		$table = Schema::quarantine_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		foreach ( self::$access_buffer as $name => $entry ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET
						last_accessed_at = %s,
						accessor_during_quarantine = %s,
						accessor_type_during_quarantine = %s,
						access_count_during_quarantine = access_count_during_quarantine + %d
					WHERE original_name = %s AND status = %s",
					$entry['last'],
					$entry['reader'],
					$entry['type'],
					$entry['count'],
					$name,
					self::STATUS_ACTIVE
				)
			);
		}
		// phpcs:enable
		self::$access_buffer = array();
	}

	/**
	 * Resets request-local state. Intended for tests.
	 */
	public static function reset_for_test(): void {
		self::$filter_value_cache = array();
		self::$access_buffer      = array();
		self::$flush_registered   = false;
	}

	/**
	 * Explains why an option cannot be quarantined. Used for error messages.
	 *
	 * @param string $option_name Option name.
	 */
	private static function reason_cannot_quarantine( string $option_name ): string {
		if ( '' === $option_name ) {
			return __( 'Option name is empty.', 'optrion' );
		}
		if ( 0 === strpos( $option_name, self::RENAME_PREFIX ) ) {
			return __( 'Option is already quarantined.', 'optrion' );
		}
		if ( strlen( $option_name ) > self::MAX_ORIGINAL_LENGTH ) {
			return __( 'Option name is too long to safely quarantine.', 'optrion' );
		}
		if ( CoreOptions::contains( $option_name ) ) {
			return __( 'WordPress core options cannot be quarantined.', 'optrion' );
		}
		return __( 'Option cannot be quarantined.', 'optrion' );
	}
}
