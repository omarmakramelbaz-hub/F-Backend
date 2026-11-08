<?php

namespace App\Services\Dashboard;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;

/**
 * A store's app order prints on Shubra's device, whoever sends it to print and from whatever device: the stores are
 * online fronts of Shubra, which prepares and sends out their orders. The print waits in Shubra's queue (kept in
 * branch_operation_commands) until Shubra's receiving device takes it, prints it and reports back, as phone orders
 * and shift receipts do. Claims are never recycled by themselves: a stuck printer is shown for review.
 */
class AppOrderBranchPrinting
{
    public const KIND = 'app.order.branch_print';

    public function __construct(private TakeawayAccess $access) {}

    /** The branch whose device prints this app order, when it is not the order's own (a store's order: Shubra). */
    public static function remoteBranch(object $row): ?string
    {
        $origin = 'f:'.(int) ($row->resturant_id ?? 0);
        $target = FasakhanstaStoreFulfillment::branch($origin);
        return $target !== $origin ? $target : null;
    }

    /** Queues the order for the device of the branch that prepares it. Inside the caller's transaction. */
    public function enqueue(string $branch, int $orderId, int $actorId): array
    {
        $key = (string) Str::uuid();
        DB::table('branch_operation_commands')->insert(['branch'=>$branch, 'actor_id'=>$actorId, 'request_key'=>$key,
            'request_hash'=>hash('sha256', self::KIND.'|'.$orderId.'|'.$key), 'kind'=>self::KIND,
            'result'=>json_encode(['source'=>'legacy', 'order_id'=>$orderId, 'status'=>'pending'], JSON_THROW_ON_ERROR),
            'created_at'=>now('UTC'), 'updated_at'=>now('UTC')]);
        return ['success'=>true, 'remote'=>true, 'branch'=>$branch,
            'message'=>'اتبعت للطباعة على جهاز '.(DB::table('resturants')->where('id', (int) substr($branch, 2))->value('name') ?: 'الفرع').'.'];
    }

    public function listing(array $values, $actor): array
    {
        $v = Validator::make($values, ['branch'=>['required', 'regex:/^f:[1-9][0-9]{0,18}$/D']])->validate();
        $this->receiver($v['branch'], $actor);
        $jobs = []; $attention = 0;
        $rows = DB::table('branch_operation_commands')->where('branch', $v['branch'])->where('kind', self::KIND)
            ->where('created_at', '>=', now('UTC')->subDays(2))->orderBy('id')->get(['id', 'result']);
        foreach ($rows as $row) {
            $job = json_decode((string) $row->result, true) ?: [];
            $status = $job['status'] ?? '';
            if ($status === 'pending' && count($jobs) < 10) $jobs[] = ['id'=>(int) $row->id];
            if ($status === 'failed' || ($status === 'claimed' && ($job['claimed_at'] ?? '') < now('UTC')->subMinutes(2)->toIso8601String())) $attention++;
        }
        return ['success'=>true, 'jobs'=>$jobs, 'attention'=>$attention];
    }

    public function claim(array $values, $actor): array
    {
        $v = Validator::make($values, ['branch'=>'required|string|max:32', 'job_id'=>'required|integer|min:1', 'claim_token'=>'required|uuid'])->validate();
        $actor = $this->receiver($v['branch'], $actor);
        return DB::transaction(function () use ($v, $actor) {
            [$row, $job] = $this->locked($v['branch'], (int) $v['job_id']);
            abort_unless(($job['status'] ?? '') === 'pending', 409, 'أمر الطباعة التقطه جهاز آخر أو يحتاج مراجعة.');
            $this->save($row, $job + ['claimed_by'=>(int) $actor->id, 'claim_token'=>$v['claim_token'], 'claimed_at'=>now('UTC')->toIso8601String()], 'claimed');
            return ['success'=>true, 'print_url'=>route('app-order-print.receipt', ['id'=>$row->id, 'claim_token'=>$v['claim_token']])];
        });
    }

    public function complete(array $values, $actor): array
    {
        $v = Validator::make($values, ['branch'=>'required|string|max:32', 'job_id'=>'required|integer|min:1', 'claim_token'=>'required|uuid',
            'result'=>'required|in:invoked,failed'])->validate();
        $actor = $this->receiver($v['branch'], $actor);
        return DB::transaction(function () use ($v, $actor) {
            [$row, $job] = $this->locked($v['branch'], (int) $v['job_id']);
            $this->claimed($job, $v['claim_token'], $actor);
            abort_unless(in_array($job['status'] ?? '', ['claimed', $v['result']], true), 409);
            if (($job['status'] ?? '') === 'claimed') $this->save($row, $job + ['done_at'=>now('UTC')->toIso8601String()], $v['result']);
            return ['success'=>true, 'status'=>$v['result']];
        });
    }

    /** The order the claimed job prints, for the claiming device only. */
    public function order(int $jobId, string $token, $actor): int
    {
        $row = DB::table('branch_operation_commands')->where('id', $jobId)->where('kind', self::KIND)->first();
        abort_unless($row, 404);
        $actor = $this->receiver($row->branch, $actor);
        $job = json_decode((string) $row->result, true) ?: [];
        $this->claimed($job, $token, $actor);
        abort_unless(($job['status'] ?? '') === 'claimed', 409);
        return (int) $job['order_id'];
    }

    private function receiver(string $branch, $actor)
    {
        $this->access->branch($branch, $actor);
        abort_unless($this->access->receiverBranch($actor) === $branch, 403, 'الطباعة التلقائية من حساب الفرع فقط.');
        return $this->access->actor($actor);
    }

    private function locked(string $branch, int $id): array
    {
        $row = DB::table('branch_operation_commands')->where('branch', $branch)->where('kind', self::KIND)->where('id', $id)->lockForUpdate()->first();
        abort_unless($row, 404);
        return [$row, json_decode((string) $row->result, true) ?: []];
    }

    private function claimed(array $job, string $token, $actor): void
    {
        abort_unless((int) ($job['claimed_by'] ?? 0) === (int) $actor->id && hash_equals((string) ($job['claim_token'] ?? ''), $token), 403);
    }

    private function save(object $row, array $job, string $status): void
    {
        $job['status'] = $status;
        DB::table('branch_operation_commands')->where('id', $row->id)->update(['result'=>json_encode($job, JSON_THROW_ON_ERROR), 'updated_at'=>now('UTC')]);
    }
}
