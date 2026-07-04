<?php

declare(strict_types=1);

namespace SuperExport\Contracts;

/**
 * Outcome of importing one batch of entities.
 */
final class ImportBatchResult
{
    /**
     * @param array<string|int, string|int> $idMap  source_id => target_id created in this batch
     * @param list<string>                  $errors Human-readable error messages
     */
    public function __construct(
        public readonly int $created = 0,
        public readonly int $skipped = 0,
        public readonly array $idMap = [],
        public readonly array $errors = [],
    ) {
    }

    public function merge(self $other): self
    {
        return new self(
            $this->created + $other->created,
            $this->skipped + $other->skipped,
            $this->idMap + $other->idMap,
            array_merge($this->errors, $other->errors),
        );
    }
}
