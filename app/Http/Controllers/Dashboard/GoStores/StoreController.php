<?php

namespace App\Http\Controllers\Dashboard\GoStores;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\GoStores\Catalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StoreController extends Controller
{
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            $admin = auth('admin')->user();
            abort_unless($admin && $admin->account_type === 'admin'
                && ($admin->id === 1 || $admin->can('resturant-list')), 403);
            if ($request->isMethod('POST')) {
                abort_unless($admin->id === 1 || $admin->can('resturant-edit'), 403);
            }
            return $next($request);
        });
    }

    public function index(Catalog $catalog)
    {
        $catalog->ready();
        $stores = User::withoutGlobalScopes()->where('app_scope', 'go_partner')->where(function ($q) {
            $q->where('account_type', 'vendor')->orWhere(function ($q) {
                $q->where('account_type', 'delegate')->whereHas('pending_vendor', fn ($p) => $p->where('profession_key', 'store_owner'));
            });
        })->orderByDesc('id')->paginate(30);
        $profiles = DB::table('go_stores')->whereIn('user_id', $stores->pluck('id'))->get()->keyBy('user_id');
        return view('admin.go_stores.index', compact('stores', 'profiles'));
    }

    public function show(int $owner, Catalog $catalog)
    {
        $catalog->ready();
        $account = $catalog->owner($owner);
        $store = $catalog->store($owner);
        $products = $catalog->products($owner)->paginate(24);
        $products->setCollection($products->getCollection()->map(fn ($p) => $catalog->present($p)));
        return view('admin.go_stores.show', compact('account', 'store', 'products'));
    }

    public function update(int $owner, Request $request, Catalog $catalog)
    {
        $catalog->ready();
        $catalog->owner($owner);
        $data = $request->validate(['commission_rate' => ['required', 'numeric', 'between:0,100', 'regex:/^\d{1,3}(?:\.\d{1,2})?$/D']]);
        DB::transaction(function () use ($owner, $request, $catalog, $data) {
            $catalog->saveStore($owner, $request);
            DB::table('users')->where('id', $owner)->update(['delegate_fees' => $data['commission_rate']]);
        });
        return redirect()->route('go-stores.show', $owner)->with('success', 'تم حفظ بيانات المتجر.');
    }

    public function editProduct(int $owner, Catalog $catalog, ?int $product = null)
    {
        $catalog->ready();
        $account = $catalog->owner($owner);
        $item = $product ? $catalog->product($owner, $product) : null;
        return view('admin.go_stores.product', compact('account', 'item'));
    }

    public function saveProduct(int $owner, Request $request, Catalog $catalog, ?int $product = null)
    {
        $catalog->ready();
        $catalog->owner($owner);
        $catalog->saveProduct($owner, $request, $product);
        return redirect()->route('go-stores.show', $owner)->with('success', 'تم حفظ المنتج وخياراته.');
    }
}
