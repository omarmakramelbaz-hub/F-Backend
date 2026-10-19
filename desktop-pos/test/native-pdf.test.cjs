'use strict';
const test=require('node:test'),assert=require('node:assert/strict');
const fs=require('node:fs/promises'),path=require('node:path'),os=require('node:os');
const pdf=require('../src/native-pdf.cjs');

test('a PDF runtime declaration cannot authorize changed, missing or additional native files',async()=>{
  const declared=pdf.declaration(),files=pdf.packageFiles();
  assert.equal(Object.keys(pdf.policy.files).length,8);
  assert.equal(pdf.policy.archive.sha256,'6ce994c89d5f6018fa158e149a2de8fbbbeb14d8ff5cb656c89893bc556e0557');
  await pdf.verify('unused',declared,files);
  await assert.rejects(pdf.verify('unused',null,files),/pinned source declaration/);
  const changed={...files,'wkhtmltox/bin/wkhtmltopdf.exe':'0'.repeat(64)};
  await assert.rejects(pdf.verify('unused',{...declared,files:changed},changed),/pinned source declaration/);
  for(const actual of [changed,Object.fromEntries(Object.entries(files).slice(1)),{...files,'wkhtmltox/bin/unknown.dll':'1'.repeat(64)}])
    await assert.rejects(pdf.verify('unused',declared,actual),/pinned official files/);
});

test('a prepared PDF payload rejects files outside the exact official release',async t=>{
  const directory=await fs.mkdtemp(path.join(os.tmpdir(),'pdf-payload-'));t.after(()=>fs.rm(directory,{recursive:true,force:true}));
  await fs.writeFile(path.join(directory,'unreviewed.exe'),'MZ synthetic unknown program');
  await assert.rejects(pdf.verifyExtracted(directory),/pinned official release/);
  if(process.platform!=='win32'){
    await fs.rm(path.join(directory,'unreviewed.exe'));
    await fs.symlink(__filename,path.join(directory,'external'));
    await assert.rejects(pdf.verifyExtracted(directory),/Unsafe Windows PDF component path/);
  }
});

test('the downloaded official payload retains complete licenses and fails verification after damage',
  {skip:!process.env.DESKTOP_TEST_WKHTMLTOX},async t=>{
    const bundle=await fs.mkdtemp(path.join(os.tmpdir(),'pdf-packaged-'));t.after(()=>fs.rm(bundle,{recursive:true,force:true}));
    const metadata=await pdf.packageInto(process.env.DESKTOP_TEST_WKHTMLTOX,bundle,path.resolve(__dirname,'../..'));
    assert.equal(metadata.archiveSha256,pdf.policy.archive.sha256);
    assert.ok(Object.keys(metadata.files).some(name=>name.startsWith('wkhtmltox/licenses/')));
    await pdf.verify(bundle,metadata);
    await fs.writeFile(path.join(bundle,'wkhtmltox/include/wkhtmltox/pdf.h'),'modified native header');
    await assert.rejects(pdf.verify(bundle,metadata),/pinned official files/);
  });
