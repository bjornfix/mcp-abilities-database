=== MCP Abilities - Database ===
Contributors: devenia
Tags: mcp, abilities, database, maintenance
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 0.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Controlled database maintenance abilities for MCP.

== Description ==

This plugin exposes confirm-gated MCP abilities for database maintenance tasks.

Current abilities:

* `database/search-replace-post-content` - search/replace inside `wp_posts.post_content` for selected post types and statuses. Dry-run is the default, and live writes require `confirm=true`.
* `database/list-post-content-matches` - read-only listing of posts whose raw `wp_posts.post_content` contains a search string.
* `database/regex-replace-post-content` - regex replacement inside `wp_posts.post_content` for selected post types and statuses. Dry-run is the default, and live writes require `confirm=true`.

== Changelog ==

= 0.1.1 =
* Add read-only post content match listing for exact maintenance queues.

= 0.1.0 =
* Initial release with controlled post content search/replace.
* Add controlled regex replacement for scoped content maintenance.
