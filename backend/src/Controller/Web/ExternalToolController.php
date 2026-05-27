<?php

namespace App\Controller\Web;

use App\Entity\ExternalTool;
use App\Entity\Tenant;
use App\Repository\ExternalToolRepository;
use App\Repository\TenantRepository;
use App\Service\ActiveTenantContext;
use App\Service\RuntimeSettingCipher;
use App\Service\RuntimeConfigurationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[Route('/external-tools')]
final class ExternalToolController extends AbstractController
{
    private const SALES_AGENT_API_BASE_URL = 'http://sales-agent-api:8000';
    private const SALES_AGENT_API_RESPOND_PATH = '/agent/respond';
    private const TOOL_TYPES = ['contact_context', 'mcp_remote', 'handoff_webhook'];
    private const PROVIDERS = ['n8n_webhook', 'openai_remote_mcp', 'mcp_remote'];
    private const AUTH_TYPES = ['none', 'bearer'];
    private const TEST_TOOL_TYPE = 'contact_context';
    private const MCP_TOOL_TYPE = 'mcp_remote';
    private const HANDOFF_TOOL_TYPE = 'handoff_webhook';
    private const MCP_PROVIDER = 'openai_remote_mcp';
    private const MCP_PROVIDER_ALTERNATE = 'mcp_remote';
    private const MCP_TEST_MESSAGE = 'Busca servicios amplios del catálogo usando services_search y devuelve solo 1 resultado con limit=1.';

    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantRepository $tenants,
        private readonly ExternalToolRepository $externalTools,
        private readonly RuntimeSettingCipher $cipher,
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(string:SALES_AGENT_BEARER_TOKEN)%')]
        private readonly string $salesAgentBearerToken,
        private readonly RuntimeConfigurationService $runtimeConfigurationService,
        private readonly ActiveTenantContext $activeTenantContext,
        private readonly ?CsrfTokenManagerInterface $csrfTokenManager = null,
    ) {
    }

    #[Route('', name: 'backend_external_tools_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->security->isGranted('ROLE_SUPER_ADMIN')) {
            return $this->redirect('/backend/login');
        }

        $activeTenant = $this->activeTenantContext->getActiveTenant();
        if (!$activeTenant instanceof Tenant) {
            return $this->renderTenantSelectionRequiredPage(
                'Servidores MCP',
                'Selecciona un negocio antes de gestionar servidores MCP.',
                'admin-external-tools',
                'Servidores MCP'
            );
        }

        $tools = $this->externalTools->findByTenantOrdered($activeTenant);
        $hasRuntimeDefault = $this->externalTools->findRuntimeDefaultMcpByTenant($activeTenant) instanceof ExternalTool;
        $runtimeState = $this->tenantMcpRuntimeState($activeTenant);

        return $this->render('backend/external_tools/index.html.twig', [
            'page_title' => sprintf('Servidores MCP de %s', $activeTenant->getName()),
            'page_subtitle' => 'Configuración de servidores MCP remotos del negocio activo.',
            'active_nav' => 'admin-external-tools',
            'tools' => array_map([$this, 'toolRow'], $tools),
            'filter_error' => null,
            'active_tenant_name' => $activeTenant->getName(),
            'has_runtime_default' => $hasRuntimeDefault,
            'runtime_state' => $runtimeState,
            ...$this->backendLayoutTemplateData(),
        ]);
    }

    #[Route('/new', name: 'backend_external_tools_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        if (!$this->security->isGranted('ROLE_SUPER_ADMIN')) {
            return $this->redirect('/backend/login');
        }

        $activeTenant = $this->activeTenantContext->getActiveTenant();
        if (!$activeTenant instanceof Tenant) {
            return $this->renderTenantSelectionRequiredPage(
                'Crear servidor MCP',
                'Selecciona un negocio antes de crear un servidor MCP remoto.',
                'admin-external-tools',
                'Servidores MCP'
            );
        }

        $values = $this->toolFormDefaults(null, $activeTenant);
        $errors = [];

        if ($request->isMethod('POST')) {
            $values = $this->toolFormValuesFromRequest($request);

            if (!$this->isValidExternalToolToken('external_tool_form_create', (string) $request->request->get('_csrf_token'))) {
                $errors[] = 'La sesión del formulario ha expirado. Vuelve a intentarlo.';
            } else {
                $errors = $this->validateToolForm($values);
                if ($errors === []) {
                    $tool = new ExternalTool($activeTenant, $values['name'], $values['type'], $values['provider']);
                    $this->applyToolFormValues($tool, $values, true, $activeTenant);
                    $this->entityManager->persist($tool);
                    $this->entityManager->flush();

                    return new RedirectResponse($this->backendExternalToolsIndexUrl($activeTenant->getId()->toRfc4122()));
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
            null,
            $activeTenant
        );
    }

    #[Route('/{id}/edit', name: 'backend_external_tools_edit', methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request): Response
    {
        if (!$this->security->isGranted('ROLE_SUPER_ADMIN')) {
            return $this->redirect('/backend/login');
        }

        $activeTenant = $this->activeTenantContext->getActiveTenant();
        if (!$activeTenant instanceof Tenant) {
            return $this->renderTenantSelectionRequiredPage(
                'Editar servidor MCP',
                'Selecciona un negocio antes de editar un servidor MCP remoto.',
                'admin-external-tools',
                'Servidores MCP'
            );
        }

        $tool = $this->externalTools->find($id);
        if (!$tool instanceof ExternalTool || $tool->getTenant()->getId()->toRfc4122() !== $activeTenant->getId()->toRfc4122()) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $values = $this->toolFormDefaults($tool, $activeTenant);
        $errors = [];

        if ($request->isMethod('POST')) {
            $values = $this->toolFormValuesFromRequest($request);

            if (!$this->isValidExternalToolToken('external_tool_form_'.$tool->getId()->toRfc4122(), (string) $request->request->get('_csrf_token'))) {
                $errors[] = 'La sesión del formulario ha expirado. Vuelve a intentarlo.';
            } else {
                $errors = $this->validateToolForm($values, $tool);
                if ($errors === []) {
                    $this->applyToolFormValues($tool, $values, false, $activeTenant);
                    $this->entityManager->persist($tool);
                    $this->entityManager->flush();

                    return new RedirectResponse($this->backendExternalToolsIndexUrl($activeTenant->getId()->toRfc4122()));
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
            $tool,
            $activeTenant
        );
    }

    #[Route('/{id}/toggle', name: 'backend_external_tools_toggle', methods: ['POST'])]
    public function toggle(string $id, Request $request): Response
    {
        if (!$this->security->isGranted('ROLE_SUPER_ADMIN')) {
            return $this->redirect('/backend/login');
        }

        $activeTenant = $this->activeTenantContext->getActiveTenant();
        $tool = $this->externalTools->find($id);
        if (!$tool instanceof ExternalTool || !$activeTenant instanceof Tenant || $tool->getTenant()->getId()->toRfc4122() !== $activeTenant->getId()->toRfc4122()) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        if (!$this->isValidExternalToolToken('external_tool_toggle_'.$tool->getId()->toRfc4122(), (string) $request->request->get('_csrf_token'))) {
            return $this->redirectToRoute('backend_external_tools_index', ['tenant_id' => $tool->getTenant()->getId()->toRfc4122()]);
        }

        $wasRuntimeDefault = $tool->isRuntimeDefault();
        $tool->setActive(!$tool->isActive());
        if (!$tool->isActive() && $wasRuntimeDefault) {
            $tool->setRuntimeDefault(false);
        }
        $this->entityManager->persist($tool);
        $this->entityManager->flush();

        $this->addFlash('success', $tool->isActive() ? 'Herramienta externa activada.' : 'Herramienta externa desactivada.');

        return new RedirectResponse($this->backendExternalToolsIndexUrl($tool->getTenant()->getId()->toRfc4122()));
    }

    #[Route('/{id}/mark-default', name: 'backend_external_tools_mark_default', methods: ['POST'])]
    public function markDefault(string $id, Request $request): Response
    {
        if (!$this->security->isGranted('ROLE_SUPER_ADMIN')) {
            return $this->redirect('/backend/login');
        }

        $activeTenant = $this->activeTenantContext->getActiveTenant();
        $tool = $this->externalTools->find($id);
        if (!$tool instanceof ExternalTool || !$activeTenant instanceof Tenant || $tool->getTenant()->getId()->toRfc4122() !== $activeTenant->getId()->toRfc4122()) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        if ($tool->getType() !== self::MCP_TOOL_TYPE) {
            return new Response('', Response::HTTP_BAD_REQUEST);
        }

        if (!$this->isValidExternalToolToken('external_tool_runtime_default_'.$tool->getId()->toRfc4122(), (string) $request->request->get('_csrf_token'))) {
            return new RedirectResponse($this->backendExternalToolsIndexUrl($tool->getTenant()->getId()->toRfc4122()));
        }

        if (!$tool->isActive()) {
            $this->addFlash('error', 'El MCP debe estar activo para marcarlo como principal.');

            return new RedirectResponse($this->backendExternalToolsIndexUrl($tool->getTenant()->getId()->toRfc4122()));
        }

        if (!$tool->isRuntimeDefault()) {
            $this->externalTools->unsetRuntimeDefaultForTenant($tool->getTenant(), $tool);
            $tool->setRuntimeDefault(true);
            $this->entityManager->persist($tool);
            $this->entityManager->flush();
            $this->addFlash('success', 'Servidor MCP marcado como principal.');
        }

        return new RedirectResponse($this->backendExternalToolsIndexUrl($tool->getTenant()->getId()->toRfc4122()));
    }

    #[Route('/{id}/delete', name: 'backend_external_tools_delete', methods: ['POST'])]
    public function delete(string $id, Request $request): Response
    {
        if (!$this->security->isGranted('ROLE_SUPER_ADMIN')) {
            return $this->redirect('/backend/login');
        }

        $activeTenant = $this->activeTenantContext->getActiveTenant();
        $tool = $this->externalTools->find($id);
        if (!$tool instanceof ExternalTool || !$activeTenant instanceof Tenant || $tool->getTenant()->getId()->toRfc4122() !== $activeTenant->getId()->toRfc4122()) {
            return new Response('', Response::HTTP_NOT_FOUND);
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
        if (!$this->security->isGranted('ROLE_SUPER_ADMIN')) {
            return $this->redirect('/backend/login');
        }

        $activeTenant = $this->activeTenantContext->getActiveTenant();
        $tool = $this->externalTools->find($id);
        if (!$tool instanceof ExternalTool || !$activeTenant instanceof Tenant || $tool->getTenant()->getId()->toRfc4122() !== $activeTenant->getId()->toRfc4122()) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        if (!$this->isValidExternalToolToken('external_tool_test_'.$tool->getId()->toRfc4122(), (string) $request->request->get('_csrf_token'))) {
            return new RedirectResponse($this->backendExternalToolsIndexUrl($tool->getTenant()->getId()->toRfc4122()));
        }

        $testResult = $this->runMcpTest($tool);

        return $this->render('backend/external_tools/index.html.twig', [
            'page_title' => sprintf('Servidores MCP de %s', $activeTenant->getName()),
            'page_subtitle' => 'Configuración de servidores MCP remotos del negocio activo.',
            'active_nav' => 'admin-external-tools',
            'tools' => array_map([$this, 'toolRow'], $this->externalTools->findByTenantOrdered($activeTenant)),
            'has_runtime_default' => $this->externalTools->findRuntimeDefaultMcpByTenant($activeTenant) instanceof ExternalTool,
            'runtime_state' => $this->tenantMcpRuntimeState($activeTenant),
            'test_result' => $testResult,
            'active_tenant_name' => $activeTenant->getName(),
            ...$this->backendLayoutTemplateData(),
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
     * @return array{id: string, tenantId: string, tenantName: string, name: string, type: string, provider: string, webhookUrl: string, authType: string, hasBearerToken: bool, timeoutSeconds: int, isActive: bool, isRuntimeDefault: bool, configText: string, configSummary: string}
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
            'hasBearerToken' => $tool->hasDownstreamAuthorizationToken(),
            'timeoutSeconds' => $tool->getTimeoutSeconds(),
            'isActive' => $tool->isActive(),
            'isRuntimeDefault' => $tool->isRuntimeDefault(),
            'configText' => $this->configToTextarea($config),
            'configSummary' => $this->configSummary($config, $tool),
            'canTest' => $this->canTestTool($tool),
            'testToken' => $this->externalToolTokenValue('external_tool_test_'.$tool->getId()->toRfc4122()),
            'toggleToken' => $this->externalToolTokenValue('external_tool_toggle_'.$tool->getId()->toRfc4122()),
            'runtimeDefaultToken' => $this->externalToolTokenValue('external_tool_runtime_default_'.$tool->getId()->toRfc4122()),
            'deleteToken' => $this->externalToolTokenValue('external_tool_delete_'.$tool->getId()->toRfc4122()),
        ];
    }

    /**
     * @return array{name: string, tenantId: string, type: string, provider: string, webhookUrl: string, authType: string, bearerToken: string, clearBearerToken: bool, timeoutSeconds: string, isActive: bool, isRuntimeDefault: bool, config: string, serverLabel: string, allowedTools: string, requireApproval: string, enabledForLlm: bool, notes: string}
     */
    private function toolFormDefaults(?ExternalTool $tool = null, ?Tenant $tenant = null): array
    {
        $config = $tool?->getConfig() ?? [];
        $tenantRuntimeDefault = $tenant instanceof Tenant ? $this->externalTools->findRuntimeDefaultMcpByTenant($tenant) : null;
        $type = $tool?->getType() ?? self::MCP_TOOL_TYPE;
        $authType = $tool?->getAuthType() ?? ($tool?->hasDownstreamAuthorizationToken() ? 'bearer' : 'none');
        if ($type === self::HANDOFF_TOOL_TYPE) {
            $authType = 'none';
        }
        return [
            'name' => $tool?->getName() ?? '',
            'tenantId' => $tenant?->getId()->toRfc4122() ?? $tool?->getTenant()?->getId()->toRfc4122() ?? '',
            'type' => $type,
            'provider' => $tool?->getProvider() ?? ($type === self::MCP_TOOL_TYPE ? self::MCP_PROVIDER : 'n8n_webhook'),
            'webhookUrl' => $tool?->getWebhookUrl() ?? '',
            'authType' => $authType,
            'bearerToken' => '',
            'clearBearerToken' => false,
            'timeoutSeconds' => (string) ($tool?->getTimeoutSeconds() ?? 5),
            'isActive' => $tool?->isActive() ?? true,
            'isRuntimeDefault' => $tool?->isRuntimeDefault() ?? ($tool === null && $tenantRuntimeDefault === null),
            'config' => $this->configToTextarea($config),
            'serverLabel' => $this->configStringField($config, 'server_label'),
            'allowedTools' => $this->configAllowedToolsField($config),
            'requireApproval' => $this->configStringField($config, 'require_approval', 'auto'),
            'enabledForLlm' => $this->configBoolField($config, 'enabled_for_llm', $tool?->getType() === self::MCP_TOOL_TYPE),
            'notes' => $this->configStringField($config, 'notes'),
        ];
    }

    /**
     * @return array{name: string, tenantId: string, type: string, provider: string, webhookUrl: string, authType: string, bearerToken: string, clearBearerToken: bool, timeoutSeconds: string, isActive: bool, isRuntimeDefault: bool, config: string, serverLabel: string, allowedTools: string, requireApproval: string, enabledForLlm: bool, notes: string}
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
            'clearBearerToken' => $request->request->has('clearBearerToken'),
            'timeoutSeconds' => trim((string) $request->request->get('timeoutSeconds', '5')),
            'isActive' => $request->request->has('isActive'),
            'isRuntimeDefault' => $request->request->has('isRuntimeDefault'),
            'config' => trim((string) $request->request->get('config', '{}')),
            'serverLabel' => trim((string) $request->request->get('serverLabel', '')),
            'allowedTools' => trim((string) $request->request->get('allowedTools', '')),
            'requireApproval' => trim((string) $request->request->get('requireApproval', 'auto')),
            'enabledForLlm' => $request->request->has('enabledForLlm'),
            'notes' => trim((string) $request->request->get('notes', '')),
        ];
    }

    /**
     * @param array{name: string, tenantId: string, type: string, provider: string, webhookUrl: string, authType: string, bearerToken: string, clearBearerToken: bool, timeoutSeconds: string, isActive: bool, isRuntimeDefault: bool, config: string, serverLabel: string, allowedTools: string, requireApproval: string, enabledForLlm: bool, notes: string} $values
     *
     * @return list<string>
     */
    private function validateToolForm(array $values, ?ExternalTool $tool = null): array
    {
        $errors = [];

        if ($values['name'] === '') {
            $errors[] = 'El nombre es obligatorio.';
        }

        if (!in_array($values['type'], self::TOOL_TYPES, true)) {
            $errors[] = 'El tipo de herramienta no es válido.';
        }

        if (!in_array($values['provider'], self::PROVIDERS, true)) {
            $errors[] = 'El proveedor no es válido.';
        }

        if (in_array($values['type'], [self::TEST_TOOL_TYPE, self::HANDOFF_TOOL_TYPE], true) && $values['provider'] !== 'n8n_webhook') {
            $errors[] = 'contact_context/handoff_webhook sólo mantienen compatibilidad con n8n_webhook.';
        }

        if ($values['type'] === self::MCP_TOOL_TYPE && !in_array($values['provider'], [self::MCP_PROVIDER, self::MCP_PROVIDER_ALTERNATE], true)) {
            $errors[] = 'mcp_remote sólo puede usar un proveedor MCP remoto.';
        }

        if ($values['type'] === self::HANDOFF_TOOL_TYPE && $values['authType'] !== 'none') {
            $errors[] = 'handoff_webhook no usa autorización bearer todavía.';
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

            if ($values['isRuntimeDefault'] && !$values['isActive']) {
                $errors[] = 'El MCP principal debe estar activo.';
            }
        }

        if ($values['type'] === self::HANDOFF_TOOL_TYPE) {
            if ($values['provider'] !== 'n8n_webhook') {
                $errors[] = 'handoff_webhook sólo puede usar el proveedor n8n_webhook.';
            }

            if ($values['authType'] !== 'none') {
                $errors[] = 'handoff_webhook no admite bearer token en esta primera fase.';
            }

            if ($values['bearerToken'] !== '') {
                $errors[] = 'handoff_webhook no admite bearer token.';
            }
        }

        if ($values['authType'] === 'bearer' && $tool === null && $values['bearerToken'] === '') {
            // Permitido por ahora: se muestra la advertencia en la UI.
        }

        return $errors;
    }

    /**
     * @param array{name: string, tenantId: string, type: string, provider: string, webhookUrl: string, authType: string, bearerToken: string, clearBearerToken: bool, timeoutSeconds: string, isActive: bool, isRuntimeDefault: bool, config: string} $values
     */
    private function applyToolFormValues(ExternalTool $tool, array $values, bool $isNew, Tenant $tenant): void
    {
        $tool->setTenant($tenant);

        $tool->setName($values['name']);
        $tool->setType($values['type']);
        $tool->setProvider($values['provider']);
        $tool->setWebhookUrl($values['webhookUrl'] !== '' ? $values['webhookUrl'] : null);
        $effectiveAuthType = $values['authType'];
        if ($values['type'] === self::HANDOFF_TOOL_TYPE) {
            $effectiveAuthType = 'none';
        } elseif ($effectiveAuthType === 'none' && ($values['bearerToken'] !== '' || $tool->hasDownstreamAuthorizationToken())) {
            $effectiveAuthType = 'bearer';
        }

        $tool->setAuthType($effectiveAuthType !== 'none' ? $effectiveAuthType : null);
        $tool->setTimeoutSeconds((int) $values['timeoutSeconds']);
        $tool->setActive($values['isActive']);
        $isRuntimeDefault = $values['type'] === self::MCP_TOOL_TYPE && $values['isActive'] && $values['isRuntimeDefault'];
        if ($isRuntimeDefault) {
            $this->externalTools->unsetRuntimeDefaultForTenant($tenant, $tool);
        }
        $tool->setRuntimeDefault($isRuntimeDefault);
        $tool->setConfig($this->buildConfig($values));
        $this->applyBearerToken($tool, $effectiveAuthType, $values['bearerToken'], $values['clearBearerToken'], $isNew);
    }

    private function applyBearerToken(ExternalTool $tool, string $authType, string $rawBearerToken, bool $clearBearerToken, bool $isNew): void
    {
        if ($authType !== 'bearer') {
            $tool->setBearerToken(null);

            return;
        }

        if ($clearBearerToken) {
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
     * @param array{name: string, tenantId: string, type: string, provider: string, webhookUrl: string, authType: string, bearerToken: string, clearBearerToken: bool, timeoutSeconds: string, isActive: bool, config: string, serverLabel: string, allowedTools: string, requireApproval: string, enabledForLlm: bool, notes: string} $values
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

    /**
     * @return array{value: string, note: string, detail: string}
     */
    private function tenantMcpRuntimeState(Tenant $tenant): array
    {
        $default = $this->externalTools->findRuntimeDefaultMcpByTenant($tenant);
        if ($default instanceof ExternalTool) {
            return [
                'value' => sprintf('Principal: %s', $default->getName()),
                'note' => 'Usado por el agente',
                'detail' => sprintf('El runtime usa %s como MCP principal.', $default->getName()),
            ];
        }

        $activeCandidates = $this->externalTools->findActiveMcpCandidatesByTenant($tenant);
        if ($activeCandidates !== [] && count($activeCandidates) > 1) {
            return [
                'value' => 'Varios MCP activos sin principal',
                'note' => 'Usado por el agente',
                'detail' => 'El runtime no elegirá ninguno automáticamente hasta definir un MCP principal.',
            ];
        }

        if ($activeCandidates !== [] && count($activeCandidates) === 1 && $activeCandidates[0] instanceof ExternalTool) {
            return [
                'value' => sprintf('Usado por el agente: %s', $activeCandidates[0]->getName()),
                'note' => 'Sin principal explícito',
                'detail' => sprintf('Hay un único MCP activo: %s. Conviene marcarlo como principal.', $activeCandidates[0]->getName()),
            ];
        }

        return [
            'value' => 'MCP pendiente de configurar',
            'note' => 'Usado por el agente',
            'detail' => 'No hay ningún MCP activo para este negocio.',
        ];
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
        ?ExternalTool $tool,
        ?Tenant $activeTenant = null
    ): Response {
        return $this->render('backend/external_tools/form.html.twig', [
            'page_title' => $pageTitle,
            'page_subtitle' => $pageSubtitle,
            'active_nav' => 'admin-external-tools',
            'values' => $values,
            'errors' => $errors,
            'hero_title' => $heroTitle,
            'submit_label' => $submitLabel,
            'action_url' => $actionUrl,
            'is_edit' => $isEdit,
            'tool_id' => $tool?->getId()->toRfc4122(),
            'has_token' => $tool?->hasDownstreamAuthorizationToken() ?? false,
            'downstream_authorization_configured' => $tool?->hasDownstreamAuthorizationToken() ?? false,
            'can_test' => $tool === null ? false : $this->canTestTool($tool),
            'form_token' => $this->externalToolTokenValue($isEdit && $tool instanceof ExternalTool ? 'external_tool_form_'.$tool->getId()->toRfc4122() : 'external_tool_form_create'),
            'active_tenant_name' => $activeTenant instanceof Tenant ? $activeTenant->getName() : null,
            ...$this->backendLayoutTemplateData(),
        ]);
    }

    private function renderTenantSelectionRequiredPage(string $pageTitle, string $pageSubtitle, string $activeNav, string $sectionLabel): Response
    {
        return $this->render('backend/external_tools/index.html.twig', [
            'page_title' => $pageTitle,
            'page_subtitle' => $pageSubtitle,
            'active_nav' => $activeNav,
            'tenant_required_html' => sprintf(
                '
                <section class="hero-panel hero-panel-single">
                  <div class="hero-copy">
                    <div class="eyebrow-dark">%s</div>
                    <h2>Selecciona un negocio para continuar</h2>
                    <p>Esta sección funciona dentro de un negocio activo. Abre la lista de negocios y pulsa “Gestionar” sobre el contexto que quieras usar.</p>
                    <div class="hero-actions">
                      <a class="primary-action" href="/backend/tenants">Ir a negocios</a>
                    </div>
                  </div>
                </section>
                ',
                htmlspecialchars($sectionLabel, ENT_QUOTES, 'UTF-8')
            ),
            'tools' => [],
            'filter_error' => null,
            'active_tenant_name' => null,
            ...$this->backendLayoutTemplateData(),
        ]);
    }

    private function currentUserLabel(): string
    {
        $user = $this->security->getUser();

        return $user instanceof UserInterface ? $user->getUserIdentifier() : 'Invitado';
    }

    private function currentUserDisplayName(): string
    {
        $user = $this->currentUser();
        if ($user instanceof User && $user->getName() !== '') {
            return $user->getName();
        }

        $label = $this->currentUserLabel();
        $localPart = strtolower(strstr($label, '@', true) ?: $label);

        if (str_contains($localPart, 'federicomartin')) {
            return 'Federico Martín';
        }

        $pretty = preg_replace('/[._-]+/', ' ', $localPart) ?? $localPart;
        $pretty = preg_replace('/\d+/', '', $pretty) ?? $pretty;
        $pretty = trim(preg_replace('/\s+/', ' ', $pretty) ?? $pretty);

        return $pretty !== '' ? ucwords($pretty) : 'Usuario';
    }

    private function currentUserInitials(): string
    {
        $label = $this->currentUserDisplayName();
        $parts = preg_split('/\s+/', trim($label)) ?: [];
        $parts = array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
        $seed = implode('', array_slice($parts, 0, 2));

        if ($seed === '') {
            $seed = 'SA';
        }

        return strtoupper(substr($seed, 0, 2));
    }

    /**
     * @return array{
     *     current_user_display_name: string,
     *     current_user_initials: string,
     *     active_tenant: array{id: string, name: string, slug: string, edit_url: string}|null,
     *     can_manage_active_tenant: bool,
     *     is_super_admin: bool
     * }
     */
    private function backendLayoutTemplateData(): array
    {
        return [
            'current_user_display_name' => $this->currentUserDisplayName(),
            'current_user_initials' => $this->currentUserInitials(),
            'active_tenant' => $this->activeTenantTemplateData(),
            'can_manage_active_tenant' => $this->canManageActiveTenant(),
            'is_super_admin' => $this->isSuperAdmin(),
        ];
    }

    /**
     * @return array{id: string, name: string, slug: string, edit_url: string}|null
     */
    private function activeTenantTemplateData(): ?array
    {
        $tenant = $this->activeTenantContext->getActiveTenant();
        if (!$tenant instanceof Tenant) {
            return null;
        }

        return [
            'id' => $tenant->getId()->toRfc4122(),
            'name' => $tenant->getName(),
            'slug' => $tenant->getSlug(),
            'edit_url' => sprintf('/backend/tenants/%s/edit', rawurlencode($tenant->getId()->toRfc4122())),
        ];
    }

    private function currentUser(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }

    private function isSuperAdmin(): bool
    {
        return $this->security->isGranted('ROLE_SUPER_ADMIN');
    }

    private function canManageActiveTenant(): bool
    {
        return $this->isSuperAdmin() && $this->activeTenantContext->getActiveTenant() instanceof Tenant;
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
        $runtimeResolution = $this->resolveRuntimeLlmProviderAndModel();
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
            $provider = $runtimeResolution['provider'] ?? $this->normalizeRuntimeProvider($decoded['provider'] ?? null);
            $model = $runtimeResolution['model'] ?? $this->cleanString($decoded['model'] ?? null);

            if ($provider !== 'openai') {
                return [
                    'ok' => false,
                    'found' => false,
                    'provider' => $provider,
                    'status_code' => $statusCode,
                    'latency_ms' => $latencyMs,
                    'error_code' => 'provider_not_supported',
                    'summary' => 'MCP remoto requiere OpenAI Responses API. Con Ollama se omite.',
                    'reply' => is_string($decoded['reply'] ?? null) ? $decoded['reply'] : '',
                    'model' => $model,
                    'mcp_response_id' => is_string($dataToSave['mcp_response_id'] ?? null) ? $dataToSave['mcp_response_id'] : null,
                    'mcp_tool_traces' => $this->normalizeToolTraces($dataToSave['mcp_tool_traces'] ?? []),
                    'mcp_errors' => $this->normalizeList($dataToSave['mcp_errors'] ?? []),
                ];
            }

            return [
                'ok' => true,
                'found' => (bool) ($decoded['found'] ?? false),
                'provider' => $provider,
                'status_code' => $statusCode,
                'latency_ms' => $latencyMs,
                'error_code' => null,
                'summary' => 'La prueba MCP se ejecutó correctamente.',
                'reply' => is_string($decoded['reply'] ?? null) ? $decoded['reply'] : '',
                'model' => $model,
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

    /**
     * @return array{provider: ?string, model: ?string}
     */
    private function resolveRuntimeLlmProviderAndModel(): array
    {
        $snapshot = $this->runtimeConfigurationService->snapshot();
        $values = is_array($snapshot['values'] ?? null) ? $snapshot['values'] : [];
        $defaultProfile = self::cleanString($values['llm_default_profile'] ?? null);
        $openaiModel = self::cleanString($values['openai_model'] ?? null);
        $ollamaModel = self::cleanString($values['ollama_model'] ?? null);
        $openaiReady = $this->hasRuntimeValues($values, ['openai_base_url', 'openai_model', 'openai_api_key']);
        $ollamaReady = $this->hasRuntimeValues($values, ['ollama_base_url', 'ollama_model']);

        if ($defaultProfile === 'openai') {
            return $openaiReady ? ['provider' => 'openai', 'model' => $openaiModel] : ['provider' => null, 'model' => null];
        }

        if ($defaultProfile === 'ollama') {
            return $ollamaReady ? ['provider' => 'ollama', 'model' => $ollamaModel] : ['provider' => null, 'model' => null];
        }

        if ($defaultProfile === 'heuristic' || $defaultProfile === '') {
            return ['provider' => 'heuristic', 'model' => null];
        }

        if ($defaultProfile === 'auto') {
            if ($openaiReady) {
                return ['provider' => 'openai', 'model' => $openaiModel];
            }

            if ($ollamaReady) {
                return ['provider' => 'ollama', 'model' => $ollamaModel];
            }
        }

        return ['provider' => null, 'model' => null];
    }

    /**
     * @param array<string, mixed> $values
     * @param list<string> $requiredKeys
     */
    private function hasRuntimeValues(array $values, array $requiredKeys): bool
    {
        foreach ($requiredKeys as $key) {
            if (!is_string($values[$key] ?? null) || trim((string) $values[$key]) === '') {
                return false;
            }
        }

        return true;
    }

    private function normalizeRuntimeProvider(mixed $provider): ?string
    {
        if (!is_string($provider)) {
            return null;
        }

        $normalized = trim($provider);
        if (!in_array($normalized, ['openai', 'ollama', 'heuristic'], true)) {
            return null;
        }

        return $normalized;
    }
}
