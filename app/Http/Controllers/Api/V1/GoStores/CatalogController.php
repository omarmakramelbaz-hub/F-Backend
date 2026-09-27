<?php

namespace App\Http\Controllers\Api\V1\GoStores;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponses;
use App\Services\GoStores\Catalog;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    use ApiResponses;

    private function owner(Request $request, Catalog $catalog): int
    {
        abort_unless($request->header('X-App-Scope') === 'go_partner', 403);
        $user = $catalog->owner((int) auth('api')->id());
        abort_unless($user->status === 'accepted', 403, 'الحساب غير نشط. تواصل مع الدعم.');
        $catalog->ready();
        return (int) $user->id;
    }

    public function index(Request $request, Catalog $catalog)
    {
        $owner = $this->owner($request, $catalog);
        $request->validate(['page' => 'sometimes|integer|min:1', 'search' => 'nullable|string|max:100']);
        $query = $catalog->products($owner);
        if ($search = trim($request->input('search', ''))) $query->where('name', 'like', '%'.$search.'%');
        $products = $query->paginate(30);
        return $this->successResponse(['store' => $catalog->store($owner), 'currency' => 'EGP',
            'commission_rate' => (string) auth('api')->user()->delegate_fees,
            'products' => $products->getCollection()->map(fn ($p) => $catalog->present($p))->all(),
            'page' => $products->currentPage(), 'last_page' => $products->lastPage(), 'total' => $products->total()]);
    }

    public function store(Request $request, Catalog $catalog)
    {
        return $this->successResponse(['store' => $catalog->saveStore($this->owner($request, $catalog), $request)]);
    }

    public function save(Request $request, Catalog $catalog, ?int $product = null)
    {
        return $this->successResponse(['product' => $catalog->saveProduct($this->owner($request, $catalog), $request, $product)]);
    }
}
