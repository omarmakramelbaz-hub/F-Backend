"""Execute only pure PHP metadata functions; no Laravel, PDO connection or SQL."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT=Path(__file__).resolve().parents[1]
PHP=shutil.which('php') or '/workspace/scratch/6c1b3b3f4a51/payroll-map/php-root/usr/bin/php8.3'

@unittest.skipUnless(Path(PHP).is_file(),'PHP is required for pure deployed normalizer tests')
class MigrationMetadataTests(unittest.TestCase):
    def normalize(self,type_,default,extra='',maria=False):
        raw=(ROOT/'deployment/go_erp_shared_workspace_migration.php').read_text()
        before,separator,_tail=raw.partition("try {\n    ini_set('display_errors','0');")
        self.assertTrue(separator)
        code=before.removeprefix('<?php')+"\n$v=json_decode(stream_get_contents(STDIN),true,64,JSON_THROW_ON_ERROR);echo workspace_canonical(workspace_normalize_column(...$v));"
        result=subprocess.run([PHP,'-n','-d','display_errors=0','-r',code],
            input=json.dumps([type_,default,extra,maria]).encode(),stdout=subprocess.PIPE,stderr=subprocess.PIPE,
            timeout=10,env={'PATH':'/usr/bin:/bin'})
        self.assertEqual(0,result.returncode,result.stderr.decode())
        return json.loads(result.stdout)

    def test_integer_display_width_only_never_zerofill_decimal_char_or_signedness(self):
        for name in ('tinyint','smallint','mediumint','int','integer','bigint'):
            self.assertEqual(name,self.normalize(name+'(11)',None)['type'])
            self.assertEqual(name+' unsigned',self.normalize(name+'(20) unsigned',None)['type'])
        for name in ('int(11) unsigned zerofill','decimal(12,2)','char(36)','timestamp(6)'):
            self.assertEqual(name,self.normalize(name,None)['type'])
        self.assertNotEqual(self.normalize('bigint',None),self.normalize('bigint unsigned',None))

    def test_mariadb_sql_null_literal_null_and_mysql_literal_remain_distinct(self):
        self.assertIsNone(self.normalize('varchar(100)','NULL',maria=True)['default'])
        self.assertEqual('NULL',self.normalize('varchar(100)',"'NULL'",maria=True)['default'])
        self.assertEqual('NULL',self.normalize('varchar(100)','NULL',maria=False)['default'])
        self.assertEqual("it's",self.normalize('varchar(100)',"'it''s'",maria=True)['default'])
        self.assertEqual('',self.normalize('varchar(100)',"''",maria=True)['default'])

    def test_only_unquoted_temporal_current_timestamp_expression_is_normalized(self):
        mysql=self.normalize('timestamp','CURRENT_TIMESTAMP','DEFAULT_GENERATED')
        maria=self.normalize('timestamp','current_timestamp()',maria=True)
        self.assertEqual(mysql,maria)
        self.assertNotEqual(mysql,self.normalize('timestamp',None))
        self.assertNotEqual(mysql,self.normalize('timestamp',"'current_timestamp()'",maria=True))
        self.assertEqual('current_timestamp()',self.normalize('varchar(100)','current_timestamp()',maria=True)['default'])
        self.assertEqual('current_timestamp(6)',self.normalize('timestamp(6)','current_timestamp(6)',maria=True)['default'])

    def test_on_update_generation_and_arbitrary_expression_metadata_remain_different(self):
        plain=self.normalize('timestamp','CURRENT_TIMESTAMP','DEFAULT_GENERATED')
        updated=self.normalize('timestamp','CURRENT_TIMESTAMP','DEFAULT_GENERATED on update CURRENT_TIMESTAMP')
        self.assertNotEqual(plain,updated);self.assertIn('on update',updated['extra'])
        self.assertEqual('stored generated',self.normalize('int(11)',None,'STORED GENERATED')['extra'])
        self.assertEqual('default_generated',self.normalize('int(11)','(1 + 2)','DEFAULT_GENERATED')['extra'])

    def test_integer_default_scalar_spelling_normalizes_without_retyping_string_columns(self):
        self.assertEqual(self.normalize('tinyint(1)',True),self.normalize('tinyint(1)','1'))
        self.assertEqual('0',self.normalize('bigint(20) unsigned',0)['default'])
        self.assertEqual(0,self.normalize('varchar(100)',0)['default'])

if __name__=='__main__':unittest.main()
