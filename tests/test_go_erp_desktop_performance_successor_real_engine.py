"""Fresh hold WAL regression against the unchanged original filesystem engine."""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import stat
import tempfile
from types import SimpleNamespace
import unittest

BASE = Path(__file__).resolve().parents[1]


def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    value = importlib.util.module_from_spec(spec); spec.loader.exec_module(value)
    return value


fixtures = load('successor_real_engine_test_fixtures',
                BASE / 'tests/test_go_erp_desktop_performance_successor.py')
r = fixtures.r
ENGINE_PATH = BASE / 'tests/fixtures/go_erp_desktop_performance/apply_go_erp_manual_updates_root.py'
if hashlib.sha256(ENGINE_PATH.read_bytes()).hexdigest() != \
        '0a6da17799e88b45061442f81c730e32cdb1822ae68b2bd2f659b14d9f0610d9':
    raise AssertionError('Original frozen file engine fixture changed')
engine = load('successor_original_file_engine', ENGINE_PATH)


class MappedFilesystem:
    """Map production-shaped paths into an isolated directory, keeping reads real."""
    def __init__(self, root): self.root = Path(root); self.mutations = []

    def mapped(self, path): return self.root.joinpath(*Path(path).parts[1:])

    def read_file(self, path, limit, **kwargs):
        return engine.read_file(self.mapped(path), limit, uid=os.getuid(),
            modes=kwargs.get('modes'), optional=kwargs.get('optional', False))

    def atomic_write(self, path, raw, *_args):
        engine.atomic_write(self.mapped(path), raw, os.getuid(), os.getgid(), 0o600)
        self.mutations.append(Path(path))

    canonical = staticmethod(engine.canonical)

    def directory(self, path, _uid, _gid, **_kwargs):
        info = self.mapped(path).lstat()
        if not stat.S_ISDIR(info.st_mode) or stat.S_IMODE(info.st_mode) != 0o700:
            raise RuntimeError('isolated audit directory unsafe')

    def private_directory(self, path, verify):
        verify(); self.mapped(path).mkdir(mode=0o700, exist_ok=True)
        self.directory(path, 0, 0, private=True)

    def private_record(self, path, value, verify):
        verify(); raw = value if isinstance(value, bytes) else r.canonical(value)
        target = self.mapped(path)
        if target.exists():
            if target.read_bytes() != raw: raise RuntimeError('immutable record changed')
            return
        with target.open('xb') as stream: stream.write(raw)
        target.chmod(0o600)


class OriginalEngineFreshWAL(unittest.TestCase):
    def test_optional_original_read_does_not_hide_a_missing_ancestor(self):
        with tempfile.TemporaryDirectory(prefix='go-erp-real-reader-') as temporary:
            missing = Path(temporary) / 'audit-not-created' / 'intent.json'
            with self.assertRaises(FileNotFoundError):
                engine.read_file(missing, optional=True)
            missing.parent.mkdir(mode=0o700)
            self.assertIsNone(engine.read_file(missing, optional=True))

    def test_new_audit_directory_is_probed_before_original_optional_read(self):
        pins, _job, state = fixtures.fixture()
        audit = Path('/var/lib/go-erp-manual-updates/private/audits') / ('desktop-audit-hold-' + pins['core'])
        with tempfile.TemporaryDirectory(prefix='go-erp-real-hold-wal-') as temporary:
            io = MappedFilesystem(temporary)
            io.mapped(audit.parent).mkdir(parents=True, mode=0o700)
            io.mapped(r.WORK).mkdir(parents=True, mode=0o700)
            pending = b'{"synthetic":"retained original published60 receipt"}\n'
            for path, raw in [(r.HOLD, engine.canonical(r._hold(pins['old_expected']))),
                              (r.PENDING, pending)]:
                target = io.mapped(path); target.write_bytes(raw); target.chmod(0o600)
            context = SimpleNamespace(e=io, base=SimpleNamespace(parse=json.loads),
                                      state=lambda: state, ready=lambda _state: False)
            self.assertFalse(io.mapped(audit).exists())
            self.assertTrue(r.replace_hold(context, io, audit, lambda: True,
                                          lambda _stage: True, pins, pending))
            self.assertEqual(io.mutations, [r.HOLD])
            self.assertEqual(json.loads(io.mapped(r.HOLD).read_bytes()), r._hold(pins['new_expected']))
            self.assertFalse(r.replace_hold(context, io, audit, lambda: True,
                                           lambda _stage: True, pins, pending))
            self.assertEqual(io.mutations, [r.HOLD])

    def test_rejected_old_hold_does_not_create_the_new_audit_directory(self):
        pins, _job, state = fixtures.fixture()
        audit = Path('/var/lib/go-erp-manual-updates/private/audits') / ('desktop-audit-hold-' + pins['core'])
        with tempfile.TemporaryDirectory(prefix='go-erp-real-hold-refused-') as temporary:
            io = MappedFilesystem(temporary)
            io.mapped(audit.parent).mkdir(parents=True, mode=0o700)
            io.mapped(r.WORK).mkdir(parents=True, mode=0o700)
            target = io.mapped(r.HOLD)
            target.write_bytes(engine.canonical(r._hold(pins['old_expected'], {'foreign': 'authorization'})))
            target.chmod(0o600)
            context = SimpleNamespace(e=io, base=SimpleNamespace(parse=json.loads), state=lambda: state)
            with self.assertRaisesRegex(r.Stop, 'OLD_HOLD_AUTHORIZED_OR_CHANGED'):
                r.replace_hold(context, io, audit, lambda: True,
                               lambda _stage: True, pins, b'{"old":"receipt"}')
            self.assertFalse(io.mapped(audit).exists()); self.assertEqual(io.mutations, [])


if __name__ == '__main__': unittest.main()
