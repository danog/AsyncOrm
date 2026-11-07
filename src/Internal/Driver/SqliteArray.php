<?php declare(strict_types=1);

/**
 * Copyright 2024 Daniil Gentili.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 * @author    Daniil Gentili <daniil@daniil.it>
 * @copyright 2016-2023 Daniil Gentili <daniil@daniil.it>
 * @license   https://opensource.org/license/apache-2-0 Apache 2.0
 * @link https://github.com/danog/AsyncOrm AsyncOrm documentation
 */

namespace danog\AsyncOrm\Settings;

namespace danog\AsyncOrm\Internal\Driver;

use danog\AsyncOrm\DbArrayBuilder;
use danog\AsyncOrm\Driver\DriverArray;
use danog\AsyncOrm\Internal\Serializer\IntBool;
use danog\AsyncOrm\Internal\Serializer\Passthrough;
use danog\AsyncOrm\KeyType;
use danog\AsyncOrm\Serializer;
use danog\AsyncOrm\Settings\SqliteSettings;
use danog\AsyncOrm\ValueType;
use PDO;
use PDOStatement;

/**
 * SQLite database backend.
 *
 * Queries run directly from the event loop's thread: see SqliteConnection.
 *
 * @internal
 *
 * @template TKey as array-key
 * @template TValue
 * @extends DriverArray<TKey, TValue>
 */
final class SqliteArray extends DriverArray
{
    private const ITERATE_CHUNK = 256;

    private readonly SqliteConnection $connection;
    private readonly bool $intKey;
    private readonly int $bindType;
    private readonly bool $float;

    private string $t;
    private PDOStatement $get;
    private PDOStatement $set;
    private PDOStatement $unset;

    /**
     * @param Serializer<TValue> $serializer
     */
    public function __construct(DbArrayBuilder $config, Serializer $serializer)
    {
        $settings = $config->settings;
        \assert($settings instanceof SqliteSettings);

        $this->intKey = $config->keyType === KeyType::INT;
        $this->float = $config->valueType === ValueType::FLOAT;
        $this->bindType = match ($config->valueType) {
            // Serialized values are bytes: as TEXT, SQLite could treat them as UTF-8.
            ValueType::SCALAR, ValueType::OBJECT => PDO::PARAM_LOB,
            ValueType::INT, ValueType::BOOL => PDO::PARAM_INT,
            ValueType::STRING, ValueType::FLOAT => PDO::PARAM_STR,
        };
        /** @var Serializer<TValue> */
        $serializer = match ($config->valueType) {
            ValueType::SCALAR, ValueType::OBJECT => $serializer,
            ValueType::BOOL => new IntBool,
            default => new Passthrough
        };
        parent::__construct($config, $serializer);

        $this->connection = SqliteConnection::get($settings->path);
        $this->connection->setCheckpointInWorkers($settings->checkpointInWorkers);
        $this->setupTable();
    }

    private static function quote(string $identifier): string
    {
        return '"'.\str_replace('"', '""', $identifier).'"';
    }

    /**
     * Create the table, or rebuild it if its columns don't match the key and value types:
     * SQLite can't change the type of a column.
     */
    private function setupTable(): void
    {
        $config = $this->config;
        $keyType = match ($config->keyType) {
            KeyType::INT => 'INTEGER',
            KeyType::STRING, KeyType::STRING_OR_INT => 'TEXT',
        };
        $valueType = match ($config->valueType) {
            ValueType::INT, ValueType::BOOL => 'INTEGER',
            ValueType::STRING => 'TEXT',
            ValueType::FLOAT => 'REAL',
            ValueType::SCALAR, ValueType::OBJECT => 'BLOB',
        };
        $db = $this->connection->db;
        $t = $this->t = self::quote($config->table);
        $create = static fn (string $t): string => "CREATE TABLE IF NOT EXISTS $t (key $keyType PRIMARY KEY NOT NULL, value $valueType NOT NULL)";

        $this->connection->schema(static function () use ($db, $t, $create, $keyType, $valueType, $config): void {
            $db->exec($create($t));

            $columns = [];
            /** @var list<array{name: string, type: string}> */
            $rows = $db->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $columns[$row['name']] = \strtoupper($row['type']);
            }
            if ($columns === ['key' => $keyType, 'value' => $valueType]) {
                return;
            }
            $new = self::quote($config->table."__asyncorm_rebuild");
            $db->exec("DROP TABLE IF EXISTS $new");
            $db->exec($create($new));
            $db->exec("INSERT INTO $new (key, value) SELECT CAST(key AS $keyType), CAST(value AS $valueType) FROM $t");
            $db->exec("DROP TABLE $t");
            $db->exec("ALTER TABLE $new RENAME TO $t");
        });

        $this->get = $db->prepare("SELECT value FROM $t WHERE key = ?1");
        $this->set = $db->prepare("INSERT INTO $t (key, value) VALUES (?1, ?2) ON CONFLICT (key) DO UPDATE SET value = excluded.value");
        $this->unset = $db->prepare("DELETE FROM $t WHERE key = ?1");
    }

    private function bindKey(PDOStatement $stmt, string|int $key): void
    {
        if ($this->intKey) {
            $stmt->bindValue(1, (int) $key, PDO::PARAM_INT);
        } else {
            $stmt->bindValue(1, (string) $key, PDO::PARAM_STR);
        }
    }

    #[\Override]
    public function get(string|int $key): mixed
    {
        $value = $this->connection->read(function () use ($key): string|int|float|false {
            $stmt = $this->get;
            $this->bindKey($stmt, $key);
            try {
                $stmt->execute();
                /** @var string|int|float|false */
                $value = $stmt->fetchColumn();
                return $value;
            } finally {
                $stmt->closeCursor();
            }
        });
        return $value === false ? null : $this->serializer->deserialize($value);
    }

    #[\Override]
    public function set(string|int $key, mixed $value): void
    {
        /** @var scalar */
        $value = $this->serializer->serialize($value);
        if ($this->float) {
            // PDO binds floats as strings with `precision` digits (14 by default): 17 digits
            // always identify the same double, which SQLite converts the text back to.
            $value = \sprintf('%.17G', (float) $value);
        }
        $this->connection->write(function () use ($key, $value): void {
            $stmt = $this->set;
            $this->bindKey($stmt, $key);
            $stmt->bindValue(2, $value, $this->bindType);
            try {
                $stmt->execute();
            } finally {
                $stmt->closeCursor();
            }
        });
    }

    #[\Override]
    public function unset(string|int $key): void
    {
        $this->connection->write(function () use ($key): void {
            $stmt = $this->unset;
            $this->bindKey($stmt, $key);
            try {
                $stmt->execute();
            } finally {
                $stmt->closeCursor();
            }
        });
    }

    #[\Override]
    public function count(): int
    {
        return $this->connection->read(fn (): int => (int) $this->connection->db->query("SELECT count(*) FROM {$this->t}")->fetchColumn());
    }

    #[\Override]
    public function clear(): void
    {
        $this->connection->write(fn () => $this->connection->db->exec("DELETE FROM {$this->t}"));
    }

    /**
     * Iterate in chunks, ordered by key: no statement stays open while other fibers run,
     * which would keep a read transaction open on the shared connection.
     *
     * @return \Traversable<TKey, TValue>
     */
    #[\Override]
    public function getIterator(): \Traversable
    {
        $n = self::ITERATE_CHUNK;
        /** @var TKey|null */
        $last = null;
        do {
            $rows = $this->connection->read(function () use ($n, $last): array {
                $t = $this->t;
                $stmt = $this->connection->db->prepare($last === null
                    ? "SELECT key, value FROM $t ORDER BY key LIMIT $n"
                    : "SELECT key, value FROM $t WHERE key > ?1 ORDER BY key LIMIT $n");
                if ($last !== null) {
                    $this->bindKey($stmt, $last);
                }
                $stmt->execute();
                /** @var list<list{TKey, scalar}> */
                $rows = $stmt->fetchAll(PDO::FETCH_NUM);
                return $rows;
            });
            foreach ($rows as [$key, $value]) {
                $last = $key;
                yield $key => $this->serializer->deserialize($value);
            }
        } while (\count($rows) === $n);
    }

    /**
     * Replace this array's table with another table of the same database.
     *
     * @internal
     */
    protected function importFromTable(string $fromTable): void
    {
        if ($this->config->table === $fromTable) {
            return;
        }
        $db = $this->connection->db;
        $from = self::quote($fromTable);
        $t = $this->t;
        $this->connection->schema(static function () use ($db, $from, $t): void {
            $db->exec("DROP TABLE $t");
            $db->exec("ALTER TABLE $from RENAME TO $t");
        });
        // The renamed table may have other column types.
        $this->setupTable();
    }

    /**
     * @internal
     */
    protected function getDatabaseIdentity(): ?string
    {
        return $this->connection->getIdentity();
    }
}
