<?php

declare(strict_types=1);

namespace SuperExport\Adapters;

use SuperExport\Adapters\Bitrix\BitrixAdapter;
use SuperExport\Adapters\Drupal\DrupalAdapter;
use SuperExport\Adapters\Joomla\JoomlaAdapter;
use SuperExport\Adapters\Modx\ModxAdapter;
use SuperExport\Adapters\OpenCart\OpenCartAdapter;
use SuperExport\Adapters\WordPress\WordPressAdapter;
use SuperExport\Core\CmsDetector;
use SuperExport\Core\Engine;

/**
 * Registers all CMS adapters on the engine/detector.
 */
final class AdapterRegistrar
{
    /** @param array<string, mixed> $config */
    public static function registerAll(Engine $engine, array $config): void
    {
        $adapters = [
            new WordPressAdapter($config),
            new BitrixAdapter($config),
            new OpenCartAdapter($config),
            new JoomlaAdapter($config),
            new ModxAdapter($config),
            new DrupalAdapter($config),
        ];

        foreach ($adapters as $adapter) {
            $engine->registerAdapter($adapter);
        }
    }
}
