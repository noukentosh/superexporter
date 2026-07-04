<?php

declare(strict_types=1);

namespace SuperExport\Exceptions;

class CmsNotDetectedException extends SuperExportException
{
    /**
     * @param list<string> $supportedCms
     */
    public function __construct(array $supportedCms)
    {
        $supported = $supportedCms === []
            ? '(no adapters registered yet)'
            : implode(', ', $supportedCms);

        parent::__construct('CMS could not be detected. Supported: ' . $supported);
    }
}
