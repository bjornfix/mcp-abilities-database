<?php
/**
 * Plugin Name: MCP Abilities - Database
 * Plugin URI: https://devenia.com
 * Description: Controlled database maintenance abilities for MCP, including post-content maintenance and WordPress core table-engine audits.
 * Version: 0.1.2
 * Author: basicus
 * Author URI: https://profiles.wordpress.org/basicus/
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Requires at least: 6.9
 * Requires PHP: 8.0
 *
 * @package MCP_Abilities_Database
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check if Abilities API is available.
 */
function mcp_database_check_dependencies(): bool {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		add_action(
			'admin_notices',
			static function (): void {
				echo '<div class="notice notice-error"><p><strong>MCP Abilities - Database</strong> requires the Abilities API plugin to be installed and activated.</p></div>';
			}
		);
		return false;
	}

	return true;
}

/**
 * Normalize a string-list input to safe unique values.
 *
 * @param mixed    $value Input value.
 * @param string[] $default Default values.
 * @param string[] $allowed Optional allow-list. Empty means accept sanitized keys.
 * @return string[]
 */
function mcp_database_normalize_string_list( mixed $value, array $default, array $allowed = array() ): array {
	if ( ! is_array( $value ) ) {
		$value = $default;
	}

	$normalized = array();
	foreach ( $value as $item ) {
		$item = sanitize_key( (string) $item );
		if ( '' === $item ) {
			continue;
		}
		if ( ! empty( $allowed ) && ! in_array( $item, $allowed, true ) ) {
			continue;
		}
		$normalized[] = $item;
	}

	$normalized = array_values( array_unique( $normalized ) );
	return ! empty( $normalized ) ? $normalized : $default;
}

/**
 * Get the fixed logical allow-list for WordPress core-owned database tables.
 *
 * Clients select these logical keys, never physical table names. The physical
 * names are resolved from wpdb so custom prefixes and multisite base tables are
 * supported without accepting arbitrary SQL identifiers.
 *
 * @return array<string, string> Logical table key to wpdb property.
 */
function mcp_database_core_table_allowlist(): array {
	return array(
		'posts'              => 'posts',
		'postmeta'           => 'postmeta',
		'comments'           => 'comments',
		'commentmeta'        => 'commentmeta',
		'terms'              => 'terms',
		'termmeta'           => 'termmeta',
		'term_taxonomy'      => 'term_taxonomy',
		'term_relationships' => 'term_relationships',
		'options'            => 'options',
		'links'              => 'links',
		'users'              => 'users',
		'usermeta'           => 'usermeta',
		'blogs'              => 'blogs',
		'blogmeta'           => 'blogmeta',
		'signups'            => 'signups',
		'site'               => 'site',
		'sitemeta'           => 'sitemeta',
		'registration_log'   => 'registration_log',
	);
}

/**
 * Get WordPress core table keys that are network-global on multisite.
 *
 * @return string[]
 */
function mcp_database_network_global_table_keys(): array {
	return array( 'users', 'usermeta', 'blogs', 'blogmeta', 'signups', 'site', 'sitemeta', 'registration_log' );
}

/**
 * Validate requested core table keys without silently dropping invalid input.
 *
 * @param mixed $value Requested table keys.
 * @param bool  $default_all Whether an omitted list means every allowlisted key.
 * @return array{valid: bool, tables: string[], invalid_tables: string[]}
 */
function mcp_database_validate_core_table_keys( mixed $value, bool $default_all ): array {
	$allowed = array_keys( mcp_database_core_table_allowlist() );

	if ( null === $value ) {
		return array(
			'valid'          => $default_all,
			'tables'         => $default_all ? $allowed : array(),
			'invalid_tables' => array(),
		);
	}

	if ( ! is_array( $value ) || empty( $value ) ) {
		return array(
			'valid'          => false,
			'tables'         => array(),
			'invalid_tables' => array(),
		);
	}

	$tables  = array();
	$invalid = array();
	foreach ( $value as $table_key ) {
		if ( ! is_string( $table_key ) || ! in_array( $table_key, $allowed, true ) ) {
			$invalid[] = is_scalar( $table_key ) ? (string) $table_key : '(non-scalar)';
			continue;
		}
		$tables[] = $table_key;
	}

	$tables  = array_values( array_unique( $tables ) );
	$invalid = array_values( array_unique( $invalid ) );

	return array(
		'valid'          => empty( $invalid ) && ! empty( $tables ),
		'tables'         => $tables,
		'invalid_tables' => $invalid,
	);
}

/**
 * Authorize a core-table request according to its exact table scope.
 *
 * Site-local core tables require manage_options. On multisite, any request that
 * includes a network-global core table additionally requires both super-admin
 * status and the network-options capability.
 *
 * @param array<string, mixed> $input Ability input.
 * @param bool                 $default_all Whether an omitted table list selects all tables.
 */
function mcp_database_can_manage_core_table_request( array $input, bool $default_all ): bool {
	if ( ! current_user_can( 'manage_options' ) ) {
		return false;
	}

	$selection = mcp_database_validate_core_table_keys( $input['tables'] ?? null, $default_all );
	if ( ! $selection['valid'] ) {
		return false;
	}

	if ( ! is_multisite() ) {
		return true;
	}

	$network_tables = array_intersect( $selection['tables'], mcp_database_network_global_table_keys() );
	if ( empty( $network_tables ) ) {
		return true;
	}

	return is_super_admin() && current_user_can( 'manage_network_options' );
}

/**
 * Resolve an allowlisted logical core table key to its exact wpdb table name.
 *
 * @param string $table_key Logical table key.
 * @return string|null
 */
function mcp_database_resolve_core_table_name( string $table_key ): ?string {
	global $wpdb;

	$allowlist = mcp_database_core_table_allowlist();
	if ( ! isset( $allowlist[ $table_key ] ) ) {
		return null;
	}

	$property = $allowlist[ $table_key ];
	$table    = isset( $wpdb->{$property} ) ? $wpdb->{$property} : null;

	return is_string( $table ) && '' !== $table ? $table : null;
}

/**
 * Read exact storage metadata for one allowlisted WordPress core table.
 *
 * @param string $table_key Logical table key.
 * @return array<string, mixed>
 */
function mcp_database_audit_core_table( string $table_key ): array {
	global $wpdb;

	$table = mcp_database_resolve_core_table_name( $table_key );
	if ( null === $table ) {
		return array(
			'table_key'     => $table_key,
			'table_name'    => null,
			'exists'        => false,
			'engine'        => null,
			'is_innodb'     => false,
			'table_type'    => null,
			'row_format'    => null,
			'collation'     => null,
			'rows_estimate' => null,
			'data_bytes'    => null,
			'index_bytes'   => null,
			'error_code'    => 'core_table_unavailable',
			'error'         => 'This WordPress core table is not available in the current site configuration.',
		);
	}

	$wpdb->last_error = '';
	// A live storage-engine audit must read current server metadata and cannot use WordPress object caching.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT TABLE_NAME AS table_name, ENGINE AS engine, TABLE_TYPE AS table_type, ROW_FORMAT AS row_format, TABLE_COLLATION AS table_collation, TABLE_ROWS AS rows_estimate, DATA_LENGTH AS data_bytes, INDEX_LENGTH AS index_bytes FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
			$table
		),
		ARRAY_A
	);

	if ( '' !== $wpdb->last_error ) {
		return array(
			'table_key'     => $table_key,
			'table_name'    => $table,
			'exists'        => false,
			'engine'        => null,
			'is_innodb'     => false,
			'table_type'    => null,
			'row_format'    => null,
			'collation'     => null,
			'rows_estimate' => null,
			'data_bytes'    => null,
			'index_bytes'   => null,
			'error_code'    => 'metadata_query_failed',
			'error'         => 'Database metadata query failed.',
		);
	}

	if ( ! is_array( $row ) ) {
		return array(
			'table_key'     => $table_key,
			'table_name'    => $table,
			'exists'        => false,
			'engine'        => null,
			'is_innodb'     => false,
			'table_type'    => null,
			'row_format'    => null,
			'collation'     => null,
			'rows_estimate' => null,
			'data_bytes'    => null,
			'index_bytes'   => null,
			'error_code'    => 'core_table_missing',
			'error'         => 'The resolved WordPress core table does not exist in the current database.',
		);
	}

	$engine = isset( $row['engine'] ) && is_string( $row['engine'] ) ? $row['engine'] : null;

	return array(
		'table_key'     => $table_key,
		'table_name'    => $table,
		'exists'        => true,
		'engine'        => $engine,
		'is_innodb'     => is_string( $engine ) && 0 === strcasecmp( $engine, 'InnoDB' ),
		'table_type'    => isset( $row['table_type'] ) ? (string) $row['table_type'] : null,
		'row_format'    => isset( $row['row_format'] ) ? (string) $row['row_format'] : null,
		'collation'     => isset( $row['table_collation'] ) ? (string) $row['table_collation'] : null,
		'rows_estimate' => isset( $row['rows_estimate'] ) ? (int) $row['rows_estimate'] : null,
		'data_bytes'    => isset( $row['data_bytes'] ) ? (int) $row['data_bytes'] : null,
		'index_bytes'   => isset( $row['index_bytes'] ) ? (int) $row['index_bytes'] : null,
		'error_code'    => null,
		'error'         => null,
	);
}

/**
 * Audit storage engines for requested WordPress core tables.
 *
 * @param array<string, mixed> $input Ability input.
 * @return array<string, mixed>
 */
function mcp_database_audit_core_table_engines( array $input ): array {
	$selection = mcp_database_validate_core_table_keys( $input['tables'] ?? null, true );
	if ( ! $selection['valid'] ) {
		return array(
			'success'          => false,
			'tables'           => array(),
			'invalid_tables'   => $selection['invalid_tables'],
			'audit_count'      => 0,
			'innodb_count'     => 0,
			'non_innodb_count' => 0,
			'missing_count'    => 0,
			'results'          => array(),
			'message'          => 'Tables must be a non-empty list of allowlisted WordPress core table keys.',
		);
	}

	$results          = array();
	$innodb_count     = 0;
	$non_innodb_count = 0;
	$missing_count    = 0;
	$query_failed     = false;

	foreach ( $selection['tables'] as $table_key ) {
		$result    = mcp_database_audit_core_table( $table_key );
		$results[] = $result;
		if ( ! empty( $result['is_innodb'] ) ) {
			++$innodb_count;
		} elseif ( ! empty( $result['exists'] ) ) {
			++$non_innodb_count;
		} else {
			++$missing_count;
			if ( 'metadata_query_failed' === ( $result['error_code'] ?? null ) ) {
				$query_failed = true;
			}
		}
	}

	return array(
		'success'          => ! $query_failed,
		'tables'           => $selection['tables'],
		'invalid_tables'   => array(),
		'audit_count'      => count( $results ),
		'innodb_count'     => $innodb_count,
		'non_innodb_count' => $non_innodb_count,
		'missing_count'    => $missing_count,
		'results'          => $results,
		'message'          => sprintf( 'Audited %d allowlisted WordPress core tables: %d InnoDB, %d other engines, %d unavailable or missing.', count( $results ), $innodb_count, $non_innodb_count, $missing_count ),
	);
}

/**
 * Summarize table conversion records without inferring unknown DDL effects.
 *
 * @param bool                 $dry_run Dry-run state.
 * @param bool                 $confirm Confirmation state.
 * @param string[]             $tables Requested table keys.
 * @param string[]             $invalid_tables Invalid requested keys.
 * @param array<int, array<string, mixed>> $results Per-table results.
 * @param string               $message Human-readable summary.
 * @return array<string, mixed>
 */
function mcp_database_summarize_core_table_conversion( bool $dry_run, bool $confirm, array $tables, array $invalid_tables, array $results, string $message ): array {
	$counts = array(
		'planned'               => 0,
		'failed'                => 0,
		'postcondition_met'     => 0,
		'postcondition_failed'  => 0,
		'postcondition_unknown' => 0,
		'statement_succeeded'   => 0,
		'statement_failed'      => 0,
		'mutation_changed'      => 0,
		'mutation_unchanged'    => 0,
		'mutation_unknown'      => 0,
	);

	$attempted_changed   = 0;
	$attempted_unchanged = 0;
	foreach ( $results as $result ) {
		$counts['planned'] += ! empty( $result['planned'] ) ? 1 : 0;
		$counts['failed']  += empty( $result['success'] ) ? 1 : 0;

		$postcondition = (string) ( $result['postcondition'] ?? 'not_checked' );
		if ( 'met' === $postcondition ) {
			++$counts['postcondition_met'];
		} elseif ( 'not_met' === $postcondition ) {
			++$counts['postcondition_failed'];
		} elseif ( 'unknown' === $postcondition ) {
			++$counts['postcondition_unknown'];
		}

		$statement = (string) ( $result['statement_outcome'] ?? 'not_attempted' );
		if ( 'reported_success' === $statement ) {
			++$counts['statement_succeeded'];
		} elseif ( 'reported_failure' === $statement ) {
			++$counts['statement_failed'];
		}

		$mutation = (string) ( $result['mutation_outcome'] ?? 'not_attempted' );
		if ( 'changed' === $mutation ) {
			++$counts['mutation_changed'];
			$attempted_changed += ! empty( $result['attempted'] ) ? 1 : 0;
		} elseif ( 'unchanged' === $mutation ) {
			++$counts['mutation_unchanged'];
			$attempted_unchanged += ! empty( $result['attempted'] ) ? 1 : 0;
		} elseif ( 'unknown' === $mutation ) {
			++$counts['mutation_unknown'];
		}
	}

	if ( $counts['mutation_unknown'] > 0 ) {
		$mutation_outcome = ( $attempted_changed + $attempted_unchanged ) > 0 ? 'partial_or_unknown' : 'unknown';
	} elseif ( $attempted_changed > 0 && $attempted_unchanged > 0 ) {
		$mutation_outcome = 'partial';
	} elseif ( $attempted_changed > 0 ) {
		$mutation_outcome = 'changed';
	} else {
		$mutation_outcome = 'none';
	}

	$mutation_occurred = $counts['mutation_changed'] > 0 ? true : ( $counts['mutation_unknown'] > 0 ? null : false );
	if ( $attempted_changed > 0 && $attempted_unchanged > 0 ) {
		$partial_mutation = true;
	} elseif ( $counts['mutation_unknown'] > 0 ) {
		$partial_mutation = null;
	} else {
		$partial_mutation = false;
	}

	return array(
		'success'                    => ! empty( $results ) && 0 === $counts['failed'],
		'dry_run'                    => $dry_run,
		'confirmed'                  => $confirm,
		'tables'                     => $tables,
		'invalid_tables'             => $invalid_tables,
		'planned_count'              => $counts['planned'],
		'changed_count'              => $counts['mutation_changed'],
		'unchanged_count'            => $counts['mutation_unchanged'],
		'unknown_mutation_count'     => $counts['mutation_unknown'],
		'failed_count'               => $counts['failed'],
		'postcondition_met_count'    => $counts['postcondition_met'],
		'postcondition_failed_count' => $counts['postcondition_failed'],
		'postcondition_unknown_count' => $counts['postcondition_unknown'],
		'statement_succeeded_count'  => $counts['statement_succeeded'],
		'statement_failed_count'     => $counts['statement_failed'],
		'mutation_outcome'           => $mutation_outcome,
		'mutation_occurred'          => $mutation_occurred,
		'partial_mutation'           => $partial_mutation,
		'results'                    => $results,
		'message'                    => $message,
	);
}

/**
 * Plan or execute explicit InnoDB conversions for WordPress core tables.
 *
 * @param array<string, mixed> $input Ability input.
 * @return array<string, mixed>
 */
function mcp_database_convert_core_tables_to_innodb( array $input ): array {
	global $wpdb;

	$selection = mcp_database_validate_core_table_keys( $input['tables'] ?? null, false );
	$dry_run   = (bool) ( $input['dry_run'] ?? true );
	$confirm   = (bool) ( $input['confirm'] ?? false );

	if ( ! $selection['valid'] ) {
		return mcp_database_summarize_core_table_conversion( $dry_run, $confirm, array(), $selection['invalid_tables'], array(), 'An explicit non-empty list of allowlisted WordPress core table keys is required.' );
	}

	if ( ! $dry_run && ! $confirm ) {
		return mcp_database_summarize_core_table_conversion( false, false, $selection['tables'], array(), array(), 'Live table-engine conversion requires dry_run=false and confirm=true. Review a dry run first.' );
	}

	$preflight        = array();
	$preflight_failed = false;
	foreach ( $selection['tables'] as $table_key ) {
		$before                  = mcp_database_audit_core_table( $table_key );
		$preflight[ $table_key ] = $before;
		if ( empty( $before['exists'] ) || 'BASE TABLE' !== ( $before['table_type'] ?? null ) || null === ( $before['engine'] ?? null ) ) {
			$preflight_failed = true;
		}
	}

	if ( $preflight_failed ) {
		$results = array();
		foreach ( $preflight as $table_key => $before ) {
			$record_failed = empty( $before['exists'] ) || 'BASE TABLE' !== ( $before['table_type'] ?? null ) || null === ( $before['engine'] ?? null );
			$results[]     = array(
				'table_key'        => $table_key,
				'success'          => false,
				'planned'          => false,
				'attempted'        => false,
				'changed'          => false,
				'statement_outcome' => 'not_attempted',
				'postcondition'    => 'not_checked',
				'mutation_outcome' => 'not_attempted',
				'before'           => $before,
				'after'            => null,
				'error_code'       => $record_failed ? ( empty( $before['exists'] ) ? ( $before['error_code'] ?? 'preflight_failed' ) : 'unsupported_table_type' ) : 'batch_preflight_aborted',
				'error'            => $record_failed ? ( empty( $before['exists'] ) ? ( $before['error'] ?? 'Table metadata is unavailable.' ) : 'Only physical base tables with a reported storage engine can be converted.' ) : 'Another requested table failed preflight, so no changes were attempted.',
			);
		}

		return mcp_database_summarize_core_table_conversion( $dry_run, $confirm, $selection['tables'], array(), $results, 'Preflight failed. No table-engine changes were attempted.' );
	}

	$results = array();
	foreach ( $selection['tables'] as $table_key ) {
		$before = $preflight[ $table_key ];
		if ( ! empty( $before['is_innodb'] ) ) {
			$results[] = array(
				'table_key'         => $table_key,
				'success'           => true,
				'planned'           => false,
				'attempted'         => false,
				'changed'           => false,
				'statement_outcome' => 'not_attempted',
				'postcondition'     => 'met',
				'mutation_outcome'  => 'unchanged',
				'before'            => $before,
				'after'             => $before,
				'error_code'        => null,
				'error'             => null,
			);
			continue;
		}

		if ( $dry_run ) {
			$results[] = array(
				'table_key'         => $table_key,
				'success'           => true,
				'planned'           => true,
				'attempted'         => false,
				'changed'           => false,
				'statement_outcome' => 'not_attempted',
				'postcondition'     => 'not_checked',
				'mutation_outcome'  => 'not_attempted',
				'before'            => $before,
				'after'             => null,
				'error_code'        => null,
				'error'             => null,
			);
			continue;
		}

		$table_name       = (string) $before['table_name'];
		$wpdb->last_error = '';
		// This narrowly scoped DDL is the purpose of the ability; the identifier is resolved from wpdb and prepared with %i.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Intentional confirm-gated schema change for an allowlisted WordPress core table.
		$query_result      = $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ENGINE = InnoDB', $table_name ) );
		$statement_outcome = false === $query_result ? 'reported_failure' : 'reported_success';
		$after             = mcp_database_audit_core_table( $table_key );

		if ( ! empty( $after['is_innodb'] ) ) {
			$postcondition = 'met';
		} elseif ( ! empty( $after['exists'] ) && null !== ( $after['engine'] ?? null ) ) {
			$postcondition = 'not_met';
		} else {
			$postcondition = 'unknown';
		}

		if ( 'unknown' === $postcondition ) {
			$mutation_outcome = 'unknown';
		} elseif ( 'reported_failure' === $statement_outcome ) {
			$mutation_outcome = ( $after['engine'] ?? null ) === ( $before['engine'] ?? null ) ? 'unchanged' : 'unknown';
		} else {
			$mutation_outcome = ( $after['engine'] ?? null ) === ( $before['engine'] ?? null ) ? 'unchanged' : 'changed';
		}

		$success = 'met' === $postcondition;
		if ( $success && 'reported_failure' === $statement_outcome ) {
			$error_code = 'statement_reported_failure_postcondition_met';
			$error      = 'ALTER TABLE reported a database failure, but the subsequent audit found InnoDB. Mutation attribution remains unknown.';
		} elseif ( 'unknown' === $postcondition ) {
			$error_code = 'reported_failure' === $statement_outcome ? 'statement_reported_failure_postcondition_unknown' : 'postcondition_unknown';
			$error      = 'The table engine could not be verified after ALTER TABLE.';
		} elseif ( ! $success ) {
			$error_code = 'reported_failure' === $statement_outcome ? 'alter_table_reported_failure' : 'postcondition_not_met';
			$error      = 'The table did not report InnoDB after ALTER TABLE.';
		} else {
			$error_code = null;
			$error      = null;
		}

		$results[] = array(
			'table_key'         => $table_key,
			'success'           => $success,
			'planned'           => true,
			'attempted'         => true,
			'changed'           => 'changed' === $mutation_outcome,
			'statement_outcome' => $statement_outcome,
			'postcondition'     => $postcondition,
			'mutation_outcome'  => $mutation_outcome,
			'before'            => $before,
			'after'             => $after,
			'error_code'        => $error_code,
			'error'             => $error,
		);
	}

	$summary = $dry_run
		? 'Dry run completed without attempting table-engine changes.'
		: 'Table-engine conversion completed; inspect statement, postcondition, and mutation outcomes for each requested table.';

	return mcp_database_summarize_core_table_conversion( $dry_run, $confirm, $selection['tables'], array(), $results, $summary );
}

/**
 * Get candidate posts whose post_content contains the search string.
 *
 * @param string   $search Search string.
 * @param string[] $post_types Post types.
 * @param string[] $post_statuses Post statuses.
 * @param int      $limit Max rows, 0 means no explicit limit.
 * @return array<int, array<string, mixed>>
 */
function mcp_database_get_post_content_candidates( string $search, array $post_types, array $post_statuses, int $limit ): array {
	$ids = get_posts(
		array(
			'post_type'              => $post_types,
			'post_status'            => $post_statuses,
			'posts_per_page'         => -1,
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		)
	);

	if ( ! is_array( $ids ) ) {
		return array();
	}

	$rows = array();
	foreach ( $ids as $post_id ) {
		$post_id = (int) $post_id;
		$content = get_post_field( 'post_content', $post_id, 'raw' );
		if ( ! is_string( $content ) || false === strpos( $content, $search ) ) {
			continue;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			continue;
		}

		$rows[] = array(
			'ID'           => $post_id,
			'post_title'   => $post->post_title,
			'post_type'    => $post->post_type,
			'post_status'  => $post->post_status,
			'post_content' => $content,
		);

		if ( $limit > 0 && count( $rows ) >= $limit ) {
			break;
		}
	}

	return $rows;
}

/**
 * List posts whose post_content contains a search string.
 *
 * @param array<string, mixed> $input Ability input.
 * @return array<string, mixed>
 */
function mcp_database_list_post_content_matches( array $input ): array {
	$search = isset( $input['search'] ) ? (string) $input['search'] : '';
	$limit  = isset( $input['limit'] ) ? max( 0, min( 10000, (int) $input['limit'] ) ) : 0;

	if ( '' === $search ) {
		return array(
			'success' => false,
			'message' => 'The search string is required.',
		);
	}

	$post_types = mcp_database_normalize_string_list(
		$input['post_types'] ?? array( 'page' ),
		array( 'page' ),
		get_post_types( array(), 'names' )
	);

	$post_statuses = mcp_database_normalize_string_list(
		$input['post_statuses'] ?? array( 'publish' ),
		array( 'publish' ),
		array( 'publish', 'draft', 'pending', 'private', 'future' )
	);

	$rows    = mcp_database_get_post_content_candidates( $search, $post_types, $post_statuses, $limit );
	$matches = array();
	$total_occurrences = 0;

	foreach ( $rows as $row ) {
		$post_id = isset( $row['ID'] ) ? (int) $row['ID'] : 0;
		$content = isset( $row['post_content'] ) ? (string) $row['post_content'] : '';
		$count   = substr_count( $content, $search );

		if ( $post_id < 1 || $count < 1 ) {
			continue;
		}

		$total_occurrences += $count;
		$matches[] = array(
			'id'               => $post_id,
			'title'            => html_entity_decode( wp_strip_all_tags( (string) ( $row['post_title'] ?? '' ) ), ENT_QUOTES ),
			'post_type'        => (string) ( $row['post_type'] ?? '' ),
			'post_status'      => (string) ( $row['post_status'] ?? '' ),
			'link'             => get_permalink( $post_id ),
			'occurrence_count' => $count,
		);
	}

	return array(
		'success'            => true,
		'search'             => $search,
		'post_types'         => $post_types,
		'post_statuses'      => $post_statuses,
		'limit'              => $limit,
		'match_count'        => count( $matches ),
		'occurrences_found'  => $total_occurrences,
		'matches'            => $matches,
		'message'            => sprintf( 'Found %d posts containing the search string.', count( $matches ) ),
	);
}

/**
 * Search/replace inside wp_posts.post_content.
 *
 * @param array<string, mixed> $input Ability input.
 * @return array<string, mixed>
 */
function mcp_database_search_replace_post_content( array $input ): array {
	$search  = isset( $input['search'] ) ? (string) $input['search'] : '';
	$replace = isset( $input['replace'] ) ? (string) $input['replace'] : '';
	$dry_run = (bool) ( $input['dry_run'] ?? true );
	$confirm = (bool) ( $input['confirm'] ?? false );
	$limit   = isset( $input['limit'] ) ? max( 0, min( 10000, (int) $input['limit'] ) ) : 0;

	if ( '' === $search ) {
		return array(
			'success' => false,
			'message' => 'The search string is required.',
		);
	}

	if ( $search === $replace ) {
		return array(
			'success' => false,
			'message' => 'Search and replace strings are identical.',
		);
	}

	if ( ! $dry_run && ! $confirm ) {
		return array(
			'success' => false,
			'message' => 'Live replacement requires confirm=true. Run dry_run=true first.',
		);
	}

	$post_types = mcp_database_normalize_string_list(
		$input['post_types'] ?? array( 'page' ),
		array( 'page' ),
		get_post_types( array(), 'names' )
	);

	$post_statuses = mcp_database_normalize_string_list(
		$input['post_statuses'] ?? array( 'publish' ),
		array( 'publish' ),
		array( 'publish', 'draft', 'pending', 'private', 'future' )
	);

	$rows               = mcp_database_get_post_content_candidates( $search, $post_types, $post_statuses, $limit );
	$candidate_posts    = count( $rows );
	$replacements_found = 0;
	$updated_posts      = 0;
	$replacements_made  = 0;
	$samples            = array();
	$errors             = array();

	foreach ( $rows as $row ) {
		$post_id          = isset( $row['ID'] ) ? (int) $row['ID'] : 0;
		$content          = isset( $row['post_content'] ) ? (string) $row['post_content'] : '';
		$occurrence_count = substr_count( $content, $search );

		if ( $occurrence_count < 1 ) {
			continue;
		}

		$replacements_found += $occurrence_count;
		if ( count( $samples ) < 25 ) {
			$samples[] = array(
				'id'               => $post_id,
				'title'            => html_entity_decode( wp_strip_all_tags( (string) ( $row['post_title'] ?? '' ) ), ENT_QUOTES ),
				'post_type'        => (string) ( $row['post_type'] ?? '' ),
				'post_status'      => (string) ( $row['post_status'] ?? '' ),
				'occurrence_count' => $occurrence_count,
			);
		}

		if ( $dry_run ) {
			continue;
		}

		$updated_content = str_replace( $search, $replace, $content, $replace_count );
		if ( $replace_count < 1 || $updated_content === $content ) {
			continue;
		}

		$updated = wp_update_post(
			array(
				'ID'                => $post_id,
				'post_content'      => $updated_content,
				'post_modified'     => current_time( 'mysql' ),
				'post_modified_gmt' => current_time( 'mysql', true ),
			),
			true
		);

		if ( is_wp_error( $updated ) ) {
			$errors[] = array(
				'id'    => $post_id,
				'error' => $updated->get_error_message(),
			);
			continue;
		}

		++$updated_posts;
		$replacements_made += $replace_count;
		clean_post_cache( $post_id );
	}

	return array(
		'success'             => empty( $errors ),
		'dry_run'             => $dry_run,
		'search'              => $search,
		'replace'             => $replace,
		'post_types'          => $post_types,
		'post_statuses'       => $post_statuses,
		'limit'               => $limit,
		'candidate_posts'     => $candidate_posts,
		'replacements_found'  => $replacements_found,
		'updated_posts'       => $updated_posts,
		'replacements_made'   => $replacements_made,
		'samples'             => $samples,
		'errors'              => $errors,
		'message'             => $dry_run
			? sprintf( 'Dry run found %d replacements in %d posts.', $replacements_found, $candidate_posts )
			: sprintf( 'Updated %d posts with %d replacements.', $updated_posts, $replacements_made ),
	);
}

/**
 * Regex replace inside wp_posts.post_content.
 *
 * @param array<string, mixed> $input Ability input.
 * @return array<string, mixed>
 */
function mcp_database_regex_replace_post_content( array $input ): array {
	$pattern     = isset( $input['pattern'] ) ? (string) $input['pattern'] : '';
	$replacement = isset( $input['replacement'] ) ? (string) $input['replacement'] : '';
	$dry_run     = (bool) ( $input['dry_run'] ?? true );
	$confirm     = (bool) ( $input['confirm'] ?? false );
	$limit       = isset( $input['limit'] ) ? max( 0, min( 10000, (int) $input['limit'] ) ) : 0;

	if ( '' === $pattern ) {
		return array(
			'success' => false,
			'message' => 'The regex pattern is required.',
		);
	}

	if ( false === @preg_match( $pattern, '' ) ) {
		return array(
			'success' => false,
			'message' => 'The regex pattern is invalid.',
		);
	}

	if ( ! $dry_run && ! $confirm ) {
		return array(
			'success' => false,
			'message' => 'Live replacement requires confirm=true. Run dry_run=true first.',
		);
	}

	$post_types = mcp_database_normalize_string_list(
		$input['post_types'] ?? array( 'page' ),
		array( 'page' ),
		get_post_types( array(), 'names' )
	);

	$post_statuses = mcp_database_normalize_string_list(
		$input['post_statuses'] ?? array( 'publish' ),
		array( 'publish' ),
		array( 'publish', 'draft', 'pending', 'private', 'future' )
	);

	$ids = get_posts(
		array(
			'post_type'              => $post_types,
			'post_status'            => $post_statuses,
			'posts_per_page'         => -1,
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		)
	);

	$candidate_posts    = 0;
	$replacements_found = 0;
	$updated_posts      = 0;
	$replacements_made  = 0;
	$samples            = array();
	$errors             = array();

	if ( ! is_array( $ids ) ) {
		$ids = array();
	}

	foreach ( $ids as $post_id ) {
		$post_id = (int) $post_id;
		$content = get_post_field( 'post_content', $post_id, 'raw' );
		if ( ! is_string( $content ) ) {
			continue;
		}

		$match_count = preg_match_all( $pattern, $content );
		if ( false === $match_count || $match_count < 1 ) {
			continue;
		}

		++$candidate_posts;
		$replacements_found += $match_count;
		$post = get_post( $post_id );

		if ( count( $samples ) < 25 ) {
			$samples[] = array(
				'id'               => $post_id,
				'title'            => $post instanceof WP_Post ? html_entity_decode( wp_strip_all_tags( $post->post_title ), ENT_QUOTES ) : '',
				'post_type'        => $post instanceof WP_Post ? $post->post_type : '',
				'post_status'      => $post instanceof WP_Post ? $post->post_status : '',
				'occurrence_count' => $match_count,
			);
		}

		if ( $dry_run ) {
			if ( $limit > 0 && $candidate_posts >= $limit ) {
				break;
			}
			continue;
		}

		$replace_count   = 0;
		$updated_content = preg_replace( $pattern, $replacement, $content, -1, $replace_count );
		if ( ! is_string( $updated_content ) || $replace_count < 1 || $updated_content === $content ) {
			continue;
		}

		$updated = wp_update_post(
			array(
				'ID'                => $post_id,
				'post_content'      => $updated_content,
				'post_modified'     => current_time( 'mysql' ),
				'post_modified_gmt' => current_time( 'mysql', true ),
			),
			true
		);

		if ( is_wp_error( $updated ) ) {
			$errors[] = array(
				'id'    => $post_id,
				'error' => $updated->get_error_message(),
			);
			continue;
		}

		++$updated_posts;
		$replacements_made += $replace_count;
		clean_post_cache( $post_id );

		if ( $limit > 0 && $candidate_posts >= $limit ) {
			break;
		}
	}

	return array(
		'success'             => empty( $errors ),
		'dry_run'             => $dry_run,
		'pattern'             => $pattern,
		'replacement'         => $replacement,
		'post_types'          => $post_types,
		'post_statuses'       => $post_statuses,
		'limit'               => $limit,
		'candidate_posts'     => $candidate_posts,
		'replacements_found'  => $replacements_found,
		'updated_posts'       => $updated_posts,
		'replacements_made'   => $replacements_made,
		'samples'             => $samples,
		'errors'              => $errors,
		'message'             => $dry_run
			? sprintf( 'Dry run found %d regex replacements in %d posts.', $replacements_found, $candidate_posts )
			: sprintf( 'Updated %d posts with %d regex replacements.', $updated_posts, $replacements_made ),
	);
}

/**
 * Get the JSON schema for one table-engine audit record.
 *
 * @return array<string, mixed>
 */
function mcp_database_table_engine_audit_record_schema(): array {
	return array(
		'type'                 => 'object',
		'required'             => array( 'table_key', 'table_name', 'exists', 'engine', 'is_innodb', 'table_type', 'row_format', 'collation', 'rows_estimate', 'data_bytes', 'index_bytes', 'error_code', 'error' ),
		'properties'           => array(
			'table_key'     => array( 'type' => 'string' ),
			'table_name'    => array( 'type' => array( 'string', 'null' ) ),
			'exists'        => array( 'type' => 'boolean' ),
			'engine'        => array( 'type' => array( 'string', 'null' ) ),
			'is_innodb'     => array( 'type' => 'boolean' ),
			'table_type'    => array( 'type' => array( 'string', 'null' ) ),
			'row_format'    => array( 'type' => array( 'string', 'null' ) ),
			'collation'     => array( 'type' => array( 'string', 'null' ) ),
			'rows_estimate' => array( 'type' => array( 'integer', 'null' ) ),
			'data_bytes'    => array( 'type' => array( 'integer', 'null' ) ),
			'index_bytes'   => array( 'type' => array( 'integer', 'null' ) ),
			'error_code'    => array( 'type' => array( 'string', 'null' ) ),
			'error'         => array( 'type' => array( 'string', 'null' ) ),
		),
		'additionalProperties' => false,
	);
}

/**
 * Get the JSON schema for one table-engine conversion result.
 *
 * @return array<string, mixed>
 */
function mcp_database_table_engine_conversion_record_schema(): array {
	$audit_schema = mcp_database_table_engine_audit_record_schema();

	return array(
		'type'                 => 'object',
		'required'             => array( 'table_key', 'success', 'planned', 'attempted', 'changed', 'statement_outcome', 'postcondition', 'mutation_outcome', 'before', 'after', 'error_code', 'error' ),
		'properties'           => array(
			'table_key'         => array( 'type' => 'string' ),
			'success'           => array( 'type' => 'boolean' ),
			'planned'           => array( 'type' => 'boolean' ),
			'attempted'         => array( 'type' => 'boolean' ),
			'changed'           => array( 'type' => 'boolean' ),
			'statement_outcome' => array( 'type' => 'string', 'enum' => array( 'not_attempted', 'reported_success', 'reported_failure' ) ),
			'postcondition'     => array( 'type' => 'string', 'enum' => array( 'not_checked', 'met', 'not_met', 'unknown' ) ),
			'mutation_outcome'  => array( 'type' => 'string', 'enum' => array( 'not_attempted', 'changed', 'unchanged', 'unknown' ) ),
			'before'            => $audit_schema,
			'after'             => array(
				'oneOf' => array(
					$audit_schema,
					array( 'type' => 'null' ),
				),
			),
			'error_code'        => array( 'type' => array( 'string', 'null' ) ),
			'error'             => array( 'type' => array( 'string', 'null' ) ),
		),
		'additionalProperties' => false,
	);
}

/**
 * Register database abilities.
 */
function mcp_register_database_abilities(): void {
	if ( ! mcp_database_check_dependencies() ) {
		return;
	}

	$core_table_keys = array_keys( mcp_database_core_table_allowlist() );

	wp_register_ability(
		'database/audit-core-table-engines',
		array(
			'label'               => 'Audit WordPress Core Table Engines',
			'description'         => 'Read-only storage-engine audit for an exact allowlist of WordPress core-owned tables. Uses logical table keys and resolves physical names from WordPress.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'default'              => array(),
				'properties'           => array(
					'tables' => array(
						'type'        => 'array',
						'items'       => array(
							'type' => 'string',
							'enum' => $core_table_keys,
						),
						'uniqueItems' => true,
						'minItems'    => 1,
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'success', 'tables', 'invalid_tables', 'audit_count', 'innodb_count', 'non_innodb_count', 'missing_count', 'results', 'message' ),
				'properties'           => array(
					'success'          => array( 'type' => 'boolean' ),
					'tables'           => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					'invalid_tables'   => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					'audit_count'      => array( 'type' => 'integer' ),
					'innodb_count'     => array( 'type' => 'integer' ),
					'non_innodb_count' => array( 'type' => 'integer' ),
					'missing_count'    => array( 'type' => 'integer' ),
					'results'          => array( 'type' => 'array', 'items' => mcp_database_table_engine_audit_record_schema() ),
					'message'          => array( 'type' => 'string' ),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => static function ( array $input = array() ): array {
				return mcp_database_audit_core_table_engines( $input );
			},
			'permission_callback' => static function ( array $input = array() ): bool {
				return mcp_database_can_manage_core_table_request( $input, true );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	wp_register_ability(
		'database/convert-core-tables-to-innodb',
		array(
			'label'               => 'Convert WordPress Core Tables to InnoDB',
			'description'         => 'Plans or runs confirm-gated InnoDB conversion for explicitly selected allowlisted WordPress core tables. Defaults to dry-run.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'tables' ),
				'properties'           => array(
					'tables'  => array(
						'type'        => 'array',
						'items'       => array(
							'type' => 'string',
							'enum' => $core_table_keys,
						),
						'uniqueItems' => true,
						'minItems'    => 1,
					),
					'dry_run' => array( 'type' => 'boolean', 'default' => true ),
					'confirm' => array( 'type' => 'boolean', 'default' => false ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'                 => 'object',
				'required'             => array( 'success', 'dry_run', 'confirmed', 'tables', 'invalid_tables', 'planned_count', 'changed_count', 'unchanged_count', 'unknown_mutation_count', 'failed_count', 'postcondition_met_count', 'postcondition_failed_count', 'postcondition_unknown_count', 'statement_succeeded_count', 'statement_failed_count', 'mutation_outcome', 'mutation_occurred', 'partial_mutation', 'results', 'message' ),
				'properties'           => array(
					'success'          => array( 'type' => 'boolean' ),
					'dry_run'          => array( 'type' => 'boolean' ),
					'confirmed'        => array( 'type' => 'boolean' ),
					'tables'           => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					'invalid_tables'   => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					'planned_count'    => array( 'type' => 'integer' ),
					'changed_count'    => array( 'type' => 'integer' ),
					'unchanged_count'  => array( 'type' => 'integer' ),
					'unknown_mutation_count' => array( 'type' => 'integer' ),
					'failed_count'     => array( 'type' => 'integer' ),
					'postcondition_met_count' => array( 'type' => 'integer' ),
					'postcondition_failed_count' => array( 'type' => 'integer' ),
					'postcondition_unknown_count' => array( 'type' => 'integer' ),
					'statement_succeeded_count' => array( 'type' => 'integer' ),
					'statement_failed_count' => array( 'type' => 'integer' ),
					'mutation_outcome' => array( 'type' => 'string', 'enum' => array( 'none', 'changed', 'partial', 'unknown', 'partial_or_unknown' ) ),
					'mutation_occurred' => array( 'type' => array( 'boolean', 'null' ) ),
					'partial_mutation' => array( 'type' => array( 'boolean', 'null' ) ),
					'results'          => array( 'type' => 'array', 'items' => mcp_database_table_engine_conversion_record_schema() ),
					'message'          => array( 'type' => 'string' ),
				),
				'additionalProperties' => false,
			),
			'execute_callback'    => static function ( array $input = array() ): array {
				return mcp_database_convert_core_tables_to_innodb( $input );
			},
			'permission_callback' => static function ( array $input = array() ): bool {
				return mcp_database_can_manage_core_table_request( $input, false );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => true,
				),
			),
		)
	);

	wp_register_ability(
		'database/search-replace-post-content',
		array(
			'label'               => 'Search/Replace Post Content',
			'description'         => 'Performs a controlled search/replace in wp_posts.post_content for selected post types and statuses. Defaults to dry-run and requires confirm=true for live writes.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'search', 'replace' ),
				'properties'           => array(
					'search'        => array( 'type' => 'string' ),
					'replace'       => array( 'type' => 'string' ),
					'post_types'    => array(
						'type'    => 'array',
						'items'   => array( 'type' => 'string' ),
						'default' => array( 'page' ),
					),
					'post_statuses' => array(
						'type'    => 'array',
						'items'   => array( 'type' => 'string' ),
						'default' => array( 'publish' ),
					),
					'dry_run'       => array( 'type' => 'boolean', 'default' => true ),
					'confirm'       => array( 'type' => 'boolean', 'default' => false ),
					'limit'         => array( 'type' => 'integer', 'default' => 0 ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'            => array( 'type' => 'boolean' ),
					'dry_run'            => array( 'type' => 'boolean' ),
					'candidate_posts'    => array( 'type' => 'integer' ),
					'replacements_found' => array( 'type' => 'integer' ),
					'updated_posts'      => array( 'type' => 'integer' ),
					'replacements_made'  => array( 'type' => 'integer' ),
					'samples'            => array( 'type' => 'array' ),
					'errors'             => array( 'type' => 'array' ),
					'message'            => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => static function ( array $input = array() ): array {
				return mcp_database_search_replace_post_content( $input );
			},
			'permission_callback' => static function (): bool {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => true,
				),
			),
		)
	);

	wp_register_ability(
		'database/list-post-content-matches',
		array(
			'label'               => 'List Post Content Matches',
			'description'         => 'Lists posts whose raw wp_posts.post_content contains a search string for selected post types and statuses.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'search' ),
				'properties'           => array(
					'search'        => array( 'type' => 'string' ),
					'post_types'    => array(
						'type'    => 'array',
						'items'   => array( 'type' => 'string' ),
						'default' => array( 'page' ),
					),
					'post_statuses' => array(
						'type'    => 'array',
						'items'   => array( 'type' => 'string' ),
						'default' => array( 'publish' ),
					),
					'limit'         => array( 'type' => 'integer', 'default' => 0 ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'           => array( 'type' => 'boolean' ),
					'search'            => array( 'type' => 'string' ),
					'post_types'        => array( 'type' => 'array' ),
					'post_statuses'     => array( 'type' => 'array' ),
					'limit'             => array( 'type' => 'integer' ),
					'match_count'       => array( 'type' => 'integer' ),
					'occurrences_found' => array( 'type' => 'integer' ),
					'matches'           => array( 'type' => 'array' ),
					'message'           => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => static function ( array $input = array() ): array {
				return mcp_database_list_post_content_matches( $input );
			},
			'permission_callback' => static function (): bool {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	wp_register_ability(
		'database/regex-replace-post-content',
		array(
			'label'               => 'Regex Replace Post Content',
			'description'         => 'Performs a confirm-gated regex replacement in wp_posts.post_content for selected post types and statuses. Defaults to dry-run.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'pattern', 'replacement' ),
				'properties'           => array(
					'pattern'       => array( 'type' => 'string' ),
					'replacement'   => array( 'type' => 'string' ),
					'post_types'    => array(
						'type'    => 'array',
						'items'   => array( 'type' => 'string' ),
						'default' => array( 'page' ),
					),
					'post_statuses' => array(
						'type'    => 'array',
						'items'   => array( 'type' => 'string' ),
						'default' => array( 'publish' ),
					),
					'dry_run'       => array( 'type' => 'boolean', 'default' => true ),
					'confirm'       => array( 'type' => 'boolean', 'default' => false ),
					'limit'         => array( 'type' => 'integer', 'default' => 0 ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'            => array( 'type' => 'boolean' ),
					'dry_run'            => array( 'type' => 'boolean' ),
					'candidate_posts'    => array( 'type' => 'integer' ),
					'replacements_found' => array( 'type' => 'integer' ),
					'updated_posts'      => array( 'type' => 'integer' ),
					'replacements_made'  => array( 'type' => 'integer' ),
					'samples'            => array( 'type' => 'array' ),
					'errors'             => array( 'type' => 'array' ),
					'message'            => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => static function ( array $input = array() ): array {
				return mcp_database_regex_replace_post_content( $input );
			},
			'permission_callback' => static function (): bool {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => true,
				),
			),
		)
	);
}
add_action( 'wp_abilities_api_init', 'mcp_register_database_abilities' );
