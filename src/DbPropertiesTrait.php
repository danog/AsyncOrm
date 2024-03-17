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
use danog\AsyncOrm\Internal\DbPropertiesFactory;
use ReflectionClass;

use function Amp\async;
use function Amp\Future\await;

/**
 * Include this trait and call DbPropertiesTrait::initDb to use AsyncOrm's database backend for properties marked by the OrmMappedArray property.
 */
trait DbPropertiesTrait
{
    /**
     * Initialize database instance.
     *
     * @internal
     */
    public function initDb(Settings $dbSettings): void
    {
        $prefix = $this->getDbPrefix();

        $className = \explode('\\', static::class);
        $className = \end($className);

        $promises = [];
        foreach ((new ReflectionClass(static::class))->getProperties() as $property) {
            $attr = $property->getAttributes(OrmMappedArray::class);
            if (!$attr) {
                continue;
            }
            $attr = $attr[0]->newInstance();
            $table = $prefix.'_';
            $table .= $attr->table ?? "{$className}_{$property}";
            $promises[$property] = async(DbPropertiesFactory::get(...), $dbSettings, $table, $attr, $this->{$property} ?? null);
        }
        $promises = await($promises);
        foreach ($promises as $key => $data) {
            $this->{$key} = $data;
        }
    }

    /**
     * Returns the prefix to use for table names.
     */
    protected function getTablePrefix(): string
    {
        return '';
    }
}
