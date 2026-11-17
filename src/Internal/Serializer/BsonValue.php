<?php declare(strict_types=1);

/**
 * Copyright 2026 Daniil Gentili.
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
 * @copyright 2016-2026 Daniil Gentili <daniil@daniil.it>
 * @license   https://opensource.org/license/apache-2-0 Apache 2.0
 * @link https://github.com/danog/AsyncOrm AsyncOrm documentation
 */

namespace danog\AsyncOrm\Internal\Serializer;

use danog\AsyncOrm\Serializer;
use danog\AsyncOrm\ValueType;
use MongoDB\BSON\Binary;

/**
 * The BSON value of an element of a MongoDB array.
 *
 * Integers, floats and booleans are stored as such; strings and serialized values as binary data, since BSON
 * strings must be UTF-8.
 *
 * A collection has no column types to migrate when the value type of an array changes: values are converted to
 * the current type when read instead.
 *
 * @internal
 *
 * @template TValue
 * @implements Serializer<TValue>
 */
final class BsonValue implements Serializer
{
    /**
     * @param Serializer<TValue> $inner The serializer of mixed and object values.
     */
    public function __construct(
        private readonly ValueType $type,
        private readonly Serializer $inner,
    ) {
    }

    #[\Override]
    public function serialize(mixed $value): mixed
    {
        return match ($this->type) {
            ValueType::INT, ValueType::FLOAT, ValueType::BOOL => $value,
            ValueType::STRING => new Binary((string) $value),
            ValueType::SCALAR, ValueType::OBJECT => new Binary((string) $this->inner->serialize($value)),
        };
    }

    #[\Override]
    public function deserialize(mixed $value): mixed
    {
        if ($value instanceof Binary) {
            $value = $value->getData();
        }
        /** @var TValue */
        return match ($this->type) {
            ValueType::INT => (int) $value,
            ValueType::FLOAT => (float) $value,
            ValueType::BOOL => (bool) $value,
            ValueType::STRING => (string) $value,
            ValueType::SCALAR, ValueType::OBJECT => $this->inner->deserialize($value),
        };
    }
}
