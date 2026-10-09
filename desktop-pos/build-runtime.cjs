'use strict';
const fs = require('node:fs/promises');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const crypto = require('node:crypto');
const sourcePolicy = require('./runtime-source-policy.cjs');
const sourceCode = require('./src/dashboard-source.cjs');

/** Package the original source from an explicit allowlist; never copy an environment or production storage. */
async function build({ source, target, dependencyRoot, phpDirectory, mariaDirectory, revision }) {
  if (!/^[a-f0-9]{40}$/.test(revision)) throw Error('A pinned source revision is required.');
  if (execFileSync('git', ['rev-parse','HEAD'], { cwd: source, encoding:'utf8' }).trim() !== revision) throw Error('Source revision does not match the checkout.');
  execFileSync('git', ['diff-index','--quiet','HEAD','--'], { cwd: source });
  const layoutRoot = path.join(source, 'public/dashboard/vendor/desktop-external');
  const layout = JSON.parse(await fs.readFile(path.join(layoutRoot, 'manifest.json'), 'utf8'));
  if (layout.format !== 1 || layout.complete !== true || !layout.assets || !layout.licenses || !layout.distributions) throw Error('The original offline layout assets are incomplete.');
  for (const item of [...Object.values(layout.assets), ...Object.values(layout.licenses)]) {
    if (!/^[a-zA-Z0-9_./@-]+$/.test(item.path || '') || /(?:^|\/)\.{1,2}(?:\/|$)/.test(item.path)) throw Error('Unsafe layout asset path.');
    const bytes = await fs.readFile(path.join(layoutRoot, item.path));
    if (crypto.createHash('sha256').update(bytes).digest('hex') !== item.sha256 || (item.bytes !== undefined && item.bytes !== bytes.length)) throw Error('A bundled original layout asset failed verification.');
  }
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
    if (sourcePolicy.excludedPath(file)) continue;
    const from = path.join(source, file);
    const stat = await fs.lstat(from);
    if (!stat.isFile()) continue; // Never follow storage links into server data or outside the source tree.
    const bytes = await fs.readFile(from);
    if (sourcePolicy.credentialJson(file, bytes)) continue;
    const to = path.join(application, file);
    await fs.mkdir(path.dirname(to), { recursive: true });
    await fs.writeFile(to, bytes);
    hashes[file] = crypto.createHash('sha256').update(bytes).digest('hex');
  }
  await fs.cp(path.join(dependencyRoot, 'vendor'), path.join(application, 'vendor'), { recursive: true });
  for (const name of ['composer.json','composer.lock']) await fs.copyFile(path.join(dependencyRoot, name), path.join(application, name));
  await fs.mkdir(path.join(application, 'bootstrap/cache'), { recursive: true });
  if (phpDirectory) {
    const phpRoot = await fs.realpath(phpDirectory);
    const certificates = await fs.readFile(path.join(phpRoot, 'ssl', 'cacert.pem'), 'utf8');
    const pemCertificates = certificates.match(/-----BEGIN CERTIFICATE-----[\s\S]*?-----END CERTIFICATE-----/g) || [];
    if (!pemCertificates.length || /PRIVATE KEY/.test(certificates)) throw Error('The PHP public CA bundle is missing or contains private key material.');
    for (const pem of pemCertificates) new crypto.X509Certificate(pem);
    // CI setup directories also contain shell-tool aliases such as printf.exe. Package
    // the actual PHP runtime and its extensions, never links into the build host.
    const allowed = async file => {
      const relative = path.relative(phpRoot, file);
      if (!relative) return true;
      const components = relative.split(path.sep), first = components[0].toLowerCase();
      if (first === 'ssl' && components.length > 1 && relative.replaceAll(path.sep, '/') !== 'ssl/cacert.pem') return false;
      if (components.length === 1 && !['php.exe','php.ini','ext','extras','ssl'].includes(first)
          && !/\.(?:dll|pem|crt|txt|md)$/i.test(first)) return false;
      const resolved = await fs.realpath(file), within = path.relative(phpRoot, resolved);
      if (within.startsWith('..' + path.sep) || path.isAbsolute(within)) throw Error('PHP runtime link points outside its source directory: ' + relative);
      return true;
    };
    await fs.cp(phpRoot, path.join(target, 'php'), { recursive: true, dereference: true, filter: allowed });
  }
  if (mariaDirectory) await fs.cp(mariaDirectory, path.join(target, 'mariadb'), { recursive: true });
  const sourceFingerprint = await sourceCode.fingerprint(application);
  await fs.writeFile(path.join(target, 'manifest.json'), JSON.stringify({ format:1, platform:'win32-x64', sourceRevision:revision, sourceHashes:hashes, sourceFingerprint }, null, 2)+'\n');
  return { application, files: Object.keys(hashes).length };
}
module.exports = build;
if (require.main === module) {
  const [source,target,dependencyRoot,phpDirectory,mariaDirectory,revision] = process.argv.slice(2);
  if (!source || !target || !dependencyRoot || !revision) throw Error('Usage: build-runtime source target dependencies php mariadb revision');
  build({ source:path.resolve(source),target:path.resolve(target),dependencyRoot:path.resolve(dependencyRoot),phpDirectory:path.resolve(phpDirectory),mariaDirectory:path.resolve(mariaDirectory),revision }).then(result => process.stdout.write(JSON.stringify(result)+'\n'));
}
