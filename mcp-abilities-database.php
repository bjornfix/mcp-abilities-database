<?php
/**
 * Plugin Name: MCP Abilities - Database
 * Plugin URI: https://devenia.com
 * Description: Controlled database maintenance abilities for MCP, including post-content maintenance and WordPress database health audits.
 * Version: 0.1.4
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
 * Expected index shapes for WordPress core tables.
 *
 * Index names are deliberately ignored. WordPress installations may retain a
 * valid equivalent index under a different name, while the ordered columns and
 * uniqueness are what determine whether the expected lookup path exists.
 *
 * @return array<string, array<int, array{columns: string[], unique: bool}>>
 */
function mcp_database_expected_core_index_shapes(): array {
	return array(
		'posts' => array(
			array( 'columns' => array( 'ID' ), 'unique' => true ),
			array( 'columns' => array( 'post_name' ), 'unique' => false ),
			array( 'columns' => array( 'post_type', 'post_status', 'post_date', 'ID' ), 'unique' => false ),
			array( 'columns' => array( 'post_parent' ), 'unique' => false ),
			array( 'columns' => array( 'post_author' ), 'unique' => false ),
			array( 'columns' => array( 'post_type', 'post_status', 'post_author' ), 'unique' => false ),
		),
		'postmeta' => array(
			array( 'columns' => array( 'meta_id' ), 'unique' => true ),
			array( 'columns' => array( 'post_id' ), 'unique' => false ),
			array( 'columns' => array( 'meta_key' ), 'unique' => false ),
		),
		'comments' => array(
			array( 'columns' => array( 'comment_ID' ), 'unique' => true ),
			array( 'columns' => array( 'comment_post_ID' ), 'unique' => false ),
			array( 'columns' => array( 'comment_approved', 'comment_date_gmt' ), 'unique' => false ),
			array( 'columns' => array( 'comment_date_gmt' ), 'unique' => false ),
			array( 'columns' => array( 'comment_parent' ), 'unique' => false ),
			array( 'columns' => array( 'comment_author_email' ), 'unique' => false ),
		),
		'commentmeta' => array(
			array( 'columns' => array( 'meta_id' ), 'unique' => true ),
			array( 'columns' => array( 'comment_id' ), 'unique' => false ),
			array( 'columns' => array( 'meta_key' ), 'unique' => false ),
		),
		'terms' => array(
			array( 'columns' => array( 'term_id' ), 'unique' => true ),
			array( 'columns' => array( 'slug' ), 'unique' => false ),
			array( 'columns' => array( 'name' ), 'unique' => false ),
		),
		'termmeta' => array(
			array( 'columns' => array( 'meta_id' ), 'unique' => true ),
			array( 'columns' => array( 'term_id' ), 'unique' => false ),
			array( 'columns' => array( 'meta_key' ), 'unique' => false ),
		),
		'term_taxonomy' => array(
			array( 'columns' => array( 'term_taxonomy_id' ), 'unique' => true ),
			array( 'columns' => array( 'term_id', 'taxonomy' ), 'unique' => true ),
			array( 'columns' => array( 'taxonomy' ), 'unique' => false ),
		),
		'term_relationships' => array(
			array( 'columns' => array( 'object_id', 'term_taxonomy_id' ), 'unique' => true ),
			array( 'columns' => array( 'term_taxonomy_id' ), 'unique' => false ),
		),
		'options' => array(
			array( 'columns' => array( 'option_id' ), 'unique' => true ),
			array( 'columns' => array( 'option_name' ), 'unique' => true ),
			array( 'columns' => array( 'autoload' ), 'unique' => false ),
		),
		'links' => array(
			array( 'columns' => array( 'link_id' ), 'unique' => true ),
			array( 'columns' => array( 'link_visible' ), 'unique' => false ),
		),
		'users' => array(
			array( 'columns' => array( 'ID' ), 'unique' => true ),
			array( 'columns' => array( 'user_login' ), 'unique' => false ),
			array( 'columns' => array( 'user_nicename' ), 'unique' => false ),
			array( 'columns' => array( 'user_email' ), 'unique' => false ),
		),
		'usermeta' => array(
			array( 'columns' => array( 'umeta_id' ), 'unique' => true ),
			array( 'columns' => array( 'user_id' ), 'unique' => false ),
			array( 'columns' => array( 'meta_key' ), 'unique' => false ),
		),
	);
}

/**
 * Whether one ordered column list is a left prefix of another.
 *
 * @param string[] $candidate Possible prefix.
 * @param string[] $covering Possible covering list.
 */
function mcp_database_index_columns_are_left_prefix( array $candidate, array $covering ): bool {
	if ( empty( $candidate ) || count( $candidate ) >= count( $covering ) ) {
		return false;
	}

	return $candidate === array_slice( $covering, 0, count( $candidate ) );
}

/**
 * Authorize site-table metadata inspection.
 *
 * A single-site administrator owns the whole installation schema. On
 * multisite, even a site-local prefix can overlap network-global or sibling
 * blog table names, so schema discovery additionally requires network-level
 * authority.
 */
function mcp_database_can_audit_index_health(): bool {
	if ( ! current_user_can( 'manage_options' ) ) {
		return false;
	}

	return ! is_multisite() || ( is_super_admin() && current_user_can( 'manage_network_options' ) );
}

/**
 * Whether a discovered physical table belongs to the current site scope.
 *
 * On a multisite main site, wp_ must not absorb sibling tables such as wp_2_*.
 * Global core tables remain in scope only for a network-authorized caller.
 */
function mcp_database_is_current_site_table( string $table_name ): bool {
	global $wpdb;

	if ( '' === $table_name || ! str_starts_with( $table_name, $wpdb->prefix ) ) {
		return false;
	}

	if ( is_multisite() && $wpdb->prefix === $wpdb->base_prefix ) {
		$sibling_pattern = '/^' . preg_quote( $wpdb->base_prefix, '/' ) . '[0-9]+_/';
		if ( 1 === preg_match( $sibling_pattern, $table_name ) ) {
			return false;
		}
	}

	return true;
}

/**
 * Audit index metadata for every physical table owned by the current site prefix.
 *
 * No row values, arbitrary identifiers, or caller-supplied SQL are accepted or
 * returned. Performance Schema usage counters are observations since the last
 * server reset, not proof that an index is safe to remove.
 *
 * @param array<string, mixed> $input Bounded pagination input.
 * @return array<string, mixed>
 */
function mcp_database_audit_index_health( array $input = array() ): array {
	global $wpdb;

	$limit  = max( 1, min( 100, (int) ( $input['limit'] ?? 25 ) ) );
	$offset = max( 0, (int) ( $input['offset'] ?? 0 ) );
	$table_pattern = $wpdb->esc_like( $wpdb->prefix ) . '%';
	$wpdb->last_error = '';
	// Current physical metadata cannot be served from WordPress object cache.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$table_rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT TABLE_NAME AS table_name, ENGINE AS engine, TABLE_TYPE AS table_type, ROW_FORMAT AS row_format, TABLE_COLLATION AS table_collation, TABLE_ROWS AS rows_estimate, DATA_LENGTH AS data_bytes, INDEX_LENGTH AS index_bytes, DATA_FREE AS data_free_bytes FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s ORDER BY TABLE_NAME",
			$table_pattern
		),
		ARRAY_A
	);

	if ( '' !== $wpdb->last_error || ! is_array( $table_rows ) ) {
		return array(
			'success'                  => false,
			'observed_at'              => gmdate( 'c' ),
			'scope'                    => 'current_site',
			'table_prefix'             => $wpdb->prefix,
			'total_table_count'        => 0,
			'returned_table_count'     => 0,
			'table_count'              => 0,
			'limit'                    => $limit,
			'offset'                   => $offset,
			'next_offset'              => null,
			'total_data_bytes'         => 0,
			'total_index_bytes'        => 0,
			'total_free_bytes'         => 0,
			'engine_counts'            => array(),
			'usage_counters_available' => false,
			'issue_count'              => 1,
			'issue_counts'             => array( 'table_metadata_query_failed' => 1 ),
			'returned_issue_count'     => 1,
			'issues_truncated'         => false,
			'issues'                   => array( array( 'code' => 'table_metadata_query_failed', 'severity' => 'error', 'table_name' => '', 'index_name' => '', 'related_index_name' => '', 'message' => 'Database table metadata could not be read.' ) ),
			'tables'                   => array(),
			'message'                  => 'Index health audit could not read database table metadata.',
		);
	}
	$table_rows = array_values(
		array_filter(
			$table_rows,
			static fn( array $row ): bool => mcp_database_is_current_site_table( (string) ( $row['table_name'] ?? '' ) )
		)
	);
	$total_table_count = count( $table_rows );
	$allowed_table_names = array_fill_keys( array_map( static fn( array $row ): string => (string) $row['table_name'], $table_rows ), true );

	$wpdb->last_error = '';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$index_rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT TABLE_NAME AS table_name, INDEX_NAME AS index_name, NON_UNIQUE AS non_unique, SEQ_IN_INDEX AS seq_in_index, COLUMN_NAME AS column_name, SUB_PART AS sub_part, CARDINALITY AS cardinality, INDEX_TYPE AS index_type FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX",
			$table_pattern
		),
		ARRAY_A
	);
	$index_query_failed = '' !== $wpdb->last_error || ! is_array( $index_rows );
	if ( $index_query_failed ) {
		$index_rows = array();
	} else {
		$index_rows = array_values(
			array_filter(
				$index_rows,
				static fn( array $row ): bool => isset( $allowed_table_names[ (string) ( $row['table_name'] ?? '' ) ] )
			)
		);
	}

	$wpdb->last_error = '';
	// These counters may be unavailable when Performance Schema is disabled or access is restricted.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$usage_rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT OBJECT_NAME AS table_name, INDEX_NAME AS index_name, COUNT_READ AS count_read, COUNT_WRITE AS count_write, COUNT_FETCH AS count_fetch FROM performance_schema.table_io_waits_summary_by_index_usage WHERE OBJECT_SCHEMA = DATABASE() AND OBJECT_NAME LIKE %s",
			$table_pattern
		),
		ARRAY_A
	);
	$usage_available = '' === $wpdb->last_error && is_array( $usage_rows ) && ! empty( $usage_rows );
	$usage_map       = array();
	if ( $usage_available ) {
		foreach ( $usage_rows as $usage_row ) {
			$table_name = (string) ( $usage_row['table_name'] ?? '' );
			$index_name = (string) ( $usage_row['index_name'] ?? '' );
			if ( isset( $allowed_table_names[ $table_name ] ) && '' !== $index_name ) {
				$usage_map[ $table_name ][ $index_name ] = array(
					'count_read'  => (int) ( $usage_row['count_read'] ?? 0 ),
					'count_write' => (int) ( $usage_row['count_write'] ?? 0 ),
					'count_fetch' => (int) ( $usage_row['count_fetch'] ?? 0 ),
				);
			}
		}
	}

	$indexes_by_table = array();
	foreach ( $index_rows as $row ) {
		$table_name = (string) ( $row['table_name'] ?? '' );
		$index_name = (string) ( $row['index_name'] ?? '' );
		$column_name = (string) ( $row['column_name'] ?? '' );
		if ( '' === $table_name || '' === $index_name || '' === $column_name ) {
			continue;
		}
		if ( ! isset( $indexes_by_table[ $table_name ][ $index_name ] ) ) {
			$indexes_by_table[ $table_name ][ $index_name ] = array(
				'name'        => $index_name,
				'unique'      => 0 === (int) ( $row['non_unique'] ?? 1 ),
				'primary'     => 'PRIMARY' === $index_name,
				'type'        => (string) ( $row['index_type'] ?? '' ),
				'columns'     => array(),
				'cardinality' => 0,
				'count_read'  => null,
				'count_write' => null,
				'count_fetch' => null,
			);
		}
		$sub_part = isset( $row['sub_part'] ) ? (int) $row['sub_part'] : 0;
		$indexes_by_table[ $table_name ][ $index_name ]['columns'][] = $column_name . ( $sub_part > 0 ? '(' . $sub_part . ')' : '' );
		$indexes_by_table[ $table_name ][ $index_name ]['cardinality'] = max(
			(int) $indexes_by_table[ $table_name ][ $index_name ]['cardinality'],
			(int) ( $row['cardinality'] ?? 0 )
		);
	}

	foreach ( $indexes_by_table as $table_name => &$table_indexes ) {
		foreach ( $table_indexes as $index_name => &$index ) {
			if ( isset( $usage_map[ $table_name ][ $index_name ] ) ) {
				$index['count_read']  = $usage_map[ $table_name ][ $index_name ]['count_read'];
				$index['count_write'] = $usage_map[ $table_name ][ $index_name ]['count_write'];
				$index['count_fetch'] = $usage_map[ $table_name ][ $index_name ]['count_fetch'];
			}
		}
		unset( $index );
	}
	unset( $table_indexes );

	$issues            = array();
	$results           = array();
	$total_data_bytes  = 0;
	$total_index_bytes = 0;
	$total_free_bytes  = 0;
	$engine_counts     = array();
	$core_shapes       = mcp_database_expected_core_index_shapes();
	$core_names        = array();
	foreach ( array_keys( $core_shapes ) as $table_key ) {
		$table_name = mcp_database_resolve_core_table_name( $table_key );
		if ( null !== $table_name ) {
			$core_names[ $table_name ] = $table_key;
		}
	}

	foreach ( $table_rows as $table_row ) {
		$table_name  = (string) ( $table_row['table_name'] ?? '' );
		$table_indexes = array_values( $indexes_by_table[ $table_name ] ?? array() );
		$data_bytes  = (int) ( $table_row['data_bytes'] ?? 0 );
		$index_bytes = (int) ( $table_row['index_bytes'] ?? 0 );
		$free_bytes  = (int) ( $table_row['data_free_bytes'] ?? 0 );
		$total_data_bytes  += $data_bytes;
		$total_index_bytes += $index_bytes;
		$total_free_bytes  += $free_bytes;
		$engine_key = strtoupper( (string) ( $table_row['engine'] ?? 'UNKNOWN' ) );
		$engine_key = '' !== $engine_key ? $engine_key : 'UNKNOWN';
		$engine_counts[ $engine_key ] = (int) ( $engine_counts[ $engine_key ] ?? 0 ) + 1;

		$duplicate_count = 0;
		for ( $left = 0; $left < count( $table_indexes ); ++$left ) {
			for ( $right = $left + 1; $right < count( $table_indexes ); ++$right ) {
				$first  = $table_indexes[ $left ];
				$second = $table_indexes[ $right ];
				if ( 'BTREE' !== strtoupper( (string) $first['type'] ) || 'BTREE' !== strtoupper( (string) $second['type'] ) ) {
					continue;
				}
				if ( $first['columns'] === $second['columns'] && $first['unique'] === $second['unique'] ) {
					++$duplicate_count;
					$issues[] = array( 'code' => 'duplicate_index_exact', 'severity' => 'warning', 'table_name' => $table_name, 'index_name' => (string) $second['name'], 'related_index_name' => (string) $first['name'], 'message' => 'Two indexes have the same ordered columns and uniqueness.' );
					continue;
				}
				if ( empty( $first['unique'] ) && empty( $second['unique'] ) ) {
					if ( mcp_database_index_columns_are_left_prefix( $first['columns'], $second['columns'] ) ) {
						++$duplicate_count;
						$issues[] = array( 'code' => 'duplicate_index_left_prefix', 'severity' => 'review', 'table_name' => $table_name, 'index_name' => (string) $first['name'], 'related_index_name' => (string) $second['name'], 'message' => 'A non-unique BTREE index is the left prefix of another index; review workload evidence before removal.' );
					} elseif ( mcp_database_index_columns_are_left_prefix( $second['columns'], $first['columns'] ) ) {
						++$duplicate_count;
						$issues[] = array( 'code' => 'duplicate_index_left_prefix', 'severity' => 'review', 'table_name' => $table_name, 'index_name' => (string) $second['name'], 'related_index_name' => (string) $first['name'], 'message' => 'A non-unique BTREE index is the left prefix of another index; review workload evidence before removal.' );
					}
				}
			}
		}

		$has_primary = false;
		$unused_count = 0;
		foreach ( $table_indexes as $index ) {
			$has_primary = $has_primary || ! empty( $index['primary'] );
			if ( $usage_available && empty( $index['primary'] ) && 0 === (int) $index['count_read'] ) {
				++$unused_count;
			}
		}
		if ( 'BASE TABLE' === (string) ( $table_row['table_type'] ?? '' ) && ! $has_primary ) {
			$issues[] = array( 'code' => 'missing_primary_key', 'severity' => 'warning', 'table_name' => $table_name, 'index_name' => '', 'related_index_name' => '', 'message' => 'Base table has no primary key.' );
		}

		if ( isset( $core_names[ $table_name ] ) ) {
			$table_key = $core_names[ $table_name ];
			foreach ( $core_shapes[ $table_key ] as $expected ) {
				$matched = false;
				foreach ( $table_indexes as $actual ) {
					$actual_columns = array_map( static fn( string $column ): string => (string) preg_replace( '/\([0-9]+\)$/', '', $column ), $actual['columns'] );
					if ( $actual_columns === $expected['columns'] && ( ! $expected['unique'] || ! empty( $actual['unique'] ) ) ) {
						$matched = true;
						break;
					}
				}
				if ( ! $matched ) {
					$issues[] = array( 'code' => 'missing_core_index_shape', 'severity' => 'error', 'table_name' => $table_name, 'index_name' => implode( ',', $expected['columns'] ), 'related_index_name' => '', 'message' => 'A WordPress core index shape is missing.' );
				}
			}
		}

		$results[] = array(
			'table_name'               => $table_name,
			'engine'                   => isset( $table_row['engine'] ) ? (string) $table_row['engine'] : null,
			'row_format'               => isset( $table_row['row_format'] ) ? (string) $table_row['row_format'] : null,
			'collation'                => isset( $table_row['table_collation'] ) ? (string) $table_row['table_collation'] : null,
			'rows_estimate'            => isset( $table_row['rows_estimate'] ) ? (int) $table_row['rows_estimate'] : null,
			'data_bytes'               => $data_bytes,
			'index_bytes'              => $index_bytes,
			'data_free_bytes'          => $free_bytes,
			'index_count'              => count( $table_indexes ),
			'has_primary_key'          => $has_primary,
			'duplicate_candidate_count'=> $duplicate_count,
			'unused_observation_count' => $unused_count,
			'indexes'                  => $table_indexes,
		);
	}

	if ( $index_query_failed ) {
		$issues[] = array( 'code' => 'index_metadata_query_failed', 'severity' => 'error', 'table_name' => '', 'index_name' => '', 'related_index_name' => '', 'message' => 'Database index metadata could not be read.' );
	}

	$issue_counts = array();
	foreach ( $issues as $issue ) {
		$code = sanitize_key( (string) ( $issue['code'] ?? '' ) );
		if ( '' !== $code ) {
			$issue_counts[ $code ] = (int) ( $issue_counts[ $code ] ?? 0 ) + 1;
		}
	}
	$total_issue_count = count( $issues );
	$results           = array_slice( $results, $offset, $limit );
	$returned_tables   = array_fill_keys( array_map( static fn( array $row ): string => (string) $row['table_name'], $results ), true );
	$issues            = array_values(
		array_filter(
			$issues,
			static function ( array $issue ) use ( $returned_tables ): bool {
				$table_name = (string) ( $issue['table_name'] ?? '' );
				return '' === $table_name || isset( $returned_tables[ $table_name ] );
			}
		)
	);
	$next_offset = $offset + count( $results ) < $total_table_count ? $offset + count( $results ) : null;

	return array(
		'success'                  => ! $index_query_failed,
		'observed_at'              => gmdate( 'c' ),
		'scope'                    => 'current_site',
		'table_prefix'             => $wpdb->prefix,
		'total_table_count'        => $total_table_count,
		'returned_table_count'     => count( $results ),
		'table_count'              => count( $results ),
		'limit'                    => $limit,
		'offset'                   => $offset,
		'next_offset'              => $next_offset,
		'total_data_bytes'         => $total_data_bytes,
		'total_index_bytes'        => $total_index_bytes,
		'total_free_bytes'         => $total_free_bytes,
		'engine_counts'            => $engine_counts,
		'usage_counters_available' => $usage_available,
		'issue_count'              => $total_issue_count,
		'issue_counts'             => $issue_counts,
		'returned_issue_count'     => count( $issues ),
		'issues_truncated'         => count( $issues ) < $total_issue_count,
		'issues'                   => $issues,
		'tables'                   => $results,
		'message'                  => sprintf( 'Audited %d site-prefixed tables and their index metadata; found %d issue or review item(s) across the full scope.', count( $results ), $total_issue_count ),
	);
}

/**
 * Audit bounded wp_options health without returning option values.
 *
 * @param array<string, mixed> $input Bounded detail input.
 * @return array<string, mixed>
 */
function mcp_database_audit_options_health( array $input = array() ): array {
	global $wpdb;

	$limit           = max( 1, min( 50, (int) ( $input['limit'] ?? 10 ) ) );
	$autoload_values = array( 'yes', 'on', 'auto', 'auto-on' );
	$now             = time();
	$wpdb->last_error = '';
	// Fixed aggregate query over the exact WordPress options table; values are measured, never returned.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$summary = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT COUNT(*) AS option_count, COALESCE(SUM(OCTET_LENGTH(option_value)), 0) AS total_value_bytes, COALESCE(SUM(CASE WHEN autoload IN (%s, %s, %s, %s) THEN 1 ELSE 0 END), 0) AS autoload_count, COALESCE(SUM(CASE WHEN autoload IN (%s, %s, %s, %s) THEN OCTET_LENGTH(option_value) ELSE 0 END), 0) AS autoload_bytes, COALESCE(SUM(CASE WHEN autoload IN (%s, %s, %s, %s) AND OCTET_LENGTH(option_value) >= 262144 THEN 1 ELSE 0 END), 0) AS oversized_autoload_count, COALESCE(SUM(CASE WHEN option_name LIKE %s THEN 1 ELSE 0 END), 0) AS transient_row_count, COALESCE(SUM(CASE WHEN option_name LIKE %s AND CAST(option_value AS UNSIGNED) > 0 AND CAST(option_value AS UNSIGNED) < %d THEN 1 ELSE 0 END), 0) AS expired_transient_count FROM %i",
			$autoload_values[0],
			$autoload_values[1],
			$autoload_values[2],
			$autoload_values[3],
			$autoload_values[0],
			$autoload_values[1],
			$autoload_values[2],
			$autoload_values[3],
			$autoload_values[0],
			$autoload_values[1],
			$autoload_values[2],
			$autoload_values[3],
			$wpdb->esc_like( '_transient_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_' ) . '%',
			$now,
			$wpdb->options
		),
		ARRAY_A
	);

	if ( '' !== $wpdb->last_error || ! is_array( $summary ) ) {
		return array(
			'success'                  => false,
			'observed_at'              => gmdate( 'c' ),
			'option_count'             => 0,
			'total_value_bytes'        => 0,
			'autoload_count'           => 0,
			'autoload_bytes'           => 0,
			'oversized_autoload_count' => 0,
			'transient_row_count'      => 0,
			'expired_transient_count'  => 0,
			'limit'                    => $limit,
			'top_autoloaded_options'   => array(),
			'issue_count'              => 1,
			'issues'                   => array( array( 'code' => 'options_health_query_failed', 'severity' => 'error', 'message' => 'Options health metadata could not be read.' ) ),
			'message'                  => 'Options health audit failed.',
		);
	}

	$wpdb->last_error = '';
	// Names and byte sizes are bounded diagnostic metadata; option values are never selected.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$top_rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT option_name, autoload, OCTET_LENGTH(option_value) AS value_bytes FROM %i WHERE autoload IN (%s, %s, %s, %s) ORDER BY value_bytes DESC, option_name ASC LIMIT %d",
			$wpdb->options,
			$autoload_values[0],
			$autoload_values[1],
			$autoload_values[2],
			$autoload_values[3],
			$limit
		),
		ARRAY_A
	);
	$top_rows = is_array( $top_rows ) && '' === $wpdb->last_error ? $top_rows : array();
	$top_options = array_map(
		static fn( array $row ): array => array(
			'option_name' => (string) ( $row['option_name'] ?? '' ),
			'autoload'    => (string) ( $row['autoload'] ?? '' ),
			'value_bytes' => (int) ( $row['value_bytes'] ?? 0 ),
		),
		$top_rows
	);

	$autoload_bytes           = (int) ( $summary['autoload_bytes'] ?? 0 );
	$oversized_autoload_count = (int) ( $summary['oversized_autoload_count'] ?? 0 );
	$expired_transient_count  = (int) ( $summary['expired_transient_count'] ?? 0 );
	$issues                    = array();
	if ( $autoload_bytes > 800000 ) {
		$issues[] = array( 'code' => 'autoload_total_large', 'severity' => 'warning', 'message' => 'Total autoloaded option values exceed 800,000 bytes.' );
	}
	if ( $oversized_autoload_count > 0 ) {
		$issues[] = array( 'code' => 'oversized_autoloaded_options', 'severity' => 'review', 'message' => 'One or more autoloaded options are at least 262,144 bytes.' );
	}
	if ( $expired_transient_count > 0 ) {
		$issues[] = array( 'code' => 'expired_transients_present', 'severity' => 'review', 'message' => 'Expired transient timeout rows are present.' );
	}

	return array(
		'success'                  => true,
		'observed_at'              => gmdate( 'c' ),
		'option_count'             => (int) ( $summary['option_count'] ?? 0 ),
		'total_value_bytes'        => (int) ( $summary['total_value_bytes'] ?? 0 ),
		'autoload_count'           => (int) ( $summary['autoload_count'] ?? 0 ),
		'autoload_bytes'           => $autoload_bytes,
		'oversized_autoload_count' => $oversized_autoload_count,
		'transient_row_count'      => (int) ( $summary['transient_row_count'] ?? 0 ),
		'expired_transient_count'  => $expired_transient_count,
		'limit'                    => $limit,
		'top_autoloaded_options'   => $top_options,
		'issue_count'              => count( $issues ),
		'issues'                   => $issues,
		'message'                  => sprintf( 'Audited %d options, including %d autoloaded rows and %d expired transient timeout rows.', (int) ( $summary['option_count'] ?? 0 ), (int) ( $summary['autoload_count'] ?? 0 ), $expired_transient_count ),
	);
}

/**
 * Delete a bounded batch of expired site transients.
 *
 * The timeout rows are selected from the exact current-site options table, but
 * deletion goes through the WordPress option API so persistent object caches
 * are invalidated correctly. Names and values are never returned.
 *
 * @param array<string, mixed> $input Cleanup controls.
 * @return array<string, mixed>
 */
function mcp_database_cleanup_expired_transients( array $input = array() ): array {
	global $wpdb;

	$limit   = max( 1, min( 500, (int) ( $input['limit'] ?? 100 ) ) );
	$dry_run = ! array_key_exists( 'dry_run', $input ) || (bool) $input['dry_run'];
	$confirm = (bool) ( $input['confirm'] ?? false );
	$before  = mcp_database_audit_options_health( array( 'limit' => 1 ) );

	if ( ! $dry_run && ! $confirm ) {
		return array(
			'success'                    => false,
			'dry_run'                    => false,
			'confirmed'                  => false,
			'limit'                      => $limit,
			'expired_before'             => (int) ( $before['expired_transient_count'] ?? 0 ),
			'selected_count'             => 0,
			'deleted_transient_count'    => 0,
			'deleted_timeout_count'      => 0,
			'skipped_refreshed_count'    => 0,
			'failed_count'               => 0,
			'expired_after'              => (int) ( $before['expired_transient_count'] ?? 0 ),
			'more_expired_may_remain'    => ! empty( $before['expired_transient_count'] ),
			'message'                    => 'Live transient cleanup requires dry_run=false and confirm=true. Review a dry run first.',
		);
	}

	$now = time();
	$wpdb->last_error = '';
	// Select only a bounded batch of expired timeout option names; transient values are never read by SQL or returned.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$timeout_rows = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM %i WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) > 0 AND CAST(option_value AS UNSIGNED) < %d ORDER BY option_id ASC LIMIT %d",
			$wpdb->options,
			$wpdb->esc_like( '_transient_timeout_' ) . '%',
			$now,
			$limit
		)
	);

	if ( '' !== $wpdb->last_error || ! is_array( $timeout_rows ) ) {
		return array(
			'success'                    => false,
			'dry_run'                    => $dry_run,
			'confirmed'                  => $confirm,
			'limit'                      => $limit,
			'expired_before'             => (int) ( $before['expired_transient_count'] ?? 0 ),
			'selected_count'             => 0,
			'deleted_transient_count'    => 0,
			'deleted_timeout_count'      => 0,
			'skipped_refreshed_count'    => 0,
			'failed_count'               => 1,
			'expired_after'              => (int) ( $before['expired_transient_count'] ?? 0 ),
			'more_expired_may_remain'    => ! empty( $before['expired_transient_count'] ),
			'message'                    => 'Expired transient candidates could not be read.',
		);
	}

	$selected_count          = count( $timeout_rows );
	$deleted_transient_count = 0;
	$deleted_timeout_count   = 0;
	$skipped_refreshed_count = 0;
	$failed_count            = 0;

	if ( ! $dry_run ) {
		$timeout_prefix = '_transient_timeout_';
		foreach ( $timeout_rows as $timeout_option_name ) {
			$timeout_option_name = (string) $timeout_option_name;
			$transient_name      = substr( $timeout_option_name, strlen( $timeout_prefix ) );
			$current_timeout     = get_option( $timeout_option_name, false );

			if ( '' === $transient_name || ! is_numeric( $current_timeout ) || (int) $current_timeout <= 0 || (int) $current_timeout >= time() ) {
				++$skipped_refreshed_count;
				continue;
			}

			if ( delete_option( '_transient_' . $transient_name ) ) {
				++$deleted_transient_count;
			}
			delete_option( $timeout_option_name );
			if ( false === get_option( $timeout_option_name, false ) ) {
				++$deleted_timeout_count;
			} else {
				++$failed_count;
			}
		}
	}

	$after         = $dry_run ? $before : mcp_database_audit_options_health( array( 'limit' => 1 ) );
	$expired_after = (int) ( $after['expired_transient_count'] ?? 0 );

	return array(
		'success'                    => 0 === $failed_count && ! empty( $before['success'] ) && ! empty( $after['success'] ),
		'dry_run'                    => $dry_run,
		'confirmed'                  => $confirm,
		'limit'                      => $limit,
		'expired_before'             => (int) ( $before['expired_transient_count'] ?? 0 ),
		'selected_count'             => $selected_count,
		'deleted_transient_count'    => $deleted_transient_count,
		'deleted_timeout_count'      => $deleted_timeout_count,
		'skipped_refreshed_count'    => $skipped_refreshed_count,
		'failed_count'               => $failed_count,
		'expired_after'              => $expired_after,
		'more_expired_may_remain'    => $expired_after > 0,
		'message'                    => $dry_run
			? sprintf( 'Dry run selected %d expired transient timeout row(s); no options were deleted.', $selected_count )
			: sprintf( 'Deleted %d expired transient timeout row(s); %d expired timeout row(s) remain.', $deleted_timeout_count, $expired_after ),
	);
}

/**
 * Return one bounded, correlated database health snapshot.
 *
 * @return array<string, mixed>
 */
function mcp_database_audit_health(): array {
	$index_health   = mcp_database_audit_index_health( array( 'limit' => 1, 'offset' => 0 ) );
	$options_health = mcp_database_audit_options_health( array( 'limit' => 5 ) );
	$issue_counts   = (array) ( $index_health['issue_counts'] ?? array() );

	return array(
		'success'     => ! empty( $index_health['success'] ) && ! empty( $options_health['success'] ),
		'observed_at' => gmdate( 'c' ),
		'scope'       => 'current_site',
		'coverage'    => array(
			'storage_and_indexes' => ! empty( $index_health['success'] ) ? 'complete' : 'unavailable',
			'options'             => ! empty( $options_health['success'] ) ? 'complete' : 'unavailable',
			'core_data_integrity' => 'not_run',
			'query_workload'      => ! empty( $index_health['usage_counters_available'] ) ? 'index_counters_available' : 'unavailable',
		),
		'storage'     => array(
			'table_count'       => (int) ( $index_health['total_table_count'] ?? 0 ),
			'data_bytes'        => (int) ( $index_health['total_data_bytes'] ?? 0 ),
			'index_bytes'       => (int) ( $index_health['total_index_bytes'] ?? 0 ),
			'free_bytes'        => (int) ( $index_health['total_free_bytes'] ?? 0 ),
			'engine_counts'     => (array) ( $index_health['engine_counts'] ?? array() ),
		),
		'indexes'     => array(
			'issue_count'              => (int) ( $index_health['issue_count'] ?? 0 ),
			'issue_counts'             => $issue_counts,
			'usage_counters_available' => ! empty( $index_health['usage_counters_available'] ),
		),
		'options'     => array(
			'option_count'             => (int) ( $options_health['option_count'] ?? 0 ),
			'total_value_bytes'        => (int) ( $options_health['total_value_bytes'] ?? 0 ),
			'autoload_count'           => (int) ( $options_health['autoload_count'] ?? 0 ),
			'autoload_bytes'           => (int) ( $options_health['autoload_bytes'] ?? 0 ),
			'oversized_autoload_count' => (int) ( $options_health['oversized_autoload_count'] ?? 0 ),
			'expired_transient_count'  => (int) ( $options_health['expired_transient_count'] ?? 0 ),
			'issue_count'              => (int) ( $options_health['issue_count'] ?? 0 ),
		),
		'message'     => 'Returned a bounded current-site database health snapshot. Use the focused index and options audits for details.',
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
 * Get the output schema for the read-only index health audit.
 *
 * @return array<string, mixed>
 */
function mcp_database_index_health_output_schema(): array {
	$nullable_integer = array( 'type' => array( 'integer', 'null' ) );
	$nullable_string  = array( 'type' => array( 'string', 'null' ) );
	$index_schema     = array(
		'type'                 => 'object',
		'required'             => array( 'name', 'unique', 'primary', 'type', 'columns', 'cardinality', 'count_read', 'count_write', 'count_fetch' ),
		'properties'           => array(
			'name'        => array( 'type' => 'string' ),
			'unique'      => array( 'type' => 'boolean' ),
			'primary'     => array( 'type' => 'boolean' ),
			'type'        => array( 'type' => 'string' ),
			'columns'     => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
			'cardinality' => array( 'type' => 'integer' ),
			'count_read'  => $nullable_integer,
			'count_write' => $nullable_integer,
			'count_fetch' => $nullable_integer,
		),
		'additionalProperties' => false,
	);
	$issue_schema = array(
		'type'                 => 'object',
		'required'             => array( 'code', 'severity', 'table_name', 'index_name', 'related_index_name', 'message' ),
		'properties'           => array(
			'code'               => array( 'type' => 'string' ),
			'severity'           => array( 'type' => 'string', 'enum' => array( 'error', 'warning', 'review' ) ),
			'table_name'         => array( 'type' => 'string' ),
			'index_name'         => array( 'type' => 'string' ),
			'related_index_name' => array( 'type' => 'string' ),
			'message'            => array( 'type' => 'string' ),
		),
		'additionalProperties' => false,
	);
	$table_schema = array(
		'type'                 => 'object',
		'required'             => array( 'table_name', 'engine', 'row_format', 'collation', 'rows_estimate', 'data_bytes', 'index_bytes', 'data_free_bytes', 'index_count', 'has_primary_key', 'duplicate_candidate_count', 'unused_observation_count', 'indexes' ),
		'properties'           => array(
			'table_name'                => array( 'type' => 'string' ),
			'engine'                    => $nullable_string,
			'row_format'                => $nullable_string,
			'collation'                 => $nullable_string,
			'rows_estimate'             => $nullable_integer,
			'data_bytes'                => array( 'type' => 'integer' ),
			'index_bytes'               => array( 'type' => 'integer' ),
			'data_free_bytes'           => array( 'type' => 'integer' ),
			'index_count'               => array( 'type' => 'integer' ),
			'has_primary_key'           => array( 'type' => 'boolean' ),
			'duplicate_candidate_count' => array( 'type' => 'integer' ),
			'unused_observation_count'  => array( 'type' => 'integer' ),
			'indexes'                   => array( 'type' => 'array', 'items' => $index_schema ),
		),
		'additionalProperties' => false,
	);

	return array(
		'type'                 => 'object',
		'required'             => array( 'success', 'observed_at', 'scope', 'table_prefix', 'total_table_count', 'returned_table_count', 'table_count', 'limit', 'offset', 'next_offset', 'total_data_bytes', 'total_index_bytes', 'total_free_bytes', 'engine_counts', 'usage_counters_available', 'issue_count', 'issue_counts', 'returned_issue_count', 'issues_truncated', 'issues', 'tables', 'message' ),
		'properties'           => array(
			'success'                  => array( 'type' => 'boolean' ),
			'observed_at'              => array( 'type' => 'string' ),
			'scope'                    => array( 'type' => 'string', 'enum' => array( 'current_site' ) ),
			'table_prefix'             => array( 'type' => 'string' ),
			'total_table_count'        => array( 'type' => 'integer' ),
			'returned_table_count'     => array( 'type' => 'integer' ),
			'table_count'              => array( 'type' => 'integer' ),
			'limit'                    => array( 'type' => 'integer' ),
			'offset'                   => array( 'type' => 'integer' ),
			'next_offset'              => $nullable_integer,
			'total_data_bytes'         => array( 'type' => 'integer' ),
			'total_index_bytes'        => array( 'type' => 'integer' ),
			'total_free_bytes'         => array( 'type' => 'integer' ),
			'engine_counts'            => array( 'type' => 'object', 'additionalProperties' => array( 'type' => 'integer' ) ),
			'usage_counters_available' => array( 'type' => 'boolean' ),
			'issue_count'              => array( 'type' => 'integer' ),
			'issue_counts'             => array( 'type' => 'object', 'additionalProperties' => array( 'type' => 'integer' ) ),
			'returned_issue_count'     => array( 'type' => 'integer' ),
			'issues_truncated'         => array( 'type' => 'boolean' ),
			'issues'                   => array( 'type' => 'array', 'items' => $issue_schema ),
			'tables'                   => array( 'type' => 'array', 'items' => $table_schema ),
			'message'                  => array( 'type' => 'string' ),
		),
		'additionalProperties' => false,
	);
}

/**
 * Get the output schema for the options health audit.
 *
 * @return array<string, mixed>
 */
function mcp_database_options_health_output_schema(): array {
	return array(
		'type'                 => 'object',
		'required'             => array( 'success', 'observed_at', 'option_count', 'total_value_bytes', 'autoload_count', 'autoload_bytes', 'oversized_autoload_count', 'transient_row_count', 'expired_transient_count', 'limit', 'top_autoloaded_options', 'issue_count', 'issues', 'message' ),
		'properties'           => array(
			'success'                  => array( 'type' => 'boolean' ),
			'observed_at'              => array( 'type' => 'string' ),
			'option_count'             => array( 'type' => 'integer' ),
			'total_value_bytes'        => array( 'type' => 'integer' ),
			'autoload_count'           => array( 'type' => 'integer' ),
			'autoload_bytes'           => array( 'type' => 'integer' ),
			'oversized_autoload_count' => array( 'type' => 'integer' ),
			'transient_row_count'      => array( 'type' => 'integer' ),
			'expired_transient_count'  => array( 'type' => 'integer' ),
			'limit'                    => array( 'type' => 'integer' ),
			'top_autoloaded_options'   => array(
				'type'  => 'array',
				'items' => array(
					'type'                 => 'object',
					'required'             => array( 'option_name', 'autoload', 'value_bytes' ),
					'properties'           => array(
						'option_name' => array( 'type' => 'string' ),
						'autoload'    => array( 'type' => 'string' ),
						'value_bytes' => array( 'type' => 'integer' ),
					),
					'additionalProperties' => false,
				),
			),
			'issue_count'              => array( 'type' => 'integer' ),
			'issues'                   => array(
				'type'  => 'array',
				'items' => array(
					'type'                 => 'object',
					'required'             => array( 'code', 'severity', 'message' ),
					'properties'           => array(
						'code'     => array( 'type' => 'string' ),
						'severity' => array( 'type' => 'string', 'enum' => array( 'error', 'warning', 'review' ) ),
						'message'  => array( 'type' => 'string' ),
					),
					'additionalProperties' => false,
				),
			),
			'message'                  => array( 'type' => 'string' ),
		),
		'additionalProperties' => false,
	);
}

/**
 * Get the output schema for bounded expired-transient cleanup.
 *
 * @return array<string, mixed>
 */
function mcp_database_transient_cleanup_output_schema(): array {
	$integer = array( 'type' => 'integer' );

	return array(
		'type'                 => 'object',
		'required'             => array( 'success', 'dry_run', 'confirmed', 'limit', 'expired_before', 'selected_count', 'deleted_transient_count', 'deleted_timeout_count', 'skipped_refreshed_count', 'failed_count', 'expired_after', 'more_expired_may_remain', 'message' ),
		'properties'           => array(
			'success'                    => array( 'type' => 'boolean' ),
			'dry_run'                    => array( 'type' => 'boolean' ),
			'confirmed'                  => array( 'type' => 'boolean' ),
			'limit'                      => $integer,
			'expired_before'             => $integer,
			'selected_count'             => $integer,
			'deleted_transient_count'    => $integer,
			'deleted_timeout_count'      => $integer,
			'skipped_refreshed_count'    => $integer,
			'failed_count'               => $integer,
			'expired_after'              => $integer,
			'more_expired_may_remain'    => array( 'type' => 'boolean' ),
			'message'                    => array( 'type' => 'string' ),
		),
		'additionalProperties' => false,
	);
}

/**
 * Get the output schema for the bounded database health snapshot.
 *
 * @return array<string, mixed>
 */
function mcp_database_health_output_schema(): array {
	return array(
		'type'                 => 'object',
		'required'             => array( 'success', 'observed_at', 'scope', 'coverage', 'storage', 'indexes', 'options', 'message' ),
		'properties'           => array(
			'success'     => array( 'type' => 'boolean' ),
			'observed_at' => array( 'type' => 'string' ),
			'scope'       => array( 'type' => 'string', 'enum' => array( 'current_site' ) ),
			'coverage'    => array(
				'type'                 => 'object',
				'required'             => array( 'storage_and_indexes', 'options', 'core_data_integrity', 'query_workload' ),
				'properties'           => array(
					'storage_and_indexes' => array( 'type' => 'string' ),
					'options'             => array( 'type' => 'string' ),
					'core_data_integrity' => array( 'type' => 'string' ),
					'query_workload'      => array( 'type' => 'string' ),
				),
				'additionalProperties' => false,
			),
			'storage'     => array(
				'type'                 => 'object',
				'required'             => array( 'table_count', 'data_bytes', 'index_bytes', 'free_bytes', 'engine_counts' ),
				'properties'           => array(
					'table_count'   => array( 'type' => 'integer' ),
					'data_bytes'    => array( 'type' => 'integer' ),
					'index_bytes'   => array( 'type' => 'integer' ),
					'free_bytes'    => array( 'type' => 'integer' ),
					'engine_counts' => array( 'type' => 'object', 'additionalProperties' => array( 'type' => 'integer' ) ),
				),
				'additionalProperties' => false,
			),
			'indexes'     => array(
				'type'                 => 'object',
				'required'             => array( 'issue_count', 'issue_counts', 'usage_counters_available' ),
				'properties'           => array(
					'issue_count'              => array( 'type' => 'integer' ),
					'issue_counts'             => array( 'type' => 'object', 'additionalProperties' => array( 'type' => 'integer' ) ),
					'usage_counters_available' => array( 'type' => 'boolean' ),
				),
				'additionalProperties' => false,
			),
			'options'     => array(
				'type'                 => 'object',
				'required'             => array( 'option_count', 'total_value_bytes', 'autoload_count', 'autoload_bytes', 'oversized_autoload_count', 'expired_transient_count', 'issue_count' ),
				'properties'           => array(
					'option_count'             => array( 'type' => 'integer' ),
					'total_value_bytes'        => array( 'type' => 'integer' ),
					'autoload_count'           => array( 'type' => 'integer' ),
					'autoload_bytes'           => array( 'type' => 'integer' ),
					'oversized_autoload_count' => array( 'type' => 'integer' ),
					'expired_transient_count'  => array( 'type' => 'integer' ),
					'issue_count'              => array( 'type' => 'integer' ),
				),
				'additionalProperties' => false,
			),
			'message'     => array( 'type' => 'string' ),
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
		'database/audit-health',
		array(
			'label'               => 'Audit Database Health',
			'description'         => 'Returns one bounded, timestamped current-site snapshot of storage engines, table and index size, index findings, options/autoload health, and observability coverage.',
			'category'            => 'site',
			'input_schema'        => array( 'type' => 'object', 'default' => array(), 'properties' => array(), 'additionalProperties' => false ),
			'output_schema'       => mcp_database_health_output_schema(),
			'execute_callback'    => static function ( $input = array() ): array {
				unset( $input );
				return mcp_database_audit_health();
			},
			'permission_callback' => static function ( $input = array() ): bool {
				unset( $input );
				return mcp_database_can_audit_index_health();
			},
			'meta'                => array( 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ) ),
		)
	);

	wp_register_ability(
		'database/audit-options-health',
		array(
			'label'               => 'Audit WordPress Options Health',
			'description'         => 'Read-only bounded audit of option value size, autoload volume, large autoloaded option names and sizes, and expired transient counts. Option values are never returned.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'default'              => array(),
				'properties'           => array( 'limit' => array( 'type' => 'integer', 'default' => 10, 'minimum' => 1, 'maximum' => 50 ) ),
				'additionalProperties' => false,
			),
			'output_schema'       => mcp_database_options_health_output_schema(),
			'execute_callback'    => static function ( $input = array() ): array {
				return mcp_database_audit_options_health( is_array( $input ) ? $input : array() );
			},
			'permission_callback' => static function ( $input = array() ): bool {
				unset( $input );
				return current_user_can( 'manage_options' );
			},
			'meta'                => array( 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ) ),
		)
	);

	wp_register_ability(
		'database/cleanup-expired-transients',
		array(
			'label'               => 'Clean Up Expired WordPress Transients',
			'description'         => 'Plans or deletes a bounded batch of expired current-site transient option pairs. Defaults to dry-run and never returns transient names or values.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'default'              => array(),
				'properties'           => array(
					'limit'   => array( 'type' => 'integer', 'default' => 100, 'minimum' => 1, 'maximum' => 500 ),
					'dry_run' => array( 'type' => 'boolean', 'default' => true ),
					'confirm' => array( 'type' => 'boolean', 'default' => false ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => mcp_database_transient_cleanup_output_schema(),
			'execute_callback'    => static function ( $input = array() ): array {
				return mcp_database_cleanup_expired_transients( is_array( $input ) ? $input : array() );
			},
			'permission_callback' => static function ( $input = array() ): bool {
				unset( $input );
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
		'database/audit-index-health',
		array(
			'label'               => 'Audit Database Index Health',
			'description'         => 'Read-only index, storage-size, duplicate-index, core-index-shape, and optional Performance Schema usage audit for tables owned by the current WordPress site prefix.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'default'              => array(),
				'properties'           => array(
					'limit'  => array( 'type' => 'integer', 'default' => 25, 'minimum' => 1, 'maximum' => 100 ),
					'offset' => array( 'type' => 'integer', 'default' => 0, 'minimum' => 0 ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => mcp_database_index_health_output_schema(),
			'execute_callback'    => static function ( $input = array() ): array {
				return mcp_database_audit_index_health( is_array( $input ) ? $input : array() );
			},
			'permission_callback' => static function ( $input = array() ): bool {
				unset( $input );
				return mcp_database_can_audit_index_health();
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
			'execute_callback'    => static function ( $input = array() ): array {
				return mcp_database_audit_core_table_engines( is_array( $input ) ? $input : array() );
			},
			'permission_callback' => static function ( $input = array() ): bool {
				return mcp_database_can_manage_core_table_request( is_array( $input ) ? $input : array(), true );
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
