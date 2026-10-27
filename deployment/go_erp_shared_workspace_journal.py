#!/usr/bin/env python3
"""Root-private durable CAS journal adapter for the guarded coordinator.

The final loader must pin this module and the supplied source_io engine bytes.
Only a coordinator-owned private journal is writable; no source, database, native
counter, artifact, installer, feature flag or service is touched by this adapter.
"""
import os
from pathlib import Path
import re
import stat

MAX_CHECKPOINT = 2 * 1024**2
SHA = re.compile(r'[a-f0-9]{64}\Z')
FINAL_JOURNAL_PIN = None


class Stop(Exception):
    pass


def need(value, code):
    if not value:
        raise Stop('GO_ERP_SHARED_WORKSPACE_JOURNAL_' + code)


class PrivateJournal:
    """Durable commit(old_sha, next_state), with original locks revalidated.

    `source_io` is the independently hash-verified source engine, not request data.
    The current pointer and immutable checkpoints live in exact trusted root-owned
    0700 directories. Recovery loads the last atomic current checkpoint; orphaned
    immutable records never authorize an effect or overwrite an old reservation.
    """
    def __init__(self, current_path, record_directory, source_io, verify_locks, *, checkpoint=None):
        need(callable(verify_locks) and (checkpoint is None or callable(checkpoint)), 'LOCK_VERIFIER_REQUIRED')
        self.current, self.records = Path(current_path), Path(record_directory)
        self.io, self.verify_locks = source_io, verify_locks
        self.checkpoint = checkpoint or (lambda _phase: None)
        self.verify()
        self.io.directory(self.current.parent, 0, 0, private=True)
        self.io.directory(self.records.parent, 0, 0, private=True)
        need(self.current.is_absolute() and self.records.is_absolute()
            and self.current.parent.resolve() == self.current.parent
            and self.records.parent.resolve() == self.records.parent
            and self.current != self.records, 'PATH_INVALID')

    def verify(self):
        need(self.verify_locks() is not False, 'HELD_LOCK_NOT_VERIFIED')

    def _read(self):
        result = self.io.read(self.current, 0, 0, optional=True, private=True, limit=MAX_CHECKPOINT)
        if result is None:
            return None
        raw, info = result
        value = self.io.parse(raw)
        need(raw == self.io.canonical(value), 'CANONICAL_CHECKPOINT_REQUIRED')
        need(self.records.exists(), 'IMMUTABLE_CHECKPOINT_RECORD_MISSING')
        record = self.io.read(self.records / ('checkpoint-' + self.io.sha(raw) + '.json'),
            0, 0, optional=True, private=True, limit=MAX_CHECKPOINT)
        need(record is not None and record[0] == raw, 'IMMUTABLE_CHECKPOINT_RECORD_CHANGED')
        return raw, info, value

    def load(self):
        self.verify()
        current = self._read()
        self.verify()
        return None if current is None else current[2]

    def commit(self, old_sha, next_state):
        need(old_sha is None or isinstance(old_sha, str) and SHA.fullmatch(old_sha), 'EXPECTED_SHA_INVALID')
        need(isinstance(next_state, dict), 'CHECKPOINT_OBJECT_REQUIRED')
        raw = self.io.canonical(next_state)
        need(0 < len(raw) <= MAX_CHECKPOINT, 'CHECKPOINT_LIMIT')
        self.verify()
        previous = self._read()
        need((previous is None and old_sha is None) or previous is not None
            and self.io.sha(previous[0]) == old_sha, 'CAS_CONFLICT')
        digest = self.io.sha(raw)
        self.io.private_directory(self.records, self.verify)
        self.io.private_record(self.records / ('checkpoint-' + digest + '.json'), raw, self.verify)
        parent = self.io.parent_fd(self.current)
        stage = '.workspace-journal-' + os.urandom(16).hex()
        staged = None
        try:
            original_parent = os.fstat(parent)
            fd = os.open(stage, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600, dir_fd=parent)
            with os.fdopen(fd, 'wb') as stream:
                stream.write(raw)
                stream.flush()
                os.fchown(stream.fileno(), 0, 0)
                os.fchmod(stream.fileno(), 0o600)
                os.fsync(stream.fileno())
                staged = os.fstat(stream.fileno())
            self.checkpoint('written')
            self.verify()
            self.io.same_parent(self.current, original_parent)
            current = self._read()
            need((previous is None and current is None) or previous is not None and current is not None
                and previous[0] == current[0] and self.io.identity(previous[1]) == self.io.identity(current[1]),
                'CAS_CONFLICT')
            if current is None:
                self.io.rename_absent(parent, stage, self.current.name)
            else:
                os.replace(stage, self.current.name, src_dir_fd=parent, dst_dir_fd=parent)
            self.checkpoint('renamed')
            os.fsync(parent)
            self.verify()
            self.io.same_parent(self.current, original_parent)
            final = self._read()
            need(final is not None and final[0] == raw and self.io.inode(final[1]) == self.io.inode(staged),
                'COMMIT_VERIFY_FAILED')
            self.checkpoint('committed')
            return digest
        finally:
            try:
                try:
                    remaining = os.stat(stage, dir_fd=parent, follow_symlinks=False)
                except FileNotFoundError:
                    remaining = None
                if remaining is not None:
                    need(staged is not None and stat.S_ISREG(remaining.st_mode) and remaining.st_nlink == 1
                        and self.io.inode(remaining) == self.io.inode(staged), 'STAGE_NOT_OWNED')
                    os.unlink(stage, dir_fd=parent)
                    os.fsync(parent)
            finally:
                os.close(parent)
