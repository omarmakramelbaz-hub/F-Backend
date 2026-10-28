#!/usr/bin/env python3
"""Derive performance native payloads from the exact current workspace cohort.

This pure adapter neither reads server configuration nor installs, executes or
publishes a worker. The caller still owns the original held transaction, source
capture, predecessor proof, CI/installer acceptance and one-candidate allocation.
Only the reviewed seed and three packaged-native proof hashes may change.
"""
import ast
import copy
import hashlib
import re

CURRENT_SEED = 'dc06af6f8e4ef9ea03a3fb9c728d26acb2f8193a'
CURRENT_CORE = '052e69008da7e330815b376824cd8afc18cf04c1'
CURRENT_PINS = {'desktop_dashboard_server_preflight.py': {'blob': '4f3feb5b5c68ae06039c56758f6a72aebd3bdbc4',
                                           'sha256': '21a8f2ffa16e47322853c33de07af855d8fcb4cc6759750dfda84ca5a0db33bb',
                                           'size': 10515},
 'diagnose_desktop_dashboard_current_source.py': {'blob': 'e7a7a8271481013a1c16167d6f7a6ff429687ef5',
                                                  'sha256': '3e3c852b5916f0cf38d7deb16656e0c13f855f0b5bf8fb326b2e25250d3be7ed',
                                                  'size': 16743},
 'go_erp_current_candidate_ci.yml': {'blob': 'af4d9bb97572c84e26cb6750d380adde2f7e9423',
                                     'sha256': '203ca21186223cacb7feb98dc8eb372bceb258c4e0f01e4c7a8b917b417271ea',
                                     'size': 7231},
 'go_erp_live_source.py': {'blob': 'a781ecf3c51bfb5cdbd4bc7f0592edad866b1f10',
                           'sha256': 'bcc72a63ad69691aa2626dba0d3987da6e5af5386b8850ce988a69094917e344',
                           'size': 38824},
 'go_erp_live_updates.py': {'blob': '5818feb84b52f8706f6a0429aac738e8f741773b',
                            'sha256': '9a53103762c01884a60bcf81d68ec1efa0763fc38ff4ce6af482cc0c122cc8e1',
                            'size': 79669},
 'publish_go_erp_update.py': {'blob': 'f9a7353b0705383844a576ab6d0f5b1662912bfb',
                              'sha256': '3a277fcbe44f3b58bc89be907928ed3f6dcb1bb43e21c30e2f83948ab8245490',
                              'size': 18089},
 'verify_go_erp_current_candidate_ci.py': {'blob': 'fb2c6ee364fae14062148846b654df8c96bba0de',
                                           'sha256': 'e005df2cd2229188d79418754dedd1ea18cbfb5256727ee126a881dfa660461a',
                                           'size': 10634}}
CURRENT_NATIVE_HASHES = {'account-login.cjs': '7893c3878d24e834f819c6cceb385c3268add103edd064405b52834d140def5e',
 'account-picker-preload.cjs': '28f3b722f35ea4350afcc74fd2e9d098d1fb2145382fc2b7bd7f79ebe908ac5c',
 'account-picker.cjs': '8f75a9c90109db4944052d1840363e1d4556ecad95311348dcc6f3860eb52b5e',
 'account-profiles.cjs': '993170aa1ec520aa6424b8e42e92f77473fea45aef9c2c076196b2fecc6f1e55',
 'account-signout.cjs': '1c96013bc57c16d186dc804eb741141d3642393a3a9c8c70e062a1e814df10c5',
 'account-switch.cjs': '673ff879883febd989e5d475774f208e02e708111f426c072e020486690f30b8',
 'dashboard-connected-review.cjs': 'e752272d3201753b434a4a2469698c84ba2bc238cfe90fea329b7d720ce8c26b',
 'dashboard-connected.cjs': 'a1fb3570f4419d51ad97148a8dda929473f0652bf6d5bb311ba1110e02627c9e',
 'dashboard-coverage.cjs': 'f428af93dac9682d906ff907a868b8ac5d1d172e802efd108c903a9f1acb78ed',
 'dashboard-delta.cjs': 'b929a383cf5b240c7dde60523d5371abb41b276f56a724d2056c65095184b61d',
 'dashboard-download.cjs': 'b4241bff982ad12d4199aa51a34d6bd3353cfac5916b31ee2dc4c055423753e6',
 'dashboard-generation.cjs': 'cac2795af7fee4132391f892725cd2059ce188e9161b7d995bbb482ee60f735f',
 'dashboard-get-policy.cjs': '480a4ce30cc96037ce03fa59c028ba0799911e5a505036e7cab9079a2c169a49',
 'dashboard-live-social.cjs': '54cba5db5e109120273f5b1b3dc5843be450f3f0ce7249636e9b501a598b90ba',
 'dashboard-media.cjs': '3a2a6eda3d3b72503ef689c54cb3f3d3f0f735da4f1a97911b0063f950209890',
 'dashboard-mode.cjs': '9b8257034666f7c1bbaffebd419daf5dbdf2a5f233134e8a5548c97dfa6dcd0e',
 'dashboard-outbox-review.cjs': 'a30edb2c0ef638e954138f3162e3c1a99725d4c88d3ed912c00ed27e4b8418bb',
 'dashboard-policy.cjs': 'bf023bb6d3d90e5c9e9d491af78425a914b3f14ddd1bb11a9f3b558834fe6e73',
 'dashboard-preload.cjs': '5e781c700690e9fd6378ce15cb498c0aab8f627226e5647ffc98454b079d835e',
 'dashboard-preparation.cjs': '2e38f78e7a6d960784d765559bd1a9098a16511b564f5f687737282fc1af9a02',
 'dashboard-print-effects.cjs': 'af330659d0aed8be521e4e89ab430b1f079c556901fc2f52dc28cb20713f5a7e',
 'dashboard-print-sync.cjs': '81b1909b138c7b7e824d15ed079a53f209f312cddcdb17247e29ccc817f1340a',
 'dashboard-remote-attempts.cjs': '1d7e47b6f0361effeb8e42f8d1f260f2a898fe06f758a556cb0ceae6670d4297',
 'dashboard-remote-state.cjs': 'f4bcac724326023ea6eec6a38e2ceade3b88a59d97ca4f5d5ef8c25cae327456',
 'dashboard-source.cjs': '6099f1c378db0fb41cdbc37fece95338234b5812c69718bec76d23e63c299f19',
 'dashboard-sync.cjs': '3471cc4f1c6c8f1181cef0f57cf850cf955727c79a3f70c5aa6753b52e326a2c',
 'dashboard-updater.cjs': '26470e192724aa8f08ca612cd5f7c150a1240d7891bf439e0f2c64a73e1e2511',
 'dashboard.cjs': '730e96757bd7a19e13485f6d6104b3a318de7d9ebcaad4904c40c52a813619b9',
 'lifecycle-trace.cjs': '6971373cf5349edc81df2286506e51cff0039b46166d2c8b20aeec9018f5a5b3',
 'local-runtime.cjs': 'c6b3006a58398a100be9e12b83771034d37f84849ca3fff464497a01aaa05d5c',
 'login-preload.cjs': 'ce14e2d7dadc27c1928d71ffee428ac6a1fde6b5e9badf7d1e53418ff024d592',
 'login-window.cjs': 'c9dfbb3c78651216b5af5b60a4c732212b70f01de6f08440a513dd13bc5faa1e',
 'main.cjs': 'ac39f3bcd0a22d73792f522db05ba69e56a773bd8b90b3182a18e9f600e8d424',
 'native-auth-http.cjs': '5db0080d238f2e3717804fd8242ea2ee08c38844b78abedc2f6bbf08c903f486',
 'native-deadline.cjs': 'abae563dc7912f7165eb5cf85680fcebce525b63af1bd113d5c61b7a0e8300d0',
 'native-pdf.cjs': '7a32ed38275907186dc4232203a4e0fb4b0bf14f1e166d74cbabdadd05981242',
 'preload.cjs': '679f4c9badfefae5aa4138acfe9e44540a4eeae13f2f2bbfa408c374bcd4708c',
 'receipt.cjs': '73367398a935befae88a31a827a5e0c9e53b0e63962e0067348941d8b8978343',
 'runtime-archive.cjs': '1552fe46b85c29d01697a75fe8232d2eef30377c90c06eb700d121628e27265f',
 'runtime-compact.cjs': 'ff955619e9cfe4be9410b78dd849eed511bf3a6323466e0bae163f4eb402a542',
 'runtime-inventory-worker.cjs': '43225d6f04b4c9e54b19bfb009da96af72fad41d7d59f5da6b2a1c726e4132f0',
 'runtime-media.cjs': '62803c2dbc20899650619b18f5321c6f5a49d0c5853792f2a6553f5a3cfbde73',
 'shutdown.cjs': 'def1ab7cf95a9455e6e9845949c431d93bec47acee38a6b26fe1710a06debb64',
 'startup-source-idle.cjs': '87d3ca18182e183d99e5c7fec3bb16482f6527f54f5e39dc7b50e9e8a50a3928',
 'startup-source-upgrade.cjs': '429cd0b29eb9b4ffca3217ab455086f1783f27c21dca3675a593d8c530b68c5c',
 'store.cjs': '25dbe2aaaa467489e8d0d043a1f4935f62bd83a9399679ef40a9cbe7979dbb44',
 'sync-schedule.cjs': '3330293eb7fc9c103c513bb99cc31dcf6a78b7937121b4e4b0e11d65e4f027f8',
 'sync.cjs': '5158195671570d427ce4540b014d1861ecc92a8f67132b73afc8b6f2af452be4',
 'update-install.cjs': '43f781094ffb349d5d5fca796f47076af06236ff173eac3d3dfebf3e616194e7',
 'workspace-actor.cjs': 'd58b09cd2315e0c5eaa4a402b5d02920bdbb290bd236e32247e4a0e4e3ac45ba',
 'workspace-connected.cjs': '511d45584c4fcf5adfafe69d301d9d49ec2b5544ad2fc7aba179737a461e2e1b',
 'workspace-delta-runtime.cjs': '251c846a1057a9519763ec4850ab779e70bcf75320d1da9e00b4589a65a5a6e8',
 'workspace-delta-unchanged.cjs': '7596036a43835c297171e641580f501659b3516a830d5cb8ab83731e2e27d93e',
 'workspace-delta.cjs': 'e61a37b56f0946bfc72d6f150de08efabc14903a54741a397db5874fc52dd3c2',
 'workspace-enrollment-seal.cjs': '6db08588b0be555b2a9814e8bc75bc3cb7eab8aa7269e94ea8d10e9c6e148af7',
 'workspace-guard.cjs': 'fc30fab01e614ef343c302470ca7289b4d35dc2d42ef34a8a6918a190ef37040',
 'workspace-http.cjs': '034fb7499dd4071a991e78c54a9658a62d84c3e0252a52b4cead7a0726d26168',
 'workspace-json-field.cjs': '938b51892167dc55589f508c3f692dd29e7886eecfda3f9d611a306f762b02c9',
 'workspace-legacy-inspector.cjs': '9a08a246920986f4cbc6de21e906555146b2cf54feaba3fe8547c6a4f27264be',
 'workspace-live-social.cjs': '9c830a0e74efa200525f2fc685152866ffd0c131e5691aad8c601c86d66deef3',
 'workspace-login.cjs': '196dbb0ead6452b0ae82696d4e737915f64b5ee92e14cfc3ea32007f4e3d5b84',
 'workspace-migration-controller.cjs': 'ea526c26185b4d4c0e9aca5e90b9b726d7dfd9f2acb500fecf3825a5cf7d3c1a',
 'workspace-migration.cjs': 'e657f27de7a964d1a5c2f1bf53ecc63c3ff7c571ac4ae92274ddeee850d321a2',
 'workspace-mode.cjs': '829ab325e1a00d57ab9e17e9284c0d8a125736f45bcabd5b8a3309011e17f4c9',
 'workspace-native.cjs': 'bf5f82ecff9cb1ad21e6eaf2ead511a48d2165dc697e6013c352727e40eebce2',
 'workspace-online-login.cjs': 'ecffddf12a237b9f8cc68973d27e8266a62987df72834964f29e4c5fa64a875d',
 'workspace-outbox-review.cjs': '001d874b3ae32e50a1e8a58ffb50288cec56721d6c2c313be6e3d80c240fb7e4',
 'workspace-password-lease.cjs': '397d95d8caf48c272049a4a9ac1340165c772e21ae97bf6d5e98d94ae24aa139',
 'workspace-policy.cjs': '890cdaf5cf0a738ba50bd317d06d7813dd3e315ce0486812086f486e0b428cbe',
 'workspace-preparation.cjs': 'e2bc092c797a8a78d2c658f76fe2bc38560500fe0cf6c858f6fc1b4bbcc49c1f',
 'workspace-prepared.cjs': 'bf2bc66bc88caff0d19c8dda5b0807b5f18204a3cf9d16b446b170cc4b9ee46a',
 'workspace-profile.cjs': '1bfce9e6377394ad2ae286a194b892012943f51c7fbcbd0e83ea580b5bd24cd0',
 'workspace-protocol.cjs': '524d2140e4ea85db7991c2f888149c7d32c155a526521291ae7b8d409d3e3e8c',
 'workspace-remote-attempts.cjs': 'be00d832799254e0abc4ada66859ef36d29b1d4b5bbbf8a19eddb3849caea295',
 'workspace-remote-state.cjs': 'a2fd7327cbc51afc8abb5bc1fcc0541907d5e89960dfa3a4657e345815f4c274',
 'workspace-runtime.cjs': '8f91398f0d4cdfcb2e8fe10090151454499e4f97f6d89d1f0f00a0262843e79c',
 'workspace-source.cjs': 'ac8cfd86a03db3ad920cc3424e9d489326fa499bf243c0bef0708977effcd0e8',
 'workspace-sync.cjs': 'e152422de41d40b679b0f353e0bed8f58ab877f8ab01b7577d33563116517123'}
CHANGED_NATIVE = frozenset(('main.cjs', 'dashboard.cjs', 'workspace-delta.cjs'))
SHA = re.compile(r'[a-f0-9]{64}\Z')
REV = re.compile(r'[a-f0-9]{40}\Z')


class Stop(Exception):
    pass


def need(value, code):
    if not value:
        raise Stop('GO_ERP_DESKTOP_PERFORMANCE_PAYLOAD_' + code)


def sha(raw):
    return hashlib.sha256(raw).hexdigest()


def blob(raw):
    return hashlib.sha1(b'blob ' + str(len(raw)).encode() + b'\0' + raw).hexdigest()


def _seed(value):
    need(isinstance(value, str) and REV.fullmatch(value) and value != '0' * 40, 'SEED_REQUIRED')
    return value


def _native_map(value):
    need(isinstance(value, dict) and CHANGED_NATIVE <= set(value)
         and all(isinstance(name, str) and re.fullmatch(r'[A-Za-z0-9_.-]+\.cjs', name)
                 and isinstance(digest, str) and SHA.fullmatch(digest)
                 for name, digest in value.items()), 'EXACT_ASAR_NATIVE_MAP_REQUIRED')
    return value


def _change_scope(before, after):
    _native_map(before)
    _native_map(after)
    need(set(before) == set(after), 'NATIVE_ROSTER_CHANGED')
    need({name for name in before if before[name] != after[name]} == CHANGED_NATIVE,
         'NATIVE_CHANGE_SCOPE_CHANGED')


def rebind_seed(raw, old_seed, seed):
    """Change the one reviewed module assignment, preserving all other bytes."""
    _seed(old_seed)
    _seed(seed)
    need(seed != old_seed, 'NEW_SEED_REQUIRED')
    need(isinstance(raw, bytes), 'PAYLOAD_BYTES_REQUIRED')
    text = raw.decode('utf-8')
    tree = ast.parse(text)
    assignments = [node for node in tree.body if isinstance(node, ast.Assign)
                   and len(node.targets) == 1 and isinstance(node.targets[0], ast.Name)
                   and node.targets[0].id == 'PREVIEW31_SEED']
    need(len(assignments) == 1 and isinstance(assignments[0].value, ast.Constant)
         and assignments[0].value.value == old_seed, 'SEED_ASSIGNMENT_CHANGED')
    before = ("PREVIEW31_SEED = '" + old_seed + "'").encode('ascii')
    after = ("PREVIEW31_SEED = '" + seed + "'").encode('ascii')
    need(raw.count(old_seed.encode('ascii')) == 1 and raw.count(before) == 1,
         'SEED_CONTEXT_CHANGED')
    result = raw.replace(before, after)
    need(result.replace(after, before) == raw, 'SEED_REBIND_CHANGED_OTHER_BYTES')
    compile(result, '<performance-native-seed>', 'exec')
    return result


def rebind_worker(raw, old_seed, seed, old_native_hashes, native_hashes):
    """Rebind only the seed and existing installed-ASAR map predicate."""
    _change_scope(old_native_hashes, native_hashes)
    need(isinstance(raw, bytes), 'PAYLOAD_BYTES_REQUIRED')
    text = raw.decode('utf-8')
    tree = ast.parse(text)
    functions = [node for node in tree.body if isinstance(node, ast.FunctionDef)
                 and node.name == 'verify_installed_startup_source']
    need(len(functions) == 1, 'STARTUP_VERIFIER_CONTEXT_CHANGED')
    matches = []
    for node in ast.walk(functions[0]):
        if (isinstance(node, ast.Compare) and len(node.ops) == len(node.comparators) == 1
                and isinstance(node.ops[0], ast.Eq) and isinstance(node.left, ast.Call)
                and isinstance(node.left.func, ast.Attribute)
                and isinstance(node.left.func.value, ast.Name) and node.left.func.value.id == 'value'
                and node.left.func.attr == 'get' and len(node.left.args) == 1
                and isinstance(node.left.args[0], ast.Constant)
                and node.left.args[0].value == 'packagedNativeHashes'):
            matches.append(node)
    need(len(matches) == 1 and ast.literal_eval(matches[0].comparators[0]) == old_native_hashes,
         'STARTUP_NATIVE_PREDICATE_CHANGED')
    before = ("and value.get('packagedNativeHashes') == "
              + repr(dict(sorted(old_native_hashes.items())))).encode('ascii')
    after = ("and value.get('packagedNativeHashes') == "
             + repr(dict(sorted(native_hashes.items())))).encode('ascii')
    # The byte binding and the AST comparison are both required. A second
    # occurrence, a different operator or a rewritten predicate fails closed.
    function = ast.get_source_segment(text, functions[0]).encode('utf-8')
    need(function.count(before) == raw.count(before) == 1, 'STARTUP_PROOF_LITERAL_CHANGED')
    result = rebind_seed(raw.replace(before, after), old_seed, seed)
    old_assignment = ("PREVIEW31_SEED = '" + old_seed + "'").encode('ascii')
    new_assignment = ("PREVIEW31_SEED = '" + seed + "'").encode('ascii')
    need(result.replace(after, before).replace(new_assignment, old_assignment) == raw,
         'WORKER_REBIND_CHANGED_OTHER_BYTES')
    compile(result, '<performance-native-worker>', 'exec')
    return result


def native(payloads, *, seed, packaged_native_hashes):
    """Accept only all seven publicly pinned current-runtime files.

    The installed-byte reader must run under the original held locks. An input
    not matching the current cohort is never normalized or accepted as another
    predecessor. The returned bytes need the caller's original runtime, program,
    source and acceptance bindings before any effect is allowed.
    """
    _seed(seed)
    need(seed != CURRENT_SEED, 'NEW_SEED_REQUIRED')
    _change_scope(CURRENT_NATIVE_HASHES, packaged_native_hashes)
    need(isinstance(payloads, dict) and set(payloads) == set(CURRENT_PINS),
         'CURRENT_PAYLOAD_ROSTER_CHANGED')
    for name, raw in payloads.items():
        pin = CURRENT_PINS[name]
        need(isinstance(raw, bytes) and (blob(raw), len(raw), sha(raw))
             == (pin['blob'], pin['size'], pin['sha256']), 'CURRENT_PAYLOAD_BYTES_CHANGED')
    result = copy.deepcopy(payloads)
    result['go_erp_live_updates.py'] = rebind_worker(payloads['go_erp_live_updates.py'],
        CURRENT_SEED, seed, CURRENT_NATIVE_HASHES, packaged_native_hashes)
    result['go_erp_live_source.py'] = rebind_seed(payloads['go_erp_live_source.py'], CURRENT_SEED, seed)
    for name, raw in result.items():
        if name.endswith('.py'):
            compile(raw, name, 'exec')
    return result


def after_original_seed_rebind(payloads, *, seed, packaged_native_hashes):
    """Adapter for the frozen Context's derive-then-transform callback.

    Its original derivation has already replaced the seed, while all native
    proof hashes still belong to the current cohort. Reverse only that exact
    assignment, reprove all seven original byte bindings, then derive the final
    bytes. This does not normalize or accept a foreign predecessor.
    """
    need(isinstance(payloads, dict) and set(payloads) == set(CURRENT_PINS),
         'CURRENT_PAYLOAD_ROSTER_CHANGED')
    restored = copy.deepcopy(payloads)
    for name in ('go_erp_live_updates.py', 'go_erp_live_source.py'):
        restored[name] = rebind_seed(restored[name], seed, CURRENT_SEED)
    return native(restored, seed=seed, packaged_native_hashes=packaged_native_hashes)
