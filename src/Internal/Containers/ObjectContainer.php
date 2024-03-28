<?php declare(strict_types=1);

/**
 * This file is part of AsyncOrm.
 * AsyncOrm is free software: you can redistribute it and/or modify it under the terms of the GNU Affero General private License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.
 * AsyncOrm is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU Affero General private License for more details.
 * You should have received a copy of the GNU General private License along with AsyncOrm.
 * If not, see <http://www.gnu.org/licenses/>.
 *
 * @author    Daniil Gentili <daniil@daniil.it>
 * @author    Alexander Pankratov <alexander@i-c-a.su>
 * @copyright 2016-2024 Daniil Gentili <daniil@daniil.it>
 * @copyright 2016-2024 Alexander Pankratov <alexander@i-c-a.su>
 * @license   https://opensource.org/licenses/AGPL-3.0 AGPLv3
 * @link https://daniil.it/AsyncOrm AsyncOrm documentation
 */

namespace danog\AsyncOrm\Internal\Containers;

use Amp\Sync\LocalMutex;
use danog\AsyncOrm\DbArray;
use danog\AsyncOrm\DbObject;
use danog\AsyncOrm\FieldConfig;
use Revolt\EventLoop;
use Traversable;

/** @internal */
final class ObjectContainer
{
    /**
     * @var array<ObjectReference>
     */
    private array $cache = [];

    private int $cacheTtl;

    /**
     * Cache cleanup watcher ID.
     */
    private ?string $cacheCleanupId = null;

    private LocalMutex $mutex;

    public function __construct(
        /** @var DbArray<array-key, DbObject> */
        public DbArray $inner,
        public FieldConfig $config,
    ) {
        $this->mutex = new LocalMutex;
    }
    public function __sleep()
    {
        return ['inner'];
    }
    public function __wakeup(): void
    {
        $this->mutex = new LocalMutex;
    }

    public function startCacheCleanupLoop(int $cacheTtl): void
    {
        $this->cacheTtl = $cacheTtl;
        if ($this->cacheCleanupId !== null) {
            EventLoop::cancel($this->cacheCleanupId);
        }
        $this->cacheCleanupId = EventLoop::repeat(
            \max(1, $this->cacheTtl / 5),
            fn () => $this->flushCache(),
        );
    }
    public function stopCacheCleanupLoop(): void
    {
        if ($this->cacheCleanupId !== null) {
            EventLoop::cancel($this->cacheCleanupId);
            $this->cacheCleanupId = null;
        }
    }

    public function get(string|int $index): mixed
    {
        if (isset($this->cache[$index])) {
            $obj = $this->cache[$index];
            $ref = $obj->reference->get();
            if ($ref !== null) {
                $obj->ttl = \time() + $this->cacheTtl;
                return $ref;
            }
            unset($this->cache[$index]);
        }

        $result = $this->inner->get($index);
        if (isset($this->cache[$index])) {
            return $this->cache[$index]->reference->get();
        }
        if ($result === null) {
            return null;
        }
        \var_dump($result);

        $result->initDb($this, $index, $this->config);

        $this->cache[$index] = new ObjectReference($result, \time() + $this->cacheTtl);

        return $result;
    }

    public function set(string|int $key, DbObject $value): void
    {
        if (isset($this->cache[$key]) && $this->cache[$key]->reference->get() === $value) {
            return;
        }
        $value->initDb($this, $key, $this->config);
        $this->cache[$key] = new ObjectReference($value, \time() + $this->cacheTtl);
        $value->save();
    }

    public function unset(string|int $key): void
    {
        unset($this->cache[$key]);
        $this->inner->unset($key);
    }

    public function getIterator(): Traversable
    {
        $this->flushCache();
        foreach ($this->inner->getIterator() as $key => $value) {
            if (isset($this->cache[$key])) {
                $obj = $this->cache[$key];
                $ref = $obj->reference->get();
                if ($ref !== null) {
                    $obj->ttl = \time() + $this->cacheTtl;
                    yield $obj;
                    continue;
                }
            }
            $value->initDb($this, $key, $this->config);
            $this->cache[$key] = new ObjectReference($value, \time() + $this->cacheTtl);
            yield $value;
        }
    }

    public function count(): int
    {
        $this->flushCache();
        return $this->inner->count();
    }

    public function clear(): void
    {
        $lock = $this->mutex->acquire();
        $this->cache = [];
        $lock->release();

        $this->inner->clear();
    }

    /**
     * Flush all flushable keys.
     */
    public function flushCache(): void
    {
        $lock = $this->mutex->acquire();
        try {
            $now = \time();
            $new = [];
            foreach ($this->cache as $key => $value) {
                if ($value->ttl > $now) {
                    $value->obj = null;
                }
                if ($value->reference->get() !== null) {
                    $new[$key] = $value;
                }
            }
            $this->cache = $new;
        } finally {
            EventLoop::queue($lock->release(...));
        }
    }
}
