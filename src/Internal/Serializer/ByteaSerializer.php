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
 * @author    Alexander Pankratov <alexander@i-c-a.su>
 * @copyright 2016-2024 Daniil Gentili <daniil@daniil.it>
 * @copyright 2016-2024 Alexander Pankratov <alexander@i-c-a.su>
 * @license   https://opensource.org/license/apache-2-0 Apache 2.0
 * @link https://daniil.it/AsyncOrm AsyncOrm documentation
 */

namespace danog\AsyncOrm\Internal\Serializer;

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
