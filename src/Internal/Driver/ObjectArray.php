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

use danog\AsyncOrm\DbArray;
use danog\AsyncOrm\DbObject;
use danog\AsyncOrm\Driver\MemoryArray;
use danog\AsyncOrm\FieldConfig;
use danog\AsyncOrm\Internal\Containers\ObjectContainer;
use danog\AsyncOrm\Settings\DriverSettings;
use Traversable;

/**
 * Object caching proxy.
 *
 * @internal
 *
 * @template TKey as array-key
 * @template TValue as DbObject
 *
 * @extends DbArray<TKey, TValue>
 */
final class ObjectArray extends DbArray
{
    /** @var ObjectContainer<TKey, TValue> */
    private readonly ObjectContainer $cache;

    /**
     * Get instance.
     */
    public static function getInstance(FieldConfig $config, DbArray|null $previous): DbArray
    {
        $new = $config->settings->getDriverClass();
        \assert($config->settings instanceof DriverSettings);
        if ($previous === null) {
            /** @psalm-suppress MixedArgumentTypeCoercion */
            $previous = new self($new::getInstance($config, null), $config, $config->settings->cacheTtl);
        } elseif ($previous instanceof self) {
            /** @psalm-suppress MixedPropertyTypeCoercion, InvalidArgument */
            $previous->cache->inner = $new::getInstance($config, $previous->cache->inner);
            $previous->cache->config = $config;
            $previous->cache->cacheTtl = $config->settings->cacheTtl;
        } else {
            /** @psalm-suppress MixedArgumentTypeCoercion */
            $previous = new self($new::getInstance($config, $previous), $config, $config->settings->cacheTtl);
        }
        if ($previous->cache->inner instanceof MemoryArray) {
            $previous->cache->flushCache();
            return $previous->cache->inner;
        }
        $previous->cache->startCacheCleanupLoop();
        return $previous;
    }

    /** @param DbArray<TKey, TValue> $inner */
    public function __construct(DbArray $inner, FieldConfig $config, int $cacheTtl)
    {
        $this->cache = new ObjectContainer($inner, $config, $cacheTtl);
    }

    public function __destruct()
    {
        $this->cache->stopCacheCleanupLoop();
    }

    /** @api */
    public function count(): int
    {
        return $this->cache->count();
    }

    public function clear(): void
    {
        $this->cache->clear();
    }

    /**
     * @param TKey $key
     * @return TValue
     */
    public function get(mixed $key): mixed
    {
        return $this->cache->get($key);
    }

    /**
     * @param TKey $key
     * @param TValue $value
     */
    public function set(string|int $key, mixed $value): void
    {
        $this->cache->set($key, $value);
    }

    /** @param TKey $key */
    public function unset(string|int $key): void
    {
        $this->cache->unset($key);
    }

    /** @return Traversable<TKey, TValue> */
    public function getIterator(): Traversable
    {
        return $this->cache->getIterator();
    }
}
