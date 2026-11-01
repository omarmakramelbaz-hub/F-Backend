<?php
namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\OwnerDashboards\Runtime;

final class OwnerDashboardFilesController extends Controller
{
    public function show(string $path)
    {
        abort_unless(Runtime::tenant(),404);
        // The login logo is a bundled asset. Tenant account/business media requires its own authenticated session.
        abort_unless($path==='owner-dashboard-logo.png'||auth('admin')->check(),403);
        abort_if(str_contains($path,"\0")||preg_match('#(?:^|/)[.]{1,2}(?:/|$)#',$path),404);
        $root=realpath(storage_path('app/public'));$file=realpath(storage_path('app/public/'.$path));
        abort_unless($root&&$file&&str_starts_with($file,$root.DIRECTORY_SEPARATOR)&&is_file($file),404);
        $extension=strtolower(pathinfo($file,PATHINFO_EXTENSION));
        abort_unless(in_array($extension,['jpg','jpeg','png','gif','webp','ico','pdf','mp4','mp3'],true),404);
        return response()->file($file,['Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff']);
    }
}
