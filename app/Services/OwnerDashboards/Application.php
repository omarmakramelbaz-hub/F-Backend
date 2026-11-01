<?php
namespace App\Services\OwnerDashboards;

/** A tenant never loads the primary installation's cached config/routes/providers or storage. */
final class Application extends \Illuminate\Foundation\Application
{
    private string $ownerPublic;
    public function __construct(string $basePath,string $public){$this->ownerPublic=$public;parent::__construct($basePath);}
    protected function normalizeCachePath($key,$default){return $this->bootstrapPath($default);}
    public function publicPath(){return $this->ownerPublic;}
}
