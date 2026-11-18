<?php

namespace App\Session;

use Illuminate\Session\FileSessionHandler;

/**
 * The file sessions, saved whole or not at all. Laravel saves a session file by emptying it and then writing it, so a
 * request of the same device that reads it in between finds it empty: the account is signed out, the page's token no
 * longer matches (419 PAGE EXPIRED) and that request then saves the empty session for good. A busy device (a branch
 * device polling its orders, prints and alerts every few seconds) hits that every so often. Here each save is written
 * to a file of its own next to it and then put in its place at once, so a read finds either the previous session or
 * the new one, never part of it.
 */
class AtomicFileSessionHandler extends FileSessionHandler
{
    public function write($sessionId, $data): bool
    {
        $path = $this->path.'/'.$sessionId;
        $temporary = $path.'.'.bin2hex(random_bytes(6)).'.saving';
        if (file_put_contents($temporary, $data) !== strlen($data) || !rename($temporary, $path)) {
            @unlink($temporary);
            // As Laravel saves it, rather than losing the session.
            return parent::write($sessionId, $data);
        }
        return true;
    }
}
