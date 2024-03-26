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

namespace danog\AsyncOrm\Serializer;

use Amp\Postgres\PostgresByteA;
use danog\AsyncOrm\Serializer;

/**
 * @internal BYTEA serializer
 *
 * @template TValue
 * @implements Serializer<TValue>
 */
final class ByteaSerializer implements Serializer
{
    /**
     * @param Serializer<TValue> $inner
     */
    public function __construct(
        private readonly Serializer $inner
    ) {
    }
    public function serialize(mixed $value): mixed
    {
        /** @psalm-suppress MixedArgument */
        return new PostgresByteA($this->inner->serialize($value));
    }
    public function deserialize(mixed $value): mixed
    {
        return $this->inner->deserialize($value);
    }
}
