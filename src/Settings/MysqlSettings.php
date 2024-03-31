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

use Amp\Mysql\MysqlConfig;
use danog\AsyncOrm\Internal\Driver\MysqlArray;
use danog\AsyncOrm\Serializer;

/**
 * MySQL backend settings.
 *
 * MariaDb 10.2+ or Mysql 5.6+ required.
 *
 * @extends SqlSettings<MysqlConfig>
 */
final readonly class MysqlSettings extends SqlSettings
{
    /**
     * @api
     * @param ?Serializer $serializer to use for object and mixed type values, if null defaults to either Igbinary or Native.
     * @param int<0, max> $cacheTtl Cache TTL in seconds, if 0 disables caching.
     * @param int<1, max> $maxConnections Maximum connection limit
     * @param int<1, max> $idleTimeout Idle timeout
     */
    public function __construct(
        MysqlConfig $config,
        ?Serializer $serializer = null,
        int $cacheTtl = self::DEFAULT_CACHE_TTL,
        int $maxConnections = self::DEFAULT_SQL_MAX_CONNECTIONS,
        int $idleTimeout = self::DEFAULT_SQL_IDLE_TIMEOUT,
        /**
         *
         * Whether to optimize MySQL tables automatically if more than the specified amount of megabytes is wasted by the MySQL engine.
         *
         * Be careful when tweaking this setting as it may lead to slowdowns on startup.
         *
         * If null disables optimization.
         *
         * @var int<1, max>|null $optimizeIfWastedMb
         */
        public ?int $optimizeIfWastedMb = null,
    ) {
        parent::__construct($config, $serializer, $cacheTtl, $maxConnections, $idleTimeout);
    }

    public function getDriverClass(): string
    {
        return MysqlArray::class;
    }
}
