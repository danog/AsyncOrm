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
use Amp\DeferredFuture;
use Amp\Mysql\MysqlConfig;
use Amp\Postgres\PostgresConfig;
use Amp\Process\Process;
use Amp\Redis\RedisConfig;
use AssertionError;
use danog\AsyncOrm\DbArrayBuilder;
use danog\AsyncOrm\DbObject;
use danog\AsyncOrm\Driver\MemoryArray;
use danog\AsyncOrm\Internal\Containers\CacheContainer;
use danog\AsyncOrm\Internal\Driver\CachedArray;
use danog\AsyncOrm\Internal\Driver\ObjectArray;
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
        if (\fgets($lockFile) === 'done') {
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
        foreach (self::$processes as $name => $process) {
            $ok = awaitAny([
                async(self::waitForStartup(...), $process->getStdout()),
                async(self::waitForStartup(...), $process->getStderr()),
            ]);
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

    public function assertSameNotObject(mixed $a, mixed $b): void
    {
        if ($b instanceof DbObject) {
            $this->assertSame($a::class, $b::class);
        } else {
            $this->assertSame($a, $b);
        }
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
        $f = new DeferredFuture;
        EventLoop::queue($f->complete(...));
        $f->getFuture()->await();

        $orm = $field->build();
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
        $this->assertSame(54321, $obj->arr[12345]);
        $this->assertSame(654321, $obj->arr2[123456]);
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

    public function testCache(): void
    {
        $field = new DbArrayBuilder("testCache", new RedisSettings(
            RedisConfig::fromUri("redis://127.0.0.1"),
            cacheTtl: 1
        ), KeyType::INT, ValueType::INT);
        $fieldNoCache = new DbArrayBuilder("testCache", new RedisSettings(
            RedisConfig::fromUri("redis://127.0.0.1"),
            cacheTtl: 0
        ), KeyType::INT, ValueType::INT);
        $orm = $field->build();
        $ormUnCached = $fieldNoCache->build();

        $orm->set(0, 1);
        $this->assertCount(0, $ormUnCached);
        delay(0.1);
        $this->assertCount(0, $ormUnCached);
        delay(0.9);
        $this->assertCount(1, $ormUnCached);
        delay(1.0);
        /** @var CacheContainer */
        $c = (new ReflectionProperty(CachedArray::class, 'cache'))->getValue($orm);
        $this->assertCount(0, (new ReflectionProperty(CacheContainer::class, 'cache'))->getValue($c));

        $f1 = async($orm->get(...), 0);
        $f2 = async($orm->get(...), 0);
        $this->assertSame(1, $f1->await());
        $this->assertSame(1, $f2->await());

        $orm->clear();

        $obj = new TestObject;
        $obj->initDbProperties(new RedisSettings(
            RedisConfig::fromUri("redis://127.0.0.1"),
            cacheTtl: 1
        ), 'testCacheMore_');

        $fieldNoCache2 = new DbArrayBuilder("testCacheMore_arr2", new RedisSettings(
            RedisConfig::fromUri("redis://127.0.0.1"),
            cacheTtl: 0
        ), KeyType::INT, ValueType::INT);
        $orm2Uncached = $fieldNoCache2->build();

        $fieldNoCache4 = new DbArrayBuilder("testCacheMore_arr4", new RedisSettings(
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
        $orm2Uncached->clear();

        $obj->arr4->set(0, 1);
        $this->assertCount(1, $orm4Uncached);
        $orm4Uncached->clear();
    }

    public static function provideSettingsKeysValues(): \Generator
    {
        $key = 0;
        foreach (self::provideSettings() as [, $settings]) {
            foreach ([
                [ValueType::INT, 123],
                [ValueType::STRING, '123'],
                [ValueType::STRING, 'test'],
                [ValueType::FLOAT, 123.321],
                [ValueType::BOOL, true],
                [ValueType::BOOL, false],

                // Uncomment when segfaults are fixed
                [ValueType::OBJECT, new TestObject],

                [ValueType::SCALAR, 'test'],
                [ValueType::SCALAR, 123],
                [ValueType::SCALAR, ['test' => 123]],
                [ValueType::SCALAR, 123.321],
            ] as [$valueType, $value]) {
                if ($valueType === ValueType::OBJECT && (
                    $settings instanceof MemorySettings
                    || $settings->serializer instanceof Json
                )) {
                    continue;
                }
                yield [
                    $key++,
                    $settings,
                    KeyType::INT,
                    1234,
                    $valueType,
                    $value
                ];
                yield [
                    $key++,
                    $settings,
                    KeyType::STRING,
                    'test',
                    $valueType,
                    $value
                ];
                yield [
                    $key++,
                    $settings,
                    KeyType::STRING,
                    '4321',
                    $valueType,
                    $value
                ];
                yield [
                    $key++,
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
                ];
            }
        }
    }
}
