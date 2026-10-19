<?php
namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Services\Site\WebStorefront;
use Illuminate\Http\Request;

class StorefrontController extends Controller
{
    public function index(WebStorefront $store)
    {
        app()->setLocale('ar');
        return response()->view('site.home',['storefrontBranches'=>[],'storefrontInfo'=>$store->publicInfo()])->header('Cache-Control','private, no-store');
    }
    public function branches(Request $request, WebStorefront $store)
    {
        app()->setLocale('ar');
        return response()->json($store->discover($request))->header('Cache-Control','private, no-store');
    }
    public function catalog(Request $request, WebStorefront $store)
    {
        app()->setLocale('ar');
        $v=$request->validate(['branch_id'=>'required|integer|min:1']);
        return response()->json($store->catalog((int)$v['branch_id'],$request))->header('Cache-Control','no-store');
    }
    public function suggestions(Request $request, WebStorefront $store)
    {
        $v=$request->validate(['query'=>'required|string|min:3|max:240']);
        return response()->json($store->suggestions($v['query']))->header('Cache-Control','no-store');
    }
    public function quote(Request $request, WebStorefront $store) {return response()->json($store->quote($request))->header('Cache-Control','no-store');}
    public function checkout(Request $request, WebStorefront $store) {return response()->json($store->checkout($request))->header('Cache-Control','no-store');}
}
