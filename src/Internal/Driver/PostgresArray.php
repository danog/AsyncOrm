<?php declare(strict_types=1);

/**
 * This file is part of AsyncOrm.
 * AsyncOrm is free software: you can redistribute it and/or modify it under the terms of the GNU Affero General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.
 * AsyncOrm is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU Affero General Public License for more details.
 * You should have received a copy of the GNU General Public License along with AsyncOrm.
 * If not, see <http://www.gnu.org/licenses/>.
 *
 * @author    Daniil Gentili <daniil@daniil.it>
 * @author    Alexander Pankratov <alexander@i-c-a.su>
 * @copyright 2016-2024 Daniil Gentili <daniil@daniil.it>
 * @copyright 2016-2024 Alexander Pankratov <alexander@i-c-a.su>
 * @license   https://opensource.org/licenses/AGPL-3.0 AGPLv3
 * @link https://daniil.it/AsyncOrm AsyncOrm documentation
 */

namespace danog\AsyncOrm\Internal\Driver;

use Amp\Postgres\PostgresConnectionPool;
use Amp\Sync\LocalKeyedMutex;
use danog\AsyncOrm\Driver\SqlArray;
use danog\AsyncOrm\FieldConfig;
use danog\AsyncOrm\KeyType;
use danog\AsyncOrm\Serializer;
use danog\AsyncOrm\Serializer\ByteaSerializer;
use danog\AsyncOrm\Settings\Postgres;
use danog\AsyncOrm\ValueType;
use Revolt\EventLoop;

/**
 * Postgres database backend.
 *
 * @internal
 * @template TKey as array-key
 * @template TValue
 * @extends SqlArray<TKey, TValue>
 */
class PostgresArray extends SqlArray
{
    /** @var array<PostgresConnectionPool> */
    private static array $connections = [];

    private static ?LocalKeyedMutex $mutex = null;
    public function __construct(FieldConfig $config, Serializer $serializer)
    {
        self::$mutex ??= new LocalKeyedMutex;
        $settings = $config->settings;
        \assert($settings instanceof Postgres);

        $dbKey = $settings->getDbIdentifier();
        $lock = self::$mutex->acquire($dbKey);

        try {
            if (!isset(self::$connections[$dbKey])) {
                $db = $settings->config->getDatabase();
                $user = $settings->config->getUser();
                $connection =  new PostgresConnectionPool($settings->config->withDatabase(null));

                $result = $connection->query("SELECT * FROM pg_database WHERE datname = '{$db}'");

                // Replace with getRowCount once it gets fixed
                if (!\iterator_count($result)) {
                    $connection->query("
                        CREATE DATABASE {$db}
                        OWNER {$user}
                        ENCODING utf8
                    ");
                }
                $connection->close();

                self::$connections[$dbKey] = new PostgresConnectionPool($settings->config, $settings->maxConnections, $settings->idleTimeoutgetIdleTimeout());
            }
        } finally {
            EventLoop::queue($lock->release(...));
        }

        $connection = self::$connections[$dbKey];

        $keyType = match ($config->annotation->keyType) {
            KeyType::STRING_OR_INT => "VARCHAR(255)",
            KeyType::STRING => "VARCHAR(255)",
            KeyType::INT => "BIGINT",
        };
        $valueType = match ($config->annotation->valueType) {
            ValueType::INT => "BIGINT",
            ValueType::STRING => "VARCHAR(255)",
            default => "BYTEA",
        };
        $serializer = match ($config->annotation->valueType) {
            ValueType::INT, ValueType::STRING => $serializer,
            default => new ByteaSerializer($serializer)
        };

        $connection->query("
            CREATE TABLE IF NOT EXISTS \"bytea_{$config->table}\"
            (
                \"key\" $keyType PRIMARY KEY NOT NULL,
                \"value\" $valueType NOT NULL
            );            
        ");

        $result = $this->db->query("DESCRIBE \"bytea_{$config->table}\"");
        while ($column = $result->fetchRow()) {
            ['Field' => $key, 'Type' => $type, 'Null' => $null] = $column;
            $type = \strtoupper($type);
            if (\str_starts_with($type, 'BIGINT')) {
                $type = 'BIGINT';
            }
            if ($key === 'key') {
                $expected = $keyType;
            } elseif ($key === 'value') {
                $expected = $valueType;
            } else {
                $this->db->query("ALTER TABLE \"bytea_{$config->table}\" DROP \"$key\"");
            }
            if ($expected !== $type || $null !== 'NO') {
                $this->db->query("ALTER TABLE \"bytea_{$config->table}\" MODIFY \"$key\" $expected NOT NULL");
            }
        }

        parent::__construct(
            $config,
            $serializer,
            $connection,
            "SELECT value FROM \"bytea_{$config->table}\" WHERE key = :index",
            "
                INSERT INTO \"bytea_{$config->table}\"
                (key,value)
                VALUES (:index, :value)
                ON CONFLICT (key) DO UPDATE SET value = :value
            ",
            "
                DELETE FROM \"bytea_{$config->table}\"
                WHERE key = :index
            ",
            "SELECT count(key) as count FROM \"bytea_{$config->table}\"",
            "SELECT key, value FROM \"bytea_{$config->table}\"",
            "DELETE FROM \"bytea_{$config->table}\""
        );
    }

    protected function importFromTable(string $fromTable): void
    {
        $this->db->query(/** @lang PostgreSQL */ "
            DROP TABLE \"bytea_{$this->config->table}\";
        ");
        $this->db->query(/** @lang PostgreSQL */ "
            ALTER TABLE \"bytea_$fromTable\" RENAME TO \"bytea_{$this->config->table}\";
        ");
    }
}
