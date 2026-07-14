=== MCP Abilities - Database ===
Contributors: basicus
Tags: mcp, abilities, database, maintenance
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 0.1.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Controlled database maintenance abilities for MCP.

== Description ==

This plugin exposes confirm-gated MCP abilities for database maintenance tasks.

Current abilities:

* `database/search-replace-post-content` - search/replace inside `wp_posts.post_content` for selected post types and statuses. Dry-run is the default, and live writes require `confirm=true`.
* `database/list-post-content-matches` - read-only listing of posts whose raw `wp_posts.post_content` contains a search string.
* `database/regex-replace-post-content` - regex replacement inside `wp_posts.post_content` for selected post types and statuses. Dry-run is the default, and live writes require `confirm=true`.
* `database/audit-core-table-engines` - read-only engine/status audit for a fixed allowlist of WordPress core-owned tables.
* `database/convert-core-tables-to-innodb` - dry-run-first, confirm-gated conversion of explicitly selected allowlisted core tables to InnoDB.

Table-engine abilities accept logical WordPress table keys only. Physical names are resolved from WordPress, so custom prefixes and multisite base tables work without accepting arbitrary table names or SQL. On multisite, network-global table keys require super-admin and network-options authority, including audit and dry-run requests. Conversion responses report the database statement outcome, verified postcondition, and known or unknown mutation separately.

== Changelog ==

= 0.1.2 =
* Add read-only WordPress core table-engine audit.
* Add explicit, confirm-gated InnoDB conversion with structured before/after evidence.
* Enforce multisite network authority for global tables and preserve ambiguous DDL outcomes without exposing raw database errors.

= 0.1.1 =
* Add read-only post content match listing for exact maintenance queues.

= 0.1.0 =
* Initial release with controlled post content search/replace.
* Add controlled regex replacement for scoped content maintenance.
