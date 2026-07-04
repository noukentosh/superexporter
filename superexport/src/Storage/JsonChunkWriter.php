<?php

declare(strict_types=1);

namespace SuperExport\Storage;

use SuperExport\Exceptions\SuperExportException;
use SuperExport\Universal\EntityType;
use SuperExport\Universal\Serializer;

/**
 * Writes canonical entities into paginated chunk files:
 * storage/entities/posts/posts_0001.json, posts_0002.json, ...
 */
final class JsonChunkWriter
{
    /** @var array<string, list<array<string, mixed>>> */
    private array $buffers = [];

    /** @var array<string, int> */
    private array $chunkCounters = [];

    /** @var array<string, list<string>> */
    private array $writtenChunks = [];

    public function __construct(
        private readonly string $storagePath,
        private readonly Serializer $serializer,
        private readonly int $chunkSize = 500,
    ) {
        if ($chunkSize < 1) {
            throw new SuperExportException('Chunk size must be >= 1.');
        }
    }

    /**
     * @param array<string, mixed> $record
     */
    public function write(EntityType $type, array $record): void
    {
        $this->buffers[$type->value][] = $record;

        if (count($this->buffers[$type->value]) >= $this->chunkSize) {
            $this->flushType($type);
        }
    }

    /**
     * Flush all pending buffers and return the chunk file map for the manifest.
     *
     * @return array<string, list<string>> entity type => relative chunk file names
     */
    public function finalize(): array
    {
        foreach (array_keys($this->buffers) as $typeValue) {
            $type = EntityType::from($typeValue);
            if ($this->buffers[$typeValue] !== []) {
                $this->flushType($type);
            }
        }

        return $this->writtenChunks;
    }

    private function flushType(EntityType $type): void
    {
        $records = $this->buffers[$type->value] ?? [];
        if ($records === []) {
            return;
        }

        $this->chunkCounters[$type->value] = ($this->chunkCounters[$type->value] ?? 0) + 1;
        $fileName = sprintf('%s_%04d.json', $type->value, $this->chunkCounters[$type->value]);

        $dir = $this->storagePath . DIRECTORY_SEPARATOR . 'entities' . DIRECTORY_SEPARATOR . $type->value;
        $this->ensureDir($dir);

        $path = $dir . DIRECTORY_SEPARATOR . $fileName;
        if (@file_put_contents($path, $this->serializer->encode($records)) === false) {
            throw new SuperExportException('Cannot write chunk file: ' . $path);
        }

        $this->writtenChunks[$type->value][] = $fileName;
        $this->buffers[$type->value] = [];
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new SuperExportException('Cannot create directory: ' . $dir);
        }
    }
}
