"""Private Arabic helper behavior, real engine integration when explicitly supplied."""
import importlib.util
import io
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest
import wave

HELPER = Path(__file__).resolve().parents[2] / "scripts/hologram_arabic/synthesize.py"
SPEC = importlib.util.spec_from_file_location("arabic_helper", HELPER)
SPEECH = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(SPEECH)


class ArabicHelperTests(unittest.TestCase):
    def test_numeric_grouping_preserves_signed_decimal_values(self):
        cases = {
            "العملاء: 50,803 عميل.": "العملاء: 50803 عميل.",
            "رصيد الفروع: -39,421.05 ج.م": "رصيد الفروع: سالب 39421.05 جنيه مصري",
            "١٢٬٣٤٥٫٦٧ ج.م": "12345.67 جنيه مصري",
            "+1,234,567.00": "موجب 1234567.00",
            "١٫٠٥": "1.05",
            "سالب -1.05 جنيه.": "سالب 1.05 جنيه.",
            "الرصيد −١٫٠٥": "الرصيد سالب 1.05",
            "أمر، قسم، 1,2,3": "أمر، قسم، 1,2,3",
            "1,234,5": "1,234,5",
        }
        for original, expected in cases.items():
            with self.subTest(original=original):
                self.assertEqual(SPEECH.spoken_text(original), expected)

    def test_invalid_or_oversized_text_is_refused_before_loading_engine(self):
        for text in [None, "", "ع" * 401, "<speak>مرحبا</speak>", "[[phonemes]]", "مرحبا\0"]:
            with self.subTest(text=text):
                with self.assertRaisesRegex(SPEECH.SpeechError, "invalid_text"):
                    SPEECH.synthesize(text, Path("/nonexistent-engine"))

    def test_cli_does_not_accept_model_paths_or_flags(self):
        result = subprocess.run([os.sys.executable, "-I", "-B", str(HELPER), "--model", "/tmp/model"],
                                input=b"private reply", capture_output=True, timeout=10)
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(result.stdout, b"")
        self.assertNotIn(b"private reply", result.stderr)

    @unittest.skipUnless(os.environ.get("GO_ARABIC_ENGINE_DIR"), "real pinned engine is supplied by CI")
    def test_actual_isolated_arabic_process_outputs_audible_bounded_wave(self):
        engine = Path(os.environ["GO_ARABIC_ENGINE_DIR"])
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            shutil.copy2(HELPER, root / "synthesize.py")
            shutil.copytree(engine, root / "engine")
            result = subprocess.run([os.sys.executable, "-I", "-B", str(root / "synthesize.py")],
                                    input="العملاء: 50,803 عميل. رصيد الفروع -39,421.05 ج.م".encode(),
                                    capture_output=True, timeout=9)
            self.assertEqual(result.returncode, 0, result.stderr.decode())
            self.assertLessEqual(len(result.stdout), 2097152)
            with wave.open(io.BytesIO(result.stdout)) as stream:
                self.assertEqual((stream.getnchannels(), stream.getsampwidth(), stream.getframerate()), (1, 2, 22050))
                self.assertGreater(stream.getnframes(), 2205)
                self.assertLessEqual(stream.getnframes(), 30 * 22050)
                self.assertTrue(any(stream.readframes(stream.getnframes())))
            self.assertEqual(result.stderr, b"")


if __name__ == "__main__":
    unittest.main()
