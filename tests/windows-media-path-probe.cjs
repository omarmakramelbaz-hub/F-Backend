'use strict';
// Diagnostic only: imports the immutable failed module without changing it.
// Every writable path is a fresh disposable directory. Reports contain booleans
// and fixed source identity only, never absolute paths or exception messages.
const fs = require('node:fs/promises');
const path = require('node:path');
const os = require('node:os');
const crypto = require('node:crypto');
const { spawn } = require('node:child_process');

const REVISION = '5f2b0fecafec1c3803499284b0abcca4ecfed7e4';
const MODULE_SHA = 'dcb86602d1cf34daf043c1d2d1d1c711e8b3f9f100c8598dabe1fdbc496a1d23';
const PATH_ERROR = 'مسار ملفات الصور يحتاج التحقق؛ بيانات الجهاز محفوظة.';
const digest = data => crypto.createHash('sha256').update(data).digest('hex');
const normalize = value => path.resolve(value).toLowerCase();
const sameNode = (left, right) => left.dev === right.dev && left.ino === right.ino && left.mode === right.mode;
const report = { format: 1, kind: 'go-erp-isolated-windows-media-path-probe',
  sourceRevision: REVISION, originalModuleSha256: MODULE_SHA, completed: false,
  productionModified: false, accountDataRead: false, phase: 'platform' };

function check(value, reason) {
  if (!value) { const error = Error(reason); error.probeReason = reason; throw error; }
}

async function shortPath(directory) {
  const child = spawn('powershell.exe', ['-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass',
    '-File', path.join(__dirname, 'windows-media-short-path.ps1')],
  { windowsHide: true, shell: false, stdio: ['pipe', 'pipe', 'pipe'] });
  let output = '', overflow = false;
  child.stdout.setEncoding('utf8');
  child.stdout.on('data', data => { output += data; if (output.length > 32768) { overflow = true; child.kill(); } });
  child.stderr.resume();
  child.stdin.on('error', () => {}); child.stdin.end(directory);
  const timeout = setTimeout(() => child.kill(), 15000);
  try {
    const code = await new Promise((resolve, reject) => { child.once('error', reject); child.once('close', resolve); });
    check(code === 0 && !overflow && path.isAbsolute(output) && !/[\r\n]/.test(output), 'SHORT_PATH_PROBE_FAILED');
    return output;
  } finally { clearTimeout(timeout); if (child.exitCode === null) child.kill(); }
}

async function main() {
  let lexical, original;
  const modulePath = path.resolve(process.argv[2]);
  const outputPath = path.resolve(process.argv[3] || 'probe-result.json');
  try {
    check(process.platform === 'win32', 'WINDOWS_REQUIRED');
    report.phase = 'module';
    original = await fs.readFile(modulePath);
    check(digest(original) === MODULE_SHA, 'ORIGINAL_MODULE_DIFFERS');
    const media = require(modulePath);
    report.phase = 'temporary-directory';
    lexical = await fs.mkdtemp(path.join(os.tmpdir(), 'go-erp-media-path-probe-'));
    const physical = await fs.realpath(lexical);
    const alias = await shortPath(physical);
    const [lexicalStat, physicalStat, aliasStat] = await Promise.all(
      [lexical, physical, alias].map(value => fs.lstat(value, { bigint: true })));
    report.actualTempAlias = normalize(lexical) !== normalize(physical);
    report.actualTempUsesShortName = /(?:^|[\\/])[^\\/]*~[0-9]+(?:[\\/]|$)/i.test(lexical);
    report.aliasSameNode = sameNode(lexicalStat, physicalStat);
    report.shortAliasAvailable = normalize(alias) !== normalize(physical);
    report.shortAliasSameNode = sameNode(aliasStat, physicalStat);
    report.rootsAreRealDirectories = [lexicalStat, physicalStat, aliasStat]
      .every(item => item.isDirectory() && !item.isSymbolicLink());
    check(report.aliasSameNode && report.shortAliasSameNode && report.rootsAreRealDirectories, 'ALIAS_NODE_DIFFERS');

    const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a6ioAAAAASUVORK5CYII=', 'base64');
    const item = { path: 'products/1/probe.png', sha256: digest(png), bytes: png.length,
      mime: 'image/png', ticket: 'synthetic-local-only' };
    async function download(label, root) {
      const privateRoot = path.join(root, label, 'storage', 'app');
      const publicRoot = path.join(privateRoot, 'public');
      await fs.mkdir(publicRoot, { recursive: true });
      let requests = 0;
      try {
        const result = await media.download(publicRoot, [item], async () => {
          requests++;
          return new Response(png, { headers: { 'content-type': 'image/png', 'content-length': String(png.length) } });
        }, () => false, privateRoot);
        const data = await fs.readFile(path.join(publicRoot, 'products', '1', 'probe.png'));
        return { accepted: result.verified === true && digest(data) === item.sha256,
          pathGuardRejected: false, requests };
      } catch (error) {
        return { accepted: false, pathGuardRejected: error.message === PATH_ERROR, requests };
      }
    }
    report.phase = 'original-download';
    report.lexical = await download('lexical', lexical);
    report.canonical = await download('canonical', physical);
    report.shortAlias = await download('short-alias', alias);
    check(report.canonical.accepted && report.canonical.requests === 1, 'CANONICAL_DOWNLOAD_FAILED');
    check(report.actualTempAlias ? report.lexical.pathGuardRejected && report.lexical.requests === 0
      : report.lexical.accepted, 'TEMP_ALIAS_BEHAVIOR_DIFFERS');
    check(report.shortAliasAvailable ? report.shortAlias.pathGuardRejected && report.shortAlias.requests === 0
      : report.shortAlias.accepted, 'SHORT_ALIAS_BEHAVIOR_DIFFERS');

    report.phase = 'junction-guard';
    const outside = path.join(physical, 'synthetic-outside'), linked = path.join(physical, 'junction');
    await fs.mkdir(path.join(outside, 'public'), { recursive: true });
    await fs.symlink(outside, linked, 'junction');
    let requested = false;
    try {
      await media.download(path.join(linked, 'public'), [item], async () => { requested = true; throw Error('UNEXPECTED_REQUEST'); });
      report.junctionRejected = false;
    } catch (error) { report.junctionRejected = error.message === PATH_ERROR; }
    report.junctionTargetUntouched = (await fs.readdir(path.join(outside, 'public'))).length === 0;
    check(report.junctionRejected && !requested && report.junctionTargetUntouched, 'JUNCTION_GUARD_FAILED');
    await fs.unlink(linked);
    report.phase = 'module-after';
    report.originalModuleUnchanged = digest(await fs.readFile(modulePath)) === MODULE_SHA;
    check(report.originalModuleUnchanged, 'ORIGINAL_MODULE_CHANGED');
    report.phase = 'complete'; report.completed = true;
  } catch (error) {
    report.reason = error.probeReason || 'ISOLATED_PROBE_FAILED';
    process.exitCode = 1;
  } finally {
    if (lexical) await fs.rm(lexical, { recursive: true, force: true }).catch(() => { report.cleanupFailed = true; process.exitCode = 1; });
    report.cleanedUp = !report.cleanupFailed;
    const data = JSON.stringify(report);
    await fs.writeFile(outputPath, data + '\n', { flag: 'wx' });
    process.stdout.write(data + '\n');
  }
}

main().catch(() => { process.stderr.write('ISOLATED_PROBE_REPORT_FAILED\n'); process.exitCode = 1; });
