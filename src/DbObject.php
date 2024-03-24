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

namespace danog\AsyncOrm;

use danog\AsyncOrm\Annotations\OrmMappedArray;
use danog\AsyncOrm\Settings\DriverSettings;
use danog\AsyncOrm\Settings\Mysql;
use ReflectionClass;

use function Amp\async;
use function Amp\Future\await;

abstract class DbObject
{
    /**
     * Initialize database instance.
     *
     * @internal
     */
    final public function __initDb(string $table, Settings $settings): void
    {
        $promises = [];
        foreach ((new ReflectionClass(static::class))->getProperties() as $property) {
            $attr = $property->getAttributes(OrmMappedArray::class);
            if (!$attr) {
                continue;
            }
            $attr = $attr[0]->newInstance();

            $ttl = $attr->cacheTtl;
            $optimize = $attr->optimizeIfWastedGtMb;
            if ($settings instanceof DriverSettings) {
                $ttl ??= $settings->cacheTtl;

                if ($settings instanceof Mysql) {
                    $optimize ??= $settings->optimizeIfWastedGtMb;
                }
            }

            $config = new FieldConfig(
                $table.'_'.$property->getName(),
                $settings,
                $attr->keyType,
                $attr->valueType,
                $ttl,
                $optimize,
            );

            $promises[$property] = async(
                $config->get(...),
                $this->{$property} ?? null
            );
        }
        $promises = await($promises);
        foreach ($promises as $key => $data) {
            $this->{$key} = $data;
        }
    }
}
