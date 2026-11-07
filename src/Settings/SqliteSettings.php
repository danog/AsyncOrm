<?php declare(strict_types=1);

/**
 * Copyright 2024 Daniil Gentili.
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
 * @copyright 2016-2023 Daniil Gentili <daniil@daniil.it>
 * @license   https://opensource.org/license/apache-2-0 Apache 2.0
 * @link https://github.com/danog/AsyncOrm AsyncOrm documentation
 */

namespace danog\AsyncOrm\Settings;

namespace danog\AsyncOrm\Settings;

use danog\AsyncOrm\Internal\Driver\SqliteArray;
use danog\AsyncOrm\Serializer;

/**
 * SQLite backend settings.
 *
 * Requires ext-pdo_sqlite. The database is queried directly from the event loop's thread
 * (a query takes a few microseconds, less than the client side of a query to a database
 * server), never waiting for locks or disk syncs there.
 */
final readonly class SqliteSettings extends DriverSettings
{
    /**
     * Absolute path of the database file.
     */
    public string $path;

    /**
     * @api
     *
     * @param string $path Absolute path of the database file (created if it doesn't exist).
     * @param ?Serializer $serializer to use for object and mixed type values, if null defaults to either Igbinary or Native.
     * @param int<0, max> $cacheTtl Cache TTL in seconds
     *
     * @psalm-capabilities read-props
     */
    public function __construct(
        string $path,
        ?Serializer $serializer = null,
        int $cacheTtl = self::DEFAULT_CACHE_TTL,
        /**
         * Whether to checkpoint the WAL in amphp/parallel workers.
         *
         * A checkpoint syncs the database file to disk. If false, or if no worker can be
         * started, SQLite checkpoints by itself once the WAL reaches 1000 pages, when
         * committing: that commit blocks the event loop while the disk syncs (a few
         * milliseconds on an SSD, more on slower storage).
         */
        public bool $checkpointInWorkers = true,
    ) {
        // Absolute: the path identifies the database (see getDbIdentifier), whatever the
        // working directory of the process that opens it.
        if (!\str_starts_with($path, '/') && !\preg_match('~^([a-zA-Z]:)?[\\\\/]~', $path)) {
            throw new \InvalidArgumentException("The path of the database file must be absolute, got '$path'!");
        }
        $this->path = $path;
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
        return $this->path;
    }

    /**
     * @psalm-pure
     */
    #[\Override]
    public function getDriverClass(): string
    {
        return SqliteArray::class;
    }
}
