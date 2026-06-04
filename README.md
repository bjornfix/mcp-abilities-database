# MCP Abilities - Database

Controlled database maintenance abilities for MCP.

This plugin provides confirm-gated search and replace operations for WordPress post content. Dry-run is the default; write operations require explicit confirmation.

## Abilities

- `database/search-replace-post-content`
- `database/regex-replace-post-content`

## Requirements

- WordPress 6.9+
- PHP 8.0+
- Abilities API
- MCP Adapter

## Safety Model

The abilities are scoped to post content and selected post types/statuses. Use dry-run output first, then run confirmed writes only after reviewing the affected rows.
