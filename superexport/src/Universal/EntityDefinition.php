<?php

declare(strict_types=1);

namespace SuperExport\Universal;

/**
 * Metadata for a discovered entity type (stored in manifest entity_definitions).
 */
final class EntityDefinition
{
    public const KIND_CONTENT = 'content';
    public const KIND_TAXONOMY = 'taxonomy';

    public const CANONICAL_POST = 'post';
    public const CANONICAL_PAGE = 'page';
    public const CANONICAL_PRODUCT = 'product';
    public const CANONICAL_CATEGORY = 'category';
    public const CANONICAL_TAG = 'tag';

    /**
     * @param array<string, mixed> $source CMS-native metadata (native_type, iblock_id, …)
     */
    public function __construct(
        public readonly EntityKey $key,
        public readonly string $kind,
        public readonly string $label,
        public readonly string $canonicalKind,
        public readonly array $source = [],
    ) {
    }

    public function isContent(): bool
    {
        return $this->kind === self::KIND_CONTENT;
    }

    public function isTaxonomy(): bool
    {
        return $this->kind === self::KIND_TAXONOMY;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'label' => $this->label,
            'canonical_kind' => $this->canonicalKind,
            'source' => $this->source,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(EntityKey $key, array $data): self
    {
        return new self(
            key: $key,
            kind: (string) ($data['kind'] ?? self::KIND_CONTENT),
            label: (string) ($data['label'] ?? $key->value),
            canonicalKind: (string) ($data['canonical_kind'] ?? self::CANONICAL_POST),
            source: is_array($data['source'] ?? null) ? $data['source'] : [],
        );
    }

    public static function forStandard(EntityType $type, string $label = ''): self
    {
        $key = EntityKey::fromStandard($type);
        $canonical = match ($type) {
            EntityType::Page => self::CANONICAL_PAGE,
            EntityType::Product => self::CANONICAL_PRODUCT,
            EntityType::Category => self::CANONICAL_CATEGORY,
            EntityType::Tag => self::CANONICAL_TAG,
            default => self::CANONICAL_POST,
        };
        $kind = $type->isTaxonomy() ? self::KIND_TAXONOMY : self::KIND_CONTENT;

        return new self(
            key: $key,
            kind: $kind,
            label: $label !== '' ? $label : $type->value,
            canonicalKind: $canonical,
        );
    }
}
