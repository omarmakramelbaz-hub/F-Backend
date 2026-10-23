#!/usr/bin/env python3
"""Read-only public source comparison for the retained payment/navigation package.

Only reviewed public filenames may be printed. Unknown filenames, file contents,
configuration, environment, account rows, and exception text are never reported.
"""
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import socket
import stat
import sys
import types

PACKAGE = Path('/var/lib/go-erp-live-updates/program-download.PLfU5USt/package')
ENTRY = 'upgrade_go_erp_payment_nav_successor_root.py'
ENTRY_PIN = '68fd6bc1f3b551bf5fa0bfd613c1f5f0dd070094'
PAYMENT_AFTER = {
    'app/Services/Dashboard/OrderBoardService.php': '2017c1aaf7456e8b28a32de4c25619cf27fcea3cbf44467c6be814a8d280a23d',
    'app/Services/Dashboard/PosServiceTicket.php': '7da5db38087515505ec3766974aea9a7bd56c64ee42a4a9c3ac5465d4dcbb3a6',
    'public/dashboard/js/phone-orders.js': '5e129d86e09e0de7b7ad02d2f375f83115bf07ca7344f7032ae0b5266afae92f',
    'public/dashboard/css/phone-delivery-refresh.css': '2094d3b19350a03e80da24afa92cefcfa23eebd4fc4a06539841bf4fa24098e7',
}
LATER_RELEASE = '31d4020a9195904e1578bd6207f5dc805aa1541d'
LATER_PINS = {'app/Http/Controllers/CheckoutController.php': ('078651b261687498182f0ff996856c2bcb351cdd8a404644f2d85ac81f28cf4d', '5fe3aadd1fff0976a0775dcbb7ed21b568144e53fcc9638da2c94adc28cf099c', 'ec76900a10c11c702659eff3199d4b751dce5343'), 'app/Http/Controllers/Payment/PaymobController.php': ('2755fa979f6701f6a63feeef5f2813553bb36c8386676c0ac64231d19e5e3e92', '7ebc0f5eb5d66da7804a3678cddc9fc7cd9af600020d234569b5bd5ade07e266', '9bd38c2a69debfea91d31d2a7f3179ab0fd631b4'), 'app/Http/Controllers/Payment/RestaurantPaymentController.php': (None, '0810327324eed83eee58b57de028efebf9b95ce771a99359495c9ea9b89b2846', 'efc57fdc0ea3f3173080a2282402e1c51305ef3e'), 'app/Services/Payments/RestaurantOrderPayment.php': (None, 'e841b9b25468dec4fcd9e2ba945c81b64c26686f3cb985742bee40534bb6b86a', '1699f40405d04abe92ae5350b57d26f0a90a8b36'), 'app/Services/Payments/RestaurantPaymentEffects.php': (None, '7e9ee79b729cec4b0f1918a55defaaee5e485262de21dfa4a82896dfa214fb1d', '23264d1573363b722b87dab064e53da1e14df6de'), 'app/Console/Commands/ReconcileRestaurantPayments.php': (None, '2267d945828dc600f7f14383a27d93dd8b468db6917a5f9ad5f8fbc4197a773a', 'ff7d17d64d8676b89dee567fa434fdecfaf59c8e'), 'app/Console/Kernel.php': ('8fb0eafbd1da789d7bbdeef46d4ed5825e53c7ebf1926b3dda7424a0622a024d', '1fdb8226b76de21967bfe60ab29105925b8b7cc079726c47e6886a276011148d', 'a3d44fd2fa9827ee2bae5fe93cffa6a470eac7af'), 'app/Services/Dashboard/OrderBoardService.php': ('2017c1aaf7456e8b28a32de4c25619cf27fcea3cbf44467c6be814a8d280a23d', '0f55a52febe52a190fe94de85a5b9de5331f0c712c5939fd7f6f864292eb562f', '6cb2330647bd701291be94fe5549f2d1bc000015'), 'routes/api.php': ('d23a57e8263159e34d07153b0b877be20b77e566d16d6e8cd7d938220b80e6eb', '4f857145372aa64a268a3b1f17eef5a65bf0ccf303cefb313fad7a64fc0b28fd', 'bc722739c14e22607028de7bb15f59a55a8ab464'), 'resources/views/admin/orders/board_card.blade.php': ('e96d2153898b6644fabaff3b944a2b4b57db1e70f69255a255c667186b66a811', '4b9645a0104b27da44bfe27d7a3f449d056d8124dcf576cf4b9fd2da453c0f5b', '6b723579bd15adc82524bd6056d79feac37aac87'), 'resources/views/admin/orders/board_detail.blade.php': ('07ae6692aac29cf3e3fb12daf4e045a0821766e55707ab7e75e9dd7e5b586bcc', 'ea75936637421cc14e73823e3c746bda5b45d2642aed8ca24babe74f18041f82', 'e94cb48239828025415861b1c8ef45621049ddbb'), 'resources/lang/ar/order_board.php': ('d36d8ab3de02b4a1a6353b7f94b2314813ae6816cee505566cbcbda622b95a16', 'e06264d6ce07b931c5937f4f9dc9399b0203f8121bc12b8a51346fc4d02fe2d4', '966b799f864d96f36b17684b6cc03a716dbea7af'), 'resources/lang/en/order_board.php': ('6cf88da57608da855f99e8b9acd9f461ebbca2a947e1d6ea1393062bd8f1f941', 'f3d8010f5876bc6a5049a920285fa2a5e81a788dc51a43c69e7e8aa1232ce394', '9a0a2eb2fdf97a4bf248547b274331b03bfd2d01'), 'public/dashboard/css/order-board.css': ('75d033c79ebedb1692b5e0c0e4fcddb0bba25bd702a3af5496ae33b717a19868', '564c41551a14a417dde7679dcd736339f3f7b009084c1c9dcf10ede5915bd061', '88093e1439246947cde3a540b1db0d14229a7d67')}
NORMALIZED_BASELINE_PINS = {'public/dashboard/vendor/desktop-external/cdn.ckeditor.com/4.14.0/standard/styles.js': ('1699ca4a59cae3236c9d806c2772f8422bf62ff740a4c8a25cd39459e0327945', '312ac2e841606bcd2d02567d25f29ba0b022596395ce2245388dff636b6f27da'), 'public/dashboard/vendor/desktop-external/cdn.ckeditor.com/4.14.0/standard/contents.css': ('9bf5aa118214cb68aa1d7c2db85aca0805f0bb4e5bdf738418440fb26ebe6fe4', '43b78285c786c968e35d8a44aafb06df291e840e106bd01ddf36df96ce84ff5d'), 'public/dashboard/vendor/desktop-external/cdn.ckeditor.com/4.14.0/standard/skins/moono-lisa/dialog.css': ('0fe84414dbe7272f788ec60dddd6304f6987161477bd982c99f4658c875d6f66', '3172fade4aa73acfb668024abd2e86a7f9babdf0ea4a791a6586ea4a4610fb7e'), 'public/dashboard/vendor/desktop-external/cdn.ckeditor.com/4.14.0/standard/skins/moono-lisa/dialog_ie.css': ('0bbb3d8e15148af6d9480a78c102ef4243e3296e23fb716b28ba9772950057b3', 'b9577cbc913ce0b858b2066e0bb7df435f2667a0276e3d2afe3c2dd5c31b44cd'), 'public/dashboard/vendor/desktop-external/cdn.ckeditor.com/4.14.0/standard/skins/moono-lisa/dialog_iequirks.css': ('9fcf206cdd1cfea9d13e9ef362f56d65e12c2464515ba6f89a277eb6c79c1287', '0e516e41aab5339563bbfef65f470a195549edcf5d076dff3949804dffec3eb4'), 'public/dashboard/vendor/desktop-external/cdn.ckeditor.com/4.14.0/standard/skins/moono-lisa/editor.css': ('8593276ee01d7347932eddeed151ff759affd8a1c4ecba7050066904b650d548', '95beed7f74e8c7637a6e63153b2042fbe299ec124b4bbb33385e091414ef2b90'), 'public/dashboard/vendor/desktop-external/cdn.ckeditor.com/4.14.0/standard/skins/moono-lisa/dialog_ie8.css': ('d6301ae22323dea51c3f49f291d1f4b4a9062ba99457693c172bd99e24cfedc8', '801d8d1bc1ecd6656c34f793a09a9c016112a986f0d3eb6015c012e41fb13903'), 'public/dashboard/vendor/desktop-external/cdn.ckeditor.com/4.14.0/standard/skins/moono-lisa/editor_iequirks.css': ('f55594daaaad2bfa028d27f89e1d59a63c3a94c19d31b03c7ce68a73bed2a391', '5886447bbf59e32e63cffaa3d7e8ebfd3549e987df3c21fac42405fde48f6705'), 'public/dashboard/vendor/desktop-external/cdn.ckeditor.com/4.14.0/standard/skins/moono-lisa/editor_ie.css': ('9f47603fca027adae0ae2299da806db7fe1a4039b6bbfe1bafe76b76d05781fc', 'b99119baa412caa6e437cc575262a31164e5d52d9136e2d08bfd5de5a92e694e'), 'public/dashboard/vendor/desktop-external/cdn.ckeditor.com/4.14.0/standard/skins/moono-lisa/editor_gecko.css': ('a1935ef06ae8861f7d618074443768665748466c083a5b86796fb80f23744d42', '3bfef1d4d9822f1e4e67832ae80dfb8a0625c9e834b049b8cd2b6a08a8f82e02'), 'public/dashboard/vendor/desktop-external/cdn.ckeditor.com/4.14.0/standard/skins/moono-lisa/editor_ie8.css': ('65c83c4a97f7b366e0da829272d04f6e8bdcc4293218d52072348dd0e75f275e', '9cbc27f136856f8e869a1bc91fc0cb6f697cb92e8ebfc34d12eed5f784013823'), 'public/dashboard/vendor/desktop-external/cdn.ckeditor.com/4.14.0/standard/plugins/dialog/styles/dialog.css': ('34597be03ec4a2bbb7e29790dfa2409302270396ca2a707d04fc456526a671a2', '944a6c1eef8a1387edab2666a401743fa249737e91a4ea870a38b74bc765ff2d'), 'public/dashboard/vendor/desktop-external/cdn.ckeditor.com/4.14.0/standard/plugins/tableselection/styles/tableselection.css': ('4e9d6995e37ce82b7d76c8a250b91adcb8822bcce06a672d59eedbd4272ca347', '06af6a726df4456d900fb36b791417aae3ba25e0815f319caf16f508dda827d2'), 'public/dashboard/vendor/desktop-external/cdn.ckeditor.com/4.14.0/standard/plugins/scayt/skins/moono-lisa/scayt.css': ('bf60e5f2e58f5cb573a161d1e18fddab3f8f14d94f56e023602e361501b7d52a', '94465f8a7abf4da8295e0fa93a60eba1287830dfc41e3698fb3d20091b95d58c'), 'public/dashboard/vendor/desktop-external/cdn.ckeditor.com/4.14.0/standard/plugins/scayt/dialogs/dialog.css': ('f733204c5ef69fb7ee5b692906df334c0da3c0d1f3eaa30121fe6f072cb2abc2', '75e0679953e586ffac55af12700c15b9a4b7d49765103c16fd8943c24162bf0c'), 'public/dashboard/vendor/desktop-external/cdn.ckeditor.com/4.14.0/standard/plugins/scayt/dialogs/toolbar.css': ('72d4fc29f9371dee54146bc9e308de97bb6bc9b4473172db5a3703b39bad4b71', 'e66191abbfc4b5e5e6291bf4d4cacb4e074bb8d94457ff5384b3f18e72195a2c'), 'public/dashboard/vendor/desktop-external/cdn.ckeditor.com/4.14.0/standard/plugins/wsc/skins/moono-lisa/wsc.css': ('48a790bfb941e6852c8b1b0552af231f0fc129a28f1b17f406f9fc487af9879e', 'a0fc7fe1ba275090a93038c52950ddb538963b336f0009f4c6efd1722717404f'), 'public/dashboard/vendor/desktop-external/cdn.ckeditor.com/4.14.0/standard/plugins/wsc/dialogs/tmpFrameset.html': ('6a3fee8581a7c81c67a1b7245cfbc5d3e8cd91809c26d61a3f745e7cdede5199', 'b0469cc15d45f9e9f59c7b478adf12fe5300450f6579b4226cf746bbf79a2387'), 'public/dashboard/vendor/desktop-external/cdn.ckeditor.com/4.14.0/standard/plugins/wsc/dialogs/ciframe.html': ('52a7adca1aaf7634e4bf2969f4c15b5baa9cfff8b73502ef1a9cda8c86c965c6', 'bbc1f85ed681f80c12a29fc6e390fe3259fc127faadd7a1ab87cc6acf13705f3'), 'public/dashboard/vendor/desktop-external/cdn.ckeditor.com/4.14.0/standard/plugins/wsc/dialogs/wsc.css': ('655dcf78d6c556019ed776988dcc8b5b29f83f83638e0f7678e3e3253175e892', '0d1cf66009e0451fc8b73a8c6e329575ca4dc34dd12a695ee37905b0fcc0424c')}
MAX_ROWS = 100
LATER_SOURCE = {'format': 1, 'files': 1346, 'framework': '8.83.29',
                'sha256': '5825ec04b927fcafc42e04bd9ee4705a2e1862bb62be700a116502eacad60a1e'}
REGULATION_BLOBS = {
    'app/Http/Controllers/Dashboard/BranchOperationsController.php': 'b3cbe80593e4383ffb6392dce4c7918df39fa5fb',
    'resources/views/admin/branch_operations/regulation.blade.php': '2d9578661d17258fab6563bcbf8202160c423a3b',
}
REGULATION_OLD = 'resources/views/admin/employees/regulation.blade.php'


class DiagnosticStop(Exception):
    pass


def need(value):
    if not value:
        raise DiagnosticStop()


def blob(data):
    return hashlib.sha1(b'blob ' + str(len(data)).encode() + b'\0' + data).hexdigest()


def read_entry(package):
    path = package / ENTRY
    for parent in (path.parent, *path.parent.parents):
        item = parent.lstat()
        need(stat.S_ISDIR(item.st_mode) and not stat.S_ISLNK(item.st_mode)
             and item.st_uid == 0 and not item.st_mode & 0o022)
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        before = os.fstat(fd)
        key = lambda item: (item.st_dev, item.st_ino, item.st_mode, item.st_uid, item.st_gid,
                            item.st_nlink, item.st_size, item.st_mtime_ns, item.st_ctime_ns)
        need(stat.S_ISREG(before.st_mode) and
             (before.st_uid, before.st_gid, before.st_nlink, stat.S_IMODE(before.st_mode)) == (0, 0, 1, 0o644)
             and 0 < before.st_size <= 2 * 1024 * 1024)
        data = bytearray()
        while len(data) <= 2 * 1024 * 1024:
            part = os.read(fd, min(65536, 2 * 1024 * 1024 + 1 - len(data)))
            if not part:
                break
            data.extend(part)
        need(len(data) == before.st_size and key(before) == key(os.fstat(fd)) == key(path.lstat())
             and blob(data) == ENTRY_PIN)
    finally:
        os.close(fd)
    value = types.ModuleType('readonly_payment_navigation_source_entry')
    value.__file__ = str(path)
    sys.modules[value.__name__] = value
    exec(compile(bytes(data), str(path), 'exec'), value.__dict__)
    return value


def source_baselines(ctx, entry, scanner):
    """Use only the exact already present candidate36 metadata, without fetching."""
    data = ctx.base.git(entry.WORK / 'checkout', 'show', entry.OLD_CANDIDATE + ':' + ctx.prior.VERSIONS[2])
    metadata = ctx.base.parse(data)
    inventory = metadata.get('inventory')
    need(isinstance(inventory, dict) and metadata == ctx.prior.candidate_metadata(entry.SEED, inventory, 36)
         and metadata.get('inventorySha256') == entry.OLD_INVENTORY)
    before = {}
    for name, row in inventory.items():
        need(isinstance(name, str) and isinstance(row, dict))
        if not scanner.selected(name) or not row.get('present'):
            continue
        need(bool(scanner.SAFE.fullmatch(name)) and not scanner.SECRET.search(name)
             and all(part not in ('', '.', '..') for part in name.split('/'))
             and isinstance(row.get('sha256'), str) and re.fullmatch(r'[a-f0-9]{64}', row['sha256']))
        before[name] = {'sha256': row['sha256']}
    # Twenty reviewed CKEditor assets use CRLF. Normalize only their exact
    # immutable raw pins; every other raw hash is validated by the final digest.
    for name, (raw, normalized) in NORMALIZED_BASELINE_PINS.items():
        need(before.get(name, {}).get('sha256') == raw)
        before[name] = {'sha256': normalized}
    need(scanner.digest(before) == entry.OLD_SOURCE)
    payment = {name: dict(value) for name, value in before.items()}
    need(set(PAYMENT_AFTER) == set(entry.PAYMENT_PINS))
    for name, fingerprint in PAYMENT_AFTER.items():
        need(name in payment)
        payment[name] = {'sha256': fingerprint}
    need(scanner.digest(payment) == entry.BEFORE_SOURCE)
    later = {name: dict(value) for name, value in payment.items()}
    for name, pin in LATER_PINS.items():
        old, after, _ = pin
        need(scanner.selected(name) and bool(scanner.SAFE.fullmatch(name)) and not scanner.SECRET.search(name)
             and (later.get(name, {}).get('sha256')) == old)
        later[name] = {'sha256': after}
    need(scanner.digest(later) == LATER_SOURCE)
    return before, payment, later


def compare_sources(before, payment, later, actual):
    """Unknown actual names are counted, never returned or put into errors."""
    allowed = set(payment) | set(LATER_PINS) | set(REGULATION_BLOBS)
    changes = []
    for name in sorted(allowed):
        expected = payment.get(name, {}).get('sha256')
        value = actual.get(name)
        observed = value.get('sha256') if isinstance(value, dict) else None
        if expected == observed:
            continue
        row = {'path': name, 'prepared_sha256': before.get(name, {}).get('sha256'),
               'post_payment_sha256': expected, 'actual_sha256': observed,
               'status': 'missing' if value is None else 'different'}
        if name in LATER_PINS:
            row['matches_later_release'] = observed == later[name]['sha256']
        if name in REGULATION_BLOBS:
            row['matches_regulation_move'] = isinstance(value, dict) and value.get('blob') == REGULATION_BLOBS[name]
        changes.append(row)
    return {'selected_difference_count': len(changes), 'selected_differences': changes[:MAX_ROWS],
            'selected_differences_truncated': len(changes) > MAX_ROWS,
            'unknown_selected_file_count': len(set(actual) - allowed)}


def prepared_targets(ctx, entry):
    rows, matched = [], 0
    # Every output path comes from the exact pinned prior module's fixed roster.
    with ctx.application.PinnedSourceReader(entry.ROOT, ctx.owner.pw_uid) as reader:
        for name, wanted in sorted(ctx.prior.TARGET_PINS.items()):
            try:
                data, _ = reader._read(name)
                actual = blob(data) if data is not None else None
            except BaseException:
                rows.append({'path': name, 'status': 'unreadable', 'prepared_blob': wanted})
                continue
            if actual == wanted:
                matched += 1
                continue
            row = {'path': name, 'status': 'missing' if actual is None else 'different',
                   'prepared_blob': wanted, 'actual_blob': actual}
            if name in LATER_PINS:
                row['matches_later_release'] = actual == LATER_PINS[name][2]
            if name in REGULATION_BLOBS:
                row['matches_regulation_move'] = actual == REGULATION_BLOBS[name]
            rows.append(row)
    return {'prepared_target_count': len(ctx.prior.TARGET_PINS), 'prepared_target_matches': matched,
            'prepared_target_differences': rows}


def run(package=PACKAGE):
    result = {'format': 1, 'kind': 'go-erp-payment-navigation-public-source-diagnostic',
              'application_writes': False, 'database_accessed': False, 'service_changed': False,
              'candidate_fetch_performed': False, 'credentials_disclosed': False}
    stage = 'host'
    try:
        need(os.geteuid() == 0 and socket.gethostname().split('.')[0] == 'srv1836524')
        stage = 'pinned-context'
        entry = read_entry(package)
        ctx = entry.Context(package)
        stage = 'quiescent'
        ctx.prior.assert_quiescent()
        with ctx.prior.held_lock(ctx.utility, entry.WORK) as (_, verify):
            stage = 'prepared-targets'
            result.update(prepared_targets(ctx, entry))
            stage = 'source-baselines'
            scanner = ctx.application.pinned_scanner(package)
            before, payment, later = source_baselines(ctx, entry, scanner)
            result['prepared_source'] = scanner.digest(before)
            result['post_payment_source'] = scanner.digest(payment)
            result['known_later_release'] = LATER_RELEASE
            result['known_later_source'] = scanner.digest(later)
            stage = 'actual-source'
            actual = scanner.scan(entry.ROOT)
            result['actual_source'] = scanner.digest(actual)
            result.update(compare_sources(before, payment, later, actual))
            result['matches_later_source'] = result['actual_source'] == result['known_later_source']
            result['regulation_move_matches'] = (REGULATION_OLD not in actual and all(
                actual.get(name, {}).get('blob') == pin for name, pin in REGULATION_BLOBS.items()))
            stage = 'manifest-presence'
            result['manifest_present'] = ctx.application.optional_manifest(entry.ROOT, ctx.owner.pw_uid)[0] is not None
            verify()
        result['ok'] = True
    except BaseException:
        result.update(ok=False, failed_step=stage, reason='READ_ONLY_CHECK_FAILED')
    return result


if __name__ == '__main__':
    print(json.dumps(run(), sort_keys=True))
