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
    public function getFields(EntityKey $key): array
    {
        $standard = $key->toStandardType();
        if ($standard === EntityType::Meta) {
            return self::META_FIELDS;
        }

        if ($key->isContent()) {
            return self::CONTENT_FIELDS;
        }

        if ($key->isTaxonomy()) {
            return self::TAXONOMY_FIELDS;
        }

        return self::CONTENT_FIELDS;
    }

    /**
     * @return array<string, array{type: string, required: bool}>
     */
    public function getFieldsForType(EntityType $type): array
    {
        return $this->getFields(EntityKey::fromStandard($type));
    }

    /**
     * Schema description for all given entity keys (manifest "schema.fields").
     *
     * @param list<EntityKey> $keys
     * @return array<string, array<string, array{type: string, required: bool}>>
     */
    public function describe(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key->value] = $this->getFields($key);
        }

        return $out;
    }

    /**
     * Validate a canonical record; returns a list of problems (empty = valid).
     *
     * @param array<string, mixed> $record
     * @return list<string>
     */
    public function validate(EntityKey $key, array $record): array
    {
        $errors = [];
        foreach ($this->getFields($key) as $field => $def) {
            if ($def['required'] && (!array_key_exists($field, $record) || $record[$field] === null || $record[$field] === '')) {
                $errors[] = sprintf('%s: missing required field "%s"', $key->value, $field);
            }
        }

        return $errors;
    }
}
