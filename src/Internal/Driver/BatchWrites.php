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
 * @copyright 2016-2023 Daniil Gentili <daniil@daniil.it>
 * @license   https://opensource.org/license/apache-2-0 Apache 2.0
 * @link https://github.com/danog/AsyncOrm AsyncOrm documentation
 */

namespace danog\AsyncOrm\Settings;

namespace danog\AsyncOrm\Internal\Driver;

/**
 * A backend that writes many elements with few queries, used to flush the write cache:
 * one query per element costs a round trip each.
 *
 * @internal
 *
 * @template TKey as array-key
 * @template TValue
 */
interface BatchWrites
{
    /**
     * Set multiple elements.
     *
     * @param array<TKey, TValue> $values
     */
    public function setMany(array $values): void;

    /**
     * Unset multiple elements.
     *
     * @param list<TKey> $keys
     */
    public function unsetMany(array $keys): void;
}
