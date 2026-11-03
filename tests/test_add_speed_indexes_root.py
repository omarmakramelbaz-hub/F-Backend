"""The speed indexes: added online one at a time, never twice, and only the ones this script added are removed."""
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('speed_indexes', ROOT / 'deployment/add_speed_indexes_root.py')
indexes = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(indexes)


class FakeDatabase:
    """information_schema for a few tables, and the ALTER statements it was asked to run."""

    def __init__(self):
        self.tables = {
            'notifications': {'rows': 3700, 'columns': ['id', 'type', 'notifiable_type', 'notifiable_id', 'data', 'read_at', 'created_at'],
                              'indexes': {'PRIMARY': ['id'], 'notifications_notifiable_type_notifiable_id_index': ['notifiable_type', 'notifiable_id']}},
            'crm_customers': {'rows': 46000, 'columns': ['id', 'name', 'service_consent', 'marketing_consent', 'opted_out_at'],
                              'indexes': {'PRIMARY': ['id'], 'customers_by_name': ['name', 'phone']}},
            'whatsapp_inbox_messages': {'rows': 46000, 'columns': ['id', 'conversation_id', 'created_at'], 'indexes': {'PRIMARY': ['id']}},
            'users': {'rows': 11000, 'columns': ['id', 'updated_at'], 'indexes': {'PRIMARY': ['id']}},
            'user_address': {'rows': 2800, 'columns': ['id'], 'indexes': {'PRIMARY': ['id']}},
        }
        self.altered = []
        self.fail = set()

    def __call__(self, statement, timeout=900):
        name = statement.rsplit("'", 2)[-2] if "'" in statement else None
        if statement.startswith('SELECT TABLE_ROWS'):
            return [[str(self.tables[name]['rows'])]] if name in self.tables else []
        if statement.startswith('SELECT COLUMN_NAME'):
            return [[column] for column in self.tables[name]['columns']]
        if statement.startswith('SELECT INDEX_NAME'):
            return [[index, column] for index, columns in sorted(self.tables[name]['indexes'].items()) for column in columns]
        self.altered.append(statement)
        table = statement.split('ALTER TABLE `')[1].split('`')[0]
        index = statement.split('INDEX `')[1].split('`')[0]
        if index in self.fail:
            return None
        if ' ADD INDEX ' in statement:
            self.tables[table]['indexes'][index] = statement.split('(')[1].split(')')[0].replace('`', '').split(', ')
        else:
            self.tables[table]['indexes'].pop(index)
        return []


class SpeedIndexTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='go-erp-indexes-')
        self.folder = Path(self.temporary.name) / 'state'
        self.database = FakeDatabase()

    def tearDown(self):
        self.temporary.cleanup()

    def test_the_plan_adds_only_what_is_missing_and_changes_nothing(self):
        steps = {step['index']: step for step in indexes.plan(self.database)}
        self.assertEqual(steps['go_notifications_unread']['action'], 'add')
        self.assertEqual(steps['go_crm_customers_name'], {'table': 'crm_customers', 'index': 'go_crm_customers_name', 'action': 'present',
                                                          'why': 'covered by customers_by_name'})
        self.assertEqual(steps['go_meta_messages_thread_time']['why'], 'no such table')
        self.assertEqual(steps['go_user_address_updated']['why'], 'no column updated_at')
        self.assertEqual(self.database.altered, [])

    def test_indexes_are_added_online_one_at_a_time_and_kept_on_record(self):
        result = indexes.apply(self.database, self.folder, clock=iter(range(100)).__next__)
        self.assertEqual(result['result'], 'GO_ERP_SPEED_INDEXES_ADDED')
        added = [step['index'] for step in result['steps'] if step['action'] == 'added']
        self.assertEqual(added, ['go_notifications_unread', 'go_crm_customers_service', 'go_crm_customers_marketing', 'go_wa_messages_thread_time',
                                 'go_users_updated'])
        self.assertTrue(all(statement.startswith('SET SESSION lock_wait_timeout = 10; ALTER TABLE ') and statement.endswith(', ALGORITHM=INPLACE, LOCK=NONE')
                            for statement in self.database.altered))
        self.assertIn('ADD INDEX `go_notifications_unread` (`notifiable_type`, `notifiable_id`, `read_at`, `created_at`)', self.database.altered[0])
        self.assertEqual([item['index'] for item in json.loads((self.folder / 'added.json').read_text())], added)
        # Run again: nothing to add.
        again = indexes.apply(self.database, self.folder)
        self.assertFalse([step for step in again['steps'] if step['action'] in ('added', 'failed')])

    def test_a_failed_index_is_reported_and_only_this_scripts_indexes_are_removed(self):
        self.database.fail.add('go_users_updated')
        result = indexes.apply(self.database, self.folder)
        self.assertEqual((result['result'], result['failed']), ('GO_ERP_SPEED_INDEXES_PARTLY_ADDED', ['go_users_updated']))
        self.database.fail.clear()
        removed = indexes.rollback(self.database, self.folder)
        self.assertEqual(removed['result'], 'GO_ERP_SPEED_INDEXES_REMOVED')
        self.assertEqual(set(removed['removed']), {'go_notifications_unread', 'go_crm_customers_service', 'go_crm_customers_marketing',
                                                   'go_wa_messages_thread_time'})
        self.assertIn('customers_by_name', self.database.tables['crm_customers']['indexes'], 'An index it did not add stays')
        self.assertEqual(json.loads((self.folder / 'added.json').read_text()), [])

    def test_names_are_never_taken_from_outside_the_list(self):
        with self.assertRaises(indexes.Stop):
            indexes.quoted('users`; DROP TABLE users; --')


if __name__ == '__main__':
    unittest.main()
