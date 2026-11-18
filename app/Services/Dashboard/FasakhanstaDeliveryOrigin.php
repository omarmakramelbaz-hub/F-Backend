<?php
namespace App\Services\Dashboard;

use Illuminate\Support\Facades\DB;

/** Delivery billing origin only; POS authorization, pricing, stock and printing keep the fulfillment branch. */
class FasakhanstaDeliveryOrigin
{
    public const FULFILLMENT_BRANCH = 'f:301';
    public const PHYSICAL_BRANCH_IDS = [94, 301, 307, 363];
    /** Explicit public retail-store identities reviewed against the live inventory; this does not change ownership. */
    public const CAIRO_ORIGINS = [
        309 => 'المقطم ستور',
        310 => 'زايد ستور',
        311 => 'التجمع ستور',
        312 => 'مدينتي ستور',
        313 => 'مصر الجديده',
        336 => 'المهندسين ستور',
        337 => 'مدينة  نصر ستور',
        338 => 'زهراء مدينة نصر ستور',
        339 => 'عين شمس ستور',
        340 => 'التجمع الثالث ستور',
        341 => '15مايو ستور',
        342 => 'المعادي ستور',
        343 => 'أكتوبر ستور',
        344 => 'حدائق أكتوبر ستور',
        345 => 'الجيزه ستور',
        346 => 'حلوان ستور',
        347 => 'الهرم ستور',
        348 => 'زمالك ستور',
        349 => 'الشروق ستور',
        350 => 'الرحاب ستور',
        354 => 'منصورية ستور',
        355 => 'قليوب ستور',
        356 => 'العاصمه ستور',
        357 => 'العاشر ستور',
        358 => 'عبور ستور',
        359 => 'شبرا ستور',
        360 => 'كرداسة ستور',
    ];
    private TakeawayAccess $access;
    public function __construct(TakeawayAccess $access) { $this->access = $access; }

    public static function isConfiguredOrigin(string $value): bool
    {
        return preg_match('/\Af:([1-9][0-9]{0,18})\z/', $value, $match)
            && (string) (int) $match[1] === $match[1]
            && isset(self::CAIRO_ORIGINS[(int) $match[1]]);
    }

    public static function matchesRegisteredName(string $value, string $name): bool
    {
        if (!self::isConfiguredOrigin($value)) return false;
        $normalize = static fn(string $label): string => trim((string) preg_replace('/\s+/u', ' ', $label));
        return $normalize($name) === $normalize(self::CAIRO_ORIGINS[(int) substr($value, 2)]);
    }

    public function check(string $origin, string $fulfillment, $actor, bool $lock = false): array
    {
        // Authenticate the physical recipient before reading even public origin metadata.
        $branch = $this->access->branch($fulfillment, $actor, $lock);
        abort_unless($branch['value'] === self::FULFILLMENT_BRANCH
            && self::isConfiguredOrigin($origin), 422, 'متجر بداية التوصيل غير مرتبط بفرع التنفيذ.');
        abort_unless($this->access->has('resturants', 'parent_id'), 503, 'ربط متجر التوصيل بفرع التنفيذ غير متاح.');
        $query = DB::table('resturants')->where('id', (int) substr($origin, 2));
        if ($lock) $query->lockForUpdate();
        $row = $query->first();
        abort_unless($row && in_array((int) ($row->parent_id ?? 0), [0, 301], true)
            && self::matchesRegisteredName($origin, (string) ($row->name ?? '')), 422, 'متجر بداية التوصيل غير مرتبط بفرع التنفيذ.');
        $tiers=[];foreach(BranchDeliveryTariff::TIERS as $field)if(property_exists($row,$field))$tiers[$field]=$row->{$field};
        return ['value' => $origin, 'id' => (int) $row->id, 'kind' => 'f', 'name' => (string) ($row->name ?? ''),
            'label' => (string) ($row->name ?? ''), 'address' => (string) ($row->address ?? ''), 'phone' => (string) ($row->phone ?? ''),
            'latitude' => $row->lat ?? ($row->latitude ?? null), 'longitude' => $row->lng ?? ($row->longitude ?? null),
            'km_price' => $row->km_price ?? null, 'parent_id' => $row->parent_id === null ? null : (int) $row->parent_id]+$tiers;
    }
}
