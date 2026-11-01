#!/usr/bin/env python3
"""Bounded private file-spool GO greeting worker. No HTTP or login synthesis."""
import argparse
import array
import fcntl
import hashlib
import json
import math
import os
from pathlib import Path
import re
import resource
import shutil
import signal
import stat
import subprocess
import tempfile
import time
import unicodedata
import wave
from itertools import islice

from bootstrap_runtime import (MANIFEST_SHA256, VoiceError, atomic_bytes, atomic_json,
                               digest, directory, read_json, regular, verify_assets,
                               verify_runtime)

VOICE = "go-approved-qwen-base-1.7b-q4-4283-atempo085-v1"
TEMPLATE = "welcome-name-approved-tail-v1"
IDENTITY = ("version", "key", "scope", "guard", "actor_id", "display_name", "spoken_name", "voice_version", "template_version")
MIN_MEMORY = 5 * 1024 ** 3
MAX_ATTEMPTS = 3
STALE_SECONDS = 900
HEX = re.compile(r"^[a-f0-9]{64}$")


def private_dir(path, create=False):
    path = directory(path, create)
    info = path.stat()
    if info.st_uid != os.geteuid() or stat.S_IMODE(info.st_mode) != 0o700:
        raise VoiceError("private_directory_permissions")
    return path


def normalize(value, maximum=150):
    if not isinstance(value, str) or len(value.encode("utf-8")) > 1024:
        raise VoiceError("invalid_name")
    # PHP only NFC-normalizes when intl is installed. Preserve the exact identity
    # string/key from either valid runtime; canonicalize whitespace/controls here.
    value = " ".join("".join(" " if unicodedata.category(c)[0] in "CZ" or c.isspace() else c
                              for c in value).split())
    if not 1 <= len(value) <= maximum or any(c in value for c in "<>{}[]"):
        raise VoiceError("invalid_name")
    return value


def validate_job(job, scope, filename):
    if not isinstance(job, dict) or type(job.get("version")) is not int or job.get("version") != 1 or job.get("scope") != scope or job.get("guard") != "admin":
        raise VoiceError("invalid_job_identity")
    if job.get("voice_version") != VOICE or job.get("template_version") != TEMPLATE:
        raise VoiceError("unsupported_voice_version")
    if not isinstance(job.get("actor_id"), str) or not re.fullmatch(r"[1-9][0-9]{0,19}", job["actor_id"]):
        raise VoiceError("invalid_actor")
    for field in ["display_name", "spoken_name"]:
        if normalize(job.get(field), 450 if field == "spoken_name" else 150) != job[field]:
            raise VoiceError("noncanonical_name")
    for c in job["spoken_name"]:
        if c in " .,&'’\u002d" or c.isdigit() or unicodedata.category(c)[0] == "M":
            continue
        if not (unicodedata.category(c)[0] == "L" and "LATIN" in unicodedata.name(c, "")):
            raise VoiceError("unreviewed_pronunciation")
    if not isinstance(job.get("created_at"), int) or isinstance(job["created_at"], bool) or job["created_at"] < 1 or job["created_at"] > time.time() + 300:
        raise VoiceError("invalid_job_timestamp")
    payload = ["go-hologram-voice", 1, scope, "admin", job["actor_id"], job["display_name"], job["spoken_name"], VOICE, TEMPLATE]
    key = hashlib.sha256(json.dumps(payload, ensure_ascii=False, separators=(",", ":")).encode()).hexdigest()
    if job.get("key") != key or filename != key + ".json":
        raise VoiceError("job_key_mismatch")
    return job


def load_config(path):
    config = read_json(path, 65536, True)
    if not isinstance(config, dict) or type(config.get("version")) is not int or config.get("version") != 1:
        raise VoiceError("invalid_config")
    for name in ["runtime_root", "assets_root", "state_root", "capacity_receipt", "ffmpeg_path"]:
        value = config.get(name)
        if not isinstance(value, str) or not Path(value).is_absolute() or ".." in Path(value).parts:
            raise VoiceError("invalid_config_path")
    if type(config.get("threads", 2)) is not int or not 1 <= config.get("threads", 2) <= 6:
        raise VoiceError("invalid_threads")
    minimum = config.get("min_available_memory_bytes", MIN_MEMORY)
    if type(minimum) is not int or minimum < MIN_MEMORY:
        raise VoiceError("invalid_memory_floor")
    scopes, roots = set(), set()
    runtimes = config.get("runtimes")
    if not isinstance(runtimes, list) or not 1 <= len(runtimes) <= 1000:
        raise VoiceError("invalid_runtime_list")
    for runtime in runtimes:
        if not isinstance(runtime, dict):
            raise VoiceError("invalid_runtime_entry")
        scope, root = runtime.get("scope"), runtime.get("spool_root")
        if not isinstance(scope, str) or not re.fullmatch(r"central|tenant:[A-Za-z0-9_-]{1,100}", scope):
            raise VoiceError("invalid_scope")
        if not isinstance(root, str) or not Path(root).is_absolute() or ".." in Path(root).parts or scope in scopes or root in roots:
            raise VoiceError("duplicate_or_unsafe_runtime")
        scopes.add(scope)
        roots.add(root)
    return config


def available_memory():
    try:
        available = next(int(line.split()[1]) * 1024 for line in Path("/proc/meminfo").read_text().splitlines() if line.startswith("MemAvailable:"))
    except (OSError, ValueError, StopIteration):
        return 0
    try:
        lines = Path("/proc/self/cgroup").read_text().splitlines()
    except OSError:
        return available
    try:
        group, v1_group = "/", None
        for line in lines:
            if line.startswith("0::"):
                group = line[3:]
            parts = line.split(":", 2)
            if len(parts) == 3 and "memory" in parts[1].split(","):
                v1_group = parts[2]
        base = Path("/sys/fs/cgroup") / group.lstrip("/")
        if not (base / "memory.max").is_file():
            base = Path("/sys/fs/cgroup")
        if (base / "memory.max").is_file():
            maximum = (base / "memory.max").read_text().strip()
            if maximum == "max":
                return available
            current = int((base / "memory.current").read_text())
            values = dict(line.split() for line in (base / "memory.stat").read_text().splitlines())
            cached = max(0, int(values.get("inactive_file", 0)) + int(values.get("active_file", 0))
                         - int(values.get("unevictable", 0)) - int(values.get("file_dirty", 0)) - int(values.get("file_writeback", 0)))
            available = min(available, max(0, int(maximum) - current + cached))
        elif v1_group is not None:
            base = Path("/sys/fs/cgroup/memory") / v1_group.lstrip("/")
            if not (base / "memory.limit_in_bytes").is_file():
                base = Path("/sys/fs/cgroup/memory")
            if (base / "memory.limit_in_bytes").is_file():
                maximum = int((base / "memory.limit_in_bytes").read_text())
                if maximum < 1 << 60:  # v1's huge sentinel means no controller cap.
                    current = int((base / "memory.usage_in_bytes").read_text())
                    values = dict(line.split() for line in (base / "memory.stat").read_text().splitlines())
                    cached = max(0, int(values.get("total_inactive_file", values.get("inactive_file", 0)))
                                 + int(values.get("total_active_file", values.get("active_file", 0)))
                                 - int(values.get("total_unevictable", values.get("unevictable", 0)))
                                 - int(values.get("total_dirty", values.get("dirty", 0)))
                                 - int(values.get("total_writeback", values.get("writeback", 0))))
                    available = min(available, max(0, maximum - current + cached))
        return available
    except (OSError, ValueError, TypeError):
        return 0


def generation_allowed(config):
    if config.get("generation_enabled") is not True:
        return False
    try:
        receipt = read_json(config["capacity_receipt"], 16384, True)
        if not isinstance(receipt, dict):
            return False
        checked = receipt.get("checked_at")
        if receipt.get("safe") is not True or receipt.get("runtime_root") != config["runtime_root"] or receipt.get("model_manifest_sha256") != MANIFEST_SHA256:
            return False
        if type(checked) is not int or not -300 <= time.time() - checked <= 86400:
            return False
        return available_memory() >= config.get("min_available_memory_bytes", MIN_MEMORY)
    except (VoiceError, OSError, ValueError, TypeError):
        return False


def checked_ready(root, job):
    path = root / "ready" / job["key"]
    if not path.exists() and not path.is_symlink():
        return False
    private_dir(path)
    metadata = read_json(path / "metadata.json", 16384)
    if not isinstance(metadata, dict):
        raise VoiceError("invalid_ready_metadata")
    if any(metadata.get(k) != job[k] for k in IDENTITY):
        raise VoiceError("ready_identity_mismatch")
    duration = metadata.get("duration_seconds")
    if type(duration) not in {int, float} or not math.isfinite(duration) or not 1 <= duration <= 120:
        raise VoiceError("ready_duration_invalid")
    for name, hash_field, maximum in [("audio.mp3", "audio_sha256", 8388608), ("envelope.json", "envelope_sha256", 262144)]:
        info = regular(path / name, maximum)
        if stat.S_IMODE(info.st_mode) != 0o600 or (name == "audio.mp3" and info.st_size < 1024) or digest(path / name) != metadata.get(hash_field):
            raise VoiceError("ready_hash_mismatch")
    validate_envelope(read_json(path / "envelope.json"), duration)
    return True


def validate_envelope(data, duration):
    if not isinstance(data, dict) or data.get("sample_rate") != 50 or not isinstance(data.get("values"), list):
        raise VoiceError("invalid_envelope")
    values = data["values"]
    if not 1 <= len(values) <= 6000 or abs(len(values) / 50 - duration) > .1:
        raise VoiceError("invalid_envelope_length")
    if any(type(x) not in {int, float} or not math.isfinite(x) or not 0 <= x <= 1 for x in values):
        raise VoiceError("invalid_envelope_value")


def run_command(command, timeout=240, library_dir=None):
    def low_priority():
        os.nice(10)
        resource.setrlimit(resource.RLIMIT_CORE, (0, 0))
        resource.setrlimit(resource.RLIMIT_FSIZE, (16777216, 16777216))
        resource.setrlimit(resource.RLIMIT_CPU, (180, 185))
    env = {k: v for k, v in os.environ.items() if k not in {"LD_PRELOAD", "LD_AUDIT", "LD_LIBRARY_PATH"}}
    if library_dir:
        env["LD_LIBRARY_PATH"] = str(library_dir)
    process = subprocess.Popen(command, stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL,
                               stderr=subprocess.DEVNULL, env=env, preexec_fn=low_priority,
                               start_new_session=True, close_fds=True)
    try:
        if process.wait(timeout=timeout) != 0:
            raise VoiceError("generation_process_failed")
    except subprocess.TimeoutExpired as error:
        os.killpg(process.pid, signal.SIGKILL)
        process.wait(timeout=5)
        raise VoiceError("generation_timeout") from error
    finally:
        if process.poll() is None:
            os.killpg(process.pid, signal.SIGKILL)
            process.wait(timeout=5)


def generate(job, config, manifest, work, runtime_binary):
    assets = Path(config["assets_root"])
    runtime = Path(config["runtime_root"])
    ffmpeg = Path(config["ffmpeg_path"])
    regular(ffmpeg, trusted=True)
    if not os.access(ffmpeg, os.X_OK):
        raise VoiceError("ffmpeg_unavailable")
    raw, prefix, combined = work / "raw.wav", work / "prefix.wav", work / "combined.wav"
    threads = str(config.get("threads", 2))
    command = [str(runtime_binary), "-m", str(runtime / "models/Qwen3-TTS-12Hz-1.7B-Base-Q4_K_M.gguf"),
               "-mm", str(runtime / "models/mmproj-Qwen3-TTS-12Hz-1.7B-Base-Q8_0.gguf"),
               "-p", "Welcome, " + unicodedata.normalize("NFC", job["spoken_name"]) + ".", "--tts-lang", "en", "--tts-speaker-file",
               str(assets / manifest["reference"]), "--output", str(raw), "-n", "350", "-t", threads,
               "-tb", threads, "-c", "1024", "-b", "256", "-ub", "128", "--temp", "0.7",
               "--top-p", "0.9", "--top-k", "50", "--repeat-penalty", "1.05", "--seed", "4283"]
    run_command(command, library_dir=runtime_binary.parent)
    regular(raw, 8388608)
    with wave.open(str(raw)) as stream:
        # This decoder writes 80ms per generated frame. Reaching the hard
        # 350-frame budget cannot prove name completion, so never publish it.
        if stream.getnframes() / stream.getframerate() >= 27.99:
            raise VoiceError("prefix_generation_limit")
    run_command([str(ffmpeg), "-y", "-hide_banner", "-loglevel", "error", "-i", str(raw), "-af",
                 manifest["postprocessing"]["filter"], "-ar", "24000", "-ac", "1", str(prefix)], timeout=30)
    with wave.open(str(prefix)) as stream:
        if stream.getnchannels() != 1 or stream.getsampwidth() != 2 or stream.getframerate() != 24000:
            raise VoiceError("invalid_prefix_audio")
        head = stream.readframes(stream.getnframes())
    prefix_samples = array.array("h", head)
    active = [i for i in range(0, len(prefix_samples), 240)
              if math.sqrt(sum(float(x) * x for x in prefix_samples[i:i + 240]) / len(prefix_samples[i:i + 240])) > 260]
    if not active:
        raise VoiceError("silent_prefix")
    # Exactly the reviewed prototype: retain 100ms after the last active 10ms
    # frame. The approved tail already contains the pause before "I am GO".
    head = prefix_samples[:min(len(prefix_samples), active[-1] + 240 + 2400)].tobytes()
    with wave.open(str(assets / manifest["common_tail"])) as stream:
        if stream.getparams()[:3] != (1, 2, 24000):
            raise VoiceError("invalid_common_tail")
        tail = stream.readframes(stream.getnframes())
    if hashlib.sha256(tail).hexdigest() != manifest["common_tail_pcm_sha256"]:
        raise VoiceError("common_tail_hash_mismatch")
    samples = array.array("h", head + tail)
    duration = len(samples) / 24000
    if not .1 <= len(head) / 48000 <= 40 or not 1 <= duration <= 120:
        raise VoiceError("generated_duration_invalid")
    with wave.open(str(combined), "wb") as stream:
        stream.setnchannels(1)
        stream.setsampwidth(2)
        stream.setframerate(24000)
        stream.writeframes(head + tail)
    # Tail PCM is copied unchanged; only the newly spoken prefix receives DSP.
    levels = [math.sqrt(sum(float(x) * x for x in samples[i:i + 480]) / len(samples[i:i + 480])) for i in range(0, len(samples), 480)]
    scale = max(1.0, sorted(levels)[int((len(levels) - 1) * .95)])
    env = {"sample_rate": 50, "values": [round(min(1.0, x / scale), 3) for x in levels], "duration": duration}
    run_command([str(ffmpeg), "-y", "-hide_banner", "-loglevel", "error", "-i", str(combined),
                 "-codec:a", "libmp3lame", "-b:a", "96k", str(work / "audio.mp3")], timeout=30)
    atomic_json(work / "envelope.json", env)
    return work / "audio.mp3", work / "envelope.json", duration


def publish(root, job, audio, envelope, duration):
    validate_envelope(read_json(envelope), duration)
    info = regular(audio, 8388608)
    if info.st_size < 1024:
        raise VoiceError("audio_too_small")
    ready = private_dir(root / "ready")
    temporary = Path(tempfile.mkdtemp(prefix=".publish-", dir=ready))
    os.chmod(temporary, 0o700)
    try:
        atomic_bytes(temporary / "audio.mp3", Path(audio).read_bytes())
        atomic_bytes(temporary / "envelope.json", Path(envelope).read_bytes())
        metadata = {k: job[k] for k in IDENTITY}
        metadata.update(audio_sha256=digest(temporary / "audio.mp3"), envelope_sha256=digest(temporary / "envelope.json"), duration_seconds=duration)
        atomic_json(temporary / "metadata.json", metadata)
        os.rename(temporary, ready / job["key"])
        dfd = os.open(ready, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        try:
            os.fsync(dfd)
        finally:
            os.close(dfd)
    finally:
        if temporary.exists():
            shutil.rmtree(temporary)


def descriptor(path):
    info = regular(path, 16384)
    if info.st_uid != os.geteuid() or stat.S_IMODE(info.st_mode) != 0o600:
        raise VoiceError("job_permissions")
    return read_json(path, 16384)


def marker(root, key, reason):
    atomic_json(root / "dead" / (key + ".json"), dict(version=1, key=key, reason=reason, failed_at=int(time.time())))


def attempt_state(state_root, key):
    path = state_root / "attempts" / (key + ".json")
    if not path.exists():
        return {"attempts": 0, "next_attempt_at": 0}
    value = read_json(path, 4096)
    if not isinstance(value, dict) or type(value.get("attempts")) is not int or not 0 <= value["attempts"] <= MAX_ATTEMPTS:
        raise VoiceError("invalid_attempt_state")
    if type(value.get("next_attempt_at")) is not int or not 0 <= value["next_attempt_at"] <= time.time() + 86400:
        raise VoiceError("invalid_attempt_state")
    return value


def process_runtime(runtime, config, manifest, counts, limit, runtime_cache):
    root = private_dir(runtime["spool_root"])
    for child in ["queue", "processing", "ready", "dead"]:
        private_dir(root / child, True)
    state = private_dir(config["state_root"])
    # A killed invocation leaves a descriptor; only expired leases are recovered.
    counts.setdefault("scanned", 0)
    for path in islice((root / "processing").iterdir(), 1000):
        if counts["scanned"] >= 1000:
            return
        counts["scanned"] += 1
        key = path.stem
        if not HEX.fullmatch(key) or path.suffix != ".json":
            continue
        try:
            job = validate_job(descriptor(path), runtime["scope"], path.name)
            if checked_ready(root, job):
                path.unlink()
            elif time.time() - path.stat().st_mtime > STALE_SECONDS:
                status = attempt_state(state, key)
                if status["attempts"] >= MAX_ATTEMPTS:
                    marker(root, key, "retry_exhausted")
                    path.unlink()
                elif not (root / "queue" / path.name).exists():
                    os.rename(path, root / "queue" / path.name)
                else:
                    path.unlink()
                counts["recovered"] += 1
        except (VoiceError, OSError, ValueError):
            marker(root, key, "invalid_processing_job")
            if path.is_file() or path.is_symlink():
                path.unlink()
    for path in islice((root / "queue").iterdir(), 1000):
        if counts["processed"] >= limit or counts["scanned"] >= 1000:
            break
        counts["scanned"] += 1
        key = path.stem
        if not HEX.fullmatch(key) or path.suffix != ".json":
            continue
        claimed = None
        try:
            job = validate_job(descriptor(path), runtime["scope"], path.name)
            if (root / "dead" / path.name).exists():
                path.unlink()
                counts["processed"] += 1
                continue
            if checked_ready(root, job):
                path.unlink()
                counts["cached"] += 1
                counts["processed"] += 1
                continue
            status = attempt_state(state, key)
            if status.get("next_attempt_at", 0) > time.time():
                continue
            prepared = next((x for x in manifest["prepared"] if x["spoken_name"] == job["spoken_name"]), None)
            if not prepared and not generation_allowed(config):
                counts["paused"] += 1
                continue
            if not prepared and "binary" not in runtime_cache:
                runtime_cache["binary"] = verify_runtime(config["runtime_root"])
            if (root / "processing" / path.name).exists():
                continue
            if status["attempts"] >= MAX_ATTEMPTS:
                raise VoiceError("retry_exhausted")
            claimed = root / "processing" / path.name
            os.rename(path, claimed)
            os.utime(claimed, None, follow_symlinks=False)
            status = {"attempts": status["attempts"] + 1, "next_attempt_at": 0}
            atomic_json(state / "attempts" / path.name, status)
            counts["processed"] += 1
            if prepared:
                assets = Path(config["assets_root"])
                publish(root, job, assets / prepared["audio"], assets / prepared["envelope"], prepared["duration_seconds"])
            else:
                # Hashing/loading runtime files took time: recheck live headroom.
                if not generation_allowed(config):
                    status["attempts"] -= 1
                    atomic_json(state / "attempts" / path.name, status)
                    os.rename(claimed, path)
                    claimed = None
                    counts["paused"] += 1
                    continue
                work = Path(tempfile.mkdtemp(prefix="job-", dir=state / "work"))
                try:
                    audio, env, duration = generate(job, config, manifest, work, runtime_cache["binary"])
                    publish(root, job, audio, env, duration)
                finally:
                    shutil.rmtree(work)
            claimed.unlink()
            claimed = None
            (state / "attempts" / path.name).unlink(missing_ok=True)
            counts["published"] += 1
        except (VoiceError, OSError, ValueError, wave.Error) as error:
            reason = str(error) if isinstance(error, VoiceError) else "job_failed"
            if claimed is not None and claimed.exists():
                status = attempt_state(state, key)
                if status["attempts"] < MAX_ATTEMPTS:
                    status["next_attempt_at"] = int(time.time()) + 60 * status["attempts"]
                    atomic_json(state / "attempts" / path.name, status)
                    if not path.exists():
                        os.rename(claimed, path)
                    else:
                        claimed.unlink()
                    counts["retried"] += 1
                    continue
            marker(root, key, reason)
            if claimed is None:
                counts["processed"] += 1
            target = claimed or path
            if target.is_file() or target.is_symlink():
                target.unlink()
            counts["dead"] += 1


def clean_expired_work(state, runtimes):
    # Only generated staging names in private, explicitly configured directories.
    # A crashed child has bounded CPU lifetime; its lease is longer than that.
    parents = [(state / "work", "job-")]
    parents += [(Path(r["spool_root"]) / "ready", ".publish-") for r in runtimes]
    examined = 0
    for parent, prefix in parents:
        if not parent.exists() and not parent.is_symlink():
            continue
        private_dir(parent)
        for path in islice(parent.iterdir(), 1000):
            examined += 1
            if examined > 1000:
                return
            if not path.name.startswith(prefix):
                continue
            info = path.lstat()
            if info.st_uid == os.geteuid() and stat.S_ISDIR(info.st_mode) and time.time() - info.st_mtime > STALE_SECONDS:
                shutil.rmtree(path)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--config", required=True, type=Path)
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument("--once", action="store_true")
    mode.add_argument("--max-jobs", type=int, default=1)
    parser.add_argument("--check", action="store_true")
    args = parser.parse_args()
    if not 1 <= args.max_jobs <= 10:
        parser.error("--max-jobs must be 1..10")
    os.umask(0o077)
    try:
        config = load_config(args.config)
        manifest = verify_assets(config["assets_root"])
        state = private_dir(config["state_root"])
        if args.check:
            print(json.dumps(dict(ok=True, generation_allowed=generation_allowed(config), manifest_sha256=MANIFEST_SHA256)))
            return 0
        for child in ["attempts", "work"]:
            private_dir(state / child, True)
        lock_fd = os.open(state / "worker.lock", os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
        try:
            try:
                fcntl.flock(lock_fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError:
                print(json.dumps(dict(ok=True, busy=True)))
                return 0
            counts = dict(processed=0, published=0, cached=0, paused=0, recovered=0, retried=0, dead=0)
            runtime_cache = {}
            # Round-robin trusted roots prevents a busy central queue starving
            # independent owner installations. No runtime is auto-discovered.
            cursor_path = state / "cursor.json"
            cursor_state = read_json(cursor_path, 4096) if cursor_path.exists() else {"next": 0}
            if not isinstance(cursor_state, dict):
                raise VoiceError("invalid_scope_cursor")
            cursor = cursor_state.get("next", 0)
            if type(cursor) is not int or cursor < 0:
                raise VoiceError("invalid_scope_cursor")
            for offset in range(len(config["runtimes"])):
                index = (cursor + offset) % len(config["runtimes"])
                runtime = config["runtimes"][index]
                process_runtime(runtime, config, manifest, counts, args.max_jobs, runtime_cache)
                atomic_json(cursor_path, {"next": (index + 1) % len(config["runtimes"])})
                if counts["processed"] >= args.max_jobs or counts.get("scanned", 0) >= 1000:
                    break
            clean_expired_work(state, config["runtimes"])
            print(json.dumps(dict(ok=True, **counts)))
            return 0
        finally:
            os.close(lock_fd)
    except (VoiceError, OSError, ValueError, KeyError, TypeError) as error:
        print(json.dumps(dict(ok=False, reason=str(error) if isinstance(error, VoiceError) else "worker_failed")))
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
