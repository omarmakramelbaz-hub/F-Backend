<?php
namespace App\Services\OwnerDashboards;

use App\Services\Dashboard\DashboardLoginIdentity;
use Illuminate\Support\Facades\{DB, Hash, Schema, Validator};
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Only the primary owner's installation has this control-plane table. */
final class Registry
{
    private string $control;
    public function __construct(?string $controlDirectory=null){$this->control=$controlDirectory??Runtime::control();}
    private const PUBLIC_FIELDS = ['id','slug','name','email','mobile','desired_state','state','revision',
        'applied_revision','error_code','created_at','updated_at'];

    public function authorize(?object $actor): void
    {
        abort_unless(Runtime::primary($actor),403);
        $fresh=DB::table('users')->where('id',1)->first(['id','account_type','status']);
        abort_unless($fresh && $fresh->account_type==='admin' && $fresh->status==='accepted',403);
        abort_unless(Schema::hasTable('owner_dashboards') && is_dir($this->control.'/deny'),503,
            'إدارة الأونرز غير مفعّلة على السيرفر بعد.');
    }

    public function listing(?object $actor)
    {
        $this->authorize($actor);
        return DB::table('owner_dashboards')->where('desired_state','<>','deleted')->orderByDesc('created_at')
            ->paginate(20,self::PUBLIC_FIELDS);
    }

    public function show(?object $actor,string $id): object
    {
        $this->authorize($actor); $this->id($id);
        $row=DB::table('owner_dashboards')->where('id',$id)->where('desired_state','<>','deleted')->first(self::PUBLIC_FIELDS);
        abort_unless($row,404); return $row;
    }

    public function save(?object $actor,array $input,?string $id=null): string
    {
        $this->authorize($actor); if($id!==null)$this->id($id);
        $input['email']=mb_strtolower(trim((string)($input['email']??'')));
        $data=Validator::make($input,[
            'name'=>'required|string|min:2|max:130','email'=>['required','email','max:191',Rule::unique('owner_dashboards','email')->ignore($id)],
            'mobile'=>'nullable|string|max:32','password'=>($id===null?'required':'nullable').'|string|min:8|max:72|confirmed',
        ])->validate();
        $mobile=empty($data['mobile'])?null:DashboardLoginIdentity::mobile($data['mobile']);
        if(!empty($data['mobile'])&&$mobile===null)throw ValidationException::withMessages(['mobile'=>'اكتب رقم موبايل مصري صحيح.']);
        return DB::transaction(function()use($id,$data,$mobile): string{
            $changes=['name'=>$data['name'],'email'=>$data['email'],'mobile'=>$mobile,'updated_at'=>now()];
            if(!empty($data['password']))$changes['password_hash']=Hash::make($data['password']);
            if($id===null){
                $id=(string)Str::uuid();$changes+=['id'=>$id,'slug'=>bin2hex(random_bytes(8)),
                    'created_by'=>1,'desired_state'=>'active','state'=>'pending','revision'=>1,'applied_revision'=>0,'created_at'=>now()];
                DB::table('owner_dashboards')->insert($changes);
            }else{
                $row=DB::table('owner_dashboards')->where('id',$id)->lockForUpdate()->first();
                abort_unless($row&&$row->desired_state!=='deleted',404);
                $this->deny($id,(int)$row->revision+1); // A future revision marker cannot be cleared by an older worker.
                $changes+=['revision'=>(int)$row->revision+1,'state'=>'pending','error_code'=>null];
                DB::table('owner_dashboards')->where('id',$id)->update($changes);
            }
            return $id;
        });
    }

    public function transition(?object $actor,string $id,string $state): void
    {
        $this->authorize($actor);$this->id($id);abort_unless(in_array($state,['active','suspended','deleted'],true),422);
        DB::transaction(function()use($id,$state){
            $row=DB::table('owner_dashboards')->where('id',$id)->lockForUpdate()->first();
            abort_unless($row&&$row->desired_state!=='deleted',404);
            $this->deny($id,(int)$row->revision+1);
            DB::table('owner_dashboards')->where('id',$id)->update(['desired_state'=>$state,'state'=>'pending',
                'revision'=>(int)$row->revision+1,'error_code'=>null,'updated_at'=>now()]);
        });
    }

    public static function url(object $row): string
    {
        $host=preg_replace('/^www\./i','',(string)parse_url(config('app.url'),PHP_URL_HOST));
        return 'https://go-'.$row->slug.'.'.$host.'/admin/login';
    }
    private function id(string $id): void { abort_unless(preg_match('/^[0-9a-f-]{36}$/D',$id),404); }
    private function deny(string $id,int $revision): void
    {
        $directory=$this->control.'/deny';abort_unless(is_dir($directory)&&!is_link($directory),503);
        $path=$directory.'/'.$id;abort_if(is_link($path),503);
        $handle=@fopen($path,'c');abort_unless($handle,503,'تعذر تأمين إيقاف اللوحة قبل تحديثها.');
        $value=(string)$revision;
        try{abort_unless(flock($handle,LOCK_EX)&&ftruncate($handle,0)&&fwrite($handle,$value)===strlen($value)&&fflush($handle),503);}
        finally{fclose($handle);}
    }
}
