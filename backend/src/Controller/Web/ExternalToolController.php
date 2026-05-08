<?php

namespace App\Controller\Web;

use App\Entity\ExternalTool;
use App\Entity\Tenant;
use App\Repository\ExternalToolRepository;
use App\Repository\TenantRepository;
use App\Service\RuntimeSettingCipher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
    private const SALES_AGENT_API_BASE_URL = 'http://sales-agent-api:8000';
    private const SALES_AGENT_API_RESPOND_PATH = '/agent/respond';
    private const TOOL_TYPES = ['contact_context', 'mcp_remote'];
    private const PROVIDERS = ['n8n_webhook', 'openai_remote_mcp', 'mcp_remote'];
    private const AUTH_TYPES = ['none', 'bearer'];
    private const TEST_TOOL_TYPE = 'contact_context';
    private const MCP_TOOL_TYPE = 'mcp_remote';
    private const MCP_PROVIDER = 'openai_remote_mcp';
    private const MCP_PROVIDER_ALTERNATE = 'mcp_remote';
    private const MCP_TEST_MESSAGE = 'Busca el contexto del contacto con teléfono +34600000000 usando la herramienta contact_context_mock disponible.';

    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantRepository $tenants,
        private readonly ExternalToolRepository $externalTools,
        private readonly RuntimeSettingCipher $cipher,
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(string:SALES_AGENT_BEARER_TOKEN)%')]
        private readonly string $salesAgentBearerToken,
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
            'page_title' => 'Servidores MCP',
            'page_subtitle' => 'Configuración de servidores MCP remotos por tenant.',
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
            'Servidores MCP',
            'Define el servidor MCP remoto que el runtime usará como tool nativa.',
            'Crear servidor MCP',
            'Guardar servidor MCP',
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
            'Servidores MCP',
            'Ajusta el servidor remoto, seguridad y parámetros de la herramienta.',
            'Editar servidor MCP',
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

        $testResult = $this->runMcpTest($tool);

        return $this->render('backend/external_tools/index.html.twig', [
            'page_title' => 'Servidores MCP',
            'page_subtitle' => 'Configuración de servidores MCP remotos por tenant.',
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
        $config = $tool->getConfig();
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
            'configText' => $this->configToTextarea($config),
            'configSummary' => $this->configSummary($config, $tool),
            'canTest' => $this->canTestTool($tool),
            'testToken' => $this->externalToolTokenValue('external_tool_test_'.$tool->getId()->toRfc4122()),
            'toggleToken' => $this->externalToolTokenValue('external_tool_toggle_'.$tool->getId()->toRfc4122()),
            'deleteToken' => $this->externalToolTokenValue('external_tool_delete_'.$tool->getId()->toRfc4122()),
        ];
    }

    /**
     * @return array{name: string, tenantId: string, type: string, provider: string, webhookUrl: string, authType: string, bearerToken: string, timeoutSeconds: string, isActive: bool, config: string, serverLabel: string, allowedTools: string, requireApproval: string, enabledForLlm: bool, notes: string}
     */
    private function toolFormDefaults(?ExternalTool $tool = null): array
    {
        $config = $tool?->getConfig() ?? [];
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
            'config' => $this->configToTextarea($config),
            'serverLabel' => $this->configStringField($config, 'server_label'),
            'allowedTools' => $this->configAllowedToolsField($config),
            'requireApproval' => $this->configStringField($config, 'require_approval', 'auto'),
            'enabledForLlm' => $this->configBoolField($config, 'enabled_for_llm', $tool?->getType() === self::MCP_TOOL_TYPE),
            'notes' => $this->configStringField($config, 'notes'),
        ];
    }

    /**
     * @return array{name: string, tenantId: string, type: string, provider: string, webhookUrl: string, authType: string, bearerToken: string, timeoutSeconds: string, isActive: bool, config: string, serverLabel: string, allowedTools: string, requireApproval: string, enabledForLlm: bool, notes: string}
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
            'serverLabel' => trim((string) $request->request->get('serverLabel', '')),
            'allowedTools' => trim((string) $request->request->get('allowedTools', '')),
            'requireApproval' => trim((string) $request->request->get('requireApproval', 'auto')),
            'enabledForLlm' => $request->request->has('enabledForLlm'),
            'notes' => trim((string) $request->request->get('notes', '')),
        ];
    }

    /**
     * @param array{name: string, tenantId: string, type: string, provider: string, webhookUrl: string, authType: string, bearerToken: string, timeoutSeconds: string, isActive: bool, config: string, serverLabel: string, allowedTools: string, requireApproval: string, enabledForLlm: bool, notes: string} $values
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

        if ($values['type'] === self::TEST_TOOL_TYPE && $values['provider'] !== 'n8n_webhook') {
            $errors[] = 'contact_context es legacy y sólo mantiene compatibilidad con n8n_webhook.';
        }

        if ($values['type'] === self::MCP_TOOL_TYPE && !in_array($values['provider'], [self::MCP_PROVIDER, self::MCP_PROVIDER_ALTERNATE], true)) {
            $errors[] = 'mcp_remote sólo puede usar un proveedor MCP remoto.';
        }

        if ($values['webhookUrl'] === '') {
            $errors[] = 'La URL del webhook es obligatoria.';
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

        if ($values['type'] === self::MCP_TOOL_TYPE) {
            if ($values['serverLabel'] === '') {
                $errors[] = 'El server label es obligatorio para MCP remoto.';
            }

            if (!in_array($values['requireApproval'], ['auto', 'never', 'always'], true)) {
                $errors[] = 'El modo de aprobación MCP no es válido.';
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
        $tool->setConfig($this->buildConfig($values));
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
     * @param array{name: string, tenantId: string, type: string, provider: string, webhookUrl: string, authType: string, bearerToken: string, timeoutSeconds: string, isActive: bool, config: string, serverLabel: string, allowedTools: string, requireApproval: string, enabledForLlm: bool, notes: string} $values
     *
     * @return array<string, mixed>
     */
    private function buildConfig(array $values): array
    {
        $config = $this->decodeConfig($values['config']);

        if ($values['type'] === self::MCP_TOOL_TYPE) {
            $config['server_label'] = $values['serverLabel'];
            $config['allowed_tools'] = $this->parseAllowedTools($values['allowedTools']);
            $config['require_approval'] = $values['requireApproval'] !== '' ? $values['requireApproval'] : 'auto';
            $config['enabled_for_llm'] = $values['enabledForLlm'];
            if ($values['notes'] !== '') {
                $config['notes'] = $values['notes'];
            } else {
                unset($config['notes']);
            }
        } else {
            unset($config['server_label'], $config['allowed_tools'], $config['require_approval'], $config['enabled_for_llm'], $config['notes']);
        }

        return $config;
    }

    private function configStringField(array $config, string $key, string $default = ''): string
    {
        $value = $config[$key] ?? $default;
        if (!is_string($value)) {
            return $default;
        }

        return trim($value);
    }

    private function configBoolField(array $config, string $key, bool $default = false): bool
    {
        if (!array_key_exists($key, $config)) {
            return $default;
        }

        return (bool) $config[$key];
    }

    private function configAllowedToolsField(array $config): string
    {
        $value = $config['allowed_tools'] ?? [];
        if (!is_array($value)) {
            return '';
        }

        $tools = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $tools[] = trim($item);
            }
        }

        return implode("\n", array_values(array_unique($tools)));
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

    /**
     * @return list<string>
     */
    private function parseAllowedTools(string $rawAllowedTools): array
    {
        $rawAllowedTools = trim($rawAllowedTools);
        if ($rawAllowedTools === '') {
            return [];
        }

        $parts = preg_split('/[\r\n,]+/', $rawAllowedTools) ?: [];
        $tools = [];
        foreach ($parts as $part) {
            $tool = trim($part);
            if ($tool !== '') {
                $tools[] = $tool;
            }
        }

        return array_values(array_unique($tools));
    }

    private function configToTextarea(array $config): string
    {
        if ($config === []) {
            return '{}';
        }

        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($json) && $json !== '' ? $json : '{}';
    }

    private function configSummary(array $config, ?ExternalTool $tool = null): string
    {
        if ($config === []) {
            return '{}';
        }

        if ($tool instanceof ExternalTool && $tool->getType() === self::MCP_TOOL_TYPE) {
            $parts = [];
            $serverLabel = $tool->getServerLabel();
            if ($serverLabel !== null) {
                $parts[] = 'server: '.$serverLabel;
            }

            $allowedTools = $tool->getAllowedTools();
            if ($allowedTools !== []) {
                $parts[] = 'tools: '.implode(', ', array_slice($allowedTools, 0, 3)).(count($allowedTools) > 3 ? '…' : '');
            }

            $requireApproval = $tool->getRequireApproval();
            if ($requireApproval !== null) {
                $parts[] = 'approval: '.$requireApproval;
            }

            if ($parts !== []) {
                return implode(' | ', $parts);
            }
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
            'can_test' => $tool === null ? false : $this->canTestTool($tool),
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

    private function canTestTool(ExternalTool $tool): bool
    {
        return $tool->getType() === self::MCP_TOOL_TYPE && $tool->isEnabledForLlm();
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function normalizeList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $items[] = trim($item);
            }
        }

        return array_values(array_unique($items));
    }

    /**
     * @param mixed $value
     * @return list<array{type: string, tool_name: string, status: string, output: mixed}>
     */
    private function normalizeToolTraces(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $traces = [];
        foreach ($value as $trace) {
            if (!is_array($trace)) {
                continue;
            }

            $traces[] = [
                'type' => self::cleanString($trace['type'] ?? null),
                'tool_name' => self::cleanString($trace['tool_name'] ?? $trace['toolName'] ?? null),
                'status' => self::cleanString($trace['status'] ?? null),
                'output' => $trace['output'] ?? null,
            ];
        }

        return $traces;
    }

    private static function cleanString(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        return trim($value);
    }

    /**
     * @return array<string, mixed>
     */
    private function runMcpTest(ExternalTool $tool): array
    {
        $payload = [
            'tenant_id' => $tool->getTenant()->getId()->toRfc4122(),
            'channel_type' => 'whatsapp',
            'external_channel_id' => 'ui-mcp-test',
            'contact' => [
                'wa_id' => '+34600000000',
                'phone' => '+34600000000',
                'name' => 'Cliente Demo',
            ],
            'conversation' => [
                'external_id' => 'ui-mcp-test-'.$tool->getId()->toRfc4122().'-'.time(),
                'last_messages' => [],
            ],
            'message' => [
                'text' => self::MCP_TEST_MESSAGE,
            ],
            'raw_event' => [
                'source' => 'ui_mcp_test',
            ],
        ];

        if (!$this->canTestTool($tool)) {
            return [
                'ok' => false,
                'found' => false,
                'provider' => $tool->getProvider(),
                'status_code' => null,
                'latency_ms' => 0,
                'error_code' => 'provider_not_supported',
                'summary' => 'MCP remoto requiere OpenAI Responses API. Con Ollama se omite.',
                'reply' => '',
                'model' => null,
                'mcp_response_id' => null,
                'mcp_tool_traces' => [],
                'mcp_errors' => [],
            ];
        }

        $startedAt = microtime(true);
        try {
            $response = $this->httpClient->request('POST', self::SALES_AGENT_API_BASE_URL.self::SALES_AGENT_API_RESPOND_PATH, [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->salesAgentBearerToken,
                    'Accept' => 'application/json',
                ],
                'json' => $payload,
                'timeout' => 60,
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
                    'reply' => '',
                    'model' => null,
                    'mcp_response_id' => null,
                    'mcp_tool_traces' => [],
                    'mcp_errors' => [],
                ];
            }

            $dataToSave = is_array($decoded['data_to_save'] ?? null) ? $decoded['data_to_save'] : [];
            $mcpSkippedReason = is_string($dataToSave['mcp_skipped_reason'] ?? null) ? trim((string) $dataToSave['mcp_skipped_reason']) : null;
            if (($decoded['provider'] ?? null) !== 'openai' || $mcpSkippedReason === 'provider_not_supported') {
                return [
                    'ok' => false,
                    'found' => false,
                    'provider' => is_string($decoded['provider'] ?? null) ? $decoded['provider'] : $tool->getProvider(),
                    'status_code' => $statusCode,
                    'latency_ms' => $latencyMs,
                    'error_code' => 'provider_not_supported',
                    'summary' => 'MCP remoto requiere OpenAI Responses API. Con Ollama se omite.',
                    'reply' => is_string($decoded['reply'] ?? null) ? $decoded['reply'] : '',
                    'model' => is_string($decoded['model'] ?? null) ? $decoded['model'] : null,
                    'mcp_response_id' => is_string($dataToSave['mcp_response_id'] ?? null) ? $dataToSave['mcp_response_id'] : null,
                    'mcp_tool_traces' => $this->normalizeToolTraces($dataToSave['mcp_tool_traces'] ?? []),
                    'mcp_errors' => $this->normalizeList($dataToSave['mcp_errors'] ?? []),
                ];
            }

            return [
                'ok' => true,
                'found' => (bool) ($decoded['found'] ?? false),
                'provider' => is_string($decoded['provider'] ?? null) ? $decoded['provider'] : 'openai',
                'status_code' => $statusCode,
                'latency_ms' => $latencyMs,
                'error_code' => null,
                'summary' => 'La prueba MCP se ejecutó correctamente.',
                'reply' => is_string($decoded['reply'] ?? null) ? $decoded['reply'] : '',
                'model' => is_string($decoded['model'] ?? null) ? $decoded['model'] : null,
                'mcp_response_id' => is_string($dataToSave['mcp_response_id'] ?? null) ? $dataToSave['mcp_response_id'] : null,
                'mcp_tool_traces' => $this->normalizeToolTraces($dataToSave['mcp_tool_traces'] ?? []),
                'mcp_errors' => $this->normalizeList($dataToSave['mcp_errors'] ?? []),
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
                'reply' => '',
                'model' => null,
                'mcp_response_id' => null,
                'mcp_tool_traces' => [],
                'mcp_errors' => [self::cleanString($exception->getMessage())],
            ];
        }
    }
}
