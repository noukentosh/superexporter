<?php

declare(strict_types=1);

namespace SuperExport\Universal;

/**
 * String identifier for an exportable entity (standard or dynamic).
 *
 * Examples: posts, cpt:portfolio, iblock:12, taxonomy:genre, iblock_section:5
 */
final class EntityKey
{
    private function __construct(
        public readonly string $value,
    ) {
    }

    public static function fromStandard(EntityType $type): self
    {
        return new self($type->value);
    }

    public static function cpt(string $postType): self
    {
        return new self('cpt:' . self::sanitizeSegment($postType));
    }

    public static function taxonomy(string $taxonomy): self
    {
        return new self('taxonomy:' . self::sanitizeSegment($taxonomy));
    }

    public static function iblock(int $iblockId): self
    {
        return new self('iblock:' . $iblockId);
    }

    public static function iblockSection(int $iblockId): self
    {
        return new self('iblock_section:' . $iblockId);
    }

    public static function parse(string $value): self
    {
        $value = trim($value);
        if ($value === '') {
            throw new \InvalidArgumentException('Entity key cannot be empty.');
        }

        if (EntityType::tryFrom($value) !== null) {
            return new self($value);
        }

        if (preg_match('/^(cpt|taxonomy):[a-zA-Z0-9_-]+$/', $value) === 1) {
            return new self($value);
        }

        if (preg_match('/^iblock:\d+$/', $value) === 1 || preg_match('/^iblock_section:\d+$/', $value) === 1) {
            return new self($value);
        }

        throw new \InvalidArgumentException('Invalid entity key: ' . $value);
    }

    public static function tryParse(string $value): ?self
    {
        try {
            return self::parse($value);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    public function toStandardType(): ?EntityType
    {
        return EntityType::tryFrom($this->value);
    }

    public function isStandard(): bool
    {
        return $this->toStandardType() !== null;
    }

    public function isContent(): bool
    {
        $standard = $this->toStandardType();

        return $standard !== null
            ? $standard->isContent()
            : str_starts_with($this->value, 'cpt:') || str_starts_with($this->value, 'iblock:');
    }

    public function isTaxonomy(): bool
    {
        $standard = $this->toStandardType();

        return $standard !== null
            ? $standard->isTaxonomy()
            : str_starts_with($this->value, 'taxonomy:') || str_starts_with($this->value, 'iblock_section:');
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    /** Filesystem-safe segment (colons are invalid on Windows paths). */
    public function storageKey(): string
    {
        return str_replace(':', '__', $this->value);
    }

    public static function fromStorageKey(string $storageKey): self
    {
        $value = str_replace('__', ':', $storageKey);

        return self::parse($value);
    }

    private static function sanitizeSegment(string $segment): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '', $segment) ?: 'unknown';
    }
}
