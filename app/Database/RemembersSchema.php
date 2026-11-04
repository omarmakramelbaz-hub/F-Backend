<?php

namespace App\Database;

/**
 * A schema builder that answers "is this table (or column) there?" from what it already knows (SchemaMemory) instead
 * of asking the database each time. Answers are the same as the database's; what changes is how often it is asked.
 */
trait RemembersSchema
{
    public function hasTable($table)
    {
        if ($this->connection->pretending()) return parent::hasTable($table);
        $scope = $this->schemaScope();
        $name = $this->connection->getTablePrefix().$table;
        $known = SchemaMemory::tables($scope);
        if (isset($known['tables'][$name])) return true;
        // Not known: the whole list is read again (one question for every table), at most once a minute.
        if (! SchemaMemory::stillMissing($known['complete_at'])) $known = $this->readAllTables($scope);
        if (isset($known['tables'][$name])) return true;
        // A name that differs only in its letters' case is left to the database to answer, as before.
        if (isset($known['folded'][strtolower($name)])) return parent::hasTable($table);
        return false;
    }

    public function getColumnListing($table)
    {
        if ($this->connection->pretending()) return parent::getColumnListing($table);
        $known = SchemaMemory::columns($this->schemaScope(), (string) $table);
        if ($known !== null && ($known['columns'] !== [] || SchemaMemory::stillMissing($known['at']))) return $known['columns'];
        return $this->readColumns($table)['columns'];
    }

    public function hasColumn($table, $column)
    {
        return $this->hasColumns($table, [$column]);
    }

    public function hasColumns($table, array $columns)
    {
        if ($this->connection->pretending()) return parent::hasColumns($table, $columns);
        $known = SchemaMemory::columns($this->schemaScope(), (string) $table) ?? $this->readColumns($table);
        if ($this->listed($known['columns'], $columns)) return true;
        // A column not in the list is looked for again once a minute: one added since is seen within that minute.
        if (SchemaMemory::stillMissing($known['at'])) return false;
        return $this->listed($this->readColumns($table)['columns'], $columns);
    }

    private function schemaScope(): string
    {
        return (string) $this->connection->getName().'|'.$this->connection->getDatabaseName().'|'.$this->connection->getTablePrefix();
    }

    private function readAllTables(string $scope): array
    {
        $names = [];
        foreach ($this->getAllTables() as $row) {
            $row = (array) $row;
            $name = $row['name'] ?? reset($row);
            if (is_string($name) && $name !== '') $names[] = $name;
        }
        SchemaMemory::rememberTables($scope, $names);
        return SchemaMemory::tables($scope);
    }

    private function readColumns($table): array
    {
        return SchemaMemory::rememberColumns($this->schemaScope(), (string) $table, parent::getColumnListing($table));
    }

    /** Whether every column is in the listing, compared as Laravel compares them (letters' case aside). */
    private function listed(array $listing, array $columns): bool
    {
        $listing = array_map('strtolower', $listing);
        foreach ($columns as $column) {
            if (! in_array(strtolower($column), $listing)) return false;
        }
        return true;
    }
}
