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
use danog\AsyncOrm\DbObject;
use danog\AsyncOrm\Driver\MemoryArray;
use danog\AsyncOrm\FieldConfig;
use danog\AsyncOrm\Internal\Driver\CachedArray;
use danog\AsyncOrm\Internal\Driver\ObjectArray;
use danog\AsyncOrm\KeyType;
use danog\AsyncOrm\Serializer\Igbinary;
use danog\AsyncOrm\Serializer\Json;
use danog\AsyncOrm\Serializer\Native;
use danog\AsyncOrm\Settings;
use danog\AsyncOrm\Settings\DriverSettings;
use danog\AsyncOrm\Settings\Memory;
use danog\AsyncOrm\Settings\Mysql;
use danog\AsyncOrm\Settings\Postgres;
use danog\AsyncOrm\Settings\Redis;
use danog\AsyncOrm\ValueType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use WeakReference;

use function Amp\async;
use function Amp\ByteStream\buffer;
use function Amp\ByteStream\getStderr;
use function Amp\ByteStream\getStdout;
use function Amp\ByteStream\pipe;
use function Amp\ByteStream\splitLines;
use function Amp\Future\await;
use function Amp\Future\awaitAny;

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

    public function assertSameNotObject(mixed $a, mixed $b): void
    {
        if ($b instanceof DbObject) {
            $this->assertSame($a::class, $b::class);
        } else {
            $this->assertSame($a, $b);
        }
    }
    private static int $cnt = 0;

    #[DataProvider('provideSettingsKeysValues')]
    public function testBasic(Settings $settings, KeyType $keyType, string|int $key, ValueType $valueType, mixed $value): void
    {
        $cnt = self::$cnt++;
        $field = new FieldConfig(
            "testBasic_$cnt",
            $settings,
            $keyType,
            $valueType
        );
        $orm = $field->build();
        $orm[$key] = $value;

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
    }

    #[DataProvider('provideSettings')]
    public function testKeyMigration(Settings $settings): void
    {
        $field = new FieldConfig(
            'testKeyMigration',
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
            'testKeyMigration',
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
            'testKeyMigration',
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
            'testKeyMigration',
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

    #[DataProvider('provideSettings')]
    public function testObject(Settings $settings): void
    {
        if (!$settings instanceof DriverSettings) {
            $this->expectExceptionMessage("Objects can only be saved to a database backend!");
        }
        if ($settings->serializer instanceof Json) {
            $this->expectExceptionMessage("The JSON backend cannot be used when serializing objects!");
        }
        $field = new FieldConfig(
            'testObject',
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

        $obj = $orm[321];

        $this->assertSame(1, $obj->loadedCnt);
        $this->assertSame(1, $obj->saveAfterCnt);
        $this->assertSame(1, $obj->saveBeforeCnt);

        unset($obj);
        $orm = $field->build();
        $obj = $orm[321];

        $this->assertSame(1, $obj->loadedCnt);
        $this->assertSame(0, $obj->saveAfterCnt);
        $this->assertSame(0, $obj->saveBeforeCnt);

        $orm[321] = $obj;

        $this->assertSame(1, $obj->loadedCnt);
        $this->assertSame(0, $obj->saveAfterCnt);
        $this->assertSame(0, $obj->saveBeforeCnt);

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
    }

    public static function provideSettingsKeysValues(): \Generator
    {
        foreach (self::provideSettings() as [$settings]) {
            foreach ([
                [ValueType::INT, 123],
                [ValueType::STRING, '123'],
                [ValueType::STRING, 'test'],
                [ValueType::FLOAT, 123.321],
                [ValueType::BOOL, true],
                [ValueType::BOOL, false],

                [ValueType::OBJECT, new TestObject],

                [ValueType::SCALAR, 'test'],
                [ValueType::SCALAR, 123],
                [ValueType::SCALAR, ['test' => 123]],
                [ValueType::SCALAR, 123.321],
                [ValueType::SCALAR, new TestObject],
            ] as [$valueType, $value]) {
                if ($valueType === ValueType::OBJECT && (
                    $settings instanceof Memory
                    || $settings->serializer instanceof Json
                )) {
                    continue;
                }
                yield [
                    $settings,
                    KeyType::INT,
                    1234,
                    $valueType,
                    $value
                ];
                yield [
                    $settings,
                    KeyType::STRING,
                    'test',
                    $valueType,
                    $value
                ];
                yield [
                    $settings,
                    KeyType::STRING,
                    '4321',
                    $valueType,
                    $value
                ];
                yield [
                    $settings,
                    KeyType::STRING_OR_INT,
                    'test_2',
                    $valueType,
                    $value
                ];
            }
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
