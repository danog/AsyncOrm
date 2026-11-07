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
 * @author    Alexander Pankratov <alexander@i-c-a.su>
 * @copyright 2016-2024 Daniil Gentili <daniil@daniil.it>
 * @copyright 2016-2024 Alexander Pankratov <alexander@i-c-a.su>
 * @license   https://opensource.org/license/apache-2-0 Apache 2.0
 * @link https://github.com/danog/AsyncOrm AsyncOrm documentation
 */

namespace danog\AsyncOrm\Internal\Driver;

use Amp\Mysql\MysqlConnectionPool;
use Amp\Sql\SqlResult;
use Amp\Sync\LocalKeyedMutex;
use AssertionError;
use danog\AsyncOrm\DbArrayBuilder;
use danog\AsyncOrm\Driver\Mysql;
use danog\AsyncOrm\Driver\SqlArray;
use danog\AsyncOrm\Internal\Serializer\BoolInt;
use danog\AsyncOrm\Internal\Serializer\FloatString;
use danog\AsyncOrm\Internal\Serializer\Passthrough;
use danog\AsyncOrm\KeyType;
use danog\AsyncOrm\Serializer;
use danog\AsyncOrm\ValueType;
use PDO;
use Revolt\EventLoop;

/**
 * MySQL database backend.
 *
 * @internal
 *
 * @template TKey as array-key
 * @template TValue
 * @extends SqlArray<TKey, TValue>
 */
final class MysqlArray extends SqlArray
{
    /** @var array<list{MysqlConnectionPool, \PDO, int}> */
    private static array $connections = [];

    private static ?LocalKeyedMutex $mutex = null;

    /** Room for the rest of a statement: its quoted key (at most 255 characters, each escaped) and the X''. */
    private const STATEMENT_OVERHEAD = 4096;

    // We're forced to use quoting (just like PDO does internally when using prepares) because native MySQL prepares are extremely slow.
    protected PDO $pdo;

    /** Whether values are stored in a binary (BLOB) column: they're then sent as hex literals. */
    private bool $binaryValues;

    /** The server's max_allowed_packet: no statement may be longer. */
    private int $maxAllowedPacket;

    /**
     * @psalm-suppress MethodSignatureMismatch
     * @param Serializer<TValue> $serializer
     */
    public function __construct(DbArrayBuilder $config, Serializer $serializer)
    {
        $settings = $config->settings;
        \assert($settings instanceof \danog\AsyncOrm\Settings\MysqlSettings);

        self::$mutex ??= new LocalKeyedMutex;
        $dbKey = $settings->getDbIdentifier();
        $lock = self::$mutex->acquire($dbKey);

        try {
            if (!isset(self::$connections[$dbKey])) {
                $db = $settings->config->getDatabase();
                $connection = new MysqlConnectionPool($settings->config->withDatabase(null), 1);
                $connection->query("
                    CREATE DATABASE IF NOT EXISTS `{$db}`
                    CHARACTER SET 'utf8mb4' 
                    COLLATE 'utf8mb4_general_ci'
                ");
                try {
                    $max = $connection->query("SHOW VARIABLES LIKE 'max_connections'")->fetchRow();
                    \assert(\is_array($max));
                    $max = $max['Value'] ?? null;
                    \assert(\is_int($max));
                    if ($max < 100000) {
                        $connection->query("SET GLOBAL max_connections = 100000");
                    }
                    // @codeCoverageIgnoreStart
                } catch (\Throwable) {
                }
                // @codeCoverageIgnoreEnd
                $connection->close();

                $host = $settings->config->getHost();
                $port = $settings->config->getPort();
                // @codeCoverageIgnoreStart
                if (!\extension_loaded('pdo_mysql')) {
                    throw new AssertionError("PDO is needed for the mysql backend!");
                }

                $pdo = new PDO(
                    $host[0] === '/'
                        ? "mysql:unix_socket={$host};charset=utf8mb4"
                        : "mysql:host={$host};port={$port};charset=utf8mb4",
                    $settings->config->getUser(),
                    $settings->config->getPassword(),
                );
                // @codeCoverageIgnoreEnd

                // 0 (nothing fits: always quote) if it can't be read.
                $maxAllowedPacket = (int) $pdo->query('SELECT @@max_allowed_packet')->fetchColumn();
                self::$connections[$dbKey] = [
                    new MysqlConnectionPool($settings->config, $settings->maxConnections, $settings->idleTimeout),
                    $pdo,
                    $maxAllowedPacket,
                ];
            }
        } finally {
            EventLoop::queue($lock->release(...));
        }

        [$db, $pdo, $this->maxAllowedPacket] = self::$connections[$dbKey];
        $this->pdo = $pdo;

        $keyType = match ($config->keyType) {
            KeyType::STRING_OR_INT => "VARCHAR(255)",
            KeyType::STRING => "VARCHAR(255)",
            KeyType::INT => "BIGINT",
        };
        $valueType = match ($config->valueType) {
            ValueType::INT => "BIGINT",
            ValueType::STRING => "VARCHAR(255)",
            ValueType::OBJECT => "MEDIUMBLOB",
            ValueType::FLOAT => "FLOAT(53)",
            ValueType::BOOL => "BIT(1)",
            ValueType::SCALAR, ValueType::OBJECT => "MEDIUMBLOB"
        };
        $this->binaryValues = $valueType === "MEDIUMBLOB";
        /** @var Serializer<TValue> */
        $serializer = match ($config->valueType) {
            ValueType::SCALAR, ValueType::OBJECT => $serializer,
            ValueType::BOOL => new BoolInt,
            // Not a float parameter, which execute() would print with 14 digits.
            ValueType::FLOAT => new FloatString,
            default => new Passthrough
        };
        /** @psalm-suppress InvalidArgument */
        parent::__construct(
            $config,
            $serializer,
            $db,
            "SELECT `value` FROM `{$config->table}` WHERE `key` = :index LIMIT 1",
            "
                REPLACE INTO `{$config->table}` 
                SET `key` = :index, `value` = :value 
            ",
            "
                DELETE FROM `{$config->table}`
                WHERE `key` = :index
            ",
            "
                SELECT count(`key`) as `count` FROM `{$config->table}`
            ",
            "
                SELECT `key`, `value` FROM `{$config->table}`
            ",
            "
                DELETE FROM `{$config->table}`
            "
        );

        $db->query("
            CREATE TABLE IF NOT EXISTS `{$config->table}`
            (
                `key` $keyType PRIMARY KEY NOT NULL,
                `value` $valueType NOT NULL
            )
            ENGINE = InnoDB
            CHARACTER SET 'utf8mb4' 
            COLLATE 'utf8mb4_general_ci'
        ");

        $result = $db->query("DESCRIBE `{$config->table}`");
        while ($column = $result->fetchRow()) {
            ['Field' => $key, 'Type' => $type, 'Null' => $null] = $column;
            \assert(\is_string($key));
            \assert(\is_string($type));
            $type = \strtoupper($type);
            if (\str_starts_with($type, 'BIGINT')) {
                $type = 'BIGINT';
            }
            if ($key === 'key') {
                $expected = $keyType;
            } elseif ($key === 'value') {
                $expected = $valueType;
            } else {
                // @codeCoverageIgnoreStart
                $db->query("ALTER TABLE `{$config->table}` DROP `$key`");
                continue;
                // @codeCoverageIgnoreEnd
            }
            if ($expected !== $type || $null !== 'NO') {
                $db->query("ALTER TABLE `{$config->table}` MODIFY `$key` $expected NOT NULL");
            }
        }

        if ($settings->optimizeIfWastedMb !== null) {
            $database = $settings->config->getDatabase();
            $result = $db->prepare("SELECT data_free FROM information_schema.tables WHERE table_schema=? AND table_name=?")
                ->execute([$database, $config->table])
                ->fetchRow();
            if ($result === null) {
                // @codeCoverageIgnoreStart
                throw new AssertionError("Result cannot be null!");
                // @codeCoverageIgnoreEnd
            }
            $result = $result['data_free'] ?? $result['DATA_FREE'];
            if (!\is_int($result)) {
                // @codeCoverageIgnoreStart
                throw new AssertionError("data_free must be an integer!");
                // @codeCoverageIgnoreEnd
            }
            if (($result >> 20) >= $settings->optimizeIfWastedMb) {
                $db->query("OPTIMIZE TABLE `{$config->table}`");
            }
        }
    }

    /** @psalm-impure */
    #[\Override]
    protected function getDatabaseIdentity(): ?string
    {
        $row = $this->db->query(
            "SELECT CONCAT_WS(CHAR(0), @@hostname, @@port, @@datadir, @@server_id, DATABASE()) AS id"
        )->fetchRow();
        return isset($row['id']) && \is_string($row['id']) ? $row['id'] : null;
    }

    /**
     * Perform async request to db.
     */
    #[\Override]
    protected function execute(string $sql, array $params = []): SqlResult
    {
        $replacements = [];
        foreach ($params as $key => $value) {
            if (!\is_string($value)) {
                $value = (string) $value;
            } elseif ($key === 'value' && $this->binaryValues
                // A hex literal doubles the value, quote() barely grows it (binary data has few bytes to
                // escape): it's only used if the statement still fits in a packet.
                && 2 * \strlen($value) + \strlen($sql) + self::STATEMENT_OVERHEAD <= $this->maxAllowedPacket
            ) {
                // Exact for a binary column, and much cheaper than quote(), which escapes
                // (and validates as utf8mb4) the serialized value byte by byte.
                $value = "X'".\bin2hex($value)."'";
            } else {
                $value = $this->pdo->quote($value);
            }
            $replacements[":$key"] = $value;
        }

        // One pass: a replaced value is never scanned again for another placeholder.
        return $this->db->query(\strtr($sql, $replacements));
    }

    #[\Override]
    protected function importFromTable(string $fromTable): void
    {
        if ($this->config->table === $fromTable) {
            return;
        }

        $this->db->query("
            REPLACE INTO `{$this->config->table}`
            SELECT * FROM `{$fromTable}`;
        ");

        $this->db->query("
            DROP TABLE `{$fromTable}`;
        ");
    }
}
