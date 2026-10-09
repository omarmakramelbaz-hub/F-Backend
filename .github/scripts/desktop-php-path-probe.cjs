'use strict';
// Diagnose the exact bundled PHP before spending time compiling another installer.
const fs = require('node:fs/promises');
const path = require('node:path');
const os = require('node:os');
const {spawnSync} = require('node:child_process');
const assert = require('node:assert/strict');

(async () => {
  assert.equal(process.platform, 'win32');
  const source = path.resolve(process.argv[2]);
  const root = await fs.mkdtemp(path.join(os.tmpdir(), 'fasakhansta-php-path-'));
  const modules = ['pdo_mysql','pdo_sqlite','sqlite3','mbstring','sodium','gd','curl','intl','fileinfo','exif','openssl'];
  const code = 'echo json_encode(["directory"=>ini_get("extension_dir"),"charset"=>ini_get("default_charset"),"modules"=>array_map("extension_loaded",' + JSON.stringify(modules).replace('[','array(').replace(']',')') + ')]);';
  try {
    const phpRoot = path.join(root, 'تجربة البرنامج~1', 'php');
    await fs.cp(source, phpRoot, {recursive:true});
    const executable = path.join(phpRoot, 'php.exe');
    const extensions = path.join(phpRoot, 'ext');
    const quoted = '"' + extensions.replaceAll('\\', '/') + '"';
    const ini = path.join(root, 'quoted-extensions.ini');
    await fs.writeFile(ini, 'extension_dir=' + quoted + '\n' + modules.map(name=>'extension=' + name).join('\n') + '\n');
    const variants = [
      ['unquoted-command-line', ['-n','-d','extension_dir=' + extensions, ...modules.flatMap(name=>['-d','extension=' + name])]],
      ['quoted-command-line', ['-n','-d','extension_dir=' + quoted, ...modules.flatMap(name=>['-d','extension=' + name])]],
      ['quoted-configuration-file', ['-c', ini]],
      ['relative-extension-directory', ['-n','-d','extension_dir=ext', ...modules.flatMap(name=>['-d','extension=' + name])], phpRoot],
      ['verified-source-control', ['-n','-d','extension_dir="' + path.join(source,'ext').replaceAll('\\','/') + '"', ...modules.flatMap(name=>['-d','extension=' + name])]],
    ];
    const results = variants.map(([name,args,cwd]) => {
      const result = spawnSync(executable, [...args,'-r',code], {encoding:'utf8',windowsHide:true,timeout:30000,cwd});
      let settings; try { settings = JSON.parse(result.stdout.slice(result.stdout.lastIndexOf('{'))); } catch {}
      return {name,status:result.status,settings,allModules:settings?.modules?.length === modules.length && settings.modules.every(Boolean),stdout:result.stdout,stderr:result.stderr,error:result.error?.message};
    });
    process.stdout.write(JSON.stringify({modules,results},null,2) + '\n');
    assert.equal(results.find(result=>result.name==='verified-source-control').allModules, true, 'Every extension must load from the independently verified source.');
    assert.equal(results.find(result=>result.name==='relative-extension-directory').allModules, true, 'Every bundled extension must load from the actual Arabic PHP directory.');
  } finally { await fs.rm(root,{recursive:true,force:true}); }
})().catch(error=>{process.stderr.write(error.stack + '\n');process.exitCode=1;});
