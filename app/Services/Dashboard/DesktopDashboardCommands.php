<?php
namespace App\Services\Dashboard;

use Illuminate\Http\UploadedFile;

/** Dispatches to the same business services used by the dashboard controllers. */
class DesktopDashboardCommands
{
    public function execute(string $route,array $payload,$actor): array
    {
        abort_unless(DesktopDashboardRoutes::journaled($route),422,'نوع العملية لم يُجهّز للمزامنة بعد.');
        if(DesktopDashboardLegacy::handles($route))return app(DesktopDashboardLegacy::class)->execute($route,$payload,$actor);
        $v=$payload['values']??[];$p=$payload['parameters']??[];
        if(str_starts_with($route,'employees.'))abort_unless(in_array($actor->account_type,['admin','vendor','resturant_owner'],true),403);
        $simple=[
            'takeaway.checkout'=>[TakeawayService::class,'checkout'],
            'customers.save'=>[BranchCustomers::class,'save'],'delivery-companies.save'=>[DeliveryCompanies::class,'save'],
            'employees.save'=>[BranchPayroll::class,'employeeSave'],'employees.attendance'=>[BranchPayroll::class,'attendance'],
            'employees.attendance-rules'=>[BranchPayroll::class,'saveAttendanceRules'],
            'employees.entry'=>[BranchPayroll::class,'entry'],'employees.wallet'=>[BranchPayroll::class,'wallet'],
            'employees.daily-notes'=>[BranchPayroll::class,'dailyNotes'],'employees.void-entry'=>[BranchPayroll::class,'voidEntry'],
            'employees.pay'=>[BranchPayroll::class,'pay'],
            'branch-stock.recipe-save'=>[BranchInventory::class,'saveRecipe'],'branch-expenses.categorySave'=>[ExpenseCategories::class,'save'],
            'phone-orders.dispatch-company'=>[PhoneDeliveryBoard::class,'dispatch'],'phone-orders.finish-batch'=>[PhoneDeliveryBoard::class,'finish'],
        ];
        if(isset($simple[$route])){[$class,$method]=$simple[$route];return app($class)->$method($v,$actor);}
        if($route==='employees.close'){
            abort_unless(isset($payload['facts']['payroll'])&&is_array($payload['facts']['payroll']),409,'بيانات كشف المرتب المحلي غير مكتملة.');
            return app(BranchPayroll::class)->reconcileDesktop($v,$payload['facts']['payroll'],$actor);
        }
        if(in_array($route,['takeaway.movements','takeaway.settings'],true))return app(TakeawayService::class)->changeRegister($v,$actor,$route==='takeaway.settings');
        if(in_array($route,['dining.table-save','dining.settings'],true))return app(PosServiceTable::class)->configure($v,$actor,$route==='dining.settings');
        if($route==='branch-stock.receive')return app(BranchInventory::class)->installed()?app(BranchInventory::class)->receive($v,$actor):app(BranchStock::class)->receive($v,$actor);
        if($route==='branch-expenses.review')return app(BranchExpenses::class)->review((int)($p['id']??0),$v,$actor);
        if($route==='branch-expenses.save'){
            $file=null;$temporary=null;
            try{
                if(isset($payload['files']['attachment'])){
                    $attachment=$payload['files']['attachment'];$bytes=base64_decode($attachment['base64']??'',true);
                    abort_unless($bytes!==false && strlen($bytes)<=5*1024*1024 && hash_equals($attachment['sha256']??'',hash('sha256',$bytes)),422,'المرفق غير مكتمل.');
                    if(!is_dir(storage_path('private')))mkdir(storage_path('private'),0700,true);
                    $temporary=tempnam(storage_path('private'),'desktop-attachment-');file_put_contents($temporary,$bytes);$file=new UploadedFile($temporary,basename($attachment['name']??'attachment'),null,null,true);
                }
                return app(BranchExpenses::class)->save($v,$actor,$file);
            }finally{if($temporary&&is_file($temporary))unlink($temporary);}
        }
        if($route==='branch-shifts.close'){
            abort_unless(isset($payload['facts']['shift'])&&is_array($payload['facts']['shift']),409,'مصادر تقفيل الوردية المحلية غير مكتملة.');
            return app(BranchShiftClosing::class)->reconcileDesktop($v,$payload['facts']['shift'],$actor);
        }
        foreach(['dining'=>'dine','phone-orders'=>'phone'] as $prefix=>$channel){
            if($route===$prefix.'.save')return app(PosServiceTicket::class)->save($channel,$v,$actor);
            if($route===$prefix.'.action')return app(PosServiceTicket::class)->action($channel,(int)($p['id']??0),$v,$actor);
            if($route===$prefix.'.settle')return app(PosServiceTicket::class)->settle($channel,(int)($p['id']??0),$v,$actor);
        }
        abort(422,'نوع العملية غير معروف.');
    }
}
