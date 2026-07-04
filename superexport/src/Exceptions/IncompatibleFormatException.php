<?php

declare(strict_types=1);

namespace SuperExport\Exceptions;

class IncompatibleFormatException extends SuperExportException
{
    public function __construct(string $foundVersion, string $supportedVersion)
    {
        parent::__construct(sprintf(
            'Incompatible manifest format_version "%s" (supported: "%s.x").',
            $foundVersion,
            $supportedVersion
        ));
    }
}
