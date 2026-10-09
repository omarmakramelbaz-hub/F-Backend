<?php

namespace App\Http\Controllers\Dashboard;
use App\Interfaces\ProductRepositoryInterface;
use App\Models\Product;
use App\Http\Requests\Dashboard\Product\StoreProductRequest;
use App\Http\Traits\UploadImageTrait;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\ProductFeature;
use App\Models\ResturantProduct;
class ProductController extends Controller
{
    use UploadImageTrait;
    private ProductRepositoryInterface $productRepository;

    public function __construct(ProductRepositoryInterface $productRepository) 
    {
        $this->middleware('permission:product-list', ['only' => ['index','show']]);
        $this->middleware('permission:product-create', ['only' => ['create','store']]);
        $this->middleware('permission:product-edit', ['only' => ['update','edit']]);
        $this->middleware('permission:product-delete', ['only' => ['destroy','delete_all','deleteAll']]);
        $this->productRepository = $productRepository;
    }


    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $products = $this->productRepository->getAllProducts($request);
        return view('admin.products.index', compact('products'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $product = new Product() ;
        return view('admin.products.create', compact('product'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \App\Http\Requests\StoreproductRequest  $request
     * @return \Illuminate\Http\Response
     */
    public function store(StoreProductRequest $request)
    {
        $productDetails = $request->except('_token');

        $product = $this->productRepository->createProduct($productDetails);
        
        return redirect('admin/products')->with('success',trans('messages.AddSuccessfully'));
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\product  $product
     * @return \Illuminate\Http\Response
     */
    public function show(Product $product)
    {
        return view('admin.products.show', compact('product'));
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\product  $product
     * @return \Illuminate\Http\Response
     */
    public function edit(Product $product)
    {
        return view('admin.products.edit', compact('product'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \App\Http\Requests\UpdateproductRequest  $request
     * @param  \App\Models\product  $product
     * @return \Illuminate\Http\Response
     */
    public function update(StoreProductRequest $request, product $product)
    {
        $productDetails = $request->except('_token','_method');
        $this->productRepository->updateProduct($product->id, $productDetails);
    
        return redirect('admin/products')->with('success',trans('messages.UpdateSuccessfully'));
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\product  $product
     * @return \Illuminate\Http\Response
     */
    public function destroy(Product $product)
    {
        $this->productRepository->deleteProduct($product->id);
        return redirect('admin/products')->with('success',trans('messages.DeleteSuccessfully'));
    }
    public function deleteAll(Request $request)
    {  
        $ids = $request->ids;
        $this->productRepository->deleteAllProducts($ids);
        return response()->json(['success'=> trans('messages.RecordsDeleteSuccessfully')]);
    }

    public function fetchSubcategory(Request $request)
    {
        if($request->ajax()){
            $product = Product::where('id',$request->product_id)->first();
            $category = Category::where('parent_id',$request->category_id)->get();
            $data = view('admin.products.ajax-subcategory-select',compact('category','product'))->render();
            return response()->json(['options'=>$data,'product' => $product,'category' => $category]);
        }
    }
    public function fetchProduct(Request $request)
    {
        // dd($request->all());
        if($request->ajax()){
            $product = Product::where('id',$request->product_id)->first();
            $subcategory = Product::where('subcategory_id',$request->subcategory_id)->get();
            if($subcategory->isEmpty())
            {
                $subcategory = Product::where('category_id',$request->subcategory_id)->get();
            }
            $data = view('admin.products.ajax-product-select',compact('subcategory','product'))->render();
            return response()->json(['options'=>$data,'product' => $product,'subcategory' => $subcategory]);
        }
    }
    
    public function fetchFeature(Request $request)
    {
        if($request->ajax()){
            $product = Product::where('id',$request->product_id)->first();
            $features = ProductFeature::where('product_id',$request->product_id)->get();
            $resturant_product = ResturantProduct::find($request->id);
            $data = view('admin.products.ajax-product-feature',compact('features','product','resturant_product'))->render();
            return response()->json(['options'=>$data,'product' => $product,'features' => $features,'resturant_product' => $resturant_product]);
        }
    }
    
    public function previewMenu(){
        $products = Category::with('childs','category_products')->orderBy('order','asc')->cursor();
        return view('admin.products.preview-menu', compact('products')); 
    }
}
