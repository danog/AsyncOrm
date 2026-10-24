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

namespace danog\AsyncOrm\Driver;

use danog\AsyncOrm\DbArray;
use danog\AsyncOrm\DbArrayBuilder;
use danog\AsyncOrm\Serializer;
use danog\AsyncOrm\Settings\DriverSettings;
use danog\AsyncOrm\Settings\SqlSettings;

use function Amp\async;
use function Amp\Future\await;

/**
 * Base class for driver-based arrays.
 *
 * @template TKey as array-key
 * @template TValue
 *
 * @psalm-consistent-constructor
 *
 * @extends DbArray<TKey, TValue>
 *
 * @api
 */
abstract class DriverArray extends DbArray
{
    private bool $inited = false;
    /**
     * @param Serializer<TValue> $serializer
     *
     * @psalm-impure Subclasses connect to the database, and the constructor is consistent.
     */
    protected function __construct(
        protected readonly DbArrayBuilder $config,
        protected readonly Serializer $serializer
    ) {
        $this->inited = true;
    }

    /**
     * @template TTKey as array-key
     * @template TTValue
     * @param DbArray<TTKey, TTValue> $previous
     * @return DbArray<TTKey, TTValue>
     */
    #[\Override]
    public static function getInstance(DbArrayBuilder $config, DbArray|null $previous): DbArray
    {
        $migrate = true;
        /** @psalm-suppress DocblockTypeContradiction TODO fix in psalm */
        if ($previous !== null
            && $previous::class === static::class
            && $previous->config == $config
        ) {
            if ($previous->inited) {
                return $previous;
            }
            $migrate = false;
        }
        \assert($config->settings instanceof DriverSettings);

        /** @var DbArray<TTKey, TTValue> */
        $instance = new static($config, $config->settings->serializer);

        if ($previous === null || !$migrate) {
            return $instance;
        }

        /** @psalm-suppress DocblockTypeContradiction TODO fix in psalm */
        $sameDriver = $previous instanceof SqlArray
            && $instance instanceof SqlArray
            && $previous::class === $instance::class;
        if ($sameDriver) {
            \assert($config->settings instanceof SqlSettings);
            \assert($previous->config->settings instanceof SqlSettings);
            $c = $config->settings->config;
            $prevC = $previous->config->settings->config;
            if ($c->getHost() === $prevC->getHost()
                && $c->getPort() === $prevC->getPort()
                && $c->getDatabase() === $prevC->getDatabase()
            ) {
                return self::sameDatabase($instance, $previous);
            }
        }

        /** @psalm-suppress DocblockTypeContradiction TODO fix in psalm */
        if ($previous instanceof self && !$previous->inited) {
            // Unserialized (e.g. from a session) and not woken up yet: it has its settings,
            // but no connection or queries. Bring it up from its own settings to read it.
            /** @var DbArray<TTKey, TTValue> */
            $previous = $previous::getInstance($previous->config, null);
        }

        // The old rows are deleted only once they're known to be stored elsewhere: by
        // another driver, or by an SQL server and database that tell they're not the same.
        // Otherwise the previous array may be the very same storage under other settings
        // (another address, cache TTL or serializer), and the copy rewrites its rows in place.
        $deleteOld = $previous::class !== $instance::class;
        if ($sameDriver) {
            /** @psalm-suppress DocblockTypeContradiction TODO fix in psalm */
            \assert($previous instanceof SqlArray);
            // The same server and database under another address (e.g. TCP and a unix
            // socket): copying the rows would read and rewrite the very same table.
            $id = $instance->getDatabaseIdentity();
            $prevId = $previous->getDatabaseIdentity();
            if ($id !== null && $id === $prevId) {
                return self::sameDatabase($instance, $previous);
            }
            $deleteOld = $id !== null && $prevId !== null;
        }

        $promises = [];
        foreach ($previous->getIterator() as $key => $value) {
            // The new copy first: if this is interrupted, a row is at worst in both.
            $f = async($instance->set(...), $key, $value);
            if ($deleteOld) {
                $f = $f->map(static fn () => $previous->unset($key));
            }
            $promises []= $f;
            if (\count($promises) % 500 === 0) {
                // @codeCoverageIgnoreStart
                await($promises);
                $promises = [];
                // @codeCoverageIgnoreEnd
            }
        }
        if ($promises) {
            await($promises);
        }

        return $instance;
    }

    /**
     * The array is on the same database as before: at most, its table was renamed.
     *
     * @template TTKey as array-key
     * @template TTValue
     * @param SqlArray<TTKey, TTValue> $instance
     * @return SqlArray<TTKey, TTValue>
     */
    private static function sameDatabase(SqlArray $instance, SqlArray $previous): SqlArray
    {
        if ($instance->config->table !== $previous->config->table) {
            $instance->importFromTable($previous->config->table);
        }
        // Only keyType/valueType changed, and we already migrated those on construction.
        return $instance;
    }

    /**
     * Sleep function.
     *
     * @psalm-pure
     */
    public function __sleep(): array
    {
        return ['config'];
    }
}
