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
 * @copyright 2016-2023 Daniil Gentili <daniil@daniil.it>
 * @license   https://opensource.org/licenses/AGPL-3.0 AGPLv3
 * @link https://daniil.it/AsyncOrm AsyncOrm documentation
 */

namespace danog\AsyncOrm;

use Amp\Sync\LocalKeyedMutex;
use AssertionError;
use WeakMap;

/**
 * Async DB mapper.
 *
 * @template T as DbObject
 * @template TKey as KeyType
 */
final readonly class DbMapper
{
    private readonly DbArray $arr;
    private readonly LocalKeyedMutex $mutex;
    private readonly WeakMap $inited;
    /**
     * Constructor.
     *
     * @param string $table Table name
     * @param class-string<T> Class of associated DbObject.
     * @param Settings $settings Settings
     * @param TKey $keyType Key type
     * @param int<0, max>|null $cacheTtl TTL of the cache, if null defaults to the value specified in the settings, if zero disables caching.
     * @param int<1, max>|null $optimizeIfWastedGtMb Optimize table if more than this many megabytes are wasted, if null defaults to the value specified in the settings.
     * @param ?self $previous Previous instance, used for migrations.
     */
    public function __construct(
        private readonly string $table,
        private readonly string $class,
        private readonly Settings $settings,
        KeyType $keyType,
        ?int $cacheTtl = null,
        ?int $optimizeIfWastedGtMb = null,
        ?self $previous = null
    ) {
        if (\is_subclass_of($class, DbArray::class)) {
            throw new AssertionError("$class must extend DbArray!");
        }
        $this->inited = new WeakMap;
        $this->mutex = new LocalKeyedMutex;
        $config = new FieldConfig(
            $table,
            $settings,
            $keyType,
            ValueType::OBJECT,
            $cacheTtl,
            $optimizeIfWastedGtMb
        );
        $this->arr = $config->get($previous?->arr);
        if ($previous !== null) {
            foreach ($previous->inited as $key => $obj) {
                $obj->__initDb($this->table, $this->settings);
                $this->inited[$key] = $obj;
            }
        }
    }

    /**
     * @param (TKey is KeyType::STRING ? string : (TKey is KeyType::INT ? int : string|int)) $key
     *
     * @return T
     */
    public function create(string|int $key): DbObject
    {
        $lock = $this->mutex->acquire((string) $key);
        try {
            if (isset($this->inited[$key])) {
                throw new AssertionError("An object under the key $key already exists!");
            }
            $obj = new $this->class;
            $obj->__initDb($this->table, $this->settings);
            $this->arr[$key] = $obj;
            $this->inited[$key] = $obj;
            return $obj;
        } finally {
            $lock->release();
        }
    }

    /**
     * Find DbObject by key.
     *
     * @param (TKey is KeyType::STRING ? string : (TKey is KeyType::INT ? int : string|int)) $key
     *
     * @return ?T
     */
    public function find(string|int $key): ?DbObject
    {
        $lock = $this->mutex->acquire((string) $key);
        try {
            if (isset($this->inited[$key])) {
                return $this->inited[$key];
            }
            $obj = $this->arr[$key];
            if ($obj !== null) {
                $obj->__initDb($this->table, $this->settings);
                $this->inited[$key] = $obj;
            }
            return $obj;
        } finally {
            $lock->release();
        }
    }
}
