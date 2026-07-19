<?php
declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );

function add_action( string $hook, callable|string $callback ): void {}
function sanitize_key( string $value ): string {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $value ) ?? '' );
}

$test_is_multisite = false;
$test_is_super_admin = true;
function is_multisite(): bool {
	global $test_is_multisite;
	return $test_is_multisite;
}
function is_super_admin(): bool {
	global $test_is_super_admin;
	return $test_is_super_admin;
}
function current_user_can( string $capability ): bool {
	return in_array( $capability, array( 'manage_options', 'manage_network_options' ), true );
}

$registered_abilities = array();
function wp_register_ability( string $name, array $definition ): void {
	global $registered_abilities;
	$registered_abilities[ $name ] = $definition;
}

final class Index_Health_Test_WPDB {
	public string $prefix = 'wp_';
	public string $base_prefix = 'wp_';
	public string $options = 'wp_options';
	public string $posts = 'unused_posts';
	public string $postmeta = 'unused_postmeta';
	public string $comments = 'unused_comments';
	public string $commentmeta = 'unused_commentmeta';
	public string $terms = 'unused_terms';
	public string $termmeta = 'unused_termmeta';
	public string $term_taxonomy = 'unused_term_taxonomy';
	public string $term_relationships = 'unused_term_relationships';
	public string $links = 'unused_links';
	public string $users = 'unused_users';
	public string $usermeta = 'unused_usermeta';
	public string $last_error = '';

	public function esc_like( string $value ): string {
		return addcslashes( $value, '_%\\' );
	}

	public function prepare( string $query, mixed ...$args ): string {
		return $query;
	}

	public function get_results( string $query, string $format ): array {
		$this->last_error = '';
		if ( str_contains( $query, 'information_schema.TABLES' ) ) {
			return array(
				array( 'table_name' => 'wp_2_private', 'engine' => 'InnoDB', 'table_type' => 'BASE TABLE', 'row_format' => 'Dynamic', 'table_collation' => 'utf8mb4_unicode_ci', 'rows_estimate' => 99, 'data_bytes' => 999, 'index_bytes' => 999, 'data_free_bytes' => 0 ),
				array( 'table_name' => 'wp_custom', 'engine' => 'InnoDB', 'table_type' => 'BASE TABLE', 'row_format' => 'Dynamic', 'table_collation' => 'utf8mb4_unicode_ci', 'rows_estimate' => 10, 'data_bytes' => 1000, 'index_bytes' => 500, 'data_free_bytes' => 100 ),
			);
		}
		if ( str_contains( $query, 'information_schema.STATISTICS' ) ) {
			return array(
				array( 'table_name' => 'wp_2_private', 'index_name' => 'secret_key', 'non_unique' => 1, 'seq_in_index' => 1, 'column_name' => 'secret', 'sub_part' => null, 'cardinality' => 99, 'index_type' => 'BTREE' ),
				array( 'table_name' => 'wp_custom', 'index_name' => 'PRIMARY', 'non_unique' => 0, 'seq_in_index' => 1, 'column_name' => 'id', 'sub_part' => null, 'cardinality' => 10, 'index_type' => 'BTREE' ),
				array( 'table_name' => 'wp_custom', 'index_name' => 'lookup', 'non_unique' => 1, 'seq_in_index' => 1, 'column_name' => 'kind', 'sub_part' => null, 'cardinality' => 3, 'index_type' => 'BTREE' ),
				array( 'table_name' => 'wp_custom', 'index_name' => 'lookup_covering', 'non_unique' => 1, 'seq_in_index' => 1, 'column_name' => 'kind', 'sub_part' => null, 'cardinality' => 3, 'index_type' => 'BTREE' ),
				array( 'table_name' => 'wp_custom', 'index_name' => 'lookup_covering', 'non_unique' => 1, 'seq_in_index' => 2, 'column_name' => 'created_at', 'sub_part' => null, 'cardinality' => 10, 'index_type' => 'BTREE' ),
			);
		}
		if ( str_contains( $query, 'performance_schema.table_io_waits_summary_by_index_usage' ) ) {
			return array();
		}
		if ( str_contains( $query, 'SELECT option_name' ) ) {
			return array( array( 'option_name' => 'large_safe_name', 'autoload' => 'on', 'value_bytes' => 300000 ) );
		}
		throw new RuntimeException( 'Unexpected get_results query.' );
	}

	public function get_row( string $query, string $format ): array {
		$this->last_error = '';
		return array(
			'option_count' => 100,
			'total_value_bytes' => 1200000,
			'autoload_count' => 20,
			'autoload_bytes' => 900000,
			'oversized_autoload_count' => 1,
			'transient_row_count' => 10,
			'expired_transient_count' => 2,
		);
	}
}

function assert_true( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$wpdb = new Index_Health_Test_WPDB();
require dirname( __DIR__ ) . '/mcp-abilities-database.php';

$scenarios = 0;
$test_is_multisite = true;
$audit = mcp_database_audit_index_health( array( 'limit' => 1 ) );
assert_true( 1 === $audit['total_table_count'] && 'wp_custom' === $audit['tables'][0]['table_name'], 'Main-site scope must exclude sibling multisite tables.' );
assert_true( 1 === $audit['issue_count'] && 'duplicate_index_left_prefix' === $audit['issues'][0]['code'], 'Left-prefix review finding must be deterministic.' );
assert_true( 1 === $audit['returned_issue_count'] && false === $audit['issues_truncated'] && 1 === $audit['issue_counts']['duplicate_index_left_prefix'], 'Issue totals and bounded details must agree.' );
assert_true( null === $audit['next_offset'] && false === $audit['usage_counters_available'], 'Pagination and unavailable usage evidence must be explicit.' );
++ $scenarios;

$options = mcp_database_audit_options_health( array( 'limit' => 5 ) );
assert_true( 900000 === $options['autoload_bytes'] && 3 === $options['issue_count'], 'Options audit must report bounded autoload and transient findings.' );
assert_true( ! array_key_exists( 'option_value', $options['top_autoloaded_options'][0] ), 'Option values must never be returned.' );
++ $scenarios;

$snapshot = mcp_database_audit_health();
assert_true( 1 === $snapshot['storage']['table_count'] && 900000 === $snapshot['options']['autoload_bytes'], 'Health snapshot must correlate storage and options observations.' );
assert_true( 'not_run' === $snapshot['coverage']['core_data_integrity'], 'Deferred expensive integrity coverage must be explicit.' );
++ $scenarios;

mcp_register_database_abilities();
$index_definition = $registered_abilities['database/audit-index-health'];
assert_true( 100 === $index_definition['input_schema']['properties']['limit']['maximum'], 'Index drill-down must be bounded.' );
assert_true( true === $index_definition['permission_callback']( null ), 'Network authority may audit the current multisite scope.' );
++ $scenarios;

$test_is_super_admin = false;
assert_true( false === $index_definition['permission_callback']( array() ), 'Multisite site admins must not inspect network-overlapping schema metadata.' );
++ $scenarios;

echo json_encode( array( 'success' => true, 'scenarios' => $scenarios ), JSON_UNESCAPED_SLASHES ) . PHP_EOL;
