<?php

declare(strict_types=1);

namespace Cms\Fields\Types;

final class RelationType extends AbstractFieldType
{
    public function name(): string
    {
        return 'relation';
    }

    public function defaultConfig(): array
    {
        return [
            'cardinality' => 'manyToOne',
            'relatedSlug' => '',
            'labelField' => 'id',
            'foreignKey' => '',
        ];
    }

    public function validateConfig(array $config): void
    {
        $cardinality = $config['cardinality'] ?? 'manyToOne';
        if (!in_array($cardinality, ['manyToOne', 'oneToMany'], true)) {
            throw new \InvalidArgumentException('Relation cardinality must be manyToOne or oneToMany');
        }
        $slug = isset($config['relatedSlug']) && is_string($config['relatedSlug'])
            ? trim($config['relatedSlug'])
            : '';
        if ($slug === '' || !preg_match('/^[a-z][a-z0-9_]{0,63}$/', $slug)) {
            throw new \InvalidArgumentException('Relation fields require a valid relatedSlug');
        }
        if ($cardinality === 'oneToMany') {
            $fk = isset($config['foreignKey']) && is_string($config['foreignKey'])
                ? trim($config['foreignKey'])
                : '';
            if ($fk === '' || !preg_match('/^[a-z][a-z0-9_]{0,63}$/', $fk)) {
                throw new \InvalidArgumentException('oneToMany relations require foreignKey on the related resource');
            }
        }
    }
}
