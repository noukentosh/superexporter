<?php

declare(strict_types=1);

namespace SuperExport\Universal;

use SuperExport\Exceptions\SuperExportException;

/**
 * JSON encoding/decoding with consistent flags and error handling.
 */
final class Serializer
{
    private const ENCODE_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT;

    /**
     * @param array<mixed> $data
     */
    public function encode(array $data): string
    {
        try {
            return json_encode($data, self::ENCODE_FLAGS | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new SuperExportException('JSON encode failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array<mixed>
     */
    public function decode(string $json): array
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new SuperExportException('JSON decode failed: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($data)) {
            throw new SuperExportException('JSON root must be an object or array.');
        }

        return $data;
    }
}
