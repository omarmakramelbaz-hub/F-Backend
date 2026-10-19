"""Install only the reviewed wallet settlement additions and narrow live-source edits."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import shutil
import stat
import subprocess
import tempfile
from datetime import datetime, timezone


PAYMOB_SHA = 'b6c63574ad727cc3ed130aedbbe7d824424ec673d1a777094aefe6979d72204f'
CHECKOUT_SHA = '6233c1c14501818940e775619f9d6044f65652e59ac67aa13defce737a28dc65'
NEW_FILES = [
    'app/Services/Payments/PaymobTransactionInquiry.php',
    'app/Services/Payments/WalletTopupSettlement.php',
    'app/Services/Payments/WalletTopupEffects.php',
    'app/Http/Controllers/Payment/WalletTopupController.php',
    'config/wallet_topups.php',
]
ROUTE_ANCHOR = "    Route::get('/pament/callback', [PaymobController::class, 'callback']);"
ROUTES = """
    // Wallet callbacks verify payment through authenticated gateway inquiry.
    Route::post('/wallet-topup/paymob/notify', [\\App\\Http\\Controllers\\Payment\\WalletTopupController::class, 'notify'])->withoutMiddleware('throttle:api')->middleware('throttle:300,1')->name('wallet-topups.notify');
    Route::get('/wallet-topup/paymob/return', [\\App\\Http\\Controllers\\Payment\\WalletTopupController::class, 'returnFromGateway'])->name('wallet-topups.return');
"""
INTENTION_ANCHOR = '            "payment_methods" =>[(int)$integration_id],\n'
INTENTION_URLS = """            // Wallet completion must reach the server even if the WebView closes.
            ...($order->type === 'wallet' ? [
                'notification_url' => rtrim((string) config('app.url'), '/') . '/api/wallet-topup/paymob/notify',
                'redirection_url' => rtrim((string) config('app.url'), '/') . '/api/wallet-topup/paymob/return',
            ] : []),
"""
CHECKOUT_ANCHOR = "        $pay = Payment::where('intention_order_id', $order_id)->firstOrFail();\n        $order = Order::findOrFail($pay->order_id);\n"
CHECKOUT_GUARD = """        if ($order->type === 'wallet') {
            // Legacy returns share the same verified, atomic settlement path.
            try {
                $details = json_decode($payment, true);
                $transaction = is_array($details) ? ($details['id'] ?? null) : null;
                $transaction = (is_string($transaction) || is_int($transaction))
                    && preg_match('/\\A[1-9][0-9]{0,19}\\z/', (string) $transaction) ? (string) $transaction : null;
                app(\\App\\Services\\Payments\\WalletTopupSettlement::class)->settle((int) $pay->id, $transaction);
                return redirect()->route('paySuccess');
            } catch (\\Throwable $exception) {
                return redirect()->route('payFailed');
            }
        }
"""


def digest(data):
    return hashlib.sha256(data).hexdigest()


def regular(path, root):
    if path.is_symlink() or not path.is_file() or path.resolve() != path:
        raise RuntimeError('WALLET_INSTALL_REGULAR_FILE_REQUIRED ' + str(path.relative_to(root)))


def patched(data, expected, anchor, addition):
    original = data.decode('utf-8')
    if addition in original:
        if original.count(addition) != 1:
            raise RuntimeError('WALLET_INSTALL_DUPLICATE_PATCH')
        candidate = original.replace(addition, '', 1).encode()
        if digest(candidate) != expected:
            raise RuntimeError('WALLET_INSTALL_SOURCE_CHANGED')
        return data
    if digest(data) != expected or original.count(anchor) != 1:
        raise RuntimeError('WALLET_INSTALL_SOURCE_CHANGED')
    return original.replace(anchor, anchor + addition, 1).encode()


def install(root, payload, php):
    if root.is_symlink() or root.resolve() != root or not root.is_dir():
        raise RuntimeError('WALLET_INSTALL_INVALID_ROOT')
    targets = {}
    for relative in NEW_FILES:
        source, target = payload / relative, root / relative
        regular(source, payload)
        if target.exists() or target.is_symlink():
            regular(target, root)
            if target.read_bytes() != source.read_bytes():
                raise RuntimeError('WALLET_INSTALL_NEW_FILE_CONFLICT ' + relative)
        # Check every existing ancestor before creating or replacing a file.
        parent = target.parent
        while parent != root:
            if parent.is_symlink() or (parent.exists() and not parent.is_dir()):
                raise RuntimeError('WALLET_INSTALL_INVALID_PARENT')
            parent = parent.parent
        targets[relative] = source.read_bytes()
    for relative, sha, anchor, addition in [
        ('app/Http/Controllers/CheckoutController.php', CHECKOUT_SHA, CHECKOUT_ANCHOR, CHECKOUT_GUARD),
        ('app/Http/Controllers/Payment/PaymobController.php', PAYMOB_SHA, INTENTION_ANCHOR, INTENTION_URLS),
    ]:
        target = root / relative
        regular(target, root)
        targets[relative] = patched(target.read_bytes(), sha, anchor, addition)
    relative = 'routes/api.php'
    route_file = root / relative
    regular(route_file, root)
    routes = route_file.read_text()
    if ROUTES in routes:
        if routes.count(ROUTES) != 1:
            raise RuntimeError('WALLET_INSTALL_DUPLICATE_ROUTES')
    else:
        if routes.count(ROUTE_ANCHOR) != 1 or 'wallet-topup/paymob/' in routes:
            raise RuntimeError('WALLET_INSTALL_ROUTE_ANCHOR_CHANGED')
        routes = routes.replace(ROUTE_ANCHOR, ROUTE_ANCHOR + '\n' + ROUTES, 1)
    targets[relative] = routes.encode()

    # Complete all syntax checks before any live file changes.
    with tempfile.TemporaryDirectory(prefix='wallet-source-check-') as stage:
        for relative, content in targets.items():
            check = Path(stage) / Path(relative).name
            check.write_bytes(content)
            subprocess.run([str(php), '-l', str(check)], check=True, stdout=subprocess.DEVNULL)
    changed = {p: data for p, data in targets.items()
               if not (root / p).is_file() or (root / p).read_bytes() != data}
    if not changed:
        print('WALLET_TOPUP_INSTALL_ALREADY_CURRENT')
        return
    backup = Path(tempfile.mkdtemp(prefix=datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%SZ-'),
                                   dir=str(root.parent / 'wallet-topup-settlement-backups')))
    os.chmod(backup, 0o700)
    manifest = {}
    for relative, content in changed.items():
        target = root / relative
        if target.exists():
            saved = backup / relative
            saved.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(target, saved)
            manifest[relative] = {'before': digest(target.read_bytes()), 'after': digest(content)}
        else:
            manifest[relative] = {'before': None, 'after': digest(content)}
    (backup / 'manifest.json').write_text(json.dumps(manifest, indent=2) + '\n')
    # Services/config/controller first; legacy guard before routing and new intentions.
    order = NEW_FILES + ['app/Http/Controllers/CheckoutController.php', 'routes/api.php',
                         'app/Http/Controllers/Payment/PaymobController.php']
    for relative in order:
        if relative not in changed:
            continue
        target = root / relative
        target.parent.mkdir(parents=True, exist_ok=True)
        previous = target.stat() if target.exists() else None
        fd, temporary = tempfile.mkstemp(prefix='.' + target.name + '.', dir=str(target.parent))
        try:
            with os.fdopen(fd, 'wb') as output:
                output.write(changed[relative]); output.flush(); os.fsync(output.fileno())
            os.chmod(temporary, stat.S_IMODE(previous.st_mode) if previous else 0o644)
            os.replace(temporary, target)
        finally:
            if os.path.exists(temporary): os.unlink(temporary)
        print('UPDATE ' + relative)
    print('WALLET_TOPUP_SETTLEMENT_INSTALL_OK')
    print('BACKUP ' + str(backup))


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--root', required=True, type=Path)
    parser.add_argument('--payload', required=True, type=Path)
    parser.add_argument('--php', required=True, type=Path)
    args = parser.parse_args()
    backup_base = args.root.parent / 'wallet-topup-settlement-backups'
    if backup_base.is_symlink() or backup_base.resolve() != backup_base:
        raise RuntimeError('WALLET_INSTALL_INVALID_BACKUP_PATH')
    backup_base.mkdir(mode=0o700, exist_ok=True)
    install(args.root, args.payload, args.php)


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        # Never include application source or arbitrary exception output.
        message = str(error)
        print(message if message.startswith('WALLET_INSTALL_') else 'WALLET_INSTALL_FAILED')
        raise SystemExit(1)
