import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(path.dirname(new URL(import.meta.url).pathname), '..');
const php = fs.readFileSync(path.join(root, 'mcp-abilities-database.php'), 'utf8');
const start = php.indexOf('function mcp_database_cleanup_expired_transients');
const end = php.indexOf('\nfunction mcp_database_audit_health', start);
if (start < 0 || end < 0) throw new Error('Transient cleanup implementation slice was not found.');
const implementation = php.slice(start, end);

for (const [pattern, description] of [
  [/'database\/cleanup-expired-transients'/, 'cleanup ability is registered'],
  [/min\( 500, \(int\) \( \$input\['limit'\]/, 'cleanup batch is capped at 500'],
  [/! \$dry_run && ! \$confirm/, 'live deletion requires explicit confirmation'],
  [/delete_option\( '_transient_' \./, 'transient value uses the WordPress option API'],
  [/delete_option\( \$timeout_option_name \)/, 'timeout uses the WordPress option API'],
  [/'destructive'\s*=>\s*true/, 'ability is marked destructive'],
  [/'idempotent'\s*=>\s*true/, 'ability is marked idempotent'],
  [/current_user_can\( 'manage_options' \)/, 'ability requires administrative authority'],
  [/'more_expired_may_remain'/, 'bounded continuation state is explicit'],
]) {
  if (!pattern.test(php)) throw new Error(`Contract assertion failed: ${description}`);
}

for (const [pattern, description] of [
  [/\$input\[['"](?:option|option_name|transient|transient_name|sql|table)['"]\]/, 'caller-selected names or SQL are forbidden'],
  [/SELECT\s+option_name\s*,\s*option_value/i, 'transient values must never be selected'],
  [/'error'\s*=>\s*\$wpdb->last_error/, 'raw database diagnostics must not be returned'],
]) {
  if (pattern.test(implementation)) throw new Error(`Forbidden pattern found: ${description}`);
}

console.log(JSON.stringify({ success: true, assertions: 9, forbidden_checks: 3 }));
