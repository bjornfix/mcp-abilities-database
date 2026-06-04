<?php
/**
 * Plugin Name: MCP Abilities - Database
 * Plugin URI: https://devenia.com
 * Description: Controlled database maintenance abilities for MCP. Provides confirm-gated search/replace for WordPress post content.
 * Version: 0.1.0
 * Author: Devenia
 * Author URI: https://devenia.com
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
 * Register database abilities.
 */
function mcp_register_database_abilities(): void {
	if ( ! mcp_database_check_dependencies() ) {
		return;
	}

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
