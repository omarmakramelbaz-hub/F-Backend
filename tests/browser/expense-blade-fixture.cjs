'use strict';
const cp=require('node:child_process'),path=require('node:path'),assert=require('node:assert/strict');
module.exports=function renderExpense(boot){
 assert(process.env.CRM_TEST_AUTOLOAD,'The production-version Blade fixture requires an isolated autoloader');
 const root=path.resolve(__dirname,'../..');
 const php=String.raw`function config($key,$default=null){return $default;}function __($key){$labels=$GLOBALS['expenseLabels'];return $labels[substr($key,9)]??$key;}function csrf_token(){return 'fixture';}require getenv('CRM_TEST_AUTOLOAD');$root=getenv('QA_ROOT');$boot=json_decode(getenv('QA_BOOT'),true,512,JSON_THROW_ON_ERROR);$GLOBALS['expenseLabels']=require $root.'/resources/lang/ar/expenses.php';$blade=explode('@endsection',explode("@section('content')",file_get_contents($root.'/resources/views/admin/expenses/index.blade.php'),2)[1],2)[0];$compiler=new \Illuminate\View\Compilers\BladeCompiler(new \Illuminate\Filesystem\Filesystem,sys_get_temp_dir());$__env=new class{use \Illuminate\View\Concerns\ManagesLoops;};ob_start();eval('?>'.$compiler->compileString($blade));echo ob_get_clean();`;
 return cp.execFileSync(process.env.CRM_TEST_PHP||'php',['-r',php],{encoding:'utf8',env:{...process.env,QA_ROOT:root,QA_BOOT:JSON.stringify(boot)}});
};
