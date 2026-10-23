$ErrorActionPreference = 'Stop'
# Read only: run the retained Preview31 PHP with the exact generated import ini.
# No Laravel bootstrap, database connection, application launch or profile changes.
try {
    $goExpectedRevision = '27eae737be190a9900fed67810393a0b68627119'
    $goProfile = Join-Path $env:APPDATA 'fasakhansta-dashboard-preview\dashboard'
    $goInstalled = Get-ItemProperty -LiteralPath 'HKCU:\Software\Microsoft\Windows\CurrentVersion\Uninstall\FasakhanstaDashboardPreview'
    if ($goInstalled.DisplayName -ne 'GO ERP' -or $goInstalled.DisplayVersion -ne '0.4.4-preview.31') {
        throw 'GO_ERP_EXPECTED_PREVIEW31'
    }
    if ($goInstalled.UninstallString -notmatch '^"([A-Za-z]:\\[^\"]+)\\Uninstall\.exe"$') {
        throw 'GO_ERP_INSTALL_DIRECTORY_UNVERIFIED'
    }
    $goBuildPath = Join-Path $Matches[1] 'resources\desktop-build.json'
    $goBuild = Get-Content -LiteralPath $goBuildPath -Raw | ConvertFrom-Json
    if ($goBuild.version -ne '0.4.4-preview.31' -or $goBuild.sourceRevision -ne $goExpectedRevision) {
        throw 'GO_ERP_EXPECTED_PREVIEW31_BUILD'
    }
    $goRuntime = Join-Path $goProfile ('runtimes\' + $goExpectedRevision)
    $goPhp = Join-Path $goRuntime 'php\php.exe'
    $goApplication = Join-Path $goRuntime 'application'
    $goIni = Join-Path $goProfile ('php-' + $goExpectedRevision + '.ini')
    $goReceipt = Join-Path $goProfile ('runtimes\' + $goExpectedRevision + '.enc')
    foreach ($goFile in @($goPhp, $goIni, $goReceipt)) {
        if (-not (Test-Path -LiteralPath $goFile -PathType Leaf)) {
            throw 'GO_ERP_RETAINED_RUNTIME_NOT_AVAILABLE'
        }
    }
    if (-not (Test-Path -LiteralPath $goApplication -PathType Container)) {
        throw 'GO_ERP_RETAINED_APPLICATION_NOT_AVAILABLE'
    }
    $goCode = @'
$expected=$argv[1];$loaded=php_ini_loaded_file();
if(PHP_SAPI!=='cli'||!$loaded||realpath($loaded)!==realpath($expected))exit(2);
echo json_encode(['result'=>'GO_ERP_DESKTOP_IMPORT_PHP_SETTINGS','sapi'=>PHP_SAPI,
 'php_version'=>PHP_VERSION,'php_int_size'=>PHP_INT_SIZE,
 'serialize_precision'=>ini_get('serialize_precision'),'precision'=>ini_get('precision'),
 'memory_limit'=>ini_get('memory_limit'),'expected_ini_loaded'=>true,
 'additional_ini_files'=>php_ini_scanned_files()!==false&&trim(php_ini_scanned_files())!=='',
 'float_encoding_control'=>json_encode(0.1,JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR)],
 JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
'@
    $goOldScanDir = [Environment]::GetEnvironmentVariable('PHP_INI_SCAN_DIR', 'Process')
    try {
        [Environment]::SetEnvironmentVariable('PHP_INI_SCAN_DIR', (Join-Path $goRuntime 'php\conf.d'), 'Process')
        Push-Location -LiteralPath $goApplication
        try {
            $goIniArgument = '../../../php-' + $goExpectedRevision + '.ini'
            $goOutput = & $goPhp '-c' $goIniArgument '-d' 'memory_limit=-1' '-r' $goCode $goIni 2>$null
            $goPhpExitCode = $LASTEXITCODE
        } finally {
            Pop-Location
        }
    } finally {
        [Environment]::SetEnvironmentVariable('PHP_INI_SCAN_DIR', $goOldScanDir, 'Process')
    }
    if ($goPhpExitCode -ne 0) { throw 'GO_ERP_PHP_SETTINGS_CHECK_FAILED' }
    $goResult = ($goOutput -join "`n") | ConvertFrom-Json
    if ($goResult.result -ne 'GO_ERP_DESKTOP_IMPORT_PHP_SETTINGS' -or
        $goResult.expected_ini_loaded -isnot [bool] -or
        $goResult.expected_ini_loaded -ne $true -or $goResult.sapi -ne 'cli' -or
        $goResult.php_version -isnot [string] -or
        $goResult.php_version -notmatch '^[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}[-A-Za-z0-9.]{0,24}\z' -or
        ($goResult.php_int_size -isnot [int] -and $goResult.php_int_size -isnot [long]) -or
        $goResult.php_int_size -notin @(4, 8) -or
        $goResult.serialize_precision -isnot [string] -or
        $goResult.serialize_precision -notmatch '^(?:-1|[0-9]{1,4})\z' -or
        $goResult.precision -isnot [string] -or
        $goResult.precision -notmatch '^(?:-1|[0-9]{1,4})\z' -or
        $goResult.memory_limit -ne '-1' -or
        $goResult.additional_ini_files -isnot [bool] -or
        $goResult.float_encoding_control -isnot [string] -or
        $goResult.float_encoding_control.Length -gt 128 -or
        $goResult.float_encoding_control -notmatch '^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?\z') {
        throw 'GO_ERP_PHP_SETTINGS_OUTPUT_UNVERIFIED'
    }
    [ordered]@{
        result = 'GO_ERP_DESKTOP_IMPORT_PHP_SETTINGS'
        sapi = 'cli'
        php_version = $goResult.php_version
        php_int_size = $goResult.php_int_size
        serialize_precision = $goResult.serialize_precision
        precision = $goResult.precision
        memory_limit = '-1'
        expected_ini_loaded = $true
        additional_ini_files = $goResult.additional_ini_files
        float_encoding_control = $goResult.float_encoding_control
    } | ConvertTo-Json -Depth 3
} catch {
    $goReason = 'GO_ERP_PHP_SETTINGS_CHECK_UNAVAILABLE'
    if ($_.Exception.Message -match '^GO_ERP_[A-Z0-9_]+$') { $goReason = $_.Exception.Message }
    [ordered]@{ result = 'GO_ERP_DESKTOP_IMPORT_PHP_SETTINGS_FAILED'; reason = $goReason } | ConvertTo-Json
    exit 1
}
