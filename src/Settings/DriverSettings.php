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

use danog\AsyncOrm\Serializer;
use danog\AsyncOrm\Serializer\Igbinary;
use danog\AsyncOrm\Serializer\Native;
use danog\AsyncOrm\Settings;

/**
 * Base class for database backends.
 */
abstract readonly class DriverSettings implements Settings
{
    final public const DEFAULT_CACHE_TTL = 5*60;
    public readonly Serializer $serializer;
    /**
     * @param ?Serializer $serializer to use for object and mixed type values, if null defaults to either Igbinary or Native.
     */
    public function __construct(
        ?Serializer $serializer = null,
        /**
         * @var int<0, max> $cacheTtl For how long to keep records in memory after last read.
         */
        public int $cacheTtl = self::DEFAULT_CACHE_TTL,
    ) {
        $this->serializer = $serializer ?? (\extension_loaded('igbinary') ? new Igbinary : new Native);
    }

    /**
     * Get the DB's unique ID.
     *
     * @internal
     */
    abstract public function getDbIdentifier(): string;
}
