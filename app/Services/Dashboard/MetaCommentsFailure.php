<?php

namespace App\Services\Dashboard;

/** Fixed public codes only: never retain Graph response bodies or credential details. */
class MetaCommentsFailure extends \RuntimeException
{
    public string $reason;
    public int $status;
    public array $permissions;

    public function __construct(string $reason, int $status = 503, array $permissions = [])
    {
        parent::__construct($reason);
        $this->reason = $reason;
        $this->status = $status;
        $this->permissions = $permissions;
    }
}
