<?php

declare(strict_types=1);

namespace SuperExport\Adapters\WordPress;

use PDO;
use SuperExport\Universal\EntityDefinition;
use SuperExport\Universal\EntityKey;
use SuperExport\Universal\EntityType;

/**
 * Discovers WordPress post types and custom taxonomies from the database.
 */
final class WpEntityDiscovery
{
    /** @var list<string> */
    private const EXCLUDED_POST_TYPES = [
        'revision', 'nav_menu_item', 'attachment', 'customize_changeset',
        'wp_block', 'oembed_cache', 'user_request', 'wp_navigation',
        'acf-field', 'acf-field-group', 'shop_order', 'shop_order_refund',
        'shop_coupon', 'shop_webhook', 'scheduled-action',
    ];

    /** @var list<string> */
    private const EXCLUDED_TAXONOMIES = [
        'category', 'post_tag', 'nav_menu', 'link_category', 'post_format',
        'product_type', 'product_visibility', 'product_shipping_class',
        'pa_', // product attribute prefix handled separately
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $tablePrefix,
        private readonly bool $hasWooCommerce = false,
    ) {
    }

    /** @return list<EntityKey> */
    public function discoverEntityKeys(): array
    {
        $keys = [];
        foreach ($this->discoverPostTypes() as $postType => $info) {
            $keys[] = $info['key'];
        }
        foreach ($this->discoverTaxonomies() as $taxonomy => $info) {
            $keys[] = $info['key'];
        }

        return $keys;
    }

    /** @return array<string, EntityDefinition> */
    public function discoverDefinitions(): array
    {
        $definitions = [];
        foreach ($this->discoverPostTypes() as $postType => $info) {
            $definitions[$info['key']->value] = $info['definition'];
        }
        foreach ($this->discoverTaxonomies() as $taxonomy => $info) {
            $definitions[$info['key']->value] = $info['definition'];
        }

        return $definitions;
    }

    /**
     * @return array<string, array{key: EntityKey, definition: EntityDefinition, post_type: string}>
     */
    public function discoverPostTypes(): array
    {
        $sql = 'SELECT post_type, COUNT(*) AS cnt
                FROM ' . $this->table('posts') . "
                WHERE post_status NOT IN ('auto-draft','inherit','trash')
                GROUP BY post_type
                HAVING cnt > 0";

        $result = [];
        foreach ($this->pdo->query($sql)->fetchAll() as $row) {
            $postType = (string) $row['post_type'];
            if ($this->isExcludedPostType($postType)) {
                continue;
            }

            $key = $this->mapPostTypeToKey($postType);
            $canonical = $this->resolveCanonicalKind($postType);
            $label = $this->humanize($postType);

            $result[$postType] = [
                'key' => $key,
                'post_type' => $postType,
                'definition' => new EntityDefinition(
                    key: $key,
                    kind: EntityDefinition::KIND_CONTENT,
                    label: $label,
                    canonicalKind: $canonical,
                    source: ['cms' => 'wordpress', 'native_type' => $postType],
                ),
            ];
        }

        return $result;
    }

    /**
     * @return array<string, array{key: EntityKey, definition: EntityDefinition, taxonomy: string}>
     */
    public function discoverTaxonomies(): array
    {
        $sql = 'SELECT tt.taxonomy, COUNT(*) AS cnt,
                       MAX(CASE WHEN tt.parent > 0 THEN 1 ELSE 0 END) AS has_hierarchy
                FROM ' . $this->table('term_taxonomy') . ' tt
                GROUP BY tt.taxonomy
                HAVING cnt > 0';

        $result = [];
        foreach ($this->pdo->query($sql)->fetchAll() as $row) {
            $taxonomy = (string) $row['taxonomy'];
            if ($this->isExcludedTaxonomy($taxonomy)) {
                continue;
            }

            $key = $this->mapTaxonomyToKey($taxonomy);
            $hasHierarchy = (int) ($row['has_hierarchy'] ?? 0) > 0;
            $canonical = $hasHierarchy
                ? EntityDefinition::CANONICAL_CATEGORY
                : EntityDefinition::CANONICAL_TAG;

            $result[$taxonomy] = [
                'key' => $key,
                'taxonomy' => $taxonomy,
                'definition' => new EntityDefinition(
                    key: $key,
                    kind: EntityDefinition::KIND_TAXONOMY,
                    label: $this->humanize($taxonomy),
                    canonicalKind: $canonical,
                    source: ['cms' => 'wordpress', 'native_type' => $taxonomy],
                ),
            ];
        }

        return $result;
    }

    public function resolvePostType(EntityKey $key): ?string
    {
        foreach ($this->discoverPostTypes() as $postType => $info) {
            if ($info['key']->equals($key)) {
                return $postType;
            }
        }

        return match ($key->value) {
            'posts' => 'post',
            'pages' => 'page',
            'products' => 'product',
            default => str_starts_with($key->value, 'cpt:')
                ? substr($key->value, 4)
                : null,
        };
    }

    public function resolveTaxonomy(EntityKey $key): ?string
    {
        foreach ($this->discoverTaxonomies() as $taxonomy => $info) {
            if ($info['key']->equals($key)) {
                return $taxonomy;
            }
        }

        return match ($key->value) {
            'categories' => 'category',
            'tags' => 'post_tag',
            default => str_starts_with($key->value, 'taxonomy:')
                ? substr($key->value, 9)
                : null,
        };
    }

    private function mapPostTypeToKey(string $postType): EntityKey
    {
        return match ($postType) {
            'post' => EntityKey::fromStandard(EntityType::Post),
            'page' => EntityKey::fromStandard(EntityType::Page),
            'product' => EntityKey::fromStandard(EntityType::Product),
            default => EntityKey::cpt($postType),
        };
    }

    private function mapTaxonomyToKey(string $taxonomy): EntityKey
    {
        return match ($taxonomy) {
            'category' => EntityKey::fromStandard(EntityType::Category),
            'post_tag' => EntityKey::fromStandard(EntityType::Tag),
            default => EntityKey::taxonomy($taxonomy),
        };
    }

    private function resolveCanonicalKind(string $postType): string
    {
        return match ($postType) {
            'page' => EntityDefinition::CANONICAL_PAGE,
            'product' => EntityDefinition::CANONICAL_PRODUCT,
            default => $this->isHierarchicalPostType($postType)
                ? EntityDefinition::CANONICAL_PAGE
                : EntityDefinition::CANONICAL_POST,
        };
    }

    private function isHierarchicalPostType(string $postType): bool
    {
        if ($postType === 'page') {
            return true;
        }

        $sql = 'SELECT COUNT(*) FROM ' . $this->table('posts') . '
                WHERE post_type = :type AND post_parent > 0 LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['type' => $postType]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function isExcludedPostType(string $postType): bool
    {
        if (in_array($postType, self::EXCLUDED_POST_TYPES, true)) {
            return true;
        }

        if (str_starts_with($postType, 'wp_') || str_starts_with($postType, 'elementor_')) {
            return true;
        }

        return !$this->hasWooCommerce && $postType === 'product';
    }

    private function isExcludedTaxonomy(string $taxonomy): bool
    {
        if (in_array($taxonomy, self::EXCLUDED_TAXONOMIES, true)) {
            return true;
        }

        if (str_starts_with($taxonomy, 'pa_')) {
            return true;
        }

        return str_starts_with($taxonomy, 'wp_');
    }

    private function humanize(string $value): string
    {
        return ucwords(str_replace(['_', '-'], ' ', $value));
    }

    private function table(string $name): string
    {
        return $this->tablePrefix . $name;
    }
}
