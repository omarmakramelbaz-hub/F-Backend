'use strict';
const test = require('node:test'), assert = require('node:assert/strict');
const fs = require('node:fs/promises'), path = require('node:path'), os = require('node:os'), vm = require('node:vm');
const { createRequire } = require('node:module');
const filename = path.join(__dirname, '../src/local-runtime.cjs');
async function subject() {
  const context = vm.createContext({ module: { exports: {} }, require: createRequire(filename), Buffer, process, URL, Error, JSON,
    AbortSignal, fetch, setTimeout, clearTimeout });
  vm.runInContext(await fs.readFile(filename, 'utf8') + '\nmodule.exports={phpIniFor};', context, { filename });
  return context.module.exports.phpIniFor;
}
async function fixture(t, configured = false) {
  const root = await fs.mkdtemp(path.join(await fs.realpath(os.tmpdir()), 'runtime-opcode-'));
  t.after(() => fs.rm(root, { recursive: true, force: true }));
  const bundle = path.join(root, 'retained'), profile = path.join(root, 'profile');
  for (const name of ['application', 'php/ext', 'php/ssl']) await fs.mkdir(path.join(bundle, name), { recursive: true });
  await fs.mkdir(profile);
  const ini = (configured ? 'zend_extension=C:/builder/ext/php_opcache.dll\n' : '')
    + 'opcache.enable_cli=0\nopcache.validate_timestamps=0\nopcache.file_cache=C:/untrusted-old-cache\n';
  await fs.writeFile(path.join(bundle, 'php/php.ini'), ini);
  await fs.writeFile(path.join(bundle, 'php/ext/php_opcache.dll'), 'synthetic-verified-opcode-extension');
  await fs.writeFile(path.join(bundle, 'php/ssl/cacert.pem'), 'synthetic-public-certificate');
  return { root, bundle, profile };
}

test('retained ini enables only the verified bundled opcode DLL with fresh validation and no persistent bytecode cache', async t => {
  const build = await subject();
  for (const configured of [false, true]) {
    const f = await fixture(t, configured), target = await build(f.bundle, { sourceRevision: 'a'.repeat(40) }, f.profile);
    const ini = await fs.readFile(target, 'utf8');
    assert.equal((ini.match(/^zend_extension=/gm) || []).length, 1);
    assert.match(ini, /^zend_extension="\.\.\/php\/ext\/php_opcache\.dll"$/m);
    const effective = Object.fromEntries(ini.split(/\r?\n/).filter(line => line.includes('=')).map(line => line.split(/=(.*)/s).slice(0, 2)));
    assert.deepEqual(Object.fromEntries(['opcache.enable', 'opcache.enable_cli', 'opcache.validate_timestamps', 'opcache.revalidate_freq',
      'opcache.revalidate_path', 'opcache.use_cwd', 'opcache.enable_file_override', 'opcache.file_cache', 'opcache.file_cache_only',
      'opcache.jit', 'opcache.jit_buffer_size', 'memory_limit'].map(key => [key, effective[key]])), {
      'opcache.enable': '1', 'opcache.enable_cli': '1', 'opcache.validate_timestamps': '1', 'opcache.revalidate_freq': '0',
      'opcache.revalidate_path': '1', 'opcache.use_cwd': '1', 'opcache.enable_file_override': '0', 'opcache.file_cache': '',
      'opcache.file_cache_only': '0', 'opcache.jit': '0', 'opcache.jit_buffer_size': '0', memory_limit: '256M' });
    assert.equal(await fs.readFile(path.join(f.bundle, 'php/ext/php_opcache.dll'), 'utf8'), 'synthetic-verified-opcode-extension');
  }
});

test('old retained bundles without an opcode DLL are not rewritten or redirected to a global extension', async t => {
  const f = await fixture(t), build = await subject();
  await fs.unlink(path.join(f.bundle, 'php/ext/php_opcache.dll'));
  const ini = await fs.readFile(await build(f.bundle, { sourceRevision: 'b'.repeat(40) }, f.profile), 'utf8');
  assert.doesNotMatch(ini, /^zend_extension=/m);
  assert.match(ini, /^opcache.jit=0$/m);
});
