#!/usr/bin/env python3
"""Install/check only pinned GO voice assets; never synthesize or discover tenants."""
import argparse
import fcntl
import hashlib
import json
import os
from pathlib import Path
import platform
import shutil
import stat
import subprocess
import tarfile
import tempfile
import urllib.request

MANIFEST_SHA256 = "8675b9323563709b3b9b650838e834d9f1f3b5ff46eeb770b1cb1f47a53a3969"
HERE = Path(__file__).resolve().parent
MAX_JSON = 262144


class VoiceError(Exception):
    pass


def digest(path):
    h = hashlib.sha256()
    with open(path, "rb") as stream:
        for chunk in iter(lambda: stream.read(1048576), b""):
            h.update(chunk)
    return h.hexdigest()


def directory(path, create=False):
    """Open every absolute component without following symbolic links."""
    p = Path(path)
    if not p.is_absolute() or ".." in p.parts:
        raise VoiceError("unsafe_directory")
    fd = os.open("/", os.O_RDONLY | os.O_DIRECTORY)
    try:
        for index, part in enumerate(p.parts[1:]):
            if create and index == len(p.parts[1:]) - 1:
                try:
                    os.mkdir(part, 0o700, dir_fd=fd)
                except FileExistsError:
                    pass
            next_fd = os.open(part, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=fd)
            os.close(fd)
            fd = next_fd
        return p
    except OSError as error:
        raise VoiceError("unsafe_directory") from error
    finally:
        os.close(fd)


def regular(path, maximum=None, trusted=False):
    p = Path(path)
    directory(p.parent)
    try:
        info = p.lstat()
    except OSError as error:
        raise VoiceError("missing_file") from error
    if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1:
        raise VoiceError("unsafe_file")
    if maximum is not None and info.st_size > maximum:
        raise VoiceError("oversize_file")
    if trusted and (info.st_mode & 0o022 or info.st_uid not in {0, os.geteuid()}):
        raise VoiceError("untrusted_file")
    return info


def read_json(path, maximum=MAX_JSON, trusted=False):
    regular(path, maximum, trusted)
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    try:
        data = os.read(fd, maximum + 1)
    finally:
        os.close(fd)
    if len(data) > maximum:
        raise VoiceError("oversize_json")
    try:
        return json.loads(data.decode("utf-8"))
    except (ValueError, UnicodeError) as error:
        raise VoiceError("invalid_json") from error


def atomic_bytes(path, data, mode=0o600):
    p = Path(path)
    directory(p.parent)
    fd, temporary = tempfile.mkstemp(prefix=".voice-", dir=p.parent)
    try:
        os.fchmod(fd, mode)
        with os.fdopen(fd, "wb") as stream:
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(temporary, p)
        dfd = os.open(p.parent, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        try:
            os.fsync(dfd)
        finally:
            os.close(dfd)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def atomic_json(path, data):
    atomic_bytes(path, (json.dumps(data, ensure_ascii=False, separators=(",", ":"), allow_nan=False) + "\n").encode())


def load_manifest(root):
    root = directory(root)
    path = root / "assets.json"
    regular(path, MAX_JSON, True)
    if digest(path) != MANIFEST_SHA256:
        raise VoiceError("manifest_hash_mismatch")
    return read_json(path)


def verify_assets(root):
    root = directory(root)
    manifest = load_manifest(root)
    for item in manifest["local_assets"]:
        path = root / item["path"]
        info = regular(path, item["size"], True)
        if info.st_size != item["size"] or digest(path) != item["sha256"]:
            raise VoiceError("asset_hash_mismatch")
    return manifest


def verify_runtime(root):
    root = directory(root)
    record = read_json(root / "installed.json", trusted=True)
    if not isinstance(record, dict) or record.get("manifest_sha256") != MANIFEST_SHA256 or type(record.get("version")) is not int or record.get("version") != 1:
        raise VoiceError("runtime_manifest_mismatch")
    source = load_manifest(HERE)
    required = {"models/" + a["name"]: a for a in source["downloads"] if a["name"].endswith(".gguf")}
    archive = next(a for a in source["downloads"] if a["name"].endswith(".tar.gz"))
    archive_path = root / "downloads" / archive["name"]
    regular(archive_path, archive["size"], True)
    if archive_path.stat().st_size != archive["size"] or digest(archive_path) != archive["sha256"]:
        raise VoiceError("binary_archive_hash_mismatch")
    binaries = archive_contents(archive_path)
    required.update({"bin/llama-b12032/" + name: dict(size=len(data), sha256=hashlib.sha256(data).hexdigest())
                     for name, data in binaries.items()})
    entries = record.get("files", [])
    if not isinstance(entries, list) or not 1 <= len(entries) <= 100:
        raise VoiceError("invalid_runtime_entries")
    seen = set()
    for item in entries:
        if not isinstance(item, dict) or not isinstance(item.get("path"), str) or type(item.get("size")) is not int or not isinstance(item.get("sha256"), str):
            raise VoiceError("invalid_runtime_entry")
        relative = Path(item.get("path", ""))
        if relative.is_absolute() or ".." in relative.parts or len(relative.parts) < 2:
            raise VoiceError("unsafe_runtime_entry")
        path = root / relative
        info = regular(path, item["size"], True)
        if info.st_size != item["size"] or digest(path) != item["sha256"]:
            raise VoiceError("runtime_hash_mismatch")
        if str(relative) in seen:
            raise VoiceError("duplicate_runtime_entry")
        seen.add(str(relative))
        if str(relative) not in required or item != dict(path=str(relative), size=required[str(relative)]["size"], sha256=required[str(relative)]["sha256"]):
            raise VoiceError("runtime_provenance_mismatch")
    if not set(required).issubset(seen) or "bin/llama-b12032/llama-tts" not in seen:
        raise VoiceError("runtime_incomplete")
    return root / "bin/llama-b12032/llama-tts"


def archive_contents(path):
    result = {}
    with tarfile.open(path, "r:gz") as tar:
        members = {m.name: m for m in tar.getmembers()}
        for member in members.values():
            name = Path(member.name)
            if len(name.parts) != 2 or name.parts[0] != "llama-b12032" or member.isdir():
                continue
            if name.name not in {"llama-tts", "LICENSE"} and not name.name.startswith("lib"):
                continue
            original = member
            for _ in range(4):
                if not original.issym():
                    break
                if "/" in original.linkname or ".." in original.linkname:
                    raise VoiceError("unsafe_archive_link")
                original = members["llama-b12032/" + original.linkname]
            if not original.isfile() or original.size > 16777216:
                raise VoiceError("unsafe_archive_entry")
            # Materialize known library aliases as regular files, never symlinks.
            result[name.name] = tar.extractfile(original).read()
    return result


def install_assets(destination):
    manifest = verify_assets(HERE)
    root = directory(destination, True)
    directory(root / "assets", True)
    for item in manifest["local_assets"]:
        atomic_bytes(root / item["path"], (HERE / item["path"]).read_bytes())
    atomic_bytes(root / "assets.json", (HERE / "assets.json").read_bytes())
    verify_assets(root)


def install_runtime(destination):
    if platform.system() != "Linux" or platform.machine() not in {"x86_64", "amd64"}:
        raise VoiceError("unsupported_platform")
    manifest = load_manifest(HERE)
    root = directory(destination, True)
    for part in ["downloads", "models", "bin"]:
        directory(root / part, True)
    lock_fd = os.open(root / ".install.lock", os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
    try:
        fcntl.flock(lock_fd, fcntl.LOCK_EX)
        for item in manifest["downloads"]:
            path = root / ("models" if item["name"].endswith(".gguf") else "downloads") / item["name"]
            if path.exists():
                regular(path, item["size"], True)
                if path.stat().st_size == item["size"] and digest(path) == item["sha256"]:
                    continue
                raise VoiceError("existing_download_mismatch")
            fd, temporary = tempfile.mkstemp(prefix=".download-", dir=path.parent)
            try:
                with os.fdopen(fd, "wb") as stream, urllib.request.urlopen(item["url"], timeout=60) as response:
                    total = 0
                    h = hashlib.sha256()
                    while True:
                        chunk = response.read(1048576)
                        if not chunk:
                            break
                        total += len(chunk)
                        if total > item["size"]:
                            raise VoiceError("download_oversize")
                        h.update(chunk)
                        stream.write(chunk)
                    stream.flush()
                    os.fsync(stream.fileno())
                if total != item["size"] or h.hexdigest() != item["sha256"]:
                    raise VoiceError("download_hash_mismatch")
                os.chmod(temporary, 0o600)
                os.replace(temporary, path)
            finally:
                if os.path.exists(temporary):
                    os.unlink(temporary)
        archive = next(a for a in manifest["downloads"] if a["name"].endswith(".tar.gz"))
        target = directory(root / "bin" / "llama-b12032", True)
        for name, data in archive_contents(root / "downloads" / archive["name"]).items():
            atomic_bytes(target / name, data, 0o700 if name == "llama-tts" else 0o600)
        files = []
        expected = [root / "models" / a["name"] for a in manifest["downloads"] if a["name"].endswith(".gguf")]
        expected += [target / name for name in archive_contents(root / "downloads" / archive["name"])]
        for path in sorted(expected):
            info = regular(path, trusted=True)
            files.append(dict(path=str(path.relative_to(root)), size=info.st_size, sha256=digest(path)))
        atomic_json(root / "installed.json", dict(version=1, manifest_sha256=MANIFEST_SHA256, files=files))
        verify_runtime(root)
    finally:
        os.close(lock_fd)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--runtime-root", type=Path)
    parser.add_argument("--assets-root", type=Path)
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument("--check", action="store_true")
    mode.add_argument("--install", action="store_true")
    mode.add_argument("--install-assets", action="store_true")
    args = parser.parse_args()
    os.umask(0o077)
    try:
        if args.install_assets:
            if not args.assets_root:
                raise VoiceError("assets_root_required")
            install_assets(args.assets_root)
        elif args.install:
            if not args.runtime_root:
                raise VoiceError("runtime_root_required")
            install_runtime(args.runtime_root)
        else:
            if args.assets_root:
                verify_assets(args.assets_root)
            if args.runtime_root:
                verify_runtime(args.runtime_root)
            if not args.runtime_root and not args.assets_root:
                raise VoiceError("root_required")
        print(json.dumps(dict(ok=True, manifest_sha256=MANIFEST_SHA256)))
        return 0
    except (VoiceError, OSError, ValueError, KeyError, tarfile.TarError) as error:
        reason = str(error) if isinstance(error, VoiceError) else "bootstrap_failed"
        print(json.dumps(dict(ok=False, reason=reason)))
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
