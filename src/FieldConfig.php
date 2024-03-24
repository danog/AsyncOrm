<?php declare(strict_types=1);

namespace danog\AsyncOrm;

use danog\AsyncOrm\Internal\Driver\CachedArray;

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
        /**
         * TTL of the cache, if zero disables caching.
         *
         * @var int<0, max>
         */
        public readonly int $cacheTtl,
        /**
         * Optimize table if more than this many megabytes are wasted, if null disables optimization.
         *
         * @var int<1, max>|null
         */
        public readonly ?int $optimizeIfWastedGtMb,
    ) {
    }

    /** @internal */
    public function get(?DbArray $previous = null): DbArray
    {
        if ($this->cacheTtl === 0) {
            return $this->settings->getDriverClass()::getInstance($this, $previous);
        }

        return CachedArray::getInstance($this, $previous);
    }
}
