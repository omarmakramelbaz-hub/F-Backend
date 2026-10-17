<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\DesktopPos;
use Illuminate\Http\Request;

class DesktopPosController extends Controller
{
    public function health(Request $request,DesktopPos $pos)
    {
        $pos->device($request->bearerToken()??'');
        return response()->json(['ok'=>true,'time'=>now('UTC')->toIso8601String()])->header('Cache-Control','no-store');
    }
    public function pair(Request $request,DesktopPos $pos)
    {
        $v=$request->validate(['code'=>'required|string|min:16|max:40']);
        return response()->json($pos->pair($v['code']))->header('Cache-Control','no-store');
    }
    public function snapshot(Request $request,DesktopPos $pos)
    {
        return response()->json($pos->snapshot($pos->device($request->bearerToken()??'')))->header('Cache-Control','no-store');
    }
    public function sync(Request $request,DesktopPos $pos)
    {
        $request->validate(['event'=>'required|array']);
        return response()->json($pos->ingest($pos->device($request->bearerToken()??''),$request->input('event')))->header('Cache-Control','no-store');
    }
}
