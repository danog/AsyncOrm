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
use danog\AsyncOrm\FieldConfig;
use danog\AsyncOrm\Internal\Containers\CacheContainer;
use danog\AsyncOrm\Settings\DriverSettings;
use Revolt\EventLoop;
use Traversable;

/**
 * Array caching proxy.
 *
 * @template TKey as array-key
 * @template TValue
 *
 * @internal
 * @api
 *
 * @extends DbArray<TKey, TValue>
 */
final class CachedArray extends DbArray
{
    /** @var CacheContainer<TKey, TValue> */
    private readonly CacheContainer $cache;

    /**
     * Get instance.
     */
    public static function getInstance(FieldConfig $config, DbArray|null $previous): DbArray
    {
        \assert($config->settings instanceof DriverSettings);
        $new = $config->settings->getDriverClass();
        if ($previous === null) {
            $previous = new self($new::getInstance($config, null), $config->settings->cacheTtl);
        } elseif ($previous instanceof self) {
            $previous->cache->inner = $new::getInstance($config, $previous->cache->inner);
            $previous->cache->cacheTtl = $config->settings->cacheTtl;
        } else {
            $previous = new self($new::getInstance($config, $previous), $config->settings->cacheTtl);
        }
        $previous->cache->startCacheCleanupLoop();
        return $previous;
    }

    public function __construct(DbArray $inner, int $cacheTtl)
    {
        $this->cache = new CacheContainer($inner, $cacheTtl);
    }

    public function __destruct()
    {
        $this->cache->stopCacheCleanupLoop();
        EventLoop::queue($this->cache->flushCache(...));
    }

    public function flushCache(): void
    {
        $this->cache->flushCache();
    }

    public function count(): int
    {
        return $this->cache->count();
    }

    public function clear(): void
    {
        $this->cache->clear();
    }

    /** @param TKey $key */
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
        $this->cache->set($key, null);
    }

    /** @return Traversable<TKey, TValue> */
    public function getIterator(): Traversable
    {
        return $this->cache->getIterator();
    }
}
