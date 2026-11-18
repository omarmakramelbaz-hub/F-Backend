<?php
namespace App\Services\Dashboard;

use Illuminate\Http\UploadedFile;

/** Same Laravel upload, with one operation-bound immutable name instead of random replay names. */
class DesktopDashboardGoProductUpload extends UploadedFile
{
    public function __construct(string $path,string $name,string $mime,private string $desktopName)
    {parent::__construct($path,$name,$mime,null,true);}
    public function hashName($path=null)
    {return ($path?rtrim($path,'/').'/':'').$this->desktopName;}
    public function store($path='',$options=[])
    {return app(DesktopDashboardGoProductImages::class)->store($this,$path,$options,fn()=>parent::store($path,$options));}
}
