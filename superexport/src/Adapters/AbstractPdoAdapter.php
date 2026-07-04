<?php

declare(strict_types=1);

namespace SuperExport\Adapters;

use PDO;
use PDOStatement;
use SuperExport\Contracts\CmsAdapterInterface;
use SuperExport\Contracts\ImportBatchResult;
use SuperExport\Contracts\ImportContextInterface;
use SuperExport\Exceptions\SuperExportException;
use SuperExport\Universal\EntityType;

/**
 * Shared PDO utilities for CMS adapters.
 */
abstract class AbstractPdoAdapter implements CmsAdapterInterface
{
    protected ?PDO $pdo = null;
    protected ?string $dbPrefix = null;
    protected ?string $cmsVersion = null;
    protected ?string $siteUrl = null;
    protected string $rootPath = '';

    /** @param array<string, mixed> $config */
    public function __construct(protected readonly array $config = [])
    {
    }

    abstract protected function connectFromCms(string $rootPath): PDO;

    abstract protected function readCmsMetadata(string $rootPath): void;

    protected function getPdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        if (isset($this->config['db']['pdo']) && $this->config['db']['pdo'] instanceof PDO) {
            $this->pdo = $this->config['db']['pdo'];

            return $this->pdo;
        }

        $explicit = $this->config['db'] ?? [];
        if (!empty($explicit['dsn'])) {
            $this->pdo = new PDO(
                (string) $explicit['dsn'],
                (string) ($explicit['user'] ?? ''),
                (string) ($explicit['password'] ?? ''),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
            );

            return $this->pdo;
        }

        if ($this->rootPath === '') {
            throw new SuperExportException('Database connection not initialized for ' . $this->getName() . ' adapter.');
        }

        $this->pdo = $this->connectFromCms($this->rootPath);

        return $this->pdo;
    }

    protected function initForRoot(string $rootPath): void
    {
        $this->rootPath = rtrim($rootPath, '/\\');
        $this->readCmsMetadata($this->rootPath);
        $this->getPdo();
    }

    protected function table(string $name): string
    {
        return ($this->dbPrefix ?? '') . $name;
    }

    protected function tableExists(string $name): bool
    {
        try {
            $this->getPdo()->query('SELECT 1 FROM ' . $this->table($name) . ' LIMIT 1');
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    protected function batchedQuery(string $sql, int $batchSize, array $params = []): \Generator
    {
        $offset = 0;
        do {
            $stmt = $this->getPdo()->prepare($sql . ' LIMIT ' . (int) $batchSize . ' OFFSET ' . $offset);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            foreach ($rows as $row) {
                yield $row;
            }
            $count = count($rows);
            $offset += $count;
        } while ($count === $batchSize);
    }

    protected function scalarCount(string $sql, array $params = []): int
    {
        $stmt = $this->getPdo()->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();

        return (int) $value;
    }

    protected function isoDate(?string $value): ?string
    {
        if ($value === null || $value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }

        $ts = strtotime($value);

        return $ts !== false ? gmdate('Y-m-d\TH:i:s\Z', $ts) : null;
    }

    protected function mapStatus(string $native, array $map, string $default = 'draft'): string
    {
        return $map[$native] ?? $default;
    }

    /**
     * @param list<array<string, mixed>> $entities
     */
    protected function dryRunResult(array $entities): ImportBatchResult
    {
        $idMap = [];
        foreach ($entities as $entity) {
            $idMap[(string) $entity['source_id']] = 'dry-run-' . $entity['source_id'];
        }

        return new ImportBatchResult(created: count($entities), idMap: $idMap);
    }

    protected function resolveSlug(
        string $slug,
        string $duplicateStrategy,
        callable $exists,
    ): ?string {
        if (!$exists($slug)) {
            return $slug;
        }

        return match ($duplicateStrategy) {
            'skip' => null,
            'suffix' => $this->uniqueSlug($slug, $exists),
            'overwrite' => $slug,
            default => null,
        };
    }

    private function uniqueSlug(string $slug, callable $exists): string
    {
        $candidate = $slug;
        $i = 2;
        while ($exists($candidate)) {
            $candidate = $slug . '-' . $i;
            $i++;
        }

        return $candidate;
    }

    /** @return array<string, string> */
    protected static function parsePhpDefines(string $content, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            if (preg_match('/define\s*\(\s*[\'"]' . preg_quote($key, '/') . '[\'"]\s*,\s*[\'"]([^\'"]*)[\'"]\s*\)/', $content, $m)) {
                $out[$key] = $m[1];
            } elseif (preg_match('/\$' . preg_quote($key, '/') . '\s*=\s*[\'"]([^\'"]*)[\'"]\s*;/', $content, $m)) {
                $out[$key] = $m[1];
            }
        }

        return $out;
    }

    public function getCmsVersion(): ?string
    {
        return $this->cmsVersion;
    }

    public function getSiteUrl(): ?string
    {
        return $this->siteUrl;
    }

    public function getDbPrefix(): ?string
    {
        return $this->dbPrefix;
    }

    public function detect(string $rootPath): bool
    {
        if (!$this->canDetectByFiles($rootPath)) {
            return false;
        }

        try {
            $this->initForRoot($rootPath);
        } catch (\Throwable) {
            return false;
        }

        return $this->canDetectByTables();
    }

    abstract protected function canDetectByFiles(string $rootPath): bool;

    abstract protected function canDetectByTables(): bool;
}
