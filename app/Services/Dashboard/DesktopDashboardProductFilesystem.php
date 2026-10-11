<?php
namespace App\Services\Dashboard;

use Illuminate\Contracts\Filesystem\Factory;
use Spatie\MediaLibrary\MediaCollections\Filesystem;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/** Scoped binding for the pinned 9.12.4 package; ordinary requests retain its original filesystem. */
class DesktopDashboardProductFilesystem extends Filesystem
{
    public function __construct(Factory $filesystem,private DesktopDashboardProductImages $images) {parent::__construct($filesystem);}
    public function removeAllFiles(Media $media): void
    {
        if($this->images->preserves($media))return;
        abort_if($media->model_type==='App\\Models\\ResturantProduct',409,'حذف صورة المنتج يحتاج حقائق محفوظة وصلاحية الفرع الحالية.');
        parent::removeAllFiles($media);
    }
    public function copyToMediaLibrary(string $pathToFile,Media $media,?string $type=null,?string $targetFileName=null)
    {
        if($media->model_type==='App\\Models\\ResturantProduct'){
            $this->images->copy($pathToFile,$media,$type,$targetFileName);return;
        }
        return parent::copyToMediaLibrary($pathToFile,$media,$type,$targetFileName);
    }
}
