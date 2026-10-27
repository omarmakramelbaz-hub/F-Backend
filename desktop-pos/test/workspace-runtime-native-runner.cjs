'use strict';
// Node owns the disposable genuine PHP server and TLS publication fixture while
// an actual pinned Electron process owns DPAPI, original sessions and runtimes.
const fs = require('node:fs'), path = require('node:path'), os = require('node:os'), http = require('node:http'), https = require('node:https'), net = require('node:net');
const crypto = require('node:crypto'), assert = require('node:assert/strict'), { spawn, spawnSync, execFileSync } = require('node:child_process');
assert.equal(process.platform, 'win32', 'Shared workspace native acceptance requires actual Windows.');
const [bundleArg, portArg, outputArg] = process.argv.slice(2); if (!bundleArg || !portArg || !outputArg) throw Error('Usage: workspace-runtime-native-runner actual-bundle external-MariaDB-port proof-file');
const bundle = fs.realpathSync.native(path.resolve(bundleArg)), dbPort = Number(portArg), output = path.resolve(outputArg), repository = path.resolve(__dirname, '../..');
assert.ok(Number.isInteger(dbPort) && dbPort >= 1024 && dbPort <= 65535);
const php = path.join(bundle, 'php/php.exe'), electron = path.resolve(__dirname, '../node_modules/electron/dist/electron.exe');
const { sourceFiles, fixturePaths } = require('../../.github/scripts/verify-workspace-runtime-native.cjs');
const { fixture, router } = fixturePaths(repository);
const sourceRevision = execFileSync('git', ['rev-parse', 'HEAD'], { cwd: repository, encoding: 'utf8' }).trim();
const manifest = JSON.parse(fs.readFileSync(path.join(bundle, 'manifest.json'), 'utf8')); assert.equal(manifest.sourceRevision, sourceRevision);
if (process.env.GITHUB_SHA) assert.equal(sourceRevision, process.env.GITHUB_SHA);
const root = path.join(fs.realpathSync.native(os.tmpdir()), 'go-erp-workspace-native-' + crypto.randomBytes(8).toString('hex'));
fs.mkdirSync(root, { mode: 0o700 });
fs.writeFileSync(path.join(root, 'disposable-workspace-native.marker'), 'GO_ERP_DISPOSABLE_SHARED_WORKSPACE_ONLY\n', { flag: 'wx' });
const serverConfig = path.join(root, 'server-config.json'), runnerConfig = path.join(root, 'native-config.json'), childProof = path.join(root, 'native-proof.json');
const certificate = fs.readFileSync(path.join(__dirname, 'fixtures/connected-loopback-cert.pem')), key = fs.readFileSync(path.join(__dirname, 'fixtures/connected-loopback-key.pem'));
const certificateFingerprint = new crypto.X509Certificate(certificate).fingerprint256;
const counts = { totalRequests: 0, offlineRequests: 0, publicationRequests: 0 };
const count = () => fs.writeFileSync(path.join(root, 'request-counts.json'), JSON.stringify(counts) + '\n'); count();
const sha = file => crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex');
const sourceHashes = Object.fromEntries(sourceFiles(repository).map(file => [file, sha(path.join(repository, file))]));
let phpServer, tls, child, prepared = false, phpError = '', childDiagnostic = '', privateServerConfig;
const phpEvents = [], cliFailures = [];
function sanitizedPhp(text) {
  const secrets = [...(privateServerConfig?.credentials || []).flatMap(row => [row.email, row.password]),
    ...Object.entries(privateServerConfig?.environment || {}).filter(([name]) => /KEY|PASSWORD|TOKEN|SECRET/.test(name)).map(([, value]) => value)];
  let value = String(text); for (const secret of secrets) if (typeof secret === 'string' && secret.length >= 4) value = value.split(secret).join('[redacted]');
  return value.replace(/base64:[A-Za-z0-9+/=]+/g, '[redacted-key]').replace(/Bearer\s+[^\s"']+/gi, 'Bearer [redacted]')
    .replace(/\$2[aby]\$[0-9]{2}\$[./A-Za-z0-9]{53}/g, '[redacted-verifier]')
      .replace(/\$argon2(?:id|i|d)\$[^\s"']+/g, '[redacted-verifier]')
      .replace(/\b[A-Za-z0-9+/]{64,}={0,2}\b/g, '[redacted-token]')
      .replace(/\b[a-f0-9]{32,}\b/gi, '[redacted-token]').replace(/https?:\/\/[^\s"']+/g, '[redacted-url]').slice(0, 1200);
}
const phpArguments = ['-c', path.join(bundle, 'php/php.ini')];
const environment = { ...process.env, PHP_INI_SCAN_DIR: path.join(bundle, 'php/conf.d'), DESKTOP_WORKSPACE_NATIVE_FIXTURE_CONFIG: serverConfig };
const cli = args => {
  const result = spawnSync(php, [...phpArguments, '-d', 'opcache.enable_cli=0', fixture, ...args], { cwd: repository, env: environment, encoding: 'utf8', windowsHide: true, timeout: 180000, maxBuffer: 8 * 1024 ** 2 });
  if (result.status !== 0) {
    for (const line of String(result.stderr || '').split(/\r?\n/)) if (line.startsWith('NATIVE_SERVER_PHP_DIAGNOSTIC ')) {
      // The source-bound PHP producer emits only typed public location/code
      // fields; no original stdout or exception/SQL message is forwarded.
      try {
        const report = JSON.parse(line.slice('NATIVE_SERVER_PHP_DIAGNOSTIC '.length));
        if (report && /^[A-Za-z0-9_\\]{1,160}$/.test(report.type || '') && /^[A-Za-z0-9_.-]{1,120}$/.test(report.file || '')
          && Number.isInteger(report.line) && report.line > 0) {
          const safe = { type: report.type, file: report.file, line: report.line,
            phase: /^[a-z-]{1,60}$/.test(report.phase || '') ? report.phase : 'unknown' };
          if (Number.isInteger(report.status) && report.status >= 400 && report.status <= 599) safe.status = report.status;
          if (/^[A-Z0-9]{5}$/.test(report.sql_state || '') && Number.isInteger(report.driver_errno) && report.driver_errno > 0 && report.driver_errno <= 65535) {
            safe.sql_state = report.sql_state; safe.driver_errno = report.driver_errno;
            const context = report.sql_context;
            if (context && ['create-table','alter-table','other'].includes(context.operation)
              && ['workspace-command-authorities','workspace-authority-history','workspace-authority','workspace-members','other'].includes(context.table)
              && ['expected','effects','command-table','default','other','not-reported'].includes(context.target))
              safe.sql_context = { operation: context.operation, table: context.table, target: context.target };
          }
          safe.frames = (Array.isArray(report.frames) ? report.frames : []).filter(value => /^[A-Za-z0-9_.-]{1,120}$/.test(value?.file || '')
            && Number.isInteger(value.line) && value.line > 0).slice(0,10).map(value => ({ file: value.file, line: value.line }));
          if ([1,4,16,64,256,4096].includes(report.error_type)) safe.error_type = report.error_type;
          const safeLine = 'NATIVE_SERVER_PHP_DIAGNOSTIC ' + JSON.stringify(safe);
          phpEvents.push(safeLine); process.stderr.write(safeLine + '\n');
        }
      } catch {}
    }
    const failure = { action: ['prepare','inspect','cleanup'].includes(args[0]) ? args[0] : 'unknown', status: result.status ?? null, signal: result.signal || null,
      code: /^[A-Z0-9_]{1,80}$/.test(result.error?.code || '') ? result.error.code : null,
      stdoutBytes: Buffer.byteLength(result.stdout || ''), stderrBytes: Buffer.byteLength(result.stderr || '') };
    cliFailures.push(failure); process.stderr.write('NATIVE_SERVER_CLI_FAILED ' + JSON.stringify(failure) + '\n');
  }
  assert.equal(result.status, 0, 'The private real PHP fixture failed.'); return result.stdout;
};
const freePort = async () => { const value = net.createServer(); await new Promise(resolve => value.listen(0, '127.0.0.1', resolve)); const port = value.address().port; await new Promise(resolve => value.close(resolve)); return port; };
const ready = async port => { const until = Date.now() + 15000; while (Date.now() < until) {
  if (phpServer.exitCode !== null) throw Error('The genuine PHP server stopped before readiness.');
  if (await new Promise(resolve => { const socket = net.connect(port, '127.0.0.1'); socket.once('connect', () => { socket.destroy(); resolve(true); }); socket.once('error', () => resolve(false)); })) return;
  await new Promise(resolve => setTimeout(resolve, 50));
} throw Error('The genuine PHP server did not listen.'); };
async function main() {
  try {
    const tlsPort = await freePort(), phpPort = await freePort(), origin = 'https://127.0.0.1:' + tlsPort;
    cli(['prepare', path.join(bundle, 'application'), String(dbPort), root, origin, sourceRevision]); prepared = true;
    const config = privateServerConfig = JSON.parse(fs.readFileSync(serverConfig, 'utf8')); assert.equal(config.origin, origin); assert.equal(config.sourceRevision, sourceRevision);
    const publicationVersion = JSON.parse(fs.readFileSync(path.join(repository, 'desktop-pos/package.json'), 'utf8')).version;
    fs.writeFileSync(runnerConfig, JSON.stringify({ format: 2, kind: 'go-erp-workspace-native-runner', root, bundle, serverConfig, codeRoot: path.join(repository, 'desktop-pos/src'),
      proof: childProof, certificateFingerprint, sourceHashes, publicationVersion }) + '\n', { flag: 'wx' });
    phpServer = spawn(php, [...phpArguments, '-d', 'opcache.enable_cli=0', '-S', '127.0.0.1:' + phpPort, router], { cwd: path.join(bundle, 'application'), env: environment,
      windowsHide: true, shell: false, stdio: ['ignore', 'ignore', 'pipe'] });
    phpServer.stderr.on('data', bytes => { phpError = (phpError + bytes.toString()).slice(-4096);
      for (const line of bytes.toString().split(/\r?\n/)) if (/WORKSPACE_NATIVE_SERVER_ROUTER_FAILED|PHP (?:Fatal|Warning|Parse)|Uncaught|SQLSTATE/.test(line)) {
        phpEvents.push(sanitizedPhp(line)); if (phpEvents.length > 10) phpEvents.shift();
      }
    }); await ready(phpPort);
    const proxy = (request, response) => {
      const target = http.request({ hostname: '127.0.0.1', port: phpPort, method: request.method, path: request.url,
        headers: { ...request.headers, host: new URL(origin).host } }, incoming => { response.writeHead(incoming.statusCode, incoming.headers); incoming.pipe(response); });
      target.on('error', () => response.destroy()); request.pipe(target);
    };
    tls = https.createServer({ key, cert: certificate }, (request, response) => {
      counts.totalRequests++; if (fs.existsSync(path.join(root, 'offline.marker'))) { counts.offlineRequests++; count(); response.writeHead(503).end(); return; } count();
      if (request.url !== '/api/desktop-dashboard/update') { proxy(request, response); return; }
      counts.publicationRequests++; count();
      // Only publication storage is synthetic on Windows. Real group root
      // authentication occurs through the unmodified PHP authority endpoint.
      if (request.method !== 'GET' || request.headers['x-fasakhansta-update-protocol'] !== '2') { response.writeHead(403).end(); return; }
      const check = http.get({ hostname: '127.0.0.1', port: phpPort, path: '/api/desktop-dashboard/workspace/manifest',
        headers: { Host: new URL(origin).host, Accept: 'application/json', Authorization: request.headers.authorization || '' } }, actual => {
        actual.resume(); actual.on('end', () => {
          if (actual.statusCode !== 200) { response.writeHead(403).end(); return; }
          const installerSha256 = crypto.createHash('sha256').update('Synthetic publication; no installer download allowed.').digest('hex');
          const value = { format: 1, state: 'ready', poll_seconds: 60, server_source: config.sourceFingerprint,
            release: { version: publicationVersion, sequence: 1, source_revision: sourceRevision, source: config.sourceFingerprint,
              installer: { bytes: 1, sha256: installerSha256, path: '/api/desktop-dashboard/update/installer/' + installerSha256 } } };
          const wire = JSON.stringify(value); response.writeHead(200, { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(wire) }); response.end(wire);
        });
      }); check.on('error', () => response.destroy());
    });
    await new Promise(resolve => tls.listen(tlsPort, '127.0.0.1', resolve));
    // This child proves a real visible GUI. libuv's windowsHide also supplies
    // SW_HIDE for a GUI process's first ShowWindow; keep only PHP children hidden.
    child = spawn(electron, [path.join(__dirname, 'workspace-runtime-native.cjs'), runnerConfig], { cwd: repository, windowsHide: false, shell: false, stdio: ['ignore', 'pipe', 'pipe'] });
    child.stdout.on('data', bytes => process.stdout.write(bytes)); child.stderr.on('data', bytes => { childDiagnostic = (childDiagnostic + bytes.toString()).slice(-8192); process.stderr.write(bytes); });
    const timeout = setTimeout(() => child.kill(), 15 * 60 * 1000);
    let status; try { status = await new Promise((resolve, reject) => { child.once('error', reject); child.once('close', resolve); }); } finally { clearTimeout(timeout); }
    assert.equal(status, 0, 'Actual shared-workspace native process failed.');
    const proof = JSON.parse(fs.readFileSync(childProof, 'utf8')); assert.equal(proof.completed, true); assert.equal(proof.sourceRevision, sourceRevision);
    assert.deepEqual(proof.sourceHashes, sourceHashes); assert.equal(counts.offlineRequests, 0); assert.ok(counts.publicationRequests >= 1);
    const inspection = JSON.parse(cli(['inspect', serverConfig]));
    proof.serverInspection = inspection; proof.requestCounts = counts;
    assert.equal(inspection.commands.filter(row => row.command_id === proof.originalCommandIds[0] && row.actor_id === 10).length, 1);
    assert.equal(inspection.commands.filter(row => row.command_id === proof.originalCommandIds[1] && row.actor_id === 11).length, 1);
    assert.equal(inspection.customers.filter(row => ['01012345671','01012345672'].includes(row.phone_key)).length, 2);
    assert.equal(inspection.workspaceCount, 1); assert.equal(inspection.custodianActorId, 10);
    fs.mkdirSync(path.dirname(output), { recursive: true }); fs.writeFileSync(output, JSON.stringify(proof, null, 2) + '\n', { flag: 'wx' });
    console.log('SHARED_WORKSPACE_NATIVE_RUNNER_COMPLETE ' + JSON.stringify({ sourceRevision, productionProfile: false }));
  } catch (error) {
    const diagnostic = { format: 2, kind: 'go-erp-workspace-native-diagnostic', sourceRevision, counts,
      cliFailures, native: childDiagnostic.split(/\r?\n/).filter(line => line.startsWith('NATIVE_SHARED_WORKSPACE_FAILED ')).slice(-1),
      phpDiagnostics: phpEvents,
      code: /^[A-Z0-9_]{1,100}$/.test(error.code || '') ? error.code : 'NATIVE_ASSERTION_FAILED' };
    fs.mkdirSync(path.dirname(output), { recursive: true }); fs.writeFileSync(path.join(path.dirname(output), 'workspace-native-diagnostic.json'), JSON.stringify(diagnostic, null, 2) + '\n'); throw error;
  } finally {
    if (child?.exitCode === null) child.kill();
    if (tls) { tls.closeAllConnections(); await new Promise(resolve => tls.close(resolve)); }
    if (phpServer?.exitCode === null) { phpServer.kill(); await new Promise(resolve => phpServer.once('close', resolve)); }
    if (prepared) cli(['cleanup', serverConfig]);
    fs.rmSync(root, { recursive: true, force: true, maxRetries: 30, retryDelay: 100 });
  }
}
main().catch(error => { process.stderr.write(error.message + '\n'); process.exitCode = 1; });
