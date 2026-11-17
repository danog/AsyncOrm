<?php declare(strict_types=1);

/**
 * Copyright 2026 Daniil Gentili.
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
 * @copyright 2016-2026 Daniil Gentili <daniil@daniil.it>
 * @license   https://opensource.org/license/apache-2-0 Apache 2.0
 * @link https://github.com/danog/AsyncOrm AsyncOrm documentation
 */

namespace danog\AsyncOrm\Internal\Driver;

use danog\AsyncOrm\DbArrayBuilder;
use danog\AsyncOrm\Driver\DriverArray;
use danog\AsyncOrm\Internal\Serializer\BsonValue;
use danog\AsyncOrm\KeyType;
use danog\AsyncOrm\Serializer;
use danog\AsyncOrm\Settings\MongoSettings;
use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\Command;
use MongoDB\Driver\Exception\CommandException;
use MongoDB\Driver\Manager;
use MongoDB\Driver\Query;

/**
 * MongoDB database backend.
 *
 * Each array is a collection, with one document {_id: key, v: value} per element. Keys are always stored as
 * strings (as with Redis), so that changing the key type of an array needs no migration.
 *
 * With danog/mongo-php-async-driver, every query suspends only the calling fiber.
 *
 * @internal
 *
 * @template TKey as array-key
 * @template TValue
 * @extends DriverArray<TKey, TValue>
 * @implements BatchWrites<TKey, TValue>
 */
final class MongoArray extends DriverArray implements BatchWrites
{
    /** Keys per delete of unsetMany(). */
    private const UNSET_MANY_LIMIT = 1000;
    /** The NamespaceNotFound error code. */
    private const NAMESPACE_NOT_FOUND = 26;
    private const TYPE_MAP = ['root' => 'array', 'document' => 'array', 'array' => 'array'];

    /** @var array<string, Manager> */
    private static array $managers = [];

    private readonly Manager $db;
    private readonly string $database;
    /** Namespace of the collection: database.collection. */
    private readonly string $ns;
    private readonly bool $castToInt;

    /**
     * @param Serializer<TValue> $serializer
     */
    public function __construct(DbArrayBuilder $config, Serializer $serializer)
    {
        $settings = $config->settings;
        \assert($settings instanceof MongoSettings);
        $this->castToInt = $config->keyType === KeyType::INT;
        parent::__construct($config, new BsonValue($config->valueType, $serializer));

        $this->database = $settings->database;
        $this->ns = "{$settings->database}.{$config->table}";

        $key = $settings->getDbIdentifier()."\0".\serialize($settings->uriOptions);
        if (!isset(self::$managers[$key])) {
            // Connecting is lazy: fail here, as the other backends do, rather than at the first query.
            $manager = new Manager($settings->uri, $settings->uriOptions);
            $manager->executeCommand('admin', new Command(['ping' => 1]));
            self::$managers[$key] = $manager;
        }
        $this->db = self::$managers[$key];
    }

    #[\Override]
    public function get(string|int $key): mixed
    {
        $cursor = $this->db->executeQuery($this->ns, new Query(['_id' => (string) $key], ['limit' => 1]));
        $cursor->setTypeMap(self::TYPE_MAP);
        foreach ($cursor as $document) {
            \assert(\is_array($document));
            return $this->serializer->deserialize($document['v']);
        }
        return null;
    }

    #[\Override]
    public function set(string|int $key, mixed $value): void
    {
        $bulk = new BulkWrite;
        $this->upsert($bulk, $key, $value);
        $this->db->executeBulkWrite($this->ns, $bulk);
    }

    /**
     * @param TKey $key
     * @param TValue $value
     */
    private function upsert(BulkWrite $bulk, string|int $key, mixed $value): void
    {
        $bulk->update(
            ['_id' => (string) $key],
            ['v' => $this->serializer->serialize($value)],
            ['upsert' => true],
        );
    }

    #[\Override]
    public function unset(string|int $key): void
    {
        $bulk = new BulkWrite;
        $bulk->delete(['_id' => (string) $key], ['limit' => 1]);
        $this->db->executeBulkWrite($this->ns, $bulk);
    }

    /**
     * One bulk write, which the driver splits into as few commands as the server's limits allow.
     */
    #[\Override]
    public function setMany(array $values): void
    {
        if (!$values) {
            return;
        }
        $bulk = new BulkWrite(['ordered' => false]);
        foreach ($values as $key => $value) {
            $this->upsert($bulk, $key, $value);
        }
        $this->db->executeBulkWrite($this->ns, $bulk);
    }

    #[\Override]
    public function unsetMany(array $keys): void
    {
        if (!$keys) {
            return;
        }
        $bulk = new BulkWrite(['ordered' => false]);
        foreach (\array_chunk($keys, self::UNSET_MANY_LIMIT) as $chunk) {
            $bulk->delete(['_id' => ['$in' => \array_map(static fn (string|int $key): string => (string) $key, $chunk)]], ['limit' => 0]);
        }
        $this->db->executeBulkWrite($this->ns, $bulk);
    }

    /**
     * Exact, unlike the count command without a query, which reads the collection's metadata (wrong after an
     * unclean shutdown).
     */
    #[\Override]
    public function count(): int
    {
        $cursor = $this->db->executeReadCommand($this->database, new Command([
            'aggregate' => $this->config->table,
            'pipeline' => [['$count' => 'n']],
            'cursor' => new \stdClass,
        ]));
        $cursor->setTypeMap(self::TYPE_MAP);
        foreach ($cursor as $document) {
            \assert(\is_array($document) && \is_int($document['n']));
            return $document['n'];
        }
        return 0;
    }

    #[\Override]
    public function clear(): void
    {
        $bulk = new BulkWrite;
        $bulk->delete([], ['limit' => 0]);
        $this->db->executeBulkWrite($this->ns, $bulk);
    }

    /**
     * @return \Traversable<TKey, TValue>
     */
    #[\Override]
    public function getIterator(): \Traversable
    {
        $cursor = $this->db->executeQuery($this->ns, new Query([]));
        $cursor->setTypeMap(self::TYPE_MAP);
        foreach ($cursor as $document) {
            \assert(\is_array($document) && \is_string($document['_id']));
            $key = $document['_id'];
            /** @var TKey */
            $key = $this->castToInt ? (int) $key : $key;
            yield $key => $this->serializer->deserialize($document['v']);
        }
    }

    /**
     * Replace this array's collection with another collection of the same database.
     *
     * @internal
     */
    protected function importFromTable(string $fromTable): void
    {
        if ($this->config->table === $fromTable) {
            return;
        }
        try {
            $this->db->executeCommand('admin', new Command([
                'renameCollection' => "{$this->database}.$fromTable",
                'to' => $this->ns,
                'dropTarget' => true,
            ]));
        } catch (CommandException $e) {
            if ($e->getCode() !== self::NAMESPACE_NOT_FOUND) {
                throw $e;
            }
            // Collections are only created by their first write: the previous array was empty.
            $this->clear();
        }
    }

    /**
     * A MongoDB deployment has no identifier telling it apart whatever address reaches it.
     *
     * @internal
     */
    protected function getDatabaseIdentity(): ?string
    {
        return null;
    }
}
