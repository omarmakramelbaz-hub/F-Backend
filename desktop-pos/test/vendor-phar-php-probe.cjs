'use strict';
// Explicit actual-PHP probe. No skipped success when PHP is unavailable.
const fs = require('node:fs/promises');
const path = require('node:path');
const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const { build, SCOPE } = require('../build-vendor-phar.cjs');
const sourceCode = require('../src/dashboard-source.cjs');

async function run({ php, application, output }) {
  application = path.resolve(application); output = path.resolve(output);
  await fs.mkdir(output, { recursive: false, mode: 0o700 });
  const artifact = path.join(output, 'artifact');
  const index = await build({ vendor: path.join(application, 'vendor'), target: artifact, php });
  const compact = path.join(output, 'application');
  const excluded = path.join(application, ...SCOPE.split('/').filter(Boolean));
  await fs.cp(application, compact, { recursive: true, dereference: false,
    filter: file => file !== excluded && !file.startsWith(excluded + path.sep) });
  await fs.rename(path.join(compact, 'vendor/autoload.php'), path.join(compact, 'vendor/autoload.original.php'));
  await fs.copyFile(path.join(artifact, 'autoload.php'), path.join(compact, 'vendor/autoload.php'));
  await fs.copyFile(path.join(artifact, 'google-services.phar'), path.join(compact, 'google-services.phar'));
  const code = `
    $application=$argv[1]; $loader=require $application.'/vendor/autoload.php';
    if(realpath($application)===false)throw new RuntimeException('REAL_APPLICATION_PATH_MISSING');
    $classes=['Google\\\\Service\\\\Drive','Google\\\\Service\\\\Sheets','Google\\\\Service\\\\Gmail','Google\\\\Service\\\\Calendar'];
    $mapped=$loader->getClassMap(); $long=[];
    foreach($mapped as $class=>$file)if(str_starts_with($class,'Google\\\\Service\\\\')&&str_starts_with($file,'phar://')){
      $leaf=substr($class,strrpos($class,'\\\\')+1);if(strlen($leaf)>139&&count($long)<5)$long[]=$class;
    }
    foreach(array_merge($classes,$long) as $class){
      if(!class_exists($class))throw new RuntimeException('GOOGLE_CLASS_MISSING');
      $file=(new ReflectionClass($class))->getFileName();if(!str_starts_with($file,'phar://'))throw new RuntimeException('GOOGLE_NOT_PACKED');
    }
    if(!class_exists('Google_Service_Drive'))throw new RuntimeException('LEGACY_GOOGLE_ALIAS_MISSING');
    $framework=(new ReflectionClass(Illuminate\\Foundation\\Application::class))->getFileName();
    if(str_starts_with($framework,'phar://')||realpath($framework)===false)throw new RuntimeException('FRAMEWORK_PATH_CHANGED');
    $client=new Google\\Client(); $drive=new Google\\Service\\Drive($client);
    if(!$drive->files)throw new RuntimeException('GOOGLE_RESOURCE_MISSING');
    $again=require $application.'/vendor/autoload.php';if($again!==$loader)throw new RuntimeException('COMPOSER_LOADER_RECREATED');
    echo json_encode(['actualPhp'=>true,'googleClasses'=>count($classes)+count($long),'legacyAlias'=>true,'googleResource'=>true,
      'frameworkPhysical'=>true,'originalApplicationPhysical'=>true,'sameComposerLoader'=>true,'completed'=>true],JSON_THROW_ON_ERROR);
  `;
  const result = spawnSync(php, ['-d', 'phar.require_hash=1', '-r', code, compact], {
    env: { ...process.env, DESKTOP_DASHBOARD_LOCAL: 'true', DESKTOP_DASHBOARD_VENDOR_PHAR: path.join(compact, 'google-services.phar') },
    encoding: 'utf8', timeout: 60000, maxBuffer: 1024 * 1024, windowsHide: true
  });
  if (result.error || result.status !== 0) {
    process.stderr.write(result.stderr || 'ACTUAL_PHP_PROBE_FAILED\n');
    throw Error('ACTUAL_PHP_COMPACT_VENDOR_PROBE_FAILED');
  }
  const proof = JSON.parse(result.stdout);
  assert.equal(proof.completed, true);
  assert.deepEqual(await sourceCode.fingerprint(compact), await sourceCode.fingerprint(application));
  const receipt = { ...proof, packedFiles: Object.keys(index.files).length, archiveBytes: index.archive.bytes,
    unchangedBusinessSource: true, compactApplication: compact };
  await fs.writeFile(path.join(output, 'proof.json'), JSON.stringify(receipt, null, 2) + '\n');
  process.stdout.write('GO_ERP_COMPACT_GOOGLE_PHP_PROBE ' + JSON.stringify(receipt) + '\n');
  return receipt;
}
module.exports = run;
if (require.main === module) {
  const [php, application, output] = process.argv.slice(2);
  if (!php || !application || !output) throw Error('Usage: vendor-phar-php-probe php application new-output');
  run({ php, application, output }).catch(error => { process.stderr.write(error.message + '\n'); process.exitCode = 1; });
}
