'use strict';
const fs = require('node:fs/promises');
const path = require('node:path');
const crypto = require('node:crypto');
const net = require('node:net');
const { spawn } = require('node:child_process');
const { GenerationStore, prepared, generation } = require('./dashboard-generation.cjs');
const media = require('./dashboard-media.cjs');
const RuntimeArchive = require('./runtime-archive.cjs');
const sourceCode = require('./dashboard-source.cjs');

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
async function run(file, args, options, input = '', timeout = 90000) {
  const child = startChild(file, args, options, true);
  let output = ''; let overflow = false;
  child.stdout.setEncoding('utf8');
  child.stdout.on('data', bytes => { output += bytes.toString(); if (Buffer.byteLength(output) > 20 * 1024 * 1024) { overflow = true; child.kill(); } });
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
          }
        } catch {}
      }
      throw Error('تعذر تنفيذ خطوة تجهيز الخدمة المحلية؛ بيانات الجهاز محفوظة.', { cause });
    }
    return output;
  } finally { clearTimeout(timer); }
}
async function stopChild(child) {
  if (!child || child.exitCode !== null || child.signalCode !== null) return;
  child.kill();
  let timer;
  await Promise.race([child.failure, new Promise(resolve => { timer = setTimeout(() => { child.kill('SIGKILL'); resolve(); }, 8000); })]);
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
  constructor({ bundle, profile, safeStorage, downloadMedia, platform = process.platform, onFailure = () => {} }) {
    this.installedBundle = bundle; this.bundle = bundle; this.stagedContexts = new Map(); this.profile = path.join(profile, 'dashboard');
    this.safeStorage = safeStorage; this.platform = platform; this.onFailure = onFailure;
    this.children = []; this.stopping = false; this.token = crypto.randomBytes(32).toString('hex');
    this.controlToken = crypto.randomBytes(32).toString('hex');
    this.metadata = new GenerationStore(this.profile, safeStorage);
    this.downloadMedia = downloadMedia;
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
      return prepared(value, settings.deviceId);
    } catch (error) { if (error.code === 'ENOENT') return false; throw error; }
  }
  async connection() {
    if (!(await this.isPrepared())) throw Error('بيانات الداشبورد المحلية لم تُجهّز بالكامل.');
    return this.metadata.read('prepared');
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
    this.starting = this.boot().catch(async error => { await this.stop(); throw error; });
    return this.starting;
  }
  async boot() {
    if (this.platform !== 'win32') throw Error('حزمة التشغيل المحلية مخصصة لويندوز.');
    const settings = await this.settings();
    const active = await this.metadata.read('prepared');
    if (active && !prepared(active, settings.deviceId)) throw Error('بيانات تجهيز الداشبورد غير صالحة؛ سجلات الجهاز محفوظة.');
    this.bundle = await new RuntimeArchive(this.profile, this.metadata).select(this.bundle, active);
    const manifest = JSON.parse(await fs.readFile(path.join(this.bundle, 'manifest.json'), 'utf8'));
    if (manifest.format !== 1 || manifest.platform !== 'win32-x64') throw Error('حزمة الداشبورد المحلية غير متوافقة.');
    const php = path.join(this.bundle, 'php', 'php.exe');
    const maria = path.join(this.bundle, 'mariadb', 'bin');
    const application = path.join(this.bundle, 'application');
    for (const file of [php, path.join(maria, 'mariadbd.exe'), path.join(maria, 'mariadb-install-db.exe'), path.join(application, 'vendor', 'autoload.php')]) await fs.access(file);
    if (active?.format === 2 && active.sourceRevision !== manifest.sourceRevision) throw Error('نسخة التشغيل تختلف عن نسخة بيانات الجهاز؛ يلزم استرجاع التحديث قبل العمل.');
    this.sourceFingerprint = await sourceCode.fingerprint(application);
    if ((manifest.sourceFingerprint && !sourceCode.same(manifest.sourceFingerprint, this.sourceFingerprint))
        || (active?.sourceFingerprint && !sourceCode.same(active.sourceFingerprint, this.sourceFingerprint)))
      throw Error('مصدر البرنامج لا يطابق بيانات الجهاز؛ النسخة السابقة وسجلاتها محفوظة.');
    this.manifest = manifest;
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
      // MariaDB's Windows initializer creates system tables; no Windows service or UAC is needed.
      await run(path.join(maria, 'mariadb-install-db.exe'), [`--datadir=${data}`, `--password=${settings.rootPassword}`, `--port=${this.dbPort}`, `--config=${config}`], { cwd: this.bundle });
    }
    if (this.stopping) throw Error('البرنامج يُغلق الآن.');
    const database = startChild(path.join(maria, 'mariadbd.exe'), [`--defaults-file=${config}`, '--console'], { cwd: this.bundle });
    database.stdin.end(); this.children.push(database);
    // Database authentication and schema creation happen through PHP stdin, never shell interpolation.
    const inherited = Object.fromEntries(Object.entries(process.env).filter(([name]) =>
      /^(?:PATH|PATHEXT|SYSTEMROOT|WINDIR|COMSPEC|TEMP|TMP|USERPROFILE|LOCALAPPDATA|APPDATA|HOMEDRIVE|HOMEPATH)$/i.test(name)));
    const runtimeEnvironment = {
      ...inherited, APP_ENV: 'desktop', APP_DEBUG: 'false', APP_URL: this.origin, ASSET_URL: this.origin,
      APP_KEY: settings.appKey, DB_CONNECTION: 'mysql', DB_HOST: '127.0.0.1', DB_PORT: String(this.dbPort),
      DB_DATABASE: active?.database || 'fasakhansta_dashboard', DB_USERNAME: 'dashboard', DB_PASSWORD: settings.appPassword,
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
    if(sourceCode.same(snapshot.source,this.sourceFingerprint))return this.context();
    const mismatch=()=>Error('نسخة البرنامج لا تطابق بيانات السيرفر؛ بيانات الجهاز وسجلاته محفوظة.');
    if(!this.manifest||!this.installedBundle)throw mismatch();
    const archive=new RuntimeArchive(this.profile,this.metadata),bundle=await archive.retain(this.installedBundle);
    const manifest=await archive.manifest(bundle),application=path.join(bundle,'application');
    const sourceFingerprint=await sourceCode.fingerprint(application);
    if(!sourceCode.same(snapshot.source,sourceFingerprint)||!sourceCode.same(manifest.sourceFingerprint,sourceFingerprint))throw mismatch();
    RuntimeArchive.databaseCompatible(await this.metadata.read('runtimes/'+this.manifest.sourceRevision),
      await this.metadata.read('runtimes/'+manifest.sourceRevision));
    const php=path.join(bundle,'php','php.exe');await fs.access(php);
    return {bundle,application,php,phpIni:await phpIniFor(bundle,manifest,this.profile),manifest,sourceFingerprint};
  }
  async stage(snapshot, id, archive) {
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
    const receipt = JSON.parse(await run(context.php, [...phpArgs, path.join(context.application, 'desktop/import.php')], options, JSON.stringify(snapshot), 300000));
    const images = await media.download(path.join(this.storageFor(value), 'app/public'), snapshot.media || [], this.downloadMedia, () => this.stopping,
      path.join(this.storageFor(value), 'app'));
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
    if (this.stopping || !context || !prepared(next, (await this.settings()).deviceId) || next.sourceRevision !== context.manifest.sourceRevision
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
