<?php
namespace App\Services\Dashboard;

use App\Services\GoServices\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BranchExpenses
{
    public const CATEGORIES=['purchases','electricity','water','gas','packaging','maintenance','transport','fuel','rent','salaries','other'];
    public const METHODS=['cash','bank','card','mobile_wallet'];
    private TakeawayAccess $access;
    public function __construct(TakeawayAccess $access){$this->access=$access;}
    public function permissions($actor): array
    {
        $actor=$this->access->actor($actor);$write=$this->access->permissions($actor)['can_checkout'];
        return ['can_manage_categories'=>app(ExpenseCategories::class)->canCreate($actor),'can_create'=>$write,'can_approve'=>$write&&(($actor->account_type==='admin'&&empty($actor->owner_resturant_id))||$actor->account_type==='resturant_owner')];
    }
    public function ready(): void {abort_unless(Schema::hasTable('branch_expenses')&&Schema::hasTable('branch_expense_commands')&&$this->access->ready(),503,'صفحة المصروفات تحتاج تحديث قاعدة البيانات.');}
    public function filters(array $values): array
    {
        $v=Validator::make($values,['branch'=>['required','regex:/^(all|(?:f|gs):[1-9][0-9]{0,18})$/D'],'from'=>'nullable|date_format:Y-m-d','to'=>'nullable|date_format:Y-m-d|after_or_equal:from','category'=>['nullable',Rule::in(array_keys(app(ExpenseCategories::class)->options()))],'status'=>'nullable|in:pending,approved,rejected,voided','actor_id'=>'nullable|integer|min:1','search'=>'nullable|string|max:100','page'=>'nullable|integer|min:1'])->validate();
        $v['from']=$v['from']??now('Africa/Cairo')->startOfMonth()->toDateString();$v['to']=$v['to']??now('Africa/Cairo')->toDateString();
        abort_if($v['to']<$v['from'],422,'راجع الفترة المطلوبة.');return $v;
    }
    private function scope(array $v,$actor): array
    {
        $this->ready();$actor=$this->access->actor($actor);
        if($v['branch']==='all') {
            abort_unless($actor->account_type==='admin'&&empty($actor->owner_resturant_id),403);
            $branches=$this->access->branches($actor);
        }else $branches=[$this->access->branch($v['branch'],$actor)];
        return [DB::table('branch_expenses')->whereIn('branch',array_column($branches,'value')),$branches];
    }
    private function filtered($query,array $v)
    {
        $query->whereBetween('occurred_on',[$v['from'],$v['to']]);
        foreach(['category','status','actor_id'] as $key)if(!empty($v[$key]))$query->where($key,$v[$key]);
        if(!empty($v['search'])){$search=trim($v['search']);$query->where(function($q)use($search){$q->where('description','like','%'.$search.'%')->orWhere('supplier','like','%'.$search.'%');if(preg_match('/(?:EXP-)?([0-9]+)$/i',$search,$m))$q->orWhere('id',(int)$m[1]);});}
        return $query;
    }
    public function listing(array $values,$actor,bool $export=false): array
    {
        $v=$this->filters($values);[$base,$branches]=$this->scope($v,$actor);$query=$this->filtered(clone $base,$v);
        $total=(clone $query)->count();abort_if($export&&$total>10000,422,'ضيّق الفترة لتصدير حتى ١٠ آلاف مصروف.');$perPage=$export?max(1,$total):30;$last=max(1,(int)ceil($total/$perPage));$page=min((int)($v['page']??1),$last);
        $rows=(clone $query)->orderByDesc('occurred_on')->orderByDesc('id')->offset($export?0:($page-1)*$perPage)->limit($perPage)->get();
        $approved=(clone $base)->where('status','approved');$today=now('Africa/Cairo')->toDateString();$month=now('Africa/Cairo')->startOfMonth()->toDateString();
        $approvedPeriod=(clone $query)->where('status','approved');$sum=(int)(clone $approvedPeriod)->sum('amount_cents');$days=\Carbon\Carbon::parse($v['from'])->diffInDays(\Carbon\Carbon::parse($v['to']))+1;
        $top=(clone $approvedPeriod)->select('category')->selectRaw('SUM(amount_cents) AS amount')->groupBy('category')->orderByDesc('amount')->first();
        $names=DB::table('users')->whereIn('id',$rows->pluck('actor_id')->merge($rows->pluck('reviewer_id'))->filter()->unique())->pluck('name','id');
        $namesByBranch=array_column($branches,'name','value');$permissions=$this->permissions($actor);$categories=app(ExpenseCategories::class)->options();
        $items=$rows->map(fn($row)=>$this->present($row,$actor,$names[$row->actor_id]??'', $names[$row->reviewer_id]??'', $namesByBranch[$row->branch]??'',$permissions['can_approve'],$categories))->all();
        $actors=DB::table('users')->whereIn('id',(clone $base)->select('actor_id'))->orderBy('name')->get(['id','name']);
        return ['success'=>true,'items'=>$items,'filters'=>$v,'branches'=>$branches,'permissions'=>$permissions,'actors'=>$actors,
            'pagination'=>['page'=>$page,'last_page'=>$last,'total'=>$total],
            'summary'=>['today'=>Money::decimal((int)(clone $approved)->where('occurred_on',$today)->sum('amount_cents')),'month'=>Money::decimal((int)(clone $approved)->whereBetween('occurred_on',[$month,$today])->sum('amount_cents')),'period'=>Money::decimal($sum),'average'=>Money::decimal((int)round($sum/$days)),'count'=>(clone $approvedPeriod)->count(),'pending'=>(clone $base)->where('status','pending')->count(),'top_category'=>$top->category??null,'top_amount'=>Money::decimal((int)($top->amount??0))]]+app(ExpenseCategories::class)->choices($permissions['can_manage_categories']);
    }
    public function show(int $id,$actor): array
    {
        $this->ready();$row=DB::table('branch_expenses')->where('id',$id)->first();abort_unless($row,404);$branch=$this->access->branch($row->branch,$actor);
        $names=DB::table('users')->whereIn('id',array_filter([$row->actor_id,$row->reviewer_id]))->pluck('name','id');
        $item=$this->present($row,$actor,$names[$row->actor_id]??'',$names[$row->reviewer_id]??'',$branch['name'],$this->permissions($actor)['can_approve'],app(ExpenseCategories::class)->options());
        $item['history']=DB::table('branch_expense_commands')->where('expense_id',$id)->orderBy('id')->get(['kind','actor_id','revision','snapshot','created_at'])->map(function($r){$r->snapshot=json_decode($r->snapshot,true);unset($r->snapshot['attachment_path'],$r->snapshot['attachment_hash'],$r->snapshot['attachment_mime']);return $r;})->all();
        return ['success'=>true,'expense'=>$item];
    }
    public function attachment(int $id,$actor): array
    {
        $this->show($id,$actor);$row=DB::table('branch_expenses')->where('id',$id)->first();abort_unless($row->attachment_path&&Storage::disk('local')->exists($row->attachment_path),404);
        return ['path'=>$row->attachment_path,'name'=>$row->attachment_name,'mime'=>$row->attachment_mime];
    }
    private function commandRules(): array {return ['branch'=>['required','regex:/^(f|gs):[1-9][0-9]{0,18}$/D'],'idempotency_key'=>'required|uuid','expected_revision'=>'nullable|integer|min:1'];}
    public function save(array $values,$actor,?UploadedFile $file=null): array
    {
        $v=Validator::make($values,$this->commandRules()+['expense_id'=>'nullable|integer|min:1','occurred_on'=>'required|date_format:Y-m-d|before_or_equal:today','category'=>['required',Rule::in(array_keys(app(ExpenseCategories::class)->options()))],'description'=>'required|string|max:500','amount'=>'required|string|max:14','payment_method'=>['required',Rule::in(self::METHODS)],'payment_reference'=>'nullable|string|max:150','supplier'=>'nullable|string|max:150','cost_center'=>'nullable|string|max:150','notes'=>'nullable|string|max:1000','approve'=>'nullable|boolean'])->validate();
        foreach(['description','payment_reference','supplier','cost_center','notes'] as $field)$v[$field]=trim($v[$field]??'');
        if($v['description']==='')throw ValidationException::withMessages(['description'=>'اكتب بيان المصروف.']);
        try{$amount=Money::minor($v['amount']);}catch(\InvalidArgumentException $e){throw ValidationException::withMessages(['amount'=>'القيمة غير صالحة.']);}
        if($amount<=0||$amount>100000000)throw ValidationException::withMessages(['amount'=>'القيمة أكبر من صفر وبحد أقصى مليون جنيه.']);
        $this->ready();$actor=$this->access->actor($actor);$this->access->branch($v['branch'],$actor);$permissions=$this->permissions($actor);abort_unless($permissions['can_create'],403);abort_if(($v['approve']??false)&&!$permissions['can_approve'],403);
        $path=null;$keepUpload=false;$attachment=[];
        if($file){Validator::make(['attachment'=>$file],['attachment'=>'required|file|mimes:jpg,jpeg,png,pdf|max:5120'])->validate();$attachment=['attachment_hash'=>hash_file('sha256',$file->getRealPath()),'attachment_name'=>mb_substr(preg_replace('/[\x00-\x1F\x7F\\\\\/]/u','_', $file->getClientOriginalName()),0,200),'attachment_mime'=>$file->getMimeType()];}
        $v['amount']=Money::decimal($amount);$hash=PosServiceTicket::fingerprint(['save',$v,$attachment]);
        try{
            if($file){$path=$file->store('branch-expenses','local');abort_unless($path,503,'تعذّر حفظ المرفق.');$attachment['attachment_path']=$path;}
            $result=DB::transaction(function()use($v,$actor,$amount,$hash,$attachment,$permissions){
                if(Schema::hasTable('branch_expense_category_settings'))DB::table('users')->where('id',1)->sharedLock()->first();
                $this->access->branch($v['branch'],$actor,true);if($old=$this->replay($v,$actor,$hash))return $old;
                $row=!empty($v['expense_id'])?DB::table('branch_expenses')->where('branch',$v['branch'])->where('id',$v['expense_id'])->lockForUpdate()->first():null;
                if(!empty($v['expense_id'])){abort_unless($row,404);abort_unless($row->status==='pending',409,'يمكن تعديل المصروف المعلّق فقط.');abort_unless((int)$row->actor_id===(int)$actor->id||$permissions['can_approve'],403);abort_unless((int)($v['expected_revision']??0)===(int)$row->revision,409,'المصروف تغير؛ حدّث الصفحة.');}
                if(!isset(app(ExpenseCategories::class)->options(false)[$v['category']])&&(!$row||$row->category!==$v['category']))throw ValidationException::withMessages(['category'=>__('expenses.category_deleted')]);
                $data=array_intersect_key($v,array_flip(['branch','occurred_on','category','description','payment_method','payment_reference','supplier','cost_center','notes']));$data+=['amount_cents'=>$amount,'revision'=>$row?(int)$row->revision+1:1,'updated_at'=>now('UTC')];$data=array_merge($data,$attachment);
                if($row){$id=(int)$row->id;DB::table('branch_expenses')->where('id',$id)->update($data);}else $id=DB::table('branch_expenses')->insertGetId($data+['actor_id'=>$actor->id,'status'=>'pending','created_at'=>now('UTC')]);
                if($v['approve']??false){$fresh=DB::table('branch_expenses')->where('id',$id)->first();$this->postCash($fresh,$actor,-1);DB::table('branch_expenses')->where('id',$id)->update(['status'=>'approved','reviewer_id'=>$actor->id,'reviewed_at'=>now('UTC')]);}
                $this->record($v,$actor,$id,$hash,$row?'edit':'create');return $this->show($id,$actor)+['replayed'=>false];
            },3);
            $keepUpload=$path&&!$result['replayed'];return $result;
        }finally{
            // Keep only uploads actually referenced by a committed expense. Old
            // replaced files remain private for the immutable audit snapshots.
            if($path&&!$keepUpload)Storage::disk('local')->delete($path);
        }
    }
    public function review(int $id,array $values,$actor): array
    {
        $v=Validator::make($values,$this->commandRules()+['action'=>'required|in:approve,reject,void','reason'=>'nullable|string|max:500'])->validate();$v['reason']=trim($v['reason']??'');
        if($v['action']!=='approve'&&$v['reason']==='')throw ValidationException::withMessages(['reason'=>'اكتب سبب الرفض أو الإلغاء.']);
        $this->ready();$actor=$this->access->actor($actor);abort_unless($this->permissions($actor)['can_approve'],403);$hash=PosServiceTicket::fingerprint([$id,$v]);
        return DB::transaction(function()use($id,$v,$actor,$hash){
            $this->access->branch($v['branch'],$actor,true);if($old=$this->replay($v,$actor,$hash))return $old;
            $row=DB::table('branch_expenses')->where('branch',$v['branch'])->where('id',$id)->lockForUpdate()->first();abort_unless($row,404);
            abort_unless((int)($v['expected_revision']??0)===(int)$row->revision&&$row->status===($v['action']==='void'?'approved':'pending'),409,'حالة المصروف تغيرت؛ حدّث الصفحة.');
            if($v['action']==='approve')$this->postCash($row,$actor,-1);elseif($v['action']==='void')$this->postCash($row,$actor,1);
            DB::table('branch_expenses')->where('id',$id)->update(['status'=>['approve'=>'approved','reject'=>'rejected','void'=>'voided'][$v['action']],'revision'=>(int)$row->revision+1,'reviewer_id'=>$actor->id,'reviewed_at'=>now('UTC'),'review_reason'=>$v['reason'],'updated_at'=>now('UTC')]);
            $this->record($v,$actor,$id,$hash,$v['action']);return $this->show($id,$actor)+['replayed'=>false];
        },3);
    }
    private function postCash(object $expense,$actor,int $sign): void
    {
        if($expense->payment_method!=='cash')return;$when=now('UTC');
        DB::table('takeaway_tills')->insertOrIgnore(['branch'=>$expense->branch,'balance_cents'=>0,'tax_bps'=>0,'revision'=>1,'created_at'=>$when,'updated_at'=>$when]);
        $till=DB::table('takeaway_tills')->where('branch',$expense->branch)->lockForUpdate()->first();$delta=$sign*(int)$expense->amount_cents;$balance=(int)$till->balance_cents+$delta;
        abort_unless($balance>=0&&$balance<=100000000000,409,'رصيد خزنة الفرع لا يسمح بهذه العملية.');
        $requestKey=\Illuminate\Support\Str::uuid()->toString();$kind=$sign<0?'expense':'expense_refund';
        DB::table('takeaway_tills')->where('id',$till->id)->update(['balance_cents'=>$balance,'revision'=>(int)$till->revision+1,'updated_at'=>$when]);
        DB::table('takeaway_till_entries')->insert(['till_id'=>$till->id,'branch'=>$expense->branch,'actor_id'=>$actor->id,'request_key'=>$requestKey,'request_hash'=>PosServiceTicket::fingerprint([$kind,$expense->id]),'kind'=>$kind,'amount_cents'=>$delta,'balance_cents'=>$balance,'business_date'=>$when->copy()->setTimezone('Africa/Cairo')->toDateString(),'note'=>mb_substr(($sign<0?'مصروف ':'عكس مصروف ').'EXP-'.$expense->id.' · '.$expense->description,0,500),'metadata'=>json_encode(['expense_id'=>(int)$expense->id,'occurred_on'=>$expense->occurred_on]),'created_at'=>$when,'updated_at'=>$when]);
    }
    private function replay(array $v,$actor,string $hash): ?array
    {
        $old=DB::table('branch_expense_commands')->where('branch',$v['branch'])->where('actor_id',$actor->id)->where('request_key',$v['idempotency_key'])->first();
        if(!$old)return null;abort_unless(hash_equals($old->request_hash,$hash),409,'رقم العملية مستخدم لبيانات مختلفة.');return $this->show((int)$old->expense_id,$actor)+['replayed'=>true];
    }
    private function record(array $v,$actor,int $id,string $hash,string $kind): void
    {
        $row=DB::table('branch_expenses')->where('id',$id)->first();
        DB::table('branch_expense_commands')->insert(['branch'=>$v['branch'],'actor_id'=>$actor->id,'request_key'=>$v['idempotency_key'],'request_hash'=>$hash,'expense_id'=>$id,'kind'=>$kind,'revision'=>$row->revision,'snapshot'=>json_encode($row,JSON_UNESCAPED_UNICODE),'created_at'=>now('UTC'),'updated_at'=>now('UTC')]);
    }
    private function present(object $row,$actor,string $name,string $reviewer,string $branchName,bool $canApprove,array $categories): array
    {
        $result=(array)$row;unset($result['attachment_path'],$result['attachment_hash'],$result['attachment_mime']);$result['id']=(int)$row->id;$result['revision']=(int)$row->revision;$result['actor_id']=(int)$row->actor_id;
        $result['number']='EXP-'.str_pad((string)$row->id,6,'0',STR_PAD_LEFT);$result['amount']=Money::decimal((int)$row->amount_cents);$result['actor_name']=$name;$result['reviewer_name']=$reviewer;$result['branch_name']=$branchName;
        $result['category_name']=$categories[$row->category]??$row->category;
        $result['can_edit']=$row->status==='pending'&&((int)$row->actor_id===(int)$actor->id||$canApprove);
        $result['attachment_url']=$row->attachment_path?route('branch-expenses.attachment',['id'=>$row->id]):null;
        $result['print_url']=route('branch-expenses.print',['id'=>$row->id]);return $result;
    }
}
