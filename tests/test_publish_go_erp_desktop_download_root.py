"""The download publisher places the new app where the dashboard's "Desktop program" page offers installers."""
import base64
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import stat
import tempfile
import unittest

SPEC = importlib.util.spec_from_file_location(
    'desktop_download', Path(__file__).resolve().parents[1] / 'deployment/publish_go_erp_desktop_download_root.py')
publisher = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(publisher)


class FakeResponse(io.BytesIO):
    def __enter__(self):
        return self

    def __exit__(self, *exc):
        self.close()


class DownloadPublisherTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='go-erp-download-')
        self.shared = Path(self.temporary.name) / 'shared'
        (self.shared / 'installers').mkdir(parents=True)
        for path in (self.shared, self.shared / 'installers'):
            os.chmod(str(path), 0o750)
        self.files = {}
        self.urls = []

    def tearDown(self):
        self.temporary.cleanup()

    def opener(self, url, timeout):
        self.urls.append(url)
        return FakeResponse(self.files[url.rsplit('-', 1)[-1][:-4]])

    def add(self, version):
        data = ('installer ' + version).encode() * 1000
        self.files[version] = data
        return base64.b64encode(hashlib.sha512(data).digest()).decode(), hashlib.sha256(data).hexdigest()

    def publish(self, version, sha512, revision):
        return publisher.publish(self.shared, version, sha512, revision, opener=self.opener, owner=os.getuid())

    def records(self):
        return json.loads((self.shared / 'downloads.json').read_text())

    def test_the_first_version_becomes_the_dashboard_download(self):
        sha512, sha256 = self.add('1.4.0')
        result = self.publish('1.4.0', sha512, 'a' * 40)
        self.assertEqual(result['result'], 'GO_ERP_DESKTOP_DOWNLOAD_PUBLISHED')
        self.assertEqual(self.urls, ['https://github.com/omarmakramelbaz-hub/F-Backend/releases/download/desktop-v1.4.0/GO-ERP-Desktop-Setup-1.4.0.exe'])
        records = self.records()
        self.assertEqual((records['format'], records['kind'], records['previous']), (1, 'go-erp-published-downloads', None))
        current = records['current']
        self.assertEqual(current['installer'], {'sha256': sha256, 'bytes': len(self.files['1.4.0']),
                                                'path': '/api/desktop-dashboard/update/installer/' + sha256})
        self.assertEqual((current['version'], current['sequence'], current['source_revision'], current['gui']), ('1.4.0', 1, 'a' * 40, {}))
        installer = self.shared / 'installers' / (sha256 + '.exe')
        self.assertEqual(installer.read_bytes(), self.files['1.4.0'])
        for path in (installer, self.shared / 'downloads.json'):
            self.assertEqual(stat.S_IMODE(path.stat().st_mode), 0o640)
        self.assertEqual(self.publish('1.4.0', sha512, 'a' * 40)['result'], 'GO_ERP_DESKTOP_DOWNLOAD_ALREADY_PUBLISHED')
        self.assertEqual(len(self.urls), 1, 'An already published version is not downloaded again')

    def test_a_new_version_keeps_the_last_one_as_previous_and_removes_older_files(self):
        first = self.add('1.4.0')
        second = self.add('1.5.0')
        third = self.add('1.6.0')
        self.publish('1.4.0', first[0], 'a' * 40)
        self.publish('1.5.0', second[0], 'b' * 40)
        records = self.records()
        self.assertEqual((records['current']['version'], records['current']['sequence'], records['previous']['version']), ('1.5.0', 2, '1.4.0'))
        result = self.publish('1.6.0', third[0], 'c' * 40)
        self.assertEqual((result['previous_version'], result['older_files_removed']), ('1.5.0', 1))
        self.assertEqual(sorted(os.listdir(str(self.shared / 'installers'))), sorted([second[1] + '.exe', third[1] + '.exe']))

    def test_a_download_that_does_not_match_the_checksum_is_refused_and_leaves_nothing(self):
        self.add('1.4.0')
        wrong = base64.b64encode(hashlib.sha512(b'other').digest()).decode()
        with self.assertRaisesRegex(publisher.Stop, 'CHECKSUM_MISMATCH'):
            self.publish('1.4.0', wrong, 'a' * 40)
        self.assertEqual(os.listdir(str(self.shared / 'installers')), [])
        self.assertFalse((self.shared / 'downloads.json').exists())

    def test_unsafe_folders_and_bad_arguments_stop_before_downloading(self):
        sha512, _ = self.add('1.4.0')
        os.chmod(str(self.shared / 'installers'), 0o770)
        with self.assertRaisesRegex(publisher.Stop, 'FOLDER_INSTALLERS'):
            self.publish('1.4.0', sha512, 'a' * 40)
        with self.assertRaisesRegex(publisher.Stop, 'VERSION'):
            self.publish('1.4', sha512, 'a' * 40)
        self.assertEqual(self.urls, [])


if __name__ == '__main__':
    unittest.main()
