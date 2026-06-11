<?php

namespace App\Controller\Web;

use App\Entity\ExternalTool;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\ExternalToolRepository;
use App\Repository\TenantRepository;
use App\Service\ActiveTenantContext;
use App\Service\RuntimeSettingCipher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[Route('/n8n-services')]
final class N8nServiceController extends AbstractController
{
    private const SERVICE_PROVIDER = 'n8n_webhook';
    private const SERVICE_TYPES = ['contact_context', 'handoff_webhook', 'crm_contact_submit', 'custom'];
    private const AUTH_TYPES = ['none', 'bearer'];

    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantRepository $tenants,
        private readonly ExternalToolRepository $externalTools,
        private readonly RuntimeSettingCipher $cipher,
        private readonly HttpClientInterface $httpClient,
        private readonly ActiveTenantContext $activeTenantContext,
        private readonly ?CsrfTokenManagerInterface $csrfTokenManager = null,
    ) {
    }

    #[Route('', name: 'backend_n8n_services_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->security->isGranted('ROLE_SUPER_ADMIN')) {
            return $this->redirect('/backend/login');
        }

        $activeTenant = $this->activeTenantContext->getActiveTenant();
        if (!$activeTenant instanceof Tenant) {
            return $this->renderTenantSelectionRequiredPage(
                'Servicios n8n',
                'Selecciona un negocio antes de gestionar servicios n8n.',
                'admin-n8n-services',
                'Servicios n8n'
            );
        }

        return $this->render('backend/n8n_services/index.html.twig', [
            'page_title' => sprintf('Servicios n8n de %s', $activeTenant->getName()),
            'page_subtitle' => 'Configura webhooks n8n que Sales Agent puede llamar directamente para contexto operativo o integraciones internas.',
            'active_nav' => 'admin-n8n-services',
            'tools' => array_map([$this, 'serviceRow'], $this->n8nServicesForTenant($activeTenant)),
            'active_tenant_name' => $activeTenant->getName(),
            ...$this->backendLayoutTemplateData(),
        ]);
    }

    #[Route('/new', name: 'backend_n8n_services_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        if (!$this->security->isGranted('ROLE_SUPER_ADMIN')) {
            return $this->redirect('/backend/login');
        }

        $activeTenant = $this->activeTenantContext->getActiveTenant();
        if (!$activeTenant instanceof Tenant) {
            return $this->renderTenantSelectionRequiredPage(
                'Crear servicio n8n',
                'Selecciona un negocio antes de crear un servicio n8n.',
                'admin-n8n-services',
                'Servicios n8n'
            );
        }

        $values = $this->serviceFormDefaults(null, $activeTenant);
        $errors = [];

        if ($request->isMethod('POST')) {
            $values = $this->serviceFormValuesFromRequest($request);
            $values['tenantId'] = $activeTenant->getId()->toRfc4122();

            if (!$this->isValidExternalToolToken('n8n_service_form_create', (string) $request->request->get('_csrf_token'))) {
                $errors[] = 'La sesión del formulario ha expirado. Vuelve a intentarlo.';
            } else {
                $errors = $this->validateServiceForm($values);
                if ($errors === []) {
                    $tool = new ExternalTool($activeTenant, $values['name'], $values['type'], self::SERVICE_PROVIDER);
                    $this->applyServiceFormValues($tool, $values, true, $activeTenant);
                    $this->entityManager->persist($tool);
                    $this->entityManager->flush();

                    return new RedirectResponse($this->backendN8nServicesIndexUrl($activeTenant->getId()->toRfc4122()));
                }
            }
        }

        return $this->renderServiceFormPage(
            'Servicios n8n',
            'Define el webhook n8n que Sales Agent llamará directamente desde el runtime.',
            'Crear servicio n8n',
            'Guardar servicio n8n',
            '/backend/n8n-services/new',
            $values,
            $errors,
            false,
            null,
            $activeTenant
        );
    }

    #[Route('/{id}/edit', name: 'backend_n8n_services_edit', methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request): Response
    {
        if (!$this->security->isGranted('ROLE_SUPER_ADMIN')) {
            return $this->redirect('/backend/login');
        }

        $activeTenant = $this->activeTenantContext->getActiveTenant();
        if (!$activeTenant instanceof Tenant) {
            return $this->renderTenantSelectionRequiredPage(
                'Editar servicio n8n',
                'Selecciona un negocio antes de editar un servicio n8n.',
                'admin-n8n-services',
                'Servicios n8n'
            );
        }

        $tool = $this->findTenantService($activeTenant, $id);
        if (!$tool instanceof ExternalTool) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $values = $this->serviceFormDefaults($tool, $activeTenant);
        $errors = [];

        if ($request->isMethod('POST')) {
            $values = $this->serviceFormValuesFromRequest($request);
            $values['tenantId'] = $activeTenant->getId()->toRfc4122();

            if (!$this->isValidExternalToolToken('n8n_service_form_'.$tool->getId()->toRfc4122(), (string) $request->request->get('_csrf_token'))) {
                $errors[] = 'La sesión del formulario ha expirado. Vuelve a intentarlo.';
            } else {
                $errors = $this->validateServiceForm($values, $tool);
                if ($errors === []) {
                    $this->applyServiceFormValues($tool, $values, false, $activeTenant);
                    $this->entityManager->persist($tool);
                    $this->entityManager->flush();

                    return new RedirectResponse($this->backendN8nServicesIndexUrl($activeTenant->getId()->toRfc4122()));
                }
            }
        }

        return $this->renderServiceFormPage(
            'Servicios n8n',
            'Ajusta el webhook, la auth y el JSON operativo del servicio n8n.',
            'Editar servicio n8n',
            'Guardar cambios',
            '/backend/n8n-services/'.$tool->getId()->toRfc4122().'/edit',
            $values,
            $errors,
            true,
            $tool,
            $activeTenant
        );
    }

    #[Route('/{id}/toggle', name: 'backend_n8n_services_toggle', methods: ['POST'])]
    public function toggle(string $id, Request $request): Response
    {
        if (!$this->security->isGranted('ROLE_SUPER_ADMIN')) {
            return $this->redirect('/backend/login');
        }

        $activeTenant = $this->activeTenantContext->getActiveTenant();
        $tool = $activeTenant instanceof Tenant ? $this->findTenantService($activeTenant, $id) : null;
        if (!$tool instanceof ExternalTool) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        if (!$this->isValidExternalToolToken('n8n_service_toggle_'.$tool->getId()->toRfc4122(), (string) $request->request->get('_csrf_token'))) {
            return new RedirectResponse($this->backendN8nServicesIndexUrl($tool->getTenant()->getId()->toRfc4122()));
        }

        $tool->setActive(!$tool->isActive());
        $this->entityManager->persist($tool);
        $this->entityManager->flush();

        $this->addFlash('success', $tool->isActive() ? 'Servicio n8n activado.' : 'Servicio n8n desactivado.');

        return new RedirectResponse($this->backendN8nServicesIndexUrl($tool->getTenant()->getId()->toRfc4122()));
    }

    #[Route('/{id}/delete', name: 'backend_n8n_services_delete', methods: ['POST'])]
    public function delete(string $id, Request $request): Response
    {
        if (!$this->security->isGranted('ROLE_SUPER_ADMIN')) {
            return $this->redirect('/backend/login');
        }

        $activeTenant = $this->activeTenantContext->getActiveTenant();
        $tool = $activeTenant instanceof Tenant ? $this->findTenantService($activeTenant, $id) : null;
        if (!$tool instanceof ExternalTool) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        if (!$this->isValidExternalToolToken('n8n_service_delete_'.$tool->getId()->toRfc4122(), (string) $request->request->get('_csrf_token'))) {
            return new RedirectResponse($this->backendN8nServicesIndexUrl($tool->getTenant()->getId()->toRfc4122()));
        }

        $tenantId = $tool->getTenant()->getId()->toRfc4122();
        $this->entityManager->remove($tool);
        $this->entityManager->flush();
        $this->addFlash('success', 'Servicio n8n eliminado.');

        return new RedirectResponse($this->backendN8nServicesIndexUrl($tenantId));
    }

    #[Route('/{id}/test-connection', name: 'backend_n8n_services_test_connection', methods: ['POST'])]
    public function testConnection(string $id, Request $request): Response
    {
        if (!$this->security->isGranted('ROLE_SUPER_ADMIN')) {
            return $this->redirect('/backend/login');
        }

        $activeTenant = $this->activeTenantContext->getActiveTenant();
        $tool = $activeTenant instanceof Tenant ? $this->findTenantService($activeTenant, $id) : null;
        if (!$tool instanceof ExternalTool) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        if (!$this->isValidExternalToolToken('n8n_service_test_'.$tool->getId()->toRfc4122(), (string) $request->request->get('_csrf_token'))) {
            return new RedirectResponse($this->backendN8nServicesIndexUrl($tool->getTenant()->getId()->toRfc4122()));
        }

        $testResult = $this->runConnectionTest($tool);

        return $this->render('backend/n8n_services/index.html.twig', [
            'page_title' => sprintf('Servicios n8n de %s', $activeTenant->getName()),
            'page_subtitle' => 'Configura webhooks n8n que Sales Agent puede llamar directamente para contexto operativo o integraciones internas.',
            'active_nav' => 'admin-n8n-services',
            'tools' => array_map([$this, 'serviceRow'], $this->n8nServicesForTenant($activeTenant)),
            'active_tenant_name' => $activeTenant->getName(),
            'test_result' => $testResult,
            ...$this->backendLayoutTemplateData(),
        ]);
    }

    /**
     * @return array{id: string, tenantId: string, tenantName: string, name: string, type: string, webhookUrl: string, authType: string, hasWebhookToken: bool, hasDownstreamAuthorizationToken: bool, timeoutSeconds: int, isActive: bool, configSummary: string, toggleToken: string, editToken: string, deleteToken: string}
     */
    private function serviceRow(ExternalTool $tool): array
    {
        return [
            'id' => $tool->getId()->toRfc4122(),
            'tenantId' => $tool->getTenant()->getId()->toRfc4122(),
            'tenantName' => $tool->getTenant()->getName(),
            'name' => $tool->getName(),
            'type' => $tool->getType(),
            'webhookUrl' => $tool->getWebhookUrl() ?? '',
            'authType' => $tool->getAuthType() ?? 'none',
            'hasWebhookToken' => $tool->getBearerToken() !== null && $tool->getBearerToken() !== '',
            'hasDownstreamAuthorizationToken' => $tool->hasDownstreamAuthorizationToken(),
            'timeoutSeconds' => $tool->getTimeoutSeconds(),
            'isActive' => $tool->isActive(),
            'configSummary' => $this->configSummary($tool->getConfig()),
            'toggleToken' => $this->externalToolTokenValue('n8n_service_toggle_'.$tool->getId()->toRfc4122()),
            'editToken' => $this->externalToolTokenValue('n8n_service_form_'.$tool->getId()->toRfc4122()),
            'deleteToken' => $this->externalToolTokenValue('n8n_service_delete_'.$tool->getId()->toRfc4122()),
            'testConnectionToken' => $this->externalToolTokenValue('n8n_service_test_'.$tool->getId()->toRfc4122()),
        ];
    }

    /**
     * @return array{name: string, tenantId: string, type: string, provider: string, webhookUrl: string, authType: string, bearerToken: string, clearBearerToken: bool, downstreamAuthorizationToken: string, clearDownstreamAuthorizationToken: bool, timeoutSeconds: string, isActive: bool, config: string}
     */
    private function serviceFormDefaults(?ExternalTool $tool = null, ?Tenant $tenant = null): array
    {
        return [
            'name' => $tool?->getName() ?? '',
            'tenantId' => $tenant?->getId()->toRfc4122() ?? $tool?->getTenant()?->getId()->toRfc4122() ?? '',
            'type' => $tool?->getType() ?? 'contact_context',
            'provider' => self::SERVICE_PROVIDER,
            'webhookUrl' => $tool?->getWebhookUrl() ?? '',
            'authType' => $tool?->getAuthType() ?? ($tool?->getBearerToken() !== null ? 'bearer' : 'none'),
            'bearerToken' => '',
            'clearBearerToken' => false,
            'downstreamAuthorizationToken' => '',
            'clearDownstreamAuthorizationToken' => false,
            'timeoutSeconds' => (string) ($tool?->getTimeoutSeconds() ?? 5),
            'isActive' => $tool?->isActive() ?? true,
            'config' => $this->configToTextarea($tool?->getConfig() ?? []),
        ];
    }

    /**
     * @return array{name: string, tenantId: string, type: string, provider: string, webhookUrl: string, authType: string, bearerToken: string, clearBearerToken: bool, downstreamAuthorizationToken: string, clearDownstreamAuthorizationToken: bool, timeoutSeconds: string, isActive: bool, config: string}
     */
    private function serviceFormValuesFromRequest(Request $request): array
    {
        return [
            'name' => trim((string) $request->request->get('name', '')),
            'tenantId' => trim((string) $request->request->get('tenantId', '')),
            'type' => trim((string) $request->request->get('type', 'contact_context')),
            'provider' => self::SERVICE_PROVIDER,
            'webhookUrl' => trim((string) $request->request->get('webhookUrl', '')),
            'authType' => trim((string) $request->request->get('authType', 'none')),
            'bearerToken' => trim((string) $request->request->get('bearerToken', '')),
            'clearBearerToken' => $request->request->has('clearBearerToken'),
            'downstreamAuthorizationToken' => trim((string) $request->request->get('downstreamAuthorizationToken', '')),
            'clearDownstreamAuthorizationToken' => $request->request->has('clearDownstreamAuthorizationToken'),
            'timeoutSeconds' => trim((string) $request->request->get('timeoutSeconds', '5')),
            'isActive' => $request->request->has('isActive'),
            'config' => trim((string) $request->request->get('config', '{}')),
        ];
    }

    /**
     * @param array{name: string, tenantId: string, type: string, provider: string, webhookUrl: string, authType: string, bearerToken: string, clearBearerToken: bool, downstreamAuthorizationToken: string, clearDownstreamAuthorizationToken: bool, timeoutSeconds: string, isActive: bool, config: string} $values
     *
     * @return list<string>
     */
    private function validateServiceForm(array $values, ?ExternalTool $tool = null): array
    {
        $errors = [];

        if ($values['name'] === '') {
            $errors[] = 'El nombre es obligatorio.';
        }

        if (!in_array($values['type'], self::SERVICE_TYPES, true)) {
            $errors[] = 'El tipo de servicio n8n no es válido.';
        }

        if ($values['provider'] !== self::SERVICE_PROVIDER) {
            $errors[] = 'El provider de un servicio n8n queda fijado a n8n_webhook.';
        }

        if ($values['webhookUrl'] === '') {
            $errors[] = 'La URL del webhook es obligatoria.';
        } elseif (!$this->isValidHttpUrl($values['webhookUrl'])) {
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

        if ($tool instanceof ExternalTool && $tool->getTenant()->getId()->toRfc4122() !== $values['tenantId']) {
            $errors[] = 'El servicio pertenece a otro negocio.';
        }

        return array_values(array_unique($errors));
    }

    /**
     * @param array{name: string, tenantId: string, type: string, provider: string, webhookUrl: string, authType: string, bearerToken: string, clearBearerToken: bool, downstreamAuthorizationToken: string, clearDownstreamAuthorizationToken: bool, timeoutSeconds: string, isActive: bool, config: string} $values
     */
    private function applyServiceFormValues(ExternalTool $tool, array $values, bool $isNew, Tenant $tenant): void
    {
        $tool->setTenant($tenant);
        $tool->setName($values['name']);
        $tool->setType($values['type']);
        $tool->setProvider(self::SERVICE_PROVIDER);
        $tool->setWebhookUrl($values['webhookUrl'] !== '' ? $values['webhookUrl'] : null);

        $effectiveAuthType = $values['authType'];
        if ($effectiveAuthType === 'none' && ($values['bearerToken'] !== '' || $tool->getBearerToken() !== null)) {
            $effectiveAuthType = 'bearer';
        }

        $tool->setAuthType($effectiveAuthType !== 'none' ? $effectiveAuthType : null);
        $tool->setTimeoutSeconds((int) $values['timeoutSeconds']);
        $tool->setActive($values['isActive']);
        $tool->setConfig($this->decodeConfig($values['config']));
        $this->applyWebhookToken($tool, $effectiveAuthType, $values['bearerToken'], $values['clearBearerToken'], $isNew);
        $this->applyDownstreamAuthorizationToken(
            $tool,
            $values['downstreamAuthorizationToken'],
            $values['clearDownstreamAuthorizationToken'],
            $isNew
        );
    }

    private function applyWebhookToken(ExternalTool $tool, string $authType, string $rawBearerToken, bool $clearBearerToken, bool $isNew): void
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

    private function applyDownstreamAuthorizationToken(ExternalTool $tool, string $rawToken, bool $clearToken, bool $isNew): void
    {
        if ($clearToken) {
            $tool->setDownstreamAuthorizationToken(null);

            return;
        }

        if ($rawToken !== '') {
            $tool->setDownstreamAuthorizationToken($this->cipher->encrypt($rawToken));

            return;
        }

        if ($isNew || $tool->getDownstreamAuthorizationToken() === null) {
            $tool->setDownstreamAuthorizationToken(null);
        }
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

    private function currentUserLabel(): string
    {
        $user = $this->security->getUser();

        return $user instanceof UserInterface ? $user->getUserIdentifier() : 'Invitado';
    }

    private function currentUser(): ?\App\Entity\User
    {
        $user = $this->security->getUser();

        return $user instanceof \App\Entity\User ? $user : null;
    }

    private function isSuperAdmin(): bool
    {
        return $this->security->isGranted('ROLE_SUPER_ADMIN');
    }

    private function canManageActiveTenant(): bool
    {
        return $this->isSuperAdmin() && $this->activeTenantContext->getActiveTenant() instanceof Tenant;
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

    private function renderTenantSelectionRequiredPage(string $pageTitle, string $pageSubtitle, string $activeNav, string $sectionLabel): Response
    {
        return $this->render('backend/n8n_services/index.html.twig', [
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
            'services' => [],
            'active_tenant_name' => null,
            ...$this->backendLayoutTemplateData(),
        ]);
    }

    private function renderServiceFormPage(
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
        return $this->render('backend/n8n_services/form.html.twig', [
            'page_title' => $pageTitle,
            'page_subtitle' => $pageSubtitle,
            'active_nav' => 'admin-n8n-services',
            'values' => $values,
            'errors' => $errors,
            'hero_title' => $heroTitle,
            'submit_label' => $submitLabel,
            'action_url' => $actionUrl,
            'is_edit' => $isEdit,
            'service_id' => $tool?->getId()->toRfc4122(),
            'has_webhook_token' => $tool?->getBearerToken() !== null && $tool?->getBearerToken() !== '',
            'has_downstream_authorization_token' => $tool?->hasDownstreamAuthorizationToken() ?? false,
            'form_token' => $this->externalToolTokenValue($isEdit && $tool instanceof ExternalTool ? 'n8n_service_form_'.$tool->getId()->toRfc4122() : 'n8n_service_form_create'),
            'active_tenant_name' => $activeTenant instanceof Tenant ? $activeTenant->getName() : null,
            'service_types' => self::SERVICE_TYPES,
            ...$this->backendLayoutTemplateData(),
        ]);
    }

    /**
     * @return ExternalTool[]
     */
    private function n8nServicesForTenant(Tenant $tenant): array
    {
        return array_values(array_filter(
            $this->externalTools->findByTenantAndProviderOrdered($tenant, self::SERVICE_PROVIDER),
            static fn (ExternalTool $tool): bool => $tool->getProvider() === self::SERVICE_PROVIDER
        ));
    }

    private function findTenantService(Tenant $tenant, string $id): ?ExternalTool
    {
        $tool = $this->externalTools->find($id);
        if (!$tool instanceof ExternalTool) {
            return null;
        }

        if ($tool->getTenant()->getId()->toRfc4122() !== $tenant->getId()->toRfc4122()) {
            return null;
        }

        if ($tool->getProvider() !== self::SERVICE_PROVIDER) {
            return null;
        }

        return $tool;
    }

    private function isValidHttpUrl(string $value): bool
    {
        $parts = parse_url($value);
        if (!is_array($parts) || ($parts['scheme'] ?? '') === '' || ($parts['host'] ?? '') === '') {
            return false;
        }

        return in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true);
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

    private function backendN8nServicesIndexUrl(?string $tenantId = null): string
    {
        $query = $tenantId !== null && trim($tenantId) !== '' ? '?tenant_id='.rawurlencode(trim($tenantId)) : '';

        return '/backend/n8n-services'.$query;
    }

    /**
     * @return array{ok: bool, reachable: bool, status_code: ?int, latency_ms: int, error_code: ?string, error_message: ?string, summary: string, reply: string}
     */
    private function runConnectionTest(ExternalTool $tool): array
    {
        $webhookUrl = trim((string) $tool->getWebhookUrl());
        if ($webhookUrl === '') {
            return [
                'ok' => false,
                'reachable' => false,
                'status_code' => null,
                'latency_ms' => 0,
                'error_code' => 'invalid_config',
                'error_message' => 'Webhook URL not configured.',
                'summary' => 'El webhook no tiene URL configurada.',
                'reply' => '',
            ];
        }

        $headers = ['Accept' => 'application/json'];
        $webhookToken = $this->decryptToken($tool->getBearerToken());
        if ($tool->getAuthType() === 'bearer' && $webhookToken !== null) {
            $headers['Authorization'] = 'Bearer '.$webhookToken;
        }

        $downstreamToken = $this->decryptToken($tool->getDownstreamAuthorizationToken());
        if ($downstreamToken !== null) {
            $headers['X-Downstream-Authorization'] = 'Bearer '.$downstreamToken;
        }

        $startedAt = microtime(true);
        try {
            $response = $this->httpClient->request('HEAD', $webhookUrl, [
                'headers' => $headers,
                'timeout' => max(1, min(10, $tool->getTimeoutSeconds())),
                'max_redirects' => 0,
            ]);
            $statusCode = $response->getStatusCode();
            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
            $isReachable = $statusCode >= 200 && $statusCode < 500;
            $isValidated = $statusCode >= 200 && $statusCode < 400;
            $summary = $isValidated
                ? sprintf('Conexión verificada con respuesta %d.', $statusCode)
                : 'El host responde, pero no se pudo validar el webhook sin ejecutarlo.';

            return [
                'ok' => $isValidated,
                'reachable' => $isReachable,
                'status_code' => $statusCode,
                'latency_ms' => $latencyMs,
                'error_code' => null,
                'error_message' => null,
                'summary' => $summary,
                'reply' => '',
            ];
        } catch (\Throwable $exception) {
            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

            return [
                'ok' => false,
                'reachable' => false,
                'status_code' => null,
                'latency_ms' => $latencyMs,
                'error_code' => 'connection_error',
                'error_message' => $exception->getMessage(),
                'summary' => 'No se pudo conectar con el webhook n8n.',
                'reply' => '',
            ];
        }
    }

    private function decryptToken(?string $token): ?string
    {
        if ($token === null || trim($token) === '') {
            return null;
        }

        try {
            $decrypted = $this->cipher->decrypt($token);
        } catch (\Throwable) {
            return null;
        }

        return trim($decrypted) !== '' ? trim($decrypted) : null;
    }
}
