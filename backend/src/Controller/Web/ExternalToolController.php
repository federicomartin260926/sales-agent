<?php

namespace App\Controller\Web;

use App\Entity\ExternalTool;
use App\Entity\Tenant;
use App\Repository\ExternalToolRepository;
use App\Repository\TenantRepository;
use App\Service\RuntimeSettingCipher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[Route('/external-tools')]
final class ExternalToolController extends AbstractController
{
    private const TOOL_TYPES = ['contact_context'];
    private const PROVIDERS = ['n8n_webhook'];
    private const AUTH_TYPES = ['none', 'bearer'];
    private const TEST_TOOL_TYPE = 'contact_context';

    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantRepository $tenants,
        private readonly ExternalToolRepository $externalTools,
        private readonly RuntimeSettingCipher $cipher,
        private readonly HttpClientInterface $httpClient,
        private readonly ?CsrfTokenManagerInterface $csrfTokenManager = null,
    ) {
    }

    #[Route('', name: 'backend_external_tools_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->security->isGranted('ROLE_ADMIN')) {
            return $this->redirect('/backend/login');
        }

        $tenantFilter = trim((string) $request->query->get('tenant_id', ''));
        $selectedTenant = $tenantFilter !== '' ? $this->tenants->find($tenantFilter) : null;
        $filterError = null;
        if ($tenantFilter !== '' && !$selectedTenant instanceof Tenant) {
            $filterError = 'El tenant seleccionado no existe.';
        }

        $tools = $selectedTenant instanceof Tenant
            ? $this->externalTools->findByTenantOrdered($selectedTenant)
            : $this->externalTools->findAllOrdered();

        return $this->render('backend/external_tools/index.html.twig', [
            'page_title' => 'Herramientas externas',
            'page_subtitle' => 'Configuración y prueba de ExternalTools por tenant.',
            'active_nav' => 'admin-external-tools',
            'tenants' => $this->tenantOptions(),
            'tenant_filter' => $tenantFilter,
            'filter_error' => $filterError,
            'tools' => array_map([$this, 'toolRow'], $tools),
            ...$this->templateUserDefaults(),
        ]);
    }

    #[Route('/new', name: 'backend_external_tools_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        if (!$this->security->isGranted('ROLE_ADMIN')) {
            return $this->redirect('/backend/login');
        }

        $values = $this->toolFormDefaults();
        $errors = [];

        if ($request->isMethod('POST')) {
            $values = $this->toolFormValuesFromRequest($request);

            if (!$this->isValidExternalToolToken('external_tool_form_create', (string) $request->request->get('_csrf_token'))) {
                $errors[] = 'La sesión del formulario ha expirado. Vuelve a intentarlo.';
            } else {
                $errors = $this->validateToolForm($values);
                if ($errors === []) {
                    $tenant = $this->tenants->find($values['tenantId']);
                    if ($tenant instanceof Tenant) {
                        $tool = new ExternalTool($tenant, $values['name'], $values['type'], $values['provider']);
                        $this->applyToolFormValues($tool, $values, true);
                        $this->entityManager->persist($tool);
                        $this->entityManager->flush();

                        return new RedirectResponse($this->backendExternalToolsIndexUrl($tenant->getId()->toRfc4122()));
                    }
                    $errors[] = 'El tenant seleccionado no existe.';
                }
            }
        }

        return $this->renderToolFormPage(
            'Crear herramienta externa',
            'Define el webhook externo que el runtime usará para contextos de contacto.',
            'Crear herramienta externa',
            'Guardar herramienta',
            '/backend/external-tools/new',
            $values,
            $errors,
            false,
            null
        );
    }

    #[Route('/{id}/edit', name: 'backend_external_tools_edit', methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request): Response
    {
        if (!$this->security->isGranted('ROLE_ADMIN')) {
            return $this->redirect('/backend/login');
        }

        $tool = $this->externalTools->find($id);
        if (!$tool instanceof ExternalTool) {
            return new RedirectResponse($this->backendExternalToolsIndexUrl());
        }

        $values = $this->toolFormDefaults($tool);
        $errors = [];

        if ($request->isMethod('POST')) {
            $values = $this->toolFormValuesFromRequest($request);

            if (!$this->isValidExternalToolToken('external_tool_form_'.$tool->getId()->toRfc4122(), (string) $request->request->get('_csrf_token'))) {
                $errors[] = 'La sesión del formulario ha expirado. Vuelve a intentarlo.';
            } else {
                $errors = $this->validateToolForm($values, $tool);
                if ($errors === []) {
                    $tenant = $this->tenants->find($values['tenantId']);
                    if ($tenant instanceof Tenant) {
                        $this->applyToolFormValues($tool, $values, false, $tenant);
                        $this->entityManager->persist($tool);
                        $this->entityManager->flush();

                        return new RedirectResponse($this->backendExternalToolsIndexUrl($tenant->getId()->toRfc4122()));
                    }
                    $errors[] = 'El tenant seleccionado no existe.';
                }
            }
        }

        return $this->renderToolFormPage(
            'Editar herramienta externa',
            'Ajusta el webhook, seguridad y parámetros de la herramienta.',
            'Editar herramienta externa',
            'Guardar cambios',
            '/backend/external-tools/'.$tool->getId()->toRfc4122().'/edit',
            $values,
            $errors,
            true,
            $tool
        );
    }

    #[Route('/{id}/toggle', name: 'backend_external_tools_toggle', methods: ['POST'])]
    public function toggle(string $id, Request $request): Response
    {
        if (!$this->security->isGranted('ROLE_ADMIN')) {
            return $this->redirect('/backend/login');
        }

        $tool = $this->externalTools->find($id);
        if (!$tool instanceof ExternalTool) {
            return $this->redirectToRoute('backend_external_tools_index');
        }

        if (!$this->isValidExternalToolToken('external_tool_toggle_'.$tool->getId()->toRfc4122(), (string) $request->request->get('_csrf_token'))) {
            return $this->redirectToRoute('backend_external_tools_index', ['tenant_id' => $tool->getTenant()->getId()->toRfc4122()]);
        }

        $tool->setActive(!$tool->isActive());
        $this->entityManager->persist($tool);
        $this->entityManager->flush();

        $this->addFlash('success', $tool->isActive() ? 'Herramienta externa activada.' : 'Herramienta externa desactivada.');

        return new RedirectResponse($this->backendExternalToolsIndexUrl($tool->getTenant()->getId()->toRfc4122()));
    }

    #[Route('/{id}/delete', name: 'backend_external_tools_delete', methods: ['POST'])]
    public function delete(string $id, Request $request): Response
    {
        if (!$this->security->isGranted('ROLE_ADMIN')) {
            return $this->redirect('/backend/login');
        }

        $tool = $this->externalTools->find($id);
        if (!$tool instanceof ExternalTool) {
            return new RedirectResponse($this->backendExternalToolsIndexUrl());
        }

        if (!$this->isValidExternalToolToken('external_tool_delete_'.$tool->getId()->toRfc4122(), (string) $request->request->get('_csrf_token'))) {
            return new RedirectResponse($this->backendExternalToolsIndexUrl($tool->getTenant()->getId()->toRfc4122()));
        }

        $tenantId = $tool->getTenant()->getId()->toRfc4122();
        $this->entityManager->remove($tool);
        $this->entityManager->flush();
        $this->addFlash('success', 'Herramienta externa eliminada.');

        return new RedirectResponse($this->backendExternalToolsIndexUrl($tenantId));
    }

    #[Route('/{id}/test', name: 'backend_external_tools_test', methods: ['POST'])]
    public function test(string $id, Request $request): Response
    {
        if (!$this->security->isGranted('ROLE_ADMIN')) {
            return $this->redirect('/backend/login');
        }

        $tool = $this->externalTools->find($id);
        if (!$tool instanceof ExternalTool) {
            return new RedirectResponse($this->backendExternalToolsIndexUrl());
        }

        if (!$this->isValidExternalToolToken('external_tool_test_'.$tool->getId()->toRfc4122(), (string) $request->request->get('_csrf_token'))) {
            return new RedirectResponse($this->backendExternalToolsIndexUrl($tool->getTenant()->getId()->toRfc4122()));
        }

        $testResult = $this->runContactContextTest($tool);

        return $this->render('backend/external_tools/index.html.twig', [
            'page_title' => 'Herramientas externas',
            'page_subtitle' => 'Configuración y prueba de ExternalTools por tenant.',
            'active_nav' => 'admin-external-tools',
            'tenants' => $this->tenantOptions(),
            'tenant_filter' => $tool->getTenant()->getId()->toRfc4122(),
            'filter_error' => null,
            'tools' => array_map([$this, 'toolRow'], $this->externalTools->findByTenantOrdered($tool->getTenant())),
            'test_result' => $testResult,
            ...$this->templateUserDefaults(),
        ]);
    }

    /**
     * @return array<int, array{id: string, name: string}>
     */
    private function tenantOptions(): array
    {
        return array_map(
            static fn (Tenant $tenant): array => [
                'id' => $tenant->getId()->toRfc4122(),
                'name' => $tenant->getName(),
            ],
            $this->tenants->findAllOrdered()
        );
    }

    /**
     * @return array{id: string, tenantId: string, tenantName: string, name: string, type: string, provider: string, webhookUrl: string, authType: string, hasBearerToken: bool, timeoutSeconds: int, isActive: bool, configText: string, configSummary: string}
     */
    private function toolRow(ExternalTool $tool): array
    {
        return [
            'id' => $tool->getId()->toRfc4122(),
            'tenantId' => $tool->getTenant()->getId()->toRfc4122(),
            'tenantName' => $tool->getTenant()->getName(),
            'name' => $tool->getName(),
            'type' => $tool->getType(),
            'provider' => $tool->getProvider(),
            'webhookUrl' => $tool->getWebhookUrl() ?? '',
            'authType' => $tool->getAuthType() ?? 'none',
            'hasBearerToken' => $tool->getBearerToken() !== null && $tool->getBearerToken() !== '',
            'timeoutSeconds' => $tool->getTimeoutSeconds(),
            'isActive' => $tool->isActive(),
            'configText' => $this->configToTextarea($tool->getConfig()),
            'configSummary' => $this->configSummary($tool->getConfig()),
            'testToken' => $this->externalToolTokenValue('external_tool_test_'.$tool->getId()->toRfc4122()),
            'toggleToken' => $this->externalToolTokenValue('external_tool_toggle_'.$tool->getId()->toRfc4122()),
            'deleteToken' => $this->externalToolTokenValue('external_tool_delete_'.$tool->getId()->toRfc4122()),
        ];
    }

    /**
     * @return array{name: string, tenantId: string, type: string, provider: string, webhookUrl: string, authType: string, bearerToken: string, timeoutSeconds: string, isActive: bool, config: string}
     */
    private function toolFormDefaults(?ExternalTool $tool = null): array
    {
        return [
            'name' => $tool?->getName() ?? '',
            'tenantId' => $tool?->getTenant()?->getId()->toRfc4122() ?? '',
            'type' => $tool?->getType() ?? self::TEST_TOOL_TYPE,
            'provider' => $tool?->getProvider() ?? self::PROVIDERS[0],
            'webhookUrl' => $tool?->getWebhookUrl() ?? '',
            'authType' => $tool?->getAuthType() ?? 'none',
            'bearerToken' => '',
            'timeoutSeconds' => (string) ($tool?->getTimeoutSeconds() ?? 5),
            'isActive' => $tool?->isActive() ?? true,
            'config' => $this->configToTextarea($tool?->getConfig() ?? []),
        ];
    }

    /**
     * @return array{name: string, tenantId: string, type: string, provider: string, webhookUrl: string, authType: string, bearerToken: string, timeoutSeconds: string, isActive: bool, config: string}
     */
    private function toolFormValuesFromRequest(Request $request): array
    {
        return [
            'name' => trim((string) $request->request->get('name', '')),
            'tenantId' => trim((string) $request->request->get('tenantId', '')),
            'type' => trim((string) $request->request->get('type', self::TEST_TOOL_TYPE)),
            'provider' => trim((string) $request->request->get('provider', self::PROVIDERS[0])),
            'webhookUrl' => trim((string) $request->request->get('webhookUrl', '')),
            'authType' => trim((string) $request->request->get('authType', 'none')),
            'bearerToken' => trim((string) $request->request->get('bearerToken', '')),
            'timeoutSeconds' => trim((string) $request->request->get('timeoutSeconds', '5')),
            'isActive' => $request->request->has('isActive'),
            'config' => trim((string) $request->request->get('config', '{}')),
        ];
    }

    /**
     * @param array{name: string, tenantId: string, type: string, provider: string, webhookUrl: string, authType: string, bearerToken: string, timeoutSeconds: string, isActive: bool, config: string} $values
     *
     * @return list<string>
     */
    private function validateToolForm(array $values, ?ExternalTool $tool = null): array
    {
        $errors = [];

        if ($values['tenantId'] === '') {
            $errors[] = 'El tenant es obligatorio.';
        } elseif (!$this->tenants->find($values['tenantId']) instanceof Tenant) {
            $errors[] = 'El tenant seleccionado no existe.';
        }

        if ($values['name'] === '') {
            $errors[] = 'El nombre es obligatorio.';
        }

        if (!in_array($values['type'], self::TOOL_TYPES, true)) {
            $errors[] = 'El tipo de herramienta no es válido.';
        }

        if (!in_array($values['provider'], self::PROVIDERS, true)) {
            $errors[] = 'El proveedor no es válido.';
        }

        if ($values['provider'] === 'n8n_webhook' && $values['webhookUrl'] === '') {
            $errors[] = 'La URL del webhook es obligatoria para n8n_webhook.';
        }

        if ($values['webhookUrl'] !== '' && !$this->isValidHttpUrl($values['webhookUrl'])) {
            $errors[] = 'La URL del webhook debe ser válida y usar http o https.';
        }

        if (!in_array($values['authType'], self::AUTH_TYPES, true)) {
            $errors[] = 'El tipo de autenticación no es válido.';
        }

        if (filter_var($values['timeoutSeconds'], FILTER_VALIDATE_INT) === false) {
            $errors[] = 'El timeout debe ser un número entero.';
        } else {
            $timeout = (int) $values['timeoutSeconds'];
            if ($timeout < 1 || $timeout > 30) {
                $errors[] = 'El timeout debe estar entre 1 y 30 segundos.';
            }
        }

        if ($values['config'] !== '') {
            $decoded = json_decode($values['config'], true);
            if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
                $errors[] = 'El JSON de configuración no es válido.';
            }
        }

        if ($values['authType'] === 'bearer' && $tool === null && $values['bearerToken'] === '') {
            // Permitido por ahora: se muestra la advertencia en la UI.
        }

        return $errors;
    }

    /**
     * @param array{name: string, tenantId: string, type: string, provider: string, webhookUrl: string, authType: string, bearerToken: string, timeoutSeconds: string, isActive: bool, config: string} $values
     */
    private function applyToolFormValues(ExternalTool $tool, array $values, bool $isNew, ?Tenant $tenant = null): void
    {
        if ($tenant instanceof Tenant) {
            $tool->setTenant($tenant);
        }

        $tool->setName($values['name']);
        $tool->setType($values['type']);
        $tool->setProvider($values['provider']);
        $tool->setWebhookUrl($values['webhookUrl'] !== '' ? $values['webhookUrl'] : null);
        $tool->setAuthType($values['authType'] !== 'none' ? $values['authType'] : null);
        $tool->setTimeoutSeconds((int) $values['timeoutSeconds']);
        $tool->setActive($values['isActive']);
        $tool->setConfig($this->decodeConfig($values['config']));
        $this->applyBearerToken($tool, $values['authType'], $values['bearerToken'], $isNew);
    }

    private function applyBearerToken(ExternalTool $tool, string $authType, string $rawBearerToken, bool $isNew): void
    {
        if ($authType !== 'bearer') {
            $tool->setBearerToken(null);

            return;
        }

        if ($rawBearerToken !== '') {
            $tool->setBearerToken($this->cipher->encrypt($rawBearerToken));

            return;
        }

        if ($isNew || $tool->getBearerToken() === null) {
            $tool->setBearerToken(null);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeConfig(string $rawConfig): array
    {
        $trimmed = trim($rawConfig);
        if ($trimmed === '') {
            return [];
        }

        $decoded = json_decode($trimmed, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function configToTextarea(array $config): string
    {
        if ($config === []) {
            return '{}';
        }

        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($json) && $json !== '' ? $json : '{}';
    }

    private function configSummary(array $config): string
    {
        if ($config === []) {
            return '{}';
        }

        $json = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || $json === '') {
            return '{}';
        }

        return mb_strlen($json) > 120 ? mb_substr($json, 0, 117).'…' : $json;
    }

    private function renderToolFormPage(
        string $pageTitle,
        string $pageSubtitle,
        string $heroTitle,
        string $submitLabel,
        string $actionUrl,
        array $values,
        array $errors,
        bool $isEdit,
        ?ExternalTool $tool
    ): Response {
        return $this->render('backend/external_tools/form.html.twig', [
            'page_title' => $pageTitle,
            'page_subtitle' => $pageSubtitle,
            'active_nav' => 'admin-external-tools',
            'tenants' => $this->tenantOptions(),
            'values' => $values,
            'errors' => $errors,
            'hero_title' => $heroTitle,
            'submit_label' => $submitLabel,
            'action_url' => $actionUrl,
            'is_edit' => $isEdit,
            'tool_id' => $tool?->getId()->toRfc4122(),
            'has_token' => $tool?->getBearerToken() !== null && $tool->getBearerToken() !== '',
            'form_token' => $this->externalToolTokenValue($isEdit && $tool instanceof ExternalTool ? 'external_tool_form_'.$tool->getId()->toRfc4122() : 'external_tool_form_create'),
            ...$this->templateUserDefaults(),
        ]);
    }

    /**
     * @return array{current_user_display_name: string, current_user_initials: string}
     */
    private function templateUserDefaults(): array
    {
        return [
            'current_user_display_name' => 'Usuario',
            'current_user_initials' => 'SA',
        ];
    }

    private function isValidHttpUrl(string $value): bool
    {
        $parts = parse_url($value);
        if (!is_array($parts) || ($parts['scheme'] ?? '') === '' || ($parts['host'] ?? '') === '') {
            return false;
        }

        return in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true);
    }

    private function isValidExternalToolToken(string $id, string $value): bool
    {
        if ($this->csrfTokenManager === null) {
            return true;
        }

        return $this->csrfTokenManager->isTokenValid(new CsrfToken($id, $value));
    }

    private function externalToolTokenValue(string $id): string
    {
        if ($this->csrfTokenManager === null) {
            return '';
        }

        return $this->csrfTokenManager->getToken($id)->getValue();
    }

    private function backendExternalToolsIndexUrl(?string $tenantId = null): string
    {
        $query = $tenantId !== null && trim($tenantId) !== '' ? '?tenant_id='.rawurlencode(trim($tenantId)) : '';

        return '/backend/external-tools'.$query;
    }

    /**
     * @return array<string, mixed>
     */
    private function runContactContextTest(ExternalTool $tool): array
    {
        $payload = [
            'tool_type' => self::TEST_TOOL_TYPE,
            'tenant_id' => $tool->getTenant()->getId()->toRfc4122(),
            'tenant_slug' => $tool->getTenant()->getSlug(),
            'channel' => 'whatsapp',
            'external_channel_id' => 'test',
            'contact' => [
                'wa_id' => 'test',
                'phone' => 'test',
                'name' => 'Cliente Test',
            ],
            'conversation' => [
                'id' => null,
                'last_messages' => [],
            ],
            'message' => [
                'text' => 'Hola, vengo por información.',
                'external_message_id' => 'test',
            ],
        ];

        if ($tool->getProvider() !== 'n8n_webhook' || $tool->getWebhookUrl() === null || $tool->getWebhookUrl() === '') {
            return [
                'ok' => false,
                'found' => false,
                'provider' => $tool->getProvider(),
                'status_code' => null,
                'latency_ms' => 0,
                'error_code' => 'unsupported_provider',
                'summary' => 'La herramienta no usa el proveedor n8n_webhook o no tiene webhook configurado.',
            ];
        }

        $headers = [];
        if ($tool->getAuthType() === 'bearer' && $tool->getBearerToken() !== null) {
            try {
                $headers['Authorization'] = 'Bearer '.$this->cipher->decrypt($tool->getBearerToken());
            } catch (\Throwable) {
                $headers['Authorization'] = 'Bearer '.$tool->getBearerToken();
            }
        }

        $startedAt = microtime(true);
        try {
            $response = $this->httpClient->request('POST', $tool->getWebhookUrl(), [
                'headers' => $headers,
                'json' => $payload,
                'timeout' => max(1, min(30, $tool->getTimeoutSeconds())),
            ]);

            $statusCode = $response->getStatusCode();
            $body = $response->getContent(false);
            $decoded = json_decode($body, true);
            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

            if (!is_array($decoded)) {
                return [
                    'ok' => false,
                    'found' => false,
                    'provider' => $tool->getProvider(),
                    'status_code' => $statusCode,
                    'latency_ms' => $latencyMs,
                    'error_code' => 'invalid_response',
                    'summary' => 'La respuesta no es JSON válido.',
                ];
            }

            if (!($decoded['ok'] ?? false)) {
                return [
                    'ok' => false,
                    'found' => false,
                    'provider' => $tool->getProvider(),
                    'status_code' => $statusCode,
                    'latency_ms' => $latencyMs,
                    'error_code' => is_string($decoded['error_code'] ?? null) ? $decoded['error_code'] : 'tool_error',
                    'summary' => is_string($decoded['summary'] ?? null) ? $decoded['summary'] : 'La herramienta respondió con error.',
                ];
            }

            return [
                'ok' => true,
                'found' => (bool) ($decoded['found'] ?? false),
                'provider' => $tool->getProvider(),
                'status_code' => $statusCode,
                'latency_ms' => $latencyMs,
                'error_code' => null,
                'summary' => is_string($decoded['summary'] ?? null)
                    ? $decoded['summary']
                    : ($decoded['data']['summary'] ?? 'La herramienta respondió correctamente.'),
                'data' => $decoded,
            ];
        } catch (\Throwable $exception) {
            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

            return [
                'ok' => false,
                'found' => false,
                'provider' => $tool->getProvider(),
                'status_code' => null,
                'latency_ms' => $latencyMs,
                'error_code' => $exception instanceof \Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface ? 'timeout' : 'unexpected_error',
                'summary' => 'No se pudo ejecutar la prueba de la herramienta.',
            ];
        }
    }
}
