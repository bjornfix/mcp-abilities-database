import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(path.dirname(new URL(import.meta.url).pathname), '..');
const php = fs.readFileSync(path.join(root, 'mcp-abilities-database.php'), 'utf8');
const start = php.indexOf('function mcp_database_option_autoload_metadata');
const end = php.indexOf('\nfunction mcp_database_audit_health', start);
if (start < 0 || end < 0) throw new Error('Option autoload implementation slice was not found.');
const implementation = php.slice(start, end);

for (const [pattern, description] of [
  [/'database\/set-option-autoload'/, 'autoload ability is registered'],
  [/count\( \$value \) > 25/, 'option selection is capped at 25'],
  [/! \$dry_run && ! \$confirm/, 'live mutation requires confirmation'],
  [/wp_set_option_autoload\( \$option_name, \$target_autoload \)/, 'WordPress core owns the autoload mutation'],
  [/mcp_database_option_autoload_metadata/, 'before and after metadata use one seam'],
  [/'autoload_postcondition_failed'/, 'stored postcondition is verified'],
  [/str_starts_with\( \$option_name, '_transient_' \)/, 'transient options are excluded'],
  [/'maxItems'\s*=>\s*25/, 'ability schema matches the runtime bound'],
  [/'destructive'\s*=>\s*true/, 'ability is marked destructive'],
]) {
  if (!pattern.test(php)) throw new Error(`Contract assertion failed: ${description}`);
}

for (const [pattern, description] of [
  [/SELECT\s+option_value/i, 'option values must never be selected'],
  [/update_option\s*\(/, 'generic value mutation is forbidden'],
  [/'error'\s*=>\s*\$wpdb->last_error/, 'raw database diagnostics must not be returned'],
  [/\$input\[['"](?:sql|table|table_name)['"]\]/, 'caller-supplied SQL or table names are forbidden'],
]) {
  if (pattern.test(implementation)) throw new Error(`Forbidden pattern found: ${description}`);
}

console.log(JSON.stringify({ success: true, assertions: 9, forbidden_checks: 4 }));
