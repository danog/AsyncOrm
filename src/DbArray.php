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

use ArrayAccess;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * DB array interface.
 *
 * @template TKey as array-key
 * @template TValue
 *
 * @implements ArrayAccess<TKey, TValue>
 * @implements Traversable<TKey, TValue>
 * @implements IteratorAggregate<TKey, TValue>
 *
 * @api
 */
abstract class DbArray implements Countable, ArrayAccess, Traversable, IteratorAggregate
{
    /**
     * Check if element exists.
     *
     * @param TKey $key
     */
    final public function isset(string|int $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * @param TKey $offset
     * @return TValue
     */
    final public function offsetGet(mixed $offset): mixed
    {
        return $this->get($offset);
    }

    /**
     * @param TKey $offset
     */
    final public function offsetExists(mixed $offset): bool
    {
        return $this->isset($offset);
    }

    /**
     * @param TKey $offset
     * @param TValue $value
     */
    final public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->set($offset, $value);
    }

    /**
     * @param TKey $offset
     */
    final public function offsetUnset(mixed $offset): void
    {
        $this->unset($offset);
    }

    public function getArrayCopy(): array
    {
        return \iterator_to_array($this->getIterator());
    }

    /**
     * Unset element.
     *
     * @param TKey $key
     */
    abstract public function unset(string|int $key): void;
    /**
     * Set element.
     *
     * @param TKey   $key
     * @param TValue $value
     */
    abstract public function set(string|int $key, mixed $value): void;
    /**
     * Get element.
     *
     * @param TKey   $key
     * @return ?TValue
     */
    abstract public function get(string|int $key): mixed;
    /**
     * Clear all elements.
     */
    abstract public function clear(): void;

    /**
     * Get instance.
     */
    abstract public static function getInstance(FieldConfig $config, self|null $previous): self;
}
