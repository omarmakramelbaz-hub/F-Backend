<?php
namespace App\Services\Dashboard;

use Illuminate\Http\UploadedFile;

/** An original Laravel upload with a slot/operation-owned physical destination. */
final class DesktopDashboardSettingsUpload extends UploadedFile
{
    public function __construct(string $path,string $name,string $mime,public string $desktopSlot,public string $desktopCommand)
    {parent::__construct($path,$name,$mime,null,true);}
    public function storeAs($path,$name=null,$options=[])
    {return app(DesktopDashboardSettingsImages::class)->store($this,$path,$name,$options);}
}
