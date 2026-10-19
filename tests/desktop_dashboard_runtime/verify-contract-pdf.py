"""Parse the retained, real Windows HTTP PDF; no parser is shipped in the application.

Usage: python verify-contract-pdf.py artifacts [expected-source-revision]
The artifact directory must contain contract_100.pdf and the completed HTTP receipt.
"""
import hashlib
import html
import json
import os
from pathlib import Path
import re
import sys
import unicodedata

import pypdf
from pypdf import PdfReader
from pypdf.generic import ArrayObject, DictionaryObject, IndirectObject

PINNED_WKHTMLTOPDF_SHA256 = "64d17682320bffd45b2208ed13b136d59139e82573745f14556cf25e95cbd808"
ARABIC_PHRASES = ["عقد تجريبي", "نص محفوظ"]
# Observed wkhtmltopdf0.12.6/Arial ToUnicode glyph aliases, not character aliases.
# Unicode17 §9.2.4/Table9-10 specifies identical initial/medial yeh glyphs:
# https://www.unicode.org/versions/Unicode17.0.0/core-spec/chapter-9/
# The UCD still decomposes FBFE/FBFF to FARSI YEH, unlike FEF3/FEF4:
# https://www.unicode.org/Public/17.0.0/ucd/UnicodeData.txt
PDF_YEH_GLYPHS = {0xFBFE: 0xFEF3, 0xFBFF: 0xFEF4}


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def normalize(text):
    # PDF text extractors can return RTL runs in logical or visual order.
    # Normalize presentation-form glyphs and bidi marks, never the actual words.
    text = unicodedata.normalize("NFKC", text)
    text = " ".join("".join(c for c in text if unicodedata.category(c) != "Cf").split())
    # Qt may place a bracket and its Latin RTL token in separate PDF text runs.
    # Join only whitespace at the delimiters; preserve the token bytes themselves.
    return re.sub(r"\[\s*(contractDate|contractDay|vendorName|vendorMobile)\s*\]", r"[\1]", text)


def arabic_present(text, phrase):
    words = normalize(phrase).split()
    alternatives = {
        " ".join(words),
        " ".join(reversed(words)),
        " ".join(word[::-1] for word in words),
        " ".join(word[::-1] for word in reversed(words)),
    }
    return any(value in text for value in alternatives)


def normalize_pdf_extraction(text):
    # Apply only to PDF extraction whose exact Arabic HTML source was checked.
    # Raw U+06CC and isolated/final U+FBFC/U+FBFD deliberately remain distinct.
    return normalize(text.translate(PDF_YEH_GLYPHS))


def reject_active_content(reader):
    pending = [reader.trailer]
    seen = set()
    while pending:
        value = pending.pop()
        if isinstance(value, IndirectObject):
            identity = (value.idnum, value.generation)
            if identity in seen:
                continue
            seen.add(identity)
            value = value.get_object()
        if isinstance(value, DictionaryObject):
            for key, item in value.items():
                require(key not in {"/OpenAction", "/AA", "/JS", "/JavaScript", "/Launch", "/URI", "/EmbeddedFiles", "/EmbeddedFile", "/Filespec"}, "Parsed PDF contains an executable, attachment or external-link entry: " + str(key))
                if key == "/S":
                    require(item not in {"/JavaScript", "/Launch", "/URI", "/GoToR", "/SubmitForm", "/ImportData"}, "Parsed PDF contains an active action: " + str(item))
                pending.append(item)
        elif isinstance(value, ArrayObject):
            pending.extend(value)


def main():
    require(pypdf.__version__ == "6.10.0", "Use the pinned test-only pypdf6.10.0 parser.")
    require(len(sys.argv) in (2, 3), "Usage: verify-contract-pdf.py artifacts [expected-source-revision]")
    artifact = Path(sys.argv[1]).resolve(strict=True)
    receipt_path = artifact / "receipt.json"
    receipt_bytes = receipt_path.read_bytes()
    receipt = json.loads(receipt_bytes)
    expected = sys.argv[2] if len(sys.argv) == 3 else os.environ.get("GITHUB_SHA")
    require(receipt.get("kind") == "original-contract-pdf-http" and receipt.get("mode") == "windows", "A real Windows HTTP receipt is required.")
    require(receipt.get("completed") is True and receipt.get("databaseDropped") is True and receipt.get("httpServerStopped") is True, "The HTTP fixture must complete, stop its private HTTP child and drop its private database.")
    require(receipt.get("fullDashboard") is False, "PDF smoke does not authorize full dashboard readiness.")
    require(re.fullmatch("[a-f0-9]{40}", receipt.get("sourceRevision", "")) is not None, "Receipt source revision is invalid.")
    if expected:
        require(receipt["sourceRevision"] == expected, "Receipt belongs to another source revision.")
    if os.environ.get("CI") == "true":
        require(expected is not None and receipt.get("helperBinding") == "exact-git-revision", "CI requires exact source and helper revision binding.")
    require(receipt.get("journalCommands") == 0 and receipt.get("externalAssetRequests") == 0, "HTTP proof must retain an empty journal and no asset requests.")
    require(receipt.get("databaseBeforeSha256") is not None and receipt.get("databaseBeforeSha256") == receipt.get("databaseAfterSha256"), "Database before/after row-hash proofs differ.")
    native = receipt.get("nativePdf", {})
    require(native.get("originalHttpDownload") is True, "A standalone renderer output cannot substitute for the original HTTP route.")
    require(native.get("binarySha256") == PINNED_WKHTMLTOPDF_SHA256, "PDF extraction comparison requires the observed pinned native generator.")
    pdf_bytes = (artifact / "contract_100.pdf").read_bytes()
    html_bytes = (artifact / "contract_100.html").read_bytes()
    require(hashlib.sha256(html_bytes).hexdigest() == receipt.get("renderedHtmlSha256"), "Retained original rendered HTML differs from its HTTP receipt.")
    source_html = html.unescape(html_bytes.decode("utf8"))
    for phrase in ARABIC_PHRASES:
        require(phrase in source_html, "Original HTML is missing the exact saved Arabic source text: " + phrase)
    require(hashlib.sha256(pdf_bytes).hexdigest() == native.get("sha256") and len(pdf_bytes) == native.get("bytes"), "PDF bytes differ from the native HTTP receipt.")
    require(1000 < len(pdf_bytes) < 524288 and pdf_bytes.startswith(b"%PDF-") and re.search(rb"%%EOF\s*$", pdf_bytes), "PDF signature, size or end marker is invalid.")
    reader = PdfReader(artifact / "contract_100.pdf", strict=True)
    require(not reader.is_encrypted, "The generated contract unexpectedly requires a password.")
    reject_active_content(reader)
    require(1 <= len(reader.pages) <= 3 and len(reader.pages) == native.get("pages"), "Parsed PDF page tree differs from native bounds.")
    raw_page_text = [page.extract_text() or "" for page in reader.pages]
    page_text = [normalize_pdf_extraction(value) for value in raw_page_text]
    require(all(page_text), "Every native contract page must contain extractable text.")
    text = "\n".join(page_text)
    required = ["CONTRACT_PDF_OWNED_SENTINEL_30", "[contractDate]", "[contractDay]", "[vendorName]", "[vendorMobile]", "First line", "Second line", "<literal>"]
    for value in required:
        require(value in text, "Native PDF is missing saved literal text: " + value)
    require("FOREIGN_CONTRACT_SENTINEL_31" not in text and "unchanged" not in text, "Native PDF contains foreign-contract or local-file content.")
    for phrase in ARABIC_PHRASES:
        require(arabic_present(text, phrase), "Native PDF is missing saved Arabic text: " + phrase)
    result = {
        "format": 1, "kind": "parsed-windows-contract-pdf", "completed": True,
        "sourceRevision": receipt["sourceRevision"], "httpReceiptSha256": hashlib.sha256(receipt_bytes).hexdigest(),
        "pdfSha256": native["sha256"], "parser": "pypdf", "parserVersion": pypdf.__version__,
        "pages": len(reader.pages), "pageTextSha256": [hashlib.sha256(value.encode()).hexdigest() for value in page_text],
        "rawPageTextSha256": [hashlib.sha256(value.encode()).hexdigest() for value in raw_page_text],
        "presentationGlyphAliasesApplied": [{"from": "U+%04X" % old, "to": "U+%04X" % new, "count": sum(value.count(chr(old)) for value in raw_page_text)} for old, new in PDF_YEH_GLYPHS.items() if any(chr(old) in value for value in raw_page_text)],
        "literalTokensVerified": required, "arabicPhrasesVerified": ARABIC_PHRASES,
        "fullDashboard": False,
    }
    (artifact / "parsed-pdf-receipt.json").write_text(json.dumps(result, ensure_ascii=False, indent=2) + "\n", encoding="utf8")
    print("CONTRACT_PDF_PARSED_COMPLETE " + json.dumps({"sourceRevision": result["sourceRevision"], "pages": result["pages"], "fullDashboard": False}))


if __name__ == "__main__":
    main()
