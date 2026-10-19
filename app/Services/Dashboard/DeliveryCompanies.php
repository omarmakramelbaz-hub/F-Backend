<?php
namespace App\Services\Dashboard;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class DeliveryCompanies
{
    private BranchOperations $ops;
    public function __construct(BranchOperations $ops){$this->ops=$ops;}
    public function listing(array $values,$actor): array
    {
        $v=Validator::make($values,['branch'=>'required|string|max:30','search'=>'nullable|string|max:100','page'=>'nullable|integer|min:1','active'=>'nullable|boolean'])->validate();
        $branches=$this->ops->branches($v['branch'],$actor);$q=DB::table('branch_delivery_companies')->whereIn('branch',array_column($branches,'value'));
        if(isset($v['active']))$q->where('active',$v['active']);if(!empty($v['search']))$q->where('name','like','%'.trim($v['search']).'%');
        $count=(clone $q)->count();$last=max(1,(int)ceil($count/50));$page=min($last,(int)($v['page']??1));
        $items=$q->orderByDesc('active')->orderBy('name')->offset(($page-1)*50)->limit(50)->get();
        $orders=DB::table('pos_service_tickets as t')->whereIn('t.branch',array_column($branches,'value'))->where('t.channel','phone')->where('t.status','!=','cancelled');
        $companyColumn='t.delivery_company_id';
        if(\Illuminate\Support\Facades\Schema::hasTable('phone_delivery_dispatches')){$orders->leftJoin('phone_delivery_dispatches as d','d.ticket_id','=','t.id');$companyColumn='COALESCE(d.company_id,t.delivery_company_id)';}
        $counts=$orders->whereIn(DB::raw($companyColumn),$items->pluck('id'))->selectRaw($companyColumn." AS delivery_company_id,COUNT(*) AS orders,SUM(CASE WHEN t.payment_status='unpaid' THEN 1 ELSE 0 END) AS unpaid")->groupBy(DB::raw($companyColumn))->get()->keyBy('delivery_company_id');
        return ['success'=>true,'branches'=>$branches,'items'=>$items->map(function($r)use($counts){$item=(array)$r;$item['active']=(bool)$r->active;$item['orders']=(int)($counts[$r->id]->orders??0);$item['unpaid']=(int)($counts[$r->id]->unpaid??0);return $item;})->all(),'pagination'=>['page'=>$page,'last_page'=>$last,'total'=>$count]];
    }
    public function save(array $values,$actor): array
    {
        $v=Validator::make($values,$this->ops->rules()+['company_id'=>'nullable|integer|min:1','name'=>'required|string|max:150','phone'=>'required|string|max:30','contact_name'=>'nullable|string|max:100','address'=>'nullable|string|max:500','notes'=>'nullable|string|max:1000','active'=>'required|boolean'])->validate();
        foreach(['name','phone','contact_name','address','notes'] as $key)$v[$key]=trim($v[$key]??'');abort_if($v['name']===''||$v['phone']==='',422,'أدخل اسم الشركة ورقمها.');
        return $this->ops->write('company.save',$v,$actor,function($branch,$actor)use($v){
            $row=!empty($v['company_id'])?DB::table('branch_delivery_companies')->where('branch',$v['branch'])->where('id',$v['company_id'])->lockForUpdate()->first():null;
            if(!empty($v['company_id'])){abort_unless($row,404);$this->ops->revision($row,$v);}
            $data=array_intersect_key($v,array_flip(['branch','name','phone','contact_name','address','notes','active']));$data+=['actor_id'=>$actor->id,'revision'=>$row?(int)$row->revision+1:1,'updated_at'=>now('UTC')];
            if($row){$id=$row->id;DB::table('branch_delivery_companies')->where('id',$id)->update($data);}else $id=DB::table('branch_delivery_companies')->insertGetId($data+['created_at'=>now('UTC')]);
            return ['company'=>(array)DB::table('branch_delivery_companies')->where('id',$id)->first()];
        });
    }
    public function snapshot(?int $id,string $branch): ?array
    {
        if(!$id)return null;$row=DB::table('branch_delivery_companies')->where('branch',$branch)->where('id',$id)->lockForUpdate()->first();abort_unless($row&&$row->active,422,'اختر شركة توصيل مفعّلة في الفرع المحدد.');
        return ['id'=>(int)$row->id,'name'=>$row->name,'phone'=>$row->phone,'contact_name'=>$row->contact_name??''];
    }
}
