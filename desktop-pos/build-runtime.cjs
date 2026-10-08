'use strict';
const fs = require('node:fs/promises');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const crypto = require('node:crypto');

/** Package the original source from an explicit allowlist; never copy an environment or production storage. */
async function build({ source, target, dependencyRoot, phpDirectory, mariaDirectory, revision }) {
  if (!/^[a-f0-9]{40}$/.test(revision)) throw Error('A pinned source revision is required.');
  if (execFileSync('git', ['rev-parse','HEAD'], { cwd: source, encoding:'utf8' }).trim() !== revision) throw Error('Source revision does not match the checkout.');
  execFileSync('git', ['diff-index','--quiet','HEAD','--'], { cwd: source });
  // Refuse an existing destination: old caches, secrets or files must not survive a rebuilt bundle.
  try { await fs.lstat(target); throw Error('Runtime destination must be a new directory.'); }
  catch (error) { if (error.code !== 'ENOENT') throw error; }
  const application = path.join(target, 'application');
  await fs.mkdir(application, { recursive: true });
  const files = execFileSync('git', ['ls-files', '-z'], { cwd: source, encoding: 'utf8' }).split('\0').filter(Boolean);
  const roots = new Set(['app','bootstrap','config','database','desktop','public','resources','routes']);
  const hashes = {};
  for (const file of files) {
    if (!roots.has(file.split('/')[0]) && file !== 'artisan') continue;
    if (/^(?:bootstrap\/cache\/|public\/(?:storage|storage1)(?:\/|$))/.test(file)
        || /(?:^|\/)(?:\.env(?:\..*)?|firebase(?:_credentials)?\.json|service[-_]account[^/]*\.json|[^/]+\.(?:key|pem)|error_log)$/.test(file)) continue;
    const from = path.join(source, file);
    const stat = await fs.lstat(from);
    if (!stat.isFile()) continue; // Never follow storage links into server data or outside the source tree.
    const bytes = await fs.readFile(from);
    const to = path.join(application, file);
    await fs.mkdir(path.dirname(to), { recursive: true });
    await fs.writeFile(to, bytes);
    hashes[file] = crypto.createHash('sha256').update(bytes).digest('hex');
  }
  await fs.cp(path.join(dependencyRoot, 'vendor'), path.join(application, 'vendor'), { recursive: true });
  for (const name of ['composer.json','composer.lock']) await fs.copyFile(path.join(dependencyRoot, name), path.join(application, name));
  await fs.mkdir(path.join(application, 'bootstrap/cache'), { recursive: true });
  if (phpDirectory) await fs.cp(phpDirectory, path.join(target, 'php'), { recursive: true });
  if (mariaDirectory) await fs.cp(mariaDirectory, path.join(target, 'mariadb'), { recursive: true });
  await fs.writeFile(path.join(target, 'manifest.json'), JSON.stringify({ format:1, platform:'win32-x64', sourceRevision:revision, sourceHashes:hashes }, null, 2)+'\n');
  return { application, files: Object.keys(hashes).length };
}
module.exports = build;
if (require.main === module) {
  const [source,target,dependencyRoot,phpDirectory,mariaDirectory,revision] = process.argv.slice(2);
  if (!source || !target || !dependencyRoot || !revision) throw Error('Usage: build-runtime source target dependencies php mariadb revision');
  build({ source:path.resolve(source),target:path.resolve(target),dependencyRoot:path.resolve(dependencyRoot),phpDirectory:path.resolve(phpDirectory),mariaDirectory:path.resolve(mariaDirectory),revision }).then(result => process.stdout.write(JSON.stringify(result)+'\n'));
}
