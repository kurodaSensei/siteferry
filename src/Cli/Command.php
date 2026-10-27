<?php
/**
 * WP-CLI commands.
 *
 * @package Siteferry
 */

namespace Siteferry\Cli;

use Siteferry\Job\JobStore;
use Siteferry\Job\Runner;
use Siteferry\Plugin;
use Siteferry\Support\Settings;
use Siteferry\Support\UndoPoint;

/**
 * Export, import and search-replace the database with Siteferry.
 */
final class Command {

	/**
	 * Exports the database to a .sql.gz, with optional replacements.
	 *
	 * ## OPTIONS
	 *
	 * [<pair>...]
	 * : Search/replace pairs: `old new [old2 new2]`.
	 *
	 * [--tables=<tables>]
	 * : Comma-separated tables (default: all tables with the prefix).
	 *
	 * [--output=<file>]
	 * : Copy the result to this path.
	 *
	 * [--media]
	 * : Include uploads: the result is a .sfy site package (database + files).
	 *
	 * [--themes]
	 * : Include themes in the .sfy package.
	 *
	 * [--plugins]
	 * : Include plugins in the .sfy package.
	 *
	 * [--mu-plugins]
	 * : Include mu-plugins in the .sfy package.
	 *
	 * [--regex]
	 * : The search strings are PCRE regular expressions with delimiters.
	 *
	 * [--case-sensitive]
	 * : Case-sensitive matching.
	 *
	 * [--guids]
	 * : Also replace in the guid column.
	 *
	 * ## EXAMPLES
	 *
	 *     wp siteferry export https://example.com http://example.test --output=site.sql.gz
	 *     wp siteferry export --media --themes --plugins --output=site.sfy
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 */
	public function export( array $args, array $assoc ): void {
		$job  = $this->run( 'export', array( 'files' => $this->roots( $assoc ) ) + $this->input( $args, $assoc ) );
		$file = $job['files']['export'];
		if ( isset( $assoc['output'] ) ) {
			copy( $file, $assoc['output'] );
			$file = $assoc['output'];
		}
		\WP_CLI::success( sprintf( 'Exported: %s (%s rows)', $file, number_format( $job['rows'] ) ) );
	}

	/**
	 * Searches and replaces in the database (safe for serialized data).
	 *
	 * ## OPTIONS
	 *
	 * <pair>...
	 * : Search/replace pairs: `old new [old2 new2]`.
	 *
	 * [--tables=<tables>]
	 * : Comma-separated tables (default: all tables with the prefix).
	 *
	 * [--backup]
	 * : Create a backup first (default). Use --no-backup to skip it.
	 *
	 * [--regex]
	 * : The search strings are PCRE regular expressions with delimiters.
	 *
	 * [--case-sensitive]
	 * : Case-sensitive matching.
	 *
	 * [--guids]
	 * : Also replace in the guid column.
	 *
	 * ## EXAMPLES
	 *
	 *     wp siteferry replace https://example.com http://example.test
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 */
	public function replace( array $args, array $assoc ): void {
		$job = $this->run( 'replace', $this->input( $args, $assoc ) );
		\WP_CLI::success( sprintf( 'Search-replace finished (%s rows).', number_format( $job['rows'] ) ) );
		$this->backup_notice( $job );
	}

	/**
	 * Imports a .sql or .sql.gz file (mysqldump, phpMyAdmin or Siteferry).
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : File to import.
	 *
	 * [<pair>...]
	 * : Additional search/replace pairs.
	 *
	 * [--backup]
	 * : Create a backup first (default). Use --no-backup to skip it.
	 *
	 * [--source-prefix=<prefix>]
	 * : Table prefix to import when the file holds several WordPress installs.
	 *
	 * [--auto-url]
	 * : Replace the source site URL with this site's URL (default). Use --no-auto-url to skip it.
	 *
	 * [--regex]
	 * : The search strings are PCRE regular expressions with delimiters.
	 *
	 * [--case-sensitive]
	 * : Case-sensitive matching.
	 *
	 * ## EXAMPLES
	 *
	 *     wp siteferry import production.sql.gz
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 */
	public function import( array $args, array $assoc ): void {
		$file = (string) array_shift( $args );
		if ( ! is_readable( $file ) ) {
			\WP_CLI::error( 'Cannot read ' . $file );
		}
		$input         = $this->input( $args, $assoc );
		$input['file'] = (string) realpath( $file );
		$job           = $this->run( 'import', $input );
		\WP_CLI::success( sprintf( 'Imported (%s rows).', number_format( $job['rows'] ) ) );
		$this->backup_notice( $job );
	}

	/**
	 * Brings another site's database here and replaces this one (pull).
	 *
	 * ## OPTIONS
	 *
	 * <url>
	 * : URL of the other site.
	 *
	 * <key>
	 * : Connection key of the other site (`wp siteferry connection` there).
	 *
	 * [<pair>...]
	 * : Additional search/replace pairs.
	 *
	 * [--tables=<tables>]
	 * : Comma-separated tables of the other site (default: all tables with its prefix).
	 *
	 * [--backup]
	 * : Back up this site first (default). Use --no-backup to skip it.
	 *
	 * [--auto-url]
	 * : Replace the other site's URL with this one's (default). Use --no-auto-url to skip it.
	 *
	 * [--media]
	 * : Also transfer media (uploads).
	 *
	 * [--themes]
	 * : Also transfer themes.
	 *
	 * [--plugins]
	 * : Also transfer plugins.
	 *
	 * [--mu-plugins]
	 * : Also transfer must-use plugins (mu-plugins).
	 *
	 * [--delete]
	 * : Delete destination files that don't exist on the source (kept in a backup for 7 days).
	 *
	 * [--files-only]
	 * : Files only, no database.
	 *
	 * [--regex]
	 * : The search strings are PCRE regular expressions with delimiters.
	 *
	 * [--case-sensitive]
	 * : Case-sensitive matching.
	 *
	 * [--guids]
	 * : Also replace in the guid column.
	 *
	 * ## EXAMPLES
	 *
	 *     wp siteferry pull https://production.example abc123...
	 *     wp siteferry pull https://production.example abc123... --media --files-only
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 */
	public function pull( array $args, array $assoc ): void {
		$this->remote( 'pull', $args, $assoc );
	}

	/**
	 * Sends this site's database to another site and replaces the one there (push).
	 *
	 * ## OPTIONS
	 *
	 * <url>
	 * : URL of the other site.
	 *
	 * <key>
	 * : Connection key of the other site (`wp siteferry connection` there).
	 *
	 * [<pair>...]
	 * : Additional search/replace pairs.
	 *
	 * [--tables=<tables>]
	 * : Comma-separated tables of this site (default: all tables with the prefix).
	 *
	 * [--backup]
	 * : Back up the other site first, stored there (default). Use --no-backup to skip it.
	 *
	 * [--auto-url]
	 * : Replace this site's URL with the other one's (default). Use --no-auto-url to skip it.
	 *
	 * [--media]
	 * : Also transfer media (uploads).
	 *
	 * [--themes]
	 * : Also transfer themes.
	 *
	 * [--plugins]
	 * : Also transfer plugins.
	 *
	 * [--mu-plugins]
	 * : Also transfer must-use plugins (mu-plugins).
	 *
	 * [--delete]
	 * : Delete destination files that don't exist on the source (kept in a backup for 7 days).
	 *
	 * [--files-only]
	 * : Files only, no database.
	 *
	 * [--regex]
	 * : The search strings are PCRE regular expressions with delimiters.
	 *
	 * [--case-sensitive]
	 * : Case-sensitive matching.
	 *
	 * [--guids]
	 * : Also replace in the guid column.
	 *
	 * ## EXAMPLES
	 *
	 *     wp siteferry push https://staging.example.com abc123...
	 *     wp siteferry push https://staging.example.com abc123... --themes --plugins --files-only
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 */
	public function push( array $args, array $assoc ): void {
		$this->remote( 'push', $args, $assoc );
	}

	/**
	 * Shows this site's connection info and sets what other sites may do.
	 *
	 * ## OPTIONS
	 *
	 * [--allow-pull=<on|off>]
	 * : Allow other sites to read this database.
	 *
	 * [--allow-pull-files=<on|off>]
	 * : Allow other sites to read media, themes and plugins (including their code).
	 *
	 * [--allow-push=<on|off>]
	 * : Allow other sites to replace this database.
	 *
	 * [--allow-push-code=<on|off>]
	 * : Allow other sites to replace themes and plugins (code) in a push.
	 *
	 * [--verify-ssl=<on|off>]
	 * : Verify the HTTPS certificate when connecting to other sites.
	 *
	 * [--regenerate-key]
	 * : Create a new key (sites using the old one can no longer connect).
	 *
	 * ## EXAMPLES
	 *
	 *     wp siteferry connection --allow-pull=on
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 */
	public function connection( array $args, array $assoc ): void {
		$values = array();
		foreach ( array( 'allow-pull', 'allow-pull-files', 'allow-push', 'allow-push-code', 'verify-ssl' ) as $flag ) {
			if ( isset( $assoc[ $flag ] ) ) {
				$values[ str_replace( '-', '_', $flag ) ] = in_array( strtolower( (string) $assoc[ $flag ] ), array( 'on', '1', 'yes', 'si', 'sí', 'true' ), true );
			}
		}
		$settings = Settings::save( $values );
		if ( isset( $assoc['regenerate-key'] ) ) {
			Settings::regenerate();
		}
		\WP_CLI::log( 'Pull: ' . ( $settings['allow_pull'] ? 'allowed' : 'no' ) . ' · File pull: ' . ( $settings['allow_pull_files'] ? 'allowed' : 'no' ) . ' · Push: ' . ( $settings['allow_push'] ? 'allowed' : 'no' ) . ' · Code push: ' . ( $settings['allow_push_code'] ? 'allowed' : 'no' ) . ' · Verify SSL: ' . ( $settings['verify_ssl'] ? 'yes' : 'no' ) );
		\WP_CLI::log( "Connection info:\n" . Settings::connection() );
	}

	/**
	 * Pull or push.
	 *
	 * @param string $intent pull | push.
	 * @param array  $args   url, key and pairs.
	 * @param array  $assoc  Options.
	 */
	private function remote( string $intent, array $args, array $assoc ): void {
		$url                   = (string) array_shift( $args );
		$key                   = (string) array_shift( $args );
		$input                 = $this->input( $args, $assoc );
		$input['connection']   = $url . ' ' . $key;
		$input['files']        = $this->roots( $assoc );
		$input['delete_files'] = (bool) \WP_CLI\Utils\get_flag_value( $assoc, 'delete', false );
		$input['database']     = ! \WP_CLI\Utils\get_flag_value( $assoc, 'files-only', false );
		$job                   = $this->run( $intent, $input );
		\WP_CLI::success(
			sprintf(
				'%s finished (%s rows, %s files).',
				'pull' === $intent ? 'Pull' : 'Push',
				number_format( $job['rows'] ),
				number_format( array_sum( array_column( $job['file_stats'], 'sent' ) ) )
			)
		);
		$this->backup_notice( $job );
	}

	/**
	 * Undoes the last operation that changed this site (search-replace, import, pull or received push).
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 */
	public function undo( array $args, array $assoc ): void {
		$point = UndoPoint::get();
		if ( ! $point ) {
			\WP_CLI::error( 'There is nothing to undo.' );
		}
		\WP_CLI::confirm( sprintf( 'Undo "%s" from %s? Anything changed since then is lost.', $point['label'], wp_date( 'Y-m-d H:i', (int) $point['time'] ) ), $assoc );
		$this->run( 'undo', array() );
		\WP_CLI::success( 'Undone.' );
	}

	/**
	 * Folders requested with --media, --themes, --plugins and --mu-plugins.
	 *
	 * @param array $assoc Options.
	 * @return string[]
	 */
	private function roots( array $assoc ): array {
		$flags = array(
			'media'      => 'uploads',
			'themes'     => 'themes',
			'plugins'    => 'plugins',
			'mu-plugins' => 'mu-plugins',
		);
		return array_values( array_filter( $flags, static fn( $flag ) => \WP_CLI\Utils\get_flag_value( $assoc, $flag, false ), ARRAY_FILTER_USE_KEY ) );
	}

	/**
	 * Common options.
	 *
	 * @param array $args  Pairs.
	 * @param array $assoc Options.
	 */
	private function input( array $args, array $assoc ): array {
		if ( count( $args ) % 2 ) {
			\WP_CLI::error( 'Search/replace pairs must come in twos.' );
		}
		return array(
			'pairs'          => array_chunk( $args, 2 ),
			'tables'         => isset( $assoc['tables'] ) ? array_map( 'trim', explode( ',', $assoc['tables'] ) ) : array(),
			'regex'          => \WP_CLI\Utils\get_flag_value( $assoc, 'regex', false ),
			'case_sensitive' => \WP_CLI\Utils\get_flag_value( $assoc, 'case-sensitive', false ),
			'replace_guids'  => \WP_CLI\Utils\get_flag_value( $assoc, 'guids', false ),
			'backup'         => \WP_CLI\Utils\get_flag_value( $assoc, 'backup', true ),
			'auto_url'       => \WP_CLI\Utils\get_flag_value( $assoc, 'auto-url', true ),
			'source_prefix'  => (string) ( $assoc['source-prefix'] ?? '' ),
		);
	}

	/**
	 * Creates the job and runs it to the end with a progress bar.
	 *
	 * @param string $intent Action.
	 * @param array  $input  Options.
	 */
	private function run( string $intent, array $input ): array {
		try {
			$job = Plugin::create_job( $intent, $input );
		} catch ( \Exception $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
		$bar  = \WP_CLI\Utils\make_progress_bar( 'Siteferry', 100 );
		$seen = 0;
		while ( Runner::is_open( $job ) ) {
			$job     = Plugin::run_step( $job, 10 );
			$percent = Runner::percent( $job );
			$bar->tick( $percent - $seen );
			$seen = $percent;
		}
		$bar->finish();
		foreach ( $job['warnings'] as $warning ) {
			\WP_CLI::warning( $warning );
		}
		if ( 'done' !== $job['status'] ) {
			// No UI with a "Cancel" button here: clean up now (temporary tables and, for a push, the remote site).
			JobStore::save( Plugin::runner()->cancel( $job ) );
			\WP_CLI::error( (string) $job['error'] );
		}
		return $job;
	}

	/**
	 * Where the backup was stored.
	 *
	 * @param array $job Job.
	 */
	private function backup_notice( array $job ): void {
		if ( isset( $job['files']['backup'] ) ) {
			\WP_CLI::log( 'Backup taken before: ' . $job['files']['backup'] );
		}
	}
}
