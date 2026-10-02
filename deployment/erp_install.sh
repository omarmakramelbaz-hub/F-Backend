#!/usr/bin/env bash
# Operator console only. Never run through the deploy-only SSH identity.
# Only the reviewed ERP migrations run; no production seeder or DB rollback.
set -Eeuo pipefail
umask 022
export LC_ALL=C
unset TAR_OPTIONS GZIP POSIXLY_CORRECT

root=${1:?application root required}
backup=${2:?verified backup directory required}
target=${3:?reviewed commit required}
owner=${4:?verified owner ID required}
owner_name=${5:?verified owner name required}
base=7f34909e446f718d83ee1d05fb92bcaaac97cc42
[[ $target =~ ^[0-9a-f]{40}$ && $owner =~ ^[1-9][0-9]*$ && $EUID -ne 0 ]] || { echo 'STOP: run as the application owner, with explicit release/owner arguments.'; exit 1; }
[[ $(realpath "$root") == "$root" && $(realpath "$backup") == "$backup" ]] || { echo 'STOP: noncanonical path.'; exit 1; }
[[ $backup == "$(dirname "$root")/erp-release-backups/"* ]] || { echo 'STOP: unexpected backup directory.'; exit 1; }
cd "$root"
for tool in php git flock getfacl setfacl curl; do command -v "$tool" >/dev/null || { echo "STOP: required tool missing: $tool"; exit 1; }; done
[[ -f artisan && -f vendor/autoload.php && -f .env && ! -L .env ]] || { echo 'STOP: invalid application files.'; exit 1; }
[[ $(stat -c %u .env) == "$EUID" ]] || { echo 'STOP: environment ownership needs review; unchanged.'; exit 1; }
[[ $(git rev-parse --show-toplevel) == "$root" && $(git rev-parse HEAD) == "$base" ]] || { echo 'STOP: live checkout no longer matches the reviewed backup base.'; exit 1; }
[[ -z $(git --no-optional-locks status --porcelain --untracked-files=no) ]] || { echo 'STOP: tracked local edits; nothing overwritten.'; exit 1; }
git cat-file -e "$target^{commit}"
git merge-base --is-ancestor "$base" "$target"
[[ ! -e storage/framework/down ]] || { echo 'STOP: application was already in maintenance; left unchanged.'; exit 1; }
[[ ! -e public/erp && ! -e erp ]] || { echo 'STOP: an existing ERP directory needs review.'; exit 1; }

umask 077
exec 9>"$(dirname "$root")/.erp-install.lock"
flock -n 9 || { echo 'STOP: another ERP install is active.'; exit 1; }
run=$(mktemp -d "$(dirname "$root")/erp-install-$(date -u +%Y%m%dT%H%M%S)-XXXXXX")
log="$run/private.log"
touch "$log"
getfacl -cp .env > "$run/env.acl"
if grep -Eq '^(user|group):[^:]|^mask:|^default:' "$run/env.acl"; then
    echo "STOP: environment ACL needs review; unchanged. PRIVATE_LOG=$log"; exit 1
fi
cp -p .env "$run/environment.before"
chmod 600 "$run/environment.before"
umask 022
printf 'RELEASE_LOG=%s\n' "$log"
phase=backup-verification
maintenance=0
switched=0
env_changed=0
success=0
# Helpers are outside the web root and survive failures for diagnosis.
cat > "$run/env.php" <<'PHP'
<?php
function patchErpEnvironment(string $text, array $values): string {
    $eol = str_contains($text, "\r\n") ? "\r\n" : "\n";
    foreach ($values as $key => $value) {
        if (!in_array($key, ['ERP_ENABLED','ERP_OWNER_USER_ID','APP_DEBUG','SESSION_SECURE_COOKIE'], true)
            || !preg_match('/^(true|false|[1-9][0-9]*)$/D', $value)) { throw new RuntimeException('Invalid setting'); }
        $pattern = '/^[\t ]*(?:export[\t ]+)?'.preg_quote($key,'/').'[\t ]*=.*$/m';
        $count = preg_match_all($pattern, $text);
        if ($count > 1) { throw new RuntimeException('Duplicate setting'); }
        // Preserve the original line ending; no unrelated setting is rewritten.
        if ($count === 1) {
            $text = preg_replace_callback($pattern, fn($m) => $key.'='.$value.(str_ends_with($m[0],"\r")?"\r":""), $text);
        } else { $text .= ($text === '' || str_ends_with($text,"\n") ? '' : $eol).$key.'='.$value.$eol; }
    }
    return $text;
}
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) { return; }
try {
    [$script,$path,$directory,$owner,$enabled] = $argv;
    if (is_link($path) || !is_file($path)) { throw new RuntimeException('Unsafe environment path'); }
    $stat = stat($path); $before = file_get_contents($path);
    if ($stat === false || $before === false) { throw new RuntimeException('Cannot read environment'); }
    $after = patchErpEnvironment($before, ['APP_DEBUG'=>'false','ERP_ENABLED'=>$enabled,'ERP_OWNER_USER_ID'=>$owner,'SESSION_SECURE_COOKIE'=>'true']);
    $temp = tempnam($directory,'env-next-');
    try {
        if (file_put_contents($temp,$after) !== strlen($after) || !chmod($temp,$stat['mode'] & 0777)
            || !chgrp($temp,$stat['gid']) || fileowner($temp) !== $stat['uid']
            || hash_file('sha256',$path) !== hash('sha256',$before) || !rename($temp,$path)) {
            throw new RuntimeException('Environment changed or atomic replacement failed');
        }
    } finally { if (is_file($temp)) { unlink($temp); } }
} catch (Throwable $e) { fwrite(STDERR,"Environment update stopped; private configuration was not printed.\n"); exit(1); }
PHP

safe_command() { "$@" >>"$log" 2>&1; }
set_env() { safe_command php "$run/env.php" "$root/.env" "$run" "$owner" "$1"; }
clear_caches() {
    safe_command php artisan config:clear && safe_command php artisan route:clear && safe_command php artisan view:clear
}
finish() {
    code=$?
    trap - EXIT INT TERM HUP
    set +e
    if [[ $success == 1 ]]; then return; fi
    echo "ERP_INSTALL_STOPPED_AT=$phase"
    recovered=1
    if [[ $env_changed == 1 ]]; then set_env false || recovered=0; fi
    if [[ $switched == 1 ]]; then
        if [[ $(git rev-parse HEAD) == "$target" && -z $(git --no-optional-locks status --porcelain --untracked-files=no) ]]; then
            safe_command git -c core.hooksPath=/dev/null checkout --detach --no-overwrite-ignore "$base" || recovered=0
        else recovered=0; fi
    fi
    if [[ $env_changed == 1 || $switched == 1 ]]; then clear_caches || recovered=0; fi
    if [[ $maintenance == 1 && $recovered == 1 ]]; then safe_command php artisan up || recovered=0; fi
    if [[ $recovered == 1 ]]; then
        echo 'ERP_DISABLED_OR_NOT_INSTALLED; PREVIOUS_CODE_RETAINED_OR_RESTORED; DATABASE_TABLES_PRESERVED'
    else echo 'OPERATOR_REVIEW_REQUIRED; DO_NOT_RETRY_OR_RESTORE_THE_DATABASE'; fi
    printf 'PRIVATE_LOG=%s\n' "$log"
    [[ $code != 0 ]] || code=1
    exit "$code"
}
trap finish EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
trap 'exit 129' HUP

echo 'STEP: verifying the saved application and database hashes'
php -r '
$b=$argv[1]; $root=$argv[2]; $base=$argv[3];
if (!is_file("$b/backup.ok") || trim(file_get_contents("$b/backup.ok"))!=="ERP_BACKUP_VERIFIED") exit(1);
$m=json_decode(file_get_contents("$b/manifest.json"),true,512,JSON_THROW_ON_ERROR);
if (($m["application_root"]??null)!==$root || ($m["previous_commit"]??null)!==$base || ($m["tracked_local_edits"]??true)!==false) exit(1);
foreach (["application","database"] as $k) {
 $f="$b/".($k==="application"?"application.tar.gz":"database.sql.gz");
 if (!is_file($f)||is_link($f)||filesize($f)!==($m[$k."_bytes"]??null)||!hash_equals($m[$k."_sha256"]??"",hash_file("sha256",$f))) exit(1);
}
' "$backup" "$root" "$base" >>"$log" 2>&1

phase=release-scope-and-syntax
mapfile -t changed < <(git diff --no-renames --name-only "$base" "$target")
[[ ${#changed[@]} -gt 0 ]]
for path in "${changed[@]}"; do
    case "$path" in
      app/Http/Controllers/Erp/*|app/Http/Middleware/ErpAccess.php|app/Models/Erp/*|app/Services/Erp/*|app/Providers/RouteServiceProvider.php|config/auth.php|config/erp.php|resources/views/erp/*|resources/views/admin/layouts/menu.blade.php|routes/erp.php|public/erp-assets/*|docs/ERP_*|tests/erp_runtime/*|.devcontainer/*|.github/workflows/erp-tests.yml|deployment/erp_backup.php|deployment/erp_install.sh) ;;
      database/migrations/2026_09_30_180000_create_erp_foundation.php|database/migrations/2026_09_30_193000_create_erp_operations.php) ;;
      *) echo "STOP: unreviewed release path: $path"; exit 1;;
    esac
    entry=$(git ls-tree "$target" -- "$path")
    [[ $entry == 100644\ blob* || $entry == 100755\ blob* ]] || { echo "STOP: unexpected file type or removal: $path"; exit 1; }
    if [[ $path == *.php ]]; then
        git show "$target:$path" > "$run/syntax.php"
        safe_command php -l "$run/syntax.php"
    fi
done

cat > "$run/probe.php" <<'PHP'
<?php
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Facade;
use Illuminate\Http\Request;
function check($yes, string $message): void { if (!$yes) { throw new RuntimeException($message); } }
function routeDigest($app): string {
    $routes=[];
    foreach ($app['router']->getRoutes() as $r) {
        if ($r->uri()==='erp' || str_starts_with($r->uri(),'erp/')) continue;
        $routes[]=[$r->methods(),$r->uri(),$r->getName(),$r->getActionName(),$r->getAction('middleware')];
    }
    sort($routes); return hash('sha256',json_encode($routes,JSON_THROW_ON_ERROR));
}
try {
    [$script,$root,$mode,$owner,$ownerName,$run] = $argv;
    chdir($root);
    $loader=require "$root/vendor/autoload.php";
    check(!$loader->isClassMapAuthoritative(),'Authoritative autoload needs a separately reviewed rebuild');
    $app=require "$root/bootstrap/app.php";
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    check(PHP_VERSION_ID>=80200 && PHP_INT_SIZE===8,'Runtime mismatch');
    check(DB::connection()->getDriverName()==='mysql','Unexpected database');
    check(config('auth.guards.admin.driver')==='session','Unexpected owner guard');
    $record=DB::table('users')->where('id',(int)$owner)->first(['id','name','account_type']);
    check($record && $record->name===$ownerName && in_array($record->account_type,['admin','super_admin'],true),'Owner mismatch');
    $url=rtrim((string)config('app.url'),'/');
    check(parse_url($url,PHP_URL_SCHEME)==='https' && !parse_url($url,PHP_URL_USER) && !parse_url($url,PHP_URL_QUERY),'HTTPS application URL required');
    $repository=$app->make('migrator')->getRepository();
    check($repository->repositoryExists(),'Migration history missing');
    $ran=$repository->getRan(); sort($ran);
    $migrations=['2026_09_30_180000_create_erp_foundation','2026_09_30_193000_create_erp_operations'];
    if ($mode==='before') {
        $required=['users'=>['id','name','account_type','password'],'resturants'=>['id','name'],'orders'=>['id','resturant_id','order_no','status','type','payment_type','created_at']];
        foreach($required as $t=>$cols) foreach($cols as $c) check(Schema::hasColumn($t,$c),'Legacy schema mismatch');
        $present=array_values(array_intersect($migrations,$ran));
        check(count($present)===0 || count($present)===count($migrations),'Partial ERP migration history requires reconciliation');
        if (count($present)===0) {
            foreach (['erp_users','erp_branches','erp_ledger_state'] as $t) check(!Schema::hasTable($t),'ERP tables exist without migration history');
        } else {
            foreach (['erp_users','erp_branches','erp_ledger_state','erp_accounts'] as $t) check(Schema::hasTable($t),'Recorded ERP migration is missing tables');
            check(DB::table('erp_ledger_state')->where('id',1)->exists() && DB::table('erp_ledger_state')->where('id',1)->value('initialized_at')===null,'Opening ledger must remain uninitialized');
            foreach (['erp_branches','erp_users','erp_warehouses','erp_items','erp_stock_balances','erp_stock_documents','erp_stock_entries','erp_employees','erp_salary_rates','erp_attendance','erp_payroll_adjustments','erp_payrolls','erp_audit','erp_suppliers','erp_purchases','erp_purchase_lines','erp_recipes','erp_recipe_lines','erp_productions','erp_journals','erp_journal_lines','erp_cash_documents'] as $t) {
                check(!DB::table($t)->exists(),'Existing ERP business data requires reconciliation');
            }
        }
        file_put_contents("$run/baseline.json",json_encode(['ran'=>$ran,'routes'=>routeDigest($app),'url'=>$url],JSON_THROW_ON_ERROR));
        file_put_contents("$run/url",$url);
        echo "OWNER_AND_LIVE_BASELINE_CONFIRMED\n"; exit;
    }
    $before=json_decode(file_get_contents("$run/baseline.json"),true,512,JSON_THROW_ON_ERROR);
    $expected=$before['ran'];
    foreach($migrations as $migration) if(!in_array($migration,$expected,true)) $expected[]=$migration;
    sort($expected);
    check($ran===$expected,'Unrelated migration history changed');
    check(routeDigest($app)===$before['routes'],'Legacy routes changed');
    check(config('app.debug')===false && config('session.secure')===true,'Security configuration not effective');
    check((int)config('erp.legacy_owner_id')===(int)$owner,'ERP owner configuration mismatch');
    check((bool)config('erp.enabled')===($mode==='enabled'),'Feature flag state mismatch');
    $tables=[];
    foreach($migrations as $m) { preg_match_all("/Schema::create\('([^']+)'/",file_get_contents("$root/database/migrations/$m.php"),$matches); $tables=array_merge($tables,$matches[1]); }
    foreach($tables as $t) {
        check(Schema::hasTable($t),'ERP table missing');
        $engine=DB::selectOne('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',[DB::connection()->getTablePrefix().$t]);
        check($engine && strtoupper($engine->engine)==='INNODB','ERP engine mismatch');
    }
    check(DB::table('erp_ledger_state')->where('id',1)->exists() && DB::table('erp_ledger_state')->where('id',1)->value('initialized_at')===null,'Opening ledger must remain uninitialized');
    foreach($tables as $t) if(!in_array($t,['erp_accounts','erp_ledger_state'],true)) check(!DB::table($t)->exists(),'Unexpected business or sample data');
    if($mode==='enabled') { check(App\Services\Erp\Access::ready(),'ERP not ready'); echo "ERP_CONFIGURATION_AND_SCHEMA_VERIFIED\n"; exit; }
    // Read-only routing/rendering with in-memory sessions, never a saved login.
    // Dispatch through the router to avoid the intentionally active global maintenance middleware.
    config(['erp.enabled'=>true,'session.driver'=>'array']);
    $app['session']->forgetDrivers();
    $user=Auth::guard('admin')->getProvider()->retrieveById((int)$owner);
    check($user && $user->name===$ownerName,'Owner provider mismatch');
    Auth::guard('admin')->setUser($user);
    check(App\Services\Erp\Access::actor()?->role==='owner','Owner authorization failed');
    foreach(['','branches','inventory','employees','payroll','orders','accounts','audit','purchases','production','finance'] as $page) {
        $r=Request::create($url.'/erp'.($page!==''?'/'.$page:''),'GET');
        $app->instance('request',$r); Facade::clearResolvedInstance('request'); $app['url']->setRequest($r);
        $response=$app['router']->dispatch($r);
        check($response->getStatusCode()===200,'ERP read-only page failed: '.$page);
    }
    Auth::guard('admin')->logout(); Auth::guard('erp')->logout();
    foreach(['/erp/login'=>200,'/erp'=>302] as $path=>$status) {
        $r=Request::create($url.$path,'GET'); $app->instance('request',$r); Facade::clearResolvedInstance('request'); $app['url']->setRequest($r);
        check($app['router']->dispatch($r)->getStatusCode()===$status,'Guest access check failed');
    }
    echo "ERP_OWNER_RENDER_AND_GUEST_CHECKS_PASSED\n";
} catch(Throwable $e) { fwrite(STDERR,'Probe failed: '.$e->getMessage()."\n"); exit(1); }
PHP

phase=live-baseline
safe_command php "$run/probe.php" "$root" before "$owner" "$owner_name" "$run"
url=$(cat "$run/url")
http_check() {
    local path=$1 expected=$2 label=$3 status
    status=$(curl --compressed --silent --show-error --connect-timeout 10 --max-time 30 --proto '=https' -o "$run/$label.body" -D "$run/$label.headers" -w '%{http_code}' "$url$path" 2>>"$log")
    printf '%s HTTP %s\n' "$label" "$status" >>"$log"
    [[ $status == "$expected" ]]
}
http_check /admin/login 200 before-admin
http_check /api/go-services/capabilities 200 before-go

echo 'STEP: checked owner, backup, release scope and syntax; entering maintenance'
phase=maintenance
[[ ! -e storage/framework/down ]] || { echo 'STOP: another operator enabled maintenance.'; exit 1; }
maintenance=1
safe_command php artisan down --retry=60
phase=security-settings
env_changed=1
set_env false
safe_command php artisan config:clear
phase=code-switch
safe_command git -c core.hooksPath=/dev/null checkout --detach --no-overwrite-ignore "$target"
switched=1
clear_caches
phase=erp-migrations
echo 'STEP: applying only the two ERP migrations'
safe_command php artisan migrate --force --path=database/migrations/2026_09_30_180000_create_erp_foundation.php
safe_command php artisan migrate --force --path=database/migrations/2026_09_30_193000_create_erp_operations.php
phase=owner-render-checks
safe_command php "$run/probe.php" "$root" installed "$owner" "$owner_name" "$run"
phase=activation
set_env true
safe_command php artisan config:clear
safe_command php "$run/probe.php" "$root" enabled "$owner" "$owner_name" "$run"
safe_command php artisan up
maintenance=0
phase=public-http-checks
echo 'STEP: checking public login, ERP redirect, assets and GO capabilities'
http_check /admin/login 200 after-admin
http_check /erp/login 200 erp-login
http_check /erp 302 erp-root
http_check /erp-assets/workspace.css 200 erp-css
http_check /erp-assets/workspace.js 200 erp-js
http_check /api/go-services/capabilities 200 after-go
grep -q 'erp-assets/workspace.css' "$run/erp-login.body"
cmp -s public/erp-assets/workspace.css "$run/erp-css.body"
cmp -s public/erp-assets/workspace.js "$run/erp-js.body"
php -r '
$a=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
$b=json_decode(file_get_contents($argv[2]),true,512,JSON_THROW_ON_ERROR);
foreach(["schema_ready","enabled","payment_methods"] as $k) if(($a["data"][$k]??null)!==($b["data"][$k]??null)) exit(1);
if(($b["status"]??null)!=="Success") exit(1);
' "$run/before-go.body" "$run/after-go.body" >>"$log" 2>&1
[[ $(git rev-parse HEAD) == "$target" && -z $(git --no-optional-locks status --porcelain --untracked-files=no) ]]
umask 077
printf 'base=%s\ntarget=%s\nbackup=%s\nowner_id=%s\ncompleted_utc=%s\n' "$base" "$target" "$backup" "$owner" "$(date -u +%FT%TZ)" > "$run/release.ok"
success=1
trap - EXIT INT TERM HUP
echo 'ERP_DEPLOYED_HTTP_CHECKS_PASSED'
echo 'ONLY_ERP_MIGRATIONS_APPLIED; OPENING_LEDGER_NOT_INITIALIZED; NO_DEPUTY_ACCOUNT_CREATED'
printf 'OWNER_ID=%s\nOWNER_LOGIN=%s/admin/login\nERP_WORKSPACE=%s/erp\nRELEASE_RECEIPT=%s/release.ok\n' "$owner" "$url" "$url" "$run"
echo 'OWNER_BROWSER_LOGIN_AND_REAL_OPERATIONS_STILL_REQUIRE_USER_VERIFICATION'
