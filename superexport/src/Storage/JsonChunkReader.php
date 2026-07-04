<?php

declare(strict_types=1);

namespace SuperExport\Storage;

use SuperExport\Exceptions\SuperExportException;
use SuperExport\Universal\EntityKey;
use SuperExport\Universal\Serializer;

/**
 * Reads chunk files produced by JsonChunkWriter.
 */
final class JsonChunkReader
{
    public function __construct(
        private readonly string $storagePath,
        private readonly Serializer $serializer,
    ) {
    }

    /**
     * Iterate over all records of a key across the given chunk files.
     *
     * @param list<string> $chunkFiles Relative file names from the manifest.
     * @return \Generator<int, array<string, mixed>>
     */
    public function read(EntityKey $key, array $chunkFiles): \Generator
    {
        foreach ($chunkFiles as $fileName) {
            foreach ($this->readChunk($key, $fileName) as $record) {
                yield $record;
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function readChunk(EntityKey $key, string $fileName): array
    {
        if (basename($fileName) !== $fileName) {
            throw new SuperExportException('Invalid chunk file name: ' . $fileName);
        }

        $path = $this->storagePath . DIRECTORY_SEPARATOR . 'entities'
            . DIRECTORY_SEPARATOR . $key->storageKey() . DIRECTORY_SEPARATOR . $fileName;

        if (!is_file($path)) {
            throw new SuperExportException('Chunk file not found: ' . $path);
        }

        $json = @file_get_contents($path);
        if ($json === false) {
            throw new SuperExportException('Cannot read chunk file: ' . $path);
        }

        /** @var list<array<string, mixed>> */
        return $this->serializer->decode($json);
    }
}
