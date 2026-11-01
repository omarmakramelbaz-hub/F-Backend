<?php
namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\OwnerDashboards\Registry;
use Illuminate\Http\Request;

final class OwnersController extends Controller
{
    public function index(Registry $registry){$owners=$registry->listing(auth('admin')->user());return view('admin.owners.index',compact('owners'));}
    public function create(Registry $registry){$registry->authorize(auth('admin')->user());$owner=null;return view('admin.owners.form',compact('owner'));}
    public function edit(string $owner,Registry $registry){$owner=$registry->show(auth('admin')->user(),$owner);return view('admin.owners.form',compact('owner'));}
    public function store(Request $request,Registry $registry){$id=$registry->save(auth('admin')->user(),$request->all());return redirect()->route('owners.edit',$id)->with('success','تم تسجيل الأونر. جاري تجهيز لوحته المستقلة.');}
    public function update(Request $request,string $owner,Registry $registry){$registry->save(auth('admin')->user(),$request->all(),$owner);return redirect()->route('owners.edit',$owner)->with('success','تم استلام التعديلات. سيظهر رابط الدخول عند اكتمال تطبيقها.');}
    public function state(Request $request,string $owner,Registry $registry){$registry->transition(auth('admin')->user(),$owner,(string)$request->input('state'));return redirect()->route('owners.index')->with('success','تم تحديث حالة الأونر.');}
    public function status(string $owner,Registry $registry){return response()->json($registry->show(auth('admin')->user(),$owner))->header('Cache-Control','private, no-store');}
}
