<?php
namespace App\Services\Dashboard;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/** Explicit private provisioning during native snapshot import; never a central migration. */
final class DesktopDashboardPrintLedgerSchema
{
    public const DATABASE = 'fasakhansta_dashboard_effects';
    public const VERSION = 1;
    public const TABLES = ['print_ledger_scopes','print_ledger_commands','print_ledger_intents','print_ledger_attempts','print_ledger_reprints'];

    public function connection(): Connection
    {
        $db = DB::connection();
        abort_unless(config('desktop_dashboard.local') === true && $db->getDriverName() === 'mysql'
            && $db->getConfig('host') === '127.0.0.1'
            && preg_match('/^fasakhansta_dashboard(?:_stage_[a-f0-9]{16})?$/D', $db->getDatabaseName()), 403);
        return $db;
    }

    public function install(): void
    {
        $db = $this->connection();
        // MySQL DDL can implicitly commit. Never provision inside a business transaction.
        abort_unless($db->transactionLevel() === 0 && !$db->getPdo()->inTransaction(), 409);
        $db->statement('CREATE DATABASE IF NOT EXISTS `'.self::DATABASE.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin');
        foreach ($this->definitions() as $table=>$columns) {
            $db->statement('CREATE TABLE IF NOT EXISTS `'.self::DATABASE.'`.`'.$table.'` ('.$columns.') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin');
        }
        $this->verify($db);
    }

    public function verify(?Connection $db = null): void
    {
        $db ??= $this->connection();
        foreach ($this->contracts() as $table=>$expected) {
            $engine = $db->selectOne('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?', [self::DATABASE,$table]);
            abort_unless($engine && $engine->engine === 'InnoDB', 503, 'Private print ledger schema is unavailable.');
            $actual = [];
            foreach ($db->select('SELECT COLUMN_NAME AS name,DATA_TYPE AS type,COLUMN_TYPE AS full_type,CHARACTER_MAXIMUM_LENGTH AS capacity,COLLATION_NAME AS collation,IS_NULLABLE AS nullable FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY ORDINAL_POSITION', [self::DATABASE,$table]) as $column) {
                $length=in_array($column->type,['char','varchar'],true)?':'.$column->capacity:'';
                $unsigned=str_contains($column->full_type,'unsigned')?':unsigned':'';
                $collation=in_array($column->type,['char','varchar','longtext'],true)?':'.$column->collation:'';
                $actual[$column->name] = $column->type.$length.$unsigned.':'.$column->nullable.$collation;
            }
            abort_unless($actual === $expected, 503, 'Private print ledger schema does not match this component: '.$table);
            $indexes = $db->select('SELECT INDEX_NAME AS name,NON_UNIQUE AS non_unique,COLUMN_NAME AS column_name FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX', [self::DATABASE,$table]);
            $unique=[];
            foreach ($indexes as $index) if (!(int)$index->non_unique) $unique[$index->name][]=$index->column_name;
            $required = match($table) {
                'print_ledger_scopes'=>['PRIMARY'=>['device_id']],
                'print_ledger_commands'=>['PRIMARY'=>['device_id','command_id']],
                'print_ledger_attempts','print_ledger_reprints'=>['PRIMARY'=>['effect_id']],
                default=>['PRIMARY'=>['effect_id'],'origin_slot_unique'=>['device_id','enrollment_id','command_id','kind','slot']],
            };
            foreach ($required as $name=>$columns) abort_unless(($unique[$name]??null)===$columns,503,'Private print ledger uniqueness is unavailable.');
        }
    }

    public function table(string $table): string
    {
        if (!in_array($table,self::TABLES,true)) throw new \LogicException('Unknown private print ledger table.');
        return self::DATABASE.'.'.$table;
    }

    private function definitions(): array
    {
        return [
            'print_ledger_scopes'=>"device_id char(36) NOT NULL PRIMARY KEY,enrollment_id char(64) NOT NULL,actor_id bigint unsigned NOT NULL,enrollment_cipher longtext NOT NULL,schema_version int unsigned NOT NULL,created_at datetime NOT NULL",
            'print_ledger_commands'=>"device_id char(36) NOT NULL,command_id char(36) NOT NULL,enrollment_id char(64) NOT NULL,actor_id bigint unsigned NOT NULL,branch varchar(40) NOT NULL,route_name varchar(150) NOT NULL,request_hash char(64) NOT NULL,origin_database varchar(64) NOT NULL,snapshot_id char(36) NOT NULL,schema_hash char(64) NOT NULL,intents_hash char(64) NOT NULL,created_at datetime NOT NULL,PRIMARY KEY(device_id,command_id)",
            'print_ledger_attempts'=>"effect_id char(64) NOT NULL PRIMARY KEY,claim_token char(36) NOT NULL,native_session_id char(36) NOT NULL,actor_id bigint unsigned NOT NULL,branch varchar(40) NOT NULL,render_sha256 char(64) NOT NULL,invocation_id char(36) NOT NULL,permit_hash char(64) NOT NULL,state varchar(24) NOT NULL,outcome_cipher longtext NOT NULL,server_ack_hash char(64) NOT NULL,created_at datetime NOT NULL,updated_at datetime NOT NULL",
            'print_ledger_reprints'=>"effect_id char(64) NOT NULL PRIMARY KEY,request_id char(36) NOT NULL,device_id char(36) NOT NULL,actor_id bigint unsigned NOT NULL,branch varchar(40) NOT NULL,descriptor_cipher longtext NOT NULL,descriptor_hash char(64) NOT NULL,created_at datetime NOT NULL",
            'print_ledger_intents'=>"effect_id char(64) NOT NULL PRIMARY KEY,device_id char(36) NOT NULL,enrollment_id char(64) NOT NULL,command_id char(36) NOT NULL,kind varchar(24) NOT NULL,slot varchar(24) NOT NULL,actor_id bigint unsigned NOT NULL,branch varchar(40) NOT NULL,revision int unsigned NOT NULL,descriptor_hash char(64) NOT NULL,descriptor_cipher longtext NOT NULL,lifecycle_state varchar(24) NOT NULL,created_at datetime NOT NULL,UNIQUE KEY origin_slot_unique(device_id,enrollment_id,command_id,kind,slot)",
        ];
    }

    private function contracts(): array
    {
        $parse=function(string $sql): array {
            $result=[];
            foreach (explode(',',$sql) as $column) {
                if (preg_match('/^([a-z_][a-z0-9_]*) (char|varchar|bigint|int|longtext|datetime)\b(?:\(([0-9]+)\))?( unsigned)?/', $column,$match)) {
                    $type=$match[2];$length=in_array($type,['char','varchar'],true)?':'.$match[3]:'';
                    $unsigned=empty($match[4])?'':':unsigned';$collation=in_array($type,['char','varchar','longtext'],true)?':utf8mb4_bin':'';
                    $result[$match[1]]=$type.$length.$unsigned.':NO'.$collation;
                }
            }
            return $result;
        };
        return array_map($parse,$this->definitions());
    }
}
