"""Private voice-spool contract tests; no model, credentials, network, or speech generation.

Run: python -m unittest discover -s tests/hologram_runtime -p 'test_voice_worker.py' -v
The bundled, pinned small recordings stand in for a worker's generated output.
"""
import contextlib
import fcntl
import hashlib
import io
import json
import os
from pathlib import Path
import stat
import sys
import tarfile
import tempfile
import time
import unittest
import wave
from unittest import mock


REPO = Path(__file__).resolve().parents[2]
SCRIPTS = REPO / "scripts/hologram_voice"
sys.path.insert(0, str(SCRIPTS))
import bootstrap_runtime as bootstrap
import worker


class VoiceWorkerTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="hologram-worker-test-")
        self.base = Path(self.temporary.name)
        os.chmod(self.base, 0o700)
        self.root = self.mkdir("spool")
        self.state = self.mkdir("state")
        for child in ("queue", "processing", "ready", "dead"):
            self.mkdir("spool/" + child)
        for child in ("attempts", "work"):
            self.mkdir("state/" + child)
        self.manifest = bootstrap.verify_assets(SCRIPTS)
        self.prepared = next(p for p in self.manifest["prepared"] if p["spoken_name"] == "Office Admin")
        self.runtime = {"scope": "central", "spool_root": str(self.root)}
        self.config = {"version": 1, "runtime_root": str(self.base / "not-installed-runtime"),
                       "assets_root": str(SCRIPTS), "state_root": str(self.state),
                       "capacity_receipt": str(self.base / "capacity.json"),
                       "ffmpeg_path": "/usr/bin/ffmpeg", "generation_enabled": False,
                       "threads": 2, "runtimes": [self.runtime]}

    def tearDown(self):
        self.temporary.cleanup()

    def mkdir(self, relative):
        path = self.base / relative
        path.mkdir(mode=0o700, parents=True, exist_ok=True)
        os.chmod(path, 0o700)
        return path

    def job(self, actor="11", display="Office Admin", spoken=None, scope="central"):
        spoken = display if spoken is None else spoken
        payload = ["go-hologram-voice", 1, scope, "admin", actor, display, spoken,
                   worker.VOICE, worker.TEMPLATE]
        key = hashlib.sha256(json.dumps(payload, ensure_ascii=False, separators=(",", ":")).encode()).hexdigest()
        return {"version": 1, "key": key, "scope": scope, "guard": "admin", "actor_id": actor,
                "display_name": display, "spoken_name": spoken, "voice_version": worker.VOICE,
                "template_version": worker.TEMPLATE, "created_at": int(time.time())}

    def queue(self, job, folder="queue"):
        path = self.root / folder / (job["key"] + ".json")
        bootstrap.atomic_json(path, job)
        return path

    def counts(self):
        return dict(processed=0, published=0, cached=0, paused=0, recovered=0, retried=0, dead=0)

    def process(self, manifest=None, limit=1, cache=None):
        counts = self.counts()
        worker.process_runtime(self.runtime, self.config, manifest or self.manifest, counts, limit,
                               {} if cache is None else cache)
        return counts

    def publish(self, job):
        worker.publish(self.root, job, SCRIPTS / self.prepared["audio"],
                       SCRIPTS / self.prepared["envelope"], self.prepared["duration_seconds"])
        return self.root / "ready" / job["key"]

    def test_job_identity_cannot_be_redirected_or_rewritten(self):
        original = self.job()
        self.assertEqual(worker.validate_job(original, "central", original["key"] + ".json"), original)
        changes = [{"version": True}, {"guard": "web"}, {"actor_id": 11}, {"actor_id": "0"},
                   {"actor_id": "../11"}, {"scope": "tenant:other"}, {"voice_version": "another-speaker"},
                   {"template_version": "arbitrary-text"}, {"display_name": " Office Admin"},
                   {"display_name": "<speak>Office</speak>"}, {"spoken_name": "أحمد"},
                   {"created_at": True}, {"created_at": int(time.time()) + 1000},
                   {"key": "0" * 64}, {"spoken_name": "Different Name"}]
        for change in changes:
            with self.subTest(change=change), self.assertRaises(worker.VoiceError):
                worker.validate_job(dict(original, **change), "central", original["key"] + ".json")
        with self.assertRaises(worker.VoiceError):
            worker.validate_job(original, "central", "../" + original["key"] + ".json")

    def test_config_rejects_unsafe_runtime_and_capacity_settings(self):
        bad = [{"runtimes": [{"scope": "../../other", "spool_root": str(self.root)}]},
               {"runtimes": [{"scope": "central", "spool_root": "/tmp/../etc"}]},
               {"runtimes": [self.runtime, self.runtime]}, {"runtimes": ["central"]},
               {"threads": True}, {"threads": 7}, {"min_available_memory_bytes": worker.MIN_MEMORY - 1},
               {"state_root": "relative-state"}]
        for index, change in enumerate(bad):
            path = self.base / ("config-" + str(index) + ".json")
            bootstrap.atomic_json(path, dict(self.config, **change))
            with self.subTest(change=change), self.assertRaises(worker.VoiceError):
                worker.load_config(path)

    def test_decomposed_latin_identity_keeps_its_producer_key(self):
        decomposed = self.job(display="Jose\u0301 Alvarez")
        composed = self.job(display="Jos\u00e9 Alvarez")
        self.assertNotEqual(decomposed["key"], composed["key"])
        for job in (decomposed, composed):
            self.assertEqual(worker.validate_job(job, "central", job["key"] + ".json"), job)

    def test_bounded_display_and_spoken_name_lengths_agree_with_the_producer(self):
        valid = self.job(display="A" * 150, spoken="B" * 450)
        self.assertEqual(worker.validate_job(valid, "central", valid["key"] + ".json"), valid)
        for display, spoken in [("A" * 151, "B"), ("A", "B" * 451)]:
            job = self.job(display=display, spoken=spoken)
            with self.subTest(display=len(display), spoken=len(spoken)), self.assertRaises(worker.VoiceError):
                worker.validate_job(job, "central", job["key"] + ".json")

    def test_generation_uses_one_literal_name_argument_and_keeps_approved_tail_pcm(self):
        job = self.job(display="Jose\u0301 O'Connor")
        worker.validate_job(job, "central", job["key"] + ".json")
        work = self.mkdir("fake-generation")
        commands = []
        head_pcm = b"\x00\x10" * 24000
        binary = self.base / "model-binary-never-executed"

        def fake_command(command, **unused):
            commands.append(command)
            if command[0] == str(binary):
                with wave.open(str(work / "raw.wav"), "wb") as stream:
                    stream.setparams((1, 2, 24000, 0, "NONE", "not compressed"))
                    stream.writeframes(head_pcm)
            elif str(work / "prefix.wav") == command[-1]:
                (work / "prefix.wav").write_bytes((work / "raw.wav").read_bytes())
            elif str(work / "audio.mp3") == command[-1]:
                (work / "audio.mp3").write_bytes((SCRIPTS / self.prepared["audio"]).read_bytes())
            else:
                self.fail("Unexpected external command")

        with mock.patch.object(worker, "run_command", side_effect=fake_command):
            audio, envelope, duration = worker.generate(job, self.config, self.manifest, work, binary)
        self.assertEqual(commands[0][commands[0].index("-p") + 1], "Welcome, Jos\u00e9 O'Connor.")
        self.assertEqual(job["display_name"], "Jose\u0301 O'Connor")
        self.assertIn(self.manifest["postprocessing"]["filter"], commands[1])
        self.assertNotIn("-af", commands[2])
        with wave.open(str(SCRIPTS / self.manifest["common_tail"])) as stream:
            tail_pcm = stream.readframes(stream.getnframes())
        with wave.open(str(work / "combined.wav")) as stream:
            self.assertEqual(stream.readframes(stream.getnframes()), head_pcm + tail_pcm)
        self.assertEqual(hashlib.sha256(tail_pcm).hexdigest(), self.manifest["common_tail_pcm_sha256"])
        worker.validate_envelope(bootstrap.read_json(envelope), duration)
        self.assertTrue(audio.exists())

    def test_incomplete_or_silent_generated_prefix_can_never_be_published(self):
        for label, seconds, amplitude, expected in [("cutoff", 28, 4096, "prefix_generation_limit"),
                                                     ("silent", 1, 0, "silent_prefix")]:
            work = self.mkdir("bad-generation-" + label)
            binary = self.base / "model-binary-never-executed"
            commands = []

            def fake_command(command, **unused):
                commands.append(command)
                if command[0] == str(binary):
                    with wave.open(str(work / "raw.wav"), "wb") as stream:
                        stream.setparams((1, 2, 24000, 0, "NONE", "not compressed"))
                        stream.writeframes(int(amplitude).to_bytes(2, "little", signed=True) * (seconds * 24000))
                elif command[-1] == str(work / "prefix.wav"):
                    (work / "prefix.wav").write_bytes((work / "raw.wav").read_bytes())
                else:
                    self.fail("Failed synthesis must stop before final encoding")

            with self.subTest(label=label), mock.patch.object(worker, "run_command", side_effect=fake_command), \
                    self.assertRaisesRegex(worker.VoiceError, expected):
                worker.generate(self.job(display="Novel Person"), self.config, self.manifest, work, binary)
            self.assertFalse((work / "audio.mp3").exists())
            self.assertFalse((work / "combined.wav").exists())

    def test_untrusted_file_types_and_permissions_are_not_descriptors(self):
        job = self.job()
        target = self.base / "outside.json"
        bootstrap.atomic_json(target, job)
        link = self.root / "queue" / (job["key"] + ".json")
        link.symlink_to(target)
        with self.assertRaises(worker.VoiceError):
            worker.descriptor(link)
        counts = self.process()
        self.assertEqual(counts["dead"], 1)
        self.assertFalse(link.exists())
        self.assertEqual(bootstrap.read_json(target), job)
        hardlink = self.root / "queue" / (job["key"] + ".json")
        os.link(target, hardlink)
        with self.assertRaises(worker.VoiceError):
            worker.descriptor(hardlink)
        hardlink.unlink()
        path = self.queue(job)
        path.chmod(0o644)
        with self.assertRaises(worker.VoiceError):
            worker.descriptor(path)

    def test_symlink_spool_or_ancestor_is_never_followed(self):
        link = self.base / "linked-spool"
        link.symlink_to(self.root, target_is_directory=True)
        with self.assertRaises(worker.VoiceError):
            worker.private_dir(link)
        with self.assertRaises(worker.VoiceError):
            worker.private_dir(link / "queue")
        self.root.chmod(0o755)
        with self.assertRaises(worker.VoiceError):
            worker.private_dir(self.root)

    def test_prepared_audio_publishes_without_loading_a_model_or_capacity(self):
        job = self.job()
        self.queue(job)
        with mock.patch.object(worker, "verify_runtime", side_effect=AssertionError("Model load forbidden")), \
                mock.patch.object(worker, "generate", side_effect=AssertionError("Generation forbidden")), \
                mock.patch.object(worker, "generation_allowed", side_effect=AssertionError("Prepared audio needs no model")):
            counts = self.process()
        self.assertEqual(counts["published"], 1)
        self.assertEqual(counts["processed"], 1)
        self.assertTrue(worker.checked_ready(self.root, job))
        ready = self.root / "ready" / job["key"]
        metadata = bootstrap.read_json(ready / "metadata.json")
        self.assertEqual(metadata["audio_sha256"], bootstrap.digest(SCRIPTS / self.prepared["audio"]))
        self.assertEqual(metadata["envelope_sha256"], bootstrap.digest(SCRIPTS / self.prepared["envelope"]))
        self.assertFalse((self.root / "queue" / (job["key"] + ".json")).exists())
        self.assertFalse((self.root / "processing" / (job["key"] + ".json")).exists())
        for file in ready.iterdir():
            self.assertEqual(stat.S_IMODE(file.stat().st_mode), 0o600)
        self.assertEqual(stat.S_IMODE(ready.stat().st_mode), 0o700)

    def test_publication_is_invisible_until_all_files_are_ready(self):
        job = self.job()
        destination = self.root / "ready" / job["key"]
        original = worker.os.rename
        seen = []

        def inspect_before_rename(source, target):
            self.assertFalse(destination.exists())
            self.assertEqual(Path(target), destination)
            self.assertEqual(set(p.name for p in Path(source).iterdir()), {"audio.mp3", "envelope.json", "metadata.json"})
            metadata = bootstrap.read_json(Path(source) / "metadata.json")
            self.assertEqual(metadata["audio_sha256"], bootstrap.digest(Path(source) / "audio.mp3"))
            self.assertEqual(metadata["envelope_sha256"], bootstrap.digest(Path(source) / "envelope.json"))
            seen.append(True)
            return original(source, target)

        with mock.patch.object(worker.os, "rename", side_effect=inspect_before_rename):
            self.publish(job)
        self.assertEqual(seen, [True])
        self.assertTrue(worker.checked_ready(self.root, job))
        self.assertEqual(list((self.root / "ready").glob(".publish-*")), [])

    def test_failed_publication_cleans_staging_and_never_exposes_partial_audio(self):
        job = self.job()
        with mock.patch.object(worker, "atomic_json", side_effect=OSError("fixture disk failure")), self.assertRaises(OSError):
            self.publish(job)
        self.assertFalse((self.root / "ready" / job["key"]).exists())
        self.assertEqual(list((self.root / "ready").iterdir()), [])

    def test_cached_assets_are_immutable_and_revalidated(self):
        job = self.job()
        ready = self.publish(job)
        baseline = (ready / "metadata.json").read_bytes()
        with self.assertRaises(OSError):
            self.publish(job)
        self.assertEqual((ready / "metadata.json").read_bytes(), baseline)
        self.queue(job)
        with mock.patch.object(worker, "generate", side_effect=AssertionError("Cached output must not regenerate")):
            counts = self.process()
        self.assertEqual(counts["cached"], 1)
        self.assertEqual(counts["published"], 0)
        with open(ready / "audio.mp3", "ab") as stream:
            stream.write(b"corruption")
        with self.assertRaises(worker.VoiceError):
            worker.checked_ready(self.root, job)

    def test_ready_identity_and_symlink_audio_cannot_be_substituted(self):
        job = self.job()
        ready = self.publish(job)
        metadata = bootstrap.read_json(ready / "metadata.json")
        bootstrap.atomic_json(ready / "metadata.json", dict(metadata, actor_id="12"))
        with self.assertRaises(worker.VoiceError):
            worker.checked_ready(self.root, job)
        bootstrap.atomic_json(ready / "metadata.json", metadata)
        audio = ready / "audio.mp3"
        audio.unlink()
        audio.symlink_to(SCRIPTS / self.prepared["audio"])
        with self.assertRaises(worker.VoiceError):
            worker.checked_ready(self.root, job)

    def test_invalid_amplitude_and_duration_are_refused(self):
        worker.validate_envelope({"sample_rate": 50, "values": [0.2] * 50}, 1)
        for envelope, duration in [({"sample_rate": 50, "values": [True] * 50}, 1),
                                   ({"sample_rate": 50, "values": [float("nan")] * 50}, 1),
                                   ({"sample_rate": 50, "values": [1.1] * 50}, 1),
                                   ({"sample_rate": 50, "values": ["0.2"] * 50}, 1),
                                   ({"sample_rate": 50, "values": {"0": .2}}, 1),
                                   ({"sample_rate": 25, "values": [.2] * 50}, 1),
                                   ({"sample_rate": 50, "values": [.2] * 50}, 3)]:
            with self.subTest(envelope=envelope), self.assertRaises(worker.VoiceError):
                worker.validate_envelope(envelope, duration)

    def test_capacity_pause_preserves_the_job_and_attempt_budget(self):
        job = self.job(display="Novel Person")
        path = self.queue(job)
        with mock.patch.object(worker, "verify_runtime", side_effect=AssertionError("Paused work must not load model")), \
                mock.patch.object(worker, "generate", side_effect=AssertionError("Paused work must not generate")):
            counts = self.process()
        self.assertEqual(counts["paused"], 1)
        self.assertEqual(counts["processed"], 0)
        self.assertEqual(bootstrap.read_json(path), job)
        self.assertFalse((self.state / "attempts" / path.name).exists())

    def test_generation_requires_fresh_receipt_and_actual_memory_headroom(self):
        self.config["generation_enabled"] = True
        receipt = {"safe": True, "checked_at": int(time.time()), "runtime_root": self.config["runtime_root"],
                   "model_manifest_sha256": bootstrap.MANIFEST_SHA256}
        bootstrap.atomic_json(self.config["capacity_receipt"], receipt)
        with mock.patch.object(worker, "available_memory", return_value=worker.MIN_MEMORY):
            self.assertTrue(worker.generation_allowed(self.config))
        with mock.patch.object(worker, "available_memory", return_value=worker.MIN_MEMORY - 1):
            self.assertFalse(worker.generation_allowed(self.config))
        for change in [{"safe": False}, {"checked_at": int(time.time()) - 86401},
                       {"checked_at": int(time.time()) + 301}, {"runtime_root": "/another/runtime"},
                       {"model_manifest_sha256": "0" * 64}]:
            bootstrap.atomic_json(self.config["capacity_receipt"], dict(receipt, **change))
            with self.subTest(change=change), mock.patch.object(worker, "available_memory", return_value=100 * worker.MIN_MEMORY):
                self.assertFalse(worker.generation_allowed(self.config))
        bootstrap.atomic_json(self.config["capacity_receipt"], receipt)
        Path(self.config["capacity_receipt"]).chmod(0o666)
        with mock.patch.object(worker, "available_memory", return_value=100 * worker.MIN_MEMORY):
            self.assertFalse(worker.generation_allowed(self.config))

    def test_harmless_failed_and_timed_out_subprocesses_are_bounded(self):
        with self.assertRaisesRegex(worker.VoiceError, "generation_process_failed"):
            worker.run_command([sys.executable, "-c", "raise SystemExit(7)"], timeout=2)
        started = time.monotonic()
        with self.assertRaisesRegex(worker.VoiceError, "generation_timeout"):
            worker.run_command([sys.executable, "-c", "import time; time.sleep(10)"], timeout=.05)
        self.assertLess(time.monotonic() - started, 2)

    def test_second_capacity_check_requeues_without_spending_an_attempt(self):
        job = self.job(display="Novel Person")
        path = self.queue(job)
        with mock.patch.object(worker, "generation_allowed", side_effect=[True, False]), \
                mock.patch.object(worker, "verify_runtime", return_value=Path("/fake/model-binary")), \
                mock.patch.object(worker, "generate", side_effect=AssertionError("Headroom was revoked")):
            counts = self.process()
        self.assertEqual(counts["paused"], 1)
        self.assertTrue(path.exists())
        self.assertEqual(bootstrap.read_json(self.state / "attempts" / path.name)["attempts"], 0)

    def test_retries_have_backoff_and_stop_at_the_bound(self):
        job = self.job(display="Novel Person")
        path = self.queue(job)
        now = float(job["created_at"])
        with mock.patch.object(worker, "generation_allowed", return_value=True), \
                mock.patch.object(worker, "verify_runtime", return_value=Path("/fake/model-binary")), \
                mock.patch.object(worker, "generate", side_effect=worker.VoiceError("generation_process_failed")) as generate:
            for attempt in range(1, worker.MAX_ATTEMPTS + 1):
                with mock.patch.object(worker.time, "time", return_value=now):
                    counts = self.process()
                    self.assertEqual(counts["processed"], 1)
                    self.assertEqual(generate.call_count, attempt)
                    if attempt < worker.MAX_ATTEMPTS:
                        self.assertEqual(counts["retried"], 1)
                        before = generate.call_count
                        self.assertEqual(self.process()["processed"], 0)
                        self.assertEqual(generate.call_count, before)
                    else:
                        self.assertEqual(counts["dead"], 1)
                now += 60 * attempt + 1
        self.assertFalse(path.exists())
        self.assertFalse((self.root / "processing" / path.name).exists())
        self.assertEqual(bootstrap.read_json(self.root / "dead" / path.name)["reason"], "generation_process_failed")
        self.assertEqual(list((self.state / "work").iterdir()), [])

    def test_only_expired_processing_leases_are_recovered(self):
        stale = self.job(actor="20", display="Novel Person")
        live = self.job(actor="21", display="Another Person")
        old = self.queue(stale, "processing")
        current = self.queue(live, "processing")
        os.utime(old, (time.time() - worker.STALE_SECONDS - 1,) * 2)
        counts = self.process(limit=10)
        self.assertEqual(counts["recovered"], 1)
        self.assertEqual(counts["paused"], 1)
        self.assertTrue((self.root / "queue" / old.name).exists())
        self.assertTrue(current.exists())
        self.assertFalse((self.root / "queue" / current.name).exists())

    def test_cached_processing_lease_is_cleaned_without_republication(self):
        job = self.job()
        ready = self.publish(job)
        metadata = (ready / "metadata.json").read_bytes()
        claimed = self.queue(job, "processing")
        counts = self.process()
        self.assertFalse(claimed.exists())
        self.assertEqual(counts["published"], 0)
        self.assertEqual((ready / "metadata.json").read_bytes(), metadata)

    def test_expired_exhausted_processing_job_goes_dead(self):
        job = self.job(display="Novel Person")
        claimed = self.queue(job, "processing")
        bootstrap.atomic_json(self.state / "attempts" / claimed.name, {"attempts": worker.MAX_ATTEMPTS, "next_attempt_at": 0})
        os.utime(claimed, (time.time() - worker.STALE_SECONDS - 1,) * 2)
        counts = self.process()
        self.assertEqual(counts["recovered"], 1)
        self.assertFalse(claimed.exists())
        self.assertEqual(bootstrap.read_json(self.root / "dead" / claimed.name)["reason"], "retry_exhausted")

    def test_invocation_limit_stops_after_one_completed_job(self):
        for actor in ("30", "31"):
            self.queue(self.job(actor=actor))
        counts = self.process(limit=1)
        self.assertEqual(counts["processed"], 1)
        self.assertEqual(counts["published"], 1)
        self.assertEqual(len(list((self.root / "queue").iterdir())), 1)

    def test_global_worker_lock_prevents_another_runtime_from_being_processed(self):
        job = self.job()
        self.queue(job)
        config = self.base / "worker.json"
        bootstrap.atomic_json(config, self.config)
        fd = os.open(self.state / "worker.lock", os.O_CREAT | os.O_RDWR, 0o600)
        try:
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
            output = io.StringIO()
            with contextlib.redirect_stdout(output), mock.patch.object(sys, "argv", ["worker.py", "--config", str(config), "--once"]):
                self.assertEqual(worker.main(), 0)
            self.assertEqual(json.loads(output.getvalue()), {"ok": True, "busy": True})
            self.assertTrue((self.root / "queue" / (job["key"] + ".json")).exists())
            self.assertEqual(list((self.root / "ready").iterdir()), [])
        finally:
            os.close(fd)


class RuntimeProvenanceTests(unittest.TestCase):
    def test_installed_record_cannot_reauthorize_a_modified_binary(self):
        with tempfile.TemporaryDirectory(prefix="hologram-runtime-test-") as temporary:
            root = Path(temporary)
            for sub in ("downloads", "models", "bin/llama-b12032"):
                (root / sub).mkdir(parents=True, exist_ok=True)
            archive = root / "downloads/runtime.tar.gz"
            binary = b"approved executable fixture"
            with tarfile.open(archive, "w:gz") as tar:
                info = tarfile.TarInfo("llama-b12032/llama-tts")
                info.size = len(binary)
                tar.addfile(info, io.BytesIO(binary))
            model = root / "models/model.gguf"
            model.write_bytes(b"small pinned model fixture")
            executable = root / "bin/llama-b12032/llama-tts"
            executable.write_bytes(binary)
            manifest = {"downloads": [{"name": "model.gguf", "size": model.stat().st_size, "sha256": bootstrap.digest(model)},
                                      {"name": "runtime.tar.gz", "size": archive.stat().st_size, "sha256": bootstrap.digest(archive)}]}

            def installed():
                bootstrap.atomic_json(root / "installed.json", {"version": 1, "manifest_sha256": bootstrap.MANIFEST_SHA256,
                    "files": [{"path": str(p.relative_to(root)), "size": p.stat().st_size, "sha256": bootstrap.digest(p)} for p in (model, executable)]})

            installed()
            with mock.patch.object(bootstrap, "load_manifest", return_value=manifest):
                self.assertEqual(bootstrap.verify_runtime(root), executable)
                executable.write_bytes(b"substituted executable")
                installed()  # A self-consistent local record is not proof of pinned provenance.
                with self.assertRaisesRegex(bootstrap.VoiceError, "provenance"):
                    bootstrap.verify_runtime(root)

    def test_archive_library_alias_cannot_escape_its_directory(self):
        with tempfile.TemporaryDirectory(prefix="hologram-archive-test-") as temporary:
            archive = Path(temporary) / "bad.tar.gz"
            with tarfile.open(archive, "w:gz") as tar:
                link = tarfile.TarInfo("llama-b12032/libescape.so")
                link.type = tarfile.SYMTYPE
                link.linkname = "/etc/passwd"
                tar.addfile(link)
            with self.assertRaisesRegex(bootstrap.VoiceError, "unsafe_archive_link"):
                bootstrap.archive_contents(archive)


if __name__ == "__main__":
    unittest.main(verbosity=2)
