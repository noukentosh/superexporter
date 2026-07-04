<?php

declare(strict_types=1);

namespace SuperExport\Storage;

use SuperExport\Exceptions\IncompatibleFormatException;
use SuperExport\Exceptions\SuperExportException;
use SuperExport\Universal\EntityDefinition;
use SuperExport\Universal\EntityKey;
use SuperExport\Universal\SchemaRegistry;
use SuperExport\Universal\Serializer;

/**
 * Builds, writes and reads manifest.json — the export descriptor
 * (format version, source CMS info, schema, stats, field map, chunk index).
 */
final class ManifestManager
{
    public const FORMAT_VERSION = '1.1.0';
    private const MANIFEST_FILE = 'manifest.json';

    public function __construct(
        private readonly Serializer $serializer,
        private readonly SchemaRegistry $schemaRegistry,
    ) {
    }

    /**
     * @param array{cms: string, cms_version: ?string, site_url: ?string, db_prefix: ?string} $source
     * @param list<EntityKey>              $entities
     * @param array<string, int>           $stats
     * @param array<string, string>        $sourceFieldMap
     * @param array<string, list<string>>  $chunks
     * @param array<string, EntityDefinition> $entityDefinitions
     * @return array<string, mixed>
     */
    public function build(
        array $source,
        array $entities,
        array $stats,
        array $sourceFieldMap,
        array $chunks,
        array $entityDefinitions = [],
    ): array {
        $definitionsPayload = [];
        foreach ($entityDefinitions as $key => $definition) {
            $definitionsPayload[$key instanceof EntityKey ? $key->value : (string) $key] = $definition->toArray();
        }

        return [
            'format_version' => self::FORMAT_VERSION,
            'exported_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'source' => $source,
            'schema' => [
                'entities' => array_map(static fn (EntityKey $k): string => $k->value, $entities),
                'fields' => $this->schemaRegistry->describe($entities),
                'entity_definitions' => $definitionsPayload,
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
     * Entity keys listed in the manifest.
     *
     * @param array<string, mixed> $manifest
     * @return list<EntityKey>
     */
    public function getEntities(array $manifest): array
    {
        $names = $manifest['schema']['entities'] ?? [];
        if (!is_array($names)) {
            throw new SuperExportException('Manifest schema.entities is malformed.');
        }

        $keys = [];
        foreach ($names as $name) {
            $key = EntityKey::tryParse((string) $name);
            if ($key === null) {
                throw new SuperExportException('Manifest references unknown entity type: ' . $name);
            }
            $keys[] = $key;
        }

        return $keys;
    }

    /**
     * Entity definitions from manifest (falls back to standard definitions for 1.0.x).
     *
     * @param array<string, mixed> $manifest
     * @return array<string, EntityDefinition>
     */
    public function getEntityDefinitions(array $manifest): array
    {
        $raw = $manifest['schema']['entity_definitions'] ?? [];
        if (is_array($raw) && $raw !== []) {
            $definitions = [];
            foreach ($raw as $keyValue => $data) {
                $key = EntityKey::tryParse((string) $keyValue);
                if ($key === null || !is_array($data)) {
                    continue;
                }
                $definitions[$key->value] = EntityDefinition::fromArray($key, $data);
            }

            return $definitions;
        }

        $definitions = [];
        foreach ($this->getEntities($manifest) as $key) {
            $standard = $key->toStandardType();
            if ($standard !== null) {
                $definitions[$key->value] = EntityDefinition::forStandard($standard);
            }
        }

        return $definitions;
    }

    /**
     * Chunk file names for one entity key.
     *
     * @param array<string, mixed> $manifest
     * @return list<string>
     */
    public function getChunks(array $manifest, EntityKey $key): array
    {
        $chunks = $manifest['chunks'][$key->value] ?? [];

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
