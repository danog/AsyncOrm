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

use Amp\Sql\SqlResult;
use Amp\Sync\LocalKeyedMutex;
use danog\AsyncOrm\Driver\Mysql;
use danog\AsyncOrm\Driver\SqlArray;
use danog\AsyncOrm\Exception;
use danog\AsyncOrm\Logger;
use danog\AsyncOrm\Settings\Database\Mysql as DatabaseMysql;
use PDO;
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
    // We're forced to use quoting (just like PDO does internally when using prepares) because native MySQL prepares are extremely slow.
    protected PDO $pdo;

    /**
     * Initialize on startup.
     */
    public function initStartup(): void
    {
        $this->setTable($this->table);
        $this->initConnection($this->dbSettings);
    }

    /**
     * Prepare statements.
     *
     * @param SqlArray::SQL_* $type
     */
    protected function getSqlQuery(int $type): string
    {
        switch ($type) {
            case SqlArray::SQL_GET:
                return "SELECT `value` FROM `{$this->table}` WHERE `key` = :index LIMIT 1";
            case SqlArray::SQL_SET:
                return "
                    REPLACE INTO `{$this->table}` 
                    SET `key` = :index, `value` = :value 
                ";
            case SqlArray::SQL_UNSET:
                return "
                    DELETE FROM `{$this->table}`
                    WHERE `key` = :index
                ";
            case SqlArray::SQL_COUNT:
                return "
                    SELECT count(`key`) as `count` FROM `{$this->table}`
                ";
            case SqlArray::SQL_ITERATE:
                return "
                    SELECT `key`, `value` FROM `{$this->table}`
                ";
            case SqlArray::SQL_CLEAR:
                return "
                    DELETE FROM `{$this->table}`
                ";
        }
        throw new Exception("An invalid statement type $type was provided!");
    }

    /**
     * Perform async request to db.
     *
     */
    protected function execute(string $sql, array $params = []): SqlResult
    {
        foreach ($params as $key => $value) {
            $value = $this->pdo->quote($value);
            $sql = \str_replace(":$key", $value, $sql);
        }

        return $this->db->query($sql);
    }

    /** @var array<list{MysqlConnectionPool, \PDO}> */
    private static array $connections = [];

    private static ?LocalKeyedMutex $mutex = null;
    /** @return list{MysqlConnectionPool, \PDO} */
    public static function getConnection(DatabaseMysql $settings): array
    {
        self::$mutex ??= new LocalKeyedMutex;
        $dbKey = $settings->getDbIdentifier();
        $lock = self::$mutex->acquire($dbKey);

        try {
            if (!isset(self::$connections[$dbKey])) {
                $host = \str_replace(['tcp://', 'unix://'], '', $settings->getUri());
                if ($host[0] === '/') {
                    $port = 0;
                } else {
                    $host = \explode(':', $host, 2);
                    if (\count($host) === 2) {
                        [$host, $port] = $host;
                    } else {
                        $host = $host[0];
                        $port = MysqlConfig::DEFAULT_PORT;
                    }
                }
                $config = new MysqlConfig(
                    host: $host,
                    port: (int) $port,
                    user: $settings->getUsername(),
                    password: $settings->getPassword(),
                    database: $settings->getDatabase()
                );

                self::createDb($config);

                $host = $config->getHost();
                $port = $config->getPort();
                if (!\extension_loaded('pdo_mysql')) {
                    throw Exception::extension('pdo_mysql');
                }

                try {
                    $pdo = new PDO(
                        $host[0] === '/'
                            ? "mysql:unix_socket={$host};charset=UTF8"
                            : "mysql:host={$host};port={$port};charset=UTF8",
                        $settings->getUsername(),
                        $settings->getPassword(),
                    );
                } catch (PDOException $e) {
                    $config = $config->withPassword(null);
                    try {
                        $pdo = new PDO(
                            $host[0] === '/'
                                ? "mysql:unix_socket={$host};charset=UTF8"
                                : "mysql:host={$host};port={$port};charset=UTF8",
                            $settings->getUsername(),
                        );
                    } catch (\Throwable) {
                        throw $e;
                    }
                }

                self::$connections[$dbKey] = [
                    new MysqlConnectionPool($config, $settings->getMaxConnections(), $settings->getIdleTimeout()),
                    $pdo,
                ];
            }
        } finally {
            EventLoop::queue($lock->release(...));
        }

        return self::$connections[$dbKey];
    }

    private static function createDb(MysqlConfig $config): void
    {
        try {
            $db = $config->getDatabase();
            $connection = new MysqlConnectionPool($config->withDatabase(null));
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
            } catch (Throwable) {
            }
            $connection->close();
        } catch (Throwable $e) {
            Logger::log("An error occurred while trying to create the database: ".$e->getMessage(), Logger::ERROR);
        }
    }

    /**
     * Initialize connection.
     */
    public function initConnection(DatabaseMysql $settings): void
    {
        if (isset($this->db)) {
            return;
        }
        [$this->db, $this->pdo] = self::getConnection($settings);
    }

    /**
     * Create table for property.
     */
    protected function prepareTable(): void
    {
        //Logger::log("Creating/checking table {$this->table}", Logger::WARNING);
        \assert($this->dbSettings instanceof DatabaseMysql);
        $keyType = $this->dbSettings->keyType;
        $valueType = $this->dbSettings->valueType;

        $this->db->query("
            CREATE TABLE IF NOT EXISTS mgmt
            (
                `tableName` VARCHAR(255) NOT NULL,
                `versionInfo` LONGBLOB NOT NULL,
                PRIMARY KEY (`tableName`)
            )
            ENGINE = InnoDB
            CHARACTER SET 'utf8mb4' 
            COLLATE 'utf8mb4_general_ci'
        ");

        $version = $this->db->prepare("SELECT version FROM mgmt WHERE tableName=?")->execute([$this->table])->fetchRow()['versionInfo'] ?? null;

        if ($version === null) {
            $this->db->query("
                CREATE TABLE IF NOT EXISTS `{$this->table}`
                (
                    `key` $keyType NOT NULL,
                    `value` LONGBLOB NOT NULL,
                    PRIMARY KEY (`key`)
                )
                ENGINE = InnoDB
                CHARACTER SET 'utf8mb4' 
                COLLATE 'utf8mb4_general_ci'
            ");
        }
        if ($version < 1) {
        }
        if ($version < 2) {
            $this->db->query("ALTER TABLE `{$this->table}` MODIFY `key` BIGINT");
            $this->db->query("ALTER TABLE `{$this->table}` DROP `ts`");
        }
        $this->db->prepare("REPLACE INTO mgmt SET version=? WHERE tableName=?")->execute([self::V, $this->table]);

        if ($this->dbSettings->getOptimizeIfWastedGtMb() !== null) {
            try {
                $database = $this->dbSettings->getDatabase();
                $result = $this->db->prepare("SELECT data_free FROM information_schema.tables WHERE table_schema=? AND table_name=?")
                    ->execute([$database, $this->table])
                    ->fetchRow();
                Assert::notNull($result);
                $result = $result['data_free'] ?? $result['DATA_FREE'];
                if (($result >> 20) > $this->dbSettings->getOptimizeIfWastedGtMb()) {
                    $this->db->query("OPTIMIZE TABLE `{$this->table}`");
                }
            } catch (\Throwable $e) {
                Logger::log("An error occurred while optimizing the table: $e", Logger::ERROR);
            }
        }
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
