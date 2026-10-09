'use strict';
const fs = require('node:fs/promises'), path = require('node:path'), crypto = require('node:crypto');
const { execFileSync } = require('node:child_process');
const RuntimeArchive = require('./src/runtime-archive.cjs');
const sourceCode = require('./src/dashboard-source.cjs');
const sourcePolicy = require('./runtime-source-policy.cjs');
const hash = bytes => crypto.createHash('sha256').update(bytes).digest('hex');
const required = ['application/artisan','application/desktop/router.php','application/desktop/verify.php',
  'application/vendor/autoload.php','application/composer.json','application/composer.lock','php/php.ini'];
const executables = ['php/php.exe','mariadb/bin/mariadbd.exe','mariadb/bin/mariadb-install-db.exe'];
const extensions = ['pdo_mysql','pdo_sqlite','sqlite3','mbstring','sodium','gd','curl','intl','fileinfo','exif','openssl'];
async function verifyBundle(bundle) {
  const archive = new RuntimeArchive(bundle,null), manifest = await archive.manifest(bundle), files = await archive.inventory(bundle);
  for (const name of [...required,...executables,...extensions.map(name=>'php/ext/php_'+name+'.dll')])
    if (!files[name]) throw Error('The installed dashboard runtime is missing '+name);
  for (const name of executables) {
    const bytes = await fs.readFile(path.join(bundle,name)), offset = bytes.length>=64 ? bytes.readUInt32LE(60) : -1;
    if (bytes.toString('ascii',0,2)!=='MZ'||offset<64||offset+26>bytes.length
        ||bytes.toString('ascii',offset,offset+4)!=='PE\0\0'||bytes.readUInt16LE(offset+4)!==0x8664
        ||bytes.readUInt16LE(offset+24)!==0x20b) throw Error('The installed native executable is not Windows x64: '+name);
  }
  for (const [name,value] of Object.entries(manifest.sourceHashes))
    if (files['application/'+name]!==value) throw Error('The packaged original source changed: '+name);
  const actual = await sourceCode.fingerprint(path.join(bundle,'application'));
  if (!sourceCode.same(actual,manifest.sourceFingerprint)) throw Error('The packaged original business code does not match its source binding.');
  return {manifest,files,lockSha256:hash(await fs.readFile(path.join(bundle,'application/composer.lock')))};
}
async function beforePack(context) {
  if(context.electronPlatformName!=='win32'||context.arch!==1) throw Error('The original dashboard installer requires Windows x64.');
  const project = context.packager.projectDir, bundle = path.join(project,'runtime-bundle');
  const packageInfo = JSON.parse(await fs.readFile(path.join(project,'package.json'),'utf8'));
  const verified = await verifyBundle(bundle);
  const checkout = path.dirname(project);
  const revision = execFileSync('git',['rev-parse','HEAD'],{cwd:checkout,encoding:'utf8'}).trim();
  execFileSync('git',['diff-index','--quiet','HEAD','--'],{cwd:checkout});
  if(verified.manifest.sourceRevision!==revision) throw Error('The installer runtime was built from a different source revision.');
  const roots = new Set(['app','bootstrap','config','database','desktop','public','resources','routes']), expected = {};
  const tracked = execFileSync('git',['ls-files','-z'],{cwd:checkout,encoding:'utf8'}).split('\0').filter(Boolean);
  for (const name of tracked) {
    if ((!roots.has(name.split('/')[0]) && name!=='artisan') || sourcePolicy.excludedPath(name)) continue;
    if (!(await fs.lstat(path.join(checkout,name))).isFile()) continue;
    const bytes = await fs.readFile(path.join(checkout,name));
    if (!sourcePolicy.credentialJson(name,bytes)) expected[name]=hash(bytes);
  }
  if (JSON.stringify(Object.keys(expected).sort())!==JSON.stringify(Object.keys(verified.manifest.sourceHashes).sort())
      ||Object.keys(expected).some(name=>expected[name]!==verified.manifest.sourceHashes[name]))
    throw Error('The original runtime does not match the independently verified Git checkout.');
  const audit = JSON.parse(await fs.readFile(path.join(project,'dist/runtime-audit.json'),'utf8'));
  if(audit.format!==1||audit.lockSha256!==verified.lockSha256||!audit.result||typeof audit.result.advisories!=='object')
    throw Error('The runtime dependency audit is missing or belongs to a different lock file.');
  const advisoryCount = Object.values(audit.result.advisories).reduce((sum,items)=>sum+Object.keys(items).length,0);
  const preview = /-preview\.[1-9][0-9]*$/.test(packageInfo.version);
  if(!preview) {
    const review = JSON.parse(await fs.readFile(path.join(project,'dist/release-review.json'),'utf8'));
    if(review.sourceRevision!==revision||review.lockSha256!==verified.lockSha256
        ||!sourceCode.same(review.sourceFingerprint,verified.manifest.sourceFingerprint)
        ||['fullDashboard','dependencies','pdf','installation','updates','conflicts'].some(key=>review[key]!==true)
        ||advisoryCount||Object.keys(audit.result['ignored-advisories']||{}).length)
      throw Error('The complete original dashboard release has not passed its release checks.');
  }
  const receipt={format:1,version:packageInfo.version,channel:preview?'preview':'release',fullDashboard:!preview,
    sourceRevision:revision,sourceFingerprint:verified.manifest.sourceFingerprint,lockSha256:verified.lockSha256,advisoryCount,files:verified.files};
  await fs.mkdir(path.join(project,'dist'),{recursive:true});
  await fs.writeFile(path.join(project,'dist/runtime-package.json'),JSON.stringify(receipt,null,2)+'\n');
  if(!/^[0-9]+\.[0-9]+\.[0-9]+(?:-preview\.[1-9][0-9]*)?$/.test(packageInfo.version)) throw Error('Invalid installer version.');
  const product=context.packager.appInfo?.productName||packageInfo.build?.productName;
  if(!/^[A-Za-z0-9 ._-]{1,80}$/.test(product||'')) throw Error('Invalid installer product name.');
  await fs.writeFile(path.join(project,'dist/installer-version.nsh'),
    '!define FASAKHANSTA_VERSION "'+packageInfo.version+'"\n!define FASAKHANSTA_PRODUCT_NAME "'+product+'"\n'
    +'!define FASAKHANSTA_UNINSTALL_KEY "'+(preview?'FasakhanstaDashboardPreview':'FasakhanstaPOS')+'"\n');
}
async function afterPack(context) {
  const receipt=JSON.parse(await fs.readFile(path.join(context.packager.projectDir,'dist/runtime-package.json'),'utf8'));
  const bundled=await verifyBundle(path.join(context.appOutDir,'resources/dashboard-runtime'));
  const names=Object.keys(receipt.files).sort(),actual=Object.keys(bundled.files).sort();
  if(receipt.sourceRevision!==bundled.manifest.sourceRevision||JSON.stringify(names)!==JSON.stringify(actual)
      ||names.some(name=>receipt.files[name]!==bundled.files[name])) throw Error('The installer did not retain the complete verified dashboard runtime.');
  await fs.writeFile(path.join(context.appOutDir,'resources/desktop-build.json'),JSON.stringify(receipt,null,2)+'\n');
}
module.exports=Object.assign(beforePack,{verifyBundle,beforePack,afterPack});
