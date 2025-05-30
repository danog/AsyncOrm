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

use Amp\Postgres\PostgresConfig;
use danog\AsyncOrm\Internal\Driver\PostgresArray;
use danog\AsyncOrm\Serializer;

/**
 * Postgres backend settings.
 * @extends SqlSettings<PostgresConfig>
 */
final readonly class PostgresSettings extends SqlSettings
{
    /**
     * @api
     * @param ?Serializer $serializer to use for object and mixed type values, if null defaults to either Igbinary or Native.
     * @param int<0, max> $cacheTtl Cache TTL in seconds
     * @param int<1, max> $maxConnections Maximum connection limit
     * @param int<1, max> $idleTimeout Idle timeout
     */
    public function __construct(
        PostgresConfig $config,
        ?Serializer $serializer = null,
        int $cacheTtl = self::DEFAULT_CACHE_TTL,
        int $maxConnections = self::DEFAULT_SQL_MAX_CONNECTIONS,
        int $idleTimeout = self::DEFAULT_SQL_IDLE_TIMEOUT,
    ) {
        parent::__construct($config, $serializer, $cacheTtl, $maxConnections, $idleTimeout);
    }

    #[\Override]
    public function getDriverClass(): string
    {
        return PostgresArray::class;
    }
}
