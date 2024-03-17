<?php declare(strict_types=1);

namespace danog\AsyncOrm;

use danog\AsyncOrm\Annotations\OrmMappedArray;

/**
 * Contains configuration for a single ORM field.
 */
final class FieldConfig
{
    public function __construct(
        public readonly string $table,
        public readonly OrmMappedArray $annotation,
        public readonly Settings $settings,
    ) {
    }
}
