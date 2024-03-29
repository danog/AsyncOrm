<?php declare(strict_types=1);

namespace danog\AsyncOrm\Test;

use danog\AsyncOrm\Annotations\OrmMappedArray;
use danog\AsyncOrm\DbArray;
use danog\AsyncOrm\DbAutoProperties;
use danog\AsyncOrm\DbObject;
use danog\AsyncOrm\FieldConfig;
use danog\AsyncOrm\KeyType;
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

    public function __sleep()
    {
        return ['savedProp', 'arr'];
    }

    protected function onLoaded(FieldConfig $config): void
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
