<?php

namespace EduLazaro\Laranon\Concerns;

/**
 * Declares which attributes of a model are PII to feed the known-entities
 * recognizer:
 *
 *     protected array $anonymizable = ['email', 'name' => 'person'];
 *
 * Plain values default to the 'entity' type; key => value pairs assign one.
 */
trait Anonymizable
{
    /**
     * @return array<int, array{value: string, type: string}>
     */
    public function anonymizableEntities(): array
    {
        $entities = [];

        foreach ($this->anonymizable ?? [] as $key => $type) {
            $attribute = is_int($key) ? $type : $key;
            $entityType = is_int($key) ? 'entity' : $type;

            $value = data_get($this, $attribute);

            if (is_string($value) && trim($value) !== '') {
                $entities[] = ['value' => $value, 'type' => $entityType];
            }
        }

        return $entities;
    }
}
