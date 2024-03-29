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

use Amp\Mysql\MysqlConnectionPool;
use Amp\Sql\SqlResult;
use Amp\Sync\LocalKeyedMutex;
use AssertionError;
use danog\AsyncOrm\Driver\Mysql;
use danog\AsyncOrm\Driver\SqlArray;
use danog\AsyncOrm\FieldConfig;
use danog\AsyncOrm\Internal\Serializer\BoolInt;
use danog\AsyncOrm\Internal\Serializer\Passthrough;
use danog\AsyncOrm\KeyType;
use danog\AsyncOrm\Serializer;
use danog\AsyncOrm\ValueType;
use PDO;
use Revolt\EventLoop;
use Webmozart\Assert\Assert;

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
    /** @var array<list{MysqlConnectionPool, \PDO}> */
    private static array $connections = [];

    private static ?LocalKeyedMutex $mutex = null;

    // We're forced to use quoting (just like PDO does internally when using prepares) because native MySQL prepares are extremely slow.
    protected PDO $pdo;

    /**
     * @param Serializer<TValue> $serializer
     */
    public function __construct(FieldConfig $config, Serializer $serializer)
    {
        $settings = $config->settings;
        \assert($settings instanceof \danog\AsyncOrm\Settings\Mysql);

        self::$mutex ??= new LocalKeyedMutex;
        $dbKey = $settings->getDbIdentifier();
        $lock = self::$mutex->acquire($dbKey);

        try {
            if (!isset(self::$connections[$dbKey])) {
                $db = $settings->config->getDatabase();
                $connection = new MysqlConnectionPool($settings->config->withDatabase(null));
                $connection->query("
                    CREATE DATABASE IF NOT EXISTS `{$db}`
                    CHARACTER SET 'utf8mb4' 
                    COLLATE 'utf8mb4_general_ci'
                ");
                try {
                    $max = (int) $connection->query("SHOW VARIABLES LIKE 'max_connections'")->fetchRow()['Value'];
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
                // @codeCoverageIgnoreEnd

                $pdo = new PDO(
                    $host[0] === '/'
                        ? "mysql:unix_socket={$host};charset=UTF8"
                        : "mysql:host={$host};port={$port};charset=UTF8",
                    $settings->config->getUser(),
                    $settings->config->getPassword(),
                );

                self::$connections[$dbKey] = [
                    new MysqlConnectionPool($settings->config, $settings->maxConnections, $settings->idleTimeout),
                    $pdo,
                ];
            }
        } finally {
            EventLoop::queue($lock->release(...));
        }

        [$db, $pdo] = self::$connections[$dbKey];
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
        /** @var Serializer<TValue> */
        $serializer = match ($config->valueType) {
            ValueType::SCALAR, ValueType::OBJECT => $serializer,
            ValueType::BOOL => new BoolInt,
            default => new Passthrough
        };

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
            $type = \strtoupper($type);
            if (\str_starts_with($type, 'BIGINT')) {
                $type = 'BIGINT';
            }
            if ($key === 'key') {
                $expected = $keyType;
            } elseif ($key === 'value') {
                $expected = $valueType;
            } else {
                $db->query("ALTER TABLE `{$config->table}` DROP `$key`");
                continue;
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
            Assert::notNull($result);
            $result = $result['data_free'] ?? $result['DATA_FREE'];
            Assert::integer($result, "Could not optimize table!");
            if (($result >> 20) >= $settings->optimizeIfWastedMb) {
                $db->query("OPTIMIZE TABLE `{$config->table}`");
            }
        }

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
    }

    /**
     * Perform async request to db.
     *
     */
    protected function execute(string $sql, array $params = []): SqlResult
    {
        foreach ($params as $key => $value) {
            if (\is_string($value)) {
                $value = $this->pdo->quote($value);
            } else {
                $value = (string) $value;
            }
            $sql = \str_replace(":$key", $value, $sql);
        }

        return $this->db->query($sql);
    }

    protected function importFromTable(string $fromTable): void
    {
        $this->db->query("
            REPLACE INTO `{$this->config->table}`
            SELECT * FROM `{$fromTable}`;
        ");

        $this->db->query("
            DROP TABLE `{$fromTable}`;
        ");
    }
}
