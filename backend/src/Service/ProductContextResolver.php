<?php

namespace App\Service;

use App\Entity\EntryPoint;
use App\Entity\ExternalTool;
use App\Entity\Product;
use App\Entity\Tenant;
use App\Repository\ExternalToolRepository;
use App\Repository\ProductRepository;

final class ProductContextResolver
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ExternalToolRepository $externalTools,
    ) {
    }

    /**
     * @return array{
     *     selected_product: ?Product,
     *     product_candidates: list<Product>,
     *     selection_source: string,
     *     search_query_used: ?string,
     *     candidate_count: int,
     *     needs_service_clarification: bool,
     *     fallback_to_mcp_allowed: bool,
     *     reason: ?string,
     * }
     */
    public function resolve(Tenant $tenant, ?EntryPoint $entryPoint, ?Product $explicitProduct, ?string $currentMessage): array
    {
        if ($entryPoint instanceof EntryPoint) {
            $entryPointProduct = $entryPoint->getProduct();
            if ($entryPointProduct->getTenant()->getId()->toRfc4122() === $tenant->getId()->toRfc4122() && $entryPointProduct->isActive()) {
                return $this->resolvedResult(
                    $entryPointProduct,
                    [],
                    'entry_point',
                    null,
                    1,
                    false,
                    false,
                    'entry point product selected',
                );
            }
        }

        if ($explicitProduct instanceof Product) {
            return $this->resolvedResult(
                $explicitProduct,
                [],
                'explicit_product_id',
                null,
                1,
                false,
                false,
                'explicit product requested',
            );
        }

        $searchQuery = $this->extractSearchQuery($currentMessage);
        if ($searchQuery === null) {
            return $this->resolvedResult(
                null,
                [],
                'none',
                null,
                0,
                true,
                false,
                'missing service query',
            );
        }

        $matches = $this->products->searchActiveByTenantAndText($tenant, $searchQuery, 20);
        $ranked = $this->rankProducts($matches, $searchQuery);

        if ($ranked === []) {
            $fallbackAllowed = $this->externalTools->findRuntimeDefaultMcpByTenant($tenant) instanceof ExternalTool;

            return $this->resolvedResult(
                null,
                [],
                'none',
                $searchQuery,
                0,
                !$fallbackAllowed,
                $fallbackAllowed,
                $fallbackAllowed ? 'no local product match; MCP fallback available' : 'no local product match',
            );
        }

        if (count($ranked) === 1) {
            return $this->resolvedResult(
                $ranked[0]['product'],
                [],
                'sa_search',
                $searchQuery,
                1,
                false,
                false,
                'single local product match',
            );
        }

        $topScore = $ranked[0]['score'];
        $secondScore = $ranked[1]['score'] ?? 0;
        $topProduct = $ranked[0]['product'];
        if ($topProduct instanceof Product && $topScore >= 80 && ($secondScore === 0 || ($topScore - $secondScore) >= 20)) {
            return $this->resolvedResult(
                $topProduct,
                [],
                'sa_search',
                $searchQuery,
                1,
                false,
                false,
                'clear local product match',
            );
        }

        $candidates = array_map(
            static fn (array $item): Product => $item['product'],
            array_slice($ranked, 0, 5),
        );

        return $this->resolvedResult(
            null,
            $candidates,
            'sa_search',
            $searchQuery,
            count($candidates),
            true,
            false,
            'multiple local product candidates',
        );
    }

    /**
     * @return array{
     *     selected_product: ?Product,
     *     product_candidates: list<Product>,
     *     selection_source: string,
     *     search_query_used: ?string,
     *     candidate_count: int,
     *     needs_service_clarification: bool,
     *     fallback_to_mcp_allowed: bool,
     *     reason: ?string,
     * }
     */
    private function resolvedResult(
        ?Product $selectedProduct,
        array $productCandidates,
        string $selectionSource,
        ?string $searchQueryUsed,
        int $candidateCount,
        bool $needsServiceClarification,
        bool $fallbackToMcpAllowed,
        ?string $reason,
    ): array {
        return [
            'selected_product' => $selectedProduct,
            'product_candidates' => $productCandidates,
            'selection_source' => $selectionSource,
            'search_query_used' => $searchQueryUsed,
            'candidate_count' => $candidateCount,
            'needs_service_clarification' => $needsServiceClarification,
            'fallback_to_mcp_allowed' => $fallbackToMcpAllowed,
            'reason' => $reason,
        ];
    }

    private function extractSearchQuery(?string $message): ?string
    {
        if (!is_string($message)) {
            return null;
        }

        $normalized = trim(mb_strtolower($message));
        if ($normalized === '') {
            return null;
        }

        if ($this->looksLikeGreeting($normalized)) {
            return null;
        }

        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $normalized) ?: [];
        $tokens = array_values(array_filter(array_map('trim', $tokens), static fn (string $token): bool => $token !== '' && mb_strlen($token) >= 3));

        $stopwords = [
            'quiero', 'necesito', 'busco', 'informacion', 'información', 'info', 'sobre', 'para', 'por', 'con', 'sin',
            'servicio', 'servicios', 'producto', 'productos', 'tratamiento', 'tratamientos', 'precio', 'precios', 'presupuesto',
            'presupuestos', 'costo', 'coste', 'detalle', 'detalles', 'ver', 'mostrar', 'consulta', 'consultar', 'ayuda',
            'más', 'mas', 'algo', 'algun', 'algún', 'otro', 'otra', 'gustaria', 'gustaría',
        ];

        $filtered = [];
        foreach ($tokens as $token) {
            if (in_array($token, $stopwords, true)) {
                continue;
            }

            $filtered[] = $token;
        }

        $query = trim(implode(' ', $filtered));
        if ($query === '' || mb_strlen($query) < 3) {
            return null;
        }

        return $query;
    }

    private function looksLikeGreeting(string $message): bool
    {
        $trimmed = trim($message);
        if ($trimmed === '') {
            return false;
        }

        return (bool) preg_match('/^(hola|buenas|buen dia|buen día|gracias)([\\s!,.:-]*)$/u', $trimmed);
    }

    /**
     * @param list<Product> $products
     * @return list<array{product: Product, score: int}>
     */
    private function rankProducts(array $products, string $query): array
    {
        $queryNormalized = mb_strtolower(trim($query));
        $queryTokens = $this->tokenize($queryNormalized);
        if ($queryTokens === []) {
            return [];
        }

        $ranked = [];
        foreach ($products as $product) {
            if (!$product instanceof Product) {
                continue;
            }

            $score = $this->scoreProduct($product, $queryNormalized, $queryTokens);
            if ($score <= 0) {
                continue;
            }

            $ranked[] = [
                'product' => $product,
                'score' => $score,
            ];
        }

        usort($ranked, static function (array $left, array $right): int {
            $scoreCompare = $right['score'] <=> $left['score'];
            if ($scoreCompare !== 0) {
                return $scoreCompare;
            }

            return strcasecmp($left['product']->getName(), $right['product']->getName());
        });

        return $ranked;
    }

    /**
     * @param list<string> $queryTokens
     */
    private function scoreProduct(Product $product, string $query, array $queryTokens): int
    {
        $fields = [
            'name' => mb_strtolower($product->getName()),
            'slug' => mb_strtolower($product->getSlug()),
            'description' => mb_strtolower($product->getDescription()),
            'value' => mb_strtolower($product->getValueProposition()),
            'external' => mb_strtolower((string) $product->getExternalReference()),
        ];

        $score = 0;
        if ($fields['name'] === $query) {
            $score += 100;
        } elseif (str_starts_with($fields['name'], $query)) {
            $score += 85;
        }

        if ($fields['slug'] === $query) {
            $score += 90;
        } elseif (str_starts_with($fields['slug'], $query)) {
            $score += 75;
        }

        foreach ($queryTokens as $token) {
            if ($token === '') {
                continue;
            }

            if (str_contains($fields['name'], $token)) {
                $score += 18;
            }

            if (str_contains($fields['slug'], $token)) {
                $score += 14;
            }

            if (str_contains($fields['value'], $token)) {
                $score += 8;
            }

            if (str_contains($fields['description'], $token)) {
                $score += 4;
            }

            if ($fields['external'] !== '' && str_contains($fields['external'], $token)) {
                $score += 2;
            }
        }

        return $score;
    }

    /**
     * @return list<string>
     */
    private function tokenize(string $query): array
    {
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $query) ?: [];
        $filtered = [];
        foreach ($tokens as $token) {
            $token = trim($token);
            if ($token === '' || mb_strlen($token) < 3) {
                continue;
            }

            $filtered[] = $token;
        }

        return array_values(array_unique($filtered));
    }
}
