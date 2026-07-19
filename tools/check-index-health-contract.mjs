import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(path.dirname(new URL(import.meta.url).pathname), '..');
const php = fs.readFileSync(path.join(root, 'mcp-abilities-database.php'), 'utf8');
const auditStart = php.indexOf('function mcp_database_expected_core_index_shapes');
const auditEnd = php.indexOf('\nfunction mcp_database_summarize_core_table_conversion', auditStart);
if (auditStart < 0 || auditEnd < 0) throw new Error('Index health implementation slice was not found.');
const auditImplementation = php.slice(auditStart, auditEnd);

const assertions = [
  [/'database\/audit-index-health'/, 'index health ability is registered'],
  [/'database\/audit-health'/, 'bounded database health snapshot is registered'],
  [/'database\/audit-options-health'/, 'options health drill-down is registered'],
  [/mcp_database_audit_index_health/, 'index health implementation exists'],
  [/information_schema\.STATISTICS/, 'index definitions come from server metadata'],
  [/information_schema\.TABLES/, 'table sizes come from server metadata'],
  [/performance_schema\.table_io_waits_summary_by_index_usage/, 'usage observations use Performance Schema'],
  [/mcp_database_expected_core_index_shapes/, 'WordPress core index shapes are checked'],
  [/mcp_database_index_columns_are_left_prefix/, 'left-prefix candidates are detected centrally'],
  [/'usage_counters_available'/, 'usage availability is explicit'],
  [/'duplicate_index_exact'/, 'exact duplicate indexes are reported'],
  [/'duplicate_index_left_prefix'/, 'left-prefix candidates are review-only findings'],
  [/'missing_primary_key'/, 'missing primary keys are reported'],
  [/'missing_core_index_shape'/, 'missing core index shapes are reported'],
  [/min\( 100, \(int\) \( \$input\['limit'\]/, 'index detail response is capped at 100 tables'],
  [/min\( 50, \(int\) \( \$input\['limit'\]/, 'options detail response is capped at 50 rows'],
  [/OCTET_LENGTH\(option_value\)/, 'options audit measures bytes without returning values'],
  [/'core_data_integrity'\s*=>\s*'not_run'/, 'snapshot explicitly reports deferred integrity coverage'],
  [/'readonly'\s*=>\s*true/, 'ability is declared read-only'],
  [/current_user_can\( 'manage_options' \)/, 'ability requires administrative authority'],
  [/mcp_database_can_audit_index_health/, 'index audit uses scope-aware authorization'],
  [/is_super_admin\(\)/, 'multisite index audit requires super-admin authority'],
  [/current_user_can\( 'manage_network_options' \)/, 'multisite index audit requires network-options authority'],
  [/mcp_database_is_current_site_table/, 'discovered tables pass through one site-scope module'],
  [/\[0-9\]\+_/, 'main-site inventory excludes sibling multisite tables'],
  [/is_array\( \$input \) \? \$input : array\(\)/, 'no-input engine audit tolerates adapter null input'],
];

for (const [pattern, description] of assertions) {
  if (!pattern.test(php)) throw new Error(`Contract assertion failed: ${description}`);
}

const forbidden = [
  [/\$input\[['"](?:sql|table|table_name|index|index_name)['"]\]/, 'caller-supplied SQL or physical identifiers are forbidden'],
  [/['"](?:DROP|CREATE|ALTER)\s+(?:TABLE|INDEX)/i, 'index audit must not contain schema-changing SQL'],
  [/'error'\s*=>\s*\$wpdb->last_error/, 'raw database errors must not be returned'],
  [/SELECT\s+option_value/i, 'option values must never be selected'],
];

for (const [pattern, description] of forbidden) {
  if (pattern.test(auditImplementation)) throw new Error(`Forbidden pattern found: ${description}`);
}

console.log(JSON.stringify({ success: true, assertions: assertions.length, forbidden_checks: forbidden.length }));
