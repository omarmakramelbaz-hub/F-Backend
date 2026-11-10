#!/usr/bin/env python3
"""Offline Arabic speech from the private pinned espeak-ng library; no model or network."""
import ctypes
import io
import os
from pathlib import Path
import re
import resource
import signal
import stat
import sys
import wave

MAX_TEXT_BYTES = 1600
MAX_CHARACTERS = 400
SAMPLE_RATE = 22050
MAX_SAMPLES = SAMPLE_RATE * 30


class SpeechError(RuntimeError):
    pass


def spoken_text(text):
    """Remove grouping only inside well-formed numbers; keep signed decimals exact."""
    text = text.translate(str.maketrans("٠١٢٣٤٥٦٧٨٩۰۱۲۳۴۵۶۷۸۹", "01234567890123456789"))
    grouped = r"(?<![\d.,٬٫])([+-]?\d{1,3}(?:[,٬]\d{3})+(?:[.٫]\d+)?)(?![\d,٬])"
    text = re.sub(grouped, lambda match: match[1].replace(",", "").replace("٬", "").replace("٫", "."), text)
    text = re.sub(r"(?<=\d)٫(?=\d)", ".", text)
    # The Arabic engine otherwise ignores a leading minus as punctuation.
    def sign(match):
        word = "سالب" if match[1] in "-−" else "موجب"
        return "" if text[:match.start()].rstrip().endswith(word) else word + " "
    text = re.sub(r"(?<![\w.])([+−-])(?=\d)", sign, text)
    return re.sub(r"(?<!\w)ج\s*\.\s*م(?!\w)", "جنيه مصري", text)


def regular(path):
    info = path.lstat()
    if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1:
        raise SpeechError("invalid_engine_file")


def synthesize(text, engine):
    """Return one standard PCM WAV; callers own text authorization and process limits."""
    if not isinstance(text, str) or not 1 <= len(text) <= MAX_CHARACTERS or len(text.encode("utf-8")) > MAX_TEXT_BYTES:
        raise SpeechError("invalid_text")
    if re.search(r"[\x00-\x08\x0b\x0c\x0e-\x1f\x7f<>\[\]{}]", text):
        raise SpeechError("invalid_text")
    text = spoken_text(text)
    engine = Path(engine)
    if engine.is_symlink() or not engine.is_dir() or (engine / "espeak-ng-data").is_symlink():
        raise SpeechError("invalid_engine_directory")
    library = engine / "libespeak-ng.so.1"
    regular(library)
    regular(engine / "espeak-ng-data" / "ar_dict")
    espeak = ctypes.CDLL(str(library))
    espeak.espeak_Initialize.argtypes = [ctypes.c_int, ctypes.c_int, ctypes.c_char_p, ctypes.c_int]
    espeak.espeak_Initialize.restype = ctypes.c_int
    espeak.espeak_SetVoiceByName.argtypes = [ctypes.c_char_p]
    espeak.espeak_SetVoiceByName.restype = ctypes.c_int
    espeak.espeak_SetParameter.argtypes = [ctypes.c_int, ctypes.c_int, ctypes.c_int]
    espeak.espeak_SetParameter.restype = ctypes.c_int
    callback_type = ctypes.CFUNCTYPE(ctypes.c_int, ctypes.POINTER(ctypes.c_short), ctypes.c_int, ctypes.c_void_p)
    espeak.espeak_SetSynthCallback.argtypes = [callback_type]
    espeak.espeak_SetSynthCallback.restype = None
    espeak.espeak_Synth.argtypes = [ctypes.c_void_p, ctypes.c_size_t, ctypes.c_uint, ctypes.c_int,
                                  ctypes.c_uint, ctypes.c_uint, ctypes.POINTER(ctypes.c_uint), ctypes.c_void_p]
    espeak.espeak_Synth.restype = ctypes.c_int
    espeak.espeak_Terminate.argtypes = []
    espeak.espeak_Terminate.restype = ctypes.c_int
    pcm = bytearray()
    failed = [False]

    @callback_type
    def collect(samples, count, events):
        try:
            if count < 0 or count > MAX_SAMPLES or len(pcm) + count * 2 > MAX_SAMPLES * 2:
                failed[0] = True
                return 1
            if count and samples:
                pcm.extend(ctypes.string_at(samples, count * 2))
            return 0
        except Exception:
            failed[0] = True
            return 1

    # Synchronous callback output (2), UTF-8 (1), and DONT_EXIT (0x8000).
    sample_rate = espeak.espeak_Initialize(2, 0, os.fsencode(engine), 0x8000)
    if sample_rate != SAMPLE_RATE:
        raise SpeechError("engine_initialization_failed")
    try:
        if espeak.espeak_SetVoiceByName(b"ar") != 0:
            raise SpeechError("arabic_voice_unavailable")
        if espeak.espeak_SetParameter(1, 165, 0) != 0:
            raise SpeechError("engine_parameters_failed")
        espeak.espeak_SetSynthCallback(collect)
        raw = text.encode("utf-8") + b"\0"
        buffer = ctypes.create_string_buffer(raw)
        identifier = ctypes.c_uint(0)
        result = espeak.espeak_Synth(buffer, len(raw), 0, 1, 0, 1, ctypes.byref(identifier), None)
        if result != 0 or failed[0] or not SAMPLE_RATE // 10 * 2 <= len(pcm) <= MAX_SAMPLES * 2:
            raise SpeechError("speech_generation_failed")
        if not any(pcm):
            raise SpeechError("silent_speech")
        output = io.BytesIO()
        with wave.open(output, "wb") as stream:
            stream.setnchannels(1)
            stream.setsampwidth(2)
            stream.setframerate(SAMPLE_RATE)
            stream.writeframes(pcm)
        return output.getvalue()
    finally:
        espeak.espeak_Terminate()


def main():
    os.umask(0o077)
    resource.setrlimit(resource.RLIMIT_CORE, (0, 0))
    resource.setrlimit(resource.RLIMIT_CPU, (6, 7))
    resource.setrlimit(resource.RLIMIT_AS, (256 * 1024 ** 2, 256 * 1024 ** 2))
    resource.setrlimit(resource.RLIMIT_FSIZE, (2 * 1024 ** 2, 2 * 1024 ** 2))
    signal.alarm(7)
    try:
        if len(sys.argv) != 1:
            raise SpeechError("invalid_arguments")
        raw = sys.stdin.buffer.read(MAX_TEXT_BYTES + 1)
        if len(raw) > MAX_TEXT_BYTES:
            raise SpeechError("invalid_text")
        text = raw.decode("utf-8", "strict")
        result = synthesize(text, Path(__file__).resolve().parent / "engine")
        sys.stdout.buffer.write(result)
        sys.stdout.buffer.flush()
        return 0
    except (SpeechError, OSError, ValueError, UnicodeError, ctypes.ArgumentError):
        # Do not place a private spoken reply or filesystem detail into logs.
        sys.stderr.write("GO_ARABIC_SPEECH_UNAVAILABLE\n")
        return 1
    finally:
        signal.alarm(0)


if __name__ == "__main__":
    raise SystemExit(main())
