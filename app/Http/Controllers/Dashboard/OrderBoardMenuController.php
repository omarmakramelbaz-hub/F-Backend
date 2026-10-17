<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\OrderBoardMenu;
use App\Services\Dashboard\OrderBoardService;
use Illuminate\Http\Request;

class OrderBoardMenuController extends Controller
{
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            abort_unless(app(OrderBoardService::class)->canAccess(auth('admin')->user()), 403);
            return $next($request);
        });
    }

    public function index(Request $request, OrderBoardMenu $menu)
    {
        return response()->json($menu->listing($request, auth('admin')->user()));
    }

    public function availability(Request $request, string $kind, int $branchId, int $product, OrderBoardMenu $menu)
    {
        return response()->json($menu->setAvailability($request, $kind, $branchId, $product, auth('admin')->user()));
    }
}
