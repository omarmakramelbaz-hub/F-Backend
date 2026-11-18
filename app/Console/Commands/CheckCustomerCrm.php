<?php

namespace App\Console\Commands;

use App\Support\CustomerCrmRouteCheck;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CheckCustomerCrm extends Command
{
    protected $signature = 'crm:check {--activate-phone-cutover : Finalize the phone requirement after successful route verification}';
    protected $description = 'Verify customer CRM and POS route contracts without executing an endpoint or sending a message.';

    public function handle(Router $router): int
    {
        try {
            // Construction only: sync web groups and aliases; never handle a request.
            $this->laravel->make(Kernel::class);
            $report = CustomerCrmRouteCheck::verify($router);
            if ($this->option('activate-phone-cutover')) $report['phone_requirement_cutover'] = $this->activatePhoneCutover();
            $this->line(json_encode($report, JSON_THROW_ON_ERROR));
            return 0;
        } catch (\Throwable $error) {
            $this->error(json_encode(CustomerCrmRouteCheck::failure($error), JSON_THROW_ON_ERROR));
            return 1;
        }
    }

    private function activatePhoneCutover(): array
    {
        if (!Schema::hasTable('crm_customer_sync_states')
            || !Schema::hasColumns('crm_customer_sync_states', ['source', 'activated_at', 'bootstrapped_at', 'updated_at'])) {
            throw new \RuntimeException('CRM_ROUTE_PHONE_CUTOVER_SCHEMA');
        }
        return DB::transaction(function (): array {
            $query = DB::table('crm_customer_sync_states')->where('source', 'phone_requirement_cutover');
            $row = (clone $query)->lockForUpdate()->first();
            if (!$row) throw new \RuntimeException('CRM_ROUTE_PHONE_CUTOVER_MISSING');
            if ($row->bootstrapped_at !== null) return ['activated'=>false, 'activated_at'=>(string)$row->activated_at];
            $now = Carbon::now('UTC');
            $changed = $query->whereNull('bootstrapped_at')->update(['activated_at'=>$now, 'bootstrapped_at'=>$now, 'updated_at'=>$now]);
            if ($changed !== 1) throw new \RuntimeException('CRM_ROUTE_PHONE_CUTOVER_NOT_ACTIVATED');
            return ['activated'=>true, 'activated_at'=>$now->format('Y-m-d H:i:s')];
        }, 3);
    }
}
