<?php
declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );

function add_action( string $hook, callable|string $callback ): void {}
function sanitize_key( string $value ): string {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $value ) ?? '' );
}
function current_user_can( string $capability ): bool {
	return 'manage_options' === $capability;
}
function is_multisite(): bool {
	return false;
}
function is_super_admin(): bool {
	return true;
}

$registered_abilities = array();
function wp_register_ability( string $name, array $definition ): void {
	global $registered_abilities;
	$registered_abilities[ $name ] = $definition;
}

$test_options = array();
function get_option( string $name, mixed $default = false ): mixed {
	global $test_options;
	return array_key_exists( $name, $test_options ) ? $test_options[ $name ] : $default;
}
function delete_option( string $name ): bool {
	global $test_options;
	if ( ! array_key_exists( $name, $test_options ) ) {
		return false;
	}
	unset( $test_options[ $name ] );
	return true;
}

final class Transient_Cleanup_Test_WPDB {
	public string $prefix = 'wp_';
	public string $base_prefix = 'wp_';
	public string $options = 'wp_options';
	public string $posts = 'wp_posts';
	public string $postmeta = 'wp_postmeta';
	public string $comments = 'wp_comments';
	public string $commentmeta = 'wp_commentmeta';
	public string $terms = 'wp_terms';
	public string $termmeta = 'wp_termmeta';
	public string $term_taxonomy = 'wp_term_taxonomy';
	public string $term_relationships = 'wp_term_relationships';
	public string $links = 'wp_links';
	public string $users = 'wp_users';
	public string $usermeta = 'wp_usermeta';
	public string $last_error = '';
	public bool $refresh_race = false;

	public function esc_like( string $value ): string {
		return addcslashes( $value, '_%\\' );
	}

	public function prepare( string $query, mixed ...$args ): string {
		return $query;
	}

	public function get_row( string $query, string $format ): array {
		global $test_options;
		$expired = 0;
		foreach ( $test_options as $name => $value ) {
			if ( str_starts_with( $name, '_transient_timeout_' ) && is_numeric( $value ) && (int) $value > 0 && (int) $value < time() ) {
				++$expired;
			}
		}
		return array(
			'option_count' => count( $test_options ),
			'total_value_bytes' => 100,
			'autoload_count' => 0,
			'autoload_bytes' => 0,
			'oversized_autoload_count' => 0,
			'transient_row_count' => count( $test_options ),
			'expired_transient_count' => $expired,
		);
	}

	public function get_results( string $query, string $format ): array {
		return array();
	}

	public function get_col( string $query ): array {
		global $test_options;
		$rows = array();
		foreach ( $test_options as $name => $value ) {
			if ( str_starts_with( $name, '_transient_timeout_' ) && is_numeric( $value ) && (int) $value > 0 && (int) $value < time() ) {
				$rows[] = $name;
			}
		}
		if ( $this->refresh_race && isset( $test_options['_transient_timeout_race'] ) ) {
			$test_options['_transient_timeout_race'] = time() + 3600;
		}
		return array_slice( $rows, 0, 500 );
	}
}

function assert_true( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$wpdb = new Transient_Cleanup_Test_WPDB();
require dirname( __DIR__ ) . '/mcp-abilities-database.php';

$test_options = array(
	'_transient_expired' => 'value',
	'_transient_timeout_expired' => time() - 100,
	'_transient_race' => 'value',
	'_transient_timeout_race' => time() - 100,
	'_transient_fresh' => 'value',
	'_transient_timeout_fresh' => time() + 3600,
);

$dry_run = mcp_database_cleanup_expired_transients( array() );
assert_true( true === $dry_run['success'] && true === $dry_run['dry_run'] && 2 === $dry_run['selected_count'], 'Dry run must report candidates without deletion.' );
assert_true( isset( $test_options['_transient_timeout_expired'] ), 'Dry run must not mutate options.' );

$unconfirmed = mcp_database_cleanup_expired_transients( array( 'dry_run' => false ) );
assert_true( false === $unconfirmed['success'] && 0 === $unconfirmed['deleted_timeout_count'], 'Live cleanup must require confirmation.' );

$wpdb->refresh_race = true;
$live = mcp_database_cleanup_expired_transients( array( 'limit' => 500, 'dry_run' => false, 'confirm' => true ) );
assert_true( true === $live['success'] && 1 === $live['deleted_timeout_count'] && 1 === $live['deleted_transient_count'], 'Live cleanup must delete the still-expired pair.' );
assert_true( 1 === $live['skipped_refreshed_count'] && isset( $test_options['_transient_race'] ), 'A transient refreshed after selection must be preserved.' );
assert_true( 0 === $live['expired_after'] && false === $live['more_expired_may_remain'], 'After-count must verify the bounded cleanup result.' );
assert_true( ! str_contains( json_encode( $live ), '_transient_expired' ) && ! str_contains( json_encode( $live ), '_transient_race' ), 'Response must not expose transient names.' );

mcp_register_database_abilities();
$definition = $registered_abilities['database/cleanup-expired-transients'];
assert_true( 500 === $definition['input_schema']['properties']['limit']['maximum'], 'Ability limit must be capped at 500.' );
assert_true( true === $definition['meta']['annotations']['destructive'] && true === $definition['permission_callback']( array() ), 'Ability must be destructive and admin-authorized.' );

echo json_encode( array( 'success' => true, 'scenarios' => 4 ), JSON_UNESCAPED_SLASHES ) . PHP_EOL;
