<?php

namespace App\Http\Controllers\Dashboard;
use App\Interfaces\CategoryRepositoryInterface;
use App\Models\Category;
use App\Http\Requests\Dashboard\Product\StoreCategoryRequest;
use App\Http\Traits\UploadImageTrait;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class CategoryController extends Controller
{
    use UploadImageTrait;
    private CategoryRepositoryInterface $categoryRepository;

    public function __construct(CategoryRepositoryInterface $categoryRepository) 
    {
        $this->middleware('permission:category-list', ['only' => ['index','show']]);
        $this->middleware('permission:category-create', ['only' => ['create','store']]);
        $this->middleware('permission:category-edit', ['only' => ['update','edit','updateColumns']]);
        $this->middleware('permission:category-delete', ['only' => ['destroy','delete_all','deleteAll']]);
        $this->categoryRepository = $categoryRepository;
    }

    public function updateColumns(Request $request)
    {
        app(\App\Services\Dashboard\CategoryOrdering::class)->apply($request->only('order'));
        return response()->json(['status'=>'success']);
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $categorys = $this->categoryRepository->getAllCategorys($request);
        return view('admin.categorys.index', compact('categorys'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $category = new Category() ;
        return view('admin.categorys.create', compact('category'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \App\Http\Requests\StoreCategoryRequest  $request
     * @return \Illuminate\Http\Response
     */
    public function store(StoreCategoryRequest $request)
    {
        $categoryDetails = $request->except('_token','parent');

        $category = $this->categoryRepository->createCategory($categoryDetails);
        return redirect('admin/categorys?parent='.request('parent'))->with('success',trans('messages.AddSuccessfully'));
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Category  $category
     * @return \Illuminate\Http\Response
     */
    public function show(Category $category)
    {
        return view('admin.categorys.show', compact('category'));
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\Category  $category
     * @return \Illuminate\Http\Response
     */
    public function edit(Category $category)
    {
        return view('admin.categorys.edit', compact('category'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \App\Http\Requests\UpdateCategoryRequest  $request
     * @param  \App\Models\Category  $category
     * @return \Illuminate\Http\Response
     */
    public function update(StoreCategoryRequest $request, Category $category)
    {
        $categoryDetails = $request->except('_token','parent','_method');
        $this->categoryRepository->updateCategory($category->id, $categoryDetails);
    
        return redirect('admin/categorys?parent='.request('parent'))->with('success',trans('messages.UpdateSuccessfully'));
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Category  $category
     * @return \Illuminate\Http\Response
     */
    public function destroy(Category $category)
    {
        $this->categoryRepository->deleteCategory($category->id);
        return redirect('admin/categorys?parent='.request('parent'))->with('success',trans('messages.DeleteSuccessfully'));
    }
    public function deleteAll(Request $request)
    {  
        $ids = $request->ids;
        
        $this->categoryRepository->deleteAllCategorys($ids);
        return response()->json(['success'=> trans('messages.RecordsDeleteSuccessfully')]);

    }

}
