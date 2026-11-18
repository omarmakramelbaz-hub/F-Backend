<?php
namespace App\Http\Traits;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;
use File;
trait UploadImageTrait {

     public $public_path = "/public/uploadedImages/";
    public $storage_path = "/storage/uploadedImages/";

    public function storeFile( $file, $folderPath,$path, $width=null, $height=null ) : string
    {
       if ( $file ) {

           $extension       = $file->getClientOriginalExtension();
           $file_name       = $path.'-'.time().'.'.$extension;
           $url             = $file->storeAs($folderPath,$file_name);
           $public_path     = public_path($folderPath.$file_name);
           // $img             = Image::make($public_path);
           $url             = preg_replace( "/public/", "", $url );
           return  $url ;
       }
    }
    
    public function uploadImg( $file, $path, $width=null, $height=null ) : string
    {
       if ( $file ) {

           $extension       = $file->getClientOriginalExtension();
           $file_name       = $path.'-'.time().'.'.$extension;
           $url             = $file->storeAs($this->public_path,$file_name);
           $public_path     = public_path($this->storage_path.$file_name);
           // $img             = Image::make($public_path);
           $url             = preg_replace( "/public/", "", $url );
           if($file instanceof \App\Services\Dashboard\DesktopDashboardSettingsUpload)$url=ltrim($url,'/');
           return  $url ;
       }
    }
    public function convertImageToWebp($filePath, $varCreated ,$inputName, $folderName){
        $destinationPath = 'images';
        $myimage = $filePath->getClientOriginalName();
        $imagePath= $filePath->move(public_path($destinationPath), $myimage);
        //Create an image object.
    $ext = ['image/png','image/jpg','image/jpeg','image/webp','image/svg+xml'];
    if( in_array($filePath->getClientMimeType(), $ext)){
        if($filePath->getClientMimeType() == 'image/png'){
        $im = imagecreatefromstring(file_get_contents(($imagePath)));
        $newImagePath = str_replace("png", "webp", $imagePath);

        }elseif($filePath->getClientMimeType() == 'image/jpg'){
            $im = imagecreatefromjpeg($imagePath);
        //The path that we want to save our webp file to.
        $newImagePath = str_replace("jpg", "webp", $imagePath);
        }elseif($filePath->getClientMimeType() == 'image/jpeg'){
            $im = imagecreatefromjpeg($imagePath);
        //The path that we want to save our webp file to.
        $newImagePath = str_replace("jpeg", "webp", $imagePath);
        }elseif($filePath->getClientMimeType() == 'image/webp'){
            // $im = imagecreatefromwebp($imagePath);
            $im = imagecreatefromstring(file_get_contents(($imagePath)));
        //The path that we want to save our webp file to.
        $newImagePath = str_replace("webp", "webp", $imagePath);
        }else{
            $newImagePath = $imagePath;
        }
        if($filePath->getClientMimeType() != 'image/svg+xml'){
        //Quality of the new webp image. 1-100.
        //Reduce this to decrease the file size.
        $quality = 70;
        imagepalettetotruecolor($im);
        //Create the webp image.
        $x =imagewebp($im, $newImagePath, $quality);      
        }      
        $varCreated->addMedia($newImagePath)->toMediaCollection($inputName, $folderName);
        File::delete($imagePath);
    }
    else{
        return false;
    }
    }
    public function uploadVideo( $file, $path) : string
    {
       if ( $file ) {

           $extension       = $file->getClientOriginalExtension();
           $file_name       = $path.'-'.time().'.'.$extension;
           $url             = $file->storeAs($this->public_path,$file_name);
           $public_path     = public_path($this->storage_path.$file_name);
           $url             = '/uploadedImages/';
           // dd($url);
           return $file->move($url, $file_name);
       }
    }
}