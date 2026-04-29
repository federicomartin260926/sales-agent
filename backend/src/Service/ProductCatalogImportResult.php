<?php

namespace App\Service;

final class ProductCatalogImportResult
{
    /**
     * @param array<int, array{row: int, message: string}> $errors
     */
    public function __construct(
        public int $created = 0,
        public int $updated = 0,
        public int $omitted = 0,
        public array $errors = [],
    ) {
    }

    public function totalProcessed(): int
    {
        return $this->created + $this->updated + $this->omitted + count($this->errors);
    }
}
