<?php

namespace App\Http\Controllers\Erp;

use App\Http\Controllers\Controller;
use App\Services\Erp\Actor;
use App\Services\Erp\Decimal;
use App\Services\Erp\People;
use App\Services\Erp\Stock;
use App\Services\Erp\UnifiedOrders;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class WorkspaceController extends Controller
{
    private function actor(Request $r): Actor { return $r->attributes->get('erp_actor'); }

    private function branchesFor(Actor $actor)
    {
        return $actor->scope(DB::table('erp_branches'), 'id')->orderBy('name')->get();
    }

    private function selectedBranch(Request $r, Actor $actor): ?int
    {
        $r->validate(['branch' => 'nullable|integer|min:1']);
        if ($r->filled('branch')) { $actor->branch((int) $r->branch); return (int) $r->branch; }
        return $actor->allBranches() ? null : $actor->branchId;
    }

    private function mutate(Request $r, callable $action, string $message)
    {
        try { $action(); }
        catch (HttpExceptionInterface $e) {
            if (!in_array($e->getStatusCode(), [409, 422], true)) { throw $e; }
            return back()->withErrors(['operation' => $e->getMessage()])->withInput($r->except(['password','password_confirmation','_token']));
        }
        return back()->with('success', $message);
    }

    private function orderQuery(Actor $actor, ?int $branch)
    {
        $branches = $actor->scope(DB::table('erp_branches'), 'id');
        if ($branch) { $branches->where('id', $branch); }
        return DB::table('orders')->whereIn('resturant_id', $branches->pluck('restaurant_id'))->where('type', 'current')->whereNotNull('status');
    }

    public function home(Request $r)
    {
        $actor = $this->actor($r);
        $branches = $this->branchesFor($actor);
        $branch = $this->selectedBranch($r, $actor);
        $warehouses = $actor->scope(DB::table('erp_warehouses'));
        $employees = $actor->scope(DB::table('erp_employees'))->where('active', true);
        if ($branch) { $warehouses->where('branch_id', $branch); $employees->where('branch_id', $branch); }
        $balances = DB::table('erp_stock_balances')->whereIn('warehouse_id', $warehouses->pluck('id'));
        $localDay = Carbon::now(config('erp.timezone'))->startOfDay();
        $start = $localDay->copy()->setTimezone(config('app.timezone', 'UTC'));
        $end = $localDay->copy()->addDay()->setTimezone(config('app.timezone', 'UTC'));
        $orders = $actor->can('orders.view') ? $this->orderQuery($actor, $branch)->where('created_at', '>=', $start)->where('created_at', '<', $end)->count() : null;
        $stockValue = $actor->can('inventory.manage') ? (int) (clone $balances)->sum('value_minor') : null;
        $employeeCount = $actor->can('employees.manage') ? $employees->count() : null;
        $lowStock = $actor->can('inventory.manage') ? (clone $balances)->join('erp_items', 'erp_items.id', '=', 'erp_stock_balances.item_id')->whereColumn('quantity_milli', '<=', 'minimum_milli')->count() : null;
        return view('erp.home', compact('branches','branch','orders','stockValue','employeeCount','lowStock'));
    }

    public function branches(Request $r)
    {
        $actor = $this->actor($r); $actor->require('branches.manage');
        abort_unless($actor->allBranches(), 403);
        $branches = $this->branchesFor($actor);
        $restaurants = DB::table('resturants')->whereNotIn('id', DB::table('erp_branches')->pluck('restaurant_id'))->select('id','name')->orderBy('name')->get();
        $warehouses = $actor->scope(DB::table('erp_warehouses'))->orderBy('name')->get();
        return view('erp.branches', compact('branches','restaurants','warehouses'));
    }

    public function saveBranch(Request $r)
    {
        $actor = $this->actor($r); $actor->require('branches.manage');
        abort_unless($actor->allBranches(), 403);
        $data = $r->validate(['id' => 'nullable|integer|min:1', 'restaurant_id' => 'required_without:id|integer|exists:resturants,id|unique:erp_branches,restaurant_id', 'name' => 'required|string|max:120', 'active' => 'required|boolean']);
        return $this->mutate($r, function () use ($actor, $data) {
            DB::transaction(function () use ($actor, $data) {
                if (!empty($data['id'])) {
                    $actor->branch((int) $data['id']); $id = (int) $data['id'];
                    DB::table('erp_branches')->where('id', $id)->update(['name' => $data['name'], 'active' => $data['active'], 'updated_at' => now()]);
                } else {
                    $id = DB::table('erp_branches')->insertGetId(['restaurant_id' => $data['restaurant_id'], 'name' => $data['name'], 'active' => $data['active'], 'created_at' => now(), 'updated_at' => now()]);
                    DB::table('erp_warehouses')->insert(['branch_id' => $id, 'name' => 'مخزن '.$data['name'], 'created_at' => now(), 'updated_at' => now()]);
                }
                $actor->audit('branch.save', 'branch', $id, ['name' => $data['name'], 'active' => $data['active']], $id);
            });
        }, 'تم حفظ الفرع وربطه بالنظام.');
    }

    public function saveWarehouse(Request $r)
    {
        $actor = $this->actor($r); $actor->require('branches.manage');
        $data = $r->validate(['name' => 'required|string|max:120', 'branch_id' => 'nullable|integer|min:1']);
        return $this->mutate($r, function () use ($actor, $data) {
            $branch = !empty($data['branch_id']) ? (int) $data['branch_id'] : null; $actor->branch($branch, true);
            DB::transaction(function () use ($actor, $data, $branch) {
                $id = DB::table('erp_warehouses')->insertGetId(['name' => $data['name'], 'branch_id' => $branch, 'created_at' => now(), 'updated_at' => now()]);
                $actor->audit('warehouse.create', 'warehouse', $id, ['name' => $data['name']], $branch);
            });
        }, 'تم إنشاء المخزن.');
    }

    public function inventory(Request $r)
    {
        $actor = $this->actor($r); $actor->require('inventory.manage');
        $branch = $this->selectedBranch($r, $actor); $branches = $this->branchesFor($actor);
        $warehouseQuery = $actor->scope(DB::table('erp_warehouses'));
        if ($branch) { $warehouseQuery->where('branch_id', $branch); }
        $warehouses = $warehouseQuery->orderBy('name')->get();
        $items = DB::table('erp_items')->where('active', true)->orderBy('name')->get();
        $balances = DB::table('erp_stock_balances as b')->join('erp_items as i', 'i.id', '=', 'b.item_id')->join('erp_warehouses as w', 'w.id', '=', 'b.warehouse_id')->whereIn('w.id', $warehouses->pluck('id'))->select('b.*','i.name as item_name','i.unit','i.minimum_milli','w.name as warehouse_name')->orderBy('i.name')->paginate(30, ['*'], 'stock_page')->withQueryString();
        $documents = DB::table('erp_stock_documents as d')->join('erp_items as i', 'i.id', '=', 'd.item_id')->join('erp_warehouses as w', 'w.id', '=', 'd.warehouse_id')->leftJoin('erp_warehouses as dest', 'dest.id', '=', 'd.destination_id')->where(function ($q) use ($warehouses) { $q->whereIn('w.id', $warehouses->pluck('id'))->orWhereIn('dest.id', $warehouses->pluck('id')); })->select('d.*','i.name as item_name','i.unit','w.name as warehouse_name','dest.name as destination_name')->orderByDesc('d.id')->paginate(20, ['*'], 'history_page')->withQueryString();
        return view('erp.inventory', compact('items','warehouses','balances','documents','branches','branch'));
    }

    public function saveItem(Request $r)
    {
        $actor = $this->actor($r); $actor->require('inventory.manage'); abort_unless($actor->allBranches(), 403);
        $data = $r->validate(['sku' => 'required|alpha_dash|max:64|unique:erp_items,sku', 'name' => 'required|string|max:160', 'unit' => ['required', Rule::in(['kg','piece'])], 'category' => ['required', Rule::in(['raw','finished','packaging'])], 'minimum' => 'required|string|max:20']);
        return $this->mutate($r, function () use ($actor, $data) {
            $min = Decimal::quantity($data['minimum'], $data['unit']);
            DB::transaction(function () use ($actor, $data, $min) {
                $id = DB::table('erp_items')->insertGetId(['sku' => $data['sku'], 'name' => $data['name'], 'unit' => $data['unit'], 'category' => $data['category'], 'minimum_milli' => $min, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
                $actor->audit('item.create', 'item', $id, ['sku' => $data['sku'], 'name' => $data['name'], 'unit' => $data['unit']]);
            });
        }, 'تم إضافة الصنف المخزني.');
    }

    public function postStock(Request $r, Stock $stock)
    {
        $data = $r->validate(['request_key' => 'required|string|max:64', 'type' => 'required|string|max:20', 'item_id' => 'required|integer|min:1', 'warehouse_id' => 'required|integer|min:1', 'destination_id' => 'nullable|integer|min:1', 'quantity' => 'required|string|max:20', 'unit_cost' => 'nullable|string|max:20', 'expected_quantity' => 'nullable|string|max:20', 'reference' => 'nullable|string|max:160', 'reason' => 'required|string|max:500']);
        return $this->mutate($r, fn () => $stock->post($this->actor($r), $data), 'تم ترحيل حركة المخزون وتسجيلها في السجل.');
    }

    public function employees(Request $r)
    {
        $actor = $this->actor($r); $actor->require('employees.manage');
        $branch = $this->selectedBranch($r, $actor); $branches = $this->branchesFor($actor);
        $day = $r->query('day', now(config('erp.timezone'))->format('Y-m-d'));
        $r->merge(['day' => $day]); $r->validate(['day' => 'required|date_format:Y-m-d']);
        $query = $actor->scope(DB::table('erp_employees as e'), 'e.branch_id')->join('erp_branches as b', 'b.id', '=', 'e.branch_id')->leftJoin('erp_attendance as a', function ($join) use ($day) { $join->on('a.employee_id','=','e.id')->where('a.day','=',$day); });
        if ($branch) { $query->where('e.branch_id', $branch); }
        $employees = $query->select('e.id','e.name','e.phone','e.job_title','e.hired_on','e.branch_id','e.active','b.name as branch_name','a.status as attendance_status','a.notes as attendance_notes')->orderBy('e.name')->paginate(30)->withQueryString();
        return view('erp.employees', compact('employees','branches','branch','day'));
    }

    public function saveEmployee(Request $r, People $people)
    {
        $actor = $this->actor($r); $actor->require('employees.manage');
        $data = $r->validate(['id' => 'nullable|integer|min:1', 'name' => 'required|string|max:120', 'phone' => 'nullable|string|max:32', 'job_title' => 'required|string|max:100', 'branch_id' => 'required|integer|min:1', 'hired_on' => 'required|date_format:Y-m-d', 'active' => 'required|boolean', 'salary' => 'nullable|string|max:20']);
        return $this->mutate($r, function () use ($actor, $data, $people) {
            $actor->branch((int) $data['branch_id'], true);
            $salary = $actor->can('payroll.manage') ? Decimal::money($data['salary'] ?? '0') : 0;
            DB::transaction(function () use ($actor, $data, $people, $salary) {
                $fields = ['name' => $data['name'], 'phone' => $data['phone'] ?? null, 'job_title' => $data['job_title'], 'branch_id' => $data['branch_id'], 'active' => $data['active'], 'updated_at' => now()];
                if (!empty($data['id'])) {
                    $employee = $people->employee($actor, (int) $data['id'], true); $id = (int) $employee->id;
                    // Hire date and historical pay are never overwritten by a profile edit.
                    DB::table('erp_employees')->where('id', $id)->update($fields);
                } else {
                    $id = DB::table('erp_employees')->insertGetId($fields + ['hired_on' => $data['hired_on'], 'salary_minor' => $salary, 'created_at' => now()]);
                    DB::table('erp_salary_rates')->insert(['employee_id' => $id, 'effective_month' => substr($data['hired_on'], 0, 7), 'salary_minor' => $salary]);
                }
                $actor->audit('employee.save', 'employee', $id, ['name' => $data['name'], 'branch_id' => $data['branch_id'], 'active' => $data['active']], (int) $data['branch_id']);
            });
        }, 'تم حفظ بيانات الموظف.');
    }

    public function attendance(Request $r, int $employee, People $people)
    {
        $data = $r->validate(['day' => 'required|date_format:Y-m-d', 'status' => 'required|string|max:24', 'notes' => 'nullable|string|max:500']);
        return $this->mutate($r, fn () => $people->attendance($this->actor($r), $employee, $data), 'تم حفظ الحضور.');
    }

    public function salary(Request $r, int $employee, People $people)
    {
        $data = $r->validate(['month' => 'required|date_format:Y-m', 'salary' => 'required|string|max:20']);
        return $this->mutate($r, fn () => $people->salary($this->actor($r), $employee, $data['month'], $data['salary']), 'تم حفظ الراتب وتاريخ سريانه.');
    }

    public function payroll(Request $r, People $people)
    {
        $actor = $this->actor($r); $actor->require('payroll.manage');
        $month = $r->query('month', now(config('erp.timezone'))->format('Y-m')); $people->month($month);
        $branch = $this->selectedBranch($r, $actor); $branches = $this->branchesFor($actor);
        $query = $actor->scope(DB::table('erp_employees'))->where('hired_on', '<=', Carbon::createFromFormat('!Y-m', $month)->endOfMonth()->format('Y-m-d'));
        if ($branch) { $query->where('branch_id', $branch); }
        $employees = $query->orderBy('name')->paginate(25)->withQueryString();
        $statements = []; foreach ($employees as $employee) { $statements[$employee->id] = $people->statement($actor, (int) $employee->id, $month); }
        $adjustments = DB::table('erp_payroll_adjustments')->whereIn('employee_id', $employees->pluck('id'))->where('month', $month)->orderByDesc('id')->get();
        return view('erp.payroll', compact('employees','statements','adjustments','month','branches','branch'));
    }

    public function adjustment(Request $r, int $employee, People $people)
    {
        $data = $r->validate(['month' => 'required|date_format:Y-m', 'type' => 'required|string|max:24', 'amount' => 'required|string|max:20', 'reason' => 'required|string|max:500', 'request_key' => 'required|string|max:64']);
        return $this->mutate($r, fn () => $people->adjustment($this->actor($r), $employee, $data), 'تم تسجيل تسوية الراتب.');
    }

    public function closePayroll(Request $r, int $employee, People $people)
    {
        $data = $r->validate(['month' => 'required|date_format:Y-m']);
        return $this->mutate($r, fn () => $people->close($this->actor($r), $employee, $data['month']), 'تم اعتماد كشف الاستحقاق. الاعتماد لا يسجل صرفًا نقديًا.');
    }

    public function orders(Request $r, UnifiedOrders $orders)
    {
        $actor = $this->actor($r);
        $actor->require('orders.view');

        $day = $r->query('day', now(config('erp.timezone'))->format('Y-m-d'));
        $r->merge(['day' => $day]);

        $filters = $r->validate([
            'day' => 'required|date_format:Y-m-d',
            'app' => ['nullable', Rule::in(UnifiedOrders::APPS)],
            'kind' => ['nullable', Rule::in(UnifiedOrders::KINDS)],
            'stage' => ['nullable', Rule::in(UnifiedOrders::STAGES)],
            'payment' => ['nullable', Rule::in(['cash','wallet','card','mobile_wallet','apple_pay','google_pay'])],
            'q' => 'nullable|string|max:100',
        ]);

        $branch = $this->selectedBranch($r, $actor);
        $branches = $this->branchesFor($actor);

        // Branch managers are constrained in the aggregation service as well as
        // here. A crafted app/kind query can therefore never expose GO or another branch.
        if (!$actor->allBranches()) {
            $filters['app'] = 'fasakhansta';
            $filters['kind'] = 'branch';
        }

        $dashboard = $orders->dashboard($actor, $branch, $day, $filters);
        $columns = $dashboard['columns'];
        $stats = $dashboard['stats'];
        $version = $dashboard['version'];

        // Menu panel rules:
        // - owner/deputy: visible only after choosing one branch;
        // - branch manager: always visible for the assigned branch;
        // - "all branches" never mixes menu availability from different branches.
        $showMenu = !$actor->allBranches() || $branch !== null;
        $menuBranch = $showMenu && $branch ? $branches->firstWhere('id', $branch) : null;
        $menuItems = collect();
        $menuCounts = ['available' => 0, 'unavailable' => 0];

        if ($menuBranch && Schema::hasTable('resturant_products')) {
            $menuItems = DB::table('resturant_products')
                ->where('resturant_id', $menuBranch->restaurant_id)
                ->select('id','product_name','product_price','status','category_id')
                ->orderBy('category_id')
                ->orderBy('product_name')
                ->get();

            $menuCounts['available'] = $menuItems->where('status', 'show')->count();
            $menuCounts['unavailable'] = $menuItems->where('status', 'hide')->count();
        }

        return view('erp.orders', compact(
            'columns','stats','version','branches','branch','day','filters',
            'showMenu','menuBranch','menuItems','menuCounts'
        ));
    }

    public function menuProductStatus(Request $r, int $product)
    {
        $actor = $this->actor($r);
        $actor->require('orders.view');

        $data = $r->validate([
            'branch_id' => 'required|integer|min:1',
            'status' => ['required', Rule::in(['show','hide'])],
        ]);

        $branchId = (int) $data['branch_id'];
        $actor->branch($branchId, true);
        $erpBranch = DB::table('erp_branches')->where('id', $branchId)->first();
        abort_unless($erpBranch, 404, 'الفرع غير موجود.');

        $item = DB::table('resturant_products')
            ->where('id', $product)
            ->where('resturant_id', $erpBranch->restaurant_id)
            ->first();
        abort_unless($item, 404, 'الصنف غير موجود في هذا الفرع.');

        DB::table('resturant_products')->where('id', $product)->update([
            'status' => $data['status'],
            'updated_at' => now(),
        ]);

        $actor->audit('menu.availability', 'resturant_product', $product, [
            'status' => $data['status'],
            'restaurant_id' => (int) $erpBranch->restaurant_id,
        ], $branchId);

        return back()->with('success', $data['status'] === 'show' ? 'تم إتاحة الصنف.' : 'تم إيقاف الصنف مؤقتًا.');
    }

    public function accounts(Request $r)
    {
        abort_unless(config('erp.standalone_auth', false), 404);
        $actor = $this->actor($r); $actor->require('access.manage');
        $accounts = DB::table('erp_users')->select('id','name','email','role','branch_id','permissions','active')->orderBy('name')->get();
        $branches = $this->branchesFor($actor);
        return view('erp.accounts', compact('accounts','branches'));
    }

    public function saveAccount(Request $r)
    {
        abort_unless(config('erp.standalone_auth', false), 404);
        $actor = $this->actor($r); $actor->require('access.manage');
        $r->merge(['email' => mb_strtolower(trim((string) $r->email))]);
        $data = $r->validate(['id' => 'nullable|integer|min:1|exists:erp_users,id', 'name' => 'required|string|max:120', 'email' => ['required','email','max:190',Rule::unique('erp_users','email')->ignore($r->input('id'))], 'role' => ['required',Rule::in(Actor::ROLES)], 'branch_id' => 'nullable|integer|min:1', 'permissions' => 'nullable|array', 'permissions.*' => ['string',Rule::in(array_diff(Actor::CAPABILITIES, ['access.manage']))], 'active' => 'required|boolean', 'password' => ($r->filled('id') ? 'nullable' : 'required').'|string|min:12|max:200|confirmed']);
        return $this->mutate($r, function () use ($actor, $data) {
            $branch = $data['role'] === 'branch_manager' ? (int) ($data['branch_id'] ?? 0) : null;
            if ($data['role'] === 'branch_manager') { abort_unless($branch > 0, 422, 'حدد فرع مدير الفرع.'); $actor->branch($branch, true); }
            DB::transaction(function () use ($actor, $data, $branch) {
                $fields = ['name' => $data['name'], 'email' => $data['email'], 'role' => $data['role'], 'branch_id' => $branch, 'permissions' => json_encode(array_values($data['permissions'] ?? [])), 'active' => $data['active'], 'updated_at' => now()];
                if (!empty($data['password'])) { $fields['password'] = Hash::make($data['password']); $fields['remember_token'] = null; }
                if (!empty($data['id'])) {
                    $id = (int) $data['id']; DB::table('erp_users')->where('id',$id)->update($fields);
                } else { $id = DB::table('erp_users')->insertGetId($fields + ['created_at' => now()]); }
                unset($fields['password'], $fields['remember_token']);
                $actor->audit('access.save', 'staff_account', $id, $fields);
            });
        }, 'تم حفظ حساب ERP وصلاحياته.');
    }

    public function audit(Request $r)
    {
        $actor = $this->actor($r); $actor->require('audit.view');
        $query = $actor->scope(DB::table('erp_audit'));
        // HR/payroll details are not exposed via audit to an operator without payroll access.
        if (!$actor->can('payroll.manage')) { $query->where('action','not like','payroll.%')->where('action','!=','employee.salary'); }
        if (!$actor->can('access.manage')) { $query->where('action','not like','access.%'); }
        if (!$actor->can('finance.manage')) { $query->where('action','not like','finance.%'); }
        if (!$actor->can('purchasing.manage')) { $query->where('action','not like','purchase.%'); }
        if (!$actor->can('production.manage')) { $query->where('action','not like','production.%'); }
        $events = $query->orderByDesc('id')->paginate(40);
        return view('erp.audit', compact('events'));
    }
}
