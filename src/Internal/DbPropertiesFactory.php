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

namespace danog\AsyncOrm\Internal;

use danog\AsyncOrm\Annotations\OrmMappedArray;
use danog\AsyncOrm\DbArray;
use danog\AsyncOrm\FieldConfig;
use danog\AsyncOrm\Internal\Driver\CachedArray;
use danog\AsyncOrm\Settings;
use danog\AsyncOrm\Settings\DriverSettings;
use danog\AsyncOrm\Settings\Mysql;

/**
 * This factory class initializes the correct database backend for AsyncOrm.
 *
 * @internal
 */
final class DbPropertiesFactory
{
    public static function get(Settings $dbSettings, string $table, OrmMappedArray $config, ?DbArray $previous = null): DbArray
    {
        $dbSettings = clone $dbSettings;

        $ttl = $config->cacheTtl;
        $optimize = $config->optimizeIfWastedGtMb;
        if ($dbSettings instanceof DriverSettings) {
            $ttl ??= $dbSettings->cacheTtl;

            if ($dbSettings instanceof Mysql) {
                $optimize ??= $config->optimizeIfWastedGtMb;
            }

            $config = new OrmMappedArray(
                $config->keyType,
                $config->valueType,
                $ttl === 0 ? false : $config->enableCache,
                $ttl,
                $config->table,
                $optimize
            );
        }

        $config = new FieldConfig(
            $table,
            $config,
            $dbSettings,
        );

        if (!$config->annotation->enableCache) {
            return $config->settings->getDriverClass()::getInstance($config, $previous);
        }

        return CachedArray::getInstance($config, $previous);
    }
}
