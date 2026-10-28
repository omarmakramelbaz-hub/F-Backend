#!/usr/bin/env python3
"""Bind the existing source WAL to the three reviewed performance assets.

The original root loader authenticates the supplied source helper. This module
does not replace that helper, write an environment value, run PHP, migrate a
database, alter a service or allocate a release. Constructing its transaction
persists the original before-images/intent under the original held locks;
applying or resuming it remains the coordinator's explicit responsibility.
"""
from pathlib import Path


RELEASE = '11c739039e5c6320b3542d414682d755c7902790'
ROOT = Path('/home/fasakha/public_html')
AUDITS = Path('/var/lib/go-erp-manual-updates/private/audits')
OWNER_UID = 1001
OWNER_GID = 1003
PINS = {
    'public/dashboard/js/dashboard-spa.js': {
        'before': 'dd23e797e30e222824a484a2ecd88b59d917fac3079e163764c455c086e40d70',
        'after': '243b737fe3245b50190319468cdc3d2cad73ee9d56786a60ded92bada77edfaa'},
    'public/dashboard/js/desktop-client.js': {
        'before': '700d6dbaeb530613bfd87ed1b6ae878980155a6ced471eac185d8f8af9911013',
        'after': 'c3dcb6f0b6f249f6db5d7bbae7ea96172fd9a92d2246c9c78abc4a5918bf2621'},
    'public/dashboard/js/order-board.js': {
        'before': 'f9f1fbc57c03fef1e8183b8ddc5aa7b54ee1d232bb3b2338ea8f82a6c28cbe98',
        'after': '6330fb5f23ea363e2787b5d413744a9729d45dfe210e46b2528207e302ddac9e'},
}
ORDER = tuple(sorted(PINS))


def checked_plan(source, payloads):
    """Validate the exact immutable roster/payloads without touching the site."""
    return source.checked_plan(ROOT, RELEASE, OWNER_UID, OWNER_GID,
                               PINS, payloads, (), ORDER)


def transaction(source, payloads, *, verify_locks, checkpoint=None):
    """Use the original no-follow, inode-bound, fsynced source transaction."""
    checked_plan(source, payloads)
    return source.Transaction(ROOT, AUDITS, RELEASE, OWNER_UID, OWNER_GID,
                              payloads, PINS, (), verify_locks, checkpoint,
                              replacement_order=ORDER)


if __name__ == '__main__':
    raise SystemExit('GO_ERP_DESKTOP_PERFORMANCE_SOURCE_USE_PINNED_COORDINATOR')
