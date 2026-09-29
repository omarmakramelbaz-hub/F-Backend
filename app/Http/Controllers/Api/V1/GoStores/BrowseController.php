<?php
namespace App\Http\Controllers\Api\V1\GoStores;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponses;
use App\Models\User;
use App\Services\GoStores\Catalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BrowseController extends Controller
{
    use ApiResponses;

    private function stores()
    {
        return DB::table('go_stores as s')->join('users as u', 'u.id', '=', 's.user_id')
            ->where('u.app_scope', 'go_partner')->where('u.status', 'accepted')
            ->where(function ($q) {
                $q->where('u.account_type', 'vendor')->orWhere(function ($q) {
                    $q->where('u.account_type', 'delegate')->whereExists(function ($q) {
                        $q->selectRaw('1')->from('pending_vendors as p')->whereColumn('p.id', 'u.pending_vendor_id')->where('p.profession_key', 'store_owner');
                    });
                });
            })->select(['s.user_id', 's.name', 's.kind', 's.address']);
    }

    private function present(object $store, ?User $owner = null, ?Request $request = null): array
    {
        $owner = $owner ?? User::withoutGlobalScopes()->with(['media', 'pending_vendor'])->find($store->user_id);
        $application = $owner?->pending_vendor;
        $distance = null;
        if ($request?->filled('lat') && $request?->filled('lng') && $application
            && is_numeric($application->lat) && is_numeric($application->lng)) {
            $lat1 = deg2rad((float) $request->lat);
            $lat2 = deg2rad((float) $application->lat);
            $dLat = $lat2 - $lat1;
            $dLng = deg2rad((float) $application->lng - (float) $request->lng);
            $a = sin($dLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLng / 2) ** 2;
            $distance = 6371 * 2 * asin(sqrt(min(1, max(0, $a))));
        }
        return ['id' => (int)$store->user_id, 'name' => $store->name, 'kind' => $store->kind,
            'address' => $store->address, 'logo_url' => $owner?->getFirstMediaUrl('go_store_logo') ?? '',
            'distance_km' => $distance === null ? null : round($distance, 2),
            // Distance/radius describes proximity, not a delivery-price promise.
            'nearby' => $distance !== null && $distance <= (float) ($application->work_radius_km ?: 5)];
    }

    public function index(Request $request, Catalog $catalog)
    {
        $catalog->ready();
        $request->validate(['kind' => 'required|in:'.implode(',', array_keys(Catalog::KINDS)),
            'page' => 'sometimes|integer|min:1', 'search' => 'nullable|string|max:150',
            'sort' => 'sometimes|in:name,nearest',
            'lat' => 'nullable|required_with:lng|numeric|between:-90,90',
            'lng' => 'nullable|required_with:lat|numeric|between:-180,180']);
        $rows = $this->stores()->where('s.kind', $request->kind)->orderBy('s.name')->get();
        $owners = User::withoutGlobalScopes()->whereIn('id', $rows->pluck('user_id'))
            ->with(['media', 'pending_vendor'])->get()->keyBy('id');
        $all = $rows->map(fn ($s) => $this->present($s, $owners->get($s->user_id), $request));
        $nearby = $all->where('nearby', true)->sortBy('distance_km')->values();
        $search = mb_strtolower(trim((string) $request->input('search', '')));
        $visible = $search === '' ? $all : $all->filter(fn ($s) => mb_strpos(mb_strtolower($s['name'].' '.$s['address']), $search) !== false);
        if ($request->input('sort') === 'nearest') {
            $visible = $visible->sortBy(fn ($s) => $s['distance_km'] ?? PHP_FLOAT_MAX);
        }
        $page = (int) $request->input('page', 1);
        return $this->successResponse(['stores' => $visible->values()->forPage($page, 20)->values()->all(),
            'nearby_stores' => $nearby->take(20)->all(), 'nearby_total' => $nearby->count(),
            'page' => $page, 'last_page' => max(1, (int) ceil($visible->count() / 20)), 'total' => $visible->count()]);
    }

    public function show(Request $request, Catalog $catalog, int $store)
    {
        $catalog->ready();
        $request->validate(['page' => 'sometimes|integer|min:1']);
        $row = $this->stores()->where('s.user_id', $store)->first();
        abort_unless($row, 404);
        $page = $catalog->products((int)$row->user_id)->where('available', true)->paginate(30);
        return $this->successResponse(['store' => $this->present($row), 'products' => $page->getCollection()->map(fn ($p) => $catalog->present($p))->all(),
            'currency' => 'EGP', 'page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]);
    }
}
