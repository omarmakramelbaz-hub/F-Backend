$ErrorActionPreference = 'Stop'
try {
    Add-Type -TypeDefinition @'
using System;
using System.Text;
using System.Runtime.InteropServices;
public static class DisposableMediaPathProbe {
    [DllImport("kernel32.dll", CharSet = CharSet.Unicode, SetLastError = true)]
    public static extern uint GetShortPathName(string longPath, StringBuilder shortPath, uint capacity);
}
'@
    $probeInput = [Console]::In.ReadToEnd()
    $probeOutput = New-Object System.Text.StringBuilder 32768
    $probeLength = [DisposableMediaPathProbe]::GetShortPathName($probeInput, $probeOutput, 32768)
    if ($probeLength -eq 0 -or $probeLength -ge 32768) { exit 2 }
    [Console]::Out.Write($probeOutput.ToString())
    exit 0
} catch {
    # The caller consumes this stream privately. Never relay a user path,
    # native exception or PowerShell diagnostic to the workflow output.
    exit 3
}
