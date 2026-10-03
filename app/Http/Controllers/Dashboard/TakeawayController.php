<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\TakeawayAccess;
use App\Services\Dashboard\TakeawayCatalog;
use App\Services\Dashboard\TakeawayService;
use Illuminate\Http\Request;

class TakeawayController extends Controller
{
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            abort_unless(app(TakeawayAccess::class)->canAccess(auth('admin')->user()), 403);
            return $next($request);
        });
    }

    public function index(Request $request, TakeawayAccess $access, TakeawayService $service)
    {
        $values = $request->validate(['branch'=>['nullable','string','regex:/^(f|gs):[1-9][0-9]{0,18}$/D']]);
        $actor = auth('admin')->user();
        $branches = $access->branches($actor);
        abort_unless(count($branches), 403);
        $selected = $values['branch'] ?? $branches[0]['value'];
        $access->branch($selected, $actor);
        $permissions = $access->permissions($actor);
        $permissions['can_operate'] = $permissions['can_checkout'];
        $urls = [];
        foreach (['catalog','quote','checkout','receipts','till','movements','settings'] as $name) $urls[$name] = route('takeaway.'.$name);
        $urls['daily'] = $urls['receipts']; $urls['register'] = $urls['till'];
        $pos = array_merge($service->summary($selected, $actor), [
            'branches'=>$branches, 'selected_branch'=>$selected, 'cashier'=>['id'=>(int) $actor->id, 'name'=>(string) $actor->name],
            'urls'=>$urls, 'permissions'=>$permissions,
        ]);
        return view('admin.takeaway.index', compact('pos'));
    }

    public function catalog(Request $request, TakeawayCatalog $catalog, TakeawayService $service)
    {
        $values = $request->validate($this->branchRules() + ['search'=>'nullable|string|max:100', 'category_id'=>'nullable|integer|min:1',
            'page'=>'nullable|integer|min:1', 'per_page'=>'nullable|integer|min:1|max:100']);
        $actor = auth('admin')->user();
        return response()->json(array_merge($catalog->listing($values, $actor), $service->summary($values['branch'], $actor)));
    }

    public function quote(Request $request, TakeawayService $service)
    {
        return response()->json($service->quote($request->all(), auth('admin')->user()));
    }

    public function checkout(Request $request, TakeawayService $service)
    {
        return response()->json($service->checkout($request->all(), auth('admin')->user()));
    }

    public function receipts(Request $request, TakeawayService $service)
    {
        $values = $request->validate($this->branchRules() + ['date'=>'nullable|date_format:Y-m-d', 'idempotency_key'=>'nullable|uuid',
            'page'=>'nullable|integer|min:1', 'per_page'=>'nullable|integer|min:1|max:50']);
        return response()->json($service->receipts($values, auth('admin')->user()));
    }

    public function print(int $id, TakeawayService $service)
    {
        return view('admin.takeaway.receipt', ['receipt'=>$service->receipt($id, auth('admin')->user())]);
    }

    public function till(Request $request, TakeawayService $service)
    {
        $values = $request->validate($this->branchRules() + ['date'=>'nullable|date_format:Y-m-d']);
        return response()->json($service->register($values['branch'], auth('admin')->user(), $values['date'] ?? null));
    }

    public function movement(Request $request, TakeawayService $service)
    {
        return response()->json($service->changeRegister($request->all(), auth('admin')->user(), false));
    }

    public function settings(Request $request, TakeawayService $service)
    {
        return response()->json($service->changeRegister($request->all(), auth('admin')->user(), true));
    }

    private function branchRules(): array { return ['branch'=>['required','string','regex:/^(f|gs):[1-9][0-9]{0,18}$/D']]; }
}
