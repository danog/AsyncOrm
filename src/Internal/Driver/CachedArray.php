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
use danog\AsyncOrm\Driver\MemoryArray;
use danog\AsyncOrm\FieldConfig;
use Traversable;

/**
 * Array caching proxy.
 *
 * @internal
 *
 * @template TKey as array-key
 * @template TValue
 *
 * @extends DbArray<TKey, TValue>
 */
final class CachedArray extends DbArray
{
    private readonly CacheContainer $cache;

    /**
     * Get instance.
     */
    public static function getInstance(FieldConfig $config, DbArray|null $previous): DbArray
    {
        $new = $config->settings->getDriverClass();
        if ($previous === null) {
            $previous = new self($new::getInstance($config, null));
        } elseif ($previous instanceof self) {
            $previous->cache->inner = $new::getInstance($config, $previous->cache->inner);
        } else {
            $previous = new self($new::getInstance($config, $previous));
        }
        if ($previous->cache->inner instanceof MemoryArray) {
            $previous->cache->flushCache();
            return $previous->cache->inner;
        }
        $previous->cache->startCacheCleanupLoop($config->annotation->cacheTtl);
        return $previous;
    }

    public function __construct(DbArray $inner)
    {
        $this->cache = new CacheContainer($inner);
    }

    public function __destruct()
    {
        $this->cache->stopCacheCleanupLoop();
    }

    public function count(): int
    {
        return $this->cache->count();
    }

    public function clear(): void
    {
        $this->cache->clear();
    }

    public function get(mixed $index): mixed
    {
        return $this->cache->get($index);
    }

    public function set(string|int $key, mixed $value): void
    {
        $this->cache->set($key, $value);
    }

    public function unset(string|int $key): void
    {
        $this->cache->set($key, null);
    }

    public function getIterator(): Traversable
    {
        return $this->cache->getIterator();
    }
}
