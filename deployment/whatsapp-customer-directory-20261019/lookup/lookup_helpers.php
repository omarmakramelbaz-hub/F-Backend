<?php
declare(strict_types=1);
class LegacyCustomerImportException extends \RuntimeException {}
function lci_fail(string $message): never { throw new LegacyCustomerImportException($message); }
function lci_json(array $value): string { return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES); }

function lci_private_directory(string $path, string $root): string {
    if ($path==='' || $path[0]!=='/' || strpos($path,"\0")!==false) lci_fail('Backup directory must be an absolute path.');
    $parts=explode('/',trim($path,'/'));
    if (in_array('..',$parts,true)||in_array('.',$parts,true)||$path==='/') lci_fail('Backup directory path is unsafe.');
    $probe=rtrim($path,'/'); $missing=[];
    while (!file_exists($probe)) { array_unshift($missing,basename($probe)); $probe=dirname($probe); }
    $real=realpath($probe); if ($real===false) lci_fail('Cannot resolve backup directory.');
    $resolved=rtrim($real,'/').($missing?'/'.implode('/',$missing):'');
    if ($resolved===$root || str_starts_with($resolved,$root.'/')) lci_fail('Backup directory must be outside the Laravel web root.');
    if (is_link($path)) lci_fail('Backup directory must not be a symlink.');
    if (!is_dir($path) && !mkdir($path,0700,true)) lci_fail('Cannot create backup directory.');
    if (!chmod($path,0700)) lci_fail('Cannot secure backup directory.');
    return realpath($path) ?: lci_fail('Cannot resolve created backup directory.');
}

function lci_private_json(string $path, array $value): void {
    $h=fopen($path,'x'); if ($h===false) lci_fail('Cannot create private backup snapshot.');
    try {
        if (!chmod($path,0600)) lci_fail('Cannot secure private snapshot.');
        $bytes=lci_json($value)."\n"; $offset=0;
        while($offset<strlen($bytes)) { $n=fwrite($h,substr($bytes,$offset)); if($n===false||$n===0) lci_fail('Cannot write complete backup snapshot.'); $offset+=$n; }
        if (!fflush($h)) lci_fail('Cannot flush private snapshot.');
        if (function_exists('fsync') && !fsync($h)) lci_fail('Cannot sync private snapshot.');
    } finally { fclose($h); }
}

