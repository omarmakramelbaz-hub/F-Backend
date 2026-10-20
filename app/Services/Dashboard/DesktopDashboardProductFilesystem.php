<?php
namespace App\Services\Dashboard;

use Illuminate\Contracts\Filesystem\Factory;
use Spatie\MediaLibrary\MediaCollections\Filesystem;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/** Scoped binding for the server and bundled media library engines. */
class DesktopDashboardProductFilesystem extends Filesystem
{
    public function __construct(Factory $filesystem,private DesktopDashboardProductImages $images) {parent::__construct($filesystem);}
    public function removeAllFiles(Media $media): void
    {
        if($this->images->preserves($media))return;
        abort_if($this->images->handlesMedia($media),409,'حذف صورة المنتج يحتاج حقائق محفوظة وصلاحية الفرع الحالية.');
        parent::removeAllFiles($media);
    }
    public function copyToMediaLibrary(string $pathToFile,Media $media,?string $type=null,?string $targetFileName=null): void
    {
        if($this->images->handlesMedia($media)){
            $this->images->copy($pathToFile,$media,$type,$targetFileName);return;
        }
        parent::copyToMediaLibrary($pathToFile,$media,$type,$targetFileName);
    }
}
