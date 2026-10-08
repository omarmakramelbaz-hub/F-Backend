<?php
namespace App\Services\Dashboard;

use Illuminate\Support\Facades\{DB,Crypt};

/** Translate only named business references, never every integer in a financial payload. */
class DesktopDashboardReferences
{
    private const FIELDS=[
        'employee_id'=>'employee','entry_id'=>'employee_entry','payroll_id'=>'payroll',
        'customer_id'=>'customer','delivery_company_id'=>'company','company_id'=>'company',
        'ticket_id'=>'ticket','table_id'=>'table','expense_id'=>'expense','previous_closing_id'=>'shift',
    ];
    private const RESULTS=[
        'customers.save'=>['customer'=>'customer.id'],
        'delivery-companies.save'=>['company'=>'company.id'],
        'employees.save'=>['employee'=>'employee.id'],
        'employees.entry'=>['employee_entry'=>'entry.id'],
        'employees.close'=>['payroll'=>'statement.payroll_id'],
        'dining.table-save'=>['table'=>'table.id'],
        'dining.save'=>['ticket'=>'ticket.id'],'dining.action'=>['ticket'=>'ticket.id'],
        'dining.settle'=>['ticket'=>'ticket.id','receipt'=>'receipt.id'],
        'phone-orders.save'=>['ticket'=>'ticket.id'],'phone-orders.action'=>['ticket'=>'ticket.id'],
        'phone-orders.settle'=>['ticket'=>'ticket.id','receipt'=>'receipt.id'],
        'takeaway.checkout'=>['receipt'=>'receipt.id'],
        'branch-expenses.save'=>['expense'=>'expense.id'],
        'branch-shifts.close'=>['shift'=>'closing.id'],
        'phone-orders.dispatch-company'=>['delivery_batch'=>'batch.id'],
    ];
    public function outputs(string $route,array $result,array $values=[],int $actor=0): array
    {
        if(DesktopDashboardLegacy::handles($route))return isset($result['http'])?($result['references']??[]):[];
        $outputs=[];
        foreach(self::RESULTS[$route]??[] as $entity=>$path){$id=data_get($result,$path);if(is_numeric($id)&&(int)$id>0)$outputs[$entity]=(int)$id;}
        if(in_array($route,['branch-expenses.save','branch-expenses.review'],true) && $actor && isset($values['branch'],$values['idempotency_key'])) {
            $id=DB::table('branch_expense_commands')->where('branch',$values['branch'])->where('actor_id',$actor)->where('request_key',$values['idempotency_key'])->value('id');
            if($id)$outputs['expense_command']=(int)$id;
        }
        return $outputs;
    }
    public function capture(string $device,string $command,string $route,array $result,array $payload,int $actor): array
    {
        $outputs=$this->outputs($route,$result,$payload['values']??[],$actor);
        foreach($outputs as $entity=>$id)DB::table('desktop_dashboard_entities')->insertOrIgnore([
            'device_id'=>$device,'entity'=>$entity,'local_id'=>$id,'command_id'=>$command,'created_at'=>now('UTC'),'updated_at'=>now('UTC'),
        ]);
        return $outputs;
    }
    public function prepare(string $device,string $route,array $payload,string $currentCommand=''): array
    {
        $dependencies=[];
        $reference=function(string $entity,$id)use($device,$currentCommand,&$dependencies){
            if(!is_numeric($id)||(int)$id<1)return $id;
            $row=DB::table('desktop_dashboard_entities')->where('device_id',$device)->where('entity',$entity)->where('local_id',(int)$id)->first();
            if(!$row||$row->command_id===$currentCommand)return $id;
            $dependencies[$row->command_id]=true;
            return ['$desktop_ref'=>['entity'=>$entity,'command_id'=>$row->command_id,'local_id'=>(int)$id]];
        };
        if(DesktopDashboardLegacy::handles($route))return ['payload'=>app(DesktopDashboardLegacy::class)->inputs($route,$payload,$reference),'dependencies'=>array_keys($dependencies)];
        foreach($payload['values']??[] as $field=>$value) {
            $entity=self::FIELDS[$field]??null;
            if($field==='id'&&$route==='dining.table-save')$entity='table';
            if($field==='batch_id'&&$route==='phone-orders.finish-batch')$entity='delivery_batch';
            if($entity && !is_array($value))$payload['values'][$field]=$reference($entity,$value);
        }
        if(isset($payload['parameters']['id'])&&!is_array($payload['parameters']['id'])) {
            $entity=str_starts_with($route,'branch-expenses.')?'expense':((str_starts_with($route,'dining.')||str_starts_with($route,'phone-orders.'))?'ticket':null);
            if($entity)$payload['parameters']['id']=$reference($entity,$payload['parameters']['id']);
        }
        foreach($payload['facts']['shift']['sources']??[] as $i=>$source) {
            $entity=['pos'=>'receipt','expense'=>'expense_command'][$source['source']]??null;
            if($entity)$payload['facts']['shift']['sources'][$i]['source_id']=$reference($entity,$source['source_id']);
        }
        if(isset($payload['facts']['shift']['previous_closing_id']))$payload['facts']['shift']['previous_closing_id']=$reference('shift',$payload['facts']['shift']['previous_closing_id']);
        return ['payload'=>$payload,'dependencies'=>array_keys($dependencies)];
    }
    public function resolve(string $device,array $payload): array
    {
        $walk=function($value)use(&$walk,$device){
            if(!is_array($value))return $value;
            if(array_key_exists('$desktop_ref',$value)){
                abort_unless(count($value)===1 && is_array($value['$desktop_ref']),422,'مرجع محلي غير صالح.');$ref=$value['$desktop_ref'];
                $row=DB::table('desktop_dashboard_commands')->where('device_id',$device)->where('command_id',$ref['command_id']??'')->where('status','acknowledged')->first();
                abort_unless($row,409,'العملية السابقة لم تؤكد على السيرفر.');
                $receipt=json_decode(Crypt::decryptString($row->server_result_cipher),true,512,JSON_THROW_ON_ERROR);
                foreach($receipt['references']??[] as $mapping)if(($mapping['entity']??'')===($ref['entity']??'') && (int)($mapping['local_id']??0)===(int)($ref['local_id']??0))return (int)$mapping['server_id'];
                abort(409,'تعذر ربط رقم السجل المحلي برقم السيرفر.');
            }
            return array_map($walk,$value);
        };
        return $walk($payload);
    }
}
