<?php
declare(strict_types=1);

namespace GoErp\Operations;

use PDO;
use RuntimeException;
use Throwable;

/** One-off, operator-authorized removal. No application source or business rows change. */
final class RemoveSmartVisionAccount
{
    public const EMAIL = 'admin@smartvision.com';

    public static function run(PDO $db, string $backupDirectory, callable $encrypt): array
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
            self::assertNoDependentRowsOrTriggers($db, $driver, $target);

            // The encrypted row is retained outside public_html before the only DELETE.
            $json = json_encode(['format' => 1, 'created_at' => gmdate('c'), 'table' => 'users', 'row' => $target], JSON_THROW_ON_ERROR);
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

            $delete = $db->prepare('DELETE FROM users WHERE id=? AND email=? AND account_type=? AND name=?');
            $delete->execute([$target['id'], $target['email'], 'admin', $target['name']]);
            if ($delete->rowCount() !== 1) {
                throw new RuntimeException('SMARTVISION_DELETE_COUNT_MISMATCH');
            }
            $query->execute([self::EMAIL]);
            if ($query->fetch() !== false) {
                throw new RuntimeException('SMARTVISION_REMOVAL_NOT_VERIFIED');
            }
            $db->commit();
            return ['status' => 'SMARTVISION_ACCOUNT_DELETED', 'id' => (int) $target['id'], 'email' => self::EMAIL, 'owner_preserved' => true];
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
    }

    private static function assertNoDependentRowsOrTriggers(PDO $db, string $driver, array $target): void
    {
        $groups = [];
        if ($driver === 'mysql') {
            $triggers = $db->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE EVENT_OBJECT_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE='users' AND EVENT_MANIPULATION='DELETE'")->fetchColumn();
            $references = $db->query("SELECT TABLE_SCHEMA,TABLE_NAME,CONSTRAINT_NAME,COLUMN_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='users' ORDER BY TABLE_SCHEMA,TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($references as $reference) {
                $key = $reference['TABLE_SCHEMA'].'.'.$reference['TABLE_NAME'].'.'.$reference['CONSTRAINT_NAME'];
                $groups[$key]['table'] = self::quote($reference['TABLE_SCHEMA']).'.'.self::quote($reference['TABLE_NAME']);
                $groups[$key]['columns'][] = [$reference['COLUMN_NAME'], $reference['REFERENCED_COLUMN_NAME']];
            }
        } else {
            // SQLite is used only by the disposable regression fixtures.
            $triggers = $db->query("SELECT COUNT(*) FROM sqlite_master WHERE type='trigger' AND tbl_name='users'")->fetchColumn();
            $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($tables as $table) {
                foreach ($db->query('PRAGMA foreign_key_list('.self::quote($table).')')->fetchAll(PDO::FETCH_ASSOC) as $reference) {
                    if (strtolower($reference['table']) !== 'users') {
                        continue;
                    }
                    $key = $table.'.'.$reference['id'];
                    $groups[$key]['table'] = self::quote($table);
                    $groups[$key]['columns'][] = [$reference['from'], $reference['to'] ?: 'id'];
                }
            }
        }
        if ((int) $triggers !== 0) {
            throw new RuntimeException('SMARTVISION_DELETE_TRIGGER_REQUIRES_REVIEW');
        }
        foreach ($groups as $group) {
            $where = $values = [];
            foreach ($group['columns'] as [$child, $parent]) {
                if (!array_key_exists($parent, $target)) {
                    throw new RuntimeException('SMARTVISION_UNKNOWN_REFERENCE');
                }
                // A composite foreign key with a NULL component does not reference a row.
                if ($target[$parent] === null) {
                    continue 2;
                }
                $where[] = self::quote($child).' = ?';
                $values[] = $target[$parent];
            }
            $query = $db->prepare('SELECT 1 FROM '.$group['table'].' WHERE '.implode(' AND ', $where).' LIMIT 1'.($driver === 'mysql' ? ' FOR UPDATE' : ''));
            $query->execute($values);
            if ($query->fetchColumn() !== false) {
                throw new RuntimeException('SMARTVISION_LINKED_ROWS_REQUIRE_REVIEW');
            }
        }
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
        if ($argc !== 4 || $argv[3] !== '--apply'
            || realpath($argv[1]) !== '/home/fasakha/public_html'
            || realpath($argv[2]) !== '/home/fasakha/go-erp-account-backups') {
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
        $result = RemoveSmartVisionAccount::run($pdo, $argv[2], static fn(string $json): string => \Illuminate\Support\Facades\Crypt::encryptString($json));
        echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
    } catch (Throwable $error) {
        $code = $error instanceof RuntimeException && preg_match('/^SMARTVISION_[A-Z_]+$/D', $error->getMessage())
            ? $error->getMessage() : 'SMARTVISION_DATABASE_OR_RUNTIME_CHECK_FAILED';
        fwrite(STDERR, $code.PHP_EOL);
        exit(1);
    }
}
