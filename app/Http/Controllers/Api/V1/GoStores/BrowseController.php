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
            })->select(['s.id', 's.user_id', 's.name', 's.kind', 's.address']);
    }

    private function present(object $store): array
    {
        $owner = User::withoutGlobalScopes()->find($store->user_id);
        return ['id' => (int)$store->id, 'name' => $store->name, 'kind' => $store->kind,
            'address' => $store->address, 'logo_url' => $owner?->getFirstMediaUrl('go_store_logo') ?? ''];
    }

    public function index(Request $request, Catalog $catalog)
    {
        $catalog->ready();
        $request->validate(['kind' => 'required|in:'.implode(',', array_keys(Catalog::KINDS)), 'page' => 'sometimes|integer|min:1']);
        $page = $this->stores()->where('s.kind', $request->kind)->orderBy('s.name')->paginate(20);
        return $this->successResponse(['stores' => $page->getCollection()->map(fn ($s) => $this->present($s))->all(),
            'page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]);
    }

    public function show(Request $request, Catalog $catalog, int $store)
    {
        $catalog->ready();
        $request->validate(['page' => 'sometimes|integer|min:1']);
        $row = $this->stores()->where('s.id', $store)->first();
        abort_unless($row, 404);
        $page = $catalog->products((int)$row->user_id)->where('available', true)->paginate(30);
        return $this->successResponse(['store' => $this->present($row), 'products' => $page->getCollection()->map(fn ($p) => $catalog->present($p))->all(),
            'currency' => 'EGP', 'page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]);
    }
}
