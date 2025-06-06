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
 * @copyright 2016-2024 Daniil Gentili <daniil@daniil.it>
 * @license   https://opensource.org/license/apache-2-0 Apache 2.0
 * @link https://github.com/danog/AsyncOrm AsyncOrm documentation
 */

namespace danog\TestAsyncOrm;

use danog\AsyncOrm\Annotations\OrmMappedArray;
use danog\AsyncOrm\DbArray;
use danog\AsyncOrm\DbArrayBuilder;
use danog\AsyncOrm\DbAutoProperties;
use danog\AsyncOrm\DbObject;
use danog\AsyncOrm\KeyType;
use danog\AsyncOrm\Serializer\Json;
use danog\AsyncOrm\ValueType;

final class TestObject extends DbObject
{
    use DbAutoProperties;

    public int $loadedCnt = 0;
    public int $saveAfterCnt = 0;
    public int $saveBeforeCnt = 0;

    public mixed $savedProp = null;

    #[OrmMappedArray(
        KeyType::INT,
        ValueType::INT
    )]
    public DbArray $arr;
    #[OrmMappedArray(
        KeyType::INT,
        ValueType::INT
    )]
    public DbArray $arr2;

    #[OrmMappedArray(
        KeyType::INT,
        ValueType::OBJECT,
        cacheTtl: 0,
    )]
    public DbArray $arr3;

    #[OrmMappedArray(
        KeyType::INT,
        ValueType::INT,
        cacheTtl: 0,
        optimizeIfWastedMb: 0
    )]
    public DbArray $arr4;

    #[OrmMappedArray(
        keyType: KeyType::INT,
        valueType: ValueType::SCALAR,
        cacheTtl: 0,
        serializer: new Json(),
    )]
    public DbArray $arr5;

    public function __sleep()
    {
        return ['savedProp', 'arr', 'arr2', 'arr3', 'arr4'];
    }

    protected function onLoaded(DbArrayBuilder $config): void
    {
        $this->initDbProperties($config->settings, $config->table);
        $this->loadedCnt++;
    }
    protected function onAfterSave(): void
    {
        $this->saveAfterCnt++;
    }
    protected function onBeforeSave(): void
    {
        $this->saveDbProperties();
        $this->saveBeforeCnt++;
    }
}
