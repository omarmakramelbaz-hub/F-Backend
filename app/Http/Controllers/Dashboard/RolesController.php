<?php

namespace App\Http\Controllers\Dashboard;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use DB;

class RolesController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    function __construct()
    {
         $this->middleware('permission:role-list|role-create|role-edit|role-delete,admin', ['only' => ['index','store']]);
        $this->middleware('permission:role-list', ['only' => ['show']]);
        $this->middleware('permission:role-create', ['only' => ['create','store']]);
         $this->middleware('permission:role-edit', ['only' => ['edit','update']]);
         $this->middleware('permission:role-delete', ['only' => ['destroy']]);
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $roles = Role::where(function ($q) use ($request) {
            return $q->when($request->search, function ($query) use ($request) {
            return $query->where('name', 'like', '%' . $request->search . '%');
          });
        })->when($request->query('from_date'), function($query, $from_date) {
            $query->where('created_at', '>=', $from_date);
        })->when($request->query('to_date'), function($query, $to_date) {
            $query->where('created_at', '<=', $to_date);
        })->orderBy('id','DESC')->paginate(5);
        return view('admin.roles.index',compact('roles'))
            ->with('i', ($request->input('page', 1) - 1) * 5);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $permission = Permission::where('guard_name','admin')->get();
        return view('admin.roles.create',compact('permission'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $this->validate($request, [
            'name'          => 'required|string|min:2',
            'permission'    => 'required|array',
            'permission.*'  => 'required|string',        
        ]);
// dd($request->all());
        $role = Role::create(['name' => $request->input('name')]);
        $role->syncPermissions($request->input('permission'));
        $this->invalidateCommittedPermissions();

        return redirect()->route('roles.index')
                        ->with('success',trans('messages.AddSuccessfully'));
    }
    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $role = Role::find($id);
        $rolePermissions = Permission::where('guard_name','admin')->join("role_has_permissions","role_has_permissions.permission_id","=","permissions.id")
            ->where("role_has_permissions.role_id",$id)
            ->get();

        return view('admin.roles.show',compact('role','rolePermissions'));
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        $role = Role::find($id);
        $permission = Permission::where('guard_name','admin')->get();
        $rolePermissions = DB::table("role_has_permissions")->where("role_has_permissions.role_id",$id)
            ->pluck('role_has_permissions.permission_id','role_has_permissions.permission_id')
            ->toArray();
        return view('admin.roles.edit',compact('role','permission','rolePermissions'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    { 
        $this->validate($request, [
            'name'          => 'required|string|min:2',
            'permission'    => 'required|array',
            'permission.*'  => 'required|string',
        ]);

        $role = Role::find($id);
        $role->name = $request->input('name');
        $role->save();

        $role->syncPermissions($request->input('permission'));
        $this->invalidateCommittedPermissions();

        return redirect()->route('roles.index')
                        ->with('success',trans('messages.UpdateSuccessfully'));
    }
    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        DB::table("roles")->where('id',$id)->delete();
        // This original query bypasses Role's automatic cache invalidation.
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->invalidateCommittedPermissions();
        return redirect()->route('roles.index')
                        ->with('success',trans('messages.DeleteSuccessfully'));
    }
    
    private function invalidateCommittedPermissions(): void
    {
        // A concurrent request may cache the previous committed grants after the
        // immediate Spatie flush but before an outer desktop transaction commits.
        DB::afterCommit(fn()=>app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions());
    }

     public function deleteAll(Request $request)
    {
        $ids = $request->ids;
        Role::whereIn('id',explode(",",$ids))->delete();
        return response()->json(['success'=> trans('messages.RecordsDeleteSuccessfully')]);
    }

}
