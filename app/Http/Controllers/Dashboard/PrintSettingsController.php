<?php
namespace App\Http\Controllers\Dashboard;
use App\Http\Controllers\Controller;
use App\Services\Dashboard\TakeawayAccess;

class PrintSettingsController extends Controller
{
    public function __construct(){ $this->middleware(function($request,$next){abort_unless(app(TakeawayAccess::class)->canAccess(auth('admin')->user()),403);return $next($request);}); }
    public function index(){return view('admin.print_settings.index');}
    public function test(){return view('admin.print_settings.test');}
}
