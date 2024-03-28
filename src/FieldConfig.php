<?php declare(strict_types=1);

namespace danog\AsyncOrm;

use AssertionError;
use danog\AsyncOrm\Internal\Driver\CachedArray;
use danog\AsyncOrm\Internal\Driver\ObjectArray;
use danog\AsyncOrm\Settings\DriverSettings;
use danog\AsyncOrm\Settings\Memory;

/**
 * Contains configuration for a single ORM field.
 */
final readonly class FieldConfig
{
    public function __construct(
        /**
         * Table name.
         */
        public readonly string $table,
        /**
         * Settings.
         */
        public readonly Settings $settings,
        /**
         * Key type.
         */
        public readonly KeyType $keyType,
        /**
         * Value type.
         */
        public readonly ValueType $valueType,
    ) {
    }

    /**
     * Build database array.
     */
    public function build(?DbArray $previous = null): DbArray
    {
        if ($this->valueType === ValueType::OBJECT) {
            if (!$this->settings instanceof DriverSettings) {
                throw new AssertionError("Objects can only be saved to a database backend!");
            }
            return ObjectArray::getInstance($this, $previous);
        }
        if ($this->settings instanceof Memory
            || (
                $this->settings instanceof DriverSettings
                && $this->settings->cacheTtl === 0
            )
        ) {
            return $this->settings->getDriverClass()::getInstance($this, $previous);
        }

        return CachedArray::getInstance($this, $previous);
    }
}
