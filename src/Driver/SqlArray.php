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

use Amp\Sql\SqlConnectionPool;
use Amp\Sql\SqlResult;
use danog\AsyncOrm\FieldConfig;
use danog\AsyncOrm\Serializer;

/**
 * Generic SQL database backend.
 *
 * @template TKey as array-key
 * @template TValue
 * @extends DriverArray<TKey, TValue>
 * @api
 */
abstract class SqlArray extends DriverArray
{
    protected function __construct(
        FieldConfig $config,
        Serializer $serializer,
        protected readonly SqlConnectionPool $db,
        private readonly string $get,
        private readonly string $set,
        private readonly string $unset,
        private readonly string $count,
        private readonly string $iterate,
        private readonly string $clear,
    ) {
        parent::__construct($config, $serializer);
    }

    /**
     * Get iterator.
     *
     * @return \Traversable<array-key, mixed>
     */
    public function getIterator(): \Traversable
    {
        foreach ($this->execute($this->iterate) as ['key' => $key, 'value' => $value]) {
            yield $key => $this->serializer->deserialize($value);
        }
    }

    public function get(mixed $key): mixed
    {
        $key = (string) $key;

        $row = $this->execute($this->get, ['index' => $key])->fetchRow();
        if ($row === null) {
            return null;
        }

        $value = $this->serializer->deserialize($row['value']);

        return $value;
    }

    public function set(string|int $key, mixed $value): void
    {
        $key = (string) $key;

        $this->execute(
            $this->set,
            [
                'index' => $key,
                'value' => $this->serializer->serialize($value),
            ],
        );
    }

    /**
     * Unset value for an offset.
     *
     * @link https://php.net/manual/en/arrayiterator.offsetunset.php
     */
    public function unset(string|int $key): void
    {
        $key = (string) $key;

        $this->execute(
            $this->unset,
            ['index' => $key],
        );
    }

    /**
     * Count elements.
     *
     * @link https://php.net/manual/en/arrayiterator.count.php
     * @return int The number of elements or public properties in the associated
     *             array or object, respectively.
     */
    public function count(): int
    {
        $row = $this->execute($this->count);
        /** @var int */
        return $row->fetchRow()['count'];
    }

    /**
     * Clear all elements.
     */
    public function clear(): void
    {
        $this->execute($this->clear);
    }

    /**
     * Perform async request to db.
     *
     */
    protected function execute(string $sql, array $params = []): SqlResult
    {
        return $this->db->prepare($sql)->execute($params);
    }

    /**
     * Import data from existing table.
     */
    abstract protected function importFromTable(string $fromTable): void;
}
