<?php

declare(strict_types=1);

namespace SuperExport\Core;

use SuperExport\Contracts\CmsAdapterInterface;
use SuperExport\Exceptions\CmsNotDetectedException;

/**
 * Asks each registered adapter to detect its CMS at the given root path.
 * First positive match wins (registration order defines priority).
 */
final class CmsDetector
{
    /** @var list<CmsAdapterInterface> */
    private array $adapters = [];

    public function register(CmsAdapterInterface $adapter): void
    {
        $this->adapters[] = $adapter;
    }

    /** @return list<string> */
    public function getSupportedCmsNames(): array
    {
        return array_map(
            static fn (CmsAdapterInterface $a): string => $a->getName(),
            $this->adapters
        );
    }

    public function detect(string $rootPath): CmsAdapterInterface
    {
        $adapter = $this->tryDetect($rootPath);
        if ($adapter === null) {
            throw new CmsNotDetectedException($this->getSupportedCmsNames());
        }

        return $adapter;
    }

    public function tryDetect(string $rootPath): ?CmsAdapterInterface
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->detect($rootPath)) {
                return $adapter;
            }
        }

        return null;
    }

    public function getAdapterByName(string $name): ?CmsAdapterInterface
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->getName() === $name) {
                return $adapter;
            }
        }

        return null;
    }

    /**
     * Run detection for every registered adapter (for diagnostics UI).
     *
     * @return list<array{
     *     name: string,
     *     label: string,
     *     detected: bool,
     *     selected: bool,
     *     checks: list<array{label: string, passed: bool, level: int, detail?: string}>
     * }>
     */
    public function scanAll(string $rootPath): array
    {
        $results = [];
        $selectedName = null;

        foreach ($this->adapters as $adapter) {
            $name = $adapter->getName();
            $probe = $adapter->probeDetection($rootPath);
            $detected = $probe['detected'];
            if ($detected && $selectedName === null) {
                $selectedName = $name;
            }

            $results[] = [
                'name' => $name,
                'label' => self::formatLabel($name),
                'detected' => $detected,
                'selected' => false,
                'checks' => $probe['checks'],
            ];
        }

        if ($selectedName !== null) {
            foreach ($results as &$row) {
                $row['selected'] = $row['name'] === $selectedName;
            }
            unset($row);
        }

        return $results;
    }

    private static function formatLabel(string $name): string
    {
        return match ($name) {
            'wordpress' => 'WordPress',
            'bitrix' => 'Bitrix',
            'opencart' => 'OpenCart',
            'joomla' => 'Joomla',
            'modx' => 'MODX',
            'drupal' => 'Drupal',
            default => ucfirst($name),
        };
    }
}
