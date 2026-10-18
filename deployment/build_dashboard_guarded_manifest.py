#!/usr/bin/env python3
"""Build the method fingerprints for this incremental release, without storing old source."""
import argparse
import hashlib
import json
from pathlib import Path
import subprocess
import tempfile


BASE = '9984a1f6ee065ced2fcc5eefcec5bd2f599914df'
METHODS = {
    'app/Services/Dashboard/PosServiceTicket.php': {'present': ['presentMany', 'presentation']},
    'app/Services/Dashboard/PhoneDeliveryBoard.php': {'listing': []},
    'app/Http/Controllers/Api/V1/Auth/AddressController.php': {'store': [], 'update': []},
    'app/Http/Controllers/Api/V1/Auth/UserController.php': {'forget_pass': [], 'check_code_forget_pass': [], 'reset_password': []},
    'app/Http/Traits/SendNotification.php': {'sendNotification': []},
}


def build(root, php):
    helper = root / 'deployment/dashboard_method_tokens.php'
    manifest = {'version': 1, 'reference_base': BASE,
                'tokenizer_sha256': hashlib.sha256(helper.read_bytes()).hexdigest(), 'files': {}}
    with tempfile.TemporaryDirectory() as temporary:
        for relative, names in METHODS.items():
            before = Path(temporary) / 'before.php'
            before.write_bytes(subprocess.check_output(['git', '-C', str(root), 'show', BASE + ':' + relative]))
            previous = json.loads(subprocess.check_output([php, str(helper), str(before)]))['methods']
            source = root / relative
            proposed = json.loads(subprocess.check_output([php, str(helper), str(source)]))['methods']
            rules = {}
            for name, extra in names.items():
                rules[name] = {'before': [previous[name]['hash']], 'after': proposed[name]['hash']}
                if extra:
                    rules[name]['adds'] = {method: proposed[method]['hash'] for method in extra}
            manifest['files'][relative] = {'payload_sha256': hashlib.sha256(source.read_bytes()).hexdigest(),
                                           'methods': rules}
    destination = root / 'deployment/dashboard_guarded_methods.json'
    destination.write_text(json.dumps(manifest, indent=2) + '\n')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', required=True)
    args = parser.parse_args()
    build(Path(__file__).resolve().parents[1], args.php)
