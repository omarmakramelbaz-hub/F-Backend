'use strict';
// Prepare a separate fixture target. Original Laravel 8 fixtures remain unchanged.
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto');
const {execFileSync}=require('node:child_process');
const baseline='e62516840259cc85ea168b7245bcd5e91909ce78'; // Actual local Linux packaging provenance.
const baselineTree='0fcd11fe0ec2426ed3966cb13a96d36de97add86'; // Same reviewed tree published as a5af2b44.
const hashes={
  'composer.json':'c7f7f6ac9b766b9282be10dacfbc25c367600ad550488802b24e75b4dfc122c1',
  'composer.lock':'f0fe7a09c3736dac4cddf120f14f9ca3a5c6553a1ff7ecc2e37f4be0e5cbee75',
};
const originals={
  'run.php':'7f086296c4447bee2726636314d111661070034791f196c01e8740133d5c0961',
  'legacy.php':'ffff724591592576a22f917d120da23a02d4ab60fc2280f574b8d1ff67093100',
  'remote-attempts.php':'0519f32168fa7c19d4c25c8a4a9c1cdaaa34d3a15983be26812987cc95101cad',
  'native-delete-form-contract.php':'29c2470e2644cf66597882b2c91a63df63e9187a1590de1fa0658dca335c58cc',
};
const hash=bytes=>crypto.createHash('sha256').update(bytes).digest('hex');
const readJson=file=>JSON.parse(fs.readFileSync(file,'utf8'));
const replaceOnce=(text,before,after)=>{
  assert.equal(text.split(before).length,2,'The reviewed original fixture anchor changed.');
  return text.replace(before,after);
};

function prepare(repository,application,destination,revision,php){
  for(const value of [repository,application,destination,revision,php])assert.ok(value);
  repository=path.resolve(repository);application=path.resolve(application);destination=path.resolve(destination);
  let existingParent=path.dirname(destination);
  while(!fs.existsSync(existingParent))existingParent=path.dirname(existingParent);
  const physicalDestination=path.join(fs.realpathSync(existingParent),path.relative(existingParent,destination));
  for(const root of [repository,application]){
    const physicalRoot=fs.realpathSync(root),relative=path.relative(physicalRoot,physicalDestination);
    assert.ok(relative==='..'||relative.startsWith('..'+path.sep)||path.isAbsolute(relative),'Private fixtures must be outside source and application.');
  }
  const git=(args,options={})=>execFileSync('git',args,{cwd:repository,maxBuffer:128*1024**2,...options});
  assert.equal(git(['rev-parse','HEAD']).toString().trim(),revision);
  assert.equal(git(['status','--porcelain']).toString(),'','Candidate source must be a clean frozen commit.');
  const ancestorTrees=git(['log','--format=%T',revision]).toString().trim().split('\n');
  assert.ok(ancestorTrees.includes(baselineTree),'The reviewed original fixture tree is absent from source ancestry.');
  assert.equal(fs.existsSync(destination),false,'Private fixture destination must be new.');
  for(const [name,digest]of Object.entries(hashes)){
    assert.equal(hash(fs.readFileSync(path.join(application,name))),digest,'Actual candidate input changed: '+name);
    assert.equal(hash(git(['show',revision+':.github/diagnostics/laravel12/'+name])),digest);
  }
  const originalInputs={'composer.json':'26b487cf7267c2cf9d98818766e8078ebc776a8e8062fcff8571932f44c50306','desktop-pos/runtime/composer.json':'06f9654c0ffe566d8b04fb23bc207504f07192780155adc7fae7d7239c6fcbd6','desktop-pos/runtime/composer.lock':'319ea5f627a46681c45e57c4e780f2d1c19a80f0272711ccf770feb3716d18a7'};
  assert.equal(fs.existsSync(path.join(repository,'composer.lock')),false);
  for(const [name,digest]of Object.entries(originalInputs))assert.equal(hash(fs.readFileSync(path.join(repository,name))),digest,'Original dependency input changed: '+name);
  const locked=readJson(path.join(application,'composer.lock')).packages;
  const installedData=readJson(path.join(application,'vendor/composer/installed.json'));
  const installed=Array.isArray(installedData)?installedData:installedData.packages;
  const versions=rows=>Object.fromEntries(rows.map(row=>[row.name,row.version]));
  assert.equal(locked.length,139);assert.equal(installed.length,139);
  assert.equal(Object.keys(versions(installed)).length,139);assert.deepEqual(versions(installed),versions(locked));
  const proofCode=`$root=$argv[1];require $root.'/vendor/autoload.php';$lock=json_decode(file_get_contents($root.'/composer.lock'),true,512,JSON_THROW_ON_ERROR);$versions=[];foreach($lock['packages'] as $row)$versions[$row['name']]=\\Composer\\InstalledVersions::getPrettyVersion($row['name']);echo json_encode(['versions'=>$versions,'laravel'=>\\Illuminate\\Foundation\\Application::VERSION,'collectivePresent'=>class_exists('Collective\\\\Html\\\\FormBuilder'),'files'=>['framework'=>(new ReflectionClass(\\Illuminate\\Foundation\\Application::class))->getFileName(),'permission'=>(new ReflectionClass(\\Spatie\\Permission\\Models\\Role::class))->getFileName(),'nativeDelete'=>(new ReflectionClass(\\App\\Support\\NativeDeleteForm::class))->getFileName(),'roles'=>(new ReflectionClass(\\App\\Http\\Controllers\\Dashboard\\RolesController::class))->getFileName()]],JSON_THROW_ON_ERROR);`;
  const actual=JSON.parse(execFileSync(php,['-r',proofCode,application],{encoding:'utf8',maxBuffer:1024**2}));
  assert.deepEqual(actual.versions,versions(locked));assert.equal(actual.laravel,'12.69.3');assert.equal(actual.collectivePresent,false);
  for(const [name,file]of Object.entries(actual.files)){
    const expected={framework:'vendor/laravel/framework/src/Illuminate/Foundation/Application.php',permission:'vendor/spatie/laravel-permission/src/Models/Role.php',nativeDelete:'app/Support/NativeDeleteForm.php',roles:'app/Http/Controllers/Dashboard/RolesController.php'}[name];
    assert.equal(fs.realpathSync(file),fs.realpathSync(path.join(application,expected)));
  }
  const manifest=readJson(path.join(application,'../manifest.json'));
  assert.ok([baseline,revision].includes(manifest.sourceRevision),'App packaging must bind this frozen source or its reviewed fresh baseline.');
  const names=Object.keys(manifest.sourceHashes);assert.equal(names.length,2245);
  const batch=git(['cat-file','--batch'],{input:names.map(name=>revision+':'+name+'\n').join('')});
  const sourceHashes={};let offset=0;
  for(const name of names){
    const end=batch.indexOf(10,offset),header=batch.subarray(offset,end).toString().split(' ');
    assert.equal(header[1],'blob');const size=Number(header[2]);assert.ok(Number.isSafeInteger(size)&&size>=0);
    offset=end+1;const bytes=batch.subarray(offset,offset+size);offset+=size+1;
    sourceHashes[name]=hash(bytes);assert.equal(hash(fs.readFileSync(path.join(application,name))),sourceHashes[name],'Actual app differs from frozen source: '+name);
  }
  assert.equal(offset,batch.length);
  if(manifest.sourceRevision===revision)assert.deepEqual(manifest.sourceHashes,sourceHashes);
  else assert.equal(hash(Object.keys(manifest.sourceHashes).sort().map(name=>name+'\0'+manifest.sourceHashes[name]+'\n').join('')),'48f80de58494dbea65d3e135fbfd071e12dec2b6fe010d57a87c24fcc3cd6424','Fresh baseline metadata changed.');
  const sourceContentSha256=hash(names.sort().map(name=>name+'\0'+sourceHashes[name]+'\n').join(''));
  assert.equal(sourceContentSha256,'946d76f57a8798fd5ff2c5b4fa98cf8c29c804bbab6abf6f51898d9e8f3cf2ce');
  const prefix='tests/desktop_dashboard_runtime/';
  const fixtureNames=git(['ls-tree','-r','--name-only',baselineTree,'--','tests/desktop_dashboard_runtime','desktop-pos/src/dashboard-source.cjs','deployment/desktop_dashboard_inspect.php']).toString().trim().split('\n');
  const files=Object.fromEntries(fixtureNames.map(name=>[name,git(['show',baselineTree+':'+name])]));
  for(const [name,bytes]of Object.entries(files))assert.equal(hash(git(['show',revision+':'+name])),hash(bytes),'The original default fixture was modified: '+name);
  for(const [name,digest]of Object.entries(originals))assert.equal(hash(files[prefix+name]),digest);
  const changes={};const patch=(name,before,after)=>{
    const key=prefix+name;files[key]=Buffer.from(replaceOnce(files[key].toString(),before,after));
    changes[key]={originalSha256:hash(git(['show',baselineTree+':'+key])),privateSha256:hash(files[key])};
  };
  patch('run.php',"$app->version()==='8.83.29' && count($app['router']->getRoutes())>=565","$app->version()==='12.69.3' && count($app['router']->getRoutes())>=565");
  patch('legacy.php',"    if(getenv('DESKTOP_TEST_BROWSER_MODULE')){",`    // Candidate target: original login now regenerates CSRF; read the authenticated rendered token.
    preg_match('/name="csrf-token" content="([^"]+)"/',$page,$csrf);
    if(empty($csrf[1]))throw new RuntimeException('The original authenticated catalog page has no current CSRF token.');
    echo 'CANDIDATE_AUTHENTICATED_CSRF_READY local-owner'.PHP_EOL;
    if(getenv('DESKTOP_TEST_BROWSER_MODULE')){`);
  patch('remote-attempts.php',"    require __DIR__.'/category-order-remote.php';",`    [$candidateAuthStatus,$candidateAuthPage]=$http('/admin/products/create');
    preg_match('/name="csrf-token" content="([^"]+)"/',$candidateAuthPage,$serverCsrf);
    if($candidateAuthStatus!==200||empty($serverCsrf[1]))throw new RuntimeException('The original authenticated server page has no current CSRF token.');
    echo 'CANDIDATE_AUTHENTICATED_CSRF_READY remote-owner'.PHP_EOL;
    require __DIR__.'/category-order-remote.php';`);
  patch('remote-attempts.php',"    $foreignForm=$form;$foreignForm['_token']=$foreignCsrf[1];",`    [$candidateAuthStatus,$candidateAuthPage]=$http('/admin/takeaway');
    preg_match('/name="csrf-token" content="([^"]+)"/',$candidateAuthPage,$foreignCsrf);
    if($candidateAuthStatus!==200||empty($foreignCsrf[1]))throw new RuntimeException('The original authenticated foreign-account page has no current CSRF token.');
    echo 'CANDIDATE_AUTHENTICATED_CSRF_READY remote-foreign'.PHP_EOL;
    $foreignForm=$form;$foreignForm['_token']=$foreignCsrf[1];`);
  patch('native-delete-form-contract.php',"        verify($actual === $expected['dom'],",`        // Pinned Laravel 12 adds this exact attribute in its real csrf_field helper.
        $expectedTargetDom=$expected['dom'];
        foreach($expectedTargetDom['inputs'] as &$expectedTargetInput){
            if(($expectedTargetInput['name']??null)==='_token'){
                $expectedTargetInput['autocomplete']='off';ksort($expectedTargetInput);
            }
        }
        unset($expectedTargetInput);
        verify($actual === $expectedTargetDom,`);
  // Write only after source, actual autoloaded versions and every input guard passes.
  for(const [name,bytes]of Object.entries(files)){
    const file=path.join(destination,name);fs.mkdirSync(path.dirname(file),{recursive:true});fs.writeFileSync(file,bytes);
  }
  const receipt={format:1,kind:'isolated-laravel12-original-suite-target',source:{commit:revision,tree:git(['rev-parse',revision+'^{tree}']).toString().trim(),freshPackagingCommit:manifest.sourceRevision,sourceFiles:2245,sourceContentSha256},candidate:{inputs:hashes,actualInstalledPackages:139,versions:actual.versions,laravel:actual.laravel,collectivePresent:false},fixture:{baselineTree,linuxBaselineCommit:baseline,files:fixtureNames.length,changes,originalAssertionsPreserved:true,originalDefault8GuardUnchanged:true},status:{vendorInstalled:true,applicationMigrated:false,applicationValidated:false,acceptedRelease:false,fullDashboard:false},limitations:['This prepares private fixtures; it does not execute or accept either suite.','Linux and Windows execution evidence must remain separate.','Provider credentials, route:list and full dashboard/browser/installer acceptance remain outside this proof.']};
  fs.writeFileSync(path.join(destination,'candidate-fixture-receipt.json'),JSON.stringify(receipt,null,2)+'\n');
  console.log('LARAVEL12_PRIVATE_FIXTURES_PREPARED '+fixtureNames.length+' original files; exact139 graph; original8 baseline retained.');
  return receipt;
}
module.exports={prepare};
if(require.main===module){try{prepare(...process.argv.slice(2));}catch(error){console.error(error.stack);process.exitCode=1;}}
