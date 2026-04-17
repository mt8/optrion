<?php
/**
 * Cleaner module.
 *
 * @package Optrion
 */

declare(strict_types=1);

namespace Optrion;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Deletes wp_options rows with an automatic JSON backup and a rolling
 * 3-generation retention window.
 *
 * See docs/DESIGN.md §4.4.
 */
final class Cleaner {

	/**
	 * Maximum number of backup files retained on disk.
	 */
	public const BACKUP_RETENTION = 3;

	/**
	 * Subdirectory of wp-content where backups are written.
	 */
	public const BACKUP_SUBDIR = 'optrion-backups';

	/**
	 * Deletes the given option names, after first writing a backup file.
	 *
	 * @param string[] $option_names option_name values to delete.
	 *
	 * @return array{deleted:int,skipped:int,backup_path:?string,errors:string[]}|WP_Error
	 */
	public static function delete( array $option_names ) {
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
				'deleted'     => 0,
				'skipped'     => $skipped,
				'backup_path' => null,
				'errors'      => $errors,
			);
		}

		$backup = self::write_backup( $deletable );
		if ( $backup instanceof WP_Error ) {
			return $backup;
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

		self::prune_old_backups();

		return array(
			'deleted'     => $deleted_count,
			'skipped'     => $skipped,
			'backup_path' => $backup,
			'errors'      => $errors,
		);
	}

	/**
	 * Writes a JSON backup for the given option names and returns its full path.
	 *
	 * @param string[] $option_names option_name values.
	 *
	 * @return string|WP_Error Absolute backup path.
	 */
	public static function write_backup( array $option_names ) {
		$dir = self::backup_dir();
		if ( ! self::ensure_dir( $dir ) ) {
			return new WP_Error( 'optrion_backup_dir_failed', __( 'Could not create backup directory.', 'optrion' ) );
		}

		$json = Exporter::to_json( $option_names );
		$file = trailingslashit( $dir ) . sprintf( 'optrion-backup-%s.json', gmdate( 'Ymd-His' ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$written = file_put_contents( $file, $json, LOCK_EX );
		if ( false === $written ) {
			return new WP_Error( 'optrion_backup_write_failed', __( 'Could not write backup file.', 'optrion' ) );
		}
		return $file;
	}

	/**
	 * Removes backup files beyond the retention window (oldest first).
	 */
	public static function prune_old_backups(): void {
		$dir   = self::backup_dir();
		$files = glob( trailingslashit( $dir ) . 'optrion-backup-*.json' );
		if ( ! is_array( $files ) || count( $files ) <= self::BACKUP_RETENTION ) {
			return;
		}
		sort( $files );
		$excess = count( $files ) - self::BACKUP_RETENTION;
		for ( $i = 0; $i < $excess; $i++ ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $files[ $i ] );
		}
	}

	/**
	 * Absolute path to the backup directory.
	 */
	public static function backup_dir(): string {
		return trailingslashit( WP_CONTENT_DIR ) . self::BACKUP_SUBDIR;
	}

	/**
	 * Ensures the given directory exists and is protected with an index.php file.
	 *
	 * @param string $dir Directory path.
	 */
	private static function ensure_dir( string $dir ): bool {
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		$index = trailingslashit( $dir ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			@file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
		$htaccess = trailingslashit( $dir ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			@file_put_contents( $htaccess, "Deny from all\n" );
		}
		return true;
	}
}
