<?php

declare(strict_types=1);

namespace SuperExport\Storage;

use SuperExport\Exceptions\SuperExportException;
use SuperExport\Universal\EntityKey;
use SuperExport\Universal\Serializer;

/**
 * Writes canonical entities into paginated chunk files:
 * storage/entities/posts/posts_0001.json, entities/cpt:portfolio/cpt:portfolio_0001.json, ...
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
    public function write(EntityKey $key, array $record): void
    {
        $this->buffers[$key->value][] = $record;

        if (count($this->buffers[$key->value]) >= $this->chunkSize) {
            $this->flushType($key);
        }
    }

    /**
     * Flush all pending buffers and return the chunk file map for the manifest.
     *
     * @return array<string, list<string>> entity key => relative chunk file names
     */
    public function finalize(): array
    {
        foreach (array_keys($this->buffers) as $keyValue) {
            $key = EntityKey::parse($keyValue);
            if ($this->buffers[$keyValue] !== []) {
                $this->flushType($key);
            }
        }

        return $this->writtenChunks;
    }

    private function flushType(EntityKey $key): void
    {
        $records = $this->buffers[$key->value] ?? [];
        if ($records === []) {
            return;
        }

        $this->chunkCounters[$key->value] = ($this->chunkCounters[$key->value] ?? 0) + 1;
        $fileName = sprintf('%s_%04d.json', $key->storageKey(), $this->chunkCounters[$key->value]);

        $dir = $this->storagePath . DIRECTORY_SEPARATOR . 'entities' . DIRECTORY_SEPARATOR . $key->storageKey();
        $this->ensureDir($dir);

        $path = $dir . DIRECTORY_SEPARATOR . $fileName;
        if (@file_put_contents($path, $this->serializer->encode($records)) === false) {
            throw new SuperExportException('Cannot write chunk file: ' . $path);
        }

        $this->writtenChunks[$key->value][] = $fileName;
        $this->buffers[$key->value] = [];
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new SuperExportException('Cannot create directory: ' . $dir);
        }
    }
}
