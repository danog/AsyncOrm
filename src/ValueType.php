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
 * @link https://daniil.it/AsyncOrm AsyncOrm documentation
 */

namespace danog\AsyncOrm;

/**
 * Specifies the serializer to use when saving values.
 */
enum ValueType: string
{
    /**
     * Direct storage of UTF-8 string values.
     */
    case STRING = 'string';
    /**
     * Direct storage of integer values.
     */
    case INT = 'int';
    /**
     * Direct storage of boolean values.
     */
    case BOOL = 'bool';
    /**
     * Direct storage of floating point (double precision) values.
     */
    case FLOAT = 'float';
    /**
     * Objects extending DbObject, serialized as specified in the settings.
     */
    case OBJECT = 'object';
    /**
     * Values of any scalar type, serialized as specified in the settings.
     *
     * Using SCALAR worsens performances, please use any of the other types possible.
     */
    case SCALAR = 'scalar';
}
