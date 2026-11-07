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
 * @copyright 2016-2024 Daniil Gentili <daniil@daniil.it>
 * @license   https://opensource.org/license/apache-2-0 Apache 2.0
 * @link https://github.com/danog/AsyncOrm AsyncOrm documentation
 */

namespace danog\TestAsyncOrm;

use Amp\ByteStream\ReadableStream;
use Amp\Cancellation;
use Amp\Mysql\MysqlConfig;
use Amp\Parallel\Worker\Execution;
use Amp\Parallel\Worker\Task;
use Amp\Parallel\Worker\Worker;
use Amp\Parallel\Worker\WorkerException;
use Amp\Parallel\Worker\WorkerPool;
use Amp\Postgres\PostgresConfig;
use Amp\Process\Process;
use Amp\Redis\RedisConfig;
use Amp\TimeoutCancellation;
use AssertionError;
use danog\AsyncOrm\DbArray;
use danog\AsyncOrm\DbArrayBuilder;
use danog\AsyncOrm\DbObject;
use danog\AsyncOrm\Driver\MemoryArray;
use danog\AsyncOrm\Internal\Containers\CacheContainer;
use danog\AsyncOrm\Internal\Containers\ObjectContainer;
use danog\AsyncOrm\Internal\Driver\BatchWrites;
use danog\AsyncOrm\Internal\Driver\CachedArray;
use danog\AsyncOrm\Internal\Driver\ObjectArray;
use danog\AsyncOrm\Internal\Driver\SqliteConnection;
use danog\AsyncOrm\KeyType;
use danog\AsyncOrm\Serializer\Igbinary;
use danog\AsyncOrm\Serializer\Json;
use danog\AsyncOrm\Serializer\Native;
use danog\AsyncOrm\Settings;
use danog\AsyncOrm\Settings\DriverSettings;
use danog\AsyncOrm\Settings\MemorySettings;
use danog\AsyncOrm\Settings\MysqlSettings;
use danog\AsyncOrm\Settings\PostgresSettings;
use danog\AsyncOrm\Settings\RedisSettings;
use danog\AsyncOrm\Settings\SqliteSettings;
use danog\AsyncOrm\ValueType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Revolt\EventLoop;
use WeakReference;

use function Amp\async;
use function Amp\ByteStream\buffer;
use function Amp\ByteStream\getStderr;
use function Amp\ByteStream\getStdout;
use function Amp\ByteStream\pipe;
use function Amp\ByteStream\splitLines;
use function Amp\delay;
use function Amp\Future\await;
use function Amp\Future\awaitAny;
use function Amp\Parallel\Worker\workerPool;

final class OrmTest extends TestCase
{
    /** @var array<string, Process> */
    private static array $processes = [];
    private static function shellExec(string $cmd): void
    {
        $process = Process::start($cmd);
        async(pipe(...), $process->getStderr(), getStderr());
        async(pipe(...), $process->getStdout(), getStdout());
        $process->join();
    }
    private static bool $configured = false;
    public static function setUpBeforeClass(): void
    {
        \touch('/tmp/async-orm-test');
        $lockFile = \fopen('/tmp/async-orm-test', 'r+');
        \flock($lockFile, LOCK_EX);
        // fgets() keeps the newline written below.
        if (\fgets($lockFile) === "done\n") {
            \flock($lockFile, LOCK_UN);
            return;
        }
        self::$configured = true;
        \fwrite($lockFile, "done\n");

        $f = [];
        foreach (['redis' => 6379, 'mariadb' => 3306, 'postgres' => 5432] as $image => $port) {
            $f []= async(function () use ($image, $port) {
                self::shellExec("docker rm -f test_$image 2>/dev/null");

                $args = match ($image) {
                    'postgres' => '-e POSTGRES_HOST_AUTH_METHOD=trust',
                    'mariadb' => '-e MARIADB_ALLOW_EMPTY_ROOT_PASSWORD=1',
                    default => ''
                };
                $process = Process::start(
                    "docker run --rm -p $port:$port $args --name test_$image $image"
                );
                self::$processes[$image] = $process;
            });
        }
        await($f);
        if (!self::$processes) {
            throw new AssertionError("No processes!");
        }
        $t = new TimeoutCancellation(30.0);
        foreach (self::$processes as $name => $process) {
            $ok = awaitAny([
                async(self::waitForStartup(...), $process->getStdout()),
                async(self::waitForStartup(...), $process->getStderr()),
            ], $t);
            if (!$ok) {
                throw new AssertionError("Could not start $name!");
            }
        }

        \flock($lockFile, LOCK_UN);
    }
    public static function tearDownAfterClass(): void
    {
        if (self::$configured) {
            \unlink('/tmp/async-orm-test');
        }
    }
    private static function waitForStartup(ReadableStream $f): bool
    {
        // The postgres and mariadb images first run a temporary server to initialize the database,
        // which doesn't listen on TCP: wait for the final one.
        $postgresListening = false;
        foreach (splitLines($f) as $line) {
            if (\stripos($line, 'listening on IPv4 address') !== false) {
                $postgresListening = true;
            }
            $ready = match (true) {
                \stripos($line, 'database system is ready to accept connections') !== false => $postgresListening,
                \stripos($line, 'ready to ') !== false,
                \stripos($line, "socket: '/run/mysqld/mysqld.sock'  port: 3306") !== false => true,
                default => false,
            };
            if ($ready) {
                async(buffer(...), $f);
                return true;
            }
        }
        return false;
    }

    private static function sqlitePath(string $name): string
    {
        return \sys_get_temp_dir()."/async-orm-$name.sqlite";
    }

    public function assertSameNotObject(mixed $a, mixed $b): void
    {
        if ($b instanceof DbObject) {
            $this->assertSame($a::class, $b::class);
        } else {
            $this->assertSame($a, $b);
        }
    }

    public static function provideMysqlSettings(): \Generator
    {
        foreach (self::provideSettings() as [$k, $settings]) {
            if ($settings instanceof MysqlSettings && !$settings->serializer instanceof Json) {
                yield [$k, $settings];
            }
        }
    }

    /** A value too big to double into a hex literal within max_allowed_packet (16 MiB by default) still fits quoted. */
    #[DataProvider('provideMysqlSettings')]
    public function testValueLargerThanHalfAPacket(int $tablePostfix, MysqlSettings $settings): void
    {
        $orm = (new DbArrayBuilder("testLargeValue_$tablePostfix", $settings, KeyType::STRING, ValueType::SCALAR))->build();
        $value = \random_bytes(10 << 20);
        $orm['large'] = $value;
        $this->assertSame($value, $orm['large']);
        $orm['small'] = 'x';
        $this->assertSame('x', $orm['small']);
        unset($orm['large'], $orm['small']);
    }

    #[DataProvider('provideSettingsKeysValues')]
    public function testBasic(int $tablePostfix, Settings $settings, KeyType $keyType, string|int $key, ValueType $valueType, mixed $value): void
    {
        $field = new DbArrayBuilder(
            "testBasic_$tablePostfix",
            $settings,
            $keyType,
            $valueType
        );
        $orm = $field->build();
        $orm[$key] = $value;

        [$a, $b] = await([
            async($orm->get(...), $key),
            async($orm->get(...), $key),
        ]);
        $this->assertSameNotObject($value, $a);
        $this->assertSameNotObject($value, $b);
        $this->assertSameNotObject($value, $orm[$key]);
        $this->assertTrue(isset($orm[$key]));
        if (!$value instanceof DbObject) {
            $this->assertSameNotObject([$key => $value], $orm->getArrayCopy());
        }
        unset($orm[$key]);

        $this->assertNull($orm[$key]);
        $this->assertFalse(isset($orm[$key]));

        if ($orm instanceof CachedArray) {
            $orm->flushCache();
        }

        $this->assertCount(0, $orm);
        $this->assertNull($orm[$key]);
        $this->assertFalse(isset($orm[$key]));
        if (!$value instanceof DbObject) {
            $this->assertSameNotObject([], $orm->getArrayCopy());
        }

        if ($orm instanceof MemoryArray) {
            $orm->clear();
            $cnt = 0;
            foreach ($orm as $kk => $vv) {
                $cnt++;
            }
            $this->assertEquals(0, $cnt);
            $this->assertCount(0, $orm);
            return;
        }

        $orm = $field->build();
        $orm[$key] = $value;

        $this->assertCount(1, $orm);
        $this->assertSameNotObject($value, $orm[$key]);
        $this->assertTrue(isset($orm[$key]));

        if ($orm instanceof CachedArray) {
            $orm->flushCache();
        }
        unset($orm);
        while (\gc_collect_cycles());

        $orm = $field->build();
        $this->assertSameNotObject($value, $orm[$key]);
        $this->assertTrue(isset($orm[$key]));

        unset($orm[$key]);
        $this->assertNull($orm[$key]);
        $this->assertFalse(isset($orm[$key]));

        if ($orm instanceof CachedArray) {
            $orm->flushCache();
        }

        $this->assertCount(0, $orm);
        $orm[$key] = $value;
        $this->assertCount(1, $orm);
        $orm[$key] = $value;
        $this->assertCount(1, $orm);
        $cnt = 0;
        foreach ($orm as $kk => $vv) {
            $cnt++;
            $this->assertSameNotObject($key, $kk);
            $this->assertSameNotObject($value, $vv);
        }
        $this->assertEquals(1, $cnt);

        $orm->clear();
        $cnt = 0;
        foreach ($orm as $kk => $vv) {
            $cnt++;
        }
        $this->assertEquals(0, $cnt);
        $this->assertCount(0, $orm);

        // Test that db is flushed on __destruct
        $orm = $field->build();
        $orm[$key] = $value;
        unset($orm);

        // The destructor queues the flush: with a database server, a write that takes a
        // round trip, so wait for it rather than for any fixed time.
        $orm = $field->build();
        $t = new TimeoutCancellation(10.0);
        while (\count($orm) === 0) {
            delay(0.01, cancellation: $t);
        }
        $this->assertCount(1, $orm);
        $cnt = 0;
        foreach ($orm as $kk => $vv) {
            $cnt++;
            $this->assertSameNotObject($key, $kk);
            $this->assertSameNotObject($value, $vv);
        }
        $this->assertEquals(1, $cnt);
        $orm->clear();
    }

    public static function provideSqlSettingsOtherDatabase(): \Generator
    {
        $key = 0;
        foreach ([new Native, new Igbinary, new Json] as $serializer) {
            foreach ([0, 100] as $ttl) {
                yield [$key++, new PostgresSettings(
                    PostgresConfig::fromString('host=127.0.0.1:5432 user=postgres db=test'),
                    $serializer,
                    $ttl,
                ), new PostgresSettings(
                    PostgresConfig::fromString('host=127.0.0.1:5432 user=postgres db=test_other'),
                    $serializer,
                    $ttl,
                )];
                yield [$key++, new MysqlSettings(
                    MysqlConfig::fromString('host=127.0.0.1:3306 user=root db=test'),
                    $serializer,
                    $ttl,
                ), new MysqlSettings(
                    MysqlConfig::fromString('host=127.0.0.1:3306 user=root db=test_other'),
                    $serializer,
                    $ttl,
                )];
                yield [$key++, new SqliteSettings(
                    self::sqlitePath('test'),
                    $serializer,
                    $ttl,
                ), new SqliteSettings(
                    self::sqlitePath('test_other'),
                    $serializer,
                    $ttl,
                )];
            }
        }
    }

    /**
     * Moving an array to another database of the same driver migrates its contents.
     */
    #[DataProvider('provideSqlSettingsOtherDatabase')]
    public function testMigrationToOtherDatabase(int $tablePostfix, Settings $settings, Settings $otherSettings): void
    {
        $table = 'testMigrationToOtherDatabase_'.$tablePostfix;
        $orm = (new DbArrayBuilder($table, $settings, KeyType::STRING, ValueType::INT))->build();
        $orm->clear();
        $orm['key'] = 123;

        $other = (new DbArrayBuilder($table, $otherSettings, KeyType::STRING, ValueType::INT))->build($orm);
        $this->assertSame(123, $other['key']);
        $this->assertCount(1, $other);

        $other->clear();
    }

    /**
     * Same, from an array that was unserialized (as from a session) and never woken up:
     * the new database is the first thing it's opened for.
     */
    #[DataProvider('provideSqlSettingsOtherDatabase')]
    public function testMigrationToOtherDatabaseFromUnserialized(int $tablePostfix, Settings $settings, Settings $otherSettings): void
    {
        $table = 'testMigrationToOtherDatabaseFromUnserialized_'.$tablePostfix;
        $orm = (new DbArrayBuilder($table, $settings, KeyType::STRING, ValueType::INT))->build();
        $orm->clear();
        $orm['key'] = 123;
        // A serialized array holds no cache: flush it first, like a session save does.
        // (Otherwise the write only reaches the old database if the flush queued by the
        // destructor of the original happens to run before the copy.)
        if ($orm instanceof CachedArray) {
            $orm->flushCache();
        }
        $orm = \unserialize(\serialize($orm));

        $other = (new DbArrayBuilder($table, $otherSettings, KeyType::STRING, ValueType::INT))->build($orm);
        $this->assertSame(123, $other['key']);
        $this->assertCount(1, $other);

        $other->clear();
    }

    public static function provideMysqlSettingsSameServerOtherAddress(): \Generator
    {
        $key = 0;
        foreach ([new Native, new Igbinary, new Json] as $serializer) {
            foreach ([0, 100] as $ttl) {
                yield [$key++, new MysqlSettings(
                    MysqlConfig::fromString('host=127.0.0.1:3306 user=root db=test'),
                    $serializer,
                    $ttl,
                ), new MysqlSettings(
                    MysqlConfig::fromString('host=127.0.0.2:3306 user=root db=test'),
                    $serializer,
                    $ttl,
                )];
            }
        }
    }

    /**
     * The same server and database under another address (here another loopback address; in
     * production, TCP and a unix socket) is the same table: nothing is copied, so nothing
     * is deleted and rewritten (which loses rows if the process dies halfway).
     */
    #[DataProvider('provideMysqlSettingsSameServerOtherAddress')]
    public function testNoMigrationOnSameServerOtherAddress(int $tablePostfix, Settings $settings, Settings $otherSettings): void
    {
        $table = 'testNoMigrationOnSameServerOtherAddress_'.$tablePostfix;
        $orm = (new DbArrayBuilder($table, $settings, KeyType::STRING, ValueType::INT))->build();
        $orm->clear();
        for ($x = 0; $x < 100; $x++) {
            $orm["key$x"] = $x;
        }
        if ($orm instanceof CachedArray) {
            $orm->flushCache();
        }
        $orm = \unserialize(\serialize($orm));

        $deletes = static function (): int {
            $pool = new \Amp\Mysql\MysqlConnectionPool(MysqlConfig::fromString('host=127.0.0.1:3306 user=root db=test'));
            $n = (int) $pool->query("SHOW GLOBAL STATUS LIKE 'Handler_delete'")->fetchRow()['Value'];
            $pool->close();
            return $n;
        };
        $before = $deletes();
        $other = (new DbArrayBuilder($table, $otherSettings, KeyType::STRING, ValueType::INT))->build($orm);
        $this->assertSame($before, $deletes());

        $this->assertCount(100, $other);
        $this->assertSame(42, $other['key42']);

        $other->clear();
    }

    /** @return DbArray<int, int> */
    private static function sqliteArray(string $table, string $path = 'sqlite_specific'): DbArray
    {
        $orm = (new DbArrayBuilder($table, new SqliteSettings(self::sqlitePath($path), cacheTtl: 0), KeyType::INT, ValueType::INT))->build();
        $orm->clear();
        delay(0);
        return $orm;
    }

    private static function sqliteReader(string $path = 'sqlite_specific'): \SQLite3
    {
        $db = new \SQLite3(self::sqlitePath($path));
        $db->enableExceptions(true);
        $db->busyTimeout(5000);
        return $db;
    }

    /**
     * The writes of a tick are one transaction, committed when the loop gets a turn.
     */
    public function testSqliteTickTransaction(): void
    {
        $orm = self::sqliteArray('testSqliteTickTransaction');
        $reader = self::sqliteReader();
        $count = static fn (int $value): int => $reader->querySingle("SELECT count(*) FROM \"testSqliteTickTransaction\" WHERE value = $value");

        // Queries let the loop tick after 1ms of queries (committing): on a slow or busy
        // machine, try again until the writes were all made within one tick.
        for ($attempt = 1; ; $attempt++) {
            delay(0);
            $ticked = false;
            EventLoop::defer(static function () use (&$ticked): void {
                $ticked = true;
            });
            for ($x = 0; $x < 10; $x++) {
                $orm[$x] = $attempt;
            }
            $seen = $count($attempt);
            if (!$ticked) {
                break;
            }
            $this->assertLessThan(100, $attempt);
        }
        $this->assertSame(0, $seen);
        $this->assertSame($attempt, $orm[9]);

        delay(0);
        $this->assertSame(10, $count($attempt));
        $orm->clear();
    }

    /**
     * While another connection holds the write lock, a write waits without blocking the loop.
     */
    public function testSqliteLockWaitDoesNotBlock(): void
    {
        $orm = self::sqliteArray('testSqliteLockWaitDoesNotBlock');
        $other = self::sqliteReader();
        $other->exec('BEGIN IMMEDIATE');

        $ticks = 0;
        $timer = EventLoop::repeat(0.01, static function () use (&$ticks): void {
            $ticks++;
        });
        $set = async(static fn () => $orm[1] = 1);
        delay(0.2);
        $this->assertFalse($set->isComplete());
        $this->assertGreaterThan(5, $ticks);

        $other->exec('COMMIT');
        $set->await(new TimeoutCancellation(5.0));
        EventLoop::cancel($timer);
        $this->assertSame(1, $orm[1]);
        $orm->clear();
    }

    /**
     * Writes from several fibers waiting for the same lock all go into the same transaction.
     */
    public function testSqliteConcurrentLockWaits(): void
    {
        $orm = self::sqliteArray('testSqliteConcurrentLockWaits');
        $other = self::sqliteReader();
        $other->exec('BEGIN IMMEDIATE');

        $sets = [];
        for ($x = 0; $x < 3; $x++) {
            $sets []= async(static fn () => $orm[$x] = $x);
        }
        delay(0.05);
        $other->exec('COMMIT');
        await($sets, new TimeoutCancellation(5.0));

        for ($x = 0; $x < 3; $x++) {
            $this->assertSame($x, $orm[$x]);
        }
        $orm->clear();
    }

    /**
     * A long run of queries without suspending lets the loop run.
     */
    public function testSqliteYieldsWhenBusy(): void
    {
        $orm = self::sqliteArray('testSqliteYieldsWhenBusy');
        $ran = false;
        EventLoop::defer(static function () use (&$ran): void {
            $ran = true;
        });
        $start = \hrtime(true);
        for ($x = 0; !$ran && \hrtime(true) - $start < 5_000_000_000; $x++) {
            $orm[$x % 100] = $x;
        }
        $this->assertTrue($ran);
        $orm->clear();
    }

    private static function getCheckpointer(DbArray $orm, string $path = 'sqlite_specific'): array
    {
        $connection = (new ReflectionProperty(SqliteConnection::class, 'connections'))->getValue()[self::sqlitePath($path)];
        $db = $connection->db;
        $t = new TimeoutCancellation(10.0);
        while ((int) $db->query('PRAGMA wal_autocheckpoint')->fetchColumn() !== 0) {
            delay(0.01, cancellation: $t);
        }
        return [$connection, $db];
    }

    /**
     * The WAL is checkpointed by a worker.
     */
    public function testSqliteCheckpointer(): void
    {
        $orm = self::sqliteArray('testSqliteCheckpointer');
        [, $db] = self::getCheckpointer($orm);

        for ($x = 0; $x < 1000; $x++) {
            $orm[$x] = $x;
            if ($x % 10 === 0) {
                delay(0);
            }
        }
        delay(0);
        $t = new TimeoutCancellation(10.0);
        while (true) {
            [, $log, $checkpointed] = self::sqliteReader()->query('PRAGMA wal_checkpoint(NOOP)')->fetchArray(SQLITE3_NUM);
            if ($log === $checkpointed) {
                break;
            }
            delay(0.05, cancellation: $t);
        }
        $this->assertSame(0, (int) $db->query('PRAGMA wal_autocheckpoint')->fetchColumn());
        $orm->clear();
    }

    /**
     * If no worker can run the checkpoints, SQLite checkpoints by itself again.
     */
    public function testSqliteCheckpointFallback(): void
    {
        $orm = self::sqliteArray('testSqliteCheckpointFallback');
        [, $db] = self::getCheckpointer($orm);

        $pool = workerPool();
        workerPool(new class implements WorkerPool {
            public function submit(Task $task, ?Cancellation $cancellation = null): Execution
            {
                throw new WorkerException('No workers here');
            }
            public function isRunning(): bool
            {
                return true;
            }
            public function isIdle(): bool
            {
                return true;
            }
            public function shutdown(): void
            {
            }
            public function kill(): void
            {
            }
            public function getWorker(): Worker
            {
                throw new WorkerException('No workers here');
            }
            public function getWorkerCount(): int
            {
                return 0;
            }
            public function getIdleWorkerCount(): int
            {
                return 0;
            }
        });
        try {
            $orm[1] = 1;
            $t = new TimeoutCancellation(10.0);
            while ((int) $db->query('PRAGMA wal_autocheckpoint')->fetchColumn() === 0) {
                delay(0.01, cancellation: $t);
            }
            $this->assertSame(1000, (int) $db->query('PRAGMA wal_autocheckpoint')->fetchColumn());
        } finally {
            workerPool($pool);
            // Workers are tried again only a minute later: start over for the other tests.
            (new ReflectionProperty(SqliteConnection::class, 'connections'))->setValue(null, []);
        }
        $orm->clear();
    }

    /**
     * With checkpointInWorkers disabled, no worker is used: SQLite checkpoints by itself.
     */
    public function testSqliteCheckpointInWorkersDisabled(): void
    {
        // Its own database: connections of other tests may still have checkpoints scheduled.
        $path = self::sqlitePath('sqlite_workers_disabled');
        $orm = self::sqliteArray('testSqliteCheckpointInWorkersDisabled', 'sqlite_workers_disabled');
        [, $db] = self::getCheckpointer($orm, 'sqlite_workers_disabled');

        $submitted = 0;
        $pool = workerPool();
        workerPool(new class($submitted, $path) implements WorkerPool {
            public function __construct(private int &$submitted, private string $path)
            {
            }
            public function submit(Task $task, ?Cancellation $cancellation = null): Execution
            {
                if ((new ReflectionProperty($task, 'path'))->getValue($task) === $this->path) {
                    $this->submitted++;
                }
                throw new WorkerException('No workers here');
            }
            public function isRunning(): bool
            {
                return true;
            }
            public function isIdle(): bool
            {
                return true;
            }
            public function shutdown(): void
            {
            }
            public function kill(): void
            {
            }
            public function getWorker(): Worker
            {
                throw new WorkerException('No workers here');
            }
            public function getWorkerCount(): int
            {
                return 0;
            }
            public function getIdleWorkerCount(): int
            {
                return 0;
            }
        });
        try {
            // Disabling it on a database whose checkpoints run in workers hands them back to SQLite.
            $disabled = (new DbArrayBuilder(
                'testSqliteCheckpointInWorkersDisabled',
                new SqliteSettings($path, cacheTtl: 0, checkpointInWorkers: false),
                KeyType::INT,
                ValueType::INT,
            ))->build();
            $this->assertSame(1000, (int) $db->query('PRAGMA wal_autocheckpoint')->fetchColumn());

            for ($x = 0; $x < 100; $x++) {
                $disabled[$x] = $x;
                delay(0);
            }
            delay(0.5);
            $this->assertSame(0, $submitted);
            $this->assertSame(1000, (int) $db->query('PRAGMA wal_autocheckpoint')->fetchColumn());
            $disabled->clear();
        } finally {
            workerPool($pool);
        }
    }

    /**
     * Writes made right before exit() are committed.
     */
    public function testSqliteCommitOnExit(): void
    {
        $orm = self::sqliteArray('testSqliteCommitOnExit');
        $autoload = \var_export(__DIR__.'/../vendor/autoload.php', true);
        $path = \var_export(self::sqlitePath('sqlite_specific'), true);
        $code = "require $autoload;
            \$orm = (new danog\\AsyncOrm\\DbArrayBuilder('testSqliteCommitOnExit', new danog\\AsyncOrm\\Settings\\SqliteSettings($path, cacheTtl: 0), danog\\AsyncOrm\\KeyType::INT, danog\\AsyncOrm\\ValueType::INT))->build();
            \$orm[1] = 123;
            exit;";
        $process = Process::start([PHP_BINARY, '-d', 'error_reporting=E_ALL&~E_DEPRECATED', '-r', $code]);
        async(buffer(...), $process->getStdout());
        async(buffer(...), $process->getStderr());
        $this->assertSame(0, $process->join());
        $this->assertSame(123, $orm[1]);
        $orm->clear();
    }

    /**
     * The same file through another path is the same table: nothing is copied (and so
     * deleted from the very same table).
     */
    public function testSqliteSameFileOtherPath(): void
    {
        $orm = self::sqliteArray('testSqliteSameFileOtherPath');
        for ($x = 0; $x < 100; $x++) {
            $orm[$x] = $x;
        }
        delay(0);
        $orm = \unserialize(\serialize($orm));

        $link = \sys_get_temp_dir().'/async-orm-link.sqlite';
        if (\is_link($link)) {
            \unlink($link);
        }
        \symlink(self::sqlitePath('sqlite_specific'), $link);
        try {
            $other = (new DbArrayBuilder('testSqliteSameFileOtherPath', new SqliteSettings($link, cacheTtl: 0), KeyType::INT, ValueType::INT))->build($orm);
            delay(0);
            $this->assertCount(100, $other);
            $this->assertSame(42, $other[42]);
            $other->clear();
        } finally {
            \unlink($link);
        }
    }

    /**
     * A flush writes every pending set and unset.
     */
    #[DataProvider('provideSettings')]
    public function testFlushManyKeys(int $tablePostfix, Settings $settings): void
    {
        if (!$settings instanceof DriverSettings || $settings->cacheTtl === 0) {
            $this->assertTrue(true);
            return;
        }
        $table = "testFlushManyKeys_$tablePostfix";
        $uncached = new DbArrayBuilder($table, new ($settings::class)(...match (true) {
            $settings instanceof RedisSettings, $settings instanceof PostgresSettings, $settings instanceof MysqlSettings => [$settings->config, $settings->serializer, 0],
            $settings instanceof SqliteSettings => [$settings->path, $settings->serializer, 0],
        }), KeyType::INT, ValueType::SCALAR);
        $orm = (new DbArrayBuilder($table, $settings, KeyType::INT, ValueType::SCALAR))->build();
        $orm->clear();
        \assert($orm instanceof CachedArray);

        for ($x = 0; $x < 2500; $x++) {
            $orm[$x] = "value $x";
        }
        $orm->flushCache();
        for ($x = 0; $x < 1300; $x++) {
            unset($orm[$x]);
        }
        $orm[3000] = 'new';
        $orm->flushCache();

        $db = $uncached->build();
        $this->assertCount(1201, $db);
        $this->assertNull($db[0]);
        $this->assertSame('value 1300', $db[1300]);
        $this->assertSame('new', $db[3000]);

        $orm->clear();
    }

    /** Values that don't all fit in one packet (16 MiB by default) are flushed by several statements. */
    #[DataProvider('provideMysqlSettings')]
    public function testSetManyLargerThanAPacket(int $tablePostfix, MysqlSettings $settings): void
    {
        $orm = (new DbArrayBuilder("testSetManyLarge_$tablePostfix", $settings, KeyType::STRING, ValueType::SCALAR))->build();
        $orm->clear();
        $values = ['a' => \random_bytes(7 << 20), 'b' => \random_bytes(7 << 20), 'c' => \random_bytes(7 << 20), 'd' => 'x'];
        if ($orm instanceof CachedArray) {
            foreach ($values as $key => $value) {
                $orm[$key] = $value;
            }
            $orm->flushCache();
        } else {
            \assert($orm instanceof BatchWrites);
            $orm->setMany($values);
        }
        foreach ($values as $key => $value) {
            $this->assertSame($value, $orm[$key]);
        }
        $orm->clear();
    }

    #[DataProvider('provideSettings')]
    public function testKeyMigration(int $tablePostfix, Settings $settings): void
    {
        $field = new DbArrayBuilder(
            $table = 'testKeyMigration_'.$tablePostfix,
            $settings,
            KeyType::STRING_OR_INT,
            ValueType::INT
        );
        $orm = $field->build();
        $orm[321] = 123;

        $this->assertSame(123, $orm[321]);
        $this->assertTrue(isset($orm[321]));
        $cnt = 0;
        foreach ($orm as $kk => $vv) {
            $cnt++;
            $this->assertSame($orm instanceof MemoryArray ? 321 : "321", $kk);
            $this->assertSame(123, $vv);
        }
        $this->assertEquals(1, $cnt);

        if ($orm instanceof MemoryArray) {
            return;
        }

        $field = new DbArrayBuilder(
            $table,
            $settings,
            KeyType::INT,
            ValueType::INT
        );
        $orm = $field->build();
        $this->assertSame(123, $orm[321]);
        $this->assertTrue(isset($orm[321]));
        $cnt = 0;
        foreach ($orm as $kk => $vv) {
            $cnt++;
            $this->assertSame(321, $kk);
            $this->assertSame(123, $vv);
        }
        $this->assertEquals(1, $cnt);

        $field = new DbArrayBuilder(
            $table,
            $settings,
            KeyType::STRING,
            ValueType::INT
        );
        $orm = $field->build();
        $this->assertSame(123, $orm[321]);
        $this->assertTrue(isset($orm[321]));

        $cnt = 0;
        foreach ($orm as $kk => $vv) {
            $cnt++;
            $this->assertSame('321', $kk);
            $this->assertSame(123, $vv);
        }
        $this->assertEquals(1, $cnt);

        $field = new DbArrayBuilder(
            $table,
            $settings,
            KeyType::INT,
            ValueType::INT
        );
        $orm = $field->build();
        $this->assertSame(123, $orm[321]);
        $this->assertTrue(isset($orm[321]));

        $cnt = 0;
        foreach ($orm as $kk => $vv) {
            $cnt++;
            $this->assertSame(321, $kk);
            $this->assertSame(123, $vv);
        }
        $this->assertEquals(1, $cnt);

        $field = new DbArrayBuilder(
            $table.'_new',
            $settings,
            KeyType::INT,
            ValueType::INT
        );
        $orm = $field->build($orm);
        $this->assertSame(123, $orm[321]);
        $this->assertTrue(isset($orm[321]));

        $cnt = 0;
        foreach ($orm as $kk => $vv) {
            $cnt++;
            $this->assertSame(321, $kk);
            $this->assertSame(123, $vv);
        }
        $this->assertEquals(1, $cnt);

        $field = new DbArrayBuilder(
            $table.'_new',
            new MemorySettings,
            KeyType::INT,
            ValueType::INT
        );
        $old = $orm;
        $orm = $field->build($old);
        $this->assertSame(123, $orm[321]);
        $this->assertTrue(isset($orm[321]));

        $cnt = 0;
        foreach ($orm as $kk => $vv) {
            $cnt++;
            $this->assertSame(321, $kk);
            $this->assertSame(123, $vv);
        }
        $this->assertEquals(1, $cnt);

        $this->assertCount(0, $old);

        $field = new DbArrayBuilder(
            $table.'_new',
            new MemorySettings,
            KeyType::INT,
            ValueType::INT
        );
        $old = $orm;
        $orm = $field->build($old);
        $this->assertSame(123, $orm[321]);
        $this->assertTrue(isset($orm[321]));

        $cnt = 0;
        foreach ($orm as $kk => $vv) {
            $cnt++;
            $this->assertSame(321, $kk);
            $this->assertSame(123, $vv);
        }
        $this->assertEquals(1, $cnt);

        $this->assertCount(1, $old);
    }

    #[DataProvider('provideSettings')]
    public function testObject(int $tablePostfix, Settings $settings): void
    {
        if (!$settings instanceof DriverSettings) {
            $this->expectExceptionMessage("Objects can only be saved to a database backend!");
        }
        if ($settings->serializer instanceof Json) {
            $this->expectExceptionMessage("The JSON backend cannot be used when serializing objects!");
        }
        $field = new DbArrayBuilder(
            'testObject_'.$tablePostfix,
            $settings,
            KeyType::STRING_OR_INT,
            ValueType::OBJECT
        );
        $orm = $field->build();
        $this->assertSame(ObjectArray::class, $orm::class);

        $obj = new TestObject;

        $this->assertSame(0, $obj->loadedCnt);
        $this->assertSame(0, $obj->saveAfterCnt);
        $this->assertSame(0, $obj->saveBeforeCnt);

        $orm[321] = $obj;

        $this->assertSame(1, $obj->loadedCnt);
        $this->assertSame(1, $obj->saveAfterCnt);
        $this->assertSame(1, $obj->saveBeforeCnt);

        $obj->arr[12345] = 54321;
        $obj->arr2[123456] = 654321;
        $obj->arr5[123] = "7734";
        $this->assertSame(54321, $obj->arr[12345]);
        $this->assertSame(654321, $obj->arr2[123456]);
        $this->assertSame("7734", $obj->arr5[123]);
        $this->assertCount(1, $obj->arr);
        $this->assertCount(1, $obj->arr2);

        $obj = $orm[321];

        $this->assertSame(1, $obj->loadedCnt);
        $this->assertSame(1, $obj->saveAfterCnt);
        $this->assertSame(1, $obj->saveBeforeCnt);
        $this->assertSame(54321, $obj->arr[12345]);
        $this->assertSame(654321, $obj->arr2[123456]);
        $this->assertCount(1, $obj->arr);
        $this->assertCount(1, $obj->arr2);

        unset($obj);
        $orm = $field->build();
        $obj = $orm[321];

        $this->assertSame(1, $obj->loadedCnt);
        $this->assertSame(0, $obj->saveAfterCnt);
        $this->assertSame(0, $obj->saveBeforeCnt);
        $this->assertSame(54321, $obj->arr[12345]);
        $this->assertSame(654321, $obj->arr2[123456]);
        $this->assertCount(1, $obj->arr);
        $this->assertCount(1, $obj->arr2);

        $orm[321] = $obj;

        $this->assertSame(1, $obj->loadedCnt);
        $this->assertSame(0, $obj->saveAfterCnt);
        $this->assertSame(0, $obj->saveBeforeCnt);
        $this->assertSame(54321, $obj->arr[12345]);
        $this->assertSame(654321, $obj->arr2[123456]);
        $this->assertCount(1, $obj->arr);
        $this->assertCount(1, $obj->arr2);

        $f = new ReflectionProperty(ObjectArray::class, 'cache');
        $f->getValue($orm)->flushCache();
        while (\gc_collect_cycles());
        $this->assertSame($obj, $orm[321]);

        $orm->clear();
        unset($obj);

        $obj = new TestObject;
        $ref = WeakReference::create($obj);
        $orm[123] = $obj;
        unset($obj, $orm[123]);

        $this->assertNull($ref->get());

        $obj = new TestObject;
        $ref = WeakReference::create($obj);
        $orm = $field->build();
        $orm[123] = $obj;
        unset($obj, $orm);

        while (\gc_collect_cycles());
        $this->assertNull($ref->get());

        $obj = $field->build()[123];
        $obj->savedProp = 123;
        $obj->save();
        $this->assertSame($obj->savedProp, 123);
        unset($obj);

        $this->assertSame($field->build()[123]->savedProp, 123);
        unset($obj, $orm);

        $field->build()->clear();
    }

    public function testException(): void
    {
        $this->expectExceptionMessage("Cannot save an uninitialized object!");
        (new TestObject)->save();
    }

    #[DataProvider('provideKeyValues')]
    public function testCache(int $tablePostfix, KeyType $keyType, string|int $key, ValueType $valueType, mixed $value): void
    {
        if ($value instanceof TestObject) {
            $value = new TestObject;
        } elseif (!\is_int($value) || !\is_int($key)) {
            $this->assertTrue(true);
            return;
        }
        $field = new DbArrayBuilder("testCache_{$tablePostfix}", new RedisSettings(
            RedisConfig::fromUri("redis://127.0.0.1"),
            cacheTtl: 1
        ), $keyType, $valueType);
        $fieldNoCache = new DbArrayBuilder("testCache_{$tablePostfix}", new RedisSettings(
            RedisConfig::fromUri("redis://127.0.0.1"),
            cacheTtl: 0
        ), $keyType, $valueType);
        $orm = $field->build();
        $ormUnCached = $fieldNoCache->build();

        $orm->set($key, $value);
        if ($valueType === ValueType::OBJECT) {
            $this->assertCount(1, $ormUnCached);
        } else {
            $this->assertCount(0, $ormUnCached);
            delay(0.1);
            $this->assertCount(0, $ormUnCached);
            delay(0.9);
        }
        delay(1.0);

        if ($value instanceof TestObject) {
            unset($value);
            $c = (new ReflectionProperty(ObjectArray::class, 'cache'))->getValue($orm);
            $c->flushCache();
            $this->assertCount(0, (new ReflectionProperty(ObjectContainer::class, 'cache'))->getValue($c));

            $f1 = async($orm->get(...), $key);
            $f2 = async($orm->get(...), $key);
            $value = $f1->await();
            $this->assertSame($value, $f1->await());
            $this->assertSame($value, $f2->await());
        } else {
            /** @var CacheContainer */
            $c = (new ReflectionProperty(CachedArray::class, 'cache'))->getValue($orm);
            $this->assertCount(0, (new ReflectionProperty(CacheContainer::class, 'cache'))->getValue($c));
            $f1 = async($orm->get(...), $key);
            $f2 = async($orm->get(...), $key);
            $this->assertSame($value, $f1->await());
            $this->assertSame($value, $f2->await());

            $orm[$key] = PHP_INT_MAX;
            $this->assertSame(PHP_INT_MAX, $orm[$key]);
            EventLoop::queue($orm->set(...), $key, $value);
            $c->flushCache();
            $this->assertSame($value, $orm[$key]);
        }

        $orm->clear();
    }
    public function testCacheStandalone(): void
    {
        $obj = new TestObject;
        $obj->initDbProperties(new RedisSettings(
            RedisConfig::fromUri("redis://127.0.0.1"),
            cacheTtl: 1
        ), "testCacheStandalone_");

        $fieldNoCache2 = new DbArrayBuilder("testCacheStandalone_arr2", new RedisSettings(
            RedisConfig::fromUri("redis://127.0.0.1"),
            cacheTtl: 0
        ), KeyType::INT, ValueType::INT);
        $orm2Uncached = $fieldNoCache2->build();

        $fieldNoCache4 = new DbArrayBuilder("testCacheStandalone_arr4", new RedisSettings(
            RedisConfig::fromUri("redis://127.0.0.1"),
            cacheTtl: 0
        ), KeyType::INT, ValueType::INT);
        $orm4Uncached = $fieldNoCache4->build();

        $obj->arr2->set(0, 1);
        $this->assertCount(0, $orm2Uncached);
        delay(0.1);
        $this->assertCount(0, $orm2Uncached);
        delay(0.9);
        $this->assertCount(1, $orm2Uncached);
        $fieldNoCache2 = new DbArrayBuilder("testCacheStandalone_arr2", new RedisSettings(
            RedisConfig::fromUri("redis://127.0.0.1"),
            cacheTtl: 100
        ), KeyType::INT, ValueType::INT);
        $orm2Uncached = $fieldNoCache2->build($orm2Uncached);
        $this->assertCount(1, $orm2Uncached);
        $orm2Uncached->clear();

        $obj->arr4->set(0, 1);
        $this->assertCount(1, $orm4Uncached);

        $fieldNoCache4 = new DbArrayBuilder("testCacheStandalone_arr4", new RedisSettings(
            RedisConfig::fromUri("redis://127.0.0.1"),
            cacheTtl: 100
        ), KeyType::INT, ValueType::INT);
        $orm4Uncached = $fieldNoCache4->build($orm4Uncached);
        $this->assertCount(1, $orm4Uncached);

        $orm4Uncached->clear();
    }

    public static function provideSettingsKeysValues(): \Generator
    {
        $k = 0;
        foreach (self::provideSettings() as [, $settings]) {
            foreach (self::provideKeyValues() as [, $keyType, $key, $valueType, $value]) {
                if ($valueType === ValueType::OBJECT && (
                    $settings instanceof MemorySettings
                    || $settings->serializer instanceof Json
                )) {
                    continue;
                }
                // JSON can't encode binary strings.
                if (\is_string($value) && !\mb_check_encoding($value, 'UTF-8')
                    && !$settings instanceof MemorySettings && $settings->serializer instanceof Json
                ) {
                    continue;
                }
                yield [
                    $k++,
                    $settings,
                    $keyType,
                    $key,
                    $valueType,
                    $value
                ];
            }
        }
    }

    public static function provideKeyValues(): \Generator
    {
        $key = 0;
        foreach ([
            [ValueType::INT, 123],
            [ValueType::STRING, '123'],
            [ValueType::STRING, 'test'],
            [ValueType::FLOAT, 123.321],
            // More than 14 significant digits (the default `precision` of (string) casts).
            [ValueType::FLOAT, 0.1 + 0.2],
            [ValueType::FLOAT, M_PI],
            [ValueType::BOOL, true],
            [ValueType::BOOL, false],

            // Uncomment when segfaults are fixed
            [ValueType::OBJECT, new TestObject],

            [ValueType::SCALAR, 'test'],
            [ValueType::SCALAR, 123],
            [ValueType::SCALAR, ['test' => 123]],
            [ValueType::SCALAR, 123.321],
            // Every byte value, and a placeholder lookalike, in the serialized value.
            [ValueType::SCALAR, \implode('', \array_map(\chr(...), \range(0, 255))).":index:value"],
        ] as [$valueType, $value]) {
            yield [
                $key++,
                KeyType::INT,
                1234,
                $valueType,
                $value
            ];
            yield [
                $key++,
                KeyType::STRING,
                'test',
                $valueType,
                $value
            ];
            yield [
                $key++,
                KeyType::STRING,
                '4321',
                $valueType,
                $value
            ];
            yield [
                $key++,
                KeyType::STRING_OR_INT,
                'test_2',
                $valueType,
                $value
            ];
            // A key containing another placeholder must not be substituted into.
            yield [
                $key++,
                KeyType::STRING,
                "it's:value:index",
                $valueType,
                $value
            ];
        }

    }

    public static function provideSettings(): \Generator
    {
        $key = 0;
        yield [$key++, new MemorySettings()];
        foreach ([new Native, new Igbinary, new Json] as $serializer) {
            foreach ([0, 100] as $ttl) {
                yield from [
                    [$key++, new RedisSettings(
                        RedisConfig::fromUri('redis://127.0.0.1'),
                        $serializer,
                        $ttl,
                    )],
                    [$key++, new PostgresSettings(
                        PostgresConfig::fromString('host=127.0.0.1:5432 user=postgres db=test'),
                        $serializer,
                        $ttl,
                    )],
                    [$key++, new MysqlSettings(
                        MysqlConfig::fromString('host=127.0.0.1:3306 user=root db=test'),
                        $serializer,
                        $ttl,
                    )],
                    [$key++, new MysqlSettings(
                        MysqlConfig::fromString('host=127.0.0.1:3306 user=root db=test'),
                        $serializer,
                        $ttl,
                        optimizeIfWastedMb: 0,
                    )],
                    [$key++, new SqliteSettings(
                        self::sqlitePath('test'),
                        $serializer,
                        $ttl,
                    )],
                ];
            }
        }
    }
}
