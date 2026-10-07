<?php
namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\{DesktopPos, DesktopPosInstaller, TakeawayAccess};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Schema};

class DesktopPosController extends Controller
{
    public function index(Request $request, TakeawayAccess $access, DesktopPosInstaller $uploads)
    {
        $actor=auth('admin')->user(); abort_unless($access->canAccess($actor),403);
        $branches=array_values(array_filter($access->branches($actor),fn($b)=>$b['kind']==='f')); $values=array_column($branches,'value');
        $selected=$request->query('branch',$values[0]??''); abort_unless(in_array($selected,$values,true),403);
        $ready=config('desktop_pos.enabled')&&Schema::hasTable('desktop_pos_operations');
        $devices=$ready?DB::table('desktop_pos_devices')->where('branch',$selected)->select('id','name','actor_id','enabled','last_seen_at')->get():collect();
        // No pairing hashes or device credentials are rendered into the dashboard.
        $operations=$ready?DB::table('desktop_pos_operations')->where('branch',$selected)->orderByDesc('id')->paginate(30):null;
        $installer=is_file(config('desktop_pos.installer'));
        $canUpload=$uploads->canUpload($actor,$access);
        return view('admin.desktop_pos.index',compact('branches','selected','devices','operations','ready','installer','canUpload'));
    }
    public function issue(Request $request,DesktopPos $pos)
    {
        $v=$request->validate(['branch'=>'required|string|max:40','name'=>'required|string|max:100']);
        return redirect()->route('desktop-pos.index',['branch'=>$v['branch']])->with('desktop_pair_code',$pos->issue($v['branch'],trim($v['name']),auth('admin')->user()));
    }
    public function revoke(Request $request,TakeawayAccess $access)
    {
        app(DesktopPos::class)->ready();
        $v=$request->validate(['device_id'=>'required|uuid']); $row=DB::table('desktop_pos_devices')->where('id',$v['device_id'])->first(); abort_unless($row,404);
        $actor=$access->actor(auth('admin')->user()); $access->branch($row->branch,$actor);
        abort_unless((int)$row->actor_id===(int)$actor->id || $access->permissions($actor)['can_manage'],403);
        DB::table('desktop_pos_devices')->where('id',$row->id)->update(['enabled'=>false,'updated_at'=>now('UTC')]);
        return redirect()->route('desktop-pos.index',['branch'=>$row->branch]);
    }
    public function download(TakeawayAccess $access)
    {
        abort_unless($access->canAccess(auth('admin')->user()),403); $path=config('desktop_pos.installer');
        abort_unless(is_file($path),404,'ملف تثبيت البرنامج لم يُرفع بعد.');
        return response()->download($path,'Fasakhansta-POS-Setup.exe',['Cache-Control'=>'private, no-store']);
    }

    public function uploadStart(Request $request, TakeawayAccess $access, DesktopPosInstaller $uploads)
    {
        abort_unless($uploads->canUpload(auth('admin')->user(),$access),403);
        $v=$request->validate(['size'=>'required|integer|min:1']);
        $previous=$request->session()->get('desktop_installer_upload');
        $result=$uploads->start((int)$v['size'],$previous['id']??null);
        $request->session()->put('desktop_installer_upload',['id'=>$result['upload_id'],'actor'=>(int)auth('admin')->id()]);
        return response()->json($result);
    }

    public function uploadChunk(Request $request, TakeawayAccess $access, DesktopPosInstaller $uploads)
    {
        $id=$this->uploadSession($request,$access,$uploads);
        $v=$request->validate(['offset'=>'required|integer|min:0','chunk'=>'required|file|max:512']);
        $offset=$uploads->chunk($id,(int)$v['offset'],$request->file('chunk')->getPathname());
        return response()->json(['offset'=>$offset]);
    }

    public function uploadFinish(Request $request, TakeawayAccess $access, DesktopPosInstaller $uploads)
    {
        $id=$this->uploadSession($request,$access,$uploads);
        $uploads->finish($id);
        return response()->json(['download_url'=>route('desktop-pos.download')]);
    }

    private function uploadSession(Request $request, TakeawayAccess $access, DesktopPosInstaller $uploads): string
    {
        abort_unless($uploads->canUpload(auth('admin')->user(),$access),403);
        $v=$request->validate(['upload_id'=>'required|string|size:64|regex:/\A[0-9a-f]{64}\z/D']);
        $session=$request->session()->get('desktop_installer_upload');
        abort_unless($session && (int)$session['actor']===(int)auth('admin')->id()
            && hash_equals($session['id'],$v['upload_id']),403);
        return $session['id'];
    }
}
