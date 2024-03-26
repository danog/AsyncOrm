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

namespace danog\AsyncOrm\Settings;

use Amp\Sql\SqlConfig;
use danog\AsyncOrm\Serializer;

/**
 * Generic SQL db backend settings.
 *
 * @template T as SqlConfig
 */
abstract readonly class SqlSettings extends DriverSettings
{
    final public const DEFAULT_SQL_MAX_CONNECTIONS = 100;
    final public const DEFAULT_SQL_IDLE_TIMEOUT = 60;
    /**
     * Maximum connection limit.
     *
     * @var positive-int
     */
    public int $maxConnections;
    /**
     * Idle timeout.
     *
     * @var positive-int
     */
    public int $idleTimeout;

    /**
     * @param ?Serializer $serializer to use for object and mixed type values.
     * @param int<0, max> $cacheTtl Cache TTL in seconds
     * @param int<1, max> $maxConnections Maximum connection limit
     * @param int<1, max> $idleTimeout Idle timeout
     */
    public function __construct(
        /** @var T */
        public readonly SqlConfig $config,
        Serializer $serializer,
        int $cacheTtl = self::DEFAULT_CACHE_TTL,
        int $maxConnections = self::DEFAULT_SQL_MAX_CONNECTIONS,
        int $idleTimeout = self::DEFAULT_SQL_IDLE_TIMEOUT,
    ) {
        parent::__construct($serializer, $cacheTtl);
        $this->maxConnections = $maxConnections;
        $this->idleTimeout = $idleTimeout;
    }
    /** @internal */
    public function getDbIdentifier(): string
    {
        $host = $this->config->getHost();
        $port = $this->config->getPort();
        return "$host:$port:".(string) $this->config->getDatabase();
    }
}
