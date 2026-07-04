<?php

declare(strict_types=1);

namespace SuperExport\Storage;

use SuperExport\Core\IdRemapper;
use SuperExport\Exceptions\SuperExportException;
use SuperExport\Universal\EntityType;
use SuperExport\Universal\Serializer;

/**
 * Persists import checkpoint state for resume after interruption.
 */
final class ImportStateManager
{
    private const STATE_FILE = 'import_state.json';

    public function __construct(private readonly Serializer $serializer)
    {
    }

    public function exists(string $storagePath): bool
    {
        return is_file($storagePath . DIRECTORY_SEPARATOR . self::STATE_FILE);
    }

    /**
     * @return array{
     *   completed_types: list<string>,
     *   current_type: ?string,
     *   completed_chunks: array<string, list<string>>,
     *   id_map: array<string, array<string, string|int>>,
     *   duplicate_strategy: string,
     *   started_at: string
     * }
     */
    public function load(string $storagePath): array
    {
        $path = $storagePath . DIRECTORY_SEPARATOR . self::STATE_FILE;
        if (!is_file($path)) {
            throw new SuperExportException('Import state not found: ' . $path);
        }

        $json = (string) file_get_contents($path);
        $data = $this->serializer->decode($json);
        if (!is_array($data)) {
            throw new SuperExportException('Import state is malformed.');
        }

        return [
            'completed_types' => $data['completed_types'] ?? [],
            'current_type' => $data['current_type'] ?? null,
            'completed_chunks' => $data['completed_chunks'] ?? [],
            'id_map' => $data['id_map'] ?? [],
            'duplicate_strategy' => (string) ($data['duplicate_strategy'] ?? 'skip'),
            'started_at' => (string) ($data['started_at'] ?? ''),
        ];
    }

    /**
     * @param array{
     *   completed_types: list<string>,
     *   current_type: ?string,
     *   completed_chunks: array<string, list<string>>,
     *   id_map: array<string, array<string, string|int>>,
     *   duplicate_strategy: string,
     *   started_at: string
     * } $state
     */
    public function save(string $storagePath, array $state): void
    {
        $path = $storagePath . DIRECTORY_SEPARATOR . self::STATE_FILE;
        if (@file_put_contents($path, $this->serializer->encode($state)) === false) {
            throw new SuperExportException('Cannot write import state: ' . $path);
        }
    }

    public function clear(string $storagePath): void
    {
        $path = $storagePath . DIRECTORY_SEPARATOR . self::STATE_FILE;
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * @param list<string> $completedTypes
     * @param array<string, list<string>> $completedChunks
     * @param array<string, array<string, string|int>> $idMap
     */
    public function createInitial(
        string $duplicateStrategy,
        array $completedTypes = [],
        array $completedChunks = [],
        array $idMap = [],
    ): array {
        return [
            'completed_types' => $completedTypes,
            'current_type' => null,
            'completed_chunks' => $completedChunks,
            'id_map' => $idMap,
            'duplicate_strategy' => $duplicateStrategy,
            'started_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }

    public function isTypeCompleted(array $state, EntityType $type): bool
    {
        return in_array($type->value, $state['completed_types'], true);
    }

    public function isChunkCompleted(array $state, EntityType $type, string $chunkFile): bool
    {
        $done = $state['completed_chunks'][$type->value] ?? [];

        return in_array($chunkFile, $done, true);
    }

    public function markChunkCompleted(array &$state, EntityType $type, string $chunkFile): void
    {
        $state['current_type'] = $type->value;
        $state['completed_chunks'][$type->value] ??= [];
        if (!in_array($chunkFile, $state['completed_chunks'][$type->value], true)) {
            $state['completed_chunks'][$type->value][] = $chunkFile;
        }
    }

    public function markTypeCompleted(array &$state, EntityType $type): void
    {
        if (!in_array($type->value, $state['completed_types'], true)) {
            $state['completed_types'][] = $type->value;
        }
        $state['current_type'] = null;
    }

    public function remapperFromState(array $state): IdRemapper
    {
        return IdRemapper::fromArray($state['id_map']);
    }

    public function syncIdMap(array &$state, IdRemapper $remapper): void
    {
        $state['id_map'] = $remapper->toArray();
    }
}
