<?php

namespace App\Service;

use App\Entity\Product;
use App\Entity\Tenant;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;

final class ProductCatalogImportService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function import(Tenant $tenant, string $payload, string $format = 'auto'): ProductCatalogImportResult
    {
        $result = new ProductCatalogImportResult();
        $format = strtolower(trim($format));

        if ($format === 'auto') {
            $format = $this->detectFormat($payload);
        }

        $records = $format === 'json' ? $this->parseJson($payload, $result) : $this->parseCsv($payload, $result);

        foreach ($records as $index => $record) {
            $row = $index + 1;
            try {
                $this->upsertRecord($tenant, $record, $row, $result);
            } catch (\Throwable $exception) {
                $result->errors[] = [
                    'row' => $row,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        if ($result->created > 0 || $result->updated > 0) {
            $this->entityManager->flush();
        }

        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $records
     * @param array<int, array{row: int, message: string}> $errors
     * @return array<int, array<string, mixed>>
     */
    private function parseJson(string $payload, ProductCatalogImportResult $result): array
    {
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            $result->errors[] = [
                'row' => 0,
                'message' => 'JSON inválido.',
            ];

            return [];
        }

        $records = $decoded['items'] ?? $decoded;
        if (!is_array($records)) {
            $result->errors[] = [
                'row' => 0,
                'message' => 'El JSON debe contener un array de items.',
            ];

            return [];
        }

        $normalized = [];
        foreach ($records as $item) {
            if (is_array($item)) {
                $normalized[] = $item;
            } else {
                $result->errors[] = [
                    'row' => count($normalized) + 1,
                    'message' => 'Cada item JSON debe ser un objeto.',
                ];
            }
        }

        return $normalized;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseCsv(string $payload, ProductCatalogImportResult $result): array
    {
        $payload = trim($payload);
        if ($payload === '') {
            $result->errors[] = [
                'row' => 0,
                'message' => 'El CSV está vacío.',
            ];

            return [];
        }

        $delimiter = $this->detectDelimiter($payload);
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            $result->errors[] = [
                'row' => 0,
                'message' => 'No se pudo leer el CSV.',
            ];

            return [];
        }

        fwrite($handle, $payload);
        rewind($handle);

        $headers = null;
        $records = [];
        $row = 0;
        while (($line = fgetcsv($handle, 0, $delimiter)) !== false) {
            $row++;
            if ($line === [null] || $line === []) {
                continue;
            }

            if ($headers === null) {
                $headers = array_map(static fn (string $header): string => trim(ProductCatalogImportService::stripBom($header)), $line);
                continue;
            }

            $record = [];
            foreach ($headers as $index => $header) {
                if ($header === '') {
                    continue;
                }
                $record[$header] = $line[$index] ?? null;
            }

            $records[] = $record;
        }

        fclose($handle);

        if ($headers === null) {
            $result->errors[] = [
                'row' => 0,
                'message' => 'El CSV no tiene cabecera.',
            ];
        }

        return $records;
    }

    private function upsertRecord(Tenant $tenant, array $record, int $row, ProductCatalogImportResult $result): void
    {
        $name = $this->firstString($record, ['name']);
        if ($name === null || $name === '') {
            throw new \RuntimeException(sprintf('Fila %d: falta name.', $row));
        }

        $slug = $this->firstString($record, ['slug']);
        $integrationKey = $this->firstString($record, ['integration_key', 'external_reference']);
        $description = $this->firstString($record, ['description']);
        $valueProposition = $this->firstString($record, ['value_proposition', 'valueProposition']);
        $basePriceCents = $this->intOrNull($this->firstMixed($record, ['base_price_cents', 'basePriceCents']));
        $currency = $this->firstString($record, ['currency']);
        $active = $this->boolOrNull($this->firstMixed($record, ['active', 'is_active', 'isActive']));
        $externalSource = $integrationKey !== null ? 'crm' : $this->firstString($record, ['external_source', 'externalSource']);
        $externalReference = $integrationKey !== null ? $integrationKey : $this->firstString($record, ['external_reference', 'externalReference']);

        $product = null;
        if ($externalSource !== null && $externalReference !== null) {
            $product = $this->products->findOneByExternalIdentity($tenant, $externalSource, $externalReference);
        }

        if (!$product instanceof Product && $slug !== null && $slug !== '') {
            $product = $this->products->findOneByTenantAndSlug($tenant, $slug);
        }

        if (!$product instanceof Product) {
            $product = new Product($tenant, $name, $slug);
            $isNew = true;
        } else {
            $isNew = false;
        }

        $before = $this->productState($product);

        $product->setTenant($tenant);
        $product->setName($name);
        if ($slug !== null && $slug !== '') {
            $product->setSlug($slug);
        }
        if ($description !== null) {
            $product->setDescription($description);
        }
        if ($valueProposition !== null) {
            $product->setValueProposition($valueProposition);
        }
        if ($basePriceCents !== null || $this->hasKey($record, ['base_price_cents', 'basePriceCents'])) {
            $product->setBasePriceCents($basePriceCents);
        }
        if ($currency !== null || $this->hasKey($record, ['currency'])) {
            $product->setCurrency($currency);
        }
        if ($active !== null) {
            $product->setActive($active);
        }
        if ($externalSource !== null || $this->hasKey($record, ['external_source', 'externalSource']) || $integrationKey !== null) {
            $product->setExternalSource($externalSource);
        }
        if ($externalReference !== null || $this->hasKey($record, ['external_reference', 'externalReference']) || $integrationKey !== null) {
            $product->setExternalReference($externalReference);
        }

        $after = $this->productState($product);
        if ($isNew) {
            $this->entityManager->persist($product);
            $result->created++;
            return;
        }

        if ($before === $after) {
            $result->omitted++;
            return;
        }

        $result->updated++;
    }

    /**
     * @return array<string, mixed>
     */
    private function productState(Product $product): array
    {
        return [
            'name' => $product->getName(),
            'slug' => $product->getSlug(),
            'externalSource' => $product->getExternalSource(),
            'externalReference' => $product->getExternalReference(),
            'description' => $product->getDescription(),
            'valueProposition' => $product->getValueProposition(),
            'basePriceCents' => $product->getBasePriceCents(),
            'currency' => $product->getCurrency(),
            'isActive' => $product->isActive(),
        ];
    }

    private function detectFormat(string $payload): string
    {
        $trimmed = ltrim($payload);
        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            return 'json';
        }

        return 'csv';
    }

    private function detectDelimiter(string $payload): string
    {
        $firstLine = strtok($payload, "\n");
        if ($firstLine === false) {
            return ',';
        }

        $semiCount = substr_count($firstLine, ';');
        $commaCount = substr_count($firstLine, ',');

        return $semiCount > $commaCount ? ';' : ',';
    }

    private function firstString(array $record, array $keys): ?string
    {
        $value = $this->firstMixed($record, $keys);
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        return $trimmed !== '' ? $trimmed : null;
    }

    private function firstMixed(array $record, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $record)) {
                return $record[$key];
            }
        }

        return null;
    }

    private function hasKey(array $record, array $keys): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $record)) {
                return true;
            }
        }

        return false;
    }

    private function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return null;
            }

            return is_numeric($trimmed) ? (int) $trimmed : null;
        }

        return null;
    }

    private function boolOrNull(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (!is_string($value)) {
            return null;
        }

        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return null;
        }

        return in_array($normalized, ['1', 'true', 'yes', 'y', 'activo', 'active'], true)
            ? true
            : (in_array($normalized, ['0', 'false', 'no', 'n', 'inactivo', 'inactive'], true) ? false : null);
    }

    private static function stripBom(string $value): string
    {
        return preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
    }
}
