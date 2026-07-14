<?php
/**
 * Real WordPress/MariaDB integration proof for the core table-engine abilities.
 *
 * Run only through WP-CLI after installing the candidate plugin on a disposable
 * validation site:
 *
 *     wp --allow-root eval-file /path/to/check-table-engine-wp-runtime.php
 *
 * The proof remaps the allowlisted `links` wpdb property to one uniquely named
 * fixture table. It never changes a real WordPress core table.
 *
 * @package MCP_Abilities_Database
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( 'WP_CLI' ) ) {
	throw new RuntimeException( 'wp_cli_required' );
}

if ( ! function_exists( 'wp_get_ability' ) || ! function_exists( 'mcp_database_core_table_allowlist' ) ) {
	WP_CLI::error( 'database_plugin_or_abilities_api_unavailable' );
}

global $wpdb;

if ( ! $wpdb instanceof wpdb ) {
	WP_CLI::error( 'wpdb_unavailable' );
}

/**
 * Fail an integration assertion without including database diagnostics.
 *
 * @param bool   $condition Assertion condition.
 * @param string $code Stable failure code.
 * @return void
 */
function mcp_database_wp_runtime_assert( bool $condition, string $code ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $code );
	}
}

/**
 * Execute a registered WordPress Ability and require an array result.
 *
 * @param WP_Ability $ability Registered ability.
 * @param mixed      $input Ability input. Omit by passing the sentinel object.
 * @return array<string, mixed>
 */
function mcp_database_wp_runtime_execute( WP_Ability $ability, mixed $input ): array {
	$result = $input instanceof stdClass ? $ability->execute() : $ability->execute( $input );
	if ( is_wp_error( $result ) ) {
		throw new RuntimeException( 'ability_execution_failed:' . sanitize_key( $result->get_error_code() ) );
	}

	mcp_database_wp_runtime_assert( is_array( $result ), 'ability_result_not_array' );
	return $result;
}

$previous_user_id = get_current_user_id();
if ( ! current_user_can( 'manage_options' ) || ( is_multisite() && ( ! is_super_admin() || ! current_user_can( 'manage_network_options' ) ) ) ) {
	$operator_id = 0;
	if ( is_multisite() ) {
		$super_admins = get_super_admins();
		if ( ! empty( $super_admins ) ) {
			$operator = get_user_by( 'login', (string) reset( $super_admins ) );
			$operator_id = $operator instanceof WP_User ? (int) $operator->ID : 0;
		}
	} else {
		$administrator_ids = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ids',
			)
		);
		$operator_id = ! empty( $administrator_ids ) ? (int) reset( $administrator_ids ) : 0;
	}

	if ( $operator_id > 0 ) {
		wp_set_current_user( $operator_id );
	}
}

if ( ! current_user_can( 'manage_options' ) || ( is_multisite() && ( ! is_super_admin() || ! current_user_can( 'manage_network_options' ) ) ) ) {
	wp_set_current_user( $previous_user_id );
	WP_CLI::error( 'authorized_validation_operator_unavailable' );
}

$audit_ability   = wp_get_ability( 'database/audit-core-table-engines' );
$convert_ability = wp_get_ability( 'database/convert-core-tables-to-innodb' );

if ( ! $audit_ability instanceof WP_Ability || ! $convert_ability instanceof WP_Ability ) {
	wp_set_current_user( $previous_user_id );
	WP_CLI::error( 'table_engine_abilities_unavailable' );
}

$table_suffix  = 'mcp_dbe_' . bin2hex( random_bytes( 8 ) );
$prefix_budget = max( 0, 64 - strlen( $table_suffix ) );
$fixture_table = substr( (string) $wpdb->prefix, 0, $prefix_budget ) . $table_suffix;
$original_links = (string) $wpdb->links;

$fixture_created          = false;
$links_remapped           = false;
$cleanup_done             = false;
$cleanup_error            = null;
$previous_suppress_errors = $wpdb->suppress_errors( true );

$cleanup = static function () use ( &$cleanup_done, &$fixture_created, &$links_remapped, &$cleanup_error, $fixture_table, $original_links, $previous_user_id, $previous_suppress_errors, $wpdb ): void {
	if ( $cleanup_done ) {
		return;
	}
	$cleanup_done = true;

	if ( $links_remapped ) {
		$wpdb->links    = $original_links;
		$links_remapped = false;
	}

	if ( $fixture_created ) {
		// The identifier is generated locally and prepared with WordPress's identifier placeholder.
		$drop_result = $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $fixture_table ) );
		if ( false === $drop_result ) {
			$cleanup_error = 'fixture_drop_failed';
		}
		$fixture_created = false;
	}

	wp_set_current_user( $previous_user_id );
	$wpdb->suppress_errors( (bool) $previous_suppress_errors );
};

register_shutdown_function( $cleanup );

$failure   = null;
$scenarios = 0;

try {
	$myisam_support = $wpdb->get_var(
		"SELECT SUPPORT FROM information_schema.ENGINES WHERE ENGINE = 'MyISAM'"
	);
	mcp_database_wp_runtime_assert( in_array( strtoupper( (string) $myisam_support ), array( 'YES', 'DEFAULT' ), true ), 'myisam_unavailable' );

	$audit_schema = $audit_ability->get_input_schema();
	mcp_database_wp_runtime_assert( array() === ( $audit_schema['default'] ?? null ), 'audit_default_not_empty_object' );
	mcp_database_wp_runtime_assert( false === ( $audit_schema['additionalProperties'] ?? null ), 'audit_schema_not_closed' );
	mcp_database_wp_runtime_assert( empty( $audit_schema['required'] ), 'audit_schema_unexpected_required_input' );
	$all_table_audit = mcp_database_wp_runtime_execute( $audit_ability, new stdClass() );
	mcp_database_wp_runtime_assert( 18 === ( $all_table_audit['audit_count'] ?? null ), 'no_input_audit_did_not_select_allowlist' );
	++$scenarios;

	$create_sql = $wpdb->prepare(
		'CREATE TABLE %i (id bigint unsigned NOT NULL AUTO_INCREMENT, payload varchar(32) NOT NULL, PRIMARY KEY (id)) ENGINE=MyISAM DEFAULT CHARACTER SET utf8mb4',
		$fixture_table
	);
	$create_result = $wpdb->query( $create_sql );
	mcp_database_wp_runtime_assert( false !== $create_result, 'fixture_create_failed' );
	$fixture_created = true;
	++$scenarios;

	$wpdb->links    = $fixture_table;
	$links_remapped = true;
	mcp_database_wp_runtime_assert( $fixture_table === mcp_database_resolve_core_table_name( 'links' ), 'fixture_remap_failed' );
	mcp_database_wp_runtime_assert( $original_links !== mcp_database_resolve_core_table_name( 'links' ), 'real_links_table_still_selected' );
	++$scenarios;

	$wpdb->last_error = 'mcp-runtime-private-diagnostic-must-not-leak';
	$scoped_audit = mcp_database_wp_runtime_execute( $audit_ability, array( 'tables' => array( 'links' ) ) );
	mcp_database_wp_runtime_assert( true === ( $scoped_audit['success'] ?? null ), 'scoped_audit_failed' );
	mcp_database_wp_runtime_assert( 1 === ( $scoped_audit['non_innodb_count'] ?? null ), 'fixture_not_reported_non_innodb' );
	mcp_database_wp_runtime_assert( 'MyISAM' === ( $scoped_audit['results'][0]['engine'] ?? null ), 'fixture_engine_not_myisam' );
	mcp_database_wp_runtime_assert( ! str_contains( wp_json_encode( $scoped_audit ), 'mcp-runtime-private-diagnostic' ), 'raw_database_error_leaked' );
	++$scenarios;

	$dry_run = mcp_database_wp_runtime_execute( $convert_ability, array( 'tables' => array( 'links' ) ) );
	mcp_database_wp_runtime_assert( true === ( $dry_run['success'] ?? null ), 'dry_run_failed' );
	mcp_database_wp_runtime_assert( true === ( $dry_run['dry_run'] ?? null ), 'dry_run_default_not_applied' );
	mcp_database_wp_runtime_assert( 1 === ( $dry_run['planned_count'] ?? null ), 'dry_run_not_planned' );
	mcp_database_wp_runtime_assert( false === ( $dry_run['results'][0]['attempted'] ?? null ), 'dry_run_attempted_ddl' );
	$post_dry_audit = mcp_database_wp_runtime_execute( $audit_ability, array( 'tables' => array( 'links' ) ) );
	mcp_database_wp_runtime_assert( 'MyISAM' === ( $post_dry_audit['results'][0]['engine'] ?? null ), 'dry_run_changed_engine' );
	++$scenarios;

	$transaction_started = $wpdb->query( 'START TRANSACTION' );
	mcp_database_wp_runtime_assert( false !== $transaction_started, 'transaction_start_failed' );
	$live_result = mcp_database_wp_runtime_execute(
		$convert_ability,
		array(
			'tables'  => array( 'links' ),
			'dry_run' => false,
			'confirm' => true,
		)
	);
	$rollback_result = $wpdb->query( 'ROLLBACK' );
	mcp_database_wp_runtime_assert( false !== $rollback_result, 'post_ddl_rollback_failed' );
	mcp_database_wp_runtime_assert( true === ( $live_result['success'] ?? null ), 'live_conversion_failed' );
	mcp_database_wp_runtime_assert( 1 === ( $live_result['statement_succeeded_count'] ?? null ), 'ddl_statement_not_reported_success' );
	mcp_database_wp_runtime_assert( 1 === ( $live_result['postcondition_met_count'] ?? null ), 'ddl_postcondition_not_met' );
	mcp_database_wp_runtime_assert( 'changed' === ( $live_result['mutation_outcome'] ?? null ), 'ddl_mutation_not_reported_changed' );
	mcp_database_wp_runtime_assert( true === ( $live_result['mutation_occurred'] ?? null ), 'ddl_mutation_truth_missing' );
	++$scenarios;

	$final_audit = mcp_database_wp_runtime_execute( $audit_ability, array( 'tables' => array( 'links' ) ) );
	mcp_database_wp_runtime_assert( true === ( $final_audit['success'] ?? null ), 'final_audit_failed' );
	mcp_database_wp_runtime_assert( 1 === ( $final_audit['innodb_count'] ?? null ), 'fixture_not_innodb_after_conversion' );
	mcp_database_wp_runtime_assert( 'InnoDB' === ( $final_audit['results'][0]['engine'] ?? null ), 'rollback_reverted_ddl' );
	++$scenarios;
} catch ( Throwable $throwable ) {
	$failure = $throwable;
} finally {
	$cleanup();
}

if ( null === $failure && null !== $cleanup_error ) {
	$failure = new RuntimeException( $cleanup_error );
}

if ( $failure instanceof Throwable ) {
	WP_CLI::error( sanitize_key( $failure->getMessage() ) );
}

echo wp_json_encode(
	array(
		'success'   => true,
		'scenarios' => $scenarios,
	)
) . PHP_EOL;
