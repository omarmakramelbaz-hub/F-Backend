"""Offline contract checks for the bounded 4K hero update; no server or API calls."""
from contextlib import ExitStack
from html.parser import HTMLParser
import importlib.util
import io
import json
import os
from pathlib import Path
import struct
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch

HERE = Path(__file__).resolve().parent
REPO = HERE.parent.parent
SPEC = importlib.util.spec_from_file_location('hero4k_installer', HERE / 'install.py')
INSTALLER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(INSTALLER)


def box(kind, payload):
    return struct.pack('>I4s', len(payload) + 8, kind) + payload


def mp4(width, height, handler=b'vide', fraction=0):
    track = box(b'tkhd', b'\0' * 76 + struct.pack('>II', width * 65536 + fraction, height * 65536))
    media = box(b'mdia', box(b'hdlr', b'\0' * 8 + handler + b'\0' * 12))
    return box(b'ftyp', b'isom\0\0\0\0isom') + box(b'moov', box(b'trak', track + media)) + box(b'mdat', b'\0' * 2048)


class ManifestContract(unittest.TestCase):
    def entries(self):
        return [{'path': path, 'sha256': 'a' * 64, 'baseline_sha256': INSTALLER.BASELINES.get(path)}
                for path in sorted(INSTALLER.ALLOWED)]

    def test_only_two_view_replacements_and_three_versioned_assets_are_allowed(self):
        self.assertEqual(INSTALLER.ALLOWED, {
            'resources/views/site/home.blade.php',
            'resources/views/site/motion/hero.blade.php',
            'public/storefront/hero-motion-4k.mp4',
            'public/storefront/hero-motion-hd.mp4',
            'public/storefront/hero-motion-4k-poster.webp',
        })
        self.assertEqual(set(INSTALLER.BASELINES), {INSTALLER.HOME_VIEW, INSTALLER.HERO_VIEW})
        self.assertEqual(INSTALLER.MAX_FILE, 50 * 1024 * 1024)
        self.assertEqual(INSTALLER.MAX_TOTAL, 80 * 1024 * 1024)
        self.assertEqual(len(INSTALLER.validate_manifest(self.entries())), 5)

    def test_incomplete_duplicate_and_traversing_manifests_are_refused(self):
        invalid = [self.entries()[:-1], self.entries() + [self.entries()[0]]]
        traversal = self.entries()
        traversal[0]['path'] = '../dashboard.php'
        invalid.append(traversal)
        for value in invalid:
            with self.subTest(value=value), self.assertRaises(RuntimeError):
                INSTALLER.validate_manifest(value)

    def test_media_always_precedes_both_live_view_replacements(self):
        reversed_order = [INSTALLER.HOME_VIEW, INSTALLER.HERO_VIEW, INSTALLER.POSTER_PATH,
                          INSTALLER.HD_VIDEO_PATH, INSTALLER.VIDEO_PATH]
        self.assertEqual(INSTALLER.installation_order(reversed_order), [
            INSTALLER.POSTER_PATH, INSTALLER.HD_VIDEO_PATH, INSTALLER.VIDEO_PATH,
            INSTALLER.HERO_VIEW, INSTALLER.HOME_VIEW])

    def test_baseline_guards_cannot_be_relaxed_by_manifest(self):
        for baseline in (None, 'b' * 64):
            value = self.entries()
            next(item for item in value if item['path'] == INSTALLER.HERO_VIEW)['baseline_sha256'] = baseline
            with self.assertRaises(RuntimeError):
                INSTALLER.validate_manifest(value)
        value = self.entries()
        next(item for item in value if item['path'] == INSTALLER.VIDEO_PATH)['baseline_sha256'] = 'b' * 64
        with self.assertRaises(RuntimeError):
            INSTALLER.validate_manifest(value)


class MediaCdnContract(unittest.TestCase):
    def entries(self):
        rows = ManifestContract().entries()
        for entry in rows:
            if entry['path'] in INSTALLER.MEDIA_PATHS:
                entry['url'] = 'https://' + INSTALLER.MEDIA_CDN_HOST + '/reviewed/' + Path(entry['path']).name
        return rows

    def test_only_the_three_media_entries_may_add_static_official_cdn_urls(self):
        entries = self.entries()
        INSTALLER.validate_manifest(entries)
        self.assertEqual(sum('url' in entry for entry in entries), 3)
        for entry in entries:
            if entry['path'] not in INSTALLER.MEDIA_PATHS:
                self.assertEqual(set(entry), {'path', 'sha256', 'baseline_sha256'})
        # Repository-hosted media remains a supported hash-pinned fallback.
        INSTALLER.validate_manifest(ManifestContract().entries())

    def test_urls_on_views_and_unknown_extra_fields_are_refused(self):
        for path in (INSTALLER.HOME_VIEW, INSTALLER.HERO_VIEW):
            entries = self.entries()
            next(entry for entry in entries if entry['path'] == path)['url'] = 'https://' + INSTALLER.MEDIA_CDN_HOST + '/view.webp'
            with self.assertRaises(RuntimeError):
                INSTALLER.validate_manifest(entries)
        entries = self.entries(); entries[0]['unexpected'] = True
        with self.assertRaises(RuntimeError):
            INSTALLER.validate_manifest(entries)

    def test_wrong_host_scheme_extension_credentials_query_fragment_or_port_is_refused(self):
        host = INSTALLER.MEDIA_CDN_HOST
        invalid = [None, '', 'http://' + host + '/asset.mp4', 'https://example.com/asset.mp4',
                   'https://sub.' + host + '/asset.mp4', 'https://' + host + '.evil.example/asset.mp4',
                   'https://user@' + host + '/asset.mp4', 'https://user:password@' + host + '/asset.mp4',
                   'https://' + host + ':443/asset.mp4', 'https://' + host + '/asset.mp4?token=x',
                   'https://' + host + '/asset.mp4?', 'https://' + host + '/asset.mp4#fragment',
                   'https://' + host + '/asset.mp4#', 'https://' + host + '/asset.webp',
                   'https://' + host + '/asset.mp4\n', '/asset.mp4',
                   'https://' + host + '/%2e%2e/asset.mp4']
        for value in invalid:
            entries = self.entries()
            next(entry for entry in entries if entry['path'] == INSTALLER.VIDEO_PATH)['url'] = value
            with self.subTest(url=value), self.assertRaises(RuntimeError):
                INSTALLER.validate_manifest(entries)
        with self.assertRaises(RuntimeError):
            INSTALLER.validate_media_url('https://' + host + '/poster.mp4', INSTALLER.POSTER_PATH)

    def release(self):
        files = MediaContract().files()
        files.update({INSTALLER.HOME_VIEW: b'homepage', INSTALLER.HERO_VIEW: b'hero partial'})
        entries = self.entries()
        sources = {}
        for entry in entries:
            entry['sha256'] = INSTALLER.digest(files[entry['path']])
            url = entry.get('url', INSTALLER.REPOSITORY + '/' + 'a' * 40 + '/' + entry['path'])
            sources[url] = files[entry['path']]
        manifest = json.dumps(entries).encode()
        sources[INSTALLER.REPOSITORY + '/' + 'a' * 40 + '/' + INSTALLER.PREFIX + '/manifest.json'] = manifest
        return files, sources, INSTALLER.digest(manifest)

    def test_release_downloads_views_from_pinned_commit_and_media_from_validated_cdn(self):
        expected, sources, manifest_hash = self.release()
        called = []
        def download(url, limit):
            called.append(url)
            return sources[url]
        with patch.object(INSTALLER, 'download', side_effect=download):
            entries, files = INSTALLER.read_release('a' * 40, manifest_hash)
        self.assertEqual(files, expected)
        self.assertEqual(sum(INSTALLER.MEDIA_CDN_HOST in url for url in called), 3)
        self.assertEqual(sum(INSTALLER.REPOSITORY in url for url in called), 3)

    def test_a_changed_cdn_asset_is_refused_by_its_pinned_content_hash(self):
        expected, sources, manifest_hash = self.release()
        def download(url, limit):
            return b'changed media bytes' if INSTALLER.MEDIA_CDN_HOST in url else sources[url]
        with patch.object(INSTALLER, 'download', side_effect=download), self.assertRaisesRegex(RuntimeError, 'file hash mismatch'):
            INSTALLER.read_release('a' * 40, manifest_hash)


class MediaContract(unittest.TestCase):
    def files(self):
        return {INSTALLER.VIDEO_PATH: mp4(3840, 2160), INSTALLER.HD_VIDEO_PATH: mp4(1920, 1080),
                INSTALLER.POSTER_PATH: b'RIFF\0\0\0\0WEBP'}

    def test_actual_video_track_dimensions_are_required(self):
        self.assertEqual(INSTALLER.mp4_dimensions(mp4(3840, 2160)), (3840, 2160))
        self.assertEqual(INSTALLER.mp4_dimensions(mp4(1920, 1080)), (1920, 1080))
        INSTALLER.validate_media(self.files())

    def test_hd_payload_cannot_be_named_as_4k(self):
        files = self.files()
        files[INSTALLER.VIDEO_PATH] = mp4(1920, 1080)
        with self.assertRaisesRegex(RuntimeError, '3840x2160'):
            INSTALLER.validate_media(files)

    def test_portrait_fractional_or_audio_only_tracks_are_refused(self):
        files = self.files()
        files[INSTALLER.VIDEO_PATH] = mp4(2160, 3840)
        with self.assertRaises(RuntimeError):
            INSTALLER.validate_media(files)
        for value in [mp4(3840, 2160, fraction=1), mp4(3840, 2160, handler=b'soun')]:
            with self.assertRaises(RuntimeError):
                INSTALLER.mp4_dimensions(value)

    def test_truncated_or_unbounded_boxes_are_refused(self):
        for value in [b'ftyp', box(b'ftyp', b'isom') + struct.pack('>I4s', 999999, b'moov'),
                      box(b'ftyp', b'isom') + struct.pack('>I4s', 1, b'moov')]:
            with self.assertRaises(RuntimeError):
                INSTALLER.mp4_dimensions(value)

    def test_a_non_webp_poster_is_refused(self):
        value = self.files()
        value[INSTALLER.POSTER_PATH] = b'not a WebP image'
        with self.assertRaises(RuntimeError):
            INSTALLER.validate_media(value)


class HttpSmokeContract(unittest.TestCase):
    class Response(io.BytesIO):
        def __init__(self, url, payload, status, headers):
            super().__init__(payload)
            self.url, self.status, self.headers = url, status, headers
        def geturl(self):
            return self.url

    def exercise(self, status=206, bad_range=False, truncate=False, corrupt=False, missing_length=False, bad_length=False):
        files = MediaContract().files()
        homepage = (b'<video poster="/storefront/hero-motion-4k-poster.webp">'
                    b'<source media="(max-width: 767px)" src="/storefront/hero-motion-hd.mp4">'
                    b'<source src="/storefront/hero-motion-4k.mp4"></video>')
        def download(url, limit):
            return files[INSTALLER.POSTER_PATH] if 'poster.webp' in url else homepage
        calls = []
        def open_response(request, timeout):
            self.assertEqual(request.get_header('Range'), 'bytes=0-1023')
            path = INSTALLER.HD_VIDEO_PATH if 'hero-motion-hd.mp4' in request.full_url else INSTALLER.VIDEO_PATH
            payload = files[path][:1024]
            if corrupt: payload = b'_' + payload[1:]
            if truncate: payload = payload[:-1]
            if status == 206:
                headers = {'Content-Range': ('bytes 0-1022/' if bad_range else 'bytes 0-1023/') + str(len(files[path]))}
            else:
                headers = {} if missing_length else {'Content-Length': str(len(files[path]) - int(bad_length))}
            calls.append(request.full_url)
            return self.Response(request.full_url, payload, status, headers)
        with patch.object(INSTALLER, 'download', side_effect=download), patch.object(INSTALLER.HTTP, 'open', side_effect=open_response):
            INSTALLER.smoke('a' * 40, files)
        return calls

    def test_both_videos_must_return_full_exact_range_or_200_fallback(self):
        for status in (206, 200):
            with self.subTest(status=status):
                self.assertEqual(len(self.exercise(status=status)), 2)

    def test_200_fallback_requires_the_matching_full_asset_content_length(self):
        for options in ({'missing_length': True}, {'bad_length': True}):
            with self.subTest(options=options), self.assertRaisesRegex(RuntimeError, 'full-response size mismatch'):
                self.exercise(status=200, **options)

    def test_short_corrupt_or_incorrect_range_responses_are_refused(self):
        for options in ({'bad_range': True}, {'truncate': True}, {'corrupt': True}):
            with self.subTest(options=options), self.assertRaises(RuntimeError):
                self.exercise(**options)


class HeroMarkupContract(unittest.TestCase):
    class Tags(HTMLParser):
        def __init__(self):
            super().__init__()
            self.videos, self.sources, self.links = [], [], []
        def handle_starttag(self, tag, attrs):
            if tag == 'video': self.videos.append(dict(attrs))
            if tag == 'source': self.sources.append(dict(attrs))
            if tag == 'link': self.links.append(dict(attrs))

    def test_mobile_hd_precedes_default_4k_without_changing_playback_controls(self):
        text = (REPO / INSTALLER.HERO_VIEW).read_text()
        tags = self.Tags(); tags.feed(text)
        self.assertEqual(len(tags.videos), 1)
        self.assertEqual(len(tags.sources), 2)
        self.assertEqual(tags.sources[0]['media'], '(max-width: 767px)')
        self.assertIn('storefront/hero-motion-hd.mp4', tags.sources[0]['src'])
        self.assertNotIn('media', tags.sources[1])
        self.assertIn('storefront/hero-motion-4k.mp4', tags.sources[1]['src'])
        self.assertTrue(all(item.get('type') == 'video/mp4' for item in tags.sources))
        self.assertTrue(all(name in tags.videos[0] for name in ['autoplay', 'muted', 'loop', 'playsinline', 'data-hero-video']))
        self.assertIn('data-video-toggle', text)
        self.assertIn('فيديو تقديمي', text)

    def test_preloaded_and_displayed_poster_are_the_same_new_asset(self):
        hero = (REPO / INSTALLER.HERO_VIEW).read_text()
        home = (REPO / INSTALLER.HOME_VIEW).read_text()
        tags = self.Tags(); tags.feed(home)
        posters = [item for item in tags.links if item.get('rel') == 'preload' and item.get('as') == 'image']
        self.assertEqual(len(posters), 1)
        self.assertIn('storefront/hero-motion-4k-poster.webp', posters[0]['href'])
        self.assertIn('storefront/hero-motion-4k-poster.webp', hero)
        self.assertNotIn('hero-motion-poster.webp', home + hero)


class AtomicWriteContract(unittest.TestCase):
    def test_replacements_and_additions_can_be_rolled_back(self):
        user = SimpleNamespace(pw_uid=os.getuid(), pw_gid=os.getgid())
        with tempfile.TemporaryDirectory(prefix='hero4k-offline-') as directory, patch.object(INSTALLER, 'ROOT', Path(directory)):
            parent = Path(directory) / 'public/storefront'; parent.mkdir(parents=True)
            path = parent / 'hero-motion-4k.mp4'; path.write_bytes(b'reviewed baseline')
            with ExitStack() as stack:
                target = INSTALLER.Target(INSTALLER.VIDEO_PATH, user, stack)
                before = target.read(); after = target.write(b'candidate', before, before)
                target.write(before['data'], after, before)
                self.assertEqual(path.read_bytes(), b'reviewed baseline')
                addition = INSTALLER.Target(INSTALLER.HD_VIDEO_PATH, user, stack)
                state = addition.write(b'new asset', None)
                addition.remove(state)
                self.assertFalse((parent / 'hero-motion-hd.mp4').exists())

    def test_symbolic_parent_is_never_followed(self):
        user = SimpleNamespace(pw_uid=os.getuid(), pw_gid=os.getgid())
        with tempfile.TemporaryDirectory(prefix='hero4k-offline-') as directory, patch.object(INSTALLER, 'ROOT', Path(directory)):
            root = Path(directory); (root / 'public').mkdir(); (root / 'other').mkdir()
            (root / 'public/storefront').symlink_to(root / 'other', target_is_directory=True)
            with ExitStack() as stack, self.assertRaises(RuntimeError):
                INSTALLER.Target(INSTALLER.VIDEO_PATH, user, stack)


if __name__ == '__main__':
    unittest.main()
