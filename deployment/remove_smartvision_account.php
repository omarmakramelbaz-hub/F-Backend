<?php
declare(strict_types=1);

namespace GoErp\Operations;

use PDO;
use RuntimeException;
use Throwable;

/** One-off removal, optionally preserving the two specifically reviewed creator links. */
final class RemoveSmartVisionAccount
{
    public const EMAIL = 'admin@smartvision.com';

    /** Report counts and relationship names only; credentials and business rows never leave the database. */
    public static function inspect(PDO $db): array
    {
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($driver, ['mysql', 'sqlite'], true) || $db->inTransaction()) {
            throw new RuntimeException('SMARTVISION_UNSUPPORTED_CONNECTION');
        }
        $previousReadOnly = null;
        if ($driver === 'mysql') {
            $db->exec('SET TRANSACTION READ ONLY');
        } else {
            $previousReadOnly = (int) $db->query('PRAGMA query_only')->fetchColumn();
            $db->exec('PRAGMA query_only=ON');
        }
        try {
            $db->beginTransaction();
            $query = $db->prepare('SELECT * FROM users WHERE LOWER(TRIM(email)) = ?');
            $query->execute([self::EMAIL]);
            $rows = $query->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) === 0) {
                return ['status' => 'SMARTVISION_ACCOUNT_ALREADY_ABSENT'];
            }
            if (count($rows) !== 1) {
                throw new RuntimeException('SMARTVISION_AMBIGUOUS_EMAIL');
            }
            $target = $rows[0];
            if ((int) $target['id'] <= 1 || $target['account_type'] !== 'admin'
                || strtolower(trim((string) $target['name'])) !== 'smartvision') {
                throw new RuntimeException('SMARTVISION_IDENTITY_MISMATCH');
            }
            $relationships = self::relationships($db, $driver);
            $linked = [];
            foreach ($relationships['references'] as $group) {
                $filter = self::referenceFilter($group, $target);
                if ($filter === null) {
                    continue;
                }
                [$where, $values] = $filter;
                $query = $db->prepare('SELECT COUNT(*) FROM '.$group['table'].' WHERE '.$where);
                $query->execute($values);
                $count = (int) $query->fetchColumn();
                if ($count > 0) {
                    $linked[] = ['table' => $group['name'], 'columns' => $group['columns'],
                        'rows' => $count, 'on_delete' => $group['on_delete']];
                }
            }
            return ['status' => 'SMARTVISION_LINKS_INSPECTED', 'read_only' => true,
                'target' => ['id' => (int) $target['id'], 'email' => self::EMAIL, 'account_type' => 'admin', 'status' => $target['status']],
                'owner_active' => (int) $db->query("SELECT id FROM users WHERE id=1 AND account_type='admin' AND status='accepted'")->fetchColumn() === 1,
                'foreign_keys_checked' => count($relationships['references']), 'linked' => $linked,
                'delete_triggers' => $relationships['triggers']];
        } finally {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if ($previousReadOnly !== null) {
                $db->exec('PRAGMA query_only='.$previousReadOnly);
            }
        }
    }

    public static function run(PDO $db, string $backupDirectory, callable $encrypt, bool $preserveReviewedLinks = false): array
    {
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($driver, ['mysql', 'sqlite'], true) || $db->inTransaction()) {
            throw new RuntimeException('SMARTVISION_UNSUPPORTED_CONNECTION');
        }
        if ($driver === 'mysql') {
            $engine = $db->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users'")->fetchColumn();
            if (strtolower((string) $engine) !== 'innodb') {
                throw new RuntimeException('SMARTVISION_TRANSACTIONAL_USERS_REQUIRED');
            }
        }
        $lock = $driver === 'mysql' ? ' FOR UPDATE' : '';
        $db->beginTransaction();
        try {
            $query = $db->prepare('SELECT * FROM users WHERE LOWER(TRIM(email)) = ?'.$lock);
            $query->execute([self::EMAIL]);
            $rows = $query->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) === 0) {
                $db->rollBack();
                return ['status' => 'SMARTVISION_ACCOUNT_ALREADY_ABSENT'];
            }
            if (count($rows) !== 1) {
                throw new RuntimeException('SMARTVISION_AMBIGUOUS_EMAIL');
            }
            $target = $rows[0];
            if ((int) $target['id'] <= 1 || $target['account_type'] !== 'admin'
                || strtolower(trim((string) $target['name'])) !== 'smartvision') {
                throw new RuntimeException('SMARTVISION_IDENTITY_MISMATCH');
            }
            $owner = $db->query("SELECT id FROM users WHERE id=1 AND account_type='admin' AND status='accepted'".$lock)->fetchColumn();
            if ((int) $owner !== 1) {
                throw new RuntimeException('SMARTVISION_ACTIVE_OWNER_REQUIRED');
            }
            $reassignment = $preserveReviewedLinks ? self::reviewedReassignment($db, $driver, $target) : [];
            if (!$preserveReviewedLinks) {
                self::assertNoDependentRowsOrTriggers($db, $driver, $target);
            }

            // The encrypted row is retained outside public_html before the only DELETE.
            $payload = ['format' => $preserveReviewedLinks ? 2 : 1, 'created_at' => gmdate('c'), 'table' => 'users', 'row' => $target];
            if ($preserveReviewedLinks) {
                $payload['reassignment'] = ['from_user_id' => 560, 'to_user_id' => 1, 'column' => 'added_by', 'tables' => $reassignment];
            }
            $json = json_encode($payload, JSON_THROW_ON_ERROR);
            $encrypted = $encrypt($json);
            if (!is_string($encrypted) || $encrypted === '' || $encrypted === $json) {
                throw new RuntimeException('SMARTVISION_ENCRYPTED_BACKUP_REQUIRED');
            }
            if (!is_dir($backupDirectory) || is_link($backupDirectory)
                || ((fileperms($backupDirectory) & 0077) !== 0)) {
                throw new RuntimeException('SMARTVISION_PRIVATE_BACKUP_DIRECTORY_REQUIRED');
            }
            $backup = $backupDirectory.'/smartvision-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(8)).'.enc';
            $previousMask = umask(0077);
            try {
                $file = @fopen($backup, 'xb');
            } finally {
                umask($previousMask);
            }
            if ($file === false) {
                throw new RuntimeException('SMARTVISION_BACKUP_WRITE_FAILED');
            }
            try {
                $offset = 0;
                while ($offset < strlen($encrypted)) {
                    $written = fwrite($file, substr($encrypted, $offset));
                    if ($written === false || $written === 0) {
                        throw new RuntimeException('SMARTVISION_BACKUP_WRITE_FAILED');
                    }
                    $offset += $written;
                }
                if (!fflush($file) || !fsync($file)) {
                    throw new RuntimeException('SMARTVISION_BACKUP_WRITE_FAILED');
                }
            } finally {
                fclose($file);
            }

            foreach ($reassignment as $table) {
                $keepTimestamp = array_key_exists('updated_at', $table['rows'][0]) ? ', updated_at=updated_at' : '';
                $update = $db->prepare('UPDATE '.$table['table'].' SET added_by=?'.$keepTimestamp.' WHERE id=? AND added_by=?');
                foreach ($table['rows'] as $row) {
                    $update->execute([1, $row['id'], 560]);
                    if ($update->rowCount() !== 1) {
                        throw new RuntimeException('SMARTVISION_REASSIGNMENT_COUNT_MISMATCH');
                    }
                }
                self::verifyReassignment($db, $table);
            }
            // Keep every foreign key enabled. Any new/unreviewed relationship rolls back the whole operation.
            self::assertNoDependentRowsOrTriggers($db, $driver, $target);
            $delete = $db->prepare('DELETE FROM users WHERE id=? AND email=? AND account_type=? AND name=?');
            $delete->execute([$target['id'], $target['email'], 'admin', $target['name']]);
            if ($delete->rowCount() !== 1) {
                throw new RuntimeException('SMARTVISION_DELETE_COUNT_MISMATCH');
            }
            $query->execute([self::EMAIL]);
            if ($query->fetch() !== false) {
                throw new RuntimeException('SMARTVISION_REMOVAL_NOT_VERIFIED');
            }
            foreach ($reassignment as $name => $table) {
                if ($name === 'users') {
                    $table['rows'] = array_values(array_filter($table['rows'], static fn(array $row): bool => (int) $row['id'] !== 560));
                }
                self::verifyReassignment($db, $table);
            }
            $db->commit();
            $result = ['status' => 'SMARTVISION_ACCOUNT_DELETED', 'id' => (int) $target['id'], 'email' => self::EMAIL, 'owner_preserved' => true];
            if ($preserveReviewedLinks) {
                $result['reassigned_to_user_id'] = 1;
                $result['preserved_links'] = array_map(static fn(array $table): int => count($table['rows']), $reassignment);
            }
            return $result;
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
    }

    private static function reviewedReassignment(PDO $db, string $driver, array $target): array
    {
        if ((int) $target['id'] !== 560) {
            throw new RuntimeException('SMARTVISION_REVIEWED_ID_CHANGED');
        }
        if ($driver === 'mysql') {
            $engine = $db->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pending_vendors'")->fetchColumn();
            if (strtolower((string) $engine) !== 'innodb') {
                throw new RuntimeException('SMARTVISION_TRANSACTIONAL_LINKS_REQUIRED');
            }
            $triggers = $db->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE EVENT_OBJECT_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE IN ('users','pending_vendors') AND EVENT_MANIPULATION='UPDATE'")->fetchColumn();
            $incoming = $db->query("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IN ('users','pending_vendors') AND REFERENCED_COLUMN_NAME='added_by'")->fetchColumn();
        } else {
            $triggers = $db->query("SELECT COUNT(*) FROM sqlite_master WHERE type='trigger' AND tbl_name IN ('users','pending_vendors')")->fetchColumn();
            $incoming = 0;
            foreach ($db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN) as $table) {
                foreach ($db->query('PRAGMA foreign_key_list('.self::quote($table).')')->fetchAll(PDO::FETCH_ASSOC) as $reference) {
                    if (in_array($reference['table'], ['users', 'pending_vendors'], true) && $reference['to'] === 'added_by') {
                        $incoming++;
                    }
                }
            }
        }
        if ((int) $triggers !== 0 || (int) $incoming !== 0) {
            throw new RuntimeException('SMARTVISION_UPDATE_SIDE_EFFECTS_REQUIRE_REVIEW');
        }
        $relationships = self::relationships($db, $driver);
        if ($relationships['triggers'] !== []) {
            throw new RuntimeException('SMARTVISION_DELETE_TRIGGER_REQUIRES_REVIEW');
        }
        $expected = ['pending_vendors' => 9, 'users' => 1];
        $reviewed = [];
        $lock = $driver === 'mysql' ? ' FOR UPDATE' : '';
        foreach ($relationships['references'] as $group) {
            $approved = $group['local'] && isset($expected[$group['name']])
                && $group['columns'] === [['added_by', 'id']] && $group['on_delete'] === 'CASCADE';
            if ($approved) {
                $reviewed[$group['name']] = ['table' => $group['table']];
                continue;
            }
            $filter = self::referenceFilter($group, $target);
            if ($filter !== null) {
                [$where, $values] = $filter;
                $query = $db->prepare('SELECT 1 FROM '.$group['table'].' WHERE '.$where.' LIMIT 1'.$lock);
                $query->execute($values);
                if ($query->fetchColumn() !== false) {
                    throw new RuntimeException('SMARTVISION_UNREVIEWED_LINKS');
                }
            }
        }
        // The exact 9 + 1 rows were reported by the operator's live read-only inspection.
        $result = [];
        foreach ($expected as $name => $count) {
            if (!isset($reviewed[$name])) {
                throw new RuntimeException('SMARTVISION_REASSIGNMENT_SCHEMA_CHANGED');
            }
            $query = $db->prepare('SELECT * FROM '.$reviewed[$name]['table'].' WHERE added_by=? ORDER BY id'.$lock);
            $query->execute([560]);
            $rows = $query->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) !== $count || !array_key_exists('id', $rows[0])) {
                throw new RuntimeException('SMARTVISION_REASSIGNMENT_STATE_CHANGED');
            }
            $result[$name] = ['table' => $reviewed[$name]['table'], 'rows' => $rows];
        }
        return $result;
    }

    private static function verifyReassignment(PDO $db, array $table): void
    {
        $query = $db->prepare('SELECT * FROM '.$table['table'].' WHERE id=?');
        foreach ($table['rows'] as $before) {
            $query->execute([$before['id']]);
            $after = $query->fetch(PDO::FETCH_ASSOC);
            if ($after === false || (string) $after['added_by'] !== '1') {
                throw new RuntimeException('SMARTVISION_REASSIGNMENT_NOT_VERIFIED');
            }
            unset($before['added_by'], $after['added_by']);
            if ($before !== $after) {
                throw new RuntimeException('SMARTVISION_LINKED_DATA_CHANGED');
            }
        }
    }

    private static function relationships(PDO $db, string $driver): array
    {
        $groups = [];
        if ($driver === 'mysql') {
            $triggers = $db->query("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE EVENT_OBJECT_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE='users' AND EVENT_MANIPULATION='DELETE' ORDER BY TRIGGER_NAME")->fetchAll(PDO::FETCH_COLUMN);
            $references = $db->query("SELECT k.TABLE_SCHEMA,k.TABLE_NAME,k.CONSTRAINT_NAME,k.COLUMN_NAME,k.REFERENCED_COLUMN_NAME,r.DELETE_RULE,(k.TABLE_SCHEMA=DATABASE()) AS IS_LOCAL FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME WHERE k.REFERENCED_TABLE_SCHEMA=DATABASE() AND k.REFERENCED_TABLE_NAME='users' ORDER BY k.TABLE_SCHEMA,k.TABLE_NAME,k.CONSTRAINT_NAME,k.ORDINAL_POSITION")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($references as $reference) {
                $key = $reference['TABLE_SCHEMA'].'.'.$reference['TABLE_NAME'].'.'.$reference['CONSTRAINT_NAME'];
                $groups[$key]['table'] = self::quote($reference['TABLE_SCHEMA']).'.'.self::quote($reference['TABLE_NAME']);
                $groups[$key]['name'] = $reference['TABLE_NAME'];
                $groups[$key]['local'] = (bool) $reference['IS_LOCAL'];
                $groups[$key]['on_delete'] = $reference['DELETE_RULE'];
                $groups[$key]['columns'][] = [$reference['COLUMN_NAME'], $reference['REFERENCED_COLUMN_NAME']];
            }
        } else {
            // SQLite is used only by the disposable regression fixtures.
            $triggers = $db->query("SELECT name FROM sqlite_master WHERE type='trigger' AND tbl_name='users' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
            $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($tables as $table) {
                foreach ($db->query('PRAGMA foreign_key_list('.self::quote($table).')')->fetchAll(PDO::FETCH_ASSOC) as $reference) {
                    if (strtolower($reference['table']) !== 'users') {
                        continue;
                    }
                    $key = $table.'.'.$reference['id'];
                    $groups[$key]['table'] = self::quote($table);
                    $groups[$key]['name'] = $table;
                    $groups[$key]['local'] = true;
                    $groups[$key]['on_delete'] = $reference['on_delete'];
                    $groups[$key]['columns'][] = [$reference['from'], $reference['to'] ?: 'id'];
                }
            }
        }
        return ['references' => array_values($groups), 'triggers' => $triggers];
    }

    private static function assertNoDependentRowsOrTriggers(PDO $db, string $driver, array $target): void
    {
        $relationships = self::relationships($db, $driver);
        if ($relationships['triggers'] !== []) {
            throw new RuntimeException('SMARTVISION_DELETE_TRIGGER_REQUIRES_REVIEW');
        }
        foreach ($relationships['references'] as $group) {
            $filter = self::referenceFilter($group, $target);
            if ($filter === null) {
                continue;
            }
            [$where, $values] = $filter;
            $query = $db->prepare('SELECT 1 FROM '.$group['table'].' WHERE '.$where.' LIMIT 1'.($driver === 'mysql' ? ' FOR UPDATE' : ''));
            $query->execute($values);
            if ($query->fetchColumn() !== false) {
                throw new RuntimeException('SMARTVISION_LINKED_ROWS_REQUIRE_REVIEW');
            }
        }
    }

    private static function referenceFilter(array $group, array $target): ?array
    {
        $where = $values = [];
        foreach ($group['columns'] as [$child, $parent]) {
            if (!array_key_exists($parent, $target)) {
                throw new RuntimeException('SMARTVISION_UNKNOWN_REFERENCE');
            }
            // A composite foreign key with a NULL component does not reference a row.
            if ($target[$parent] === null) {
                return null;
            }
            $where[] = self::quote($child).' = ?';
            $values[] = $target[$parent];
        }
        return [implode(' AND ', $where), $values];
    }

    private static function quote(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    ini_set('display_errors', '0');
    ini_set('log_errors', '0');
    try {
        if ($argc !== 4 || !in_array($argv[3], ['--apply', '--inspect', '--preserve-links-and-delete'], true)
            || realpath($argv[1]) !== '/home/fasakha/public_html'
            || ($argv[3] !== '--inspect' && realpath($argv[2]) !== '/home/fasakha/go-erp-account-backups')) {
            throw new RuntimeException('SMARTVISION_OPERATOR_ARGUMENTS_REQUIRED');
        }
        $root = $argv[1];
        chdir($root);
        require $root.'/vendor/autoload.php';
        $app = require $root.'/bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $pdo = $app->make('db')->connection()->getPdo();
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new RuntimeException('SMARTVISION_MYSQL_REQUIRED');
        }
        $result = $argv[3] === '--inspect' ? RemoveSmartVisionAccount::inspect($pdo)
            : RemoveSmartVisionAccount::run($pdo, $argv[2], static fn(string $json): string => \Illuminate\Support\Facades\Crypt::encryptString($json), $argv[3] === '--preserve-links-and-delete');
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
    } catch (Throwable $error) {
        $code = $error instanceof RuntimeException && preg_match('/^SMARTVISION_[A-Z_]+$/D', $error->getMessage())
            ? $error->getMessage() : 'SMARTVISION_DATABASE_OR_RUNTIME_CHECK_FAILED';
        fwrite(STDERR, $code.PHP_EOL);
        exit(1);
    }
}
