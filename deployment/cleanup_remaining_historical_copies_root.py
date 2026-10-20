#!/usr/bin/env python3
"""Clean explicitly inventoried historical copies, preserving the verified current backup."""
import argparse
import fcntl
import os
from pathlib import Path
import socket
import sys

sys.dont_write_bytecode = True
import audit_remaining_backup_candidates_root as audit
import cleanup_unused_cpanel_copies_root as cleanup
from verify_archived_cleanup_copies import CoverageError, verify as verify_archived

HOME = '/home/fasakha'
LOCK_PATH = '/run/lock/fasakhansta-all-apps-backup.lock'
FAILED = '/root/fasakhansta-full-backups/20261019T235916Z-current-datqg7a0'
ACCOUNT_ARCHIVES = (
    HOME + '/fasakhaninja-backup-2026-07-31-2203.tar.gz',
    HOME + '/backup-9.23.2025_13-54-06_fasakha.tar.gz')
ROOT_COPIES = (
    '/root/fasakhansta-backend-before-restore-20260811-155421.tar.gz',
    '/root/fasakhansta-backup-2026-08-11')
# Every name below appeared in the owner's server inventory. No directory glob.
COMPONENT_NAMES = (
    'desktop-dashboard-release-backups', 'employee-wallet-branch-backups',
    'wallet-contrast-backups', 'home-all-branches-label-backups',
    'meta-inbox-layout-backups', 'meta-media-agent-backups',
    'employee-auto-absence-backups', 'social-comments-layout-backups',
    'branch-view-directives-backups', 'dashboard-login-session-backups',
    'direct-order-printing-backups', 'branch-stock-compact-backups',
    'expense-category-submit-backups', 'web-storefront-seo-backups',
    'wallet-topup-settlement-backups', 'order-editor-feedback-backups',
    'sidebar-position-backups', 'expense-reject-button-backups',
    'social-comments-timeline-backups', 'social-comments-original-backups',
    'app-order-items-pos-backups', 'dining-cash-enter-kitchen-backups',
    'dashboard-guarded-method-backups', 'order-live-activity-backups',
    'pos-consent-enter-backups', 'shift-compact-preview-backups',
    'sidebar-release-backups', 'customer-marketing-template-backups',
    'expense-basic-categories-backups', 'dining-cash-collection-backups',
    'sidebar-footer-backups', 'branch-basic-recipes-backups',
    'expense-branch-labels-backups', 'employee-part-time-backups',
    'store-shubra-fulfillment-backups', 'home-stock-minimum-backups',
    'social-comments-pending-backups', 'collapsed-shortcuts-backups',
    'phone-customer-auto-branch-backups', 'customer-followup-template-backups',
    'app-order-items-backups', 'header-employee-shortcut-backups',
    'employee-pay-hours-backups', 'expense-branch-local-categories-backups',
    'whatsapp-customer-capture-backups', 'order-alert-menu-backups',
    'order-board-lifecycle-backups', 'expense-stock-purchases-backups',
    'phone-customer-enter-flow-backups', 'phone-delivery-menu-layout-backups',
    'phone-delivery-timing-backups', 'pos-preparation-picker-backups',
    'pos-whole-label-backups', 'branch-treasury-notice-backups',
    'employee-net-total-backups', 'phone-delivery-unified-backups',
    'phone-delivery-workflow-backups', 'shift-report-backups',
    'order-board-release-backups', 'pos-followup-once-backups',
    'web-customer-address-backups', 'phone-preparation-consent-backups',
    'branch-stock-minimum-backups', 'pos-first-print-policy-backups',
    'web-storefront-backups', 'pos-inline-quantity-backups',
    'phone-delivery-refresh-backups', 'dashboard-all-branches-backups',
    'go-erp-logo-backups', 'meta-comment-ai-backups',
    'customer-crm-release-backups', 'meta-release-backups',
    'legacy-customer-import-backups', 'customer-service-consent-backups',
    'go-services-backups', 'whatsapp-release-backups',
    'actual-launch-reset-backups', 'desktop-pos-release-backups')
COMPONENTS = tuple(HOME + '/' + name for name in COMPONENT_NAMES)
WHITELIST = ('/home/cpmove-fasakha',) + ACCOUNT_ARCHIVES + ROOT_COPIES + (FAILED,) + COMPONENTS


def configure_scope():
    audit.CANDIDATES = WHITELIST
    scopes = list(audit.CONFIGS)
    for live in (Path(HOME) / 'public_html', Path('/root/fasakhansta-backend')):
        scopes.extend(str(live / name) for name in ('app', 'routes', 'resources'))
        if live.is_dir():
            scopes.extend(str(path) for path in live.glob('.env*'))
    audit.CONFIGS = tuple(dict.fromkeys(scopes))


def select_plan(cutoff_ns, manifest):
    selected, retained = [], []
    for path in audit.candidates():
        try:
            if path == '/home/cpmove-fasakha':
                record = audit.inspect_candidate(path)
                cleanup.require_cpanel_copy(path, record, manifest['database_verification'])
            elif path == FAILED:
                audit.need(not os.path.lexists(path + '/backup.ok'),
                           'Failed attempt now has a backup marker; retained.')
                audit.need(path != audit.BACKUP, 'Cannot remove the retained current backup.')
            elif path == ROOT_COPIES[0]:
                # Probe without extracting or printing any archive file contents.
                audit.inspect_candidate(path)
            record = cleanup.snapshot(path, cutoff_ns)
        except (audit.AuditError, OSError) as error:
            retained.append(path)
            reason = str(error) if isinstance(error, audit.AuditError) else type(error).__name__
            print('RETAINED=' + path + '\nRETAINED_REASON=' + reason, flush=True)
            continue
        selected.append(record)
        print('ELIGIBLE_OLD_COPY=' + path, flush=True)
    return selected, retained


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--apply', action='store_true')
    args = parser.parse_args(argv)
    os.umask(0o077)
    audit.need(os.geteuid() == 0 and socket.gethostname().split('.')[0] == 'srv1836524',
               'Run as root only on srv1836524.')
    os.chdir('/')
    configure_scope()
    with open(LOCK_PATH, 'a') as lock:
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise audit.AuditError('Another backup or cleanup is running.')
        print('VERIFYING_RETAINED_COMPLETE_BACKUP', flush=True)
        manifest = audit.verify_backup()
        cutoff_ns = cleanup.cutoff_from_manifest(manifest)
        plan, retained = select_plan(cutoff_ns, manifest)
        if not plan:
            print('NO_ELIGIBLE_OLD_COPIES\n' + ('CLEANUP_PARTIAL' if retained else 'CLEANUP_COMPLETE'), flush=True)
            return
        paths = [record['path'] for record in plan]
        print('CHECKING_REMAINING_COPY_DEPENDENCIES', flush=True)
        cleanup.no_dependencies(paths)
        covered = [path for path in paths if audit.inside(path, HOME)]
        coverage = {}
        if covered:
            print('COMPARING_OLD_COPIES_WITH_RETAINED_ARCHIVE=' + str(len(covered)), flush=True)
            coverage = verify_archived(HOME, audit.BACKUP + '/home-current.tar.gz', covered)
            print('ARCHIVED_OLD_COPIES_BYTE_VERIFIED', flush=True)
        # Reject changes during the archive comparison before reaching --apply.
        for record in plan:
            audit.need(cleanup.snapshot(record['path'], cutoff_ns) == record,
                       'Old copy changed during archive verification: ' + record['path'])
        if args.apply:
            cleanup.apply_plan(plan, cutoff_ns, extra_report={
                'archived_byte_coverage': coverage,
                'preserved_home_archive': audit.BACKUP + '/home-current.tar.gz'})
            print('CLEANUP_PARTIAL' if retained else 'CLEANUP_COMPLETE', flush=True)
        else:
            print('CHECK_COMPLETE\nAUDIT_ONLY=NO_FILES_REMOVED', flush=True)


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        label = str(error) if isinstance(error, (audit.AuditError, CoverageError)) else type(error).__name__
        print('CLEANUP_STOPPED: ' + label, file=sys.stderr, flush=True)
        sys.exit(1)
