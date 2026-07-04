<?php

declare(strict_types=1);

namespace SuperExport\Storage;

use SuperExport\Exceptions\IncompatibleFormatException;
use SuperExport\Exceptions\SuperExportException;
use SuperExport\Universal\EntityType;
use SuperExport\Universal\SchemaRegistry;
use SuperExport\Universal\Serializer;

/**
 * Builds, writes and reads manifest.json — the export descriptor
 * (format version, source CMS info, schema, stats, field map, chunk index).
 */
final class ManifestManager
{
    public const FORMAT_VERSION = '1.0.0';
    private const MANIFEST_FILE = 'manifest.json';

    public function __construct(
        private readonly Serializer $serializer,
        private readonly SchemaRegistry $schemaRegistry,
    ) {
    }

    /**
     * @param array{cms: string, cms_version: ?string, site_url: ?string, db_prefix: ?string} $source
     * @param list<EntityType>              $entities
     * @param array<string, int>            $stats
     * @param array<string, string>         $sourceFieldMap
     * @param array<string, list<string>>   $chunks
     * @return array<string, mixed>
     */
    public function build(
        array $source,
        array $entities,
        array $stats,
        array $sourceFieldMap,
        array $chunks,
    ): array {
        return [
            'format_version' => self::FORMAT_VERSION,
            'exported_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'source' => $source,
            'schema' => [
                'entities' => array_map(static fn (EntityType $t): string => $t->value, $entities),
                'fields' => $this->schemaRegistry->describe($entities),
            ],
            'stats' => $stats,
            'source_field_map' => $sourceFieldMap,
            'chunks' => $chunks,
        ];
    }

    /**
     * @param array<string, mixed> $manifest
     */
    public function save(string $storagePath, array $manifest): string
    {
        $path = $storagePath . DIRECTORY_SEPARATOR . self::MANIFEST_FILE;
        if (@file_put_contents($path, $this->serializer->encode($manifest)) === false) {
            throw new SuperExportException('Cannot write manifest: ' . $path);
        }

        return $path;
    }

    /**
     * Load and validate a manifest from a storage directory.
     *
     * @return array<string, mixed>
     */
    public function load(string $storagePath): array
    {
        $path = $storagePath . DIRECTORY_SEPARATOR . self::MANIFEST_FILE;
        if (!is_file($path)) {
            throw new SuperExportException('Manifest not found: ' . $path);
        }

        $json = @file_get_contents($path);
        if ($json === false) {
            throw new SuperExportException('Cannot read manifest: ' . $path);
        }

        $manifest = $this->serializer->decode($json);
        $this->assertCompatible($manifest);

        return $manifest;
    }

    /**
     * Entity types listed in the manifest.
     *
     * @param array<string, mixed> $manifest
     * @return list<EntityType>
     */
    public function getEntities(array $manifest): array
    {
        $names = $manifest['schema']['entities'] ?? [];
        if (!is_array($names)) {
            throw new SuperExportException('Manifest schema.entities is malformed.');
        }

        $types = [];
        foreach ($names as $name) {
            $type = EntityType::tryFrom((string) $name);
            if ($type === null) {
                throw new SuperExportException('Manifest references unknown entity type: ' . $name);
            }
            $types[] = $type;
        }

        return $types;
    }

    /**
     * Chunk file names for one entity type.
     *
     * @param array<string, mixed> $manifest
     * @return list<string>
     */
    public function getChunks(array $manifest, EntityType $type): array
    {
        $chunks = $manifest['chunks'][$type->value] ?? [];

        return is_array($chunks) ? array_values($chunks) : [];
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function assertCompatible(array $manifest): void
    {
        $version = (string) ($manifest['format_version'] ?? '');
        $supportedMajor = explode('.', self::FORMAT_VERSION)[0];

        if ($version === '' || explode('.', $version)[0] !== $supportedMajor) {
            throw new IncompatibleFormatException($version === '' ? '(none)' : $version, $supportedMajor);
        }
    }
}
