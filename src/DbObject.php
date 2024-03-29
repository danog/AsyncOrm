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

use danog\AsyncOrm\Internal\Containers\ObjectContainer;

/** @api */
abstract class DbObject
{
    private ObjectContainer $mapper;
    private string|int|null $key;

    /**
     * Initialize database instance.
     *
     * @internal Do not invoke manually.
     */
    final public function initDb(ObjectContainer $mapper, string|int $key): void
    {
        if (isset($this->key)) {
            $this->mapper = $mapper;
            $this->key = $key;
            return;
        }
        $this->mapper = $mapper;
        $this->key = $key;
        $this->onLoaded();
    }

    /**
     * Save object to database.
     */
    public function save(): void
    {
        $this->onBeforeSave();
        $this->mapper->inner->set($this->key, $this);
        $this->onAfterSave();
    }

    // @codeCoverageIgnoreStart
    /**
     * Method invoked after loading the object.
     */
    protected function onLoaded(): void
    {

    }
    /**
     * Method invoked before saving the object.
     */
    protected function onBeforeSave(): void
    {

    }
    /**
     * Method invoked after saving the object.
     */
    protected function onAfterSave(): void
    {

    }
    // @codeCoverageIgnoreEnd
}
