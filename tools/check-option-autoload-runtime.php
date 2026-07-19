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

$test_options = array(
	'large_legacy' => array( 'autoload' => 'yes', 'value' => 'preserve-me' ),
	'already_off'  => array( 'autoload' => 'off', 'value' => 'also-preserve' ),
);
function wp_set_option_autoload( string $option, bool $autoload ): bool {
	global $test_options;
	if ( ! isset( $test_options[ $option ] ) ) {
		return false;
	}
	$test_options[ $option ]['autoload'] = $autoload ? 'on' : 'off';
	return true;
}

final class Option_Autoload_Test_WPDB {
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
	public array $prepared_args = array();

	public function prepare( string $query, mixed ...$args ): string {
		$this->prepared_args = $args;
		return $query;
	}

	public function get_row( string $query, string $format ): ?array {
		global $test_options;
		$option_name = (string) ( $this->prepared_args[1] ?? '' );
		if ( ! isset( $test_options[ $option_name ] ) ) {
			return null;
		}
		return array(
			'option_name' => $option_name,
			'autoload'    => $test_options[ $option_name ]['autoload'],
			'value_bytes' => strlen( $test_options[ $option_name ]['value'] ),
		);
	}
}

function assert_true( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$wpdb = new Option_Autoload_Test_WPDB();
require dirname( __DIR__ ) . '/mcp-abilities-database.php';

$dry_run = mcp_database_set_option_autoload(
	array( 'option_names' => array( 'large_legacy', 'already_off' ), 'autoload' => false )
);
assert_true( true === $dry_run['success'] && 1 === $dry_run['planned_count'] && 0 === $dry_run['changed_count'], 'Dry run must distinguish planned and already-correct options.' );
assert_true( 'yes' === $test_options['large_legacy']['autoload'], 'Dry run must not mutate autoload state.' );

$unconfirmed = mcp_database_set_option_autoload(
	array( 'option_names' => array( 'large_legacy' ), 'autoload' => false, 'dry_run' => false )
);
assert_true( false === $unconfirmed['success'] && 0 === $unconfirmed['changed_count'], 'Live mutation must require confirmation.' );

$live = mcp_database_set_option_autoload(
	array( 'option_names' => array( 'large_legacy', 'already_off', 'missing' ), 'autoload' => false, 'dry_run' => false, 'confirm' => true )
);
assert_true( true === $live['success'] && 1 === $live['changed_count'] && 1 === $live['unchanged_count'] && 1 === $live['missing_count'], 'Live mutation must report changed, unchanged, and missing options.' );
assert_true( 'off' === $test_options['large_legacy']['autoload'] && 'preserve-me' === $test_options['large_legacy']['value'], 'Autoload mutation must preserve the option value.' );

$invalid = mcp_database_set_option_autoload(
	array( 'option_names' => array( '_transient_forbidden' ), 'autoload' => false )
);
assert_true( false === $invalid['success'] && array( '_transient_forbidden' ) === $invalid['invalid_option_names'], 'Transient option names must be rejected.' );

mcp_register_database_abilities();
$definition = $registered_abilities['database/set-option-autoload'];
assert_true( 25 === $definition['input_schema']['properties']['option_names']['maxItems'], 'Ability schema must enforce the 25-option cap.' );
assert_true( true === $definition['meta']['annotations']['destructive'] && true === $definition['permission_callback'](), 'Ability must be destructive and admin-authorized.' );

echo json_encode( array( 'success' => true, 'scenarios' => 4 ), JSON_UNESCAPED_SLASHES ) . PHP_EOL;
