'use strict';
const fs = require('node:fs/promises');
const { createReadStream } = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { spawn } = require('node:child_process');

const SCOPE = 'vendor/google/apiclient-services/src/';
function validate(value) {
  if (!value || value.format !== 1 || value.kind !== 'go-erp-google-services-phar' || value.scope !== SCOPE
      || value.archive?.file !== 'google-services.phar' || !/^[a-f0-9]{64}$/.test(value.archive.sha256 || '')
      || !Number.isSafeInteger(value.archive.bytes) || value.archive.bytes < 1 || value.archive.bytes > 4 * 1024 ** 3
      || !value.files || typeof value.files !== 'object' || Array.isArray(value.files)) throw Error('VENDOR_PHAR_INDEX_INVALID');
  const cases = new Set(), entries = Object.entries(value.files);
  if (!entries.length || entries.length > 100000) throw Error('VENDOR_PHAR_INDEX_INVALID');
  for (const [name, hash] of entries) {
    if (!/^vendor\/[a-zA-Z0-9_.@/+ -]+$/.test(name) || name.split('/').some(part => !part || part === '.' || part === '..')
        || !name.startsWith(SCOPE) || cases.has(name.toLowerCase()) || !/^[a-f0-9]{64}$/.test(hash)) throw Error('VENDOR_PHAR_INDEX_INVALID');
    cases.add(name.toLowerCase());
  }
  return value;
}
function matchesReceipt(index, receipt) {
  validate(index);
  if (receipt?.format !== 1 || !receipt.files || typeof receipt.files !== 'object') return false;
  const names = Object.keys(receipt.files).filter(name => name.startsWith('application/' + SCOPE)).sort();
  const wanted = Object.keys(index.files).sort();
  return names.length === wanted.length && names.every((name, position) => name === 'application/' + wanted[position]
    && receipt.files[name] === index.files[wanted[position]]);
}
async function build({ vendor, target, php }) {
  vendor = path.resolve(vendor); target = path.resolve(target);
  await fs.mkdir(target, { recursive: false, mode: 0o700 });
  const archive = path.join(target, 'google-services.phar');
  try {
    const output = await new Promise((resolve, reject) => {
      const child = spawn(php, ['-d', 'phar.readonly=0', '-d', 'memory_limit=1024M', '-d', 'opcache.enable_cli=0',
        path.join(__dirname, 'vendor-phar-build.php'), vendor, archive], { windowsHide: true, stdio: ['ignore', 'pipe', 'pipe'] });
      const chunks = []; let bytes = 0, stderr = '', partial = '', failure;
      const stop = code => { failure ||= Error(code); child.kill(); };
      const timer = setTimeout(() => stop('VENDOR_PHAR_BUILD_TIMEOUT'), 600000);
      child.stdout.on('data', chunk => {
        bytes += chunk.length;
        if (bytes > 32 * 1024 ** 2) return stop('VENDOR_PHAR_BUILD_OUTPUT_LIMIT');
        chunks.push(chunk);
      });
      child.stderr.on('data', chunk => {
        stderr = (stderr + chunk.toString('utf8')).slice(-4096);
        partial = (partial + chunk.toString('utf8')).slice(-8192);
        const lines = partial.split(/\r?\n/); partial = lines.pop();
        for (const line of lines) if (/^GO_ERP_VENDOR_PHAR_(?:SCAN|BUILD|VERIFY|COMPLETE) count=[0-9]{1,6}$/.test(line)) {
          process.stderr.write(line + '\n');
        }
      });
      child.once('error', () => { clearTimeout(timer); reject(Error('VENDOR_PHAR_BUILD_SPAWN_FAILED')); });
      child.once('close', code => {
        clearTimeout(timer);
        if (failure) return reject(failure);
        if (code !== 0) {
          const category = /Allowed memory size|Out of memory/i.test(stderr) ? 'MEMORY_LIMIT'
            : /No space left|disk full/i.test(stderr) ? 'NO_SPACE'
            : /VENDOR_PHAR_[A-Z_]+/.exec(stderr)?.[0] || 'PHP_FAILED';
          return reject(Error('VENDOR_PHAR_BUILD_' + category));
        }
        resolve(Buffer.concat(chunks).toString('utf8'));
      });
    });
    const index = validate(JSON.parse(output));
    const digest = crypto.createHash('sha256'); let bytes = 0;
    for await (const chunk of createReadStream(archive, { highWaterMark: 1024 * 1024 })) {
      bytes += chunk.length;
      if (bytes > index.archive.bytes) throw Error('VENDOR_PHAR_OUTPUT_CHANGED');
      digest.update(chunk);
    }
    if (bytes !== index.archive.bytes || digest.digest('hex') !== index.archive.sha256) {
      throw Error('VENDOR_PHAR_OUTPUT_CHANGED');
    }
    await fs.writeFile(path.join(target, 'vendor-phar-index.json'), JSON.stringify(index) + '\n', { flag: 'wx', mode: 0o600 });
    await fs.copyFile(path.join(__dirname, 'vendor-phar-autoload.php'), path.join(target, 'autoload.php'), fs.constants.COPYFILE_EXCL);
    return index;
  } catch (error) {
    await fs.rm(target, { recursive: true, force: true });
    throw error;
  }
}
module.exports = { build, validate, matchesReceipt, SCOPE };
if (require.main === module) {
  const [vendor, target, php] = process.argv.slice(2);
  if (!vendor || !target || !php) throw Error('Usage: build-vendor-phar vendor new-target php-executable');
  build({ vendor, target, php }).then(index => process.stdout.write(JSON.stringify({
    kind: index.kind, files: Object.keys(index.files).length, archiveBytes: index.archive.bytes, completed: true
  }) + '\n')).catch(error => { process.stderr.write(error.message + '\n'); process.exitCode = 1; });
}
