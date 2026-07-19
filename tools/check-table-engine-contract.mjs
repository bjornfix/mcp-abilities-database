import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';

const root = path.resolve(path.dirname(new URL(import.meta.url).pathname), '..');
const php = fs.readFileSync(path.join(root, 'mcp-abilities-database.php'), 'utf8');
const readme = fs.readFileSync(path.join(root, 'readme.txt'), 'utf8');
const githubReadme = fs.readFileSync(path.join(root, 'README.md'), 'utf8');
const wpRuntime = fs.readFileSync(path.join(root, 'tools', 'check-table-engine-wp-runtime.php'), 'utf8');
const gitAttributes = fs.readFileSync(path.join(root, '.gitattributes'), 'utf8');

const assertions = [
  [/Version: 0\.1\.4/, 'plugin header version is 0.1.4'],
  [/Stable tag: 0\.1\.4/, 'WordPress readme stable tag is 0.1.4'],
  [/\*\*Stable tag:\*\* 0\.1\.4/, 'GitHub README stable tag is 0.1.4'],
  [/Author: basicus/, 'public plugin author identity is basicus'],
  [/Contributors: basicus/, 'WordPress contributor identity is basicus'],
  [/'database\/audit-core-table-engines'/, 'audit ability is registered'],
  [/'database\/convert-core-tables-to-innodb'/, 'conversion ability is registered'],
  [/'additionalProperties'\s*=>\s*false/, 'closed input and output schemas are present'],
  [/'enum'\s*=>\s*\$core_table_keys/, 'schemas expose only fixed core table keys'],
  [/mcp_database_can_manage_core_table_request/, 'engine abilities use scope-aware permission checks'],
  [/current_user_can\( 'manage_network_options' \)/, 'network-global tables require network authority'],
  [/is_super_admin\(\)/, 'network-global tables require super-admin status'],
  [/information_schema\.TABLES/, 'audit uses information_schema metadata'],
  [/\$wpdb->get_row\(\s*\$wpdb->prepare\(/, 'metadata query is prepared directly at execution'],
  [/ALTER TABLE %i ENGINE = InnoDB/, 'ALTER identifier uses WordPress identifier preparation'],
  [/WordPress\.DB\.DirectDatabaseQuery\.SchemaChange -- Intentional confirm-gated schema change for an allowlisted WordPress core table\./, 'intentional schema-change suppression is narrow and documented'],
  [/'dry_run'\]\s*\?\?\s*true/, 'conversion defaults to dry-run'],
  [/! \$dry_run && ! \$confirm/, 'live conversion requires explicit confirmation'],
  [/'partial_mutation'/, 'partial mutation truth is returned'],
  [/'statement_outcome'/, 'statement outcome is distinct from postcondition'],
  [/'postcondition'/, 'postcondition is modeled explicitly'],
  [/'mutation_outcome'/, 'known and unknown mutation are modeled explicitly'],
  [/'metadata_query_failed'/, 'metadata errors are distinguished from missing tables'],
  [/'posts'\s*=>\s*'posts'/, 'posts is explicitly allowlisted'],
  [/'registration_log'\s*=>\s*'registration_log'/, 'multisite core tables are explicitly allowlisted'],
  [/'default'\s*=>\s*array\(\)/, 'no-input audit defaults to an empty object'],
  [/wp_get_ability\( 'database\/audit-core-table-engines' \)/, 'WordPress runtime proof resolves the registered audit ability'],
  [/wp_get_ability\( 'database\/convert-core-tables-to-innodb' \)/, 'WordPress runtime proof resolves the registered conversion ability'],
  [/\$wpdb->links\s*=\s*\$fixture_table/, 'WordPress runtime proof remaps only the links property to its fixture'],
  [/register_shutdown_function\( \$cleanup \)/, 'WordPress runtime proof registers emergency fixture cleanup'],
  [/'START TRANSACTION'/, 'WordPress runtime proof exercises explicit DDL transaction truth'],
  [/'ROLLBACK'/, 'WordPress runtime proof verifies DDL survives rollback'],
  [/^\/tools export-ignore$/m, 'runtime and contract tools are excluded from release archives'],
];

for (const [pattern, description] of assertions) {
  if (!pattern.test(`${php}\n${readme}\n${githubReadme}\n${wpRuntime}\n${gitAttributes}`)) {
    throw new Error(`Contract assertion failed: ${description}`);
  }
}

const forbidden = [
  [/['"]ALTER TABLE\s*['"]?\s*\.\s*\$/, 'ALTER TABLE must not concatenate an identifier'],
  [/SHOW TABLE STATUS LIKE/, 'audit must not interpolate SHOW TABLE STATUS identifiers'],
  [/\$input\[['"]table_name['"]\]/, 'physical table-name input is forbidden'],
  [/\$input\[['"]sql['"]\]/, 'arbitrary SQL input is forbidden'],
  [/'error'\s*=>\s*\$wpdb->last_error/, 'raw wpdb diagnostics must not be returned'],
  [/\$wpdb->get_row\(\s*\$sql\s*,/, 'metadata execution must not obscure preparation behind an intermediate variable'],
  [/\$wpdb->(posts|postmeta|comments|commentmeta|terms|termmeta|term_taxonomy|term_relationships|options|users|usermeta|blogs|blogmeta|signups|site|sitemeta|registration_log)\s*=/, 'WordPress runtime proof must not remap any core property except links'],
];

const wpRuntimeForbidden = [
  [/declare\s*\(\s*strict_types\s*=\s*1\s*\)/, 'WP eval-file runtime must not declare strict_types because WP-CLI evaluates it after bootstrap statements'],
];

for (const [pattern, description] of forbidden) {
  if (pattern.test(`${php}\n${wpRuntime}`)) {
    throw new Error(`Forbidden pattern found: ${description}`);
  }
}

for (const [pattern, description] of wpRuntimeForbidden) {
  if (pattern.test(wpRuntime)) {
    throw new Error(`Forbidden WP eval-file pattern found: ${description}`);
  }
}

console.log(JSON.stringify({ success: true, assertions: assertions.length, forbidden_checks: forbidden.length + wpRuntimeForbidden.length }));
