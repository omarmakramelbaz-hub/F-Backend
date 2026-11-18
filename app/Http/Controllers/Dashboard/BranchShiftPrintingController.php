<?php
namespace App\Http\Controllers\Dashboard;
use App\Http\Controllers\Controller;
use App\Services\Dashboard\BranchShiftPrinting;
use Illuminate\Http\Request;
class BranchShiftPrintingController extends Controller
{
    public function jobs(Request $r,BranchShiftPrinting $p){return response()->json($p->listing($r->all(),auth('admin')->user()))->header('Cache-Control','private, no-store');}
    public function claim(Request $r,BranchShiftPrinting $p){return response()->json($p->claim($r->all(),auth('admin')->user()));}
    public function complete(Request $r,BranchShiftPrinting $p){return response()->json($p->complete($r->all(),auth('admin')->user()));}
    public function receipt(int $id,Request $r,BranchShiftPrinting $p){$report=$p->receipt($id,(string)$r->query('claim_token'),auth('admin')->user());return response()->view('admin.branch_shifts.receipt',compact('report'))->header('Cache-Control','private, no-store')->header('Referrer-Policy','no-referrer');}
}
