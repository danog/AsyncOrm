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
use danog\AsyncOrm\Internal\Driver\CachedArray;
use danog\AsyncOrm\Settings\DriverSettings;
use danog\AsyncOrm\Settings\Mysql;
use ReflectionClass;

use function Amp\async;
use function Amp\Future\await;

/** @api */
trait DbAutoProperties
{
    /** @var list<CachedArray> */
    private array $properties = [];

    /**
     * Initialize database properties.
     */
    public function initDbProperties(FieldConfig $config): void
    {
        $this->properties = [];
        $promises = [];
        foreach ((new ReflectionClass(static::class))->getProperties() as $property) {
            $attr = $property->getAttributes(OrmMappedArray::class);
            if (!$attr) {
                continue;
            }
            $attr = $attr[0]->newInstance();

            $settings = $config->settings;
            if ($settings instanceof DriverSettings) {
                $ttl = $attr->cacheTtl ?? $settings->cacheTtl;
                if ($ttl !== $settings->cacheTtl) {
                    $settings = new $settings(\array_merge(
                        (array) $settings,
                        ['cacheTtl' => $ttl]
                    ));
                }
                if ($settings instanceof Mysql) {
                    $optimize = $attr->optimizeIfWastedGtMb ?? $settings->optimizeIfWastedGtMb;

                    if ($optimize !== $settings->optimizeIfWastedGtMb) {
                        $settings = new $settings(\array_merge(
                            (array) $settings,
                            ['optimizeIfWastedGtMb' => $optimize]
                        ));
                    }
                }
            }

            $config = new FieldConfig(
                $config->table.'_'.($attr->tablePostfix ?? $property->getName()),
                $settings,
                $attr->keyType,
                $attr->valueType,
            );

            $promises[] = async(function () use ($config, $property) {
                $v = $config->build($property->getValue());
                $property->setValue($v);
                if ($v instanceof CachedArray) {
                    $this->properties []= $v->flushCache();
                }
            });
        }
        await($promises);
    }

    /**
     * Save all properties.
     */
    public function saveDbProperties(): void
    {
        $futures = [];
        foreach ($this->properties as $prop) {
            $futures []= async($prop->flushCache(...));
        }
        await($futures);
    }
}
