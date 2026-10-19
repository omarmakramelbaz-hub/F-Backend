"""Strict controls for the observed Windows PDF presentation-glyph comparison."""
import importlib.util
from pathlib import Path
import unittest

spec = importlib.util.spec_from_file_location("contract_pdf_verifier", Path(__file__).with_name("verify-contract-pdf.py"))
verifier = importlib.util.module_from_spec(spec)
spec.loader.exec_module(verifier)


class ArabicPdfComparisonTest(unittest.TestCase):
    def test_actual_windows_extraction_preserves_both_arabic_phrases(self):
        # Actual artifact 12219409268 from source 6f41acdf: Arial ToUnicode uses
        # FBFE for the initial glyph of the known Arabic YEH input in تجريبي.
        raw = "ظوﻔﺣﻣ\n \nصﻧ\n & \nﻲﺑﯾرﺟﺗ\n \nدﻘﻋ"
        self.assertFalse(verifier.arabic_present(verifier.normalize(raw), "عقد تجريبي"))
        text = verifier.normalize_pdf_extraction(raw)
        for phrase in verifier.ARABIC_PHRASES:
            self.assertTrue(verifier.arabic_present(text, phrase))

    def test_only_the_two_initial_medial_presentation_forms_are_projected(self):
        self.assertEqual(verifier.normalize_pdf_extraction("\ufbfe\ufbff"), "يي")
        # Normal Unicode normalization remains unchanged outside PDF extraction.
        self.assertEqual(verifier.normalize("\ufbfe\ufbff"), "\u06cc\u06cc")
        for value in ["\u06cc", "\ufbfc", "\ufbfd"]:
            self.assertEqual(verifier.normalize_pdf_extraction(value), "\u06cc")

    def test_raw_farsi_yeh_and_isolated_final_forms_do_not_substitute_arabic(self):
        for wrong in ["عقد تجر\u06ccبي", "عقد تجريب\u06cc", "عقد تجريب\ufbfc", "عقد تجريب\ufbfd"]:
            with self.subTest(wrong=wrong):
                self.assertFalse(verifier.arabic_present(verifier.normalize_pdf_extraction(wrong), "عقد تجريبي"))

    def test_missing_or_replaced_arabic_is_still_rejected(self):
        for wrong in ["", "CONTRACT_PDF_OWNED_SENTINEL_30", "عقد", "عقد غريب", "عقد تجربي", "عقد تجريبن"]:
            with self.subTest(wrong=wrong):
                self.assertFalse(verifier.arabic_present(verifier.normalize_pdf_extraction(wrong), "عقد تجريبي"))
        self.assertFalse(verifier.arabic_present(verifier.normalize_pdf_extraction("نص مفقود"), "نص محفوظ"))


if __name__ == "__main__":
    unittest.main()
