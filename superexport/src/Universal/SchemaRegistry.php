<?php

declare(strict_types=1);

namespace SuperExport\Universal;

/**
 * Canonical field definitions for every entity type.
 *
 * Single source of truth for the "schema.fields" section of manifest.json
 * and for record validation in pipelines.
 */
final class SchemaRegistry
{
    private const CONTENT_FIELDS = [
        'source_id'     => ['type' => 'scalar', 'required' => true],
        'slug'          => ['type' => 'string', 'required' => true],
        'title'         => ['type' => 'string', 'required' => true],
        'body'          => ['type' => 'html',   'required' => false],
        'excerpt'       => ['type' => 'string', 'required' => false],
        'status'        => ['type' => 'string', 'required' => true],
        'author_name'   => ['type' => 'string', 'required' => false],
        'published_at'  => ['type' => 'datetime', 'required' => false],
        'updated_at'    => ['type' => 'datetime', 'required' => false],
        'parent_id'     => ['type' => 'scalar', 'required' => false],
        'sort_order'    => ['type' => 'int',    'required' => false],
        'taxonomy_refs' => ['type' => 'array',  'required' => false],
        'meta'          => ['type' => 'array',  'required' => false],
        'relations'     => ['type' => 'array',  'required' => false],
        'media_refs'    => ['type' => 'array',  'required' => false],
    ];

    private const TAXONOMY_FIELDS = [
        'source_id'   => ['type' => 'scalar', 'required' => true],
        'slug'        => ['type' => 'string', 'required' => true],
        'name'        => ['type' => 'string', 'required' => true],
        'description' => ['type' => 'string', 'required' => false],
        'parent_id'   => ['type' => 'scalar', 'required' => false],
        'type'        => ['type' => 'string', 'required' => true],
    ];

    private const META_FIELDS = [
        'entity_type'      => ['type' => 'string', 'required' => true],
        'entity_source_id' => ['type' => 'scalar', 'required' => true],
        'key'              => ['type' => 'string', 'required' => true],
        'value'            => ['type' => 'mixed',  'required' => false],
        'type'             => ['type' => 'string', 'required' => true],
    ];

    /**
     * @return array<string, array{type: string, required: bool}>
     */
    public function getFields(EntityType $type): array
    {
        return match (true) {
            $type->isContent()       => self::CONTENT_FIELDS,
            $type->isTaxonomy()      => self::TAXONOMY_FIELDS,
            $type === EntityType::Meta => self::META_FIELDS,
        };
    }

    /**
     * Schema description for all given entity types (manifest "schema.fields").
     *
     * @param list<EntityType> $types
     * @return array<string, array<string, array{type: string, required: bool}>>
     */
    public function describe(array $types): array
    {
        $out = [];
        foreach ($types as $type) {
            $out[$type->value] = $this->getFields($type);
        }

        return $out;
    }

    /**
     * Validate a canonical record; returns a list of problems (empty = valid).
     *
     * @param array<string, mixed> $record
     * @return list<string>
     */
    public function validate(EntityType $type, array $record): array
    {
        $errors = [];
        foreach ($this->getFields($type) as $field => $def) {
            if ($def['required'] && (!array_key_exists($field, $record) || $record[$field] === null || $record[$field] === '')) {
                $errors[] = sprintf('%s: missing required field "%s"', $type->value, $field);
            }
        }

        return $errors;
    }
}
