<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\{DesktopDashboardDevices,DesktopDashboardReconciliation,DesktopDashboardBootstrap,DesktopDashboardMedia,DesktopDashboardDelta,DesktopDashboardDeltaCodec};
use Illuminate\Http\Request;

class DesktopDashboardController extends Controller
{
    public function remoteAttempt(Request $request,DesktopDashboardDevices $devices,\App\Services\Dashboard\DesktopDashboardRemoteAttempts $attempts)
    {
        $device=$devices->device((string)$request->bearerToken());
        $request->validate(['action'=>'required|in:reserve,settle']);
        return response()->json($attempts->decide($device,$request->all(),$request->input('action')==='settle'))->header('Cache-Control','private, no-store');
    }
    public function bootstrap(Request $request,DesktopDashboardDevices $devices,DesktopDashboardBootstrap $bootstrap)
    {
        $device=$devices->device((string)$request->bearerToken());
        return response(DesktopDashboardBootstrap::json($bootstrap->export($device)),200,['Content-Type'=>'application/json','Cache-Control'=>'private, no-store']);
    }
    public function delta(Request $request,DesktopDashboardDevices $devices,DesktopDashboardDelta $delta)
    {
        $device=$devices->device((string)$request->bearerToken());
        // Keep this opaque to global JSON-normalization middleware. Its decoded
        // size budget and exact byte limits belong to this authenticated route.
        $type=$request->header('Content-Type');
        abort_unless(is_string($type)&&strtolower(trim(explode(';',$type,2)[0]))==='application/octet-stream',415,
            'نوع طلب مزامنة التعديلات غير صالح؛ بيانات الجهاز محفوظة.');
        $declared=$request->header('Content-Length');
        abort_if($declared!==null&&(!is_string($declared)||!preg_match('/^[0-9]+$/D',$declared)
            ||strlen($declared)>10||(int)$declared>DesktopDashboardDeltaCodec::MAX_INDEX_BYTES),413,
            'وصف التعديلات يتجاوز حد المزامنة؛ بيانات الجهاز محفوظة.');
        return app(\App\Services\Dashboard\DesktopDashboardDeltaCapacity::class)->run(function()use($request,$device,$delta){
            DesktopDashboardDelta::requestBudget();
            $stream=$request->getContent(true);
            abort_unless(is_resource($stream),422,'وصف مزامنة التعديلات غير صالح؛ بيانات الجهاز محفوظة.');
            try{$raw=stream_get_contents($stream,DesktopDashboardDeltaCodec::MAX_INDEX_BYTES+1);}finally{fclose($stream);}
            abort_unless(is_string($raw)&&strlen($raw)>0&&strlen($raw)<=DesktopDashboardDeltaCodec::MAX_INDEX_BYTES,413,
                'لم يصل وصف التعديلات كاملًا؛ بيانات الجهاز محفوظة.');
            try{$index=json_decode($raw,true,64,JSON_THROW_ON_ERROR);}catch(\JsonException $error){abort(422,'وصف مزامنة التعديلات غير صالح؛ بيانات الجهاز محفوظة.');}
            unset($raw);
            abort_unless(is_array($index),422,'وصف مزامنة التعديلات غير صالح؛ بيانات الجهاز محفوظة.');
            $bytes=DesktopDashboardBootstrap::json($delta->export($device,$index));
            abort_if(strlen($bytes)>DesktopDashboardDeltaCodec::MAX_RESPONSE_BYTES,413,'التعديلات تحتاج دفعات أصغر؛ بيانات الجهاز محفوظة.');
            return response($bytes,200,['Content-Type'=>'application/json','Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff']);
        });
    }
    public function ingest(Request $request,DesktopDashboardDevices $devices,DesktopDashboardReconciliation $reconciliation)
    {
        $device=$devices->device((string)$request->bearerToken());
        return response()->json($reconciliation->ingest($device,$request->all()))->header('Cache-Control','private, no-store');
    }
    public function printOutcome(Request $request,DesktopDashboardDevices $devices,\App\Services\Dashboard\DesktopDashboardPrintOwnership $printing)
    {
        $device=$devices->device((string)$request->bearerToken());
        return response()->json($printing->outcome($device,$request->all()))->header('Cache-Control','private, no-store');
    }
    public function media(Request $request,DesktopDashboardDevices $devices,DesktopDashboardMedia $media)
    {
        $device=$devices->device((string)$request->bearerToken());
        $file=$media->download($device,(string)$request->query('ticket'));
        return response($file['bytes'],200,['Content-Type'=>$file['mime'],'Content-Length'=>(string)strlen($file['bytes']),
            'Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff']);
    }
}
