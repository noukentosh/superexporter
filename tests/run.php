<?php

declare(strict_types=1);

require __DIR__ . '/../superexport/src/autoload.php';
require __DIR__ . '/Fixtures/WordPressSchema.php';
require __DIR__ . '/Fixtures/BitrixSchema.php';
require __DIR__ . '/Fixtures/JoomlaSchema.php';
require __DIR__ . '/Fixtures/ModxSchema.php';
require __DIR__ . '/Fixtures/OpenCartSchema.php';
require __DIR__ . '/Fixtures/DrupalSchema.php';
require __DIR__ . '/Unit/CoreUnitTests.php';
require __DIR__ . '/TestRunner.php';

exit((new SuperExport\Tests\TestRunner())->runAll());
