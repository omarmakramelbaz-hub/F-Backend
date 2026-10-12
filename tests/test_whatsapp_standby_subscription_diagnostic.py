#!/usr/bin/env python3
"""Read-only subscription report fixtures, including response-secret redaction."""
import hashlib
import json
import os
import pathlib
import subprocess
import tempfile
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]
HELPER = ROOT / 'deployment/whatsapp_standby_subscription_diagnostic.php'
PHP = os.environ.get('WHATSAPP_TEST_PHP', 'php')
SECRET = 'a' * 32
TOKEN = 'SensitiveTokenValueNeverReport_' + 'x' * 40
CALLBACK = 'https://private.example.test/callback?access_token=' + TOKEN
APP = '2177982783065133'


class SubscriptionDiagnosticTest(unittest.TestCase):
    def inspect(self, app_response, waba_response):
        payload = json.dumps([app_response, waba_response])
        source = '''<?php
define('WHATSAPP_STANDBY_DIAGNOSTIC_LIBRARY', true);
require $argv[1];
$responses = json_decode(stream_get_contents(STDIN), true, 24, JSON_THROW_ON_ERROR);
$calls = [];
$diag = new \\App\\Support\\WhatsAppStandbySubscriptionDiagnostic(
    static function ($url, $bearer) use (&$responses, &$calls) {
        $calls[] = ['url' => $url, 'bearer' => $bearer];
        return array_shift($responses);
    });
$report = $diag->inspect(str_repeat('a', 32), 'SensitiveTokenValueNeverReport_' . str_repeat('x', 40));
if (count($calls) !== 2
    || $calls[0]['url'] !== 'https://graph.facebook.com/v25.0/2177982783065133/subscriptions'
    || $calls[0]['bearer'] !== '2177982783065133|' . str_repeat('a', 32)
    || $calls[1]['url'] !== 'https://graph.facebook.com/v25.0/468336579702269/subscribed_apps?limit=100') exit(3);
echo json_encode($report, JSON_THROW_ON_ERROR);
'''
        with tempfile.TemporaryDirectory() as folder:
            script = pathlib.Path(folder) / 'fixture.php'
            script.write_text(source)
            result = subprocess.run([PHP, str(script), str(HELPER)], input=payload,
                                    text=True, capture_output=True, timeout=10)
        self.assertEqual(result.returncode, 0, result.stderr)
        for value in (SECRET, TOKEN, CALLBACK, 'private.example.test', 'private-other-app'):
            self.assertNotIn(value, result.stdout)
        report = json.loads(result.stdout)
        self.assertEqual(report['network_requests'], 2)
        self.assertEqual(report['writes'], 0)
        return report

    @staticmethod
    def response(data, **extra):
        return {'status': 200, 'body': json.dumps({'data': data, **extra})}

    def app(self, fields, active=True):
        return self.response([{'object': 'whatsapp_business_account', 'active': active,
            'callback_url': CALLBACK, 'fields': fields}])

    def waba(self):
        return self.response([{'whatsapp_business_api_data': {'id': APP, 'name': TOKEN,
            'link': CALLBACK}}, {'whatsapp_business_api_data': {'id': '999999999',
            'name': 'private-other-app', 'link': CALLBACK}}])

    def test_present_subscription_is_redacted(self):
        r = self.inspect(self.app([{'name': 'messages', 'version': 'v25.0'},
            {'name': 'standby', 'version': 'v25.0'}]), self.waba())
        self.assertEqual(r['status'], 'READY')
        self.assertTrue(r['app_subscription']['standby_subscribed'])
        self.assertEqual(r['app_subscription']['field_names'], ['messages', 'standby'])
        self.assertTrue(r['waba_subscription']['app_matches'])
        self.assertFalse(r['standby_visibility']['api_visibility_checked'])

    def test_absent_standby_is_not_enabled(self):
        r = self.inspect(self.app(['messages', 'smb_message_echoes'], False), self.waba())
        self.assertFalse(r['app_subscription']['standby_subscribed'])
        self.assertFalse(r['app_subscription']['active'])

    def test_missing_object_and_app_are_explicit(self):
        r = self.inspect(self.response([]), self.response([]))
        self.assertFalse(r['app_subscription']['whatsapp_object_present'])
        self.assertIsNone(r['app_subscription']['standby_subscribed'])
        self.assertFalse(r['waba_subscription']['app_matches'])

    def test_pagination_is_not_assumed_complete(self):
        r = self.inspect(self.response([], paging={'next': CALLBACK}),
                         self.response([], paging={'next': CALLBACK}))
        self.assertIsNone(r['app_subscription']['whatsapp_object_present'])
        self.assertIsNone(r['waba_subscription']['app_matches'])
        self.assertFalse(r['waba_subscription']['complete'])

    def test_api_error_has_only_fixed_public_metadata(self):
        err = {'status': 400, 'body': json.dumps({'error': {'code': 10,
            'error_subcode': 33, 'type': 'OAuthException', 'message': TOKEN + CALLBACK,
            'error_data': {'details': SECRET}, 'fbtrace_id': TOKEN}})}
        r = self.inspect(err, self.waba())
        self.assertEqual(r['status'], 'PARTIAL')
        self.assertEqual(r['probes']['APP_SUBSCRIPTIONS'], {'status': 'API_ERROR',
            'http': 400, 'code': 10, 'subcode': 33, 'error_class': 'OAUTH_EXCEPTION'})

    def test_invalid_fields_do_not_leak(self):
        r = self.inspect(self.app([TOKEN]), self.waba())
        self.assertEqual(r['probes']['APP_SUBSCRIPTIONS']['status'], 'INVALID_RESPONSE')
        self.assertEqual(r['app_subscription']['field_names'], [])
        self.assertIsNone(r['app_subscription']['standby_subscribed'])

    def test_unknown_field_names_are_counted_not_copied(self):
        r = self.inspect(self.app(['standby', SECRET]), self.waba())
        self.assertEqual(r['app_subscription']['unknown_field_count'], 1)
        self.assertEqual(r['app_subscription']['field_names'], ['standby'])

    def test_wrong_owner_launch_is_zero_requests(self):
        if os.geteuid() != 0:
            self.skipTest('Fixture requires root, which is not the fixed fasakha owner')
        result = subprocess.run([PHP, str(HELPER)], capture_output=True, text=True, timeout=10)
        self.assertEqual(result.returncode, 1)
        self.assertEqual(json.loads(result.stdout), {'diagnostic': 'READ_ONLY_STANDBY_SUBSCRIPTION',
            'status': 'WRONG_PROCESS_OWNER', 'network_requests': 0, 'writes': 0})


if __name__ == '__main__':
    unittest.main()
