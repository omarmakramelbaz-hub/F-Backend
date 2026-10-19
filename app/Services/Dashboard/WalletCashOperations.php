<?php
namespace App\Services\Dashboard;

use App\Models\{User,Wallet};
use App\Notifications\{NotifyTransferWallet,NotifyWithdrawWallet};
use App\Services\GoServices\Money;
use Illuminate\Support\Facades\{DB,Notification,Validator};

/** The original cash top-up/withdrawal forms, with one locked balance and ledger commit. */
class WalletCashOperations
{
    public const ROUTES=['wallets.transfer'=>'transfer','wallets.withdraw.store'=>'withdraw'];
    public static function handles(?string $route): bool {return isset(self::ROUTES[$route??'']);}
    public function values(string $route,array $values): array
    {
        abort_unless(self::handles($route),422);$kind=self::ROUTES[$route];
        abort_if(array_diff(array_keys($values),['amount','from_user','to_user','type','idempotency_key']),422);
        $target=$kind==='transfer'?'to_user':'from_user';$empty=$kind==='transfer'?'from_user':'to_user';
        abort_unless(!isset($values['type'])||$values['type']===$kind,422);
        abort_unless(!isset($values[$empty])||$values[$empty]==='',422);
        Validator::make($values,[$target=>'required|integer|min:1','amount'=>['required','regex:/^[0-9]{1,4}(?:\.[0-9]{1,2})?$/D'],
            'idempotency_key'=>'sometimes|required|uuid'])->validate();
        try{$amount=Money::minor($values['amount']);}catch(\InvalidArgumentException $e){abort(422);}
        abort_unless($amount>=100&&$amount<=500000,422,'القيمة من جنيه إلى 5000 جنيه، وبحد أقصى منزلتان عشريتان.');
        return ['amount'=>Money::decimal($amount),$target=>(int)$values[$target],$empty=>null,'type'=>$kind]
            +(isset($values['idempotency_key'])?['idempotency_key'=>$values['idempotency_key']]:[]);
    }
    /** Current locked grants are required before both writes and journal saved replies. */
    public function authorize(User $actor): User
    {
        $fresh=User::withoutGlobalScopes()->whereKey($actor->id)->lockForUpdate()->first();
        abort_unless($fresh&&$fresh->account_type==='admin'&&empty($fresh->owner_resturant_id)
            &&!in_array($fresh->status,['disabled','declined'],true),403);
        if((int)$fresh->id!==1)$this->requirePermission($fresh,'wallet-create');return $fresh;
    }
    public function requirePermission(User $fresh,string $name): void
    {
        $permission=DB::table(config('permission.table_names.permissions'))->where('guard_name','admin')->where('name',$name)->lockForUpdate()->first();
        abort_unless($permission,403);
        $roles=DB::table(config('permission.table_names.model_has_roles'))->where('model_type',$fresh->getMorphClass())->where('model_id',$fresh->id)->lockForUpdate()->pluck('role_id');
        $direct=DB::table(config('permission.table_names.model_has_permissions'))->where('model_type',$fresh->getMorphClass())->where('model_id',$fresh->id)->where('permission_id',$permission->id)->lockForUpdate()->first();
        $grant=DB::table(config('permission.table_names.role_has_permissions'))->whereIn('role_id',$roles)->where('permission_id',$permission->id)->lockForUpdate()->first();
        abort_unless($direct||$grant,403);
    }
    public function apply(string $route,array $values,User $actor): array
    {
        $v=$this->values($route,$values);
        return DB::transaction(function()use($route,$v,$actor){
            $actor=$this->authorize($actor);$kind=self::ROUTES[$route];$field=$kind==='transfer'?'to_user':'from_user';
            $user=User::withoutGlobalScopes()->whereKey($v[$field])->lockForUpdate()->first();
            abort_unless($user&&$user->account_type!=='admin',422,'اختر محفظة مستخدم غير إداري.');
            try{$before=Money::minor($user->getRawOriginal('balance'));$minimum=Money::minor($user->getRawOriginal('min_wallet')??'0');}
            catch(\InvalidArgumentException $e){abort(409,'رصيد المحفظة المحفوظ يحتاج مراجعة.');}
            $amount=Money::minor($v['amount']);
            abort_unless($kind!=='withdraw'||$before>=$amount,409,trans("messages.User's wallet is not enough to withdraw the amount"));
            $after=$before+($kind==='transfer'?$amount:-$amount);
            abort_if(abs($after)>=100000000,409,'رصيد المحفظة خارج الحد المسموح.');
            // The deployed transfer_reference is CHAR(36); retain all UUID bits
            // without hyphens rather than letting MariaDB truncate a prefix+UUID.
            $reference=isset($v['idempotency_key'])?'dw:'.str_replace('-','',$v['idempotency_key']):null;
            if($reference)abort_if(Wallet::where('transfer_reference',$reference)->lockForUpdate()->exists(),409,'تم حفظ رقم العملية؛ استرجع نتيجتها الأصلية.');
            $wallet=Wallet::create(['from_user'=>$v['from_user'],'to_user'=>$v['to_user'],'amount'=>$v['amount'],
                'type'=>$kind,'payment'=>'wallet','status'=>'completed','transfer_reference'=>$reference]);
            $changes=['balance'=>Money::decimal($after)];
            if($kind==='transfer'&&$after*2>$minimum&&$user->status==='disabled')$changes['status']='accepted';
            // A balance movement does not change a customer's identity/profile.
            // Avoid the broad saved-user CRM hook recapturing unrelated addresses
            // while the exact DECIMAL balance is updated under its existing lock.
            $changes['updated_at']=now();DB::table('users')->where('id',$user->id)->update($changes);
            $user->forceFill($changes)->syncOriginal();
            // Database notification evidence shares this transaction. Its push is deferred
            // until commit and is explicitly disabled in a local dashboard runtime.
            Notification::send($user,$kind==='transfer'?new NotifyTransferWallet($actor,$v['amount']):new NotifyWithdrawWallet($actor,$v['amount']));
            return ['wallet_id'=>(int)$wallet->id,'user_id'=>(int)$user->id,'kind'=>$kind,'amount'=>$v['amount'],
                'balance_before'=>Money::decimal($before),'balance_after'=>Money::decimal($after),'status_after'=>$user->status,
                'transfer_reference'=>$reference];
        });
    }
}
