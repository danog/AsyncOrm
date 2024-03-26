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

use Amp\Redis\Connection\ReconnectingRedisLink;
use Amp\Redis\RedisClient;
use Amp\Sync\LocalKeyedMutex;
use danog\AsyncOrm\Driver\DriverArray;
use danog\AsyncOrm\FieldConfig;
use danog\AsyncOrm\Serializer;
use danog\AsyncOrm\Serializer\IntString;
use danog\AsyncOrm\Serializer\Passthrough;
use danog\AsyncOrm\Settings\Redis;
use danog\AsyncOrm\ValueType;
use Revolt\EventLoop;

use function Amp\Redis\createRedisConnector;

/**
 * Redis database backend.
 *
 * @internal
 *
 * @template TKey as array-key
 * @template TValue
 * @extends DriverArray<TKey, TValue>
 */
final class RedisArray extends DriverArray
{
    /** @var array<RedisClient> */
    private static array $connections = [];
    private static ?LocalKeyedMutex $mutex = null;

    private readonly RedisClient $db;

    /**
     * @param Serializer<TValue> $serializer
     */
    public function __construct(FieldConfig $config, Serializer $serializer)
    {
        if ($serializer instanceof Passthrough && $config->valueType === ValueType::INT) {
            $serializer = new IntString;
        }
        parent::__construct($config, $serializer);

        self::$mutex ??= new LocalKeyedMutex;
        \assert($config->settings instanceof Redis);
        $dbKey = $config->settings->getDbIdentifier();
        $lock = self::$mutex->acquire($dbKey);

        try {
            if (!isset(self::$connections[$dbKey])) {
                self::$connections[$dbKey] = new RedisClient(new ReconnectingRedisLink(createRedisConnector($config->settings->config)));
                self::$connections[$dbKey]->ping();
            }
        } finally {
            EventLoop::queue($lock->release(...));
        }
        $this->db = self::$connections[$dbKey];
    }

    protected function moveDataFromTableToTable(string $from, string $to): void
    {
        $from = "va:$from";
        $to = "va:$to";

        $request = $this->db->scan($from.'*');

        $lenK = \strlen($from);
        foreach ($request as $oldKey) {
            $newKey = $to.\substr($oldKey, $lenK);
            $value = $this->db->get($oldKey);
            if ($value !== null) {
                $this->db->set($newKey, $value);
                $this->db->delete($oldKey);
            }
        }
    }

    /**
     * Get redis key name.
     */
    private function rKey(string $key): string
    {
        return 'va:'.$this->config->table.':'.$key;
    }

    /**
     * Get iterator key.
     */
    private function itKey(): string
    {
        return 'va:'.$this->config->table.'*';
    }

    public function set(string|int $key, mixed $value): void
    {
        $this->db->set($this->rKey((string) $key), $this->serializer->serialize($value));
    }

    public function get(mixed $key): mixed
    {
        $key = (string) $key;

        $value = $this->db->get($this->rKey($key));

        if ($value !== null) {
            /** @var TValue */
            $value = $this->serializer->deserialize($value);
        }

        return $value;
    }

    public function unset(string|int $key): void
    {
        $this->db->delete($this->rkey((string) $key));
    }

    /**
     * Get iterator.
     *
     * @return \Traversable<array-key, mixed>
     */
    public function getIterator(): \Traversable
    {
        $request = $this->db->scan($this->itKey());

        $len = \strlen($this->rKey(''));
        foreach ($request as $key) {
            yield \substr($key, $len) => $this->serializer->deserialize($this->db->get($key));
        }
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
        return \iterator_count($this->db->scan($this->itKey()));
    }

    /**
     * Clear all elements.
     */
    public function clear(): void
    {
        $request = $this->db->scan($this->itKey());

        $keys = [];
        foreach ($request as $key) {
            $keys[] = $key;
            if (\count($keys) === 10) {
                $this->db->delete(...$keys);
                $keys = [];
            }
        }
        if ($keys) {
            $this->db->delete(...$keys);
        }
    }
}
