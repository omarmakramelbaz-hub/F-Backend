'use strict';
const fs = require('node:fs/promises');
const path = require('node:path');
const crypto = require('node:crypto');
const { isDeepStrictEqual } = require('node:util');
const net = require('node:net');
const { spawn } = require('node:child_process');
const { GenerationStore, prepared, generation } = require('./dashboard-generation.cjs');
const media = require('./dashboard-media.cjs');
const RuntimeArchive = require('./runtime-archive.cjs');
const sourceCode = require('./dashboard-source.cjs');
const readSnapshot = require('./dashboard-download.cjs');

// Only these reviewed literal abort messages may accompany import locations.
// The PHP producer independently checks the same file/status/message mapping.
const importMessages = {
  'DesktopDashboardImport.php': {
    409: [
      'نسخة برنامج الجهاز لا تطابق بيانات السيرفر؛ النسخة الحالية وسجلاتها محفوظة.',
      'قاعدة التجهيز ليست فارغة؛ بيانات الجهاز محفوظة.',
      'جدول مرتبط غير موجود في نسخة الحساب.',
      'نسخة الحساب تحتوي على مرجع ناقص؛ لم تُفعّل قاعدة التجهيز.'
    ],
    422: ['مخطط قاعدة التجهيز غير مقبول.', 'بيانات التجهيز غير مكتملة.', 'بصمة مخطط التجهيز مختلفة.',
      'وصف تغطية نطاقات الكتالوج غير صالح.', 'بيانات نطاقات الكتالوج أو فروع الجهاز غير متطابقة.',
      'فروع نسخة التجهيز أو نطاقاتها غير متطابقة.', 'بيانات حساب مستخدم الجهاز مفقودة أو مكررة.']
  }
};

function importPhpProfile(item, scope, sapis) {
  return item && item.capture_scope === scope
    && sapis.includes(item.php_sapi) && typeof item.php_version === 'string'
    && /^[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}[-A-Za-z0-9.]{0,24}(?![\s\S])/.test(item.php_version)
    && ['serialize_precision', 'precision'].every(key => typeof item[key] === 'string' && /^(?:-1|0|[1-9][0-9]{0,3})(?![\s\S])/.test(item[key]))
      ? { capture_scope: scope, php_sapi: item.php_sapi, php_version: item.php_version,
        serialize_precision: item.serialize_precision, precision: item.precision } : null;
}

function importPhpComparison(value) {
  const server = importPhpProfile(value?.server, 'snapshot_export', ['fpm-fcgi', 'cgi-fcgi', 'apache2handler', 'litespeed', 'cli']);
  const desktop = importPhpProfile(value?.desktop, 'desktop_import', ['cli']);
  return server && desktop ? { server, desktop, server_web_request: server.php_sapi !== 'cli',
    same_serialize_precision: server.serialize_precision === desktop.serialize_precision } : null;
}

function importPhpProfiles(value) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) return null;
  const server = importPhpProfile(value.server, 'snapshot_export', ['fpm-fcgi', 'cgi-fcgi', 'apache2handler', 'litespeed', 'cli']);
  const desktop = importPhpProfile(value.desktop, 'desktop_import', ['cli']);
  // Recompute validity; only the fixed missing/invalid distinction is retained
  // when PHP deliberately omitted rejected server values.
  const serverStatus = server ? 'valid' : !Object.hasOwn(value, 'server') && value.server_profile_status === 'missing' ? 'missing' : 'invalid';
  return { server_profile_status: serverStatus, desktop_profile_status: desktop ? 'valid' : 'invalid',
    ...(server ? { server } : {}), ...(desktop ? { desktop } : {}) };
}

function importPhpReason(comparison, profiles) {
  const description = item => item.php_sapi + '/' + item.php_version
    + ' serialize_precision=' + item.serialize_precision + ' precision=' + item.precision;
  if (profiles) {
    const server = profiles.server ? (profiles.server.php_sapi !== 'cli' ? 'الموقع=' : 'مصدر البيانات=') + description(profiles.server)
      : profiles.server_profile_status === 'missing' ? 'المصدر=لم تصل إعدادات PHP مع بيانات التجهيز.' : 'المصدر=إعدادات PHP غير صالحة للتشخيص.';
    return ' PHP: ' + server + '؛ الجهاز=' + (profiles.desktop ? description(profiles.desktop) : 'إعدادات PHP غير صالحة للتشخيص.');
  }
  if (!comparison) return '';
  return ' PHP: ' + (comparison.server_web_request ? 'الموقع=' : 'مصدر البيانات=')
    + description(comparison.server) + '؛ الجهاز=' + description(comparison.desktop);
}

const pause = ms => new Promise(resolve => setTimeout(resolve, ms));
async function freePort() {
  const server = net.createServer();
  await new Promise((resolve, reject) => { server.once('error', reject); server.listen(0, '127.0.0.1', resolve); });
  const port = server.address().port;
  await new Promise(resolve => server.close(resolve));
  return port;
}
function startChild(file, args, options, capture = false) {
  const child = spawn(file, args, { ...options, windowsHide: true, shell: false, stdio: ['pipe', capture ? 'pipe' : 'ignore', 'pipe'] });
  let tail = '';
  child.stderr.on('data', bytes => { tail = (tail + bytes.toString()).slice(-8000); });
  child.stderrTail = () => tail;
  child.failure = new Promise(resolve => {
    child.once('error', error => resolve(error));
    child.once('exit', (code, signal) => resolve(Error(`Local service stopped (${code ?? signal}). ${tail}`)));
  });
  child.completed = new Promise(resolve => { child.once('error', resolve); child.once('close', resolve); });
  return child;
}
// A short, data-free reason shown with the failure so a support report names the real step and cause.
function failureReason(args, cause, text) {
  const scripts = ['database.php', 'import.php', 'verify.php', 'verify-media.php', 'archive.php', 'delta.php'];
  const script = args.filter(value => typeof value === 'string').map(value => value.split(/[\\/]/).pop()).reverse()
    .find(value => scripts.includes(value));
  const step = script ? ' [' + script + ']' : '';
  if (/Allowed memory size of \d+ bytes exhausted|(?:PHP )?Fatal error:\s*Out of memory/i.test(text))
    return step + ' السبب: الذاكرة المتاحة للخطوة غير كافية.';
  if (cause.timedOut) return step + ' السبب: الخطوة أخذت وقتًا أطول من المسموح.';
  if (cause.overflow) return step + ' السبب: ناتج الخطوة أكبر من المسموح.';
  if (cause.import) return step + ' السبب: ' + cause.import.phase + ' ' + cause.import.type + ' ' + cause.import.file + ':' + cause.import.line
    + (cause.import.status ? ' (' + cause.import.status + ')' : '')
    + (cause.import.message ? ': ' + cause.import.message : '')
    + importPhpReason(cause.import.php_comparison, cause.import.php_profiles);
  if (/(?:PHP )?(?:Fatal|Parse) error:/i.test(text)) return step + ' السبب: توقفت خطوة PHP بسبب خطأ داخلي.';
  return step + ' (رمز الخروج ' + cause.exitCode + ')';
}
async function run(file, args, options, input = '', timeout = 90000, outputLimit = 20 * 1024 * 1024) {
  const child = startChild(file, args, options, true);
  let output = ''; let outputBytes = 0; let overflow = false;
  child.stdout.setEncoding('utf8');
  child.stdout.on('data', bytes => {
    if(overflow)return;
    outputBytes+=Buffer.byteLength(bytes);
    if(outputBytes>outputLimit){overflow=true;child.kill();return;}
    output+=bytes;
  });
  child.stdin.on('error', () => {});
  child.stdin.end(input);
  let timer;
  try {
    const result = await Promise.race([
      child.completed,
      new Promise(resolve => { timer = setTimeout(() => { child.kill(); resolve(Error('Local service initialization timed out.')); }, timeout); })
    ]);
    if (child.exitCode !== 0 || overflow) {
      await stopChild(child);
      const cause = { exitCode: child.exitCode, overflow, timedOut: result instanceof Error && /timed out/.test(result.message) };
      const report = child.stderrTail().split(/\r?\n/).find(line => line.startsWith('DESKTOP_IMPORT_DIAGNOSTIC '));
      if (report) {
        try {
          const value = JSON.parse(report.slice('DESKTOP_IMPORT_DIAGNOSTIC '.length));
          if (['input', 'json', 'bootstrap', 'import'].includes(value.phase)
              && /^[a-zA-Z0-9_\\\\]{1,160}$/.test(value.type) && /^[a-zA-Z0-9_.-]{1,120}$/.test(value.file)
              && Number.isSafeInteger(value.line) && value.line > 0
              && (value.status === null || Number.isInteger(value.status) && value.status >= 400 && value.status <= 599)) {
            cause.import = { phase: value.phase, type: value.type, file: value.file, line: value.line, status: value.status };
            if (importMessages[value.file]?.[value.status]?.includes(value.message)) cause.import.message = value.message;
            const profiles = importPhpProfiles(value.php_profiles);
            if (profiles) cause.import.php_profiles = profiles;
            const comparison = Object.hasOwn(value, 'php_profiles') ? profiles && importPhpComparison(profiles) : importPhpComparison(value.php_comparison);
            if (comparison) cause.import.php_comparison = comparison;
          }
        } catch {}
      }
      throw Error('تعذر تنفيذ خطوة تجهيز الخدمة المحلية؛ بيانات الجهاز محفوظة.'
        + failureReason(args, cause, child.stderrTail() + '\n' + output.slice(-8000)), { cause });
    }
    return output;
  } finally { clearTimeout(timer); }
}
async function stopChild(child) {
  if (!child) return;
  if (child.exitCode === null && child.signalCode === null) child.kill();
  let timer;
  // exit can precede close while redirected pipes still keep the Electron
  // process alive. Wait for the child handle and its stdio to finish too.
  await Promise.race([child.completed, new Promise(resolve => { timer = setTimeout(() => {
    if (child.exitCode === null && child.signalCode === null) child.kill('SIGKILL');
    child.stdin?.destroy(); child.stdout?.destroy(); child.stderr?.destroy(); resolve();
  }, 8000); })]);
  clearTimeout(timer);
}

function phpRelativePath(application, file) {
  const relative = path.relative(application, file);
  // PHP parses startup options before converting Windows argv to UTF-8, and
  // its native extension loader calls LoadLibraryA. Keep those paths ASCII;
  // Windows resolves them against the exact retained application's cwd.
  if (!relative || path.isAbsolute(relative) || /[^\x20-\x7e]/.test(relative))
    throw Error('مسار إعدادات التشغيل المحلية غير متوافق؛ بيانات الجهاز محفوظة.');
  return relative.replaceAll('\\', '/');
}

async function phpIniFor(bundle,manifest,profile) {
    // The installed directory can disappear after an update. Resolve PHP extensions inside
    // the retained version, rather than any absolute paths copied from the build host.
    const application = path.join(bundle, 'application');
    const extensionDirectory = path.join(bundle, 'php', 'ext');
    let phpConfiguration = await fs.readFile(path.join(bundle, 'php', 'php.ini'), 'utf8');
    const extensionLines = [...phpConfiguration.matchAll(/^\s*(zend_extension|extension)\s*=\s*([^;\r\n]+).*$/gm)];
    for (const match of extensionLines) {
      const value = match[2].trim().replace(/^['"]|['"]$/g, '');
      if (/[\\/]/.test(value)) {
        const basename = value.split(/[\\/]/).pop();await fs.access(path.join(extensionDirectory, basename));
        phpConfiguration = phpConfiguration.replace(match[0], match[1] + '="' + phpRelativePath(application, path.join(extensionDirectory, basename)) + '"');
      }
    }
    const caFile = path.join(bundle, 'php', 'ssl', 'cacert.pem');
    await fs.access(caFile);
    const caPath = caFile.replaceAll('\\', '/');
    const phpIni = path.join(profile, 'php-' + manifest.sourceRevision + '.ini');
    // Keep the original expense form's upload limit in both active and staged runtimes.
    // Its encrypted base64 journal also requires room beyond PHP's build-host defaults.
    await fs.writeFile(phpIni, phpConfiguration + '\nextension_dir="' + phpRelativePath(application, extensionDirectory)
      // Do not inherit the builder's 256 MiB JIT buffer and native compilation
      // settings in a retained Windows runtime; validate ordinary PHP execution.
      + '"\ncurl.cainfo="' + caPath + '"\nopenssl.cafile="' + caPath
      + '"\nupload_max_filesize=5M\npost_max_size=12M\nmemory_limit=256M\nopcache.jit=0\nopcache.jit_buffer_size=0\n', { mode: 0o600 });
    return phpIni;
}

/** Manages private PHP/MariaDB processes. It never contacts the production database. */
class LocalRuntime {
  static phpArguments(context) {
    return ['-c', phpRelativePath(context.application, context.phpIni)];
  }
  static deltaInput(capture,delta,token) {
    const input='{"action":"reconstruct","capture_database":'+JSON.stringify(capture.capture_database)
      +',"capture_token":'+JSON.stringify(token)+',"capture":'+JSON.stringify(capture)+',"delta":'+readSnapshot.input(delta)+'}';
    if(Buffer.byteLength(input)>384*1024*1024)throw Error('بيانات تحديث الخلفية أكبر من الحد المسموح؛ العمل المحلي مستمر.');
    return input;
  }
  constructor({ bundle, profile, safeStorage, downloadMedia, authorizeInstalled = async () => false, platform = process.platform, onFailure = () => {} }) {
    this.installedBundle = bundle; this.bundle = bundle; this.stagedContexts = new Map(); this.profile = path.join(profile, 'dashboard');
    this.safeStorage = safeStorage; this.platform = platform; this.onFailure = onFailure;
    this.children = []; this.stopping = false; this.token = crypto.randomBytes(32).toString('hex');
    this.controlToken = crypto.randomBytes(32).toString('hex');
    this.metadata = new GenerationStore(this.profile, safeStorage);
    this.downloadMedia = downloadMedia;
    this.authorizeInstalled = authorizeInstalled;
  }
  async settings() {
    await fs.mkdir(this.profile, { recursive: true, mode: 0o700 });
    const file = path.join(this.profile, 'credentials.enc');
    try { return JSON.parse(this.safeStorage.decryptString(await fs.readFile(file))); }
    catch (error) {
      if (error.code !== 'ENOENT') throw Error('تعذر فتح بيانات الداشبورد المحلية؛ احتفظ بملفات الجهاز لاسترجاعها.');
      if (!this.safeStorage.isEncryptionAvailable()) throw Error('تعذر حماية بيانات الداشبورد في حساب ويندوز الحالي.');
      const value = { deviceId: crypto.randomUUID(), rootPassword: crypto.randomBytes(32).toString('hex'), appPassword: crypto.randomBytes(32).toString('hex'), appKey: 'base64:' + crypto.randomBytes(32).toString('base64') };
      await fs.writeFile(file, this.safeStorage.encryptString(JSON.stringify(value)), { flag: 'wx', mode: 0o600 });
      return value;
    }
  }
  async isPrepared() {
    try {
      const value = await this.metadata.read('prepared');
      if (!value) return false;
      const settings = await this.settings();
      return prepared(value, settings.deviceId, await this.preparedManifest(value));
    } catch (error) { if (error.code === 'ENOENT') return false; throw error; }
  }
  async preparedManifest(value) {
    if (value?.previewOnly !== true && value?.fullCoverage !== false) return null;
    if (this.manifest?.sourceRevision === value.sourceRevision) return this.manifest;
    const archive = new RuntimeArchive(this.profile, this.metadata);
    const installed = await archive.manifest(this.installedBundle);
    if (installed.sourceRevision === value.sourceRevision) return installed;
    if (!/^[a-f0-9]{40}$/.test(value.sourceRevision || '')) return null;
    return archive.manifest(path.join(this.profile, 'runtimes', value.sourceRevision));
  }
  async coverageManifest(snapshot) {
    if (this.manifest && sourceCode.same(snapshot.source, this.manifest.sourceFingerprint))
      return await this.authorizedSameSourceInstalled(snapshot) || this.manifest;
    const manifest = await new RuntimeArchive(this.profile, this.metadata).manifest(this.installedBundle);
    if (!sourceCode.same(snapshot.source, manifest.sourceFingerprint))
      throw Error('نسخة البرنامج لا تطابق بيانات السيرفر؛ بيانات الجهاز وسجلاته محفوظة.');
    return manifest;
  }
  async authorizedSameSourceInstalled(snapshot) {
    // GUI-only releases retain the legacy business-data fingerprint. A different
    // installed revision may be selected only after fresh native server authority;
    // a manually downgraded or offline installer never displaces the old runtime.
    if (!this.manifest || !this.installedBundle || !sourceCode.same(snapshot.source, this.manifest.sourceFingerprint)) return null;
    try {
      const installed = await new RuntimeArchive(this.profile, this.metadata).manifest(this.installedBundle);
      if (installed.sourceRevision === this.manifest.sourceRevision || !sourceCode.same(snapshot.source, installed.sourceFingerprint)
          || typeof this.authorizeInstalled !== 'function' || await this.authorizeInstalled(installed) !== true) return null;
      return installed;
    } catch { return null; }
  }
  async connection() {
    if (!(await this.isPrepared())) throw Error('بيانات الداشبورد المحلية لم تُجهّز بالكامل.');
    return this.metadata.read('prepared');
  }
  supportsPrintEffects() {
    // A new executable may still be serving its retained pre-upgrade runtime.
    // Negotiate from the already verified active source manifest, never by
    // catching an arbitrary control failure and treating it as permission.
    return /^[a-f0-9]{64}$/.test(this.manifest?.sourceHashes?.['app/Services/Dashboard/DesktopDashboardPrintEffects.php']||'');
  }
  async control(value) {
    if (!this.origin || this.stopping) throw Error('خدمة الداشبورد المحلية غير متاحة.');
    const response = await fetch(this.origin + '/_desktop/control', { method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Fasakhansta-Desktop': this.token, 'X-Fasakhansta-Control': this.controlToken },
      body: JSON.stringify(value), redirect: 'error', signal: AbortSignal.timeout(25000) });
    const data = await response.json();
    if (!response.ok) { const error = Error(data.message || 'تعذر قراءة سجل المزامنة.'); error.status = response.status; throw error; }
    return data;
  }
  async start() {
    if (this.starting) return this.starting;
    this.starting = this.boot().then(value => { this.started = true; return value; }).catch(async error => { await this.stop(); throw error; });
    return this.starting;
  }
  cancelStartup() { if (!this.started) this.stopping = true; }
  assertStarting() { if (this.stopping) throw Error('البرنامج يُغلق الآن.'); }
  async boot() {
    this.assertStarting();
    if (this.platform !== 'win32') throw Error('حزمة التشغيل المحلية مخصصة لويندوز.');
    const settings = await this.settings();
    const active = await this.metadata.read('prepared');
    if (active && !prepared(active, settings.deviceId, await this.preparedManifest(active))) throw Error('بيانات تجهيز الداشبورد غير صالحة؛ سجلات الجهاز محفوظة.');
    const archive = new RuntimeArchive(this.profile, this.metadata, { stopped: () => this.stopping });
    this.bundle = await archive.select(this.bundle, active);
    this.assertStarting();
    const manifest = JSON.parse(await fs.readFile(path.join(this.bundle, 'manifest.json'), 'utf8'));
    if (manifest.format !== 1 || manifest.platform !== 'win32-x64') throw Error('حزمة الداشبورد المحلية غير متوافقة.');
    const php = path.join(this.bundle, 'php', 'php.exe');
    const maria = path.join(this.bundle, 'mariadb', 'bin');
    const application = path.join(this.bundle, 'application');
    for (const file of [php, path.join(maria, 'mariadbd.exe'), path.join(maria, 'mariadb-install-db.exe'), path.join(application, 'vendor', 'autoload.php')]) await fs.access(file);
    if (active?.format === 2 && active.sourceRevision !== manifest.sourceRevision) throw Error('نسخة التشغيل تختلف عن نسخة بيانات الجهاز؛ يلزم استرجاع التحديث قبل العمل.');
    this.sourceFingerprint = await archive.sourceFingerprint(this.bundle);
    if ((manifest.sourceFingerprint && !sourceCode.same(manifest.sourceFingerprint, this.sourceFingerprint))
        || (active?.sourceFingerprint && !sourceCode.same(active.sourceFingerprint, this.sourceFingerprint)))
      throw Error('مصدر البرنامج لا يطابق بيانات الجهاز؛ النسخة السابقة وسجلاتها محفوظة.');
    this.manifest = manifest;
    this.assertStarting();
    this.dbPort = await freePort(); this.httpPort = await freePort();
    this.origin = `http://127.0.0.1:${this.httpPort}`;
    const data = path.join(this.profile, 'database');
    const storage = this.storageFor(active);
    if (active?.format === 2) await fs.access(storage);
    for (const dir of ['app/public', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs', 'bootstrap/cache', 'private']) await fs.mkdir(path.join(storage, dir), { recursive: true, mode: 0o700 });
    this.phpIni = await phpIniFor(this.bundle, manifest, this.profile);
    const config = path.join(this.profile, 'my.ini');
    const iniPath = value => '"' + value.replaceAll('\\', '/').replaceAll('"', '') + '"';
    await fs.writeFile(config, `[mysqld]\nbasedir=${iniPath(path.join(this.bundle, 'mariadb'))}\ndatadir=${iniPath(data)}\nbind-address=127.0.0.1\nport=${this.dbPort}\ncharacter-set-server=utf8mb4\ncollation-server=utf8mb4_unicode_ci\nlocal-infile=0\nskip-name-resolve\nmax-allowed-packet=64M\ninnodb-flush-log-at-trx-commit=1\n`, { mode: 0o600 });
    try { await fs.access(path.join(data, 'mysql')); }
    catch (error) {
      if (error.code !== 'ENOENT') throw error;
      if (active) throw Error('قاعدة بيانات الجهاز المجهّز غير موجودة؛ يلزم استرجاعها قبل التشغيل.');
      this.assertStarting();
      // MariaDB's Windows initializer creates system tables; no Windows service or UAC is needed.
      await run(path.join(maria, 'mariadb-install-db.exe'), [`--datadir=${data}`, `--password=${settings.rootPassword}`, `--port=${this.dbPort}`, `--config=${config}`], { cwd: this.bundle });
    }
    if (this.stopping) throw Error('البرنامج يُغلق الآن.');
    const database = startChild(path.join(maria, 'mariadbd.exe'), [`--defaults-file=${config}`, '--console'], { cwd: this.bundle });
    database.stdin.end(); this.children.push(database);
    // Database authentication and schema creation happen through PHP stdin, never shell interpolation.
    const runtimeEnvironment = { ...this.nativeEnvironment(settings,storage), DB_DATABASE: active?.database || 'fasakhansta_dashboard' };
    this.environment = runtimeEnvironment; this.php = php; this.application = application;
    const phpArgs = LocalRuntime.phpArguments(this);
    const deadline = Date.now() + 30000;
    while (true) {
      if (database.exitCode !== null || this.stopping) throw Error('تعذر تشغيل قاعدة الداشبورد المحلية.');
      try {
        await run(php, [...phpArgs, path.join(application, 'desktop', 'database.php')], { env: runtimeEnvironment, cwd: application }, JSON.stringify({ password: settings.rootPassword, appPassword: settings.appPassword }), 5000);
        break;
      } catch (error) { if (Date.now() > deadline) throw error; await pause(150); }
    }
    if (active?.format === 2) {
      const verified = JSON.parse(await run(php, [...phpArgs, path.join(application, 'desktop/verify-media.php')],
        { env: runtimeEnvironment, cwd: application }, JSON.stringify(active)));
      if (verified.verified !== true) throw Error('صور نسخة الجهاز غير مكتملة؛ العمليات محفوظة.');
    }
    await this.startWeb();
    database.failure.then(error => { if (!this.stopping) this.onFailure(error); });
    return { origin: this.origin, token: this.token, sourceRevision: manifest.sourceRevision };
  }
  nativeEnvironment(settings,storage) {
    // The encrypted native profile owns this key. Never import a server JWT
    // secret, and never reuse the encryption key itself as a signing secret.
    const appKey=settings?.appKey;
    if(typeof appKey!=='string'||!/^base64:[A-Za-z0-9+/]{43}=$/.test(appKey))throw Error('مفتاح بيانات الجهاز غير صالح؛ احتفظ بملفاته للاسترجاع.');
    const decoded=Buffer.from(appKey.slice(7),'base64');
    if(decoded.length!==32||decoded.toString('base64')!==appKey.slice(7))throw Error('مفتاح بيانات الجهاز غير صالح؛ احتفظ بملفاته للاسترجاع.');
    const jwtSecret=crypto.createHmac('sha256',decoded).update('fasakhansta-desktop-local-jwt:v1').digest('hex');
    const inherited = Object.fromEntries(Object.entries(process.env).filter(([name]) =>
      /^(?:PATH|PATHEXT|SYSTEMROOT|WINDIR|COMSPEC|TEMP|TMP|USERPROFILE|LOCALAPPDATA|APPDATA|HOMEDRIVE|HOMEPATH)$/i.test(name)));
    return {
      ...inherited, APP_ENV: 'desktop', APP_DEBUG: 'false', APP_URL: this.origin, ASSET_URL: this.origin,
      APP_KEY: settings.appKey, JWT_SECRET: jwtSecret, DB_CONNECTION: 'mysql', DB_HOST: '127.0.0.1', DB_PORT: String(this.dbPort),
      DB_DATABASE: 'fasakhansta_dashboard', DB_USERNAME: 'dashboard', DB_PASSWORD: settings.appPassword,
      CACHE_DRIVER: 'file', SESSION_DRIVER: 'file', SESSION_DOMAIN: '', SESSION_SECURE_COOKIE: 'false',
      QUEUE_CONNECTION: 'database', MAIL_MAILER: 'log', BROADCAST_DRIVER: 'log',
      DESKTOP_DASHBOARD_LOCAL: 'true', DESKTOP_DASHBOARD_STORAGE: storage,
      DESKTOP_DASHBOARD_DEVICE_ID: settings.deviceId,
      DESKTOP_DASHBOARD_ORIGIN: this.origin, DESKTOP_DASHBOARD_TOKEN: this.token,
      DESKTOP_DASHBOARD_CONTROL_TOKEN: this.controlToken,
      APP_CONFIG_CACHE: path.join(storage, 'bootstrap/cache/config.php'),
      APP_ROUTES_CACHE: path.join(storage, 'bootstrap/cache/routes.php'),
      APP_SERVICES_CACHE: path.join(storage, 'bootstrap/cache/services.php'),
      APP_PACKAGES_CACHE: path.join(storage, 'bootstrap/cache/packages.php'),
      PHP_INI_SCAN_DIR: path.join(this.bundle, 'php', 'conf.d')
    };
  }
  storageFor(value) {
    if (!value?.generation) return path.join(this.profile, 'storage');
    if (!generation(value.generation)) throw Error('مسار بيانات الجهاز غير صحيح.');
    return path.join(this.profile, 'generations', value.generation, 'storage');
  }
  environmentFor(value) {
    const storage = this.storageFor(value);
    return { ...this.environment, DB_DATABASE: value.database, DESKTOP_DASHBOARD_STORAGE: storage,
      APP_CONFIG_CACHE: path.join(storage, 'bootstrap/cache/config.php'), APP_ROUTES_CACHE: path.join(storage, 'bootstrap/cache/routes.php'),
      APP_SERVICES_CACHE: path.join(storage, 'bootstrap/cache/services.php'), APP_PACKAGES_CACHE: path.join(storage, 'bootstrap/cache/packages.php') };
  }
  async startWeb() {
    if (this.stopping) throw Error('البرنامج يُغلق الآن.');
    const php = this.php, application = this.application, runtimeEnvironment = this.environment;
    const phpArgs = LocalRuntime.phpArguments(this);
    const web = startChild(php, [...phpArgs, '-S', `127.0.0.1:${this.httpPort}`, '-t', path.join(application, 'public'), path.join(application, 'desktop', 'router.php')], { env: runtimeEnvironment, cwd: application });
    web.stdin.end(); this.children[1] = web;
    const webDeadline = Date.now() + 20000;
    while (true) {
      if (web.exitCode !== null || this.stopping) throw Error('تعذر تشغيل الداشبورد المحلية.');
      try {
        const response = await fetch(this.origin + '/_desktop/health', { headers: { 'X-Fasakhansta-Desktop': this.token }, signal: AbortSignal.timeout(2000) });
        if (response.ok) break;
      } catch {}
      if (Date.now() > webDeadline) throw Error('الداشبورد المحلية لم تبدأ في الوقت المحدد.');
      await pause(100);
    }
    web.failure.then(error => { if (!this.stopping && this.children[1] === web) this.onFailure(error); });
  }
  context() {
    return { bundle:this.bundle, application:this.application, php:this.php, phpIni:this.phpIni,
      manifest:this.manifest, sourceFingerprint:this.sourceFingerprint };
  }
  async candidate(snapshot) {
    const activeMatches=sourceCode.same(snapshot.source,this.sourceFingerprint);
    const authorized=activeMatches?await this.authorizedSameSourceInstalled(snapshot):null;
    if(activeMatches&&!authorized)return this.context();
    const mismatch=()=>Error('نسخة البرنامج لا تطابق بيانات السيرفر؛ بيانات الجهاز وسجلاته محفوظة.');
    if(!this.manifest||!this.installedBundle)throw mismatch();
    const archive=new RuntimeArchive(this.profile,this.metadata),bundle=await archive.retain(this.installedBundle);
    const manifest=await archive.manifest(bundle),application=path.join(bundle,'application');
    const sourceFingerprint=await archive.sourceFingerprint(bundle);
    if(!sourceCode.same(snapshot.source,sourceFingerprint)||!sourceCode.same(manifest.sourceFingerprint,sourceFingerprint))throw mismatch();
    if(activeMatches){
      // Retaining/verifying may take time. A revoked publication, changed source
      // or lost connection cannot use an earlier authorization to move a journal.
      if(manifest.sourceRevision!==authorized.sourceRevision||!sourceCode.same(manifest.sourceFingerprint,authorized.sourceFingerprint))return this.context();
      try{if(await this.authorizeInstalled(manifest)!==true)return this.context();}catch{return this.context();}
    }
    RuntimeArchive.databaseCompatible(await this.metadata.read('runtimes/'+this.manifest.sourceRevision),
      await this.metadata.read('runtimes/'+manifest.sourceRevision));
    const php=path.join(bundle,'php','php.exe');await fs.access(php);
    return {bundle,application,php,phpIni:await phpIniFor(bundle,manifest,this.profile),manifest,sourceFingerprint};
  }
  async authorizeDeltaContext(context) {
    if(this.stopping||!context?.manifest||typeof this.authorizeInstalled!=='function'
      ||await this.authorizeInstalled(context.manifest)!==true)throw Error('تعذر تأكيد نسخة البرنامج المتصلة؛ العمل المحلي مستمر.');
    return true;
  }
  async deltaContext(previous) {
    if(this.stopping||!this.environment||!this.manifest||!sourceCode.same(previous.sourceFingerprint,this.sourceFingerprint))
      throw Error('مصدر قاعدة الجهاز لا يطابق التشغيل الحالي؛ بيانات الجهاز محفوظة.');
    const archive=new RuntimeArchive(this.profile,this.metadata),installed=await archive.manifest(this.installedBundle);
    if(!/^[a-f0-9]{64}$/.test(installed.sourceHashes?.['desktop/delta.php']||'')||!sourceCode.valid(installed.sourceFingerprint))
      throw Error('نسخة البرنامج تحتاج تحديثًا لاستقبال تغييرات السيرفر في الخلفية.');
    // An offline or unapproved installer cannot start a clone using new code against old data.
    await this.authorizeDeltaContext({manifest:installed});
    if(installed.sourceRevision===this.manifest.sourceRevision&&sourceCode.same(installed.sourceFingerprint,this.sourceFingerprint))return this.context();
    const bundle=await archive.retain(this.installedBundle),manifest=await archive.manifest(bundle),application=path.join(bundle,'application');
    const sourceFingerprint=await archive.sourceFingerprint(bundle);
    if(manifest.sourceRevision!==installed.sourceRevision||!sourceCode.same(manifest.sourceFingerprint,sourceFingerprint)
      ||!sourceCode.same(installed.sourceFingerprint,sourceFingerprint))throw Error('لم تتأكد ملفات تحديث البرنامج؛ النسخة الحالية محفوظة.');
    RuntimeArchive.databaseCompatible(await this.metadata.read('runtimes/'+this.manifest.sourceRevision),await this.metadata.read('runtimes/'+manifest.sourceRevision));
    const context={bundle,application,php:path.join(bundle,'php','php.exe'),phpIni:await phpIniFor(bundle,manifest,this.profile),manifest,sourceFingerprint};
    await fs.access(context.php);await this.authorizeDeltaContext(context);return context;
  }
  deltaBackgroundValid(record) {
    const database=value=>value==='fasakhansta_dashboard'||/^fasakhansta_dashboard_stage_[a-f0-9]{16}$/.test(value||'');
    return record?.format===1&&record.kind==='dashboard-delta-background'&&/^[a-f0-9]{64}$/.test(record.token||'')
      &&/^[a-f0-9]{40}$/.test(record.sourceRevision||'')&&sourceCode.valid(record.sourceFingerprint)
      &&/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i.test(record.device_id||'')&&Number.isSafeInteger(record.actor_id)&&record.actor_id>0
      &&database(record.source_database)&&generation(record.capture_generation)
      &&record.capture_database==='fasakhansta_dashboard_stage_'+record.capture_generation&&record.capture_database!==record.source_database
      &&(record.candidate_generation===null&&record.candidate_database===null||generation(record.candidate_generation)
        &&record.candidate_database==='fasakhansta_dashboard_stage_'+record.candidate_generation
        &&record.candidate_database!==record.capture_database&&record.candidate_database!==record.source_database);
  }
  async restoreDeltaContext(record) {
    if(!this.deltaBackgroundValid(record))throw Error('تعذر التحقق من سجل تحديث الخلفية؛ بيانات الجهاز محفوظة.');
    if(this.manifest?.sourceRevision===record.sourceRevision&&sourceCode.same(this.sourceFingerprint,record.sourceFingerprint)
      &&/^[a-f0-9]{64}$/.test(this.manifest.sourceHashes?.['desktop/delta.php']||''))return this.context();
    const archive=new RuntimeArchive(this.profile,this.metadata),bundle=path.join(this.profile,'runtimes',record.sourceRevision);
    const receipt=await this.metadata.read('runtimes/'+record.sourceRevision);
    await archive.verify(bundle,receipt);
    const manifest=await archive.manifest(bundle),application=path.join(bundle,'application'),sourceFingerprint=await archive.sourceFingerprint(bundle);
    if(manifest.sourceRevision!==record.sourceRevision||!sourceCode.same(manifest.sourceFingerprint,sourceFingerprint)
      ||!sourceCode.same(record.sourceFingerprint,sourceFingerprint)||!/^[a-f0-9]{64}$/.test(manifest.sourceHashes?.['desktop/delta.php']||''))
      throw Error('مصدر سجل تحديث الخلفية غير متطابق؛ احتفظ ببيانات الجهاز.');
    RuntimeArchive.databaseCompatible(await this.metadata.read('runtimes/'+this.manifest.sourceRevision),receipt);
    return {bundle,application,php:path.join(bundle,'php','php.exe'),phpIni:await phpIniFor(bundle,manifest,this.profile),manifest,sourceFingerprint};
  }
  async deltaDatabaseExists(stage) {
    if(!generation(stage.value.generation)||stage.value.database!=='fasakhansta_dashboard_stage_'+stage.value.generation)throw Error('اسم قاعدة تحديث الخلفية غير صالح.');
    const settings=await this.settings();
    const result=JSON.parse(await run(stage.context.php,[...stage.args,path.join(stage.context.application,'desktop/database.php'),'stage-status'],
      {env:stage.options.env,cwd:stage.options.cwd},JSON.stringify({password:settings.rootPassword,appPassword:settings.appPassword}),15000));
    if(result?.format!==1||result.database!==stage.value.database||typeof result.exists!=='boolean')throw Error('تعذر تأكيد حالة قاعدة تحديث الخلفية.');
    return result.exists;
  }
  async recoverDeltaBackground() {
    const record=await this.metadata.read('delta-background');if(!record)return;
    if(!this.deltaBackgroundValid(record)||(await this.settings()).deviceId!==record.device_id||await this.metadata.read('refresh'))
      throw Error('تعذر استرجاع تحديث الخلفية قبل تسوية انتقال قاعدة الجهاز.');
    const context=await this.restoreDeltaContext(record),active=await this.connection();
    if(active.deviceId!==record.device_id||active.actorId!==record.actor_id||active.database===record.capture_database)
      throw Error('سجل تحديث الخلفية لا يطابق حساب قاعدة الجهاز.');
    const describe=(id,database)=>{
      const value={generation:id,database},env={...this.environmentFor(value),PHP_INI_SCAN_DIR:path.join(context.bundle,'php','conf.d'),
        DESKTOP_DASHBOARD_DELTA_SOURCE_DATABASE:active.database};
      return {value,root:path.join(this.profile,'generations',id),env,options:{env,cwd:context.application},args:LocalRuntime.phpArguments(context),context};
    };
    const capture={capture_database:record.capture_database,source_database:record.source_database};
    this.deltaCaptures??=new Map();
    this.deltaCaptures.set(record.capture_database,{...describe(record.capture_generation,record.capture_database),capture:null,
      source_database:record.source_database,token:record.token});
    if(record.candidate_database&&record.candidate_database!==active.database){
      const candidate={generation:record.candidate_generation,database:record.candidate_database};
      this.deltaStages??=new Map();this.deltaStages.set(record.candidate_generation,{...describe(record.candidate_generation,record.candidate_database),candidate,
        receipt:null,token:record.token,capture});
      if(await this.releaseDeltaStage(candidate,record.token)!==true)throw Error('تعذر تأكيد إزالة نسخة تحديث غير مفعّلة؛ النسخة الحالية محفوظة.');
    }
    if(await this.releaseDeltaCapture(capture,record.token)!==true)throw Error('تعذر تأكيد تسوية نسخة قراءة الخلفية؛ النسخة الحالية محفوظة.');
  }
  async createDeltaDatabase(context,id) {
    if(this.stopping||!this.environment||!generation(id))throw Error('تعذر بدء تحديث الخلفية؛ بيانات الجهاز محفوظة.');
    const value={generation:id,database:'fasakhansta_dashboard_stage_'+id},root=path.join(this.profile,'generations',id);
    await fs.mkdir(path.dirname(root),{recursive:true,mode:0o700});await fs.mkdir(root,{mode:0o700});
    for(const dir of ['app/public','framework/cache/data','framework/sessions','framework/views','logs','bootstrap/cache','private'])
      await fs.mkdir(path.join(this.storageFor(value),dir),{recursive:true,mode:0o700});
    const env={...this.environmentFor(value),PHP_INI_SCAN_DIR:path.join(context.bundle,'php','conf.d')};
    this.deltaController??=new AbortController();
    const settings=await this.settings(),args=LocalRuntime.phpArguments(context),options={env,cwd:context.application,signal:this.deltaController.signal};
    await run(context.php,[...args,path.join(context.application,'desktop/database.php'),'stage'],options,
      JSON.stringify({password:settings.rootPassword,appPassword:settings.appPassword}));
    return {value,root,env,options,args,context};
  }
  async captureDelta(context,previous,id,token) {
    if(!/^[a-f0-9]{64}$/.test(token||''))throw Error('بيانات تحديث الخلفية غير صالحة.');
    if(await this.metadata.read('delta-background'))throw Error('يلزم استرجاع نسخة قراءة الخلفية السابقة أولًا.');
    const background={format:1,kind:'dashboard-delta-background',token,sourceRevision:context.manifest.sourceRevision,
      sourceFingerprint:context.sourceFingerprint,source_database:previous.database,capture_generation:id,
      capture_database:'fasakhansta_dashboard_stage_'+id,candidate_generation:null,candidate_database:null,device_id:previous.deviceId,actor_id:previous.actorId};
    if(!this.deltaBackgroundValid(background))throw Error('بيانات نسخة القراءة غير صالحة.');
    // Persist the private capability before CREATE DATABASE, including a crash before PHP's first marker.
    await this.metadata.write('delta-background',background);
    const stage=await this.createDeltaDatabase(context,id),input={action:'capture',source_database:previous.database,
      device_id:previous.deviceId,actor_id:previous.actorId,snapshot_id:previous.snapshotId,schema_hash:previous.schemaHash,
      source:previous.sourceFingerprint,capture_token:token};
    stage.options.env.DESKTOP_DASHBOARD_DELTA_SOURCE_DATABASE=previous.database;
    const retained={...stage,capture:null,source_database:previous.database,token};
    this.deltaCaptures??=new Map();this.deltaCaptures.set(stage.value.database,retained);
    try{
      const capture=JSON.parse(await run(context.php,[...stage.args,'-d','memory_limit=-1',path.join(context.application,'desktop/delta.php')],
        stage.options,JSON.stringify(input),300000,72*1024*1024));
      if(capture?.capture_database!==stage.value.database||capture?.source_database!==previous.database)throw Error('تعذر تأكيد نسخة القراءة المحلية؛ البيانات الحالية محفوظة.');
      retained.capture=capture;return capture;
    }catch(error){await this.releaseDeltaCapture({capture_database:stage.value.database,source_database:previous.database},token).catch(()=>{});throw error;}
  }
  async stageDelta(context,capture,delta,id,token) {
    const retained=this.deltaCaptures?.get(capture.capture_database);
    if(!retained||retained.token!==token||retained.context!==context||retained.capture!==capture)throw Error('نسخة القراءة لا تخص تحديث الخلفية الحالي.');
    media.manifest(delta.media);
    // Preserve the exact original wire representation of every added row.
    const input=LocalRuntime.deltaInput(capture,delta,token);
    const background=await this.metadata.read('delta-background');
    if(!this.deltaBackgroundValid(background)||background.token!==token||background.capture_database!==capture.capture_database
      ||background.candidate_database!==null)throw Error('سجل نسخة تحديث الخلفية غير متطابق.');
    await this.metadata.write('delta-background',{...background,candidate_generation:id,candidate_database:'fasakhansta_dashboard_stage_'+id});
    const stage=await this.createDeltaDatabase(context,id);
    const candidate={...stage.value};
    const retainedStage={...stage,candidate,receipt:null,token,capture};
    this.deltaStages??=new Map();this.deltaStages.set(id,retainedStage);
    try{
    const receipt=JSON.parse(await run(context.php,[...stage.args,'-d','memory_limit=-1',path.join(context.application,'desktop/delta.php')],stage.options,input,300000));
    retainedStage.receipt=receipt;
    const profiles=importPhpProfiles(receipt?.php_profiles);
    if(profiles)await fs.writeFile(path.join(stage.root,'import-php-profiles.json'),JSON.stringify(profiles)+'\n',{flag:'wx',mode:0o600});
    const active=await this.connection(),storage=this.storageFor(active);
    const images=await media.download(path.join(this.storageFor(stage.value),'app/public'),delta.media,this.downloadMedia,()=>this.stopping,
      path.join(this.storageFor(stage.value),'app'),{publicRoot:path.join(storage,'app/public'),privateRoot:path.join(storage,'app'),cacheRoot:path.join(this.profile,'media-cache')});
    // Full row/media verification and stat-seal creation happen before the short final fence.
    const preverified=JSON.parse(await run(context.php,[...stage.args,path.join(context.application,'desktop/verify.php')],stage.options,
      JSON.stringify({action:'preverify',receipt,handoff_token:token}),300000));
    const {media_seal:mediaSeal,archive_handoff:archiveHandoff,...checked}=preverified;
    if(checked.verified!==true||!mediaSeal||checked.snapshot_id!==delta.snapshot_id||checked.device_id!==active.deviceId
      ||checked.actor_id!==delta.actor_id||checked.schema_hash!==delta.schema_hash
      ||!isDeepStrictEqual(checked.branches,delta.branches)||!isDeepStrictEqual(checked.coverage,delta.coverage)
      ||!sourceCode.same(checked.source,delta.source)||archiveHandoff?.format!==1||archiveHandoff.kind!=='desktop-preverified-handoff'
      ||!/^[a-f0-9]{64}$/.test(archiveHandoff.signature||'')||archiveHandoff.payload?.database!==stage.value.database
      ||archiveHandoff.payload.source_database!==capture.source_database||archiveHandoff.payload.sequence!==capture.sequence
      ||!isDeepStrictEqual(archiveHandoff.payload.replay,checked.delta_replay))throw Error('تعذر تأكيد بيانات تحديث الخلفية؛ البيانات الحالية محفوظة.');
    media.verifyReceipt(checked.media,images.files);
    Object.assign(candidate,{receipt:checked,mediaSeal,archiveHandoff,sourceRevision:context.manifest.sourceRevision,
      sourceFingerprint:checked.source,mediaVerified:images.verified});
    this.stagedContexts.set(id,context);return candidate;
    }catch(error){await this.releaseDeltaStage(candidate,token).catch(()=>{});throw error;}
  }
  async finalizeDelta(candidate,intent) {
    const stage=this.deltaStages?.get(candidate.generation);
    if(this.stopping||!stage||stage.candidate!==candidate||!candidate.mediaSeal||!candidate.archiveHandoff)throw Error('تعذر تأكيد نسخة تحديث الخلفية.');
    // The final durable-fence transaction must settle even when program exit
    // cancels earlier background work. It performs only the preverified stat handoff.
    await run(stage.context.php,[...stage.args,path.join(stage.context.application,'desktop/archive.php')],
      {env:stage.options.env,cwd:stage.options.cwd},
      JSON.stringify({receipt:candidate.receipt,source:intent.previous.database,refresh_id:intent.id,token:intent.token,
        source_identity:{snapshot_id:intent.previous.snapshotId,schema_hash:intent.previous.schemaHash},media_seal:candidate.mediaSeal,
        archive_handoff:candidate.archiveHandoff,handoff_token:stage.token}),90000);
  }
  async releaseDeltaCapture(capture,token) {
    const stage=this.deltaCaptures?.get(capture.capture_database);
    if(!stage||stage.token!==token||stage.source_database!==capture.source_database||stage.capture&&stage.capture!==capture)return;
    const active=await this.connection();if(active.database===capture.capture_database)return false;
    const options={cwd:stage.options.cwd,env:{...stage.options.env,DESKTOP_DASHBOARD_DELTA_SOURCE_DATABASE:active.database}};
    if(await this.deltaDatabaseExists(stage)){
      const result=JSON.parse(await run(stage.context.php,[...stage.args,path.join(stage.context.application,'desktop/delta.php')],options,
        JSON.stringify({action:'discard-capture',capture_database:capture.capture_database,source_database:capture.source_database,
          active_database:active.database,capture_token:token,...(stage.capture?{capture:stage.capture}:{})}),90000));
      if(result?.discarded!==true||result.database!==capture.capture_database)return false;
    }
    this.deltaCaptures.delete(capture.capture_database);
    if(await this.removeDeltaDirectory(stage.value)!==true)return false;
    const background=await this.metadata.read('delta-background'),current=await this.connection();
    if(background?.token===token&&background.capture_database===capture.capture_database
      &&(!background.candidate_database||background.candidate_database===current.database))await this.metadata.write('delta-background',null);
    return true;
  }
  async releaseDeltaStage(candidate,token) {
    const stage=this.deltaStages?.get(candidate.generation);
    if(!stage||stage.candidate!==candidate||stage.token!==token)return;
    if(await this.metadata.read('refresh'))return false;
    const active=await this.connection();if(active.database===candidate.database)return false;
    const options={cwd:stage.options.cwd,env:{...stage.options.env,DESKTOP_DASHBOARD_DELTA_SOURCE_DATABASE:active.database}};
    if(await this.deltaDatabaseExists(stage)){
      const result=JSON.parse(await run(stage.context.php,[...stage.args,path.join(stage.context.application,'desktop/delta.php')],options,
        JSON.stringify({action:'discard-stage',stage_database:candidate.database,source_database:stage.capture.source_database,
          active_database:active.database,capture_database:stage.capture.capture_database,capture_token:token,
          ...(stage.receipt?{receipt:stage.receipt}:{})}),90000));
      if(result?.discarded!==true||result.database!==candidate.database)return false;
    }
    if((await this.connection()).database===candidate.database)return false;
    this.deltaStages.delete(candidate.generation);this.stagedContexts.delete(candidate.generation);
    if(await this.removeDeltaDirectory(stage.value)!==true)return false;
    const background=await this.metadata.read('delta-background');
    if(background?.token===token&&background.candidate_database===candidate.database)
      await this.metadata.write('delta-background',{...background,candidate_generation:null,candidate_database:null});
    return true;
  }
  async removeDeltaDirectory(value) {
    if(!generation(value.generation)||value.database!=='fasakhansta_dashboard_stage_'+value.generation)return false;
    const root=path.join(this.profile,'generations',value.generation),parent=path.dirname(root);
    if((await this.connection()).database===value.database||await this.metadata.read('refresh'))return false;
    for(const file of [this.profile,parent,root]){
      let stat;try{stat=await fs.lstat(file);}catch(error){if(error.code==='ENOENT')return true;throw error;}
      if(!stat.isDirectory()||stat.isSymbolicLink())return false;
    }
    // Only this supervisor's fresh, privately marked and positively discarded temporary stage is removed.
    await fs.rm(root,{recursive:true,force:false});return true;
  }
  cancelDelta(){this.deltaController?.abort();}
  async stage(snapshot, id, archive, onMediaProgress) {
    if (this.stopping || !this.environment || !generation(id)) throw Error('تعذر بدء تجهيز البيانات.');
    const context = await this.candidate(snapshot);
    media.manifest(snapshot.media || []);
    const database = 'fasakhansta_dashboard_stage_' + id;
    const value = { generation: id, database }, env = { ...this.environmentFor(value), PHP_INI_SCAN_DIR:path.join(context.bundle,'php','conf.d') };
    const root = path.join(this.profile, 'generations', id);
    await fs.mkdir(path.dirname(root), { recursive: true, mode: 0o700 });
    await fs.mkdir(root, { mode: 0o700 }); // Never reuse a partial or previously active generation.
    for (const dir of ['app/public', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs', 'bootstrap/cache', 'private'])
      await fs.mkdir(path.join(this.storageFor(value), dir), { recursive: true, mode: 0o700 });
    const settings = await this.settings();
    const phpArgs = LocalRuntime.phpArguments(context);
    const options = { env, cwd: context.application };
    if (this.stopping) throw Error('البرنامج يُغلق الآن.');
    await run(context.php, [...phpArgs, path.join(context.application, 'desktop/database.php'), 'stage'], options,
      JSON.stringify({ password: settings.rootPassword, appPassword: settings.appPassword }));
    // The import decodes the whole server snapshot in one PHP process. A large account
    // needs more than the 256 MiB web limit, so only this one-shot step is uncapped.
    const receipt = JSON.parse(await run(context.php, [...phpArgs, '-d', 'memory_limit=-1', path.join(context.application, 'desktop/import.php')], options, readSnapshot.input(snapshot), 300000));
    const profiles = importPhpProfiles(receipt?.php_profiles);
    if (profiles) {
      // Keep only safe settings from this successful import, inside its fresh
      // private generation. Never persist the account snapshot or full receipt.
      try {
        await fs.writeFile(path.join(root, 'import-php-profiles.json'), JSON.stringify(profiles) + '\n', { flag: 'wx', mode: 0o600 });
      } catch { throw Error('تعذر حفظ مقارنة إعدادات PHP؛ بيانات الجهاز محفوظة.'); }
    }
    const images = await media.download(path.join(this.storageFor(value), 'app/public'), snapshot.media || [], this.downloadMedia, () => this.stopping,
      path.join(this.storageFor(value), 'app'), undefined, { onProgress: onMediaProgress });
    if (archive) await run(context.php, [...phpArgs, path.join(context.application, 'desktop/archive.php')], options,
      JSON.stringify({ receipt, source: archive.previous.database || 'fasakhansta_dashboard', refresh_id: archive.id, token: archive.token,
        source_identity: {snapshot_id:archive.previous.snapshotId,schema_hash:archive.previous.schemaHash} }), 90000);
    const checked = JSON.parse(await run(context.php, [...phpArgs, path.join(context.application, 'desktop/verify.php')], options, JSON.stringify(receipt), 90000));
    if (checked.verified !== true || checked.snapshot_id !== snapshot.snapshot_id || checked.device_id !== settings.deviceId
        || checked.actor_id !== snapshot.actor_id || checked.schema_hash !== snapshot.schema_hash
        || JSON.stringify(checked.branches) !== JSON.stringify(snapshot.branches) || JSON.stringify(checked.coverage) !== JSON.stringify(snapshot.coverage))
      throw Error('تعذر التحقق من اكتمال قاعدة التجهيز.');
    media.verifyReceipt(checked.media, images.files);
    if (!sourceCode.same(checked.source, snapshot.source)) throw Error('لم يتأكد مصدر برنامج التجهيز؛ بيانات الجهاز الحالية محفوظة.');
    if (this.stopping) throw Error('البرنامج يُغلق الآن.');
    this.stagedContexts.set(id,context);
    return { database, receipt: checked, sourceRevision: context.manifest.sourceRevision, sourceFingerprint: checked.source, mediaVerified: images.verified };
  }
  async drainWeb() {
    if (this.children[1] && this.origin) {
      const response = await fetch(this.origin + '/_desktop/health', { headers: { 'X-Fasakhansta-Desktop': this.token }, signal: AbortSignal.timeout(25000) });
      if (!response.ok) throw Error('تعذر إكمال طلبات الداشبورد الحالية.');
    }
    const web = this.children[1]; this.children[1] = null;
    await stopChild(web);
  }
  async activate(next, commitPointer) {
    const context = this.stagedContexts.get(next.generation);
    if (this.stopping || !context || !prepared(next, (await this.settings()).deviceId, context.manifest) || next.sourceRevision !== context.manifest.sourceRevision
        || !sourceCode.same(next.sourceFingerprint, context.sourceFingerprint))
      throw Error('نسخة التجهيز غير قابلة للتفعيل.');
    const oldEnvironment = this.environment;
    await this.drainWeb();
    try {
      // Only original login sessions survive. Application caches, media and journals stay per generation.
      await fs.cp(path.join(oldEnvironment.DESKTOP_DASHBOARD_STORAGE, 'framework/sessions'),
        path.join(this.storageFor(next), 'framework/sessions'), { recursive: true });
      if (this.stopping) throw Error('البرنامج يُغلق الآن.');
      await commitPointer();
    } catch (error) {
      // A flushed pointer may have committed before a reported filesystem failure.
      const active = await this.metadata.read('prepared');
      if (active?.refreshId !== next.refreshId) {
        this.environment = oldEnvironment;
        if (!this.stopping) await this.startWeb();
        throw error;
      }
    }
    Object.assign(this,context);
    this.environment = { ...this.environmentFor(next), PHP_INI_SCAN_DIR:path.join(context.bundle,'php','conf.d') };
    this.stagedContexts.delete(next.generation);
    this.deltaStages?.delete(next.generation);
    if (!this.stopping) await this.startWeb();
  }
  async stop() {
    this.stopping = true;
    if (this.stopped) return this.stopped;
    this.stopped = (async () => {
      // Stop PHP before shutting down InnoDB. Never delete or reinitialize a database on shutdown.
      if (this.children[1] && this.origin) {
        // The single PHP worker answers this only after its preceding accepted request completes.
        try { await fetch(this.origin + '/_desktop/health', { headers: { 'X-Fasakhansta-Desktop': this.token }, signal: AbortSignal.timeout(25000) }); } catch {}
      }
      await stopChild(this.children[1]);
      if (this.children[0] && this.php) {
        try { await run(this.php, [...LocalRuntime.phpArguments(this), path.join(this.application, 'desktop', 'database.php'), 'shutdown'], { env: this.environment, cwd: this.application }, JSON.stringify({ password: (await this.settings()).rootPassword }), 8000); } catch {}
      }
      await stopChild(this.children[0]);
    })();
    return this.stopped;
  }
}
module.exports = LocalRuntime;
