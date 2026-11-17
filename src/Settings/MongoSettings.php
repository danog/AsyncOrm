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

namespace danog\AsyncOrm\Settings;

use danog\AsyncOrm\Internal\Driver\MongoArray;
use danog\AsyncOrm\Serializer;

/**
 * MongoDB backend settings.
 *
 * Requires the mongodb extension: [danog/mongo-php-async-driver](https://github.com/danog/mongo-php-async-driver),
 * whose queries suspend only the calling fiber. The official ext-mongodb works too, but blocks the event loop
 * for every query.
 *
 * Each array is a collection of the database, with one document per element: a value can't be larger than a
 * BSON document (16 MiB), and string keys must be valid UTF-8.
 */
final readonly class MongoSettings extends DriverSettings
{
    /**
     * @api
     *
     * @param string $uri MongoDB connection string, e.g. mongodb://127.0.0.1:27017.
     * @param string $database Database name.
     * @param ?Serializer $serializer to use for object and mixed type values, if null defaults to either Igbinary or Native.
     * @param int<0, max> $cacheTtl Cache TTL in seconds
     * @param array<string, mixed> $uriOptions Options of the connection string (see MongoDB\Driver\Manager::__construct), overriding those of $uri.
     *
     * @psalm-capabilities read-props
     */
    public function __construct(
        #[\SensitiveParameter]
        public string $uri,
        public string $database,
        ?Serializer $serializer = null,
        int $cacheTtl = self::DEFAULT_CACHE_TTL,
        #[\SensitiveParameter]
        public array $uriOptions = [],
    ) {
        if ($database === '') {
            throw new \InvalidArgumentException('The database name cannot be empty!');
        }
        parent::__construct($serializer, $cacheTtl);
    }

    /**
     * @internal
     *
     * @psalm-capabilities read-props
     */
    #[\Override]
    public function getDbIdentifier(): string
    {
        return "{$this->uri}\0{$this->database}";
    }

    /**
     * @psalm-pure
     */
    #[\Override]
    public function getDriverClass(): string
    {
        return MongoArray::class;
    }
}
