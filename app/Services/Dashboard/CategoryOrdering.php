<?php
namespace App\Services\Dashboard;

use App\Models\Category;
use Illuminate\Support\Facades\{DB,Validator};

/** The original drag order, canonicalized independently of transport array order. */
class CategoryOrdering
{
    public function values(array $values): array
    {
        abort_if(array_diff(array_keys($values),['order']),422);
        Validator::make($values,[
            'order'=>'required|array|min:1|max:200',
            'order.*'=>'required|array:id,position',
            'order.*.id'=>'required|integer|min:1|max:9223372036854775807|distinct',
            'order.*.position'=>'required|integer|min:1|max:1000000|distinct',
        ])->validate();
        $order=array_values(array_map(fn($row)=>['id'=>(int)$row['id'],'position'=>(int)$row['position']],$values['order']));
        usort($order,fn($a,$b)=>$a['id']<=>$b['id']);
        return ['order'=>$order];
    }

    public function apply(array $values): void
    {
        $order=$this->values($values)['order'];
        DB::transaction(function()use($order){
            $rows=Category::whereIn('id',array_column($order,'id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            abort_unless($rows->count()===count($order),422,'أحد الأقسام لم يعد موجودًا؛ لم يُحفظ الترتيب.');
            foreach($order as $item)$rows[$item['id']]->update(['order'=>$item['position']]);
        });
    }
}
