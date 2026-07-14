<?php
declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );

function add_action( string $hook, callable|string $callback ): void {}
function sanitize_key( string $value ): string {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $value ) ?? '' );
}

$test_is_multisite = false;
$test_is_super_admin = false;
$test_capabilities = array( 'manage_options' => true, 'manage_network_options' => false );
function is_multisite(): bool {
	global $test_is_multisite;
	return $test_is_multisite;
}
function is_super_admin(): bool {
	global $test_is_super_admin;
	return $test_is_super_admin;
}
function current_user_can( string $capability ): bool {
	global $test_capabilities;
	return ! empty( $test_capabilities[ $capability ] );
}

$registered_abilities = array();
function wp_register_ability( string $name, array $definition ): void {
	global $registered_abilities;
	$registered_abilities[ $name ] = $definition;
}

final class Test_WPDB {
	public string $posts = 'custom_posts';
	public string $postmeta = 'custom_postmeta';
	public string $comments = 'custom_comments';
	public string $commentmeta = 'custom_commentmeta';
	public string $terms = 'custom_terms';
	public string $termmeta = 'custom_termmeta';
	public string $term_taxonomy = 'custom_term_taxonomy';
	public string $term_relationships = 'custom_term_relationships';
	public string $options = 'custom_options';
	public string $links = 'custom_links';
	public string $users = 'network_users';
	public string $usermeta = 'network_usermeta';
	public string $blogs = 'network_blogs';
	public string $blogmeta = 'network_blogmeta';
	public string $signups = 'network_signups';
	public string $site = 'network_site';
	public string $sitemeta = 'network_sitemeta';
	public string $registration_log = 'network_registration_log';
	public string $last_error = '';
	public ?string $prepared_table = null;
	public array $queries = array();
	public array $engines = array();
	public array $alter_return = array();
	public array $alter_engine_after = array();
	public array $metadata_fail = array();
	public array $hide_metadata_after_alter = array();
	public array $alter_attempted = array();

	public function __construct() {
		foreach ( array( 'custom_posts', 'custom_postmeta', 'custom_comments', 'custom_commentmeta', 'custom_terms', 'custom_termmeta', 'custom_term_taxonomy', 'custom_term_relationships', 'custom_options', 'custom_links', 'network_users', 'network_usermeta', 'network_blogs', 'network_blogmeta', 'network_signups', 'network_site', 'network_sitemeta', 'network_registration_log' ) as $table ) {
			$this->engines[ $table ] = 'InnoDB';
		}
	}

	public function reset_faults(): void {
		$this->queries = array();
		$this->alter_return = array();
		$this->alter_engine_after = array();
		$this->metadata_fail = array();
		$this->hide_metadata_after_alter = array();
		$this->alter_attempted = array();
		$this->last_error = '';
	}

	public function prepare( string $query, mixed ...$args ): string {
		if ( str_contains( $query, 'TABLE_NAME = %s' ) ) {
			$this->prepared_table = (string) $args[0];
			return str_replace( '%s', "'" . str_replace( "'", "''", (string) $args[0] ) . "'", $query );
		}
		if ( str_contains( $query, 'ALTER TABLE %i' ) ) {
			$table = (string) $args[0];
			$this->prepared_table = $table;
			return str_replace( '%i', '`' . str_replace( '`', '``', $table ) . '`', $query );
		}
		throw new RuntimeException( 'Unexpected prepare call.' );
	}

	public function get_row( string $query, string $format ): ?array {
		$table = $this->prepared_table;
		if ( null !== $table && in_array( $table, $this->metadata_fail, true ) ) {
			$this->last_error = 'raw-secret database diagnostic /srv/private/db.sql';
			return null;
		}
		$this->last_error = '';
		if ( null === $table || ! array_key_exists( $table, $this->engines ) ) {
			return null;
		}
		if ( ! empty( $this->alter_attempted[ $table ] ) && in_array( $table, $this->hide_metadata_after_alter, true ) ) {
			return null;
		}
		return array(
			'table_name'      => $table,
			'engine'          => $this->engines[ $table ],
			'table_type'      => 'BASE TABLE',
			'row_format'      => 'Dynamic',
			'table_collation' => 'utf8mb4_unicode_ci',
			'rows_estimate'   => 10,
			'data_bytes'      => 16384,
			'index_bytes'     => 16384,
		);
	}

	public function query( string $query ): int|false {
		$this->queries[] = $query;
		$table = $this->prepared_table;
		if ( null === $table ) {
			$this->last_error = 'raw-secret missing table diagnostic';
			return false;
		}
		$this->alter_attempted[ $table ] = true;
		$result = $this->alter_return[ $table ] ?? 0;
		if ( array_key_exists( $table, $this->alter_engine_after ) ) {
			$this->engines[ $table ] = $this->alter_engine_after[ $table ];
		} elseif ( false !== $result ) {
			$this->engines[ $table ] = 'InnoDB';
		}
		$this->last_error = false === $result ? 'raw-secret ALTER diagnostic /srv/private/db.sql' : '';
		return $result;
	}
}

function assert_true( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$wpdb = new Test_WPDB();
require dirname( __DIR__ ) . '/mcp-abilities-database.php';

$scenarios = 0;

$allowlist = mcp_database_core_table_allowlist();
assert_true( 18 === count( $allowlist ) && ! isset( $allowlist['arbitrary'] ), 'The core allowlist must remain fixed and explicit.' );
++ $scenarios;

$invalid = mcp_database_convert_core_tables_to_innodb( array( 'tables' => array( 'custom_posts' ) ) );
assert_true( false === $invalid['success'] && array() === $wpdb->queries, 'Physical table names must be rejected before SQL.' );
++ $scenarios;

$wpdb->engines['custom_posts'] = 'MyISAM';
$audit = mcp_database_audit_core_table_engines( array( 'tables' => array( 'posts', 'options' ) ) );
assert_true( true === $audit['success'] && 1 === $audit['non_innodb_count'] && 'custom_posts' === $audit['results'][0]['table_name'], 'Audit must return exact prefixed table evidence.' );
++ $scenarios;

$wpdb->metadata_fail = array( 'custom_posts' );
$metadata_failure = mcp_database_audit_core_table_engines( array( 'tables' => array( 'posts' ) ) );
assert_true( false === $metadata_failure['success'] && 'Database metadata query failed.' === $metadata_failure['results'][0]['error'], 'Raw wpdb diagnostics must not be returned.' );
assert_true( ! str_contains( json_encode( $metadata_failure ), 'raw-secret' ), 'Raw database diagnostics leaked into the audit response.' );
$wpdb->reset_faults();
++ $scenarios;

$dry_run = mcp_database_convert_core_tables_to_innodb( array( 'tables' => array( 'posts', 'options' ) ) );
assert_true( true === $dry_run['success'] && 1 === $dry_run['planned_count'] && array() === $wpdb->queries, 'Dry-run must plan without mutation.' );
++ $scenarios;

$unconfirmed = mcp_database_convert_core_tables_to_innodb( array( 'tables' => array( 'posts' ), 'dry_run' => false ) );
assert_true( false === $unconfirmed['success'] && array() === $wpdb->queries, 'Live conversion must require confirm=true.' );
++ $scenarios;

unset( $wpdb->blogs );
$preflight = mcp_database_convert_core_tables_to_innodb( array( 'tables' => array( 'posts', 'blogs' ), 'dry_run' => false, 'confirm' => true ) );
assert_true( false === $preflight['success'] && array() === $wpdb->queries && 'batch_preflight_aborted' === $preflight['results'][0]['error_code'], 'Batch preflight must prevent every mutation when one table is unavailable.' );
$wpdb->blogs = 'network_blogs';
++ $scenarios;

$wpdb->engines['custom_posts'] = 'MyISAM';
$converted = mcp_database_convert_core_tables_to_innodb( array( 'tables' => array( 'posts' ), 'dry_run' => false, 'confirm' => true ) );
assert_true( true === $converted['success'] && 'changed' === $converted['mutation_outcome'] && true === $converted['mutation_occurred'], 'Confirmed conversion must prove statement, postcondition, and mutation.' );
assert_true( 'ALTER TABLE `custom_posts` ENGINE = InnoDB' === $wpdb->queries[0], 'ALTER must use only the resolved prepared identifier.' );
$wpdb->reset_faults();
++ $scenarios;

$wpdb->engines['custom_posts'] = 'MyISAM';
$wpdb->alter_return['custom_posts'] = false;
$wpdb->alter_engine_after['custom_posts'] = 'InnoDB';
$ambiguous_success = mcp_database_convert_core_tables_to_innodb( array( 'tables' => array( 'posts' ), 'dry_run' => false, 'confirm' => true ) );
assert_true( true === $ambiguous_success['success'] && 'reported_failure' === $ambiguous_success['results'][0]['statement_outcome'] && 'met' === $ambiguous_success['results'][0]['postcondition'], 'A false statement result plus proven InnoDB must preserve both facts.' );
assert_true( 'unknown' === $ambiguous_success['results'][0]['mutation_outcome'] && null === $ambiguous_success['mutation_occurred'] && null === $ambiguous_success['partial_mutation'], 'Ambiguous mutation attribution must remain unknown.' );
assert_true( ! str_contains( json_encode( $ambiguous_success ), 'raw-secret' ), 'Raw ALTER diagnostics leaked into the response.' );
$wpdb->reset_faults();
++ $scenarios;

$wpdb->engines['custom_posts'] = 'MyISAM';
$wpdb->hide_metadata_after_alter = array( 'custom_posts' );
$missing_after = mcp_database_convert_core_tables_to_innodb( array( 'tables' => array( 'posts' ), 'dry_run' => false, 'confirm' => true ) );
assert_true( false === $missing_after['success'] && 'reported_success' === $missing_after['results'][0]['statement_outcome'] && 'unknown' === $missing_after['results'][0]['postcondition'], 'Statement success without after metadata must fail closed.' );
assert_true( 'unknown' === $missing_after['mutation_outcome'] && null === $missing_after['mutation_occurred'], 'Missing after metadata must make aggregate mutation unknown.' );
$wpdb->reset_faults();
++ $scenarios;

$wpdb->engines['custom_posts'] = 'MyISAM';
$wpdb->engines['custom_comments'] = 'MyISAM';
$wpdb->alter_return['custom_comments'] = false;
$wpdb->alter_engine_after['custom_comments'] = 'MyISAM';
$mixed = mcp_database_convert_core_tables_to_innodb( array( 'tables' => array( 'posts', 'comments' ), 'dry_run' => false, 'confirm' => true ) );
assert_true( false === $mixed['success'] && true === $mixed['partial_mutation'] && 'partial' === $mixed['mutation_outcome'], 'Known changed plus known unchanged mutation must be reported as partial.' );
assert_true( 1 === $mixed['postcondition_met_count'] && 1 === $mixed['postcondition_failed_count'] && 1 === $mixed['statement_failed_count'], 'Mixed batch aggregates must preserve statement and postcondition counts.' );
$wpdb->reset_faults();
++ $scenarios;

$wpdb->engines['custom_posts'] = 'MyISAM';
$wpdb->engines['custom_comments'] = 'MyISAM';
$wpdb->hide_metadata_after_alter = array( 'custom_comments' );
$mixed_unknown = mcp_database_convert_core_tables_to_innodb( array( 'tables' => array( 'posts', 'comments' ), 'dry_run' => false, 'confirm' => true ) );
assert_true( 'partial_or_unknown' === $mixed_unknown['mutation_outcome'] && null === $mixed_unknown['partial_mutation'], 'Mixed proven and unknown mutation must not claim definite partial state.' );
$wpdb->reset_faults();
++ $scenarios;

mcp_register_database_abilities();
$audit_definition = $registered_abilities['database/audit-core-table-engines'];
$convert_definition = $registered_abilities['database/convert-core-tables-to-innodb'];
assert_true( array() === $audit_definition['input_schema']['default'], 'No-input audit schema must default to an empty object.' );
assert_true( false === $convert_definition['input_schema']['additionalProperties'] && array( 'tables' ) === $convert_definition['input_schema']['required'], 'Conversion input schema must be closed and require tables.' );
++ $scenarios;

$test_is_multisite = true;
$test_is_super_admin = false;
$test_capabilities = array( 'manage_options' => true, 'manage_network_options' => false );
assert_true( true === $audit_definition['permission_callback']( array( 'tables' => array( 'posts' ) ) ), 'Subsite admin may audit site-local tables.' );
assert_true( false === $audit_definition['permission_callback']( array( 'tables' => array( 'users' ) ) ), 'Subsite admin must not audit network-global tables.' );
assert_true( false === $audit_definition['permission_callback']( array() ), 'No-input all-table audit must require network authority on multisite.' );
++ $scenarios;

assert_true( false === $convert_definition['permission_callback']( array( 'tables' => array( 'users' ) ) ), 'Subsite admin must not dry-run network-global conversion.' );
assert_true( false === $convert_definition['permission_callback']( array( 'tables' => array( 'users' ), 'dry_run' => false, 'confirm' => true ) ), 'Subsite admin must not run live network-global conversion.' );
++ $scenarios;

$test_is_super_admin = true;
$test_capabilities['manage_network_options'] = true;
assert_true( true === $audit_definition['permission_callback']( array() ), 'Network authority may run all-table audit.' );
assert_true( true === $convert_definition['permission_callback']( array( 'tables' => array( 'users' ) ) ), 'Network authority may dry-run network-global conversion.' );
assert_true( true === $convert_definition['permission_callback']( array( 'tables' => array( 'users' ), 'dry_run' => false, 'confirm' => true ) ), 'Network authority may run live network-global conversion.' );
++ $scenarios;

$test_is_super_admin = false;
$test_capabilities = array( 'manage_options' => false, 'manage_network_options' => false );
assert_true( false === $audit_definition['permission_callback']( array( 'tables' => array( 'posts' ) ) ), 'Users without site authority must be denied.' );
++ $scenarios;

echo json_encode( array( 'success' => true, 'scenarios' => $scenarios ), JSON_UNESCAPED_SLASHES ) . PHP_EOL;
