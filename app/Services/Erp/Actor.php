<?php

namespace App\Services\Erp;

use Illuminate\Support\Facades\DB;

class Actor
{
    public const CAPABILITIES = ['branches.manage','inventory.manage','employees.manage','payroll.manage','purchasing.manage','production.manage','finance.manage','orders.view','audit.view','access.manage'];
    // Staff roles only. The owner is resolved from the configured legacy admin.
    public const ROLES = ['deputy_manager','branch_manager'];

    public $key;
    public $name;
    public $role;
    public $branchId;
    private $permissions;

    public function __construct(string $key, string $name, string $role, ?int $branchId, array $permissions)
    {
        $this->key = $key;
        $this->name = $name;
        $this->role = $role;
        $this->branchId = $branchId;
        $this->permissions = array_values(array_intersect(self::CAPABILITIES, $permissions));
    }

    public static function defaults(string $role): array
    {
        $map = [
            'deputy_manager' => array_diff(self::CAPABILITIES, ['access.manage']),
            'branch_manager' => ['inventory.manage','production.manage','employees.manage','orders.view','audit.view'],
        ];
        return array_values($map[$role] ?? []);
    }

    public function can(string $capability): bool
    {
        if ($this->role !== 'owner' && !in_array($this->role, self::ROLES, true)) { return false; }
        if ($this->role === 'branch_manager' && in_array($capability, ['branches.manage','payroll.manage','purchasing.manage','finance.manage'], true)) { return false; }
        return $this->role === 'owner' || ($capability !== 'access.manage' && in_array($capability, $this->permissions, true));
    }

    public function require(string $capability): void
    {
        abort_unless($this->can($capability), 403, 'ليس لديك صلاحية لهذا الإجراء.');
    }

    public function allBranches(): bool
    {
        return in_array($this->role, ['owner','deputy_manager'], true);
    }

    public function branch(?int $branchId, bool $active = false): void
    {
        abort_unless($this->role === 'owner' || in_array($this->role, self::ROLES, true), 403, 'هذا الدور غير مدعوم؛ راجع المالك.');
        if ($branchId === null) {
            abort_unless($this->allBranches(), 403, 'المخزن المركزي متاح للإدارة المركزية فقط.');
            return;
        }
        abort_unless($this->allBranches() || $this->branchId === $branchId, 403, 'هذا الفرع خارج صلاحياتك.');
        $branch = DB::table('erp_branches')->where('id', $branchId)->first();
        abort_unless($branch, 404, 'الفرع غير موجود.');
        abort_if($active && !$branch->active, 422, 'الفرع متوقف.');
    }

    public function scope($query, string $column = 'branch_id')
    {
        if (!$this->allBranches()) {
            $query->where($column, $this->role === 'branch_manager' ? ($this->branchId ?? -1) : -1);
        }
        return $query;
    }

    public function audit(string $action, string $entity, int $id, array $details, ?int $branchId = null): void
    {
        DB::table('erp_audit')->insert([
            'actor_key' => $this->key, 'actor_name' => $this->name,
            'action' => $action, 'entity' => $entity, 'entity_id' => $id,
            'branch_id' => $branchId, 'details' => json_encode($details, JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
        ]);
    }
}
