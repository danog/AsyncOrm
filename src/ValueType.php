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
     * Objects, serialized as specified in the settings.
     */
    case OBJECT = 'object';
    /**
     * Values of any type, serialized as specified in the settings.
     *
     * Using MIXED worsens performances, please use STRING, INT or OBJECT whenever possible.
     */
    case MIXED = 'object';
}
