<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\BranchStockMinimums;
use Illuminate\Http\Request;

/** Keep stock settings independent of the existing recipe and receiving controller. */
class BranchStockMinimumController extends Controller
{
    public function store(Request $request, BranchStockMinimums $minimums)
    {
        return response()->json($minimums->save($request->all(), auth('admin')->user()));
    }
}
