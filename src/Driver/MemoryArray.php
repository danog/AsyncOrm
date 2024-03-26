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

namespace danog\AsyncOrm\Driver;

use ArrayObject;
use danog\AsyncOrm\DbArray;
use danog\AsyncOrm\FieldConfig;
use danog\AsyncOrm\Settings\Database\Memory;

/**
 * Memory database backend.
 *
 * @template TKey as array-key
 * @template TValue
 * @extends DbArray<TKey, TValue>
 * @api
 */
final class MemoryArray extends DbArray
{
    public function __construct(
        private array $data
    ) {
    }

    public static function getInstance(FieldConfig $config, DbArray|null $previous): DbArray
    {
        if ($previous instanceof self) {
            return $previous;
        }
        if ($previous instanceof DbArray) {
            $temp = $previous->getArrayCopy();
            $previous->clear();
            $previous = $temp;
        } else {
            $previous = [];
        }
        return new self($previous);
    }

    public function set(string|int $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }
    public function get(string|int $key): mixed
    {
        return $this->data[$key] ?? null;
    }
    public function unset(string|int $key): void
    {
        unset($this->data[$key]);
    }

    public function clear(): void
    {
        $this->data = [];
    }

    public function count(): int
    {
        return \count($this->data);
    }

    public function getIterator(): \Traversable
    {
        return new ArrayObject($this);
    }

    public function getArrayCopy(): array
    {
        return $this->data;
    }
}
