<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\{DesktopDashboardWorkspaceAuthority,DesktopDashboardWorkspaceBootstrap,
    DesktopDashboardWorkspaceDelta,DesktopDashboardWorkspaceMedia,DesktopDashboardWorkspaceReconciliation,
    DesktopDashboardDeltaCodec,DesktopDashboardDeltaCapacity,DesktopDashboardDelta,DesktopDashboardBootstrap};
use Illuminate\Http\Request;

/** Root bearer authenticates the physical workspace; each command carries its independently checked actor proof. */
final class DesktopDashboardWorkspaceController extends Controller
{
    private function root(Request $request,DesktopDashboardWorkspaceAuthority $authority): object
    {
        abort_if(config('desktop_dashboard.local')===true,403);
        return $authority->authenticate((string)$request->bearerToken());
    }
    public function manifest(Request $request,DesktopDashboardWorkspaceAuthority $authority)
    {
        $manifest=$authority->manifest($this->root($request,$authority));
        return response(DesktopDashboardWorkspaceAuthority::canonical($manifest),200,[
            'Content-Type'=>'application/json','Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff']);
    }
    public function bootstrap(Request $request,DesktopDashboardWorkspaceAuthority $authority,DesktopDashboardWorkspaceBootstrap $bootstrap)
    {
        $snapshot=$bootstrap->export($this->root($request,$authority));
        return response(DesktopDashboardBootstrap::json($snapshot),200,[
            'Content-Type'=>'application/json','Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff']);
    }
    public function delta(Request $request,DesktopDashboardWorkspaceAuthority $authority,DesktopDashboardWorkspaceDelta $delta)
    {
        $root=$this->root($request,$authority);$type=$request->header('Content-Type');
        abort_unless(is_string($type)&&strtolower(trim(explode(';',$type,2)[0]))==='application/octet-stream',415);
        $declared=$request->header('Content-Length');
        abort_if($declared!==null&&(!is_string($declared)||!preg_match('/^[0-9]+$/D',$declared)
            ||strlen($declared)>10||(int)$declared>DesktopDashboardDeltaCodec::MAX_INDEX_BYTES),413);
        return app(DesktopDashboardDeltaCapacity::class)->run(function()use($request,$root,$delta){
            DesktopDashboardDelta::requestBudget();$stream=$request->getContent(true);abort_unless(is_resource($stream),422);
            try{$raw=stream_get_contents($stream,DesktopDashboardDeltaCodec::MAX_INDEX_BYTES+1);}finally{fclose($stream);}
            abort_unless(is_string($raw)&&$raw!==''&&strlen($raw)<=DesktopDashboardDeltaCodec::MAX_INDEX_BYTES,413);
            try{$index=json_decode($raw,true,64,JSON_THROW_ON_ERROR);}catch(\JsonException $error){abort(422);}
            unset($raw);abort_unless(is_array($index),422);
            $bytes=DesktopDashboardBootstrap::json($delta->export($root,$index));
            abort_if(strlen($bytes)>DesktopDashboardDeltaCodec::MAX_RESPONSE_BYTES,413);
            return response($bytes,200,['Content-Type'=>'application/json','Cache-Control'=>'private, no-store',
                'X-Content-Type-Options'=>'nosniff']);
        });
    }
    public function ingest(Request $request,DesktopDashboardWorkspaceAuthority $authority,DesktopDashboardWorkspaceReconciliation $reconciliation)
    {
        return response()->json($reconciliation->ingest($this->root($request,$authority),$request->all()))
            ->header('Cache-Control','private, no-store');
    }
    public function media(Request $request,DesktopDashboardWorkspaceAuthority $authority,DesktopDashboardWorkspaceMedia $media)
    {
        $file=$media->downloadWorkspace($this->root($request,$authority),(string)$request->query('ticket'));
        return response($file['bytes'],200,['Content-Type'=>$file['mime'],'Content-Length'=>(string)strlen($file['bytes']),
            'Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff']);
    }
    public function printOutcome(Request $request,DesktopDashboardWorkspaceAuthority $authority,
        \App\Services\Dashboard\DesktopDashboardWorkspacePrintOwnership $printing)
    {
        return response()->json($printing->outcome($this->root($request,$authority),$request->all()))
            ->header('Cache-Control','private, no-store');
    }
    public function remoteAttempt(Request $request,DesktopDashboardWorkspaceAuthority $authority,
        \App\Services\Dashboard\DesktopDashboardWorkspaceRemoteAttempts $attempts)
    {
        $root=$this->root($request,$authority);
        $values=$request->validate(['actor_id'=>'required|integer|min:1','manifest_revision'=>'required|integer|min:1',
            'grant'=>'required|regex:/^[a-f0-9]{64}$/D','attempt'=>'required|array',
            'attempt.id'=>'required|uuid','attempt.method'=>'required|in:GET,POST,PUT,PATCH,DELETE',
            'attempt.path'=>'required|string|max:200','cancel'=>'required|boolean']);
        abort_if(array_diff(array_keys($request->all()),['actor_id','manifest_revision','grant','attempt','cancel'])
            ||array_diff(array_keys($request->input('attempt',[])),['id','method','path']),422);
        $proof=['actor_id'=>(int)$values['actor_id'],'manifest_revision'=>(int)$values['manifest_revision'],'grant'=>$values['grant']];
        if((bool)$values['cancel'])$result=$attempts->settleWorkspace($root,$proof,$values['attempt']);
        else{
            $scope=$authority->scope($root,$proof['actor_id'],$proof['grant'],$proof['manifest_revision']);
            $result=$attempts->decideWorkspace($scope,$values['attempt'],false);
        }
        return response()->json($result)
            ->header('Cache-Control','private, no-store');
    }
}
