<?php

declare(strict_types=1);

namespace SuperExport\Cli;

use SuperExport\Core\Engine;
use SuperExport\Exceptions\SuperExportException;

/**
 * CLI command dispatcher:
 *   php superexport.php detect
 *   php superexport.php export --output=./storage
 *   php superexport.php import --input=./storage [--dry-run] [--resume] [--duplicates=skip|suffix|overwrite]
 */
final class Commands
{
    public function __construct(private readonly Engine $engine)
    {
    }

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        $command = $argv[1] ?? 'help';
        $options = $this->parseOptions(array_slice($argv, 2));

        try {
            return match ($command) {
                'detect' => $this->detect(),
                'export' => $this->export($options),
                'import' => $this->import($options),
                'help', '--help', '-h' => $this->help(),
                default => $this->unknown($command),
            };
        } catch (SuperExportException $e) {
            $this->err('Error: ' . $e->getMessage());

            return 1;
        }
    }

    private function detect(): int
    {
        $adapter = $this->engine->detectCms();
        $this->out('CMS detected: ' . $adapter->getName());
        $this->out('Version:      ' . ($adapter->getCmsVersion() ?? 'unknown'));
        $this->out('Site URL:     ' . ($adapter->getSiteUrl() ?? 'unknown'));
        $this->out('DB prefix:    ' . ($adapter->getDbPrefix() ?? 'unknown'));

        $entities = array_map(static fn ($t) => $t->value, $adapter->getSupportedEntities());
        $this->out('Entities:     ' . implode(', ', $entities));

        return 0;
    }

    /**
     * @param array<string, string|bool> $options
     */
    private function export(array $options): int
    {
        $output = isset($options['output']) ? (string) $options['output'] : null;
        $result = $this->engine->export($output);

        $this->out('Manifest: ' . $result['manifest_path']);
        foreach ($result['stats'] as $entity => $count) {
            $this->out(sprintf('  %-12s %d', $entity, $count));
        }

        return 0;
    }

    /**
     * @param array<string, string|bool> $options
     */
    private function import(array $options): int
    {
        $input = isset($options['input']) ? (string) $options['input'] : null;
        if ($input === null || $input === '') {
            $this->err('Missing required option: --input=<storage dir>');

            return 1;
        }

        $dryRun = (bool) ($options['dry-run'] ?? false);
        $resume = (bool) ($options['resume'] ?? false);
        $duplicates = (string) ($options['duplicates'] ?? 'skip');
        $overrides = $this->loadMapping($options);

        if ($resume && $dryRun) {
            $this->err('Cannot combine --resume with --dry-run.');

            return 1;
        }

        $report = $this->engine->import($input, $dryRun, $duplicates, $overrides, $resume);

        $exitCode = 0;
        foreach ($report as $entity => $row) {
            $this->out(sprintf(
                '%-12s created=%d skipped=%d errors=%d',
                $entity,
                $row['created'],
                $row['skipped'],
                count($row['errors'])
            ));
            foreach ($row['errors'] as $error) {
                $this->err('  ' . $error);
                $exitCode = 1;
            }
        }

        return $exitCode;
    }

    /**
     * @param array<string, string|bool> $options
     * @return array<string, array<string, string>>
     */
    private function loadMapping(array $options): array
    {
        $path = isset($options['mapping']) ? (string) $options['mapping'] : null;
        if ($path === null || $path === '') {
            return [];
        }

        if (!is_file($path)) {
            throw new SuperExportException('Mapping file not found: ' . $path);
        }

        $json = (string) file_get_contents($path);
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new SuperExportException('Mapping file is not valid JSON: ' . $path);
        }

        /** @var array<string, array<string, string>> */
        return $data['field_overrides'] ?? $data;
    }

    private function help(): int
    {
        $this->out('SuperExport — universal CMS content export/import');
        $this->out('');
        $this->out('Usage:');
        $this->out('  php superexport.php detect');
        $this->out('  php superexport.php export [--output=./storage]');
        $this->out('  php superexport.php import --input=./storage [--dry-run] [--resume]');
        $this->out('                             [--duplicates=skip|suffix|overwrite]');
        $this->out('                             [--mapping=import_map.json]');

        return 0;
    }

    private function unknown(string $command): int
    {
        $this->err('Unknown command: ' . $command);
        $this->help();

        return 1;
    }

    /**
     * @param list<string> $args
     * @return array<string, string|bool>
     */
    private function parseOptions(array $args): array
    {
        $options = [];
        foreach ($args as $arg) {
            if (!str_starts_with($arg, '--')) {
                continue;
            }
            $arg = substr($arg, 2);
            if (str_contains($arg, '=')) {
                [$name, $value] = explode('=', $arg, 2);
                $options[$name] = $value;
            } else {
                $options[$arg] = true;
            }
        }

        return $options;
    }

    private function out(string $line): void
    {
        fwrite(STDOUT, $line . PHP_EOL);
    }

    private function err(string $line): void
    {
        fwrite(STDERR, $line . PHP_EOL);
    }
}
