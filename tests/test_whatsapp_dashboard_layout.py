"""DOM contracts and contrast for the WhatsApp dashboard presentation layer.

No application bootstrap, credentials, browser or network is used here.
Rendered dimensions are checked separately with the synthetic HTML fixture.
"""
import pathlib
import unittest
from html.parser import HTMLParser

ROOT = pathlib.Path(__file__).resolve().parents[1]


class Markup(HTMLParser):
    def __init__(self, source):
        super().__init__()
        self.nodes = []
        self.feed(source)

    def handle_starttag(self, tag, attrs):
        self.nodes.append((tag, dict(attrs)))

    def hooked(self, hook):
        return [(tag, attrs) for tag, attrs in self.nodes if hook in attrs]


def contrast(foreground, background):
    def luminance(hex_value):
        rgb = [int(hex_value[index:index + 2], 16) / 255 for index in (1, 3, 5)]
        rgb = [value / 12.92 if value <= .04045 else ((value + .055) / 1.055) ** 2.4 for value in rgb]
        return sum(value * weight for value, weight in zip(rgb, (.2126, .7152, .0722)))
    light, dark = sorted((luminance(foreground), luminance(background)), reverse=True)
    return (light + .05) / (dark + .05)


class WhatsAppLayoutTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.index = (ROOT / 'resources/views/admin/whatsapp/index.blade.php').read_text()
        cls.replies = (ROOT / 'resources/views/admin/whatsapp/replies.blade.php').read_text()
        cls.markup = Markup(cls.index + '\n' + cls.replies)
        cls.css = (ROOT / 'public/css/dashboard-whatsapp-inbox.css').read_text() + '\n' + (ROOT / 'public/css/dashboard-whatsapp-replies.css').read_text()

    def test_inbox_and_composer_hooks_remain_unique(self):
        hooks = (
            'data-wa-thread-list', 'data-wa-list-status', 'data-wa-count', 'data-wa-messages',
            'data-wa-chat-status', 'data-wa-title', 'data-wa-customer', 'data-wa-alert',
            'data-wa-connection', 'data-wa-scroll', 'data-wa-older', 'data-wa-more-threads',
            'data-wa-refresh', 'data-wa-back', 'data-wa-unread-total', 'data-wa-replies',
            'data-wa-reply-form', 'data-wa-reply-text', 'data-wa-reply-send', 'data-wa-reply-count',
            'data-wa-reply-refresh', 'data-wa-reply-status', 'data-wa-voice-record',
            'data-wa-voice-stop', 'data-wa-voice-cancel', 'data-wa-voice-time',
            'data-wa-voice-preview', 'data-wa-voice-send', 'data-wa-voice-status',
            'data-wa-reply-audit', 'data-wa-reply-audit-list', 'data-wa-thread-scroll',
            'data-wa-conversation-sentinel', 'data-wa-message-sentinel', 'data-wa-new-messages',
            'data-wa-selection-toolbar', 'data-wa-selection-count', 'data-wa-select-mode',
            'data-wa-select-all', 'data-wa-delete-selected', 'data-wa-selection-cancel',
            'data-wa-reply-quote', 'data-wa-reply-quote-text', 'data-wa-reply-quote-cancel',
            'data-wa-reply-emoji', 'data-wa-reply-emoji-picker',
            'data-wa-order-drawer',
        )
        for hook in hooks:
            with self.subTest(hook=hook):
                self.assertEqual(len(self.markup.hooked(hook)), 1)
        ids = [attrs['id'] for _, attrs in self.markup.nodes if 'id' in attrs]
        self.assertEqual(len(ids), len(set(ids)))

    def test_icon_controls_have_names_and_closed_states_are_safe(self):
        for tag, attrs in self.markup.nodes:
            if tag == 'button' and 'wa-icon-button' in attrs.get('class', '').split():
                with self.subTest(control=attrs):
                    self.assertTrue(attrs.get('aria-label'))
                    self.assertTrue(attrs.get('title'))
            self.assertFalse(any(key.startswith('on') for key in attrs), 'Inline handler in presentation layer')
        for hook in ('data-wa-reply-text', 'data-wa-reply-send', 'data-wa-voice-record'):
            self.assertIn('disabled', self.markup.hooked(hook)[0][1])
        for hook in ('data-wa-more-threads', 'data-wa-older', 'data-wa-new-messages', 'data-wa-selection-toolbar', 'data-wa-reply-quote', 'data-wa-reply-emoji-picker'):
            self.assertIn('hidden', self.markup.hooked(hook)[0][1])
        text = self.markup.hooked('data-wa-reply-text')[0][1]
        self.assertEqual(text['dir'], 'auto')
        self.assertEqual(text['maxlength'], '4096')
        self.assertIn('aria-live', self.markup.hooked('data-wa-reply-status')[0][1])
        drawer = [attrs for tag, attrs in self.markup.nodes if tag == 'details' and 'wa-inbox-order-drawer' in attrs.get('class', '').split()]
        self.assertEqual(len(drawer), 1)
        self.assertNotIn('open', drawer[0])

    def test_bootstrap_security_and_order_functions_are_preserved(self):
        for source in (self.index, self.replies):
            self.assertIn('csrf_token()', source)
            self.assertIn('JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT', source)
            self.assertNotIn('{!!', source)
        self.assertIn("@include('admin.whatsapp.orders')", self.index)
        self.assertIn('whatsapp-inbox-bootstrap', self.index)
        self.assertIn('whatsapp-replies-bootstrap', self.replies)
        self.assertIn("'expired'=>'انتهت مدة الرد المتاحة.", self.replies)
        self.assertIn("'unknown'=>'حالة الإرسال غير مؤكدة.", self.replies)
        self.assertIn("'unread_label'=>app()->getLocale() === 'ar' ? 'محادثات غير مقروءة'", self.index)
        self.assertIn('حذف من الداشبورد', self.index)

    def test_text_and_focus_contrast(self):
        pairs = (
            ('#203d42', '#ffffff'), ('#203d42', '#dcf3db'), ('#5d7174', '#ffffff'),
            ('#596e61', '#dcf3db'), ('#146446', '#edf7f0'), ('#4e695b', '#f8faf8'),
            ('#8e501a', '#f8faf8'), ('#ffffff', '#13734f'), ('#61756a', '#f8faf8'),
        )
        for foreground, background in pairs:
            with self.subTest(foreground=foreground, background=background):
                self.assertGreaterEqual(contrast(foreground, background), 4.5)
        self.assertGreaterEqual(contrast('#db6a17', '#ffffff'), 3)


if __name__ == '__main__':
    unittest.main()
