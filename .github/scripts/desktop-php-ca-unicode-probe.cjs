'use strict';
// Public TLS requests only. A readable CA pathname does not prove TLS can use it.
const fs = require('node:fs/promises');
const path = require('node:path');
const os = require('node:os');
const crypto = require('node:crypto');
const assert = require('node:assert/strict');
const {spawnSync} = require('node:child_process');
const packaging = require('../../desktop-pos/installer-runtime.cjs');
const RuntimeArchive = require('../../desktop-pos/src/runtime-archive.cjs');

const modules = ['pdo_mysql','pdo_sqlite','sqlite3','mbstring','sodium','gd','curl','intl','fileinfo','exif','openssl'];
const phpCode = String.raw`
$modules=json_decode('${JSON.stringify(modules)}',true);
$settings=['extension_dir'=>ini_get('extension_dir'),'curlCA'=>ini_get('curl.cainfo'),
 'opensslCA'=>ini_get('openssl.cafile'),'modules'=>array_map('extension_loaded',$modules)];
$curl=['ok'=>false,'errno'=>null,'error'=>'curl unavailable'];
if(extension_loaded('curl')){
 $handle=curl_init('https://github.com/');
 curl_setopt_array($handle,[CURLOPT_NOBODY=>true,CURLOPT_RETURNTRANSFER=>true,
  CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_TIMEOUT=>25,
  CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
  CURLOPT_USERAGENT=>'Fasakhansta-public-CA-portability-probe']);
 $response=curl_exec($handle);
 $status=curl_getinfo($handle,CURLINFO_RESPONSE_CODE);
 $verify=curl_getinfo($handle,CURLINFO_SSL_VERIFYRESULT);
 $curl=['ok'=>$response!==false && $status>=100 && $status<=599 && $verify===0,
  'errno'=>curl_errno($handle),'error'=>curl_error($handle),'status'=>$status,
  'verifyResult'=>$verify,'tlsBackend'=>curl_version()['ssl_version'],
  'verifyPeer'=>true,'verifyHost'=>2];
 curl_close($handle);
}
$warnings=[];
set_error_handler(function($severity,$message)use(&$warnings){$warnings[]=$message;return true;});
$context=stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,
 'peer_name'=>'github.com','SNI_enabled'=>true,'capture_peer_cert'=>true]]);
$socket=stream_socket_client('tls://github.com:443',$errno,$error,25,STREAM_CLIENT_CONNECT,$context);
restore_error_handler();
$stream=['ok'=>false,'errno'=>$errno,'error'=>$error,'warnings'=>$warnings,
 'verifyPeer'=>true,'verifyPeerName'=>true,'peerName'=>'github.com'];
if($socket!==false){
 $metadata=stream_get_meta_data($socket);
 $options=stream_context_get_options($socket);
 $certificate=$options['ssl']['peer_certificate']??null;
 $details=$certificate===null?false:openssl_x509_parse($certificate);
 $stream['crypto']=$metadata['crypto']??null;
 $stream['subject']=$details['subject']['CN']??null;
 $stream['issuer']=$details['issuer']['CN']??null;
 $stream['ok']=$certificate!==null && $details!==false && !empty($stream['crypto']);
 fclose($socket);
}
echo json_encode(['php'=>PHP_VERSION,'settings'=>$settings,'curl'=>$curl,'stream'=>$stream],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
`;

(async () => {
  assert.equal(process.platform,'win32','This diagnostic requires the exact Windows PHP bundle.');
  const source=path.resolve(process.argv[2]);
  const output=path.resolve(process.argv[3]);
  const verified=await packaging.verifyBundle(source);
  const expected=Object.fromEntries(Object.entries(verified.files).filter(([name])=>name.startsWith('php/')).map(([name,hash])=>[name.slice(4),hash]));
  const certificateBytes=await fs.readFile(path.join(source,'php/ssl/cacert.pem'),'utf8');
  const certificates=certificateBytes.match(/-----BEGIN CERTIFICATE-----[\s\S]*?-----END CERTIFICATE-----/g)||[];
  assert.ok(certificates.length>0,'The original public CA bundle is required.');
  assert.ok(!certificateBytes.includes('PRIVATE KEY'),'Only the original public certificates may be used.');
  for(const certificate of certificates)new crypto.X509Certificate(certificate);
  const root=await fs.mkdtemp(path.join(os.tmpdir(),'fasakhansta-ca-path-'));
  const results=[];
  let passed=false;
  try{
    const scan=path.join(root,'empty-ini-scan');await fs.mkdir(scan);
    for(const [name,directory]of [['english','english-control'],['arabic','تجربة شهادة البرنامج~1']]){
      const base=path.join(root,directory),php=path.join(base,'php'),application=path.join(base,'application');
      await fs.cp(path.join(source,'php'),php,{recursive:true,errorOnExist:true,force:false});
      const actual=await new RuntimeArchive(php,null).inventory(php);
      assert.deepEqual(Object.keys(actual).sort(),Object.keys(expected).sort(),name+' clone inventory changed');
      for(const [file,hash]of Object.entries(expected))assert.equal(actual[file],hash,name+' clone changed '+file);
      await fs.mkdir(application);
      for(const valid of [true,false]){
        const ca=path.join(php,'ssl',valid?'cacert.pem':'missing-certificate.pem').replaceAll('\\','/');
        const ini='extension_dir="../php/ext"\ndefault_charset=UTF-8\n'+modules.map(module=>'extension='+module).join('\n')
          +'\ncurl.cainfo="'+ca+'"\nopenssl.cafile="'+ca+'"\n';
        await fs.writeFile(path.join(application,'ca-probe.ini'),ini,'utf8');
        const child=spawnSync(path.join(php,'php.exe'),['-c','ca-probe.ini','-r',phpCode],
          {cwd:application,env:{...process.env,PHP_INI_SCAN_DIR:scan},encoding:'utf8',windowsHide:true,timeout:75000,maxBuffer:1024*1024});
        let data;try{data=JSON.parse(child.stdout);}catch{}
        const result={name:name+(valid?'-original-ca':'-missing-ca-control'),validCA:valid,expectedCA:ca,status:child.status,php:data?.php,
          settings:data?.settings,curl:data?.curl,stream:data?.stream,error:child.error?.message,stderr:child.stderr,stdout:data?undefined:child.stdout};
        results.push(result);
        process.stdout.write(JSON.stringify(result,null,2)+'\n');
      }
    }
    // Collect both path variants before assertions so a failed control cannot
    // hide the corresponding Arabic-path handshake diagnostics.
    for(const result of results){
      assert.equal(result.status,0,result.stderr||result.error);
      assert.ok(result.settings,'The actual PHP subprocess must return its TLS result.');
      assert.match(result.php,/^8\.2\./);
      assert.equal(result.settings.curlCA,result.expectedCA);assert.equal(result.settings.opensslCA,result.expectedCA);
      assert.deepEqual(result.settings.modules,modules.map(()=>true),'Every original extension must load from the copied PHP bundle.');
      if(result.validCA){
        assert.equal(result.curl.ok,true,result.name+' must perform a real cURL HTTPS request with peer and hostname verification.');
        assert.equal(result.stream.ok,true,result.name+' must complete a real OpenSSL stream TLS handshake with peer and name verification.');
      }else{
        assert.equal(result.curl.ok,false,'cURL must not bypass the configured missing CA file.');
        assert.equal(result.curl.errno,77,'The negative control must fail because its CA file cannot be loaded.');
        assert.equal(result.stream.ok,false,'OpenSSL must not bypass the configured missing CA file.');
        assert.ok(result.stream.warnings.some(warning=>warning.includes('missing-certificate.pem')),'The negative OpenSSL control must report its configured missing CA file.');
      }
    }
    passed=true;
  }finally{
    await fs.writeFile(output,JSON.stringify({format:1,passed,testedRun:38000617682,
      testedArchiveSha256:'66c8e7be5ca3d5590105bc6833af4242a95e7bfc6e9d1599a08fb72661bcdfff',sourceRevision:verified.manifest.sourceRevision,
      verifiedRuntimeFiles:Object.keys(verified.files).length,verifiedPhpFiles:Object.keys(expected).length,
      publicCertificates:certificates.length,results},null,2)+'\n');
    await fs.rm(root,{recursive:true,force:true});
  }
  process.stdout.write('PASS exact bundled PHP performs verified public TLS with the original absolute CA path in English and Arabic directories, and rejects missing CA files.\n');
})().catch(error=>{process.stderr.write(error.stack+'\n');process.exitCode=1;});
