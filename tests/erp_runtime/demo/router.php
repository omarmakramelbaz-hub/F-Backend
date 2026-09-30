<?php
if (PHP_SAPI !== 'cli-server' || getenv('ERP_DEMO') !== '1') { http_response_code(404); exit; }
// Never delegate arbitrary paths to PHP's file server or the repository public/index.php.
$path=rawurldecode(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH));
$assets=[
    '/erp/workspace.css'=>['erp/workspace.css','text/css'],
    '/erp/workspace.js'=>['erp/workspace.js','application/javascript'],
    '/dashboard/dist/img/logo image.png'=>['dashboard/dist/img/logo image.png','image/png'],
];
if (isset($assets[$path])) {
    [$file,$mime]=$assets[$path]; header('Content-Type: '.$mime);
    readfile(dirname(__DIR__,3).'/public/'.$file); exit;
}
if (!is_file(__DIR__.'/state/ready.json')) { http_response_code(503); echo 'Trial setup has not completed.'; exit; }
$app=require __DIR__.'/bootstrap.php';

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;

Route::middleware('web')->group(function () {
    Route::get('/', function () { return view('trial'); });
    Route::get('/admin/login', function () { return redirect('/'); });
    Route::get('/demo/health', function () { return response()->json(['kind'=>'fasakhansta-erp-trial']); });
    Route::post('/demo/enter', function (Request $request) {
        $data=$request->validate(['role'=>'required|in:owner,deputy,branch']);
        Auth::guard('admin')->logout(); Auth::guard('erp')->logout();
        $request->session()->invalidate(); $request->session()->regenerateToken();
        if ($data['role']==='owner') { Auth::guard('admin')->loginUsingId(1); }
        else {
            $id=$data['role']==='deputy'?1:2;
            abort_unless(\App\Models\Erp\StaffUser::where('id',$id)->where('active',true)->exists(),403);
            Auth::guard('erp')->loginUsingId($id);
        }
        return redirect()->route('erp.home');
    });
    require dirname(__DIR__,3).'/routes/erp.php';
});
$app['router']->getRoutes()->refreshNameLookups();
$kernel=$app->make(\Illuminate\Contracts\Http\Kernel::class);
$request=Request::capture();
$response=$kernel->handle($request);
if (strpos($response->headers->get('Content-Type',''),'text/html')!==false) {
    $banner='<div style="position:sticky;top:0;z-index:9999;background:#fff1d6;color:#132d3d;padding:10px;text-align:center;font:15px sans-serif" dir="rtl">نسخة تجربة • بيانات وهمية محفوظة داخل تجربتك فقط • <a href="/">تغيير حساب التجربة</a></div>';
    $response->setContent(preg_replace('/(<body\b[^>]*>)/i','$1'.$banner,$response->getContent(),1));
}
$response->headers->set('Cache-Control','no-store, private');
$response->headers->set('X-Robots-Tag','noindex, nofollow');
$response->send(); $kernel->terminate($request,$response);
