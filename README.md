# MCP Abilities - Database

Bounded database health diagnostics and controlled maintenance abilities for WordPress through MCP.

[![GitHub release](https://img.shields.io/github/v/release/bjornfix/mcp-abilities-database)](https://github.com/bjornfix/mcp-abilities-database/releases)
[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0)
[![WordPress](https://img.shields.io/badge/WordPress-6.9%2B-blue.svg)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-purple.svg)](https://php.net)

**Tested up to:** 7.0
**Stable tag:** 0.1.3
**License:** GPLv2 or later
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html

## What It Does

Inspect storage engines, index health, table size, autoloaded options, and expired transients through bounded read-only abilities. The plugin also provides tightly scoped, confirm-gated maintenance tools for post content and allowlisted WordPress core tables.

This plugin is part of the Devenia MCP abilities ecosystem. It gives an MCP-capable agent a focused, authenticated way to inspect database health and perform explicitly approved maintenance inside WordPress.

**Example:** "Check this WordPress database, especially its indexes." The agent can return a bounded health snapshot, identify review candidates, and keep diagnostic work separate from any later mutation approval.

## The Real Workflow

In practice, the human should not have to memorize every ability name.

The normal pattern is:

1. install the base MCP stack
2. install only the add-ons the site actually needs
3. let the agent discover the available abilities
4. give the agent a clear task with boundaries
5. verify the result in WordPress

The human's job is mostly to describe the goal.
The agent's job is to figure out the mechanics.

## Why This Feels Different

Most WordPress automation still leaves the repetitive part to the human.

This plugin is different because the agent can act inside the site through a narrow, authenticated ability surface:

- inspect current site state before changing anything
- run the specific action needed for the task
- return structured results that are easy to verify
- keep the workflow inside WordPress instead of a separate checklist

That changes the experience from:

- `Here is what you should do in wp-admin`

to:

- `Tell the agent what needs doing, and let it carry out the work`

## Before vs After

### Before

- ask the AI what to do
- copy the answer into WordPress by hand
- click through wp-admin for the repetitive bits
- postpone maintenance because the task is tedious

### After

- tell the agent what needs doing
- let it inspect the relevant WordPress state
- let it run the targeted ability
- verify the result and move on

## Who It Is For

This is a good fit for:

- agencies managing WordPress sites with AI-assisted maintenance
- operators who want agents to do real WordPress work instead of producing instructions
- teams already using MCP Expose Abilities
- sites where this WordPress area is updated often enough to deserve automation

It is especially useful when the manual version is repetitive enough that important maintenance gets delayed.

## Documentation

Start with the main plugin page and base stack documentation:

- [MCP Expose Abilities](https://devenia.com/plugins/mcp-expose-abilities/)
- [MCP Abilities – Database](https://devenia.com/plugins/mcp-abilities-database/)
- [Getting Started](https://github.com/bjornfix/mcp-expose-abilities/wiki/Getting-Started)
- [Install Order and Dependencies](https://github.com/bjornfix/mcp-expose-abilities/wiki/Install-Order-and-Dependencies)

If you are using an AI agent, the simplest instruction is often just:

- `Read https://github.com/bjornfix/mcp-expose-abilities and figure out the stack before making changes.`

## Start Here

If you are new to the stack, use this order:

1. Install **Abilities API**.
2. Install **MCP Adapter**.
3. Install **MCP Expose Abilities**.
4. Install **MCP Abilities - Database**.
5. Confirm the new abilities appear in discovery.
6. Give the agent a clear task that uses this add-on.

If you skip base-stack verification and start with add-ons immediately, troubleshooting gets harder than it needs to be.

## Abilities

- `database/search-replace-post-content`
- `database/list-post-content-matches`
- `database/regex-replace-post-content`
- `database/audit-core-table-engines`
- `database/convert-core-tables-to-innodb`
- `database/audit-health`
- `database/audit-index-health`
- `database/audit-options-health`

## Safety Model

Post-content abilities are scoped to selected post types/statuses. Table-engine abilities accept only fixed logical WordPress core table keys, resolve physical table names through WordPress, and never accept arbitrary SQL or table identifiers. The database-health module owns current-site table inventory, multisite scope, authorization, bounded pagination, and metadata interpretation behind one interface. Index audits never accept physical table or index names; options audits return names and byte counts only, never values. On multisite, schema discovery requires super-admin plus network-options authority because physical prefixes can overlap. Engine conversion defaults to dry-run, requires an explicit table list, and requires both `dry_run=false` and `confirm=true` for live DDL. Each result separates the reported database statement outcome, verified postcondition, and known or unknown mutation; aggregate partial state is nullable when the database effect cannot be proven.

## Changelog

### Current

- 0.1.3: Add the bounded database-health snapshot, paginated index health, options/autoload health, multisite-safe inventory, and null-input compatibility.
- 0.1.2: Add allowlisted core table-engine audit and confirm-gated InnoDB conversion.
- 0.1.1: Add read-only post content match listing for exact maintenance queues.
- Documentation aligned with the public plugin README standard.

## Contributing

PRs welcome. Keep changes focused on the plugin's WordPress ability surface and preserve authenticated, explicit workflows.

## License

GPL-2.0+

## Author

[basicus](https://profiles.wordpress.org/basicus/)

## Links

- [Plugin Page](https://devenia.com/plugins/mcp-abilities-database/)
- [MCP Expose Abilities](https://devenia.com/plugins/mcp-expose-abilities/)
- [Download](https://downloads.devenia.com/mcp-abilities-database.zip)
- [GitHub Releases](https://github.com/bjornfix/mcp-abilities-database/releases)

## Star and Share

If this plugin saves you time or makes WordPress maintenance easier to verify, please:

- star the repo
- share it with people running WordPress sites
- point them to the main plugin page so they can see what the ecosystem can actually do

Why do it?

Because agent-friendly open WordPress tooling helps more of the boring but important work get done.
