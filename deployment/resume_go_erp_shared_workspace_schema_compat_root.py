#!/usr/bin/env python3
"""Explicitly approved SQL adapter compatibility, retaining the original rollout.

Original root pins/checkpoints, source, migration bytes, four schemas and native
release inputs remain unchanged. Only the private deployment PHP adapter changes.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import socket
import subprocess
import sys
import types

APPROVAL = {'kind': 'go-erp-reviewed-full-helper-schema-compatibility', 'accepted': True, 'core': '052e69008da7e330815b376824cd8afc18cf04c1', 'base_operator': '29b245a5634e9fda747138cb0c0dbb103f1bc76a', 'base_pins_sha256': 'ed1fd7e640ac473aecc6c8a9171754f36bad048234ad28eddedb7c33130a7237', 'compat_template_sha256': 'd30b62979923aeac105fc3ff29988ab42675687676f9ffe336bed08e09a593be', 'workflow': {'path': '.github/workflows/go-erp-schema-compat-tests.yml', 'event': 'push', 'status': 'completed', 'conclusion': 'success', 'head_sha': '3e997647e9e767a774e7c62e68f562e1f1a36416', 'id': 40590160646, 'attempt': 1}, 'workflow_sha256': '4a83c0ed9f5c291629f4369bf73333b6f60258c4075a519151f1829cc2ae49c9', 'profiles': [{'actual_pdo': True, 'all_schemas_match': True, 'calls': [{'mode': 'create-table', 'table': 'desktop_dashboard_workspaces'}, {'mode': 'create-table', 'table': 'desktop_dashboard_workspace_members'}, {'mode': 'create-table', 'table': 'desktop_dashboard_workspace_manifests'}, {'migration': 'database/migrations/2026_10_27_030000_create_desktop_dashboard_workspaces.php', 'mode': 'record-ledger'}, {'mode': 'create-table', 'table': 'desktop_dashboard_ws_command_auth'}, {'migration': 'database/migrations/2026_10_27_030010_create_workspace_command_authorities.php', 'mode': 'record-ledger'}], 'compat_template_sha256': 'd30b62979923aeac105fc3ff29988ab42675687676f9ffe336bed08e09a593be', 'created_tables': 4, 'engine': 'mysql', 'exception_restore': {'class': 'RuntimeException', 'fixture_expected': True, 'illegal_offset': False, 'manager_restored': True, 'schema_restored': True}, 'final_schemas_exact': True, 'framework': '12.70.0', 'full_helper_body': True, 'initial_timestamp': 0, 'original_laravel8_defect': None, 'original_swap_failure_reproduced': False, 'private_tables_absent': True, 'recorded_migrations': 2, 'sentinel_unchanged': True, 'server_version': '8.0.46', 'source_migrations_unchanged': True, 'receipt_sha256': '657ff1f26b49b65b5dabf994970b112b514fe5f3d48583e6e90ff0b674275e2d', 'artifact_sha256': 'a22d696b6936090b663dfc85c5dc9ced65fc53144a4b10d701f4aa503e94d04e', 'artifact_id': 12774345423}, {'actual_pdo': True, 'all_schemas_match': True, 'calls': [{'mode': 'create-table', 'table': 'desktop_dashboard_workspaces'}, {'mode': 'create-table', 'table': 'desktop_dashboard_workspace_members'}, {'mode': 'create-table', 'table': 'desktop_dashboard_workspace_manifests'}, {'migration': 'database/migrations/2026_10_27_030000_create_desktop_dashboard_workspaces.php', 'mode': 'record-ledger'}, {'mode': 'create-table', 'table': 'desktop_dashboard_ws_command_auth'}, {'migration': 'database/migrations/2026_10_27_030010_create_workspace_command_authorities.php', 'mode': 'record-ledger'}], 'compat_template_sha256': 'd30b62979923aeac105fc3ff29988ab42675687676f9ffe336bed08e09a593be', 'created_tables': 4, 'engine': 'mysql', 'exception_restore': {'class': 'RuntimeException', 'fixture_expected': True, 'illegal_offset': False, 'manager_restored': True, 'schema_restored': True}, 'final_schemas_exact': True, 'framework': '12.70.0', 'full_helper_body': True, 'initial_timestamp': 1, 'original_laravel8_defect': None, 'original_swap_failure_reproduced': False, 'private_tables_absent': True, 'recorded_migrations': 2, 'sentinel_unchanged': True, 'server_version': '8.0.46', 'source_migrations_unchanged': True, 'receipt_sha256': '657ff1f26b49b65b5dabf994970b112b514fe5f3d48583e6e90ff0b674275e2d', 'artifact_sha256': 'a22d696b6936090b663dfc85c5dc9ced65fc53144a4b10d701f4aa503e94d04e', 'artifact_id': 12774345423}, {'actual_pdo': True, 'all_schemas_match': True, 'calls': [{'mode': 'create-table', 'table': 'desktop_dashboard_workspaces'}, {'mode': 'create-table', 'table': 'desktop_dashboard_workspace_members'}, {'mode': 'create-table', 'table': 'desktop_dashboard_workspace_manifests'}, {'migration': 'database/migrations/2026_10_27_030000_create_desktop_dashboard_workspaces.php', 'mode': 'record-ledger'}, {'mode': 'create-table', 'table': 'desktop_dashboard_ws_command_auth'}, {'migration': 'database/migrations/2026_10_27_030010_create_workspace_command_authorities.php', 'mode': 'record-ledger'}], 'compat_template_sha256': 'd30b62979923aeac105fc3ff29988ab42675687676f9ffe336bed08e09a593be', 'created_tables': 4, 'engine': 'mariadb', 'exception_restore': {'class': 'RuntimeException', 'fixture_expected': True, 'illegal_offset': False, 'manager_restored': True, 'schema_restored': True}, 'final_schemas_exact': True, 'framework': '8.83.29', 'full_helper_body': True, 'initial_timestamp': 0, 'original_laravel8_defect': {'class': 'TypeError', 'fixture_expected': False, 'illegal_offset': True, 'manager_restored': True, 'schema_restored': True}, 'original_swap_failure_reproduced': True, 'private_tables_absent': True, 'recorded_migrations': 2, 'sentinel_unchanged': True, 'server_version': '11.4.13-MariaDB-ubu2404', 'source_migrations_unchanged': True, 'receipt_sha256': 'c5e41d2883b0634c1b0920372d92cd4ab33d8aa3f96e55b01b52e53f07f92a77', 'artifact_sha256': 'eb98133a2f28c4dc003cb23e92b59553c659fdf6222ec74bddb14487028e7e12', 'artifact_id': 12774226285}, {'actual_pdo': True, 'all_schemas_match': True, 'calls': [{'mode': 'create-table', 'table': 'desktop_dashboard_workspaces'}, {'mode': 'create-table', 'table': 'desktop_dashboard_workspace_members'}, {'mode': 'create-table', 'table': 'desktop_dashboard_workspace_manifests'}, {'migration': 'database/migrations/2026_10_27_030000_create_desktop_dashboard_workspaces.php', 'mode': 'record-ledger'}, {'mode': 'create-table', 'table': 'desktop_dashboard_ws_command_auth'}, {'migration': 'database/migrations/2026_10_27_030010_create_workspace_command_authorities.php', 'mode': 'record-ledger'}], 'compat_template_sha256': 'd30b62979923aeac105fc3ff29988ab42675687676f9ffe336bed08e09a593be', 'created_tables': 4, 'engine': 'mariadb', 'exception_restore': {'class': 'RuntimeException', 'fixture_expected': True, 'illegal_offset': False, 'manager_restored': True, 'schema_restored': True}, 'final_schemas_exact': True, 'framework': '8.83.29', 'full_helper_body': True, 'initial_timestamp': 1, 'original_laravel8_defect': {'class': 'TypeError', 'fixture_expected': False, 'illegal_offset': True, 'manager_restored': True, 'schema_restored': True}, 'original_swap_failure_reproduced': True, 'private_tables_absent': True, 'recorded_migrations': 2, 'sentinel_unchanged': True, 'server_version': '11.4.13-MariaDB-ubu2404', 'source_migrations_unchanged': True, 'receipt_sha256': 'c5e41d2883b0634c1b0920372d92cd4ab33d8aa3f96e55b01b52e53f07f92a77', 'artifact_sha256': 'eb98133a2f28c4dc003cb23e92b59553c659fdf6222ec74bddb14487028e7e12', 'artifact_id': 12774226285}, {'actual_pdo': True, 'all_schemas_match': True, 'calls': [{'mode': 'create-table', 'table': 'desktop_dashboard_workspaces'}, {'mode': 'create-table', 'table': 'desktop_dashboard_workspace_members'}, {'mode': 'create-table', 'table': 'desktop_dashboard_workspace_manifests'}, {'migration': 'database/migrations/2026_10_27_030000_create_desktop_dashboard_workspaces.php', 'mode': 'record-ledger'}, {'mode': 'create-table', 'table': 'desktop_dashboard_ws_command_auth'}, {'migration': 'database/migrations/2026_10_27_030010_create_workspace_command_authorities.php', 'mode': 'record-ledger'}], 'compat_template_sha256': 'd30b62979923aeac105fc3ff29988ab42675687676f9ffe336bed08e09a593be', 'created_tables': 4, 'engine': 'mariadb', 'exception_restore': {'class': 'RuntimeException', 'fixture_expected': True, 'illegal_offset': False, 'manager_restored': True, 'schema_restored': True}, 'final_schemas_exact': True, 'framework': '12.70.0', 'full_helper_body': True, 'initial_timestamp': 0, 'original_laravel8_defect': None, 'original_swap_failure_reproduced': False, 'private_tables_absent': True, 'recorded_migrations': 2, 'sentinel_unchanged': True, 'server_version': '11.4.13-MariaDB-ubu2404', 'source_migrations_unchanged': True, 'receipt_sha256': '029f57faffc11c27608b1e4334da03932d7bbdb2a3ece8365c322b1797fc7a93', 'artifact_sha256': 'dcd11f802816989e32019d375517e0650f5cc7ed05a07c03ea789e61569fd648', 'artifact_id': 12773961727}, {'actual_pdo': True, 'all_schemas_match': True, 'calls': [{'mode': 'create-table', 'table': 'desktop_dashboard_workspaces'}, {'mode': 'create-table', 'table': 'desktop_dashboard_workspace_members'}, {'mode': 'create-table', 'table': 'desktop_dashboard_workspace_manifests'}, {'migration': 'database/migrations/2026_10_27_030000_create_desktop_dashboard_workspaces.php', 'mode': 'record-ledger'}, {'mode': 'create-table', 'table': 'desktop_dashboard_ws_command_auth'}, {'migration': 'database/migrations/2026_10_27_030010_create_workspace_command_authorities.php', 'mode': 'record-ledger'}], 'compat_template_sha256': 'd30b62979923aeac105fc3ff29988ab42675687676f9ffe336bed08e09a593be', 'created_tables': 4, 'engine': 'mariadb', 'exception_restore': {'class': 'RuntimeException', 'fixture_expected': True, 'illegal_offset': False, 'manager_restored': True, 'schema_restored': True}, 'final_schemas_exact': True, 'framework': '12.70.0', 'full_helper_body': True, 'initial_timestamp': 1, 'original_laravel8_defect': None, 'original_swap_failure_reproduced': False, 'private_tables_absent': True, 'recorded_migrations': 2, 'sentinel_unchanged': True, 'server_version': '11.4.13-MariaDB-ubu2404', 'source_migrations_unchanged': True, 'receipt_sha256': '029f57faffc11c27608b1e4334da03932d7bbdb2a3ece8365c322b1797fc7a93', 'artifact_sha256': 'dcd11f802816989e32019d375517e0650f5cc7ed05a07c03ea789e61569fd648', 'artifact_id': 12773961727}, {'actual_pdo': True, 'all_schemas_match': True, 'calls': [{'mode': 'create-table', 'table': 'desktop_dashboard_workspaces'}, {'mode': 'create-table', 'table': 'desktop_dashboard_workspace_members'}, {'mode': 'create-table', 'table': 'desktop_dashboard_workspace_manifests'}, {'migration': 'database/migrations/2026_10_27_030000_create_desktop_dashboard_workspaces.php', 'mode': 'record-ledger'}, {'mode': 'create-table', 'table': 'desktop_dashboard_ws_command_auth'}, {'migration': 'database/migrations/2026_10_27_030010_create_workspace_command_authorities.php', 'mode': 'record-ledger'}], 'compat_template_sha256': 'd30b62979923aeac105fc3ff29988ab42675687676f9ffe336bed08e09a593be', 'created_tables': 4, 'engine': 'mysql', 'exception_restore': {'class': 'RuntimeException', 'fixture_expected': True, 'illegal_offset': False, 'manager_restored': True, 'schema_restored': True}, 'final_schemas_exact': True, 'framework': '8.83.29', 'full_helper_body': True, 'initial_timestamp': 0, 'original_laravel8_defect': {'class': 'TypeError', 'fixture_expected': False, 'illegal_offset': True, 'manager_restored': True, 'schema_restored': True}, 'original_swap_failure_reproduced': True, 'private_tables_absent': True, 'recorded_migrations': 2, 'sentinel_unchanged': True, 'server_version': '8.0.46', 'source_migrations_unchanged': True, 'receipt_sha256': '35e89d3fb29a07946d4154f0ca95eae4bd9063148aa46cc43e5c4ea213aadaad', 'artifact_sha256': '102c6d80e2324911c04f67b864e131c2ea2f88deee198ac4f43b070191bff8bb', 'artifact_id': 12771959816}, {'actual_pdo': True, 'all_schemas_match': True, 'calls': [{'mode': 'create-table', 'table': 'desktop_dashboard_workspaces'}, {'mode': 'create-table', 'table': 'desktop_dashboard_workspace_members'}, {'mode': 'create-table', 'table': 'desktop_dashboard_workspace_manifests'}, {'migration': 'database/migrations/2026_10_27_030000_create_desktop_dashboard_workspaces.php', 'mode': 'record-ledger'}, {'mode': 'create-table', 'table': 'desktop_dashboard_ws_command_auth'}, {'migration': 'database/migrations/2026_10_27_030010_create_workspace_command_authorities.php', 'mode': 'record-ledger'}], 'compat_template_sha256': 'd30b62979923aeac105fc3ff29988ab42675687676f9ffe336bed08e09a593be', 'created_tables': 4, 'engine': 'mysql', 'exception_restore': {'class': 'RuntimeException', 'fixture_expected': True, 'illegal_offset': False, 'manager_restored': True, 'schema_restored': True}, 'final_schemas_exact': True, 'framework': '8.83.29', 'full_helper_body': True, 'initial_timestamp': 1, 'original_laravel8_defect': {'class': 'TypeError', 'fixture_expected': False, 'illegal_offset': True, 'manager_restored': True, 'schema_restored': True}, 'original_swap_failure_reproduced': True, 'private_tables_absent': True, 'recorded_migrations': 2, 'sentinel_unchanged': True, 'server_version': '8.0.46', 'source_migrations_unchanged': True, 'receipt_sha256': '35e89d3fb29a07946d4154f0ca95eae4bd9063148aa46cc43e5c4ea213aadaad', 'artifact_sha256': '102c6d80e2324911c04f67b864e131c2ea2f88deee198ac4f43b070191bff8bb', 'artifact_id': 12771959816}]}
CORE='052e69008da7e330815b376824cd8afc18cf04c1'
BASE_OPERATOR='29b245a5634e9fda747138cb0c0dbb103f1bc76a'
BASE_ROOT_SHA256='90d9dff5ff0fcadb4854f5f0dc2103dcfe8b9b25ef9922164fba1b6e690a03e9'
BASE_PINS_SHA256='ed1fd7e640ac473aecc6c8a9171754f36bad048234ad28eddedb7c33130a7237'
COMPAT_TEMPLATE='deployment/go_erp_shared_workspace_migration_compat.php'
COMPAT_SHA256='d30b62979923aeac105fc3ff29988ab42675687676f9ffe336bed08e09a593be'
WORKFLOW='.github/workflows/go-erp-schema-compat-tests.yml'
SHA=re.compile(r'[a-f0-9]{64}\Z');REV=re.compile(r'[a-f0-9]{40}\Z')

class Stop(Exception):pass
def need(value,code):
    if not value:raise Stop('GO_ERP_SCHEMA_COMPAT_'+code)
def sha(raw):return hashlib.sha256(raw).hexdigest()
def canonical(value):return json.dumps(value,sort_keys=True,separators=(',',':'),allow_nan=False).encode()+b'\n'

def approved(value):
    need(isinstance(value,dict) and value.get('kind')=='go-erp-reviewed-full-helper-schema-compatibility'
        and value.get('accepted') is True and value.get('core')==CORE and value.get('base_operator')==BASE_OPERATOR
        and value.get('base_pins_sha256')==BASE_PINS_SHA256 and value.get('compat_template_sha256')==COMPAT_SHA256,
        'FULL_HELPER_ACCEPTANCE_REQUIRED')
    workflow=value.get('workflow')
    need(isinstance(workflow,dict) and workflow.get('path')==WORKFLOW and workflow.get('event')=='push'
        and workflow.get('status')=='completed' and workflow.get('conclusion')=='success'
        and isinstance(workflow.get('head_sha'),str) and REV.fullmatch(workflow['head_sha'])
        and type(workflow.get('id')) is int and workflow['id']>0 and type(workflow.get('attempt')) is int
        and workflow['attempt']>0 and isinstance(value.get('workflow_sha256'),str)
        and SHA.fullmatch(value['workflow_sha256']),'WORKFLOW_ACCEPTANCE_REQUIRED')
    profiles=value.get('profiles')
    expected={(framework,engine,setting) for framework in ('8.83.29','12.70.0')
        for engine in ('mysql','mariadb') for setting in (0,1)}
    need(isinstance(profiles,list) and len(profiles)==8,'EIGHT_REAL_PROFILES_REQUIRED')
    seen=set()
    for row in profiles:
        need(isinstance(row,dict) and set(row)>={'framework','engine','initial_timestamp','actual_pdo',
            'full_helper_body','created_tables','recorded_migrations','all_schemas_match','source_migrations_unchanged',
            'compat_template_sha256','receipt_sha256','artifact_sha256'},'PROFILE_FIELDS_REQUIRED')
        identity=(row['framework'],row['engine'],row['initial_timestamp'])
        need(identity in expected and identity not in seen and type(row['initial_timestamp']) is int
            and all(row[k] is True for k in ('actual_pdo','full_helper_body','all_schemas_match','source_migrations_unchanged'))
            and type(row['created_tables']) is int and row['created_tables']==4
            and type(row['recorded_migrations']) is int and row['recorded_migrations']==2
            and row['compat_template_sha256']==COMPAT_SHA256
            and all(isinstance(row[k],str) and SHA.fullmatch(row[k]) for k in ('receipt_sha256','artifact_sha256')),
            'REAL_PROFILE_NOT_ACCEPTED')
        need(row['framework']!='8.83.29' or row.get('original_swap_failure_reproduced') is True,'ORIGINAL_DEFECT_NOT_REPRODUCED')
        seen.add(identity)
    need(seen==expected,'PROFILE_ROSTER_CHANGED');return value

def build_operator(base,package,acceptance):
    approved(acceptance)
    need(sha(canonical(base.PINS))==BASE_PINS_SHA256 and base.PINS.get('core')==CORE,'BASE_PINS_CHANGED')
    workflow=package.file(WORKFLOW,acceptance['workflow']['head_sha'])
    need(sha(workflow)==acceptance['workflow_sha256'],'ACCEPTED_WORKFLOW_CHANGED')
    template=package.file(COMPAT_TEMPLATE)
    need(sha(template)==COMPAT_SHA256
        and sha(package.file(COMPAT_TEMPLATE,acceptance['workflow']['head_sha']))==COMPAT_SHA256,
        'ACCEPTED_ADAPTER_CHANGED')
    class CompatOperator(base.Operator):
        def _migration_template(self):
            # Always execute the original immutable-template check first. This
            # supplemental acceptance never edits/rebinds the root checkpoint.
            original=super()._migration_template()
            need(sha(original)==self.pins['migration_template_sha256']
                and original!=template and original.count(b'const GO_ERP_WORKSPACE_MIGRATION_GATE = null;')==1
                and template.count(b'const GO_ERP_WORKSPACE_MIGRATION_GATE = null;')==1,'ORIGINAL_TEMPLATE_CHANGED')
            return template

        def _accept_compatibility(self):
            expected={'format':1,'kind':'go-erp-schema-adapter-compatibility-acceptance',
                'base_operator':BASE_OPERATOR,'base_pins_sha256':BASE_PINS_SHA256,
                'core':CORE,'compat_template_sha256':COMPAT_SHA256,
                'full_helper_acceptance_sha256':sha(canonical(acceptance)),
                'migration_hashes':self.pins['migration_hashes'],'migration_schemas':self.pins['migration_schemas']}
            receipt_path=self.audit/('schema-compatibility-'+COMPAT_SHA256+'.json')
            with self.held() as verify:
                self.flow=self._load();need(self.flow is not None,'EXISTING_ROOT_CHECKPOINT_REQUIRED')
                prior=self.io.read(receipt_path,0,0,optional=True,private=True)
                if prior is not None:
                    need(prior[0]==self.io.canonical(expected),'COMPATIBILITY_RECEIPT_CHANGED');return
                need(self.flow.get('phase')=='observed' and self.flow.get('prepared') is None
                    and self.flow.get('activation') is None and isinstance(self.flow.get('preparation_binding'),dict),
                    'PREPARATION_CHECKPOINT_REVIEW_REQUIRED')
                folders=[]
                for index,path in enumerate(self.audit.iterdir()):
                    need(index<1024,'AUDIT_SCAN_LIMIT')
                    if re.fullmatch(r'workspace-sql-[a-f0-9]{64}',path.name):folders.append(path)
                need(len(folders)==1,'EXISTING_SQL_INTENT_REQUIRED');folder=folders[0]
                self.io.directory(folder,0,0,private=True)
                intent=self.io.parse(self.io.read(folder/'intent.json',0,0,private=True)[0])
                intent_sha=self.states.checked_migration_intent(intent)
                need(folder.name=='workspace-sql-'+intent_sha and intent==self.states.migration_intent(
                    self.pins['web_release'],sha(canonical(self._source_plan())),intent['database_id_sha256'],
                    self.pins['migration_hashes'],self.pins['migration_schemas']),'SQL_INTENT_BINDING_CHANGED')
                binding=self.flow['preparation_binding'];actor,runner=self._activation(binding,verify,
                    sha(canonical(self._source_plan())),sha(canonical(self.pins)))
                migration=self._migration_actor(runner,verify,intent['database_id_sha256'])
                observed,snapshot=migration.observe();checkpoint=migration._checkpoint(snapshot)
                need(checkpoint['state'] in ('absent','owned-partial','owned-complete'),
                    'UNRECEIPTED_OR_FOREIGN_TABLE_REVIEW_REQUIRED')
                self.io.private_record(receipt_path,expected,verify)
            print('GO_ERP_SHARED_WORKSPACE_SCHEMA_COMPATIBILITY_REVIEWED',flush=True)

        def run(self):
            self._accept_compatibility()
            return super().run()
    return CompatOperator(package,base.PINS)

def main():
    parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('--git-dir',required=True)
    parser.add_argument('--release',required=True);parser.add_argument('--complete',action='store_true',required=True);args=parser.parse_args()
    acceptance=approved(APPROVAL)
    need(os.geteuid()==0 and socket.gethostname().split('.')[0]=='srv1836524','ROOT_HOST_REQUIRED')
    account=pwd.getpwnam('fasakha');need((account.pw_uid,account.pw_gid)==(1001,1003),'APPLICATION_ACCOUNT_CHANGED')
    need(REV.fullmatch(args.release),'RELEASE_REQUIRED');os.umask(0o077)
    raw=subprocess.check_output(['/usr/bin/git','--git-dir='+str(Path(args.git_dir).absolute()),'-c','credential.helper=',
        '-c','core.hooksPath=/dev/null','-c','core.attributesFile=/dev/null','show',args.release+':deployment/go_erp_shared_workspace_root.py'],
        stdin=subprocess.DEVNULL,stderr=subprocess.DEVNULL,timeout=60)
    need(sha(raw)==BASE_ROOT_SHA256,'BASE_ROOT_CHANGED')
    base=types.ModuleType('schema_compat_reviewed_original_root');base.__file__='<schema-compat-reviewed-original-root>'
    sys.modules[base.__name__]=base;exec(compile(raw,base.__file__,'exec'),base.__dict__)
    package=base.Package(args.git_dir,args.release);result=build_operator(base,package,acceptance).run()
    print(json.dumps({'format':1,'kind':'go-erp-shared-workspace-rollout','ok':True,**result}),flush=True)
    print('GO_ERP_SHARED_WORKSPACE_PUBLISHED_MANUAL_UPDATES_ONLY',flush=True)

if __name__=='__main__':
    try:main()
    except BaseException as error:
        code=str(error);print(code if re.fullmatch(r'GO_ERP_[A-Z0-9_]{1,160}',code)
            else 'GO_ERP_SCHEMA_COMPAT_PRIVATE_FAILURE',flush=True);raise SystemExit(1)
