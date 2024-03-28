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
 * @copyright 2016-2024 Daniil Gentili <daniil@daniil.it>
 * @license   https://opensource.org/licenses/AGPL-3.0 AGPLv3
 * @link https://daniil.it/AsyncOrm AsyncOrm documentation
 */

namespace danog\AsyncOrm\Test;

use Amp\ByteStream\ReadableStream;
use Amp\Mysql\MysqlConfig;
use Amp\Postgres\PostgresConfig;
use Amp\Process\Process;
use Amp\Redis\RedisConfig;
use AssertionError;
use danog\AsyncOrm\DbArray;
use danog\AsyncOrm\Driver\DriverArray;
use danog\AsyncOrm\Driver\MemoryArray;
use danog\AsyncOrm\Driver\SqlArray;
use danog\AsyncOrm\FieldConfig;
use danog\AsyncOrm\Internal\Containers\CacheContainer;
use danog\AsyncOrm\Internal\Driver\CachedArray;
use danog\AsyncOrm\Internal\Driver\MysqlArray;
use danog\AsyncOrm\Internal\Driver\PostgresArray;
use danog\AsyncOrm\Internal\Driver\RedisArray;
use danog\AsyncOrm\Internal\Serializer\ByteaSerializer;
use danog\AsyncOrm\Internal\Serializer\IntString;
use danog\AsyncOrm\Internal\Serializer\Passthrough;
use danog\AsyncOrm\KeyType;
use danog\AsyncOrm\Serializer;
use danog\AsyncOrm\Serializer\Igbinary;
use danog\AsyncOrm\Serializer\Json;
use danog\AsyncOrm\Serializer\Native;
use danog\AsyncOrm\Settings;
use danog\AsyncOrm\Settings\Memory;
use danog\AsyncOrm\Settings\Mysql;
use danog\AsyncOrm\Settings\Postgres;
use danog\AsyncOrm\Settings\Redis;
use danog\AsyncOrm\Settings\SqlSettings;
use danog\AsyncOrm\ValueType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Amp\async;
use function Amp\ByteStream\buffer;
use function Amp\ByteStream\getStderr;
use function Amp\ByteStream\getStdout;
use function Amp\ByteStream\pipe;
use function Amp\ByteStream\splitLines;
use function Amp\Future\await;
use function Amp\Future\awaitAny;

#[CoversClass(FieldConfig::class)]
#[CoversClass(KeyType::class)]
#[CoversClass(ValueType::class)]
#[CoversClass(Serializer::class)]
#[CoversClass(Passthrough::class)]
#[CoversClass(Native::class)]
#[CoversClass(Json::class)]
#[CoversClass(Igbinary::class)]
#[CoversClass(ByteaSerializer::class)]
#[CoversClass(IntString::class)]
#[CoversClass(CacheContainer::class)]
#[CoversClass(CachedArray::class)]
#[CoversClass(DbArray::class)]
#[CoversClass(DriverArray::class)]
#[CoversClass(SqlArray::class)]
#[CoversClass(PostgresArray::class)]
#[CoversClass(RedisArray::class)]
#[CoversClass(MemoryArray::class)]
#[CoversClass(MysqlArray::class)]
#[CoversClass(Settings::class)]
#[CoversClass(Memory::class)]
#[CoversClass(Postgres::class)]
#[CoversClass(Mysql::class)]
#[CoversClass(Redis::class)]
#[CoversClass(SqlSettings::class)]
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
    public static function setUpBeforeClass(): void
    {
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
        foreach (self::$processes as $name => $process) {
            $ok = awaitAny([
                async(self::waitForStartup(...), $process->getStdout()),
                async(self::waitForStartup(...), $process->getStderr()),
            ]);
            if (!$ok) {
                throw new AssertionError("Could not start $name!");
            }
        }
    }
    private static function waitForStartup(ReadableStream $f): bool
    {
        foreach (splitLines($f) as $line) {
            if (\stripos($line, 'ready to ') !== false
                || \stripos($line, "socket: '/run/mysqld/mysqld.sock'  port: 3306") !== false
            ) {
                async(buffer(...), $f);
                return true;
            }
        }
        return false;
    }
    public static function tearDownAfterClass(): void
    {
        foreach (self::$processes as $process) {
            $process->signal(15);
        }
    }

    #[DataProvider('provideSettingsKeys')]
    public function testBasic(Settings $settings, KeyType $keyType, string|int $key): void
    {
        $field = new FieldConfig(
            'testBasic',
            $settings,
            $keyType,
            ValueType::INT
        );
        $orm = $field->build();
        $orm[$key] = 123;

        $this->assertSame(123, $orm[$key]);
        $this->assertTrue(isset($orm[$key]));
        $this->assertSame([$key => 123], $orm->getArrayCopy());
        unset($orm[$key]);

        $this->assertNull($orm[$key]);
        $this->assertFalse(isset($orm[$key]));

        if ($orm instanceof CachedArray) {
            $orm->flushCache();
        }

        $this->assertCount(0, $orm);
        $this->assertNull($orm[$key]);
        $this->assertFalse(isset($orm[$key]));
        $this->assertSame([], $orm->getArrayCopy());

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
        $orm[$key] = 124;

        $this->assertCount(1, $orm);
        $this->assertCount(1, $orm);
        $this->assertSame(124, $orm[$key]);
        $this->assertTrue(isset($orm[$key]));

        if ($orm instanceof CachedArray) {
            $orm->flushCache();
        }
        unset($orm);
        while (\gc_collect_cycles());

        $orm = $field->build();
        $this->assertSame(124, $orm[$key]);
        $this->assertTrue(isset($orm[$key]));

        unset($orm[$key]);
        $this->assertNull($orm[$key]);
        $this->assertFalse(isset($orm[$key]));

        if ($orm instanceof CachedArray) {
            $orm->flushCache();
        }

        $this->assertCount(0, $orm);
        $orm[$key] = 123;
        $this->assertCount(1, $orm);
        $cnt = 0;
        foreach ($orm as $kk => $vv) {
            $cnt++;
            $this->assertSame($key, $kk);
            $this->assertSame(123, $vv);
        }
        $this->assertEquals(1, $cnt);

        $orm->clear();
        $cnt = 0;
        foreach ($orm as $kk => $vv) {
            $cnt++;
        }
        $this->assertEquals(0, $cnt);
        $this->assertCount(0, $orm);
    }

    #[DataProvider('provideSettings')]
    public function testMigration(Settings $settings): void
    {
        $field = new FieldConfig(
            'testMigration',
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

        $field = new FieldConfig(
            'testMigration',
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

        $field = new FieldConfig(
            'testMigration',
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

        $field = new FieldConfig(
            'testMigration',
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
    }

    public static function provideSettingsKeys(): \Generator
    {
        foreach (self::provideSettings() as [$settings]) {
            yield [
                $settings,
                KeyType::INT,
                1234,
            ];
            yield [
                $settings,
                KeyType::STRING,
                'test',
            ];
            yield [
                $settings,
                KeyType::STRING,
                '4321',
            ];
            yield [
                $settings,
                KeyType::STRING_OR_INT,
                'test_2',
            ];
        }
    }

    public static function provideSettings(): \Generator
    {
        yield [new Memory()];
        foreach ([new Native, new Igbinary, new Json] as $serializer) {
            foreach ([0, 100] as $ttl) {
                yield from [
                    [new Redis(
                        RedisConfig::fromUri('redis://127.0.0.1'),
                        $serializer,
                        $ttl,
                    )],
                    [new Postgres(
                        PostgresConfig::fromString('host=127.0.0.1:5432 user=postgres db=test'),
                        $serializer,
                        $ttl,
                    )],
                    [new Mysql(
                        MysqlConfig::fromString('host=127.0.0.1:3306 user=root db=test'),
                        $serializer,
                        $ttl,
                    )],
                ];
            }
        }
    }
}
