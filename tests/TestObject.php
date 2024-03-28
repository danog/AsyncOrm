<?php declare(strict_types=1);

namespace danog\AsyncOrm\Test;

use danog\AsyncOrm\DbObject;

final class TestObject extends DbObject
{
    public int $loadedCnt = 0;
    public int $saveAfterCnt = 0;
    public int $saveBeforeCnt = 0;

    public mixed $savedProp = null;

    public function __sleep()
    {
        return ['savedProp'];
    }

    protected function onLoaded(): void
    {
        $this->loadedCnt++;
    }
    protected function onAfterSave(): void
    {
        $this->saveAfterCnt++;
    }
    protected function onBeforeSave(): void
    {
        $this->saveBeforeCnt++;
    }
}
