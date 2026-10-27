<?php
namespace App\Services\Dashboard;

use App\Models\User;
use Illuminate\Support\Facades\{DB,Schema};

/** Native-installed authority uses a LOCAL MAC; the server signing key never enters the PC. */
final class DesktopDashboardWorkspaceLocalAuthority implements DesktopDashboardWorkspaceIssuer
{
    private object $issuanceCapability;
    public function __construct() { $this->issuanceCapability=new \stdClass; }
    public function selected(): bool
    {
        return config('desktop_dashboard.local')===true && getenv('DESKTOP_DASHBOARD_WORKSPACE_LOCAL')==='true';
    }
    public function active(): bool
    {
        if (!$this->selected()) return false;
        $this->record(); return true;
    }

    /** The middleware passes the real password-authenticated session, never a body actor ID. */
    public function currentScope($authenticatedActor): DesktopDashboardWorkspaceScope
    {
        $id=$authenticatedActor instanceof User ? (int)$authenticatedActor->getKey() : $authenticatedActor;
        abort_unless(is_int($id) && $id>0 && (int)auth('admin')->id()===$id,403);
        return $this->scopeForActor($id);
    }

    /** Native control authentication proves the physical root only; it never impersonates its custodian. */
    public function authenticateLocal(string $token): object
    {
        abort_unless($this->hash($token),401); $record=$this->record();
        abort_unless(hash_equals($record['root']['token_hash'],hash('sha256',$token)),401);
        return $this->transaction(fn()=>$this->rootState($record['workspace_id'],true));
    }

    /** Recaller lookup runs before guard authentication; validate its real persisted member directly. */
    public function rememberBinding(User $user): array
    {
        return $this->transaction(function() use ($user) {
            $record=$this->record(); $id=(int)$user->getKey(); $member=$this->member($record,$id);
            $this->assertMemberFacts($member); $this->assertState($this->stateRow($record['workspace_id'],true),$record);
            $password=$user->getAuthPassword();
            abort_unless(is_string($password) && hash_equals($member['password_binding'],hash('sha256',$password)),403);
            return ['format'=>2,'device'=>$record['workspace_id'],'actor'=>$id,
                'password_binding'=>$this->mac('remember',['root_fingerprint'=>$record['root_fingerprint'],
                    'actor_id'=>$id,'password_verifier'=>$password])];
        });
    }

    /** The typed provenance is issued only after private native expectations and actual import checks succeed. */
    public function install(DesktopDashboardWorkspaceImportProvenance $verified): array
    {
        DesktopDashboardWorkspaceLocalSchema::assertNativeCli(); $verified->assertVerified(); $context=$verified->context();
        foreach (['workspace_enrollment','manifest','snapshot','database'] as $name) abort_unless(array_key_exists($name,$context),422);
        $manifest=$context['manifest']; $enrollment=$context['workspace_enrollment']; $snapshot=$context['snapshot'];
        abort_unless(is_array($manifest)&&is_array($enrollment)&&is_array($snapshot)
            && is_string($context['database']) && $context['database']===DB::connection()->getDatabaseName(),422);
        $codec=new DesktopDashboardWorkspaceDeltaCodec($manifest); unset($codec);
        $root=$this->rootFromEnrollment($enrollment); $fingerprint=(new DesktopDashboardWorkspaceAuthority)->rootFingerprint($root);
        abort_unless($manifest['workspace_id']===$root->id && $manifest['root_fingerprint']===$fingerprint
            && $snapshot['workspace_id']===$root->id && $snapshot['custodian_actor_id']===(int)$root->custodian_actor_id
            && $root->id===(string)config('desktop_dashboard.device_id') && $this->uuid($snapshot['snapshot_id']??null)
            && $this->hash($snapshot['schema_hash']??null) && $this->source($snapshot['source']??null),422);
        $record=['format'=>2,'kind'=>'desktop-dashboard-workspace-local-authority','workspace_id'=>$root->id,
            'root_fingerprint'=>$fingerprint,'root'=>(array)$root,'manifest_revision'=>$manifest['revision'],
            'roster_sha256'=>$manifest['roster_sha256'],'manifest_sha256'=>hash('sha256',self::json($manifest)),
            'manifest_signature'=>$manifest['signature'],'member_count'=>count($manifest['members']),
            'snapshot'=>$snapshot,'database'=>$context['database'],
            'expected_binding_digest'=>$context['verified_expected_binding']??$context['expected_binding_digest']??null];
        abort_unless($record['expected_binding_digest']===null || $this->hash($record['expected_binding_digest']),422);
        DesktopDashboardWorkspaceLocalSchema::ensure();
        return $this->transaction(function() use ($root,$record,$manifest) {
            $state=$this->stateRow($root->id,true); $this->assertState($state,$record);
            $table=DB::table('desktop_dashboard_workspace_local_authority');
            $old=$table->where('workspace_id',$root->id)->lockForUpdate()->first();
            abort_unless(DB::table('desktop_dashboard_workspace_local_authority')->where('workspace_id','!=',$root->id)->doesntExist(),409);
            $now=now('UTC');
            if ($old) {
                $previous=$this->decode($old->record_json);
                abort_unless(hash_equals($old->local_mac,$this->mac('record',$previous))
                    && $previous['root_fingerprint']===$record['root_fingerprint']
                    && $previous['manifest_revision']<=$record['manifest_revision'],409,'هوية صلاحيات النسخة المحلية تغيرت.');
                if ($previous['manifest_revision']===$record['manifest_revision'])
                    abort_unless($previous['manifest_sha256']===$record['manifest_sha256'],409,'لا يمكن استبدال سجل عضوية بنفس النسخة.');
                DB::table('desktop_dashboard_workspace_local_authority_history')->insertOrIgnore([
                    'workspace_id'=>$root->id,'record_sha256'=>hash('sha256',$old->record_json),'record_json'=>$old->record_json,
                    'manifest_json'=>$old->manifest_json,'local_mac'=>$old->local_mac,'created_at'=>$now,'updated_at'=>$now]);
            }
            $ids=[];
            foreach ($manifest['members'] as $member) {
                $this->assertMemberFacts($member); $ids[]=$member['actor_id'];
                DB::table('desktop_dashboard_workspace_local_members')->updateOrInsert(
                    ['workspace_id'=>$root->id,'actor_id'=>$member['actor_id']],
                    ['manifest_revision'=>$manifest['revision'],'member_json'=>self::json($member),
                        'local_mac'=>$this->memberMac($record,$member),'created_at'=>$now,'updated_at'=>$now]);
            }
            // Inactive old grants remain only in the authenticated authority archive and command sidecars.
            $retired=DB::table('desktop_dashboard_workspace_local_members')->where('workspace_id',$root->id);
            if ($ids) $retired->whereNotIn('actor_id',$ids); $retired->delete();
            $attributes=['record_json'=>self::json($record),'manifest_json'=>self::json($manifest),
                'local_mac'=>$this->mac('record',$record),'updated_at'=>$now];
            if ($old) DB::table('desktop_dashboard_workspace_local_authority')->where('workspace_id',$root->id)->update($attributes);
            else DB::table('desktop_dashboard_workspace_local_authority')->insert(['workspace_id'=>$root->id]+$attributes+['created_at'=>$now]);
            return $this->receipt($record);
        });
    }

    /** Private CLI verification reads every cached member only during import/activation acceptance. */
    public function verifyImport(array $receipt, array $expectedBinding): array
    {
        DesktopDashboardWorkspaceLocalSchema::assertNativeCli(); $record=$this->record();
        $this->assertExpected($record,$expectedBinding);
        abort_unless(self::json($receipt)===self::json($this->receipt($record)),422);
        $row=DB::table('desktop_dashboard_workspace_local_authority')->where('workspace_id',$record['workspace_id'])->first(['manifest_json']);
        $manifest=$this->decode($row->manifest_json);
        $this->assertManifest($record,$manifest);
        return $this->transaction(function() use ($record,$manifest) {
            $this->assertState($this->stateRow($record['workspace_id'],true),$record);
            abort_unless(DB::table('desktop_dashboard_workspace_local_members')->where('workspace_id',$record['workspace_id'])->count()===$record['member_count'],503);
            foreach ($manifest['members'] as $member) {
                abort_unless(self::json($member)===self::json($this->member($record,$member['actor_id'])) ,503);
                $this->assertMemberFacts($member);
            }
            return $manifest;
        });
    }

    /** Fixed-size cutover check. Full members, media and journal were verified before the fence. */
    public function handoffBindings(array $receipt, array $expectedBinding): array
    {
        DesktopDashboardWorkspaceLocalSchema::assertNativeCli(); $record=$this->record();
        $this->assertExpected($record,$expectedBinding);
        abort_unless(self::json($receipt)===self::json($this->receipt($record)),422);
        $state=$this->rootState($record['workspace_id'],false);
        abort_unless($state->state==='ready',409);
        return $this->bindings($record);
    }

    /** Authenticate PDO-fetched source metadata while Laravel remains bound to the empty target. */
    public function verifyCaptureBindings(array $recordRow, array $stateRow, string $database, array $expectedBinding): array
    {
        DesktopDashboardWorkspaceLocalSchema::assertNativeCli(); $this->sourceDatabase($database);
        $record=$this->checkedRecord($recordRow,$database);
        $this->assertExpected($record,$expectedBinding); $this->assertState((object)$stateRow,$record);
        abort_unless(in_array($stateRow['state']??null,['ready','fenced'],true),409);
        return $this->bindings($record);
    }

    /** Full source acceptance uses local MACs; the importing codec separately verifies actual user rows. */
    public function verifyCapture(array $recordRow, array $memberRows, array $stateRow, string $database, array $expectedBinding): array
    {
        $this->verifyCaptureBindings($recordRow,$stateRow,$database,$expectedBinding);
        abort_unless(($stateRow['state']??null)==='ready',409);
        $record=$this->checkedRecord($recordRow,$database);
        abort_unless(isset($recordRow['manifest_json']) && is_string($recordRow['manifest_json']),503);
        $manifest=$this->decode($recordRow['manifest_json']);
        $this->assertManifest($record,$manifest);
        abort_unless(count($memberRows)===$record['member_count'],503);
        $indexed=[];
        foreach ($memberRows as $row) {
            abort_unless(is_array($row) && isset($row['actor_id'],$row['manifest_revision'],$row['member_json'],$row['local_mac'])
                && is_string($row['member_json']) && is_string($row['local_mac']),503);
            $member=$this->decode($row['member_json']); $id=$member['actor_id']??null;
            abort_unless(is_int($id) && (string)$id===(string)$row['actor_id'] && !isset($indexed[$id])
                && (string)$record['manifest_revision']===(string)$row['manifest_revision']
                && hash_equals($row['local_mac'],$this->memberMac($record,$member)),503);
            $indexed[$id]=$member;
        }
        foreach ($manifest['members'] as $member)
            abort_unless(isset($indexed[$member['actor_id']]) && self::json($indexed[$member['actor_id']])===self::json($member),503);
        return $manifest;
    }

    public function rootState(string $device, bool $lock=true): object
    {
        $record=$this->record(); abort_unless($record['workspace_id']===$device,403);
        $state=$this->stateRow($device,$lock); $this->assertState($state,$record); return $state;
    }
    public function scopeForState(object $state): ?DesktopDashboardWorkspaceScope
    {
        $context=DesktopDashboardWorkspaceExecutionContext::current();
        $authenticated=auth('admin')->id();
        $actor=$context ? $context->actor_id : ($authenticated===null?null:(int)$authenticated);
        if ($actor===null) { abort_unless(PHP_SAPI==='cli',403); return null; }
        if ($authenticated!==null) abort_unless((int)$authenticated===$actor,403);
        return $this->scopeForActor($actor,$state);
    }
    public function scopeForActor(int $actorId, ?object $state=null): DesktopDashboardWorkspaceScope
    {
        return $this->transaction(function() use ($actorId,$state) {
            $record=$this->record(); $context=DesktopDashboardWorkspaceExecutionContext::current();
            if ($context) abort_unless($context->id===$record['workspace_id'] && $context->actor_id===$actorId,403);
            $authenticated=auth('admin')->id(); if ($authenticated!==null) abort_unless((int)$authenticated===$actorId,403);
            abort_unless($authenticated!==null || $context!==null || PHP_SAPI==='cli',403);
            $actual=$this->rootState($record['workspace_id'],true);
            if ($state!==null) abort_unless($state->device_id===$actual->device_id && $state->snapshot_id===$actual->snapshot_id
                && $state->schema_hash===$actual->schema_hash,403);
            $member=$this->member($record,$actorId); $this->assertMemberFacts($member);
            $actor=User::withoutGlobalScopes()->whereKey($actorId)->lockForUpdate()->first(); abort_unless($actor,403);
            return DesktopDashboardWorkspaceScope::issue($this,$this->issuanceCapability,(object)$record['root'],$actor,$member,
                $record['root_fingerprint'],$record['manifest_revision'],null,null,$actual);
        });
    }
    public function assertScope(DesktopDashboardWorkspaceScope $scope): void
    {
        $scope->assertIssued(); abort_unless($scope->isLocal(),403); $record=$this->record();
        abort_unless($scope->id===$record['workspace_id'] && $scope->rootFingerprint()===$record['root_fingerprint']
            && $scope->manifestRevision()===$record['manifest_revision'],409);
        $this->transaction(function() use ($scope,$record) {
            $member=$this->member($record,$scope->actor_id);
            abort_unless(self::json($member)===self::json($scope->member()),403); $this->assertMemberFacts($member);
            $state=$this->rootState($record['workspace_id'],true); $captured=$scope->localState();
            abort_unless($captured && $captured->snapshot_id===$state->snapshot_id && $captured->schema_hash===$state->schema_hash,409);
        });
    }
    public function revalidateScope(DesktopDashboardWorkspaceScope $scope): DesktopDashboardWorkspaceScope
    {
        $this->assertScope($scope); return $this->scopeForActor($scope->actor_id);
    }
    public function assertIssuanceCapability(object $capability): void
    {
        if ($capability!==$this->issuanceCapability) throw new \LogicException('Local workspace authority is not a request field.');
    }

    private function record(): array
    {
        abort_unless($this->selected() && config('desktop_dashboard.workspace_enabled')===true
            && Schema::hasTable('desktop_dashboard_workspace_local_authority')
            && Schema::hasTable('desktop_dashboard_workspace_local_members'),503,'صلاحيات مساحة العمل المحلية لم تُثبّت بعد.');
        $id=(string)config('desktop_dashboard.device_id'); abort_unless($this->uuid($id),403);
        $row=DB::table('desktop_dashboard_workspace_local_authority')->where('workspace_id',$id)->first(['record_json','local_mac']);
        abort_unless($row,503); return $this->checkedRecord((array)$row,DB::connection()->getDatabaseName());
    }

    private function checkedRecord(array $row, string $database): array
    {
        abort_unless(isset($row['record_json'],$row['local_mac']) && is_string($row['record_json']) && $this->hash($row['local_mac']),503);
        $record=$this->decode($row['record_json']);
        abort_unless(hash_equals($row['local_mac'],$this->mac('record',$record)) && ($record['format']??null)===2
            && ($record['kind']??null)==='desktop-dashboard-workspace-local-authority'
            && ($record['workspace_id']??null)===(string)config('desktop_dashboard.device_id')
            && ($record['database']??null)===$database && is_array($record['root']??null)
            && is_array($record['snapshot']??null) && is_int($record['manifest_revision']??null)
            && $record['manifest_revision']>0 && is_int($record['member_count']??null)
            && $record['member_count']>=0 && $record['member_count']<=DesktopDashboardWorkspaceAuthority::MAX_MEMBERS
            && $this->hash($record['roster_sha256']??null) && $this->hash($record['manifest_sha256']??null)
            && $this->hash($record['manifest_signature']??null)
            && ($record['root_fingerprint']??null)===(new DesktopDashboardWorkspaceAuthority)->rootFingerprint((object)$record['root']),503,
            'دليل صلاحيات مساحة العمل المحلية غير صالح.');
        return $record;
    }

    private function assertExpected(array $record, array $expected): void
    {
        $keys=array_keys($expected);sort($keys);$fields=['workspace_id','root_fingerprint','revision','roster_sha256','manifest_sha256','token_hash'];sort($fields);
        abort_unless($keys===$fields && is_int($expected['revision']) && $expected['revision']>0,422);
        foreach (['workspace_id','root_fingerprint','roster_sha256','manifest_sha256'] as $key)
            abort_unless($record[$key]===$expected[$key],422);
        abort_unless($record['manifest_revision']===$expected['revision'] && $record['root']['token_hash']===$expected['token_hash'],422);
    }

    private function assertManifest(array $record, array $manifest): void
    {
        abort_unless(hash_equals($record['manifest_sha256'],hash('sha256',self::json($manifest))),503);
        $codec=new DesktopDashboardWorkspaceDeltaCodec($manifest); unset($codec);
        abort_unless($manifest['workspace_id']===$record['workspace_id'] && $manifest['revision']===$record['manifest_revision']
            && $manifest['root_fingerprint']===$record['root_fingerprint'] && $manifest['roster_sha256']===$record['roster_sha256']
            && $manifest['signature']===$record['manifest_signature'] && count($manifest['members'])===$record['member_count'],503);
    }

    private function bindings(array $record): array
    {
        return ['snapshot_id'=>$record['snapshot']['snapshot_id'],'schema_hash'=>$record['snapshot']['schema_hash'],
            'workspace'=>$this->receipt($record)];
    }

    private function sourceDatabase(string $database): void
    {
        abort_unless((bool)preg_match('/^fasakhansta_dashboard(?:_stage_[a-f0-9]{16})?$/D',$database)
            || (app()->environment('testing') && (($database==='takeaway_test' && DB::connection()->getDriverName()==='mysql')
                || (DB::connection()->getDriverName()==='sqlite' && $database!=='' && strlen($database)<=512))),403);
    }
    private function member(array $record, int $actor): array
    {
        abort_unless($actor>0 && $actor<=9007199254740991,403);
        $row=DB::table('desktop_dashboard_workspace_local_members')->where('workspace_id',$record['workspace_id'])->where('actor_id',$actor)->first();
        abort_unless($row && (int)$row->manifest_revision===$record['manifest_revision'],403,'الحساب غير مجهّز داخل مساحة العمل.');
        $member=$this->decode($row->member_json);
        abort_unless(($member['actor_id']??null)===$actor && ($member['enabled']??null)===true
            && hash_equals($row->local_mac,$this->memberMac($record,$member)),403,'دليل صلاحيات الحساب المحلي غير صالح.'); return $member;
    }
    private function assertMemberFacts(array $member): void
    {
        $facts=(new DesktopDashboardWorkspaceAuthority)->persistedMemberFacts($member['actor_id']);
        $captured=$member; unset($captured['grant']);
        // Pending role/password edits are deliberately closed until their native overlay protocol is accepted.
        abort_unless(self::json($facts)===self::json($captured),403,'بيانات دخول أو صلاحيات الحساب تغيّرت وتحتاج مزامنة مؤكدة.');
    }
    private function stateRow(string $id, bool $lock): object
    {
        abort_unless(Schema::hasTable('desktop_dashboard_local_state'),503);
        if ($lock) abort_unless(DB::transactionLevel()>0,503);
        $query=DB::table('desktop_dashboard_local_state')->where('device_id',$id); if ($lock) $query->lockForUpdate();
        $state=$query->first(); abort_unless($state,503); return $state;
    }
    private function assertState(object $state, array $record): void
    {
        abort_unless($state->device_id===$record['workspace_id'] && (int)$state->actor_id===$record['root']['custodian_actor_id']
            && $state->snapshot_id===$record['snapshot']['snapshot_id'] && $state->schema_hash===$record['snapshot']['schema_hash'],503,
            'حالة قاعدة مساحة العمل لا تطابق دليل التجهيز.');
    }
    private function rootFromEnrollment(array $value): object
    {
        abort_unless(($value['format']??null)===2 && $this->uuid($value['workspace_id']??null)
            && is_int($value['custodian_actor_id']??null) && $value['custodian_actor_id']>0
            && $value['custodian_actor_id']<=9007199254740991 && $this->hash($value['enrollment_hash']??null)
            && $this->hash($value['token_hash']??null) && is_string($value['enrolled_at']??null),422);
        return (object)['id'=>$value['workspace_id'],'custodian_actor_id'=>$value['custodian_actor_id'],'name'=>'Shared workspace',
            'enabled'=>true,'enrollment_hash'=>$value['enrollment_hash'],'token_hash'=>$value['token_hash'],'enrolled_at'=>$value['enrolled_at']];
    }
    private function receipt(array $record): array
    {
        return array_intersect_key($record,array_flip(['workspace_id','root_fingerprint','manifest_revision','roster_sha256','manifest_sha256']));
    }
    private function memberMac(array $record, array $member): string
    {
        return $this->mac('member',['record_sha256'=>hash('sha256',self::json($record)),'member'=>$member]);
    }
    private function mac(string $domain, array $value): string
    {
        return hash_hmac('sha256',"desktop-workspace-local-v2\0".$domain."\0".self::json($value),app('encrypter')->getKey());
    }
    private function decode(string $json): array
    {
        abort_unless(strlen($json)<=DesktopDashboardWorkspaceAuthority::MAX_MANIFEST_BYTES,503);
        try { $value=json_decode($json,true,64,JSON_THROW_ON_ERROR); } catch (\JsonException $e) { abort(503); }
        abort_unless(is_array($value) && self::json($value)===$json,503); return $value;
    }
    private function source($value): bool
    {
        return is_array($value) && ($value['format']??null)===1 && is_int($value['files']??null)
            && $value['files']>0 && $value['files']<=20000 && $this->hash($value['sha256']??null)
            && is_string($value['framework']??null) && $value['framework']!=='' && strlen($value['framework'])<=100;
    }
    private function hash($value): bool { return is_string($value) && (bool)preg_match('/^[a-f0-9]{64}$/D',$value); }
    private function uuid($value): bool { return is_string($value) && (bool)preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$value); }
    private static function json($value): string { return DesktopDashboardWorkspaceAuthority::canonical($value); }
    private function transaction(callable $work) { return DB::transactionLevel()>0 ? $work() : DB::transaction($work,3); }
}
