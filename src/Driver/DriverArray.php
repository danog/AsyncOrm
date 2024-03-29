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

namespace danog\AsyncOrm\Driver;

use danog\AsyncOrm\DbArray;
use danog\AsyncOrm\FieldConfig;
use danog\AsyncOrm\Serializer;
use danog\AsyncOrm\Settings\DriverSettings;

use function Amp\async;
use function Amp\Future\await;

/**
 * Base class for driver-based arrays.
 *
 * @template TKey as array-key
 * @template TValue
 *
 * @psalm-consistent-constructor
 *
 * @extends DbArray<TKey, TValue>
 *
 * @api
 */
abstract class DriverArray extends DbArray
{
    /**
     * @param Serializer<TValue> $serializer
     */
    protected function __construct(
        protected readonly FieldConfig $config,
        protected readonly Serializer $serializer
    ) {
    }

    public static function getInstance(FieldConfig $config, DbArray|null $previous): DbArray
    {
        if ($previous !== null
            && $previous::class === static::class
            && $previous->config == $config
        ) {
            return $previous;
        }
        \assert($config->settings instanceof DriverSettings);

        $instance = new static($config, $config->settings->serializer);

        if ($previous === null) {
            return $instance;
        }

        if ($previous instanceof SqlArray
            && $instance instanceof SqlArray
            && $previous::class === $instance::class
        ) {
            $instance->importFromTable($previous->config->table);
        } else {
            $promises = [];
            foreach ($previous->getIterator() as $key => $value) {
                $promises []= async($previous->unset(...), $key)
                    ->map(static fn () => $instance->set($key, $value));
                if (\count($promises) % 500 === 0) {
                    await($promises);
                    $promises = [];
                }
            }
            if ($promises) {
                await($promises);
            }
        }

        return $instance;
    }

    /**
     * Sleep function.
     */
    public function __sleep(): array
    {
        return ['config'];
    }
}
