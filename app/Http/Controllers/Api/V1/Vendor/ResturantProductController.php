<?php
namespace App\Http\Controllers\Api\V1\Vendor;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Resturant;
use App\Models\ResturantProduct;
use Illuminate\Http\Request;
use App\Http\Traits\UploadImageTrait;
use App\Http\Requests\Api\Auth\StoreUserResturantProductRequest;
use App\Http\Requests\Api\Vendor\CopyMenuRequest;
use App\Http\Resources\Api\Vendor\ResturantProductResource;
use App\Http\Resources\Api\Vendor\UserResturantProductResource;
use App\Http\Requests\Api\Vendor\UpdateStatusRequest;
use App\Http\Resources\Api\Home\ProductCategoryResource;
use App\Http\Traits\ApiResponses;
use Notification;
use JWTAuth;
use Validator;
use Auth;
use \Carbon\Carbon;
use App\Interfaces\ResturantProductRepositoryInterface;
use Illuminate\Support\Facades\Storage;

class ResturantProductController extends Controller {

  use ApiResponses;
  use UploadImageTrait;

    private ResturantProductRepositoryInterface $ResturantProductRepository;
    public function __construct(ResturantProductRepositoryInterface $ResturantProductRepository) 
    {      
        $this->ResturantProductRepository = $ResturantProductRepository;
    }
  public function index(Request $request) {
    $ResturantProduct = $this->ResturantProductRepository->getAllResturantProducts($request);
    $ResturantProductData = ResturantProductResource::collection($ResturantProduct);
    return $this->successResponse($ResturantProductData,__('api.get all items'));
  }

   public function store(Request $request) {
    $ResturantProduct = $this->ResturantProductRepository->createResturantProduct($request->except('_token')+['added_by' => auth('api')->user()->id]);
    $ResturantProductData = ResturantProductResource::make($ResturantProduct);
    return $this->successResponse('success',__('api.create new item'));
  }
  
  
   public function update(Request $request,ResturantProduct $menu) {
    $ResturantProduct = $this->ResturantProductRepository->updateResturantProduct($menu->id, $request->except('_token')+['added_by' => auth('api')->user()->id]);
    $ResturantProductData = ResturantProductResource::make($menu->fresh());
    return $this->successResponse($ResturantProductData,__('api.update item'));
  }


  public function destroy(ResturantProduct $menu) {
    $ResturantProduct = $this->ResturantProductRepository->deleteResturantProduct($menu->id);
    return $this->successResponse($ResturantProduct,__('api.delete item'));
  }
   public function show($id){
      $product=ResturantProduct::find($id);
      if($product ){
          $productData =new ResturantProductResource($product);
          return $this->successResponse($productData,__('api.get single Product'));
      }
      return $this->errorResponse(__('api.this product not found'));
  }
  
 public function copy_menu(CopyMenuRequest $request){
        $resturant=Resturant::find($request->resturant_id);
          foreach(array_values($request->restraunt_product) as $selectionIndex => $restraunt_product){
            $mainproduct=ResturantProduct::find($restraunt_product);
            $resturant_productt=$resturant->resturant_products->where('product_id',$mainproduct->product_id)->first();
                if(!$resturant_productt){
                    $product=$mainproduct->replicate();
                    $product->resturant_id = $request->resturant_id;
                    $product->added_by=auth('api')->check()? auth('api')->user()->id : auth('admin')->user()->id;
                    $product->save();
                    // Resolve the same first image through its original media disk.
                    // A temporary basename loses the source name and image extension.
                    $media = $mainproduct->getFirstMedia('product_image');
                    if ($media) {
                        app(\App\Services\Dashboard\DesktopDashboardProductImages::class)->withMenuCopy(
                            $selectionIndex, $mainproduct, $product, function () use ($mainproduct, $product, $media) {
                                $mediaPath = $mainproduct->getFirstMediaPath('product_image', 'thumb');
                                return $product->addMedia($mediaPath)
                                    ->preservingOriginal()
                                    ->usingName($media->name)
                                    ->usingFileName(basename($mediaPath))
                                    ->toMediaCollection('product_image', 'products');
                            }
                        );
                    }
                }
                else{
                   $resturant_productt->update([
                       'product_price'=>$mainproduct->product_price,
                       ]) ;
                }
          }
      $resturantData =ProductCategoryResource::collection($resturant->resturant_category_products());
       if (request()->wantsJson() || request()->is('api/*')) {
         return $this->successResponse($resturantData,__('api.get single resturant'));
       }else{
           return redirect()->back()->with(['success'=>__('main.menue copied successfully')]);
       }
  }
  
  public function update_item_status(UpdateStatusRequest $request,ResturantProduct $menu) {
    $ResturantProduct = $this->ResturantProductRepository->updateResturantProduct($menu->id, $request->except('_token')+['added_by' => auth('api')->user()->id]);
    $ResturantProductData = ResturantProductResource::make($menu->fresh());
    return $this->successResponse($ResturantProductData,__('api.update item'));
  }

}