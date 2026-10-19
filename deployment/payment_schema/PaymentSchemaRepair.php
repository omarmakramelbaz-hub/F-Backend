<?php

/** Add the missing checkout amount without guessing historical financial data. */
final class PaymentSchemaRepair
{
    private const REQUIRED = ['id', 'user_id', 'order_id', 'status', 'created_at', 'updated_at',
        'transaction_id', 'intention_order_id'];
    private $pdo;
    private $database;
    private $table;

    public function __construct(\PDO $pdo, string $prefix = '')
    {
        if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new \RuntimeException('PAYMENT_SCHEMA_MYSQL_REQUIRED');
        }
        if (!preg_match('/\A[A-Za-z0-9_]*\z/', $prefix) || strlen($prefix . 'payments') > 64) {
            throw new \RuntimeException('PAYMENT_SCHEMA_INVALID_TABLE_PREFIX');
        }
        $this->pdo = $pdo;
        $this->database = $pdo->query('SELECT DATABASE()')->fetchColumn();
        if (!is_string($this->database) || $this->database === '') {
            throw new \RuntimeException('PAYMENT_SCHEMA_DATABASE_REQUIRED');
        }
        $this->table = $prefix . 'payments';
    }

    private function query(string $sql, array $parameters = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function inspect(): array
    {
        $tables = $this->query('SELECT TABLE_TYPE AS table_type FROM information_schema.TABLES '
            . 'WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$this->database, $this->table]);
        if (count($tables) !== 1 || $tables[0]['table_type'] !== 'BASE TABLE') {
            throw new \RuntimeException('PAYMENT_SCHEMA_PAYMENTS_TABLE_REQUIRED');
        }
        $columns = $this->query('SELECT COLUMN_NAME AS name, DATA_TYPE AS data_type, '
            . 'COLUMN_TYPE AS column_type, NUMERIC_PRECISION AS numeric_precision, '
            . 'NUMERIC_SCALE AS numeric_scale, IS_NULLABLE AS is_nullable, EXTRA AS extra '
            . 'FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$this->database, $this->table]);
        $byName = [];
        foreach ($columns as $column) $byName[$column['name']] = $column;
        if (array_diff(self::REQUIRED, array_keys($byName))) {
            throw new \RuntimeException('PAYMENT_SCHEMA_REQUIRED_COLUMNS_MISSING');
        }
        $amount = $byName['total_price'] ?? null;
        // Approximate floating point cannot preserve an exact payment amount.
        if ($amount !== null && (!in_array(strtolower($amount['data_type']), ['decimal', 'numeric'], true)
            || (int) $amount['numeric_scale'] !== 2
            || (int) $amount['numeric_precision'] - (int) $amount['numeric_scale'] < 10
            || preg_match('/(?:VIRTUAL|STORED|PERSISTENT) GENERATED/i', $amount['extra']))) {
            throw new \RuntimeException('PAYMENT_SCHEMA_EXISTING_AMOUNT_INCOMPATIBLE');
        }
        return ['ready' => $amount !== null, 'action' => $amount === null ? 'add_total_price' : 'none',
            'table' => $this->table, 'required_columns_ready' => true,
            'total_price' => $amount === null ? null : ['type' => $amount['column_type'],
                'nullable' => $amount['is_nullable'] === 'YES']];
    }

    public function apply(): array
    {
        // MySQL DDL commits implicitly. Never end an enclosing business transaction.
        if ($this->pdo->inTransaction()) {
            throw new \RuntimeException('PAYMENT_SCHEMA_TRANSACTION_NOT_ALLOWED');
        }
        $this->inspect();
        $lock = 'payment-schema:' . substr(hash('sha256', $this->database . ':' . $this->table), 0, 48);
        $acquired = $this->query('SELECT GET_LOCK(?, 5) AS acquired', [$lock]);
        if ((string) ($acquired[0]['acquired'] ?? '') !== '1') {
            throw new \RuntimeException('PAYMENT_SCHEMA_LOCK_UNAVAILABLE');
        }
        $timeouts = null;
        try {
            // This is also bounded when another connection holds a metadata lock.
            $timeouts = $this->pdo->query('SELECT @@SESSION.lock_wait_timeout AS metadata_wait, '
                . '@@SESSION.innodb_lock_wait_timeout AS row_wait')->fetch(\PDO::FETCH_ASSOC);
            $this->pdo->exec('SET SESSION lock_wait_timeout = 5');
            $this->pdo->exec('SET SESSION innodb_lock_wait_timeout = 5');
            $before = $this->inspect();
            if (!$before['ready']) {
                $this->pdo->exec('ALTER TABLE `' . $this->table . '` ADD COLUMN `total_price` '
                    . 'DECIMAL(15,2) UNSIGNED NULL DEFAULT NULL');
            }
            $after = $this->inspect();
            if (!$after['ready']) throw new \RuntimeException('PAYMENT_SCHEMA_VERIFICATION_FAILED');
            $after['action'] = $before['ready'] ? 'unchanged' : 'added_total_price';
            return $after;
        } finally {
            try {
                if (is_array($timeouts)) {
                    $this->pdo->exec('SET SESSION lock_wait_timeout = ' . (int) $timeouts['metadata_wait']);
                    $this->pdo->exec('SET SESSION innodb_lock_wait_timeout = ' . (int) $timeouts['row_wait']);
                }
            } finally {
                $this->query('SELECT RELEASE_LOCK(?) AS released', [$lock]);
            }
        }
    }
}
