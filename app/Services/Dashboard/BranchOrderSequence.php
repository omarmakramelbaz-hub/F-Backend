<?php
namespace App\Services\Dashboard;
use Illuminate\Support\Facades\{DB,Schema};

/** Persist a branch/channel/shift number; database IDs and earlier orders never change. */
class BranchOrderSequence
{
    public function next(string $branch,string $channel): array
    {
        if(!preg_match('/^f:([1-9][0-9]*)$/D',$branch,$m)||!in_array($channel,['takeaway','dine','phone','app'],true)
            ||!Schema::hasTable('branch_order_sequences')||!Schema::hasTable('branch_shift_closings'))return [];
        foreach(['orders','takeaway_orders','pos_service_tickets'] as $table)if(!Schema::hasColumns($table,['shift_sequence','shift_order_number']))return [];
        return DB::transaction(function()use($branch,$channel,$m){
            // The same branch lock is held by shift closing and POS creation.
            abort_unless(DB::table('resturants')->where('id',(int)$m[1])->lockForUpdate()->first(),404);
            $last=DB::table('branch_shift_closings')->where('branch',$branch)->orderByDesc('sequence')->lockForUpdate()->first();
            if(!$last)return []; // The first authorized close starts the new numbering policy.
            $shift=(int)$last->sequence+1;
            DB::table('branch_order_sequences')->insertOrIgnore(['branch'=>$branch,'channel'=>$channel,'shift_sequence'=>$shift,'last_number'=>0]);
            $q=DB::table('branch_order_sequences')->where('branch',$branch)->where('channel',$channel)->where('shift_sequence',$shift);
            $counter=(clone $q)->lockForUpdate()->first();$next=(int)$counter->last_number+1;
            $q->update(['last_number'=>$next]);return ['shift_sequence'=>$shift,'shift_order_number'=>$next];
        },3);
    }
    public function forSale(string $branch,string $channel,?int $ticketId): array
    {
        if($ticketId){
            $ticket=DB::table('pos_service_tickets')->where('id',$ticketId)->where('branch',$branch)->where('channel',$channel)->first();
            if(!empty($ticket->shift_order_number))return ['shift_sequence'=>(int)$ticket->shift_sequence,'shift_order_number'=>(int)$ticket->shift_order_number];
            return []; // A bill opened before closing retains its original number when paid later.
        }
        return $this->next($branch,$channel);
    }
    public static function display(object $row,string $branch,string $channel,string $fallback): string
    {
        if(empty($row->shift_order_number)||empty($row->shift_sequence))return $fallback;
        $prefix=['takeaway'=>'TA','dine'=>'DI','phone'=>'PH','app'=>'R'][$channel]??'OR';
        $branchId=substr($branch,2);
        return $prefix.$branchId.'-S'.$row->shift_sequence.'-'.str_pad((string)$row->shift_order_number,4,'0',STR_PAD_LEFT);
    }
}
