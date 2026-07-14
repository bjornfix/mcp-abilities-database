import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

const root = path.resolve(path.dirname(new URL(import.meta.url).pathname), '..');
const attributes = fs.readFileSync(path.join(root, '.gitattributes'), 'utf8');

for (const required of ['/.gitattributes export-ignore', '/README.md export-ignore', '/tools export-ignore']) {
  if (!attributes.split(/\r?\n/).includes(required)) {
    throw new Error(`Missing archive rule: ${required}`);
  }
}

const temporary = fs.mkdtempSync(path.join(os.tmpdir(), 'mcp-database-archive-'));
const indexFile = path.join(temporary, 'index');
const archiveFile = path.join(temporary, 'plugin.tar');
const gitOptions = { cwd: root, encoding: 'utf8', env: { ...process.env, GIT_INDEX_FILE: indexFile } };

try {
  execFileSync('git', ['read-tree', 'HEAD'], gitOptions);
  execFileSync(
    'git',
    ['add', '-A', '--', '.gitattributes', 'README.md', 'readme.txt', 'mcp-abilities-database.php', 'tools'],
    gitOptions,
  );
  const tree = execFileSync('git', ['write-tree'], gitOptions).trim();
  execFileSync('git', ['archive', '--format=tar', `--output=${archiveFile}`, tree], gitOptions);

  const archiveEntries = execFileSync('tar', ['-tf', archiveFile], { encoding: 'utf8' })
    .trim().split(/\r?\n/).filter(Boolean);
  const executableTests = archiveEntries.filter((file) => (
    file.endsWith('.php')
    && (/(^|\/)(tests?|tools?)(\/|$)/i.test(file) || /(^|\/)(check|test)[^/]*\.php$/i.test(file))
  ));

  if (executableTests.length > 0) {
    throw new Error(`Directly web-executable test files entered the real git archive: ${executableTests.join(', ')}`);
  }

  const expected = ['mcp-abilities-database.php', 'readme.txt'];
  for (const file of expected) {
    if (!archiveEntries.includes(file)) {
      throw new Error(`Required plugin archive file missing: ${file}`);
    }
  }
  if (archiveEntries.length !== expected.length) {
    throw new Error(`Unexpected files entered the real git archive: ${archiveEntries.join(', ')}`);
  }

  console.log(JSON.stringify({ success: true, archive_files: archiveEntries.sort(), executable_tests: 0, source: 'real-git-archive' }));
} finally {
  fs.rmSync(temporary, { recursive: true, force: true });
}
