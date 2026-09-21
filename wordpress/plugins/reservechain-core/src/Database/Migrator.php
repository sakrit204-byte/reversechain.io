<?php
/**
 * Forward-only SQL migration runner.
 *
 * @package ReserveChain\Core
 */

declare( strict_types = 1 );

namespace ReserveChain\Core\Database;

use RuntimeException;

/**
 * Applies versioned .sql files from the plugin's migrations directory.
 *
 * Why not dbDelta()
 * -----------------
 * WordPress ships dbDelta(), and for a handful of flat tables it is fine. It
 * cannot express what this registry needs: foreign keys, ENUM columns,
 * triggers, composite unique constraints and ON DELETE RESTRICT. dbDelta also
 * silently ignores what it does not understand, which is the opposite of what
 * you want from the component that defines your data integrity.
 *
 * So: plain, readable, reviewable SQL files, applied in order, each recorded
 * with a checksum. A replacement developer can read them, run them by hand
 * against a dump, or port them elsewhere — which is exactly the independence
 * the handover terms require.
 *
 * Guarantees
 * ----------
 *  • Forward-only and idempotent: an applied file is never re-run.
 *  • Checksum-verified: if an already-applied file is edited afterwards, the
 *    drift is reported instead of quietly diverging from production.
 *  • Ordered: files run in lexical filename order, so 0001 precedes 0002.
 *  • Prefix-aware: `{prefix}` expands to the site's table prefix, so the
 *    plugin works on a multisite or a shared database.
 */
final class Migrator {

	/**
	 * Placeholder replaced with the WordPress table prefix.
	 */
	private const PREFIX_TOKEN = '{prefix}';

	/**
	 * Absolute path to the directory holding the .sql files.
	 *
	 * @var string
	 */
	private string $directory;

	/**
	 * Constructor.
	 *
	 * @param string|null $directory Optional override, used by the test suite.
	 */
	public function __construct( ?string $directory = null ) {
		$this->directory = $directory ?? RC_PLUGIN_DIR . 'migrations';
	}

	/**
	 * Name of the bookkeeping table, with prefix applied.
	 */
	private function ledger_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'rc_migrations';
	}

	/**
	 * Create the bookkeeping table if this is a first run.
	 *
	 * Written inline rather than in a migration file, because it is the table
	 * that records which migration files have run.
	 */
	private function ensure_ledger(): void {
		global $wpdb;

		$table   = $this->ledger_table();
		$charset = $wpdb->get_charset_collate();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is derived from $wpdb->prefix, not user input.
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS `{$table}` (
				`id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				`filename`    VARCHAR(191)    NOT NULL,
				`checksum`    CHAR(64)        NOT NULL,
				`applied_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
				`duration_ms` INT UNSIGNED    NULL,
				PRIMARY KEY (`id`),
				UNIQUE KEY `uq_migration_filename` (`filename`)
			) {$charset}"
		);
	}

	/**
	 * All migration files on disk, in execution order.
	 *
	 * @return string[] Absolute paths.
	 */
	public function available(): array {
		if ( ! is_dir( $this->directory ) ) {
			return array();
		}

		$files = glob( $this->directory . '/*.sql' );

		if ( false === $files ) {
			return array();
		}

		sort( $files, SORT_STRING );

		return $files;
	}

	/**
	 * Filenames already applied, mapped to their recorded checksum.
	 *
	 * @return array<string,string>
	 */
	public function applied(): array {
		global $wpdb;

		$this->ensure_ledger();
		$table = $this->ledger_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( "SELECT `filename`, `checksum` FROM `{$table}`", ARRAY_A );

		$map = array();

		foreach ( (array) $rows as $row ) {
			$map[ (string) $row['filename'] ] = (string) $row['checksum'];
		}

		return $map;
	}

	/**
	 * Migration files present on disk but not yet applied.
	 *
	 * @return string[] Absolute paths.
	 */
	public function pending(): array {
		$applied = $this->applied();

		return array_values(
			array_filter(
				$this->available(),
				static fn ( string $path ): bool => ! isset( $applied[ basename( $path ) ] )
			)
		);
	}

	/**
	 * Detect migration files that changed after they were applied.
	 *
	 * Editing an applied migration is a real and common mistake: it works on a
	 * fresh database and silently diverges everywhere the old version already
	 * ran. Surfacing it turns a future production mystery into a startup
	 * warning.
	 *
	 * @return array<int,array{filename:string,expected:string,actual:string}>
	 */
	public function drift(): array {
		$applied = $this->applied();
		$drift   = array();

		foreach ( $this->available() as $path ) {
			$name = basename( $path );

			if ( ! isset( $applied[ $name ] ) ) {
				continue;
			}

			$actual = hash_file( 'sha256', $path );

			if ( $actual !== $applied[ $name ] ) {
				$drift[] = array(
					'filename' => $name,
					'expected' => $applied[ $name ],
					'actual'   => (string) $actual,
				);
			}
		}

		return $drift;
	}

	/**
	 * Apply every pending migration.
	 *
	 * @return array<int,array{filename:string,statements:int,duration_ms:int}> Applied files.
	 * @throws RuntimeException When a statement fails; the file is not recorded.
	 */
	public function migrate(): array {
		global $wpdb;

		$this->ensure_ledger();

		$results = array();

		foreach ( $this->pending() as $path ) {
			$name = basename( $path );
			$sql  = file_get_contents( $path );

			if ( false === $sql ) {
				throw new RuntimeException( sprintf( 'Unable to read migration %s', $name ) );
			}

			$sql        = str_replace( self::PREFIX_TOKEN, $wpdb->prefix, $sql );
			$statements = self::split_statements( $sql );
			$started    = microtime( true );

			// Surface real errors instead of the default silent failure.
			$previous_suppress = $wpdb->suppress_errors( true );

			foreach ( $statements as $index => $statement ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- trusted, version-controlled DDL.
				$ok = $wpdb->query( $statement );

				if ( false === $ok && '' !== $wpdb->last_error ) {
					$wpdb->suppress_errors( $previous_suppress );

					throw new RuntimeException(
						sprintf(
							'Migration %1$s failed at statement %2$d: %3$s',
							$name,
							$index + 1,
							$wpdb->last_error
						)
					);
				}
			}

			$wpdb->suppress_errors( $previous_suppress );

			$duration = (int) round( ( microtime( true ) - $started ) * 1000 );

			$wpdb->insert(
				$this->ledger_table(),
				array(
					'filename'    => $name,
					'checksum'    => (string) hash_file( 'sha256', $path ),
					'duration_ms' => $duration,
				),
				array( '%s', '%s', '%d' )
			);

			$results[] = array(
				'filename'    => $name,
				'statements'  => count( $statements ),
				'duration_ms' => $duration,
			);
		}

		return $results;
	}

	/**
	 * Split a SQL script into individual statements.
	 *
	 * Quote- and comment-aware, because a naive explode(';') corrupts any
	 * statement containing a semicolon inside a string literal — which trigger
	 * messages and seeded content routinely do.
	 *
	 * Handles: single quotes, double quotes, backtick identifiers, backslash
	 * escapes, doubled-quote escapes, `--` and `#` line comments, and
	 * `/* ... *\/` block comments.
	 *
	 * @param string $sql Raw script.
	 * @return string[] Trimmed, non-empty statements.
	 */
	public static function split_statements( string $sql ): array {
		$statements = array();
		$buffer     = '';
		$length     = strlen( $sql );

		$in_single = false;
		$in_double = false;
		$in_tick   = false;

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $sql[ $i ];
			$next = $i + 1 < $length ? $sql[ $i + 1 ] : '';

			if ( ! $in_single && ! $in_double && ! $in_tick ) {
				// Line comment: -- or #
				if ( ( '-' === $char && '-' === $next ) || '#' === $char ) {
					$newline = strpos( $sql, "\n", $i );
					$i       = false === $newline ? $length : $newline;
					continue;
				}

				// Block comment.
				if ( '/' === $char && '*' === $next ) {
					$end = strpos( $sql, '*/', $i + 2 );
					$i   = false === $end ? $length : $end + 1;
					continue;
				}

				if ( ';' === $char ) {
					$trimmed = trim( $buffer );

					if ( '' !== $trimmed ) {
						$statements[] = $trimmed;
					}

					$buffer = '';
					continue;
				}
			}

			// Backslash escape inside a quoted string.
			if ( ( $in_single || $in_double ) && '\\' === $char && '' !== $next ) {
				$buffer .= $char . $next;
				$i++;
				continue;
			}

			if ( "'" === $char && ! $in_double && ! $in_tick ) {
				$in_single = ! $in_single;
			} elseif ( '"' === $char && ! $in_single && ! $in_tick ) {
				$in_double = ! $in_double;
			} elseif ( '`' === $char && ! $in_single && ! $in_double ) {
				$in_tick = ! $in_tick;
			}

			$buffer .= $char;
		}

		$trailing = trim( $buffer );

		if ( '' !== $trailing ) {
			$statements[] = $trailing;
		}

		return $statements;
	}
}
