<?php
/**
 * Read-tracking module.
 *
 * @package Optrion
 */

declare(strict_types=1);

namespace Optrion;

defined( 'ABSPATH' ) || exit;

/**
 * Observes `get_option()` reads and records per-option usage statistics.
 *
 * Behavior is described in docs/DESIGN.md §4.1:
 *
 * - Autoload options are captured in bulk via the `alloptions` filter.
 * - Non-autoload options are captured by dynamically registered
 *   `option_{$name}` filters on admin_init.
 * - All writes are buffered in memory and flushed once at shutdown.
 * - A transient gate limits tracking to admin traffic (10-minute window)
 *   and an optional sampling rate reduces load further.
 */
final class Tracker {

	/**
	 * Transient key holding the "tracking is active" flag.
	 */
	public const ENABLE_TRANSIENT = 'optrion_tracking_enabled';

	/**
	 * Option key holding the sampling rate (0-100).
	 */
	public const SAMPLING_OPTION = 'optrion_sampling_rate';

	/**
	 * Duration of the admin-triggered tracking window (seconds).
	 */
	public const WINDOW_SECONDS = 600;

	/**
	 * In-memory per-request buffer keyed by option_name.
	 *
	 * @var array<string,array{count:int,last:string,reader:string,type:string}>
	 */
	private static array $buffer = array();

	/**
	 * True once `boot()` has wired hooks for this request.
	 *
	 * @var bool
	 */
	private static bool $booted = false;

	/**
	 * True if sampling allowed this request to be tracked.
	 *
	 * @var bool
	 */
	private static bool $request_sampled = false;

	/**
	 * Registers tracking hooks for the current request.
	 *
	 * Safe to call once on `plugins_loaded`. The individual filter callbacks
	 * short-circuit if tracking is not active so that inactive sites pay only
	 * the cost of a transient lookup.
	 */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		// Admin visits renew the tracking window regardless of sampling.
		add_action( 'admin_init', array( self::class, 'refresh_window' ) );

		if ( ! self::should_track_this_request() ) {
			return;
		}
		self::$request_sampled = true;

		add_filter( 'alloptions', array( self::class, 'record_alloptions' ), 999 );
		add_action( 'admin_init', array( self::class, 'register_non_autoload_hooks' ), 999 );
		add_action( 'shutdown', array( self::class, 'flush' ), 0 );
	}

	/**
	 * Resets per-request state. Intended for tests.
	 */
	public static function reset_for_test(): void {
		self::$buffer          = array();
		self::$booted          = false;
		self::$request_sampled = false;
	}

	/**
	 * Renews the admin-presence transient so tracking runs for WINDOW_SECONDS.
	 */
	public static function refresh_window(): void {
		set_transient( self::ENABLE_TRANSIENT, 1, self::WINDOW_SECONDS );
	}

	/**
	 * Decides whether this request is eligible for tracking.
	 */
	public static function should_track_this_request(): bool {
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			return false;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return false;
		}
		if ( ! get_transient( self::ENABLE_TRANSIENT ) ) {
			return false;
		}

		$rate = (int) get_option( self::SAMPLING_OPTION, 100 );
		if ( $rate >= 100 ) {
			return true;
		}
		if ( $rate <= 0 ) {
			return false;
		}

		return wp_rand( 1, 100 ) <= $rate;
	}

	/**
	 * Filter callback for `alloptions`: records every autoload option name.
	 *
	 * @param mixed $alloptions The raw `alloptions` payload; normally an array.
	 * @return mixed The payload unchanged.
	 */
	public static function record_alloptions( $alloptions ) {
		if ( ! is_array( $alloptions ) ) {
			return $alloptions;
		}
		$reader = self::identify_caller();
		$now    = current_time( 'mysql', true );
		foreach ( array_keys( $alloptions ) as $name ) {
			self::buffer_record( (string) $name, $reader, $now );
		}
		return $alloptions;
	}

	/**
	 * Registers `option_{$name}` filters for every non-autoload option.
	 *
	 * Runs on admin_init so that autoload-flagged rows (already covered by
	 * the `alloptions` filter) are not double-registered.
	 */
	public static function register_non_autoload_hooks(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$names = $wpdb->get_col(
			"SELECT option_name FROM {$wpdb->options} WHERE autoload NOT IN ('yes','on','auto','auto-on')"
		);
		if ( ! is_array( $names ) ) {
			return;
		}
		foreach ( $names as $name ) {
			$name = (string) $name;
			add_filter(
				"option_{$name}",
				static function ( $value ) use ( $name ) {
					$reader = Tracker::identify_caller();
					Tracker::buffer_record( $name, $reader, current_time( 'mysql', true ) );
					return $value;
				},
				999
			);
		}
	}

	/**
	 * Records a single read into the in-memory buffer.
	 *
	 * @param string                         $name   Option name.
	 * @param array{type:string,slug:string} $reader Reader classification.
	 * @param string                         $now    MySQL-formatted UTC timestamp.
	 */
	public static function buffer_record( string $name, array $reader, string $now ): void {
		if ( ! isset( self::$buffer[ $name ] ) ) {
			self::$buffer[ $name ] = array(
				'count'  => 0,
				'last'   => $now,
				'reader' => $reader['slug'],
				'type'   => $reader['type'],
			);
		}
		++self::$buffer[ $name ]['count'];
		self::$buffer[ $name ]['last']   = $now;
		self::$buffer[ $name ]['reader'] = $reader['slug'];
		self::$buffer[ $name ]['type']   = $reader['type'];
	}

	/**
	 * Flushes the buffer into the tracking table as a single upsert.
	 */
	public static function flush(): void {
		if ( empty( self::$buffer ) ) {
			return;
		}
		global $wpdb;
		$table = Schema::tracking_table();

		$placeholders = array();
		$values       = array();
		foreach ( self::$buffer as $name => $entry ) {
			$placeholders[] = '(%s, %s, %d, %s, %s, %s)';
			$values[]       = $name;
			$values[]       = $entry['last'];
			$values[]       = $entry['count'];
			$values[]       = $entry['reader'];
			$values[]       = $entry['type'];
			$values[]       = $entry['last'];
		}

		$sql = "INSERT INTO {$table}"
			. ' (option_name, last_read_at, read_count, last_reader, reader_type, first_seen) VALUES '
			. implode( ',', $placeholders )
			. ' ON DUPLICATE KEY UPDATE'
			. ' last_read_at = VALUES(last_read_at),'
			. ' read_count = read_count + VALUES(read_count),'
			. ' last_reader = VALUES(last_reader),'
			. ' reader_type = VALUES(reader_type)';

		$wpdb->query( $wpdb->prepare( $sql, $values ) ); // phpcs:ignore WordPress.DB

		self::$buffer = array();
	}

	/**
	 * Returns the request-local buffer. Intended for tests.
	 *
	 * @return array<string,array{count:int,last:string,reader:string,type:string}>
	 */
	public static function buffer_snapshot(): array {
		return self::$buffer;
	}

	/**
	 * Inspects the current PHP backtrace to attribute the caller.
	 *
	 * @return array{type:string,slug:string}
	 */
	public static function identify_caller(): array {
		$trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 15 ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		return self::classify_trace( $trace );
	}

	/**
	 * Classifies a backtrace into {type, slug} based on file paths.
	 *
	 * Pure function exposed for unit tests.
	 *
	 * @param array<int,array{file?:string}> $trace debug_backtrace() output.
	 * @return array{type:string,slug:string}
	 */
	public static function classify_trace( array $trace ): array {
		$plugins = self::normalize( defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : '' );
		$mu      = self::normalize( defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : '' );
		$themes  = self::normalize( function_exists( 'get_theme_root' ) ? get_theme_root() : '' );
		$self    = self::normalize( defined( 'OPTRION_DIR' ) ? OPTRION_DIR : '' );

		foreach ( $trace as $frame ) {
			if ( empty( $frame['file'] ) ) {
				continue;
			}
			$file = self::normalize( (string) $frame['file'] );

			if ( '' !== $self && self::starts_with( $file, $self ) ) {
				continue;
			}
			if ( '' !== $plugins && self::starts_with( $file, $plugins ) ) {
				return array(
					'type' => 'plugin',
					'slug' => self::extract_slug( $file, $plugins ),
				);
			}
			if ( '' !== $mu && self::starts_with( $file, $mu ) ) {
				return array(
					'type' => 'plugin',
					'slug' => 'mu:' . self::extract_slug( $file, $mu ),
				);
			}
			if ( '' !== $themes && self::starts_with( $file, $themes ) ) {
				return array(
					'type' => 'theme',
					'slug' => self::extract_slug( $file, $themes ),
				);
			}
		}

		// No plugin / theme / core-plugin frame in the trace: we cannot say who
		// actually owns this read. Returning 'unknown' keeps downstream accessor
		// inference honest — attributing to core here caused nearly every
		// autoloaded option to be mis-labeled as WordPress-Core.
		return array(
			'type' => 'unknown',
			'slug' => '',
		);
	}

	/**
	 * Normalizes a filesystem path to forward slashes with no trailing slash.
	 *
	 * @param string $path Filesystem path.
	 */
	private static function normalize( string $path ): string {
		if ( '' === $path ) {
			return '';
		}
		$path = str_replace( '\\', '/', $path );
		return rtrim( $path, '/' );
	}

	/**
	 * Tests whether $haystack begins with $needle (byte-wise).
	 *
	 * @param string $haystack Subject.
	 * @param string $needle   Prefix.
	 */
	private static function starts_with( string $haystack, string $needle ): bool {
		return 0 === strncmp( $haystack, $needle, strlen( $needle ) );
	}

	/**
	 * Extracts the first path segment beneath a base directory.
	 *
	 * For `/path/to/plugins/foo/bar.php` with base `/path/to/plugins`
	 * this returns `foo`. Single-file plugins (e.g. `hello.php` directly
	 * under the base) return the basename without the `.php` extension.
	 *
	 * @param string $file Full file path.
	 * @param string $base Base directory (already normalized).
	 */
	private static function extract_slug( string $file, string $base ): string {
		$rel = ltrim( substr( $file, strlen( $base ) ), '/' );
		if ( '' === $rel ) {
			return '';
		}
		$slash = strpos( $rel, '/' );
		if ( false === $slash ) {
			return preg_replace( '/\.php$/', '', $rel ) ?? $rel;
		}
		return substr( $rel, 0, $slash );
	}
}
