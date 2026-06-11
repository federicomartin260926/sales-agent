<?php

namespace App\Controller\Web;

use App\Domain\CommercialDomainSchema;
use App\Entity\EntryPoint;
use App\Entity\ExternalTool;
use App\Entity\CommercialPlan;
use App\Entity\AiUsageEvent;
use App\Entity\AiModelCostReference;
use App\Entity\Playbook;
use App\Entity\Product;
use App\Entity\Tenant;
use App\Entity\TenantAiUsagePolicy;
use App\Entity\TenantAiTopUpRequest;
use App\Entity\TenantMembership;
use App\Entity\User;
use App\Exception\PlanLimitExceededException;
use App\Repository\AiUsageEventRepository;
use App\Repository\AiModelCostReferenceRepository;
use App\Repository\CommercialPlanRepository;
use App\Repository\PlaybookRepository;
use App\Repository\ProductRepository;
use App\Repository\EntryPointRepository;
use App\Repository\ExternalToolRepository;
use App\Repository\TenantAiUsagePolicyRepository;
use App\Repository\TenantAiTopUpRequestRepository;
use App\Repository\TenantRepository;
use App\Repository\UserRepository;
use App\Service\ActiveTenantContext;
use App\Service\CommercialTokenFormatter;
use App\Service\PlanEntitlementResolver;
use App\Service\PlanUsageGuard;
use App\Service\RuntimeConfigurationService;
use App\Service\TenantAccessResolver;
use App\Service\ProductCatalogImportResult;
use App\Service\ProductCatalogImportService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Twig\Environment;

final class BackendUiController
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly RuntimeConfigurationService $runtimeConfigurationService,
        private readonly ActiveTenantContext $activeTenantContext,
        private readonly Environment $twig,
        private readonly ?AiUsageEventRepository $aiUsageEvents = null,
        private readonly ?AiModelCostReferenceRepository $aiModelCosts = null,
        private readonly ?ProductCatalogImportService $productCatalogImportService = null,
        private readonly ?CsrfTokenManagerInterface $csrfTokenManager = null,
        private readonly ?TenantAccessResolver $tenantAccessResolver = null,
        private readonly ?PlanEntitlementResolver $planEntitlementResolver = null,
        private readonly ?PlanUsageGuard $planUsageGuard = null,
    ) {
    }

    #[Route('/', methods: ['GET'])]
    public function index(): Response
    {
        return $this->security->getUser() instanceof UserInterface
            ? new RedirectResponse('/backend/dashboard')
            : new RedirectResponse('/backend/login');
    }

    #[Route('/login', methods: ['GET'])]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->security->getUser() instanceof UserInterface) {
            return new RedirectResponse('/backend/dashboard');
        }

        $error = $authenticationUtils->getLastAuthenticationError();
        $lastUsername = $authenticationUtils->getLastUsername();

        $errorHtml = $error ? sprintf(
            '<div class="alert alert-error">%s</div>',
            htmlspecialchars($error->getMessage(), ENT_QUOTES, 'UTF-8')
        ) : '';

        return new Response($this->twig->render('backend/login.html.twig', [
            'error_html' => $errorHtml,
            'last_username' => $lastUsername,
        ]));
    }

    #[Route('/login-check', methods: ['POST'])]
    public function loginCheck(): Response
    {
        return new Response('', Response::HTTP_NO_CONTENT);
    }

    #[Route('/logout', methods: ['GET'])]
    public function logout(\Symfony\Component\HttpFoundation\Request $request): Response
    {
        if ($request->hasSession()) {
            $request->getSession()->invalidate();
        }

        return new RedirectResponse('/backend/login');
    }

    #[Route('/configuration', methods: ['GET', 'POST'])]
    public function configuration(Request $request): Response
    {
        if (!$this->security->isGranted('ROLE_SUPER_ADMIN')) {
            return new RedirectResponse('/backend/login');
        }

        $submitted = $request->isMethod('POST') ? $this->runtimeConfigurationValuesFromRequest($request) : [];
        $action = trim((string) $request->request->get('action', ''));

        if ($request->isMethod('POST') && $action === 'save') {
            if (!$this->isValidRuntimeConfigurationToken('runtime_configuration', (string) $request->request->get('_csrf_token'))) {
                return new RedirectResponse('/backend/configuration');
            }

            $validationErrors = $this->runtimeConfigurationService->validate($submitted);

            if ($validationErrors !== []) {
                $pageData = $this->runtimeConfigurationService->pageData($submitted, []);
                $feedbackHtml = $this->renderProfileFeedback($request);
                foreach ($validationErrors as $validationError) {
                    $feedbackHtml .= $this->renderDismissibleAlert(
                        'alert-error',
                        htmlspecialchars($validationError, ENT_QUOTES, 'UTF-8')
                    );
                }

                return $this->renderRuntimeConfigurationPage($pageData, $feedbackHtml, $this->runtimeConfigurationTokenValue('runtime_configuration'));
            }

            $result = $this->runtimeConfigurationService->save($submitted);
            $savedCount = count($result['saved']);
            $this->addProfileFlash($request, 'success', $savedCount > 0 ? sprintf('Configuración guardada (%d claves).', $savedCount) : 'No hubo cambios para guardar.');

            return new RedirectResponse('/backend/configuration');
        }

        $testResult = null;
        $testResults = [];
        $target = $this->runtimeConfigurationTargetFromAction($action);
        if ($request->isMethod('POST')) {
            if (!$this->isValidRuntimeConfigurationToken('runtime_configuration', (string) $request->request->get('_csrf_token'))) {
                return new RedirectResponse('/backend/configuration');
            }

            if ($target !== null) {
                $testResult = $this->runtimeConfigurationService->test($target, $submitted);
                $testResults[$target] = $testResult;
            }
        }

        $pageData = $this->runtimeConfigurationService->pageData($submitted, $testResults);
        $feedbackHtml = $this->renderProfileFeedback($request);
        if ($testResult instanceof \App\Service\RuntimeConnectivityTestResult) {
            $feedbackHtml .= $this->runtimeTestFeedback($testResult);
        }

        return $this->renderRuntimeConfigurationPage($pageData, $feedbackHtml, $this->runtimeConfigurationTokenValue('runtime_configuration'));
    }

    #[Route('/dashboard', methods: ['GET'])]
    public function dashboard(
        ?TenantRepository $tenants = null,
        ?UserRepository $users = null,
        ?PlaybookRepository $playbooks = null,
        ?ProductRepository $products = null,
        ?EntryPointRepository $entryPoints = null,
        ?ExternalToolRepository $externalTools = null,
    ): Response {
        if (!$this->security->isGranted('ROLE_AGENT')) {
            return new RedirectResponse('/backend/login');
        }

        $activeTenant = $this->resolvedActiveTenantForCurrentUser();
        $canManageUsers = $this->security->isGranted('ROLE_SUPER_ADMIN');

        if (!$activeTenant instanceof Tenant) {
            $accessibleTenants = $this->accessibleTenantsForCurrentUser();
            if (!$this->isSuperAdmin() && count($accessibleTenants) === 1 && $accessibleTenants[0] instanceof Tenant) {
                $this->activeTenantContext->setActiveTenant($accessibleTenants[0]);

                return new RedirectResponse('/backend/dashboard');
            }

            $heroActions = sprintf(
                '<a class="primary-action" href="/backend/tenants">Ir al selector</a>%s',
                $this->security->isGranted('ROLE_SUPER_ADMIN') ? '<a class="secondary-action" href="/backend/tenants/new">Crear negocio</a>' : ''
            );

            $content = $this->twig->render('backend/dashboard.html.twig', [
                'dashboard_title' => 'Selecciona un negocio para empezar',
                'dashboard_eyebrow' => 'Selector de negocios',
                'dashboard_subtitle' => 'Activa un negocio para ver su ficha, productos, guías, puntos de entrada y herramientas.',
                'hero_actions_html' => $heroActions,
                'metric_cards_html' => '',
                'info_cards_html' => implode('', array_filter([
                    $this->infoCard('Selector de negocios', 'Abre el listado y entra en el negocio con el que quieras trabajar.', '/backend/tenants', 'Abrir'),
                    $this->security->isGranted('ROLE_SUPER_ADMIN') ? $this->infoCard('Crear negocio', 'Alta de un nuevo contexto comercial cuando no exista todavía.', '/backend/tenants/new', 'Crear') : null,
                    $canManageUsers ? $this->infoCard('Usuarios', 'La administración transversal sigue disponible sin seleccionar negocio.', '/backend/users', 'Gestionar') : $this->infoCard('Perfil', 'Tu perfil sigue disponible aunque no haya negocio activo.', '/backend/profile', 'Abrir'),
                ])),
            ]);

            return $this->renderBackendShell(
                'Panel comercial',
                'Selecciona un negocio para empezar.',
                'dashboard',
                $content
            );
        }

        $productCount = $this->countTenantProducts($products, $activeTenant);
        $playbookCount = $this->countTenantPlaybooks($playbooks, $activeTenant);
        $entryPointCount = $this->countTenantEntryPoints($entryPoints, $activeTenant);
        $canSeeTenantTechnicalTools = $this->isSuperAdmin();
        $externalToolCount = $canSeeTenantTechnicalTools ? $this->countTenantExternalTools($externalTools, $activeTenant) : 0;
        $mcpState = $canSeeTenantTechnicalTools ? $this->tenantMcpRuntimeState($externalTools, $activeTenant) : [
            'value' => 'Reservado a plataforma',
            'note' => 'Solo super admin',
            'detail' => 'Los servidores MCP no están disponibles para clientes o tenant managers.',
        ];

        $tenantActionUrl = $this->canManageTenant($activeTenant)
            ? sprintf('/backend/tenants/%s/edit', rawurlencode($activeTenant->getId()->toRfc4122()))
            : '/backend/tenants';
        $tenantActionLabel = $this->canManageTenant($activeTenant) ? 'Editar negocio' : 'Ver negocio';
        $tenantActionClass = $this->canManageTenant($activeTenant) ? 'primary-action' : 'secondary-action';

        $heroActions = implode('', [
            sprintf('<a class="%s" href="%s">%s</a>', $tenantActionClass, htmlspecialchars($tenantActionUrl, ENT_QUOTES, 'UTF-8'), htmlspecialchars($tenantActionLabel, ENT_QUOTES, 'UTF-8')),
            '<a class="secondary-action" href="/backend/products">Ver productos / servicios</a>',
            '<a class="secondary-action" href="/backend/playbooks">Ver guías comerciales</a>',
            '<a class="secondary-action" href="/backend/entry-points">Ver puntos de entrada</a>',
            $canSeeTenantTechnicalTools ? '<a class="secondary-action" href="/backend/external-tools">Ver servidores MCP</a>' : '',
        ]);

        $content = $this->twig->render('backend/dashboard.html.twig', [
            'dashboard_title' => sprintf('Dashboard comercial — %s', $activeTenant->getName()),
            'dashboard_eyebrow' => 'Negocio activo',
            'dashboard_subtitle' => 'Aquí configuras el contexto, productos, guías y herramientas del agente para este negocio.',
            'hero_actions_html' => $heroActions,
            'metric_cards_html' => implode('', [
                $this->metricCard('Productos / servicios', (string) $productCount, 'Del negocio activo'),
                $this->metricCard('Guías comerciales', (string) $playbookCount, 'Estrategias opcionales del negocio'),
                $this->metricCard('Puntos de entrada', (string) $entryPointCount, 'Rutas y campañas activas'),
                $canSeeTenantTechnicalTools ? $this->metricCard('Servidores MCP', (string) $externalToolCount, 'Herramientas técnicas del negocio') : '',
                //$canSeeTenantTechnicalTools ? $this->metricCard('MCP runtime', $mcpState['value'], $mcpState['note']) : '',
            ]),
            'info_cards_html' => implode('', [
                $this->infoCard('Ficha del negocio', 'Edita contexto, tono y política comercial del tenant activo.', sprintf('/backend/tenants/%s/edit', rawurlencode($activeTenant->getId()->toRfc4122())), 'Editar'),
                $this->infoCard('Productos / servicios', 'Gestiona el catálogo comercial de este negocio.', '/backend/products', 'Abrir'),
                $this->infoCard('Guías comerciales', 'Ajusta estrategias específicas opcionales.', '/backend/playbooks', 'Abrir'),
                $this->infoCard('Puntos de entrada', 'Revisa rutas públicas y campañas del negocio activo.', '/backend/entry-points', 'Abrir'),
                $canSeeTenantTechnicalTools ? $this->infoCard('Servidores MCP', $mcpState['detail'], '/backend/external-tools', 'Abrir') : '',
            ]),
        ]);

        return $this->renderBackendShell(
            sprintf('Dashboard comercial — %s', $activeTenant->getName()),
            'Aquí configuras el contexto, productos, guías y herramientas del agente para este negocio.',
            'dashboard',
            $content
        );
    }

    #[Route('/playbooks', methods: ['GET'])]
    public function playbooks(Request $request, ?PlaybookRepository $playbooks = null): Response
    {
        if (!$this->security->isGranted('ROLE_MANAGER')) {
            return new RedirectResponse('/backend/login');
        }

        $feedbackHtml = $this->renderProfileFeedback($request);
        $activeTenant = $this->resolvedActiveTenantForCurrentUser();
        if (!$activeTenant instanceof Tenant) {
            return $this->renderTenantSelectionRequiredPage(
                'Guías comerciales',
                'Estrategias opcionales que complementan la política general del negocio.',
                'playbooks',
                'Guías comerciales'
            );
        }

        $rows = array_map(function (Playbook $playbook): string {
            $tenant = $playbook->getTenant();
            $product = $playbook->getProduct();
            $summary = $this->shortenListText($playbook->getConfigSummary(), 120, 'Sin resumen');
            $status = $playbook->isActive() ? '<span class="status-ok">Activo</span>' : '<span class="status-off">Inactivo</span>';
            $editUrl = sprintf('/backend/playbooks/%s/edit', rawurlencode($playbook->getId()->toRfc4122()));
            $deleteUrl = sprintf('/backend/playbooks/%s/delete', rawurlencode($playbook->getId()->toRfc4122()));

            return sprintf(
                '<tr>
                    <td><strong>%s</strong><div class="subtle">Resumen: %s</div></td>
                    <td>%s</td>
                    <td>%s</td>
                    <td>%s</td>
                    <td class="text-right">
                      <div style="display:inline-flex;align-items:center;gap:10px;flex-wrap:wrap;justify-content:flex-end">
                        <a class="icon-action" href="%s" title="Editar guía comercial" aria-label="Editar guía comercial">%s</a>
                        <form method="post" action="%s" onsubmit="return confirm(\'¿Eliminar esta guía comercial?\');" style="display:inline-flex;">
                          <input type="hidden" name="_csrf_token" value="%s">
                          <button class="icon-action icon-action-danger icon-action-button" type="submit" title="Eliminar guía comercial" aria-label="Eliminar guía comercial">%s</button>
                        </form>
                      </div>
                    </td>
                  </tr>',
                htmlspecialchars($playbook->getName(), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($summary, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($tenant->getName(), ENT_QUOTES, 'UTF-8'),
                $product ? htmlspecialchars($product->getName(), ENT_QUOTES, 'UTF-8') : 'Sin producto',
                $status,
                htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8'),
                self::iconEditSvg(),
                htmlspecialchars($deleteUrl, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($this->playbookTokenValue($deleteUrl), ENT_QUOTES, 'UTF-8'),
                self::iconDeleteSvg()
            );
        }, $playbooks ? $playbooks->findByTenantOrdered($activeTenant) : []);

        $content = $this->twig->render('backend/playbooks/index.html.twig', [
            'feedback_html' => $feedbackHtml,
            'rows_html' => $rows !== [] ? implode('', $rows) : '<tr><td colspan="5" class="empty-row">No hay guías comerciales todavía.</td></tr>',
            'active_tenant_name' => $activeTenant->getName(),
        ]);

        return $this->renderBackendShell(sprintf('Guías comerciales de %s', $activeTenant->getName()), 'Estrategias opcionales del negocio activo.', 'playbooks', $content);
    }

    #[Route('/playbooks/new', methods: ['GET', 'POST'])]
    public function playbookCreate(Request $request, ?TenantRepository $tenants = null, ?ProductRepository $products = null, ?PlaybookRepository $playbooks = null): Response
    {
        if (!$this->security->isGranted('ROLE_MANAGER')) {
            return new RedirectResponse('/backend/login');
        }

        $activeTenant = $this->resolvedActiveTenantForCurrentUser();
        if (!$activeTenant instanceof Tenant) {
            return $this->renderTenantSelectionRequiredPage(
                'Crear guía comercial',
                'Selecciona un negocio antes de crear una estrategia específica.',
                'playbooks',
                'Guías comerciales'
            );
        }

        $values = $this->playbookFormDefaults(null, $activeTenant);
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isValidPlaybookToken('/backend/playbooks/new', (string) $request->request->get('_csrf_token'))) {
                $error = 'La sesión del formulario ha expirado. Vuelve a intentarlo.';
            } else {
                $values = $this->playbookFormValuesFromRequest($request);
                $error = $this->validatePlaybookForm($values, null, $activeTenant, $products, $playbooks);

                if ($error === null) {
                    try {
                        if ($this->planUsageGuard instanceof PlanUsageGuard) {
                            $this->planUsageGuard->assertCanCreatePlaybook($activeTenant);
                        }

                        $playbook = new Playbook($activeTenant, $values['name']);
                        $this->hydratePlaybookFromForm($playbook, $values, $activeTenant, $products);
                        $this->entityManager->persist($playbook);
                        $this->entityManager->flush();

                        return new RedirectResponse('/backend/playbooks');
                    } catch (PlanLimitExceededException $exception) {
                        $error = $exception->getMessage();
                    }
                }
            }
        }

        return $this->renderPlaybookForm(
            'Crear guía comercial',
            sprintf('Define reglas específicas opcionales para un producto, campaña o situación concreta de %s.', $activeTenant->getName()),
            'Crear guía comercial',
            'Crear guía comercial',
            '/backend/playbooks/new',
            $values,
            $products,
            $error
        );
    }

    #[Route('/playbooks/{id}/edit', methods: ['GET', 'POST'])]
    public function playbookEdit(string $id, Request $request, ?TenantRepository $tenants = null, ?ProductRepository $products = null, ?PlaybookRepository $playbooks = null): Response
    {
        if (!$this->security->isGranted('ROLE_MANAGER')) {
            return new RedirectResponse('/backend/login');
        }

        $activeTenant = $this->resolvedActiveTenantForCurrentUser();
        if (!$activeTenant instanceof Tenant) {
            return $this->renderTenantSelectionRequiredPage(
                'Editar guía comercial',
                'Selecciona un negocio antes de editar esta estrategia específica.',
                'playbooks',
                'Guías comerciales'
            );
        }

        if (!$playbooks instanceof PlaybookRepository) {
            return new RedirectResponse('/backend/playbooks');
        }

        $playbook = $playbooks->find($id);
        if (!$playbook instanceof Playbook || $playbook->getTenant()->getId()->toRfc4122() !== $activeTenant->getId()->toRfc4122()) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $values = $this->playbookFormDefaults($playbook, $activeTenant);
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isValidPlaybookToken('/backend/playbooks/'.$playbook->getId()->toRfc4122().'/edit', (string) $request->request->get('_csrf_token'))) {
                $error = 'La sesión del formulario ha expirado. Vuelve a intentarlo.';
            } else {
                $values = $this->playbookFormValuesFromRequest($request);
                $error = $this->validatePlaybookForm($values, $playbook, $activeTenant, $products, $playbooks);

                if ($error === null) {
                    $this->hydratePlaybookFromForm($playbook, $values, $activeTenant, $products);
                    $this->entityManager->persist($playbook);
                    $this->entityManager->flush();

                    return new RedirectResponse('/backend/playbooks');
                }
            }
        }

        return $this->renderPlaybookForm(
            'Editar guía comercial',
            sprintf('Ajusta esta estrategia opcional para complementar la política general de %s.', $activeTenant->getName()),
            'Editar guía comercial',
            'Guardar cambios',
            '/backend/playbooks/'.$playbook->getId()->toRfc4122().'/edit',
            $values,
            $products,
            $error
        );
    }

    #[Route('/playbooks/{id}/delete', methods: ['POST'])]
    public function playbookDelete(string $id, Request $request, ?PlaybookRepository $playbooks = null): Response
    {
        if (!$this->security->isGranted('ROLE_MANAGER')) {
            return new RedirectResponse('/backend/login');
        }

        if (!$playbooks instanceof PlaybookRepository) {
            return new RedirectResponse('/backend/playbooks');
        }

        $playbook = $playbooks->find($id);
        $activeTenant = $this->resolvedActiveTenantForCurrentUser();
        if (!$playbook instanceof Playbook || !$activeTenant instanceof Tenant || $playbook->getTenant()->getId()->toRfc4122() !== $activeTenant->getId()->toRfc4122()) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $deleteUrl = '/backend/playbooks/'.$playbook->getId()->toRfc4122().'/delete';
        if (!$this->isValidPlaybookToken($deleteUrl, (string) $request->request->get('_csrf_token'))) {
            return new RedirectResponse('/backend/playbooks');
        }

        $this->entityManager->remove($playbook);
        $this->entityManager->flush();
        $this->addFlashMessage($request, 'success', 'Guía comercial eliminada.');

        return new RedirectResponse('/backend/playbooks');
    }

    #[Route('/tenants', methods: ['GET'])]
    public function tenants(Request $request, ?TenantRepository $tenants = null): Response
    {
        if (!$this->security->isGranted('ROLE_MANAGER')) {
            return new RedirectResponse('/backend/login');
        }

        $feedbackHtml = $this->renderProfileFeedback($request);
        $currentUser = $this->currentUser();
        $accessibleTenants = $this->accessibleTenantsForCurrentUser();
        if (!$this->isSuperAdmin() && count($accessibleTenants) === 1 && $accessibleTenants[0] instanceof Tenant) {
            $this->activeTenantContext->setActiveTenant($accessibleTenants[0]);

            return new RedirectResponse('/backend/dashboard');
        }

        if (!$this->isSuperAdmin()) {
            $rows = array_map(function (Tenant $tenant) use ($request): string {
                $contextSummary = $this->shortenListText($tenant->getBusinessContext(), 110, 'Sin contexto');
                $toneSummary = $this->shortenListText($tenant->getTone() ?? '', 36, 'Sin tono');
                $policySummary = $this->shortenListText($tenant->getSalesPolicySummary(), 130, 'Sin política comercial');
                $planSummary = $this->commercialPlanSummary($tenant->getCommercialPlan());
                $status = $tenant->isActive() ? '<span class="status-ok">Activo</span>' : '<span class="status-off">Inactivo</span>';
                $enterUrl = sprintf('/backend/tenants/%s/enter', rawurlencode($tenant->getId()->toRfc4122()));
                $editUrl = $this->canManageTenant($tenant) ? sprintf('/backend/tenants/%s/edit', rawurlencode($tenant->getId()->toRfc4122())) : null;

                return sprintf(
                    '<tr>
                    <td><strong>%s</strong><div class="subtle">Contexto: %s</div><div class="subtle">Tono: %s</div></td>
                    <td><code>%s</code></td>
                    <td>Política: %s</td>
                    <td>Plan: %s</td>
                    <td>%s</td>
                    <td class="text-right">
                      <div style="display:inline-flex;align-items:center;gap:10px;flex-wrap:wrap;justify-content:flex-end">
                        <form method="post" action="%s" style="display:inline-flex;">
                          <input type="hidden" name="_csrf_token" value="%s">
                          <button class="secondary-action" type="submit" title="Entrar en el negocio" aria-label="Entrar en el negocio">Entrar</button>
                        </form>
                        <a class="secondary-action" href="/backend/super-admin/tenants/%s/ai" title="IA del tenant" aria-label="IA del tenant">IA</a>
                        %s
                      </div>
                    </td>
                  </tr>',
                    htmlspecialchars($tenant->getName(), ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars($contextSummary, ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars($toneSummary, ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars($tenant->getSlug(), ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars($policySummary, ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars($planSummary, ENT_QUOTES, 'UTF-8'),
                    $status,
                    htmlspecialchars($enterUrl, ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars($this->tenantTokenValue($enterUrl), ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars($tenant->getId()->toRfc4122(), ENT_QUOTES, 'UTF-8'),
                    is_string($editUrl) ? sprintf(
                        '<a class="icon-action" href="%s" title="Editar negocio" aria-label="Editar negocio">%s</a>',
                        htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8'),
                        self::iconEditSvg()
                    ) : '<span class="subtle">Solo lectura</span>'
                );
            }, $accessibleTenants);

            $content = $this->twig->render('backend/tenants/index.html.twig', [
                'feedback_html' => $feedbackHtml,
                'rows_html' => $rows !== [] ? implode('', $rows) : '<tr><td colspan="6" class="empty-row">No tienes negocios asignados.</td></tr>',
            ]);

            return $this->renderBackendShell('Selector de negocios', 'Elige un negocio activo para abrir su ficha.', 'tenants', $content);
        }

        $activeTenantId = $this->activeTenantContext->getActiveTenantId();
        $rows = array_map(function (Tenant $tenant) use ($activeTenantId): string {
            $contextSummary = $this->shortenListText($tenant->getBusinessContext(), 110, 'Sin contexto');
            $toneSummary = $this->shortenListText($tenant->getTone() ?? '', 36, 'Sin tono');
            $policySummary = $this->shortenListText($tenant->getSalesPolicySummary(), 130, 'Sin política comercial');
            $planSummary = $this->commercialPlanSummary($tenant->getCommercialPlan());
            $status = $tenant->isActive() ? '<span class="status-ok">Activo</span>' : '<span class="status-off">Inactivo</span>';
            $editUrl = sprintf('/backend/tenants/%s/edit', rawurlencode($tenant->getId()->toRfc4122()));
            $deleteUrl = sprintf('/backend/tenants/%s/delete', rawurlencode($tenant->getId()->toRfc4122()));
            $enterUrl = sprintf('/backend/tenants/%s/enter', rawurlencode($tenant->getId()->toRfc4122()));
            $isCurrentActive = $activeTenantId === $tenant->getId()->toRfc4122();

            return sprintf(
                '<tr>
                    <td><strong>%s</strong><div class="subtle">Contexto: %s</div><div class="subtle">Tono: %s</div></td>
                    <td><code>%s</code></td>
                    <td>Política: %s</td>
                    <td>Plan: %s</td>
                    <td>%s%s</td>
                    <td class="text-right">
                      <div style="display:inline-flex;align-items:center;gap:10px;flex-wrap:wrap;justify-content:flex-end">
                        <form method="post" action="%s" style="display:inline-flex;">
                          <input type="hidden" name="_csrf_token" value="%s">
                          <button class="secondary-action" type="submit" title="Entrar en el negocio" aria-label="Entrar en el negocio">%s</button>
                        </form>
                        <a class="secondary-action" href="/backend/super-admin/tenants/%s/ai" title="IA del tenant" aria-label="IA del tenant">IA</a>
                        <a class="icon-action" href="%s" title="Editar negocio" aria-label="Editar negocio">%s</a>
                        <form method="post" action="%s" onsubmit="return confirm(\'¿Eliminar este negocio?\');" style="display:inline-flex;">
                          <input type="hidden" name="_csrf_token" value="%s">
                          <button class="icon-action icon-action-danger icon-action-button" type="submit" title="Eliminar negocio" aria-label="Eliminar negocio">%s</button>
                        </form>
                      </div>
                    </td>
                  </tr>',
                htmlspecialchars($tenant->getName(), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($contextSummary, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($toneSummary, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($tenant->getSlug(), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($policySummary, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($planSummary, ENT_QUOTES, 'UTF-8'),
                $status,
                $isCurrentActive ? '<div class="subtle">Activo en sesión</div>' : '',
                htmlspecialchars($enterUrl, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($this->tenantTokenValue($enterUrl), ENT_QUOTES, 'UTF-8'),
                $isCurrentActive ? 'En sesión' : 'Entrar',
                htmlspecialchars($tenant->getId()->toRfc4122(), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8'),
                self::iconEditSvg(),
                htmlspecialchars($deleteUrl, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($this->tenantTokenValue($deleteUrl), ENT_QUOTES, 'UTF-8'),
                self::iconDeleteSvg()
            );
        }, $tenants ? $tenants->findAllOrdered() : []);

        $content = sprintf(
            '
            <section class="hero-panel">
              <div class="hero-copy">
                <div class="eyebrow-dark">Selector de negocio</div>
                <h2>Selecciona un negocio</h2>
                <p>Elige el negocio con el que vas a trabajar. Desde aquí entras a su ficha y cambias de contexto cuando lo necesites.</p>
              </div>
              <div class="hero-aside">
                <div class="badge-live">Admin</div>
                <div class="hero-aside-title">Entrada</div>
                <p>Este listado funciona como selector inicial y de cambio de negocio activo.</p>
              </div>
            </section>
            %s
            <section class="table-card">
              <div class="table-header">
                <div>
                  <h3>Negocios disponibles</h3>
                  <p>Selecciona un negocio para abrir su ficha activa.</p>
                </div>
                <a class="primary-action" href="/backend/tenants/new">Crear negocio</a>
              </div>
              <div class="table-responsive">
                <table>
                  <thead>
                    <tr><th>Negocio</th><th>Slug</th><th>Política comercial</th><th>Plan</th><th>Estado</th><th class="text-right">Acciones</th></tr>
                  </thead>
                  <tbody>%s</tbody>
                </table>
              </div>
            </section>
            ',
            $feedbackHtml,
            $rows !== [] ? implode('', $rows) : '<tr><td colspan="6" class="empty-row">No hay negocios todavía.</td></tr>'
        );

        $content = $this->twig->render('backend/tenants/index.html.twig', [
            'feedback_html' => $feedbackHtml,
            'rows_html' => $rows !== [] ? implode('', $rows) : '<tr><td colspan="6" class="empty-row">No hay negocios todavía.</td></tr>',
        ]);

        return $this->renderBackendShell('Selector de negocios', 'Elige un negocio activo para abrir su ficha.', 'tenants', $content);
    }

    #[Route('/tenants/{id}/enter', methods: ['POST'])]
    public function tenantEnter(string $id, Request $request, ?TenantRepository $tenants = null): Response
    {
        if (!$this->security->isGranted('ROLE_MANAGER')) {
            return new RedirectResponse('/backend/login');
        }

        if (!$tenants instanceof TenantRepository) {
            return new RedirectResponse('/backend/tenants');
        }

        $tenant = $tenants->find($id);
        if (!$tenant instanceof Tenant || !$tenant->isActive()) {
            $this->activeTenantContext->clear();
            $this->addFlashMessage($request, 'error', 'Selecciona un negocio activo para continuar.');

            return new RedirectResponse('/backend/tenants');
        }

        if (!$this->canAccessTenant($tenant)) {
            $this->activeTenantContext->clear();

            return new Response('', Response::HTTP_FORBIDDEN);
        }

        if (!$this->isValidTenantToken('/backend/tenants/'.$tenant->getId()->toRfc4122().'/enter', (string) $request->request->get('_csrf_token'))) {
            $this->addFlashMessage($request, 'error', 'La sesión del formulario ha expirado. Vuelve a intentarlo.');

            return new RedirectResponse('/backend/tenants');
        }

        $this->activeTenantContext->setActiveTenant($tenant);
        $this->addFlashMessage($request, 'success', sprintf('Negocio activo: %s.', $tenant->getName()));

        return new RedirectResponse('/backend/tenants/'.$tenant->getId()->toRfc4122().'/edit');
    }

    #[Route('/tenants/{id}/delete', methods: ['POST'])]
    public function tenantDelete(string $id, Request $request, ?TenantRepository $tenants = null): Response
    {
        if (!$this->security->isGranted('ROLE_SUPER_ADMIN')) {
            return new RedirectResponse('/backend/login');
        }

        if (!$tenants instanceof TenantRepository) {
            return new RedirectResponse('/backend/tenants');
        }

        $tenant = $tenants->find($id);
        if (!$tenant instanceof Tenant) {
            return new RedirectResponse('/backend/tenants');
        }

        $deleteUrl = '/backend/tenants/'.$tenant->getId()->toRfc4122().'/delete';
        if (!$this->isValidTenantToken($deleteUrl, (string) $request->request->get('_csrf_token'))) {
            return new RedirectResponse('/backend/tenants');
        }

        $this->entityManager->remove($tenant);
        $this->entityManager->flush();
        if ($this->activeTenantContext->getActiveTenantId() === $tenant->getId()->toRfc4122()) {
            $this->activeTenantContext->clear();
        }
        $this->addFlashMessage($request, 'success', 'Negocio eliminado.');

        return new RedirectResponse('/backend/tenants');
    }

    #[Route('/tenants/new', methods: ['GET', 'POST'])]
    public function tenantCreate(
        Request $request,
        ?TenantRepository $tenants = null,
        ?TenantAiUsagePolicyRepository $aiUsagePolicies = null,
        ?CommercialPlanRepository $commercialPlans = null,
    ): Response
    {
        if (!$this->security->isGranted('ROLE_SUPER_ADMIN')) {
            return new RedirectResponse('/backend/login');
        }

        $values = $this->tenantFormDefaults(null, null, null, null, $commercialPlans);
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isValidTenantToken('/backend/tenants/new', (string) $request->request->get('_csrf_token'))) {
                $error = 'La sesión del formulario ha expirado. Vuelve a intentarlo.';
            } else {
                $values = $this->tenantFormValuesFromRequest($request);
                $error = $this->validateTenantForm($values, null, $tenants, $commercialPlans);

                if ($error === null) {
                    $tenant = new Tenant();
                    $this->hydrateTenantFromForm($tenant, $values, $commercialPlans);
                    $this->entityManager->persist($tenant);
                    $this->persistTenantAiUsagePolicy($tenant, $values, $aiUsagePolicies, false);
                    $this->entityManager->flush();

                    if ($tenant->isActive()) {
                        $this->activeTenantContext->setActiveTenant($tenant);
                    }

                    return new RedirectResponse('/backend/tenants/'.$tenant->getId()->toRfc4122().'/edit');
                }
            }
        }

        return $this->renderTenantForm(
            'Crear negocio',
            'Alta de un nuevo contexto comercial. Define nombre, contexto, tono y política comercial inicial.',
            'Crear negocio',
            'Crear negocio',
            '/backend/tenants/new',
            $values,
            $error,
            [],
            'tenants',
            $this->commercialPlanOptionsWithCurrent($commercialPlans, $values['commercialPlanId'] ?? ''),
            $this->subscriptionStatusOptions(),
            $this->commercialPlanSummaryFromValue($commercialPlans, $values['commercialPlanId'] ?? '')
        );
    }

    #[Route('/tenants/{id}/edit', methods: ['GET', 'POST'])]
    public function tenantEdit(
        string $id,
        Request $request,
        ?TenantRepository $tenants = null,
        ?TenantAiUsagePolicyRepository $aiUsagePolicies = null,
        ?AiUsageEventRepository $aiUsageEvents = null,
        ?CommercialPlanRepository $commercialPlans = null,
    ): Response
    {
        if (!$this->security->isGranted('ROLE_MANAGER')) {
            return new RedirectResponse('/backend/login');
        }

        if (!$tenants instanceof TenantRepository) {
            return new RedirectResponse('/backend/tenants');
        }

        $tenant = $tenants->find($id);
        if (!$tenant instanceof Tenant) {
            return new RedirectResponse('/backend/tenants');
        }

        if (!$this->canManageTenant($tenant)) {
            return new Response('', Response::HTTP_FORBIDDEN);
        }

        $aiUsagePolicy = $this->loadTenantAiUsagePolicy($tenant, $aiUsagePolicies);
        $aiUsageEventsRepository = $aiUsageEvents ?? $this->aiUsageEvents;
        $aiUsageData = $this->tenantAiUsageDisplayData($tenant, $aiUsageEventsRepository);
        $aiUsageTokenRate = $this->tenantAiUsageTokenRate($aiUsagePolicy);
        $values = $this->tenantFormDefaults($tenant, $aiUsagePolicy, $aiUsageEventsRepository, $aiUsageTokenRate, $commercialPlans);
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isValidTenantToken('/backend/tenants/'.$tenant->getId()->toRfc4122().'/edit', (string) $request->request->get('_csrf_token'))) {
                $error = 'La sesión del formulario ha expirado. Vuelve a intentarlo.';
            } else {
                $values = $this->tenantFormValuesFromRequest($request, $tenant);
                $error = $this->validateTenantForm($values, $tenant, $tenants, $commercialPlans);
                if ($error === null) {
                    $error = $this->validateTenantAiUsagePolicyForm($values);
                }

                if ($error === null) {
                    $this->hydrateTenantFromForm($tenant, $values, $commercialPlans);
                    $this->persistTenantAiUsagePolicy($tenant, $values, $aiUsagePolicies, false, $aiUsagePolicy, $aiUsageTokenRate);
                    $this->entityManager->persist($tenant);
                    $this->entityManager->flush();

                    if ($tenant->isActive()) {
                        $this->activeTenantContext->setActiveTenant($tenant);
                    } elseif ($this->activeTenantContext->getActiveTenantId() === $tenant->getId()->toRfc4122()) {
                        $this->activeTenantContext->clear();
                    }

                    return new RedirectResponse('/backend/tenants/'.$tenant->getId()->toRfc4122().'/edit');
                }
            }
        }

        return $this->renderTenantForm(
            'Ficha del negocio',
            'Ajusta el contexto comercial, el tono, la política de venta y los límites de uso IA del negocio activo.',
            'Ficha del negocio',
            'Guardar cambios',
            '/backend/tenants/'.$tenant->getId()->toRfc4122().'/edit',
            $values,
            $error,
            $aiUsageData,
            'tenant',
            $this->commercialPlanOptionsWithCurrent($commercialPlans, $values['commercialPlanId'] ?? ''),
            $this->subscriptionStatusOptions(),
            $this->commercialPlanSummaryFromValue($commercialPlans, $values['commercialPlanId'] ?? '')
        );
    }

    #[Route('/ai-usage', methods: ['GET'])]
    public function aiUsage(
        Request $request,
        ?TenantAiUsagePolicyRepository $aiUsagePolicies = null,
        ?AiUsageEventRepository $aiUsageEvents = null,
        ?TenantAiTopUpRequestRepository $topUpRequests = null,
    ): Response {
        if (!$this->security->isGranted('ROLE_MANAGER')) {
            return new RedirectResponse('/backend/login');
        }

        $activeTenant = $this->resolvedActiveTenantForCurrentUser();
        if (!$activeTenant instanceof Tenant) {
            return $this->renderTenantSelectionRequiredPage(
                'Uso IA',
                'Selecciona un negocio antes de consultar consumo, límites o solicitudes de ampliación.',
                'ai-usage',
                'Uso IA'
            );
        }

        return $this->renderAiUsageDashboardPage(
            $request,
            $activeTenant,
            $this->tenantAiUsageTopUpRequestFormDefaults(),
            [],
            $aiUsagePolicies,
            $aiUsageEvents,
            $topUpRequests
        );
    }

    #[Route('/ai-usage/top-up-requests', methods: ['POST'])]
    public function aiUsageTopUpRequestCreate(
        Request $request,
        ?TenantAiUsagePolicyRepository $aiUsagePolicies = null,
        ?AiUsageEventRepository $aiUsageEvents = null,
        ?TenantAiTopUpRequestRepository $topUpRequests = null,
    ): Response {
        if (!$this->security->isGranted('ROLE_MANAGER')) {
            return new RedirectResponse('/backend/login');
        }

        $activeTenant = $this->resolvedActiveTenantForCurrentUser();
        if (!$activeTenant instanceof Tenant) {
            return $this->renderTenantSelectionRequiredPage(
                'Uso IA',
                'Selecciona un negocio antes de enviar una solicitud de ampliación.',
                'ai-usage',
                'Uso IA'
            );
        }

        $values = $this->tenantAiUsageTopUpRequestFormValuesFromRequest($request);
        $errors = [];

        if (!$this->isValidTenantAiUsageTopUpRequestToken((string) $request->request->get('_csrf_token'))) {
            $errors[] = 'La sesión del formulario ha expirado. Vuelve a intentarlo.';
        } else {
            $errors = $this->validateTenantAiUsageTopUpRequestForm($values);

            if ($errors === []) {
                $requestedAmount = (int) $values['requestedTokens'];
                $message = (string) $values['message'];
                $requestEntity = new TenantAiTopUpRequest($activeTenant, $requestedAmount, $message);
                $currentUser = $this->currentUser();
                if ($currentUser instanceof User) {
                    $requestEntity->setRequestedBy($currentUser);
                }

                if ($topUpRequests instanceof TenantAiTopUpRequestRepository) {
                    $topUpRequests->save($requestEntity);
                } else {
                    $this->entityManager->persist($requestEntity);
                    $this->entityManager->flush();
                }

                $this->addFlashMessage($request, 'success', 'Solicitud de ampliación enviada y marcada como pendiente.');

                return new RedirectResponse('/backend/ai-usage');
            }
        }

        return $this->renderAiUsageDashboardPage(
            $request,
            $activeTenant,
            $values,
            $errors,
            $aiUsagePolicies,
            $aiUsageEvents,
            $topUpRequests
        );
    }

    #[Route('/super-admin/tenants/{id}/ai', methods: ['GET', 'POST'])]
    public function superAdminTenantAi(
        string $id,
        Request $request,
        ?TenantRepository $tenants = null,
        ?TenantAiUsagePolicyRepository $aiUsagePolicies = null,
        ?AiUsageEventRepository $aiUsageEvents = null,
        ?TenantAiTopUpRequestRepository $topUpRequests = null,
    ): Response {
        if (!$this->isSuperAdmin()) {
            return new RedirectResponse('/backend/dashboard');
        }

        $tenant = $this->resolveTenantForSuperAdminAi($id, $tenants);
        if (!$tenant instanceof Tenant) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $policy = $this->loadTenantAiUsagePolicyForView($tenant, $aiUsagePolicies);
        $aiUsageEventsRepository = $aiUsageEvents ?? $this->aiUsageEvents;
        $aiUsageTokenRate = $this->tenantAiUsageTokenRate($policy);
        $values = $this->tenantAiUsagePolicyValues($policy, $aiUsageTokenRate);
        $errors = [];

        if ($request->isMethod('POST')) {
            $actionUrl = '/backend/super-admin/tenants/'.$tenant->getId()->toRfc4122().'/ai';
            if (!$this->isValidTenantAiSuperAdminToken($actionUrl, (string) $request->request->get('_csrf_token'))) {
                $errors[] = 'La sesión del formulario ha expirado. Vuelve a intentarlo.';
            } else {
                $values = $this->tenantAiUsagePolicyFormValuesFromRequest($request);
                $validationError = $this->validateTenantAiUsagePolicyForm($values);
                if ($validationError !== null) {
                    $errors[] = $validationError;
                }

                if ($errors === []) {
                    $this->persistTenantAiUsagePolicy($tenant, $values, $aiUsagePolicies, true, $policy, $aiUsageTokenRate);
                    $this->addFlashMessage($request, 'success', sprintf('IA del tenant actualizada para %s.', $tenant->getName()));

                    return new RedirectResponse($actionUrl);
                }
            }
        }

        return $this->renderSuperAdminTenantAiPage(
            $request,
            $tenant,
            $values,
            $errors,
            $policy,
            $aiUsagePolicies,
            $aiUsageEvents,
            $topUpRequests
        );
    }

    #[Route('/super-admin/tenants/{tenantId}/ai/top-up-requests/{requestId}/approve', methods: ['POST'])]
    public function superAdminTenantAiTopUpRequestApprove(
        string $tenantId,
        string $requestId,
        Request $request,
        ?TenantRepository $tenants = null,
        ?TenantAiTopUpRequestRepository $topUpRequests = null,
        ?TenantAiUsagePolicyRepository $aiUsagePolicies = null,
        ?AiUsageEventRepository $aiUsageEvents = null,
    ): Response {
        if (!$this->isSuperAdmin()) {
            return new RedirectResponse('/backend/dashboard');
        }

        $tenant = $this->resolveTenantForSuperAdminAi($tenantId, $tenants);
        if (!$tenant instanceof Tenant) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $actionUrl = '/backend/super-admin/tenants/'.$tenant->getId()->toRfc4122().'/ai/top-up-requests/'.$requestId.'/approve';
        if (!$this->isValidTenantAiSuperAdminToken($actionUrl, (string) $request->request->get('_csrf_token'))) {
            $this->addFlashMessage($request, 'error', 'La sesión del formulario ha expirado. Vuelve a intentarlo.');

            return new RedirectResponse('/backend/super-admin/tenants/'.$tenant->getId()->toRfc4122().'/ai');
        }

        $requestEntity = $this->resolveTenantAiTopUpRequestForTenant($tenant, $requestId, $topUpRequests);
        if (!$requestEntity instanceof TenantAiTopUpRequest) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        if ($requestEntity->getStatus() !== TenantAiTopUpRequest::STATUS_PENDING) {
            $this->addFlashMessage($request, 'error', 'La solicitud ya no está pendiente.');

            return new RedirectResponse('/backend/super-admin/tenants/'.$tenant->getId()->toRfc4122().'/ai');
        }

        $currentUser = $this->currentUser();
        if (!$currentUser instanceof User) {
            return new RedirectResponse('/backend/dashboard');
        }

        $approvedTokens = $this->parseCommercialBlockAmount($request->request->get('approvedTokens', (string) round($requestEntity->getRequestedAmountEur())));
        if ($approvedTokens === null) {
            $this->addFlashMessage($request, 'error', 'Indica una cantidad de tokens aprobada válida.');

            return new RedirectResponse('/backend/super-admin/tenants/'.$tenant->getId()->toRfc4122().'/ai');
        }

        $flashMessage = $this->applyTenantAiTopUpRequestApproval(
            $tenant,
            $requestEntity,
            $approvedTokens,
            $currentUser
        );

        $this->entityManager->persist($requestEntity);
        $this->entityManager->flush();

        $this->addFlashMessage($request, 'success', $flashMessage);

        return new RedirectResponse('/backend/super-admin/tenants/'.$tenant->getId()->toRfc4122().'/ai');
    }

    #[Route('/super-admin/tenants/{tenantId}/ai/top-up-requests/{requestId}/reject', methods: ['POST'])]
    public function superAdminTenantAiTopUpRequestReject(
        string $tenantId,
        string $requestId,
        Request $request,
        ?TenantRepository $tenants = null,
        ?TenantAiTopUpRequestRepository $topUpRequests = null,
        ?TenantAiUsagePolicyRepository $aiUsagePolicies = null,
    ): Response {
        if (!$this->isSuperAdmin()) {
            return new RedirectResponse('/backend/dashboard');
        }

        $tenant = $this->resolveTenantForSuperAdminAi($tenantId, $tenants);
        if (!$tenant instanceof Tenant) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $actionUrl = '/backend/super-admin/tenants/'.$tenant->getId()->toRfc4122().'/ai/top-up-requests/'.$requestId.'/reject';
        if (!$this->isValidTenantAiSuperAdminToken($actionUrl, (string) $request->request->get('_csrf_token'))) {
            $this->addFlashMessage($request, 'error', 'La sesión del formulario ha expirado. Vuelve a intentarlo.');

            return new RedirectResponse('/backend/super-admin/tenants/'.$tenant->getId()->toRfc4122().'/ai');
        }

        $requestEntity = $this->resolveTenantAiTopUpRequestForTenant($tenant, $requestId, $topUpRequests);
        if (!$requestEntity instanceof TenantAiTopUpRequest) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        if ($requestEntity->getStatus() !== TenantAiTopUpRequest::STATUS_PENDING) {
            $this->addFlashMessage($request, 'error', 'La solicitud ya no está pendiente.');

            return new RedirectResponse('/backend/super-admin/tenants/'.$tenant->getId()->toRfc4122().'/ai');
        }

        $currentUser = $this->currentUser();
        if (!$currentUser instanceof User) {
            return new RedirectResponse('/backend/dashboard');
        }

        $requestEntity->reject($currentUser);
        $this->entityManager->persist($requestEntity);
        $this->entityManager->flush();

        $this->addFlashMessage($request, 'success', sprintf('Solicitud de ampliación rechazada para %s.', $tenant->getName()));

        return new RedirectResponse('/backend/super-admin/tenants/'.$tenant->getId()->toRfc4122().'/ai');
    }

    #[Route('/users', methods: ['GET'])]
    public function users(): Response
    {
        if (!$this->security->isGranted('ROLE_SUPER_ADMIN')) {
            return new RedirectResponse('/backend/dashboard');
        }

        /** @var \Doctrine\Persistence\ObjectRepository<User> $users */
        $users = $this->entityManager->getRepository(User::class);
        $rows = array_map(
            static function (User $user): array {
                $tenantNames = array_map(
                    static fn (Tenant $tenant): string => $tenant->getName(),
                    $user->getAccessibleTenants()
                );

                return [
                    'email' => $user->getEmail(),
                    'roles' => implode(', ', array_map(static fn (string $role): string => strtoupper($role), $user->getRoles())),
                    'tenants' => $tenantNames !== [] ? implode(', ', $tenantNames) : 'Sin tenants',
                    'status_label' => $user->isActive() ? 'Activo' : 'Inactivo',
                    'status_class' => $user->isActive() ? 'status-ok' : 'status-off',
                    'created_at' => $user->getCreatedAt()->format('Y-m-d H:i'),
                    'login_label' => $user->isActive() ? 'Login ok' : 'Sin acceso',
                ];
            },
            $users->findBy([], ['createdAt' => 'DESC']) ?? []
        );

        return $this->renderUsersPage($rows);
    }

    #[Route('/users/new', methods: ['GET', 'POST'])]
    public function userCreate(
        Request $request,
        ?UserRepository $users = null,
        ?TenantRepository $tenants = null,
    ): Response {
        if (!$this->security->isGranted('ROLE_SUPER_ADMIN')) {
            return new RedirectResponse('/backend/dashboard');
        }

        $values = $this->userCreateFormDefaults();
        $errors = [];
        $availableTenants = $this->activeTenantsForUserCreation($tenants);
        $userRepository = $users ?? $this->entityManager->getRepository(User::class);

        if ($request->isMethod('POST')) {
            if (!$this->isValidUserCreateToken((string) $request->request->get('_csrf_token'))) {
                $errors[] = 'La sesión del formulario ha expirado. Vuelve a intentarlo.';
            } else {
                $values = $this->userCreateFormValuesFromRequest($request);
                $errors = $this->validateUserCreateForm($values, $userRepository, $availableTenants);

                if ($errors === []) {
                    $user = new User((string) $values['email'], [(string) $values['role']]);
                    $user->setPassword($this->passwordHasher->hashPassword($user, (string) $values['password']));
                    $user->setActive((bool) $values['isActive']);

                    $this->entityManager->persist($user);
                    foreach ($this->selectedTenantsForUserCreate((array) $values['tenantIds'], $availableTenants) as $tenant) {
                        $membership = new TenantMembership($user, $tenant, (string) $values['membershipRole']);
                        $membership->setActive(true);
                        $this->entityManager->persist($membership);
                    }
                    $this->entityManager->flush();

                    $this->addFlashMessage($request, 'success', sprintf('Usuario creado: %s.', $user->getEmail()));

                    return new RedirectResponse('/backend/users');
                }
            }
        }

        $errorHtml = '';
        foreach ($errors as $error) {
            $errorHtml .= $this->renderDismissibleAlert('alert-error', htmlspecialchars($error, ENT_QUOTES, 'UTF-8'));
        }

        $content = $this->twig->render('backend/users/new.html.twig', [
            'hero_title' => 'Nuevo usuario',
            'page_subtitle' => 'Crea una cuenta global y asigna acceso a uno o varios tenants activos.',
            'error_html' => $errorHtml,
            'action_url' => '/backend/users/new',
            'csrf_token' => $this->userCreateTokenValue(),
            'values' => $values,
            'available_tenants' => array_map(
                static fn (Tenant $tenant): array => [
                    'id' => $tenant->getId()->toRfc4122(),
                    'name' => $tenant->getName(),
                    'slug' => $tenant->getSlug(),
                ],
                $availableTenants
            ),
            'role_options' => [
                ['value' => 'agent', 'label' => 'agent'],
                ['value' => 'manager', 'label' => 'manager'],
                ['value' => 'admin', 'label' => 'admin'],
                ['value' => 'super_admin', 'label' => 'super_admin'],
            ],
            'membership_role_options' => [
                ['value' => 'manager', 'label' => 'manager'],
                ['value' => 'editor', 'label' => 'editor'],
                ['value' => 'viewer', 'label' => 'viewer'],
                ['value' => 'agent', 'label' => 'agent'],
            ],
        ]);

        return $this->renderBackendShell('Nuevo usuario', 'Alta de una cuenta global con memberships de tenant.', 'admin-users', $content);
    }

    #[Route('/products', methods: ['GET'])]
    public function products(Request $request, ?ProductRepository $products = null, ?TenantRepository $tenants = null): Response
    {
        if (!$this->security->isGranted('ROLE_MANAGER')) {
            return new RedirectResponse('/backend/login');
        }

        $activeTenant = $this->resolvedActiveTenantForCurrentUser();
        if (!$activeTenant instanceof Tenant) {
            return $this->renderTenantSelectionRequiredPage(
                'Productos / servicios',
                'Selecciona un negocio antes de gestionar el catálogo comercial.',
                'products',
                'Productos / servicios'
            );
        }

        $feedbackHtml = $this->renderProfileFeedback($request);
        $productFilter = trim((string) $request->query->get('product', ''));
        $rows = array_map(function (Product $product): string {
            $status = $product->isActive() ? '<span class="status-ok">Activo</span>' : '<span class="status-off">Inactivo</span>';
            $editUrl = sprintf('/backend/products/%s/edit', rawurlencode($product->getId()->toRfc4122()));
            $deleteUrl = sprintf('/backend/products/%s/delete', rawurlencode($product->getId()->toRfc4122()));
            $identity = sprintf(
                '<div class="subtle">Slug: %s</div><div class="subtle">External: %s%s</div><div class="subtle">Precio: %s</div>',
                htmlspecialchars($product->getSlug(), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($product->getExternalSource() ?? 'local', ENT_QUOTES, 'UTF-8'),
                $product->getExternalReference() !== null ? ' / '.htmlspecialchars($product->getExternalReference(), ENT_QUOTES, 'UTF-8') : '',
                htmlspecialchars($product->getBasePriceCents() !== null ? (string) $product->getBasePriceCents().' '.($product->getCurrency() ?? '') : 'Sin precio', ENT_QUOTES, 'UTF-8')
            );

            return sprintf(
                '<tr>
                    <td><strong>%s</strong><div class="subtle">%s</div></td>
                    <td>%s</td>
                    <td>%s</td>
                    <td>%s</td>
                    <td class="text-right">
                      <div style="display:inline-flex;align-items:center;gap:10px;flex-wrap:wrap;justify-content:flex-end">
                        <a class="icon-action" href="%s" title="Editar producto / servicio" aria-label="Editar producto / servicio">%s</a>
                        <form method="post" action="%s" onsubmit="return confirm(\'¿Eliminar este producto / servicio?\');" style="display:inline-flex;">
                          <input type="hidden" name="_csrf_token" value="%s">
                          <button class="icon-action icon-action-danger icon-action-button" type="submit" title="Eliminar producto / servicio" aria-label="Eliminar producto / servicio">%s</button>
                        </form>
                      </div>
                    </td>
                  </tr>',
                htmlspecialchars($product->getName(), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($product->getDescription(), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($product->getTenant()->getName(), ENT_QUOTES, 'UTF-8'),
                $identity,
                $status,
                htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8'),
                self::iconEditSvg(),
                htmlspecialchars($deleteUrl, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($this->productTokenValue($deleteUrl), ENT_QUOTES, 'UTF-8'),
                self::iconDeleteSvg()
            );
        }, array_values(array_filter($products ? $products->findByTenantOrdered($activeTenant) : [], function (Product $product) use ($productFilter): bool {
            if ($productFilter === '') {
                return true;
            }

            $haystack = mb_strtolower(trim(implode(' ', array_filter([
                $product->getName(),
                $product->getSlug(),
                $product->getExternalSource() ?? '',
                $product->getExternalReference() ?? '',
                $product->getDescription(),
            ]))));

            return mb_stripos($haystack, mb_strtolower($productFilter)) !== false;
        })));

        $content = $this->twig->render('backend/products/index.html.twig', [
            'feedback_html' => $feedbackHtml,
            'product_filter' => $productFilter,
            'rows_html' => $rows !== [] ? implode('', $rows) : '<tr><td colspan="5" class="empty-row">No hay productos o servicios todavía.</td></tr>',
            'active_tenant_name' => $activeTenant->getName(),
        ]);

        return $this->renderBackendShell(sprintf('Productos / servicios de %s', $activeTenant->getName()), 'Catálogo comercial del negocio activo.', 'products', $content);
    }

    #[Route('/products/import', methods: ['GET', 'POST'])]
    public function productImport(Request $request, ?TenantRepository $tenants = null): Response
    {
        if (!$this->security->isGranted('ROLE_MANAGER')) {
            return new RedirectResponse('/backend/login');
        }

        $activeTenant = $this->resolvedActiveTenantForCurrentUser();
        if (!$activeTenant instanceof Tenant) {
            return $this->renderTenantSelectionRequiredPage(
                'Importar catálogo',
                'Selecciona un negocio antes de importar productos o servicios.',
                'products',
                'Productos / servicios'
            );
        }

        $values = $this->productImportFormDefaults($activeTenant);
        $error = null;
        $result = null;

        if ($request->isMethod('POST')) {
            if (!$this->isValidProductToken('/backend/products/import', (string) $request->request->get('_csrf_token'))) {
                $error = 'La sesión del formulario ha expirado. Vuelve a intentarlo.';
            } else {
                $values = $this->productImportFormValuesFromRequest($request);
                $error = $this->validateProductImportForm($values, $activeTenant);

                if ($error === null) {
                    $payload = $this->productImportPayloadFromRequest($request);

                    if ($payload === null || trim($payload) === '') {
                        $error = 'Debes pegar un CSV/JSON o subir un archivo con el catálogo.';
                    } elseif (!$this->productCatalogImportService instanceof ProductCatalogImportService) {
                        $error = 'El servicio de importación no está disponible.';
                    } else {
                        $result = $this->productCatalogImportService->import($activeTenant, $payload, $values['format']);
                    }
                }
            }
        }

        return $this->renderProductImportForm(
            'Importar catálogo de productos / servicios',
            'Carga un archivo o pega su contenido para incorporar productos / servicios al catálogo del negocio.',
            'Importar productos / servicios',
            'Importar productos / servicios',
            '/backend/products/import',
            $values,
            $result,
            $error
        );
    }

    #[Route('/products/new', methods: ['GET', 'POST'])]
    public function productCreate(Request $request, ?TenantRepository $tenants = null, ?ProductRepository $products = null): Response
    {
        if (!$this->security->isGranted('ROLE_MANAGER')) {
            return new RedirectResponse('/backend/login');
        }

        $activeTenant = $this->resolvedActiveTenantForCurrentUser();
        if (!$activeTenant instanceof Tenant) {
            return $this->renderTenantSelectionRequiredPage(
                'Crear producto / servicio',
                'Selecciona un negocio antes de crear una nueva propuesta comercial.',
                'products',
                'Productos / servicios'
            );
        }

        $values = $this->productFormDefaults(null, $activeTenant);
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isValidProductToken('/backend/products/new', (string) $request->request->get('_csrf_token'))) {
                $error = 'La sesión del formulario ha expirado. Vuelve a intentarlo.';
            } else {
                $values = $this->productFormValuesFromRequest($request);
                $error = $this->validateProductForm($values, null, $activeTenant, $products);

                if ($error === null) {
                    try {
                        if ($this->planUsageGuard instanceof PlanUsageGuard) {
                            $this->planUsageGuard->assertCanCreateProduct($activeTenant);
                        }

                        $product = new Product($activeTenant, $values['name']);
                        $this->hydrateProductFromForm($product, $values, $activeTenant);
                        $this->entityManager->persist($product);
                        $this->entityManager->flush();

                        return new RedirectResponse('/backend/products');
                    } catch (PlanLimitExceededException $exception) {
                        $error = $exception->getMessage();
                    }
                }
            }
        }

        return $this->renderProductForm(
            'Crear producto / servicio',
            sprintf('Define la oferta, la propuesta de valor y la política comercial específica de %s.', $activeTenant->getName()),
            'Crear producto / servicio',
            'Crear producto / servicio',
            '/backend/products/new',
            $values,
            null,
            $error
        );
    }

    #[Route('/products/{id}/edit', methods: ['GET', 'POST'])]
    public function productEdit(string $id, Request $request, ?TenantRepository $tenants = null, ?ProductRepository $products = null): Response
    {
        if (!$this->security->isGranted('ROLE_MANAGER')) {
            return new RedirectResponse('/backend/login');
        }

        $activeTenant = $this->resolvedActiveTenantForCurrentUser();
        if (!$activeTenant instanceof Tenant) {
            return $this->renderTenantSelectionRequiredPage(
                'Editar producto / servicio',
                'Selecciona un negocio antes de editar el catálogo comercial.',
                'products',
                'Productos / servicios'
            );
        }

        if (!$products instanceof ProductRepository) {
            return new RedirectResponse('/backend/products');
        }

        $product = $products->find($id);
        if (!$product instanceof Product || $product->getTenant()->getId()->toRfc4122() !== $activeTenant->getId()->toRfc4122()) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $values = $this->productFormDefaults($product, $activeTenant);
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isValidProductToken('/backend/products/'.$product->getId()->toRfc4122().'/edit', (string) $request->request->get('_csrf_token'))) {
                $error = 'La sesión del formulario ha expirado. Vuelve a intentarlo.';
            } else {
                $values = $this->productFormValuesFromRequest($request);
                $error = $this->validateProductForm($values, $product, $activeTenant, $products);

                if ($error === null) {
                    $this->hydrateProductFromForm($product, $values, $activeTenant);
                    $this->entityManager->persist($product);
                    $this->entityManager->flush();

                    return new RedirectResponse('/backend/products');
                }
            }
        }

        return $this->renderProductForm(
            'Editar producto / servicio',
            sprintf('Ajusta el producto, su propuesta de valor y la política comercial que lo acompaña en %s.', $activeTenant->getName()),
            'Editar producto / servicio',
            'Guardar cambios',
            '/backend/products/'.$product->getId()->toRfc4122().'/edit',
            $values,
            $error
        );
    }

    #[Route('/products/{id}/delete', methods: ['POST'])]
    public function productDelete(string $id, Request $request, ?ProductRepository $products = null): Response
    {
        if (!$this->security->isGranted('ROLE_MANAGER')) {
            return new RedirectResponse('/backend/login');
        }

        if (!$products instanceof ProductRepository) {
            return new RedirectResponse('/backend/products');
        }

        $product = $products->find($id);
        $activeTenant = $this->resolvedActiveTenantForCurrentUser();
        if (!$product instanceof Product || !$activeTenant instanceof Tenant || $product->getTenant()->getId()->toRfc4122() !== $activeTenant->getId()->toRfc4122()) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        if (!$this->isValidProductToken('/backend/products/'.$product->getId()->toRfc4122().'/delete', (string) $request->request->get('_csrf_token'))) {
            return new RedirectResponse('/backend/products/'.$product->getId()->toRfc4122().'/edit');
        }

        $this->entityManager->remove($product);
        $this->entityManager->flush();
        $this->addFlashMessage($request, 'success', 'Producto / servicio eliminado.');

        return new RedirectResponse('/backend/products');
    }

    #[Route('/entry-points', methods: ['GET'])]
    public function entryPoints(?EntryPointRepository $entryPoints = null): Response
    {
        if (!$this->security->isGranted('ROLE_MANAGER')) {
            return new RedirectResponse('/backend/login');
        }

        $activeTenant = $this->resolvedActiveTenantForCurrentUser();
        if (!$activeTenant instanceof Tenant) {
            return $this->renderTenantSelectionRequiredPage(
                'Puntos de entrada',
                'Selecciona un negocio antes de gestionar campañas y rutas públicas.',
                'entry-points',
                'Puntos de entrada'
            );
        }

        $rows = array_map(static function (EntryPoint $entryPoint): string {
            $status = $entryPoint->isActive() ? '<span class="status-ok">Activo</span>' : '<span class="status-off">Inactivo</span>';
            $detailUrl = sprintf('/backend/entry-points/%s', rawurlencode($entryPoint->getId()->toRfc4122()));
            $editUrl = sprintf('/backend/entry-points/%s/edit', rawurlencode($entryPoint->getId()->toRfc4122()));

            return sprintf(
                '<tr>
                    <td><strong>%s</strong><div class="subtle">%s</div></td>
                    <td><code>%s</code></td>
                    <td>%s</td>
                    <td>%s</td>
                    <td>%s</td>
                    <td class="text-right"><a class="icon-action" href="%s" title="Ver punto de entrada" aria-label="Ver punto de entrada">%s</a> <a class="icon-action" href="%s" title="Editar punto de entrada" aria-label="Editar punto de entrada">%s</a></td>
                  </tr>',
                htmlspecialchars($entryPoint->getName(), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($entryPoint->getCode(), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($entryPoint->getCode(), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($entryPoint->getTenant()->getName(), ENT_QUOTES, 'UTF-8'),
                $entryPoint->getProduct() ? htmlspecialchars($entryPoint->getProduct()->getName(), ENT_QUOTES, 'UTF-8') : 'Sin producto',
                $status,
                htmlspecialchars($detailUrl, ENT_QUOTES, 'UTF-8'),
                self::iconDetailSvg(),
                htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8'),
                self::iconEditSvg()
            );
        }, $entryPoints ? $entryPoints->findByTenantOrdered($activeTenant) : []);

        $content = $this->twig->render('backend/entry_points/index.html.twig', [
            'rows_html' => $rows !== [] ? implode('', $rows) : '<tr><td colspan="6" class="empty-row">No hay puntos de entrada todavía.</td></tr>',
            'active_tenant_name' => $activeTenant->getName(),
        ]);

        return $this->renderBackendShell(sprintf('Puntos de entrada de %s', $activeTenant->getName()), 'Códigos de campaña y enlaces públicos del negocio activo.', 'entry-points', $content);
    }

    #[Route('/entry-points/new', methods: ['GET', 'POST'])]
    public function entryPointCreate(Request $request, ?ProductRepository $products = null, ?PlaybookRepository $playbooks = null, ?EntryPointRepository $entryPoints = null): Response
    {
        if (!$this->security->isGranted('ROLE_MANAGER')) {
            return new RedirectResponse('/backend/login');
        }

        $activeTenant = $this->resolvedActiveTenantForCurrentUser();
        if (!$activeTenant instanceof Tenant) {
            return $this->renderTenantSelectionRequiredPage(
                'Crear punto de entrada',
                'Selecciona un negocio antes de definir una campaña o enlace público.',
                'entry-points',
                'Puntos de entrada'
            );
        }

        $values = $this->entryPointFormDefaults();
        $values['tenantName'] = $activeTenant->getName();
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isValidEntryPointToken('/backend/entry-points/new', (string) $request->request->get('_csrf_token'))) {
                $error = 'La sesión del formulario ha expirado. Vuelve a intentarlo.';
            } else {
                $values = $this->entryPointFormValuesFromRequest($request);
                $error = $this->validateEntryPointForm($values, null, $activeTenant, $products, $playbooks, $entryPoints);

                if ($error === null) {
                    $product = $products instanceof ProductRepository ? $products->find($values['productId']) : null;
                    if (!$product instanceof Product || $product->getTenant()->getId()->toRfc4122() !== $activeTenant->getId()->toRfc4122()) {
                        $error = 'El producto seleccionado no pertenece al negocio activo.';
                    } else {
                        try {
                            if ($this->planUsageGuard instanceof PlanUsageGuard) {
                                $this->planUsageGuard->assertCanCreateEntryPoint($activeTenant);
                            }

                            $entryPoint = new EntryPoint($product, $values['code'], $values['name']);
                            $this->hydrateEntryPointFromForm($entryPoint, $values, $product, $playbooks);
                            $this->entityManager->persist($entryPoint);
                            $this->entityManager->flush();

                            return new RedirectResponse('/backend/entry-points');
                        } catch (PlanLimitExceededException $exception) {
                            $error = $exception->getMessage();
                        }
                    }
                }
            }
        }

        return $this->renderEntryPointForm(
            'Crear punto de entrada',
            sprintf('Define el código público y su contexto comercial antes de crear tráfico en %s.', $activeTenant->getName()),
            'Crear punto de entrada',
            'Crear punto de entrada',
            '/backend/entry-points/new',
            $values,
            $products,
            $playbooks,
            $error
        );
    }

    #[Route('/entry-points/{id}/edit', methods: ['GET', 'POST'])]
    public function entryPointEdit(string $id, Request $request, ?ProductRepository $products = null, ?PlaybookRepository $playbooks = null, ?EntryPointRepository $entryPoints = null): Response
    {
        if (!$this->security->isGranted('ROLE_MANAGER')) {
            return new RedirectResponse('/backend/login');
        }

        $activeTenant = $this->resolvedActiveTenantForCurrentUser();
        if (!$activeTenant instanceof Tenant) {
            return $this->renderTenantSelectionRequiredPage(
                'Editar punto de entrada',
                'Selecciona un negocio antes de editar este punto de entrada.',
                'entry-points',
                'Puntos de entrada'
            );
        }

        if (!$entryPoints instanceof EntryPointRepository) {
            return new RedirectResponse('/backend/entry-points');
        }

        $entryPoint = $entryPoints->find($id);
        if (!$entryPoint instanceof EntryPoint || $entryPoint->getTenant()->getId()->toRfc4122() !== $activeTenant->getId()->toRfc4122()) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $values = $this->entryPointFormDefaults($entryPoint);
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isValidEntryPointToken('/backend/entry-points/'.$entryPoint->getId()->toRfc4122().'/edit', (string) $request->request->get('_csrf_token'))) {
                $error = 'La sesión del formulario ha expirado. Vuelve a intentarlo.';
            } else {
                $values = $this->entryPointFormValuesFromRequest($request);
                $error = $this->validateEntryPointForm($values, $entryPoint, $activeTenant, $products, $playbooks, $entryPoints);

                if ($error === null) {
                    $product = $products instanceof ProductRepository ? $products->find($values['productId']) : $entryPoint->getProduct();
                    if (!$product instanceof Product || $product->getTenant()->getId()->toRfc4122() !== $activeTenant->getId()->toRfc4122()) {
                        $error = 'El producto seleccionado no pertenece al negocio activo.';
                    } else {
                        $this->hydrateEntryPointFromForm($entryPoint, $values, $product, $playbooks);
                        $this->entityManager->persist($entryPoint);
                        $this->entityManager->flush();

                        return new RedirectResponse('/backend/entry-points/'.$entryPoint->getId()->toRfc4122());
                    }
                }
            }
        }

        return $this->renderEntryPointForm(
            'Editar punto de entrada',
            sprintf('Ajusta el código, las UTM por defecto y la relación con canal, producto o playbook en %s.', $activeTenant->getName()),
            'Editar punto de entrada',
            'Guardar cambios',
            '/backend/entry-points/'.$entryPoint->getId()->toRfc4122().'/edit',
            $values,
            $products,
            $playbooks,
            $error
        );
    }

    #[Route('/entry-points/{id}', methods: ['GET'])]
    public function entryPointDetail(string $id, ?EntryPointRepository $entryPoints = null): Response
    {
        if (!$this->security->isGranted('ROLE_MANAGER')) {
            return new RedirectResponse('/backend/login');
        }

        $activeTenant = $this->resolvedActiveTenantForCurrentUser();
        if (!$activeTenant instanceof Tenant) {
            return $this->renderTenantSelectionRequiredPage(
                'Punto de entrada',
                'Selecciona un negocio antes de revisar el detalle de la ruta pública.',
                'entry-points',
                'Puntos de entrada'
            );
        }

        if (!$entryPoints instanceof EntryPointRepository) {
            return new RedirectResponse('/backend/entry-points');
        }

        $entryPoint = $entryPoints->find($id);
        if (!$entryPoint instanceof EntryPoint || $entryPoint->getTenant()->getId()->toRfc4122() !== $activeTenant->getId()->toRfc4122()) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $redirectUrl = '/api/r/wa/'.$entryPoint->getCode();
        $exampleUrl = $redirectUrl.'?utm_source=google&utm_medium=cpc&utm_campaign=example';

        $content = sprintf(
            '
            <section class="hero-panel">
              <div class="hero-copy">
                <div class="eyebrow-dark">Routing comercial</div>
                <h2>%s</h2>
                <p>%s</p>
              </div>
              <div class="hero-aside">
                <div class="badge-live">%s</div>
                <div class="hero-aside-title">Detalle</div>
                <p>Usa este punto de entrada para campañas, botones o QR con contexto comercial explícito.</p>
              </div>
            </section>
            <section class="table-card">
              <div class="table-header">
                <div>
                  <h3>URLs públicas</h3>
                  <p>La URL de redirección se expone sin JWT y preserva el ref para atribución.</p>
                </div>
              </div>
              <div class="form-grid">
                <div class="field field-full">
                  <label for="entrypoint-url">URL de redirección</label>
                  <input id="entrypoint-url" type="text" value="%s" readonly>
                </div>
                <div class="field field-full">
                  <label for="entrypoint-example">Ejemplo con UTM</label>
                  <input id="entrypoint-example" type="text" value="%s" readonly>
                </div>
                <div class="field">
                  <label>Código</label>
                  <input type="text" value="%s" readonly>
                </div>
                <div class="field">
                  <label>Negocio</label>
                  <input type="text" value="%s" readonly>
                </div>
                <div class="field">
                  <label>Producto</label>
                  <input type="text" value="%s" readonly>
                </div>
                <div class="field">
                  <label>Playbook</label>
                  <input type="text" value="%s" readonly>
                </div>
                <div class="field">
                  <label>CRM branch ref</label>
                  <input type="text" value="%s" readonly>
                </div>
              </div>
            </section>
            ',
            htmlspecialchars($entryPoint->getName(), ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($entryPoint->getDefaultMessage() ?? 'Sin mensaje por defecto.', ENT_QUOTES, 'UTF-8'),
            $entryPoint->isActive() ? 'Activo' : 'Inactivo',
            htmlspecialchars($redirectUrl, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($exampleUrl, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($entryPoint->getCode(), ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($entryPoint->getTenant()->getName(), ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($entryPoint->getProduct()?->getName() ?? 'Sin producto', ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($entryPoint->getPlaybook()?->getName() ?? 'Sin playbook', ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($entryPoint->getCrmBranchRef() ?? '—', ENT_QUOTES, 'UTF-8')
        );

        $content = $this->twig->render('backend/entry_points/detail.html.twig', [
            'entrypoint_name' => $entryPoint->getName(),
            'entrypoint_default_message' => $entryPoint->getDefaultMessage() ?? 'Sin mensaje por defecto.',
            'entrypoint_state' => $entryPoint->isActive() ? 'Activo' : 'Inactivo',
            'redirect_url' => $redirectUrl,
            'example_url' => $exampleUrl,
            'code' => $entryPoint->getCode(),
            'tenant_name' => $entryPoint->getTenant()->getName(),
            'product_name' => $entryPoint->getProduct()?->getName() ?? 'Sin producto',
            'playbook_name' => $entryPoint->getPlaybook()?->getName() ?? 'Sin playbook',
            'crm_branch_ref' => $entryPoint->getCrmBranchRef() ?? '—',
        ]);

        return $this->renderBackendShell('Punto de entrada', 'Detalle público y contexto comercial asociado al código.', 'entry-points', $content);
    }

    #[Route('/profile', methods: ['GET'])]
    public function profile(Request $request): Response
    {
        if (!$this->security->isGranted('ROLE_AGENT')) {
            return new RedirectResponse('/backend/login');
        }

        $user = $this->currentUser();
        if (!$user instanceof User) {
            return new RedirectResponse('/backend/login');
        }

        $feedbackHtml = $this->renderProfileFeedback($request);
        $roleLabel = implode(', ', array_map(
            static fn (string $role): string => strtolower(str_replace('ROLE_', '', $role)),
            $user->getRoles()
        ));

        $content = sprintf(
            '
            <section class="hero-panel hero-panel-single">
              <div class="hero-copy">
                <div class="eyebrow-dark">Sesión</div>
                <h2>Mi perfil</h2>
                <p>Actualiza tu nombre visible y tu clave de acceso. El panel humano sigue separado de la API técnica y de las integraciones entre servicios.</p>
              </div>
            </section>
            %s
            <section class="profile-grid">
              <article class="profile-card">
                <div class="profile-card-header">Datos de usuario</div>
                <div class="profile-card-body">
                  <div class="profile-meta">
                    <div><strong>Email:</strong> %s</div>
                    <div><strong>Rol:</strong> %s</div>
                    <div><strong>Creado:</strong> %s</div>
                  </div>

                  <form method="post" action="/backend/profile/name" class="profile-form">
                    <input type="hidden" name="_csrf_token" value="%s">
                    <div class="field">
                      <label for="profile-name">Nombre</label>
                      <input id="profile-name" name="name" type="text" value="%s" maxlength="180" required>
                    </div>
                    <div class="profile-actions">
                      <button class="primary-action" type="submit">Guardar nombre</button>
                    </div>
                  </form>
                </div>
              </article>

              <article class="profile-card">
                <div class="profile-card-header">Cambiar clave</div>
                <div class="profile-card-body">
                  <form method="post" action="/backend/profile/password" class="profile-form">
                    <input type="hidden" name="_csrf_token" value="%s">
                    <div class="field">
                      <label for="profile-current-password">Clave actual</label>
                      <input id="profile-current-password" name="currentPassword" type="password" autocomplete="current-password" required>
                    </div>

                    <div class="field">
                      <label for="profile-new-password">Nueva clave</label>
                      <input id="profile-new-password" name="newPassword" type="password" autocomplete="new-password" minlength="8" required>
                      <div class="field-note">Mínimo 8 caracteres.</div>
                    </div>

                    <div class="profile-actions">
                      <button class="primary-action" type="submit">Actualizar clave</button>
                    </div>
                  </form>
                </div>
              </article>
            </section>
            ',
            $feedbackHtml,
            htmlspecialchars($user->getEmail(), ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($user->getCreatedAt()->format('Y-m-d H:i'), ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($this->profileTokenValue('profile_name'), ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($user->getName(), ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($this->profileTokenValue('profile_password'), ENT_QUOTES, 'UTF-8')
        );

        $content = $this->twig->render('backend/profile.html.twig', [
            'feedback_html' => $feedbackHtml,
            'email' => $user->getEmail(),
            'role_label' => $roleLabel,
            'created_at' => $user->getCreatedAt()->format('Y-m-d H:i'),
            'profile_name_token' => $this->profileTokenValue('profile_name'),
            'name' => $user->getName(),
            'profile_password_token' => $this->profileTokenValue('profile_password'),
        ]);

        return $this->renderBackendShell('Mi perfil', 'Datos de cuenta y seguridad del usuario conectado.', 'profile', $content);
    }

    #[Route('/profile/name', methods: ['POST'])]
    public function profileName(Request $request): Response
    {
        if (!$this->security->isGranted('ROLE_AGENT')) {
            return new RedirectResponse('/backend/login');
        }

        $user = $this->currentUser();
        if (!$user instanceof User) {
            return new RedirectResponse('/backend/login');
        }

        if (!$this->isValidProfileToken('profile_name', (string) $request->request->get('_csrf_token'))) {
            return new RedirectResponse('/backend/profile');
        }

        $name = trim((string) $request->request->get('name', ''));
        if ($name === '') {
            $this->addProfileFlash($request, 'error', 'Nombre requerido.');

            return new RedirectResponse('/backend/profile');
        }

        $user->setName($name);
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $this->addProfileFlash($request, 'success', 'Nombre actualizado.');

        return new RedirectResponse('/backend/profile');
    }

    #[Route('/profile/password', methods: ['POST'])]
    public function profilePassword(Request $request): Response
    {
        if (!$this->security->isGranted('ROLE_AGENT')) {
            return new RedirectResponse('/backend/login');
        }

        $user = $this->currentUser();
        if (!$user instanceof User) {
            return new RedirectResponse('/backend/login');
        }

        if (!$this->isValidProfileToken('profile_password', (string) $request->request->get('_csrf_token'))) {
            return new RedirectResponse('/backend/profile');
        }

        $currentPassword = (string) $request->request->get('currentPassword', '');
        $newPassword = (string) $request->request->get('newPassword', '');

        if ($currentPassword === '' || $newPassword === '') {
            $this->addProfileFlash($request, 'error', 'Completa la clave actual y la nueva clave.');

            return new RedirectResponse('/backend/profile');
        }

        if (strlen($newPassword) < 8) {
            $this->addProfileFlash($request, 'error', 'La nueva clave debe tener al menos 8 caracteres.');

            return new RedirectResponse('/backend/profile');
        }

        if (!$this->passwordHasher->isPasswordValid($user, $currentPassword)) {
            $this->addProfileFlash($request, 'error', 'La clave actual no es correcta.');

            return new RedirectResponse('/backend/profile');
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, $newPassword));
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $this->addProfileFlash($request, 'success', 'Clave actualizada.');

        return new RedirectResponse('/backend/profile');
    }

    #[Route('/api-health', methods: ['GET'])]
    public function apiHealth(): Response
    {
        if (!$this->security->isGranted('ROLE_SUPER_ADMIN')) {
            return new RedirectResponse('/backend/login');
        }

        $content = sprintf(
            '
            <section class="hero-panel">
              <div class="hero-copy">
                <div class="eyebrow-dark">API técnica</div>
                <h2>API Health</h2>
                <p>Verificación rápida del backend JSON y del runtime. La API sigue separada del panel humano y del servicio FastAPI.</p>
              </div>
              <div class="hero-aside">
                <div class="badge-live">Status</div>
                <div class="hero-aside-title">Backend API</div>
                <p>Usa esta vista como puerta de entrada rápida antes de probar integraciones internas.</p>
              </div>
            </section>
            <section class="cards-grid">
              %s
              %s
              %s
            </section>
            ',
            $this->infoCard('Health endpoint', '/backend/api/health', '/backend/api/health', 'GET'),
            $this->infoCard('Login API', '/backend/api/login', '/backend/api/login', 'POST'),
            $this->infoCard('Runtime', 'FastAPI / Symfony separados por diseño.', '/backend/dashboard', 'Panel')
        );

        $content = $this->twig->render('backend/api_health.html.twig', [
            'info_cards_html' => implode('', [
                $this->infoCard('Health endpoint', '/backend/api/health', '/backend/api/health', 'GET'),
                $this->infoCard('Login API', '/backend/api/login', '/backend/api/login', 'POST'),
                $this->infoCard('Runtime', 'FastAPI / Symfony separados por diseño.', '/backend/dashboard', 'Panel'),
            ]),
        ]);

        return $this->renderBackendShell('API Health', 'Estado de la API técnica y rutas internas.', 'api-health', $content);
    }

    private function renderBackendShell(string $pageTitle, string $pageSubtitle, string $activeNav, string $contentHtml): Response
    {
        return new Response($this->twig->render('backend/layout.html.twig', [
            'page_title' => $pageTitle,
            'page_subtitle' => $pageSubtitle,
            'active_nav' => $activeNav,
            'content' => $contentHtml,
            ...$this->currentUserTemplateData(),
        ]));
    }

    private function renderTenantSelectionRequiredPage(string $pageTitle, string $pageSubtitle, string $activeNav, string $sectionLabel): Response
    {
        $content = sprintf(
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
        );

        return $this->renderBackendShell($pageTitle, $pageSubtitle, $activeNav, $content);
    }

    /**
     * @param array<int, array{email: string, roles: string, status_label: string, status_class: string, created_at: string, login_label: string}> $users
     */
    private function renderUsersPage(array $users): Response
    {
        return new Response($this->twig->render('backend/users/index.html.twig', [
            'page_title' => 'Usuarios',
            'page_subtitle' => 'Cuentas y roles de acceso interno.',
            'active_nav' => 'admin-users',
            'create_url' => '/backend/users/new',
            'users' => $users,
            ...$this->currentUserTemplateData(),
        ]));
    }

    /**
     * @param array<string, array{href: string, label: string, meta: string, roles: string[]}> $items
     */
    private function renderNav(string $activeNav): string
    {
        $items = [
            'dashboard' => ['href' => '/backend/dashboard', 'label' => 'Dashboard', 'roles' => ['ROLE_AGENT', 'ROLE_MANAGER', 'ROLE_ADMIN']],
            'tenants' => ['href' => '/backend/tenants', 'label' => 'Negocios', 'roles' => ['ROLE_MANAGER', 'ROLE_ADMIN']],
            'playbooks' => ['href' => '/backend/playbooks', 'label' => 'Guías comerciales', 'roles' => ['ROLE_MANAGER', 'ROLE_ADMIN']],
            'admin-products' => ['href' => '/backend/products', 'label' => 'Productos / servicios', 'roles' => ['ROLE_MANAGER', 'ROLE_ADMIN']],
            'entry-points' => ['href' => '/backend/entry-points', 'label' => 'Puntos de entrada', 'roles' => ['ROLE_MANAGER', 'ROLE_ADMIN']],
            'admin-users' => ['href' => '/backend/users', 'label' => 'Usuarios', 'roles' => ['ROLE_SUPER_ADMIN']],
            'admin-configuration' => ['href' => '/backend/configuration', 'label' => 'Configuración', 'roles' => ['ROLE_SUPER_ADMIN']],
            'admin-api-health' => ['href' => '/backend/api-health', 'label' => 'Integración técnica', 'roles' => ['ROLE_SUPER_ADMIN']],
        ];

        $html = '';
        foreach (['dashboard', 'tenants', 'playbooks', 'admin-products', 'entry-points'] as $key) {
            $item = $items[$key];
            if (!$this->canSeeNavItem($item['roles'])) {
                continue;
            }

            $class = $key === $activeNav ? 'active' : '';
            $html .= sprintf(
                '<a class="%s" href="%s">%s</a>',
                $class,
                htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8')
            );
        }

        $adminItems = [];
        if ($this->isSuperAdmin()) {
            foreach (['admin-users', 'admin-configuration', 'admin-api-health'] as $key) {
                $item = $items[$key];
                if (!$this->canSeeNavItem($item['roles'])) {
                    continue;
                }

                $adminItems[] = sprintf(
                    '<a class="%s" href="%s">%s</a>',
                    $key === $activeNav ? 'active' : '',
                    htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8')
                );
            }
        }

        if ($adminItems !== []) {
            $html .= sprintf(
                '<details class="nav-group"%s><summary>Administración <span class="nav-caret">▾</span></summary><div class="nav-subitems">%s</div></details>',
                in_array($activeNav, ['admin-users', 'admin-configuration', 'admin-api-health'], true) ? ' open' : '',
                implode('', $adminItems)
            );
        }

        return $html;
    }

    private function renderUserMenu(): string
    {
        return sprintf(
            '<div class="user-area"><details class="user-dropdown"><summary class="user-summary"><div class="user-chip"><div class="avatar">%s</div><div class="user-meta"><strong>%s</strong></div></div><span class="nav-caret">▾</span></summary><div class="user-links"><a href="/backend/profile">Mi perfil</a><a href="/backend/logout">Salir</a></div></details></div>',
            htmlspecialchars($this->currentUserInitials(), ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($this->currentUserDisplayName(), ENT_QUOTES, 'UTF-8')
        );
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
     * @return array{current_user_display_name: string, current_user_initials: string, active_tenant: array{id: string, name: string, slug: string, edit_url: string}|null}
     */
    private function currentUserTemplateData(): array
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
        $tenant = $this->resolvedActiveTenantForCurrentUser();
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
     * @return Tenant[]
     */
    private function activeTenantsForUserCreation(?TenantRepository $tenants): array
    {
        if (!$tenants instanceof TenantRepository) {
            $tenants = $this->entityManager->getRepository(Tenant::class);
            if (!$tenants instanceof TenantRepository) {
                return [];
            }
        }

        return array_values(array_filter(
            $tenants->findAllOrdered(),
            static fn (Tenant $tenant): bool => $tenant->isActive()
        ));
    }

    /**
     * @return Tenant[]
     */
    private function selectedTenantsForUserCreate(array $selectedTenantIds, array $availableTenants): array
    {
        $availableById = [];
        foreach ($availableTenants as $tenant) {
            $availableById[$tenant->getId()->toRfc4122()] = $tenant;
        }

        $selected = [];
        foreach ($selectedTenantIds as $tenantId) {
            $tenantId = trim((string) $tenantId);
            if ($tenantId === '' || !isset($availableById[$tenantId])) {
                continue;
            }

            $selected[$tenantId] = $availableById[$tenantId];
        }

        return array_values($selected);
    }

    /**
     * @param array<string, mixed> $values
     * @param Tenant[] $availableTenants
     *
     * @return string[]
     */
    private function validateUserCreateForm(array $values, mixed $users, array $availableTenants): array
    {
        $errors = [];

        $email = trim((string) ($values['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Debes indicar un email válido.';
        } elseif (is_object($users) && method_exists($users, 'findOneByEmail') && $users->findOneByEmail($email) instanceof User) {
            $errors[] = 'Ya existe un usuario con ese email.';
        }

        $password = trim((string) ($values['password'] ?? ''));
        $passwordConfirmation = trim((string) ($values['passwordConfirmation'] ?? ''));
        if ($password === '') {
            $errors[] = 'La contraseña temporal es obligatoria.';
        } elseif ($passwordConfirmation === '' || $password !== $passwordConfirmation) {
            $errors[] = 'La repetición de contraseña no coincide.';
        }

        $role = (string) ($values['role'] ?? '');
        if (!in_array($role, ['agent', 'manager', 'admin', 'super_admin'], true)) {
            $errors[] = 'El rol global no es válido.';
        }

        $membershipRole = (string) ($values['membershipRole'] ?? '');
        if (!in_array($membershipRole, ['manager', 'editor', 'viewer', 'agent'], true)) {
            $errors[] = 'El rol de membership no es válido.';
        }

        $selectedTenants = $this->selectedTenantsForUserCreate((array) ($values['tenantIds'] ?? []), $availableTenants);
        if ($role !== 'super_admin' && $selectedTenants === []) {
            $errors[] = 'Debes asignar al menos un tenant a un usuario no-super-admin.';
        }

        $selectedTenantIds = array_map(static fn (Tenant $tenant): string => $tenant->getId()->toRfc4122(), $selectedTenants);
        $submittedTenantIds = array_values(array_filter(array_map(
            static fn (mixed $tenantId): string => trim((string) $tenantId),
            (array) ($values['tenantIds'] ?? [])
        )));
        foreach ($submittedTenantIds as $tenantId) {
            if (!in_array($tenantId, $selectedTenantIds, true)) {
                $errors[] = 'Todos los tenants seleccionados deben existir y estar activos.';
                break;
            }
        }

        return array_values(array_unique($errors));
    }

    /**
     * @return array{email: string, password: string, passwordConfirmation: string, role: string, membershipRole: string, isActive: bool, tenantIds: list<string>}
     */
    private function userCreateFormDefaults(): array
    {
        return [
            'email' => '',
            'password' => '',
            'passwordConfirmation' => '',
            'role' => 'agent',
            'membershipRole' => 'manager',
            'isActive' => true,
            'tenantIds' => [],
        ];
    }

    /**
     * @return array{email: string, password: string, passwordConfirmation: string, role: string, membershipRole: string, isActive: bool, tenantIds: list<string>}
     */
    private function userCreateFormValuesFromRequest(Request $request): array
    {
        $submitted = $request->request->all();
        $tenantIds = $submitted['tenantIds'] ?? [];
        if (!is_array($tenantIds)) {
            $tenantIds = [$tenantIds];
        }

        $tenantIds = array_values(array_unique(array_filter(array_map(
            static fn (mixed $tenantId): string => trim((string) $tenantId),
            $tenantIds
        ))));

        return [
            'email' => trim((string) $request->request->get('email', '')),
            'password' => (string) $request->request->get('password', ''),
            'passwordConfirmation' => (string) $request->request->get('passwordConfirmation', ''),
            'role' => strtolower(trim((string) $request->request->get('role', ''))),
            'membershipRole' => strtolower(trim((string) $request->request->get('membershipRole', 'manager'))),
            'isActive' => $request->request->getBoolean('isActive'),
            'tenantIds' => $tenantIds,
        ];
    }

    private function isValidUserCreateToken(string $value): bool
    {
        if ($this->csrfTokenManager === null) {
            return true;
        }

        return $this->csrfTokenManager->isTokenValid(new CsrfToken('user_create', $value));
    }

    private function userCreateTokenValue(): string
    {
        if ($this->csrfTokenManager === null) {
            return '';
        }

        return $this->csrfTokenManager->getToken('user_create')->getValue();
    }

    private function canManageActiveTenant(): bool
    {
        $tenant = $this->resolvedActiveTenantForCurrentUser();
        if (!$tenant instanceof Tenant) {
            return false;
        }

        if ($this->tenantAccessResolver instanceof TenantAccessResolver) {
            return $this->tenantAccessResolver->canManageTenant($this->currentUser(), $tenant);
        }

        return true;
    }

    private function countTenantProducts(?ProductRepository $products, Tenant $tenant): int
    {
        if (!$products instanceof ProductRepository) {
            return 0;
        }

        $tenantId = $tenant->getId()->toRfc4122();

        return count(array_filter(
            $products->findAllOrdered(),
            static fn (Product $product): bool => $product->getTenant()->getId()->toRfc4122() === $tenantId
        ));
    }

    private function countTenantPlaybooks(?PlaybookRepository $playbooks, Tenant $tenant): int
    {
        if (!$playbooks instanceof PlaybookRepository) {
            return 0;
        }

        $tenantId = $tenant->getId()->toRfc4122();

        return count(array_filter(
            $playbooks->findAllOrdered(),
            static fn (Playbook $playbook): bool => $playbook->getTenant()->getId()->toRfc4122() === $tenantId
        ));
    }

    private function countTenantEntryPoints(?EntryPointRepository $entryPoints, Tenant $tenant): int
    {
        if (!$entryPoints instanceof EntryPointRepository) {
            return 0;
        }

        $tenantId = $tenant->getId()->toRfc4122();

        return count(array_filter(
            $entryPoints->findAllOrdered(),
            static fn (EntryPoint $entryPoint): bool => $entryPoint->getTenant()->getId()->toRfc4122() === $tenantId
        ));
    }

    private function countTenantExternalTools(?ExternalToolRepository $externalTools, Tenant $tenant): int
    {
        if (!$externalTools instanceof ExternalToolRepository) {
            return 0;
        }

        return count(array_filter(
            $externalTools->findByTenantOrdered($tenant),
            static fn (ExternalTool $tool): bool => $tool->getType() === 'mcp_remote' && in_array($tool->getProvider(), ['openai_remote_mcp', 'mcp_remote'], true)
        ));
    }

    /**
     * @return array{value: string, note: string, detail: string}
     */
    private function tenantMcpRuntimeState(?ExternalToolRepository $externalTools, Tenant $tenant): array
    {
        if (!$externalTools instanceof ExternalToolRepository) {
            return [
                'value' => 'MCP pendiente de configurar',
                'note' => 'Usado por el agente',
                'detail' => 'Configura un MCP principal para que el runtime tenga una referencia explícita.',
            ];
        }

        $default = $externalTools->findRuntimeDefaultMcpByTenant($tenant);
        if ($default instanceof ExternalTool) {
            return [
                'value' => sprintf('Principal: %s', $default->getName()),
                'note' => 'Usado por el agente',
                'detail' => sprintf('El MCP principal del runtime es %s.', $default->getName()),
            ];
        }

        $activeCandidates = $externalTools->findActiveMcpCandidatesByTenant($tenant);
        if ($activeCandidates !== [] && count($activeCandidates) > 1) {
            return [
                'value' => 'Varios MCP activos sin principal',
                'note' => 'Usado por el agente',
                'detail' => 'Hay varios MCP activos, pero el runtime no elegirá ninguno automáticamente hasta definir uno principal.',
            ];
        }

        if ($activeCandidates !== [] && count($activeCandidates) === 1 && $activeCandidates[0] instanceof ExternalTool) {
            return [
                'value' => sprintf('Usado por el agente: %s', $activeCandidates[0]->getName()),
                'note' => 'Sin principal explícito',
                'detail' => sprintf('Hay un único MCP activo: %s. Conviene marcarlo como principal para evitar ambigüedad.', $activeCandidates[0]->getName()),
            ];
        }

        return [
            'value' => 'MCP pendiente de configurar',
            'note' => 'Usado por el agente',
            'detail' => 'No hay ningún MCP activo para este negocio. Configura uno para que el runtime lo pueda usar.',
        ];
    }

    private function currentUser(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }

    /**
     * @return Tenant[]
     */
    private function accessibleTenantsForCurrentUser(): array
    {
        if (!$this->tenantAccessResolver instanceof TenantAccessResolver) {
            $tenant = $this->activeTenantContext->getActiveTenant();

            return $tenant instanceof Tenant ? [$tenant] : [];
        }

        return $this->tenantAccessResolver->accessibleTenants($this->currentUser());
    }

    private function isSuperAdmin(): bool
    {
        if (!$this->tenantAccessResolver instanceof TenantAccessResolver) {
            return $this->security->isGranted('ROLE_SUPER_ADMIN');
        }

        return $this->tenantAccessResolver->isSuperAdmin($this->currentUser());
    }

    private function canAccessTenant(Tenant $tenant): bool
    {
        if (!$this->tenantAccessResolver instanceof TenantAccessResolver) {
            return true;
        }

        return $this->tenantAccessResolver->canAccessTenant($this->currentUser(), $tenant);
    }

    private function canManageTenant(Tenant $tenant): bool
    {
        if (!$this->tenantAccessResolver instanceof TenantAccessResolver) {
            return true;
        }

        return $this->tenantAccessResolver->canManageTenant($this->currentUser(), $tenant);
    }

    private function resolvedActiveTenantForCurrentUser(bool $autoSelectSingle = true): ?Tenant
    {
        $activeTenant = $this->activeTenantContext->getActiveTenant();

        if (!$this->tenantAccessResolver instanceof TenantAccessResolver) {
            return $activeTenant;
        }

        $resolved = $this->tenantAccessResolver->resolveActiveTenantForUser($this->currentUser(), $activeTenant, $autoSelectSingle);

        if ($resolved instanceof Tenant) {
            if (!$activeTenant instanceof Tenant || $activeTenant->getId()->toRfc4122() !== $resolved->getId()->toRfc4122()) {
                $this->activeTenantContext->setActiveTenant($resolved);
            }

            return $resolved;
        }

        if ($activeTenant instanceof Tenant && !$this->tenantAccessResolver->canAccessTenant($this->currentUser(), $activeTenant)) {
            $this->activeTenantContext->clear();
        }

        return null;
    }

    private function shortenListText(string $value, int $limit, string $fallback): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);
        if ($value === '') {
            return $fallback;
        }

        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, max(1, $limit - 1))).'…';
    }

    /**
     * @return array{name: string, slug: string, businessContext: string, tone: string, whatsappPhoneNumberId: string, whatsappPublicPhone: string, humanHandoffEnabled: bool, humanHandoffWhatsappPublic: string, humanHandoffMessage: string, humanHandoffStrategy: string, commercialPlanId: string, subscriptionStatus: string, currentPeriodStart: string, currentPeriodEnd: string, positioning: string, qualificationFocus: string, handoffRules: string, salesBoundaries: string, notes: string, isActive: bool, aiEnabled: bool, dailyCostLimitEur: string, monthlyCostLimitEur: string, defaultModel: string, fallbackModel: string, limitAction: string}
     */
    private function tenantFormDefaults(
        ?Tenant $tenant = null,
        ?TenantAiUsagePolicy $aiUsagePolicy = null,
        ?AiUsageEventRepository $aiUsageEvents = null,
        ?float $tokenRate = null,
        ?CommercialPlanRepository $commercialPlans = null,
    ): array
    {
        $salesPolicy = $tenant?->getSalesPolicy() ?? [];
        $aiPolicy = $this->tenantAiUsagePolicyValues(
            $aiUsagePolicy,
            $tokenRate ?? $this->tenantAiUsageTokenRate($aiUsagePolicy)
        );
        $commercialPlan = $tenant?->getCommercialPlan();

        return [
            'name' => $tenant?->getName() ?? '',
            'slug' => $tenant?->getSlug() ?? '',
            'businessContext' => $tenant?->getBusinessContext() ?? '',
            'tone' => $tenant?->getTone() ?? '',
            'whatsappPhoneNumberId' => $tenant?->getWhatsappPhoneNumberId() ?? '',
            'whatsappPublicPhone' => $tenant?->getWhatsappPublicPhone() ?? '',
            'humanHandoffEnabled' => $tenant?->isHumanHandoffEnabled() ?? false,
            'humanHandoffWhatsappPublic' => $tenant?->getHumanHandoffWhatsappPublic() ?? '',
            'humanHandoffMessage' => $tenant?->getHumanHandoffMessage() ?? '',
            'humanHandoffStrategy' => $tenant?->getHumanHandoffStrategy() ?? 'disabled',
            'positioning' => $this->tenantPolicyValue($salesPolicy, 'positioning'),
            'qualificationFocus' => $this->tenantPolicyValue($salesPolicy, 'qualificationFocus'),
            'handoffRules' => $this->tenantPolicyValue($salesPolicy, 'handoffRules'),
            'salesBoundaries' => $this->tenantPolicyLines($salesPolicy, 'salesBoundaries'),
            'notes' => $this->tenantPolicyValue($salesPolicy, 'notes'),
            'isActive' => $tenant?->isActive() ?? true,
            'aiEnabled' => $aiPolicy['aiEnabled'],
            'dailyCostLimitEur' => $aiPolicy['dailyCostLimitEur'],
            'monthlyCostLimitEur' => $aiPolicy['monthlyCostLimitEur'],
            'defaultModel' => $aiPolicy['defaultModel'],
            'fallbackModel' => $aiPolicy['fallbackModel'],
            'limitAction' => $aiPolicy['limitAction'],
            'commercialPlanId' => $commercialPlan?->getId()->toRfc4122() ?? '',
            'subscriptionStatus' => $tenant?->getSubscriptionStatus() ?? '',
            'currentPeriodStart' => $tenant?->getCurrentPeriodStart()?->format('Y-m-d\TH:i') ?? '',
            'currentPeriodEnd' => $tenant?->getCurrentPeriodEnd()?->format('Y-m-d\TH:i') ?? '',
        ];
    }

    /**
     * @return array{name: string, slug: string, businessContext: string, tone: string, whatsappPhoneNumberId: string, whatsappPublicPhone: string, humanHandoffEnabled: bool, humanHandoffWhatsappPublic: string, humanHandoffMessage: string, humanHandoffStrategy: string, commercialPlanId: string, subscriptionStatus: string, currentPeriodStart: string, currentPeriodEnd: string, positioning: string, qualificationFocus: string, handoffRules: string, salesBoundaries: string, notes: string, isActive: bool, aiEnabled: bool, dailyCostLimitEur: string, monthlyCostLimitEur: string, defaultModel: string, fallbackModel: string, limitAction: string}
     */
    private function tenantFormValuesFromRequest(Request $request, ?Tenant $tenant = null): array
    {
        $commercialPlanId = $tenant?->getCommercialPlan()?->getId()->toRfc4122() ?? '';
        $subscriptionStatus = $tenant?->getSubscriptionStatus() ?? '';
        $currentPeriodStart = $tenant?->getCurrentPeriodStart()?->format('Y-m-d\TH:i') ?? '';
        $currentPeriodEnd = $tenant?->getCurrentPeriodEnd()?->format('Y-m-d\TH:i') ?? '';

        return [
            'name' => trim((string) $request->request->get('name', '')),
            'slug' => trim((string) $request->request->get('slug', '')),
            'businessContext' => trim((string) $request->request->get('businessContext', '')),
            'tone' => trim((string) $request->request->get('tone', '')),
            'whatsappPhoneNumberId' => trim((string) $request->request->get('whatsappPhoneNumberId', '')),
            'whatsappPublicPhone' => trim((string) $request->request->get('whatsappPublicPhone', '')),
            'humanHandoffEnabled' => $request->request->has('humanHandoffEnabled'),
            'humanHandoffWhatsappPublic' => trim((string) $request->request->get('humanHandoffWhatsappPublic', '')),
            'humanHandoffMessage' => trim((string) $request->request->get('humanHandoffMessage', '')),
            'humanHandoffStrategy' => trim((string) $request->request->get('humanHandoffStrategy', 'disabled')),
            'positioning' => trim((string) $request->request->get('positioning', '')),
            'qualificationFocus' => trim((string) $request->request->get('qualificationFocus', '')),
            'handoffRules' => trim((string) $request->request->get('handoffRules', '')),
            'salesBoundaries' => trim((string) $request->request->get('salesBoundaries', '')),
            'notes' => trim((string) $request->request->get('notes', '')),
            'isActive' => $request->request->has('isActive'),
            'aiEnabled' => $request->request->has('aiEnabled'),
            'dailyCostLimitEur' => trim((string) $request->request->get('dailyCostLimitEur', '')),
            'monthlyCostLimitEur' => trim((string) $request->request->get('monthlyCostLimitEur', '')),
            'defaultModel' => trim((string) $request->request->get('defaultModel', '')),
            'fallbackModel' => trim((string) $request->request->get('fallbackModel', '')),
            'limitAction' => trim((string) $request->request->get('limitAction', 'handoff_human')),
            'commercialPlanId' => trim((string) $request->request->get('commercialPlanId', $commercialPlanId)),
            'subscriptionStatus' => trim((string) $request->request->get('subscriptionStatus', $subscriptionStatus)),
            'currentPeriodStart' => trim((string) $request->request->get('currentPeriodStart', $currentPeriodStart)),
            'currentPeriodEnd' => trim((string) $request->request->get('currentPeriodEnd', $currentPeriodEnd)),
        ];
    }

    private function renderTenantForm(
        string $pageTitle,
        string $pageSubtitle,
        string $heroTitle,
        string $submitLabel,
        string $actionUrl,
        array $values,
        ?string $error = null,
        array $aiUsage = [],
        string $activeNav = 'tenants',
        array $commercialPlanOptions = [],
        array $subscriptionStatusOptions = [],
        string $commercialPlanSelectedLabel = 'Sin plan asignado',
    ): Response {
        $aiUsage = array_replace(
            [
                'today' => [
                    'estimatedCostEur' => '0,00 €',
                    'totalTokens' => '0',
                ],
                'month' => [
                    'estimatedCostEur' => '0,00 €',
                    'totalTokens' => '0',
                ],
                'recentEvents' => [],
            ],
            $aiUsage
        );

        $errorHtml = $error !== null ? sprintf(
            '<div class="form-alert form-alert-error">%s</div>',
            htmlspecialchars($error, ENT_QUOTES, 'UTF-8')
        ) : '';
        $content = $this->twig->render('backend/tenants/form.html.twig', [
            'hero_title' => $heroTitle,
            'page_subtitle' => $pageSubtitle,
            'error_html' => $errorHtml,
            'action_url' => $actionUrl,
            'csrf_token' => $this->tenantTokenValue($actionUrl),
            'ai_assistant_endpoint' => '/backend/ai/tenant-draft-assistant',
            'ai_assistant_token' => $this->tenantDraftAssistantTokenValue(),
            'ai_assistant_initial_message' => 'Hola. Te ayudaré a completar la ficha del negocio. La pantalla está separada en Ficha negocio, Canales, Handoff y Uso IA. Si no estás seguro con un WhatsApp o con el handoff, déjalo en blanco y lo revisamos después.',
            'ai_assistant_compose_note' => sprintf('La ficha se rellena en pantalla. No se guardará hasta que pulses %s.', $submitLabel),
            'is_super_admin' => $this->security->isGranted('ROLE_SUPER_ADMIN'),
            'values' => $values,
            'commercial_daily_limit_options' => $this->commercialMillionOptionsWithCurrent([0.1, 0.25, 0.5, 1, 2, 5], $values['dailyCostLimitEur'] ?? ''),
            'commercial_monthly_limit_options' => $this->commercialMillionOptionsWithCurrent([0.5, 1, 3, 5, 10, 15, 25, 50, 100], $values['monthlyCostLimitEur'] ?? ''),
            'default_model_options' => $this->tenantAiModelOptionsWithCurrent(AiModelCostReference::USAGE_TYPE_LLM_CHAT, $values['defaultModel'] ?? ''),
            'fallback_model_options' => $this->tenantAiModelOptionsWithCurrent(AiModelCostReference::USAGE_TYPE_LLM_CHAT, $values['fallbackModel'] ?? ''),
            'commercial_plan_options' => $commercialPlanOptions,
            'subscription_status_options' => $subscriptionStatusOptions,
            'commercial_plan_selected_label' => $commercialPlanSelectedLabel,
            'daily_limit_display' => $this->formatCommercialTokenValue($values['dailyCostLimitEur'] ?? ''),
            'monthly_limit_display' => $this->formatCommercialTokenValue($values['monthlyCostLimitEur'] ?? ''),
            'submit_label' => $submitLabel,
            'ai_usage' => $aiUsage,
        ]);

        return $this->renderBackendShell($pageTitle, $pageSubtitle, $activeNav, $content);
    }

    private function validateTenantForm(array $values, ?Tenant $tenant, ?TenantRepository $tenants, ?CommercialPlanRepository $commercialPlans = null): ?string
    {
        if ($values['name'] === '') {
            return 'El nombre del negocio es obligatorio.';
        }

        if (mb_strlen($values['name']) > 255) {
            return 'El nombre del negocio no puede superar 255 caracteres.';
        }

        if ($values['slug'] === '') {
            return 'El slug del negocio es obligatorio.';
        }

        if (mb_strlen($values['slug']) > 180) {
            return 'El slug del negocio no puede superar 180 caracteres.';
        }

        if ($values['businessContext'] !== '' && mb_strlen($values['businessContext']) > 5000) {
            return 'El contexto del negocio no puede superar 5000 caracteres.';
        }

        if ($values['tone'] !== '' && mb_strlen($values['tone']) > 120) {
            return 'El tono no puede superar 120 caracteres.';
        }

        if ($values['whatsappPhoneNumberId'] !== '' && mb_strlen($values['whatsappPhoneNumberId']) > 255) {
            return 'El WhatsApp Phone Number ID no puede superar 255 caracteres.';
        }

        if ($values['whatsappPublicPhone'] !== '' && mb_strlen($values['whatsappPublicPhone']) > 50) {
            return 'El WhatsApp público no puede superar 50 caracteres.';
        }

        if ($values['humanHandoffWhatsappPublic'] !== '' && mb_strlen($values['humanHandoffWhatsappPublic']) > 50) {
            return 'El WhatsApp humano no puede superar 50 caracteres.';
        }

        if ($values['humanHandoffMessage'] !== '' && mb_strlen($values['humanHandoffMessage']) > 4000) {
            return 'El mensaje de derivación no puede superar 4000 caracteres.';
        }

        if (!in_array($values['humanHandoffStrategy'], ['disabled', 'manual_wa_link', 'n8n_webhook', 'manual_wa_link_and_n8n'], true)) {
            return 'La estrategia de handoff no es válida.';
        }

        if ($values['subscriptionStatus'] !== '' && !in_array($values['subscriptionStatus'], ['trialing', 'active', 'past_due', 'cancelled', 'manual', 'paused'], true)) {
            return 'El estado de suscripción no es válido.';
        }

        if ($values['commercialPlanId'] !== '' && $commercialPlans instanceof CommercialPlanRepository) {
            $commercialPlan = $commercialPlans->find($values['commercialPlanId']);
            if (!$commercialPlan instanceof CommercialPlan) {
                return 'El plan comercial seleccionado no existe.';
            }
        }

        foreach (['currentPeriodStart', 'currentPeriodEnd'] as $field) {
            if ($values[$field] === '') {
                continue;
            }

            if ($this->parseDateTimeLocal($values[$field]) === null) {
                return sprintf('El campo "%s" debe ser una fecha válida.', $field);
            }
        }

        $salesPolicy = $this->tenantSalesPolicyFromForm($values);
        $error = CommercialDomainSchema::validateTenantSalesPolicy($salesPolicy);
        if ($error !== null) {
            return sprintf('La política comercial no es válida: %s', $error);
        }

        if ($tenants instanceof TenantRepository) {
            $existing = $tenants->findOneBy(['slug' => $values['slug']]);
            if ($existing instanceof Tenant) {
                if ($tenant === null || $existing->getId()->toRfc4122() !== $tenant->getId()->toRfc4122()) {
                    return 'Ya existe otro negocio con ese slug.';
                }
            }

            if ($values['whatsappPhoneNumberId'] !== '') {
                foreach ($tenants->findByWhatsappPhoneNumberId($values['whatsappPhoneNumberId']) as $existingTenant) {
                    if (!$existingTenant instanceof Tenant) {
                        continue;
                    }

                    if ($tenant === null || $existingTenant->getId()->toRfc4122() !== $tenant->getId()->toRfc4122()) {
                        return 'Este WhatsApp Phone Number ID ya está en uso por otro negocio.';
                    }
                }
            }
        }

        return null;
    }

    private function validateTenantAiUsagePolicyForm(array $values): ?string
    {
        foreach (['dailyCostLimitEur', 'monthlyCostLimitEur'] as $field) {
            $value = $values[$field] ?? '';
            if ($value === '') {
                continue;
            }

            if ($this->parseCommercialTokenAmount($value) === null) {
                return 'Los límites de tokens deben ser cantidades comerciales positivas o vacíos.';
            }
        }

        $maxAudioSeconds = $values['maxAudioTranscriptionSeconds'] ?? '';
        if ($maxAudioSeconds !== '') {
            if (!is_numeric($maxAudioSeconds) || (int) $maxAudioSeconds < 1) {
                return 'El límite máximo de audio debe ser un entero mayor o igual que 1.';
            }
        }

        if ($values['defaultModel'] !== '' && mb_strlen($values['defaultModel']) > 100) {
            return 'El modelo por defecto no puede superar 100 caracteres.';
        }

        if ($values['fallbackModel'] !== '' && mb_strlen($values['fallbackModel']) > 100) {
            return 'El modelo alternativo no puede superar 100 caracteres.';
        }

        $audioLimitExceededMessage = $values['audioLimitExceededMessage'] ?? '';
        if ($audioLimitExceededMessage !== '' && mb_strlen($audioLimitExceededMessage) > 2000) {
            return 'El mensaje de audio no puede superar 2000 caracteres.';
        }

        if (!in_array($values['limitAction'], ['handoff_human', 'block'], true)) {
            return 'La acción de límite no es válida.';
        }

        return null;
    }

    private function hydrateTenantFromForm(Tenant $tenant, array $values, ?CommercialPlanRepository $commercialPlans = null): void
    {
        $commercialPlan = null;
        if ($commercialPlans instanceof CommercialPlanRepository && $values['commercialPlanId'] !== '') {
            $candidate = $commercialPlans->find($values['commercialPlanId']);
            if ($candidate instanceof CommercialPlan) {
                $commercialPlan = $candidate;
            }
        }

        $tenant->setName($values['name']);
        $tenant->setSlug($values['slug']);
        $tenant->setBusinessContext($values['businessContext']);
        $tenant->setTone($values['tone'] !== '' ? $values['tone'] : null);
        $tenant->setWhatsappPhoneNumberId($values['whatsappPhoneNumberId'] !== '' ? $values['whatsappPhoneNumberId'] : null);
        $tenant->setWhatsappPublicPhone($values['whatsappPublicPhone'] !== '' ? $values['whatsappPublicPhone'] : null);
        $tenant->setHumanHandoffEnabled($values['humanHandoffEnabled']);
        $tenant->setHumanHandoffWhatsappPublic($values['humanHandoffWhatsappPublic'] !== '' ? $values['humanHandoffWhatsappPublic'] : null);
        $tenant->setHumanHandoffMessage($values['humanHandoffMessage'] !== '' ? $values['humanHandoffMessage'] : null);
        $tenant->setHumanHandoffStrategy($values['humanHandoffStrategy']);
        $tenant->setCommercialPlan($commercialPlan);
        $tenant->setSubscriptionStatus($values['subscriptionStatus'] !== '' ? $values['subscriptionStatus'] : null);
        $tenant->setCurrentPeriodStart($this->parseDateTimeLocal($values['currentPeriodStart']));
        $tenant->setCurrentPeriodEnd($this->parseDateTimeLocal($values['currentPeriodEnd']));
        $tenant->setSalesPolicy($this->tenantSalesPolicyFromForm($values));
        $tenant->setActive($values['isActive']);
    }

    private function loadTenantAiUsagePolicy(Tenant $tenant, ?TenantAiUsagePolicyRepository $aiUsagePolicies): TenantAiUsagePolicy
    {
        if ($aiUsagePolicies instanceof TenantAiUsagePolicyRepository) {
            return $aiUsagePolicies->findOrCreateByTenant($tenant);
        }

        return new TenantAiUsagePolicy($tenant);
    }

    private function persistTenantAiUsagePolicy(
        Tenant $tenant,
        array $values,
        ?TenantAiUsagePolicyRepository $aiUsagePolicies,
        bool $flush = false,
        ?TenantAiUsagePolicy $policy = null,
        ?float $tokenRate = null,
    ): TenantAiUsagePolicy {
        $policy ??= $this->loadTenantAiUsagePolicy($tenant, $aiUsagePolicies);
        $this->hydrateTenantAiUsagePolicyFromForm($policy, $values, $tokenRate ?? $this->tenantAiUsageTokenRate($policy));

        if ($aiUsagePolicies instanceof TenantAiUsagePolicyRepository) {
            $aiUsagePolicies->save($policy, $flush);
        } else {
            $this->entityManager->persist($policy);
            if ($flush) {
                $this->entityManager->flush();
            }
        }

        return $policy;
    }

    /**
     * @return array{aiEnabled: bool, dailyCostLimitEur: string, monthlyCostLimitEur: string, defaultModel: string, fallbackModel: string, maxAudioTranscriptionSeconds: string, audioLimitExceededMessage: string, limitAction: string}
     */
    private function tenantAiUsagePolicyValues(?TenantAiUsagePolicy $policy = null, ?float $tokenRate = null): array
    {
        $tokenRate ??= $this->tenantAiUsageModelAverageCostPerToken($policy?->getDefaultModel() ?? $policy?->getFallbackModel());
        return [
            'aiEnabled' => $policy?->isAiEnabled() ?? true,
            'dailyCostLimitEur' => $this->formatTokenInputFromCost($policy?->getDailyCostLimitEur(), $tokenRate),
            'monthlyCostLimitEur' => $this->formatTokenInputFromCost($policy?->getMonthlyCostLimitEur(), $tokenRate),
            'defaultModel' => $policy?->getDefaultModel() ?? '',
            'fallbackModel' => $policy?->getFallbackModel() ?? '',
            'defaultModelOptions' => $this->tenantAiModelOptionsWithCurrent(AiModelCostReference::USAGE_TYPE_LLM_CHAT, $policy?->getDefaultModel()),
            'fallbackModelOptions' => $this->tenantAiModelOptionsWithCurrent(AiModelCostReference::USAGE_TYPE_LLM_CHAT, $policy?->getFallbackModel()),
            'maxAudioTranscriptionSeconds' => (string) ($policy?->getMaxAudioTranscriptionSeconds() ?? TenantAiUsagePolicy::DEFAULT_MAX_AUDIO_TRANSCRIPTION_SECONDS),
            'audioLimitExceededMessage' => $policy?->getAudioLimitExceededMessage() ?? TenantAiUsagePolicy::DEFAULT_AUDIO_LIMIT_EXCEEDED_MESSAGE,
            'limitAction' => $policy?->getLimitAction() ?? 'handoff_human',
        ];
    }

    /**
     * @return array{
     *   today: array{estimatedCostEur: string, totalTokens: string},
     *   month: array{estimatedCostEur: string, totalTokens: string},
     *   recentEvents: array<int, array{
     *     createdAt: string,
     *     provider: string,
     *     model: string,
     *     inputTokens: string,
     *     outputTokens: string,
     *     cachedTokens: string,
     *     totalTokens: string,
     *     estimatedCostEur: string,
     *     latencyMs: string
     *   }>
     * }
     */
    private function tenantAiUsageDisplayData(Tenant $tenant, ?AiUsageEventRepository $aiUsageEvents): array
    {
        $empty = [
            'today' => ['estimatedCostEur' => '0,00 €', 'totalTokens' => '0'],
            'month' => ['estimatedCostEur' => '0,00 €', 'totalTokens' => '0'],
            'recentEvents' => [],
        ];

        if (!$aiUsageEvents instanceof AiUsageEventRepository) {
            return $empty;
        }

        $timezone = new \DateTimeZone(date_default_timezone_get() ?: 'UTC');
        $today = new \DateTimeImmutable('today', $timezone);
        $month = new \DateTimeImmutable('first day of this month', $timezone);

        $todaySummary = $aiUsageEvents->summarizeSince($tenant, $today);
        $monthSummary = $aiUsageEvents->summarizeSince($tenant, $month);

        return [
            'today' => [
                'totalTokens' => CommercialTokenFormatter::formatCommercialMillionTokens((int) ($todaySummary['total_tokens'] ?? 0)),
                'estimatedCostEur' => $this->formatMoneyEur($todaySummary['estimated_cost_eur'] ?? 0.0),
            ],
            'month' => [
                'totalTokens' => CommercialTokenFormatter::formatCommercialMillionTokens((int) ($monthSummary['total_tokens'] ?? 0)),
                'estimatedCostEur' => $this->formatMoneyEur($monthSummary['estimated_cost_eur'] ?? 0.0),
            ],
            'recentEvents' => array_map(
                fn (AiUsageEvent $event): array => $this->tenantAiUsageEventView($event),
                $aiUsageEvents->findRecentByTenant($tenant, 5)
            ),
        ];
    }

    /**
     * @param array{requestedTokens: string, message: string} $values
     */
    private function renderAiUsageDashboardPage(
        Request $request,
        Tenant $tenant,
        array $values,
        array $errors,
        ?TenantAiUsagePolicyRepository $aiUsagePolicies,
        ?AiUsageEventRepository $aiUsageEvents,
        ?TenantAiTopUpRequestRepository $topUpRequests,
    ): Response {
        $policy = $this->loadTenantAiUsagePolicyForView($tenant, $aiUsagePolicies);
        $dashboard = $this->tenantAiUsageDashboardData($tenant, $policy, $aiUsageEvents ?? $this->aiUsageEvents, $topUpRequests);
        $errorHtml = '';
        foreach ($errors as $error) {
            $errorHtml .= $this->renderDismissibleAlert('alert-error', htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8'));
        }

        $content = $this->twig->render('backend/ai_usage/index.html.twig', [
            'hero_title' => 'Uso IA',
            'page_subtitle' => sprintf('Consumo, límites y solicitudes de ampliación de %s.', $tenant->getName()),
            'feedback_html' => $this->renderProfileFeedback($request),
            'error_html' => $errorHtml,
            'action_url' => '/backend/ai-usage/top-up-requests',
            'csrf_token' => $this->aiUsageTopUpRequestTokenValue(),
            'values' => $values,
            'commercial_token_options' => $this->commercialMillionOptionsWithCurrent([1, 5, 10, 25, 50, 100], $values['requestedTokens'] ?? ''),
            'dashboard' => $dashboard,
            ...$dashboard,
        ]);

        return $this->renderBackendShell('Uso IA', 'Consumo, límites y solicitudes de ampliación del negocio activo.', 'ai-usage', $content);
    }

    /**
     * @param array{aiEnabled: bool, dailyCostLimitEur: string, monthlyCostLimitEur: string, defaultModel: string, fallbackModel: string, limitAction: string} $values
     * @param string[] $errors
     */
    private function renderSuperAdminTenantAiPage(
        Request $request,
        Tenant $tenant,
        array $values,
        array $errors,
        ?TenantAiUsagePolicy $policy,
        ?TenantAiUsagePolicyRepository $aiUsagePolicies,
        ?AiUsageEventRepository $aiUsageEvents,
        ?TenantAiTopUpRequestRepository $topUpRequests,
    ): Response {
        $dashboard = $this->tenantAiUsageDashboardData($tenant, $policy, $aiUsageEvents ?? $this->aiUsageEvents, $topUpRequests);
        $errorHtml = '';
        foreach ($errors as $error) {
            $errorHtml .= $this->renderDismissibleAlert('alert-error', htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8'));
        }

        $policyValues = array_replace($this->tenantAiUsagePolicyValues($policy), $values);

        $content = $this->twig->render('backend/super_admin/tenant_ai.html.twig', [
            'tenant' => $this->superAdminTenantAiTenantView($tenant),
            'policy_exists' => $policy instanceof TenantAiUsagePolicy,
            'policy_updated_at' => $policy instanceof TenantAiUsagePolicy ? $policy->getUpdatedAt()->format('Y-m-d H:i') : '—',
            'policy_action_url' => '/backend/super-admin/tenants/'.$tenant->getId()->toRfc4122().'/ai',
            'policy_token' => $this->tenantAiSuperAdminTokenValue('/backend/super-admin/tenants/'.$tenant->getId()->toRfc4122().'/ai'),
            'policy_values' => $policyValues,
            'commercial_limit_options' => $this->commercialMillionOptionsWithCurrent([0.1, 0.25, 0.5, 1, 2, 5], $policyValues['dailyCostLimitEur'] ?? ''),
            'commercial_monthly_limit_options' => $this->commercialMillionOptionsWithCurrent([0.5, 1, 3, 5, 10, 15, 25, 50, 100], $policyValues['monthlyCostLimitEur'] ?? ''),
            'limit_action_options' => [
                ['value' => 'handoff_human', 'label' => 'handoff_human'],
                ['value' => 'block', 'label' => 'block'],
            ],
            'feedback_html' => $this->renderProfileFeedback($request),
            'error_html' => $errorHtml,
            'dashboard' => $dashboard,
            'back_url' => '/backend/tenants',
        ]);

        return $this->renderBackendShell(
            sprintf('IA del tenant - %s', $tenant->getName()),
            'Configuración técnica de IA, consumo y solicitudes para un tenant concreto.',
            'tenants',
            $content
        );
    }

    /**
     * @return array{
     *   status: array{label: string, class: string, detail: string},
     *   today: array{estimatedCostEur: string, totalTokens: string},
     *   month: array{estimatedCostEur: string, totalTokens: string},
     *   daily_limit: array{label: string, value: array{primary: string, secondary: string|null}, used: array{primary: string, secondary: string|null}, remaining: array{primary: string, secondary: string|null}, percent: ?int, percent_label: string, class: string, secondary: string},
     *   monthly_base_limit: array{label: string, value: string, note: string},
     *   monthly_top_ups: array{label: string, value: string, note: string},
     *   monthly_effective_limit: array{label: string, value: array{primary: string, secondary: string|null}, used: array{primary: string, secondary: string|null}, remaining: array{primary: string, secondary: string|null}, percent: ?int, percent_label: string, class: string, secondary: string},
     *   period: array{label: string, current: string, daily_reset: string, monthly_reset: string},
     *   recentEvents: array<int, array{
     *     createdAt: string,
     *     feature: string,
     *     provider: string,
     *     model: string,
     *     inputTokens: string,
     *     outputTokens: string,
     *     cachedTokens: string,
     *     totalTokens: string,
     *     estimatedCostEur: string,
     *     latencyMs: string,
     *     status: string,
     *     status_class: string
     *   }>,
     *   recentRequests: array<int, array{
     *     createdAt: string,
     *     amountTokens: string,
     *     message: string,
     *     status: string,
     *     status_class: string,
     *     requestedBy: string
     *   }>,
     *   policy: array{exists: bool, aiEnabled: bool, defaultModel: string, fallbackModel: string, limitAction: string, monthlyCostLimitEur: string, dailyCostLimitEur: string}
     * }
     */
    private function tenantAiUsageDashboardData(
        Tenant $tenant,
        ?TenantAiUsagePolicy $policy,
        ?AiUsageEventRepository $aiUsageEvents,
        ?TenantAiTopUpRequestRepository $topUpRequests,
    ): array {
        $timezone = new \DateTimeZone('Europe/Madrid');
        $today = new \DateTimeImmutable('today', $timezone);
        $month = new \DateTimeImmutable('first day of this month', $timezone);
        $periodKey = $this->tenantAiCurrentPeriodKey($month);
        $dailyReset = $today->modify('+1 day');
        $monthlyReset = $month->modify('+1 month');

        $todaySummary = $aiUsageEvents instanceof AiUsageEventRepository ? $aiUsageEvents->summarizeSince($tenant, $today) : [
            'estimated_cost_eur' => 0.0,
            'total_tokens' => 0,
        ];
        $monthSummary = $aiUsageEvents instanceof AiUsageEventRepository ? $aiUsageEvents->summarizeSince($tenant, $month) : [
            'estimated_cost_eur' => 0.0,
            'total_tokens' => 0,
        ];

        $tokenRate = $this->tenantAiUsageTokenRate($policy);
        $commercialPlanContext = $this->tenantCommercialPlanContext($tenant, $policy, $topUpRequests, $tokenRate);
        $dailyLimitTokens = $policy instanceof TenantAiUsagePolicy ? $this->tokenAmountFromCost($policy->getDailyCostLimitEur(), $tokenRate) : null;
        $monthlyBaseLimitTokens = $commercialPlanContext['baseTokens'];
        $approvedTopUpTokens = $topUpRequests instanceof TenantAiTopUpRequestRepository ? $topUpRequests->sumApprovedTokensByTenantAndPeriod($tenant, $periodKey) : 0;
        $monthlyEffectiveLimitTokens = $commercialPlanContext['effectiveTokens'];
        $dailyUsedTokens = (int) ($todaySummary['total_tokens'] ?? 0);
        $monthlyUsedTokens = (int) ($monthSummary['total_tokens'] ?? 0);
        $dailyRemainingTokens = $dailyLimitTokens !== null ? max(0, $dailyLimitTokens - $dailyUsedTokens) : null;
        $monthlyRemainingTokens = $monthlyEffectiveLimitTokens !== null ? max(0, $monthlyEffectiveLimitTokens - $monthlyUsedTokens) : null;
        $dailyPercent = $dailyLimitTokens !== null && $dailyLimitTokens > 0 ? min(100, (int) round(($dailyUsedTokens / $dailyLimitTokens) * 100)) : null;
        $monthlyPercent = $monthlyEffectiveLimitTokens !== null && $monthlyEffectiveLimitTokens > 0 ? min(100, (int) round(($monthlyUsedTokens / $monthlyEffectiveLimitTokens) * 100)) : null;
        $status = [
            'label' => 'Sin política',
            'class' => 'status-warn',
            'detail' => 'No hay política IA configurada. La vista se muestra en modo seguro.',
        ];
        if ($policy instanceof TenantAiUsagePolicy) {
            if (!$policy->isAiEnabled()) {
                $status = [
                    'label' => 'Inactiva',
                    'class' => 'status-off',
                    'detail' => 'La política IA está desactivada para este negocio.',
                ];
            } elseif ($dailyLimitTokens !== null && $dailyUsedTokens >= $dailyLimitTokens) {
                $status = [
                    'label' => 'Bloqueada por límite diario',
                    'class' => 'status-warn',
                    'detail' => 'El consumo diario ha alcanzado el límite configurado.',
                ];
            } elseif ($monthlyEffectiveLimitTokens !== null && $monthlyUsedTokens >= $monthlyEffectiveLimitTokens) {
                $status = [
                    'label' => 'Bloqueada por límite mensual',
                    'class' => 'status-warn',
                    'detail' => 'El consumo mensual ha alcanzado el límite configurado.',
                ];
            } else {
                $status = [
                    'label' => 'Activa',
                    'class' => 'status-ok',
                    'detail' => 'La IA está habilitada y operando dentro de los límites configurados.',
                ];
            }
        }

        $recentEvents = [];
        if ($aiUsageEvents instanceof AiUsageEventRepository) {
            $recentEvents = array_map(
                fn (AiUsageEvent $event): array => $this->tenantAiUsageDashboardEventView($event),
                $aiUsageEvents->findRecentByTenant($tenant, 5)
            );
        }

        $recentRequests = [];
        if ($topUpRequests instanceof TenantAiTopUpRequestRepository) {
            $recentRequests = array_map(
                fn (TenantAiTopUpRequest $requestEntity): array => $this->tenantAiUsageTopUpRequestView($requestEntity),
                $topUpRequests->findRecentByTenant($tenant, 5)
            );
        }

        return [
            'status' => $status,
            'today' => [
                'estimatedCostEur' => $this->formatMoneyEur((float) ($todaySummary['estimated_cost_eur'] ?? 0.0)),
                'totalTokens' => CommercialTokenFormatter::formatCommercialDual($dailyUsedTokens),
            ],
            'month' => [
                'estimatedCostEur' => $this->formatMoneyEur((float) ($monthSummary['estimated_cost_eur'] ?? 0.0)),
                'totalTokens' => CommercialTokenFormatter::formatCommercialDual($monthlyUsedTokens),
            ],
            'daily_limit' => $this->tenantAiUsageLimitView('Límite diario base', $dailyLimitTokens, $dailyUsedTokens, $dailyRemainingTokens, $dailyPercent, 'Cuota recurrente diaria del tenant.', $policy instanceof TenantAiUsagePolicy),
            'monthly_base_limit' => [
                'label' => $commercialPlanContext['baseLabel'],
                'value' => CommercialTokenFormatter::formatCommercialDual($monthlyBaseLimitTokens),
                'note' => $commercialPlanContext['baseNote'],
            ],
            'monthly_top_ups' => [
                'label' => 'Recargas aprobadas este mes',
                'value' => CommercialTokenFormatter::formatCommercialDual($approvedTopUpTokens),
                'note' => $approvedTopUpTokens > 0 ? sprintf('Aplicadas al periodo %s.', $periodKey) : 'No hay recargas aprobadas en el periodo actual.',
            ],
            'monthly_effective_limit' => $this->tenantAiUsageLimitView('Cupo efectivo este mes', $monthlyEffectiveLimitTokens, $monthlyUsedTokens, $monthlyRemainingTokens, $monthlyPercent, 'Base + recargas del periodo actual.', $policy instanceof TenantAiUsagePolicy),
            'commercial_plan' => $commercialPlanContext['commercialPlan'],
            'monthly_limit_source' => $commercialPlanContext['source'],
            'period' => [
                'label' => 'Periodo actual',
                'current' => $periodKey,
                'daily_reset' => $dailyReset->format('Y-m-d H:i'),
                'monthly_reset' => $monthlyReset->format('Y-m-d H:i'),
            ],
            'recentEvents' => $recentEvents,
            'recentRequests' => $recentRequests,
            'policy' => [
                'exists' => $policy instanceof TenantAiUsagePolicy,
                'aiEnabled' => $policy?->isAiEnabled() ?? true,
                'defaultModel' => $policy?->getDefaultModel() ?? '',
                'fallbackModel' => $policy?->getFallbackModel() ?? '',
                'defaultModelOptions' => $this->tenantAiModelOptions(AiModelCostReference::USAGE_TYPE_LLM_CHAT),
                'fallbackModelOptions' => $this->tenantAiModelOptions(AiModelCostReference::USAGE_TYPE_LLM_CHAT),
                'maxAudioTranscriptionSeconds' => (string) ($policy?->getMaxAudioTranscriptionSeconds() ?? TenantAiUsagePolicy::DEFAULT_MAX_AUDIO_TRANSCRIPTION_SECONDS),
                'audioLimitExceededMessage' => $policy?->getAudioLimitExceededMessage() ?? TenantAiUsagePolicy::DEFAULT_AUDIO_LIMIT_EXCEEDED_MESSAGE,
                'limitAction' => $policy?->getLimitAction() ?? 'handoff_human',
                'monthlyCostLimitEur' => $this->formatTokenInputFromCost($policy?->getMonthlyCostLimitEur(), $tokenRate) ?? '',
                'dailyCostLimitEur' => $this->formatTokenInputFromCost($policy?->getDailyCostLimitEur(), $tokenRate) ?? '',
            ],
        ];
    }

    /**
     * @return array{
     *     baseTokens: int|null,
     *     baseLabel: string,
     *     baseNote: string,
     *     source: 'plan'|'manual_policy'|'none',
     *     commercialPlan: array{exists: bool, code: string, name: string, tokens: array{primary: string, secondary: string|null}, summary: string, note: string}
     * }
     */
    private function tenantCommercialPlanContext(Tenant $tenant, ?TenantAiUsagePolicy $policy, ?TenantAiTopUpRequestRepository $topUpRequests, ?float $tokenRate): array
    {
        $plan = $tenant->getCommercialPlan();
        $planData = [
            'exists' => $plan instanceof CommercialPlan,
            'code' => $plan instanceof CommercialPlan ? $plan->getCode() : '',
            'name' => $plan instanceof CommercialPlan ? $plan->getName() : 'Sin plan comercial asignado',
            'tokens' => CommercialTokenFormatter::formatCommercialDual(null),
            'summary' => $plan instanceof CommercialPlan ? sprintf('%s (%s)', $plan->getName(), $plan->getCode()) : 'Sin plan comercial asignado',
            'note' => $plan instanceof CommercialPlan ? 'Tokens incluidos por el plan comercial.' : 'El cupo mensual sale de la policy manual si existe.',
        ];

        $planTokens = null;
        if ($this->planEntitlementResolver instanceof PlanEntitlementResolver) {
            $resolved = $this->planEntitlementResolver->resolve($tenant);
            $limits = $resolved['limits'] ?? [];
            $planTokens = $this->extractCommercialLimitTokens($limits['included_monthly_ai_tokens'] ?? null);
        } elseif ($plan instanceof CommercialPlan) {
            $planTokens = $this->extractCommercialLimitTokens($plan->getLimits()['included_monthly_ai_tokens'] ?? null);
        }

        $manualBaseTokens = $policy instanceof TenantAiUsagePolicy ? $this->tokenAmountFromCost($policy->getMonthlyCostLimitEur(), $tokenRate) : null;
        $source = 'none';
        $baseTokens = null;
        if ($planTokens !== null) {
            $source = 'plan';
            $baseTokens = $planTokens;
            $planData['tokens'] = CommercialTokenFormatter::formatCommercialDual($planTokens);
            $planData['note'] = sprintf('Incluye %s tokens/mes.', CommercialTokenFormatter::formatCommercialMillionTokens($planTokens));
        } elseif ($manualBaseTokens !== null) {
            $source = 'manual_policy';
            $baseTokens = $manualBaseTokens;
            $planData['note'] = 'Sin plan comercial asignado; la policy manual define el cupo base.';
        }

        $extraTokens = $topUpRequests instanceof TenantAiTopUpRequestRepository ? $topUpRequests->sumApprovedTokensByTenantAndPeriod($tenant, $this->tenantAiCurrentPeriodKey()) : 0;
        $effectiveTokens = $baseTokens !== null ? $baseTokens + $extraTokens : null;

        return [
            'baseTokens' => $baseTokens,
            'baseLabel' => $source === 'plan' ? 'Tokens incluidos por plan' : 'Plan mensual base',
            'baseNote' => $source === 'plan'
                ? sprintf('Plan comercial asignado: %s.', $planData['summary'])
                : ($source === 'manual_policy'
                    ? 'Base manual configurada en la policy del tenant.'
                    : 'No hay plan comercial ni base mensual configurada.'),
            'source' => $source,
            'commercialPlan' => $planData,
            'effectiveTokens' => $effectiveTokens,
        ];
    }

    private function extractCommercialLimitTokens(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        $tokens = (int) round((float) $value);

        return $tokens > 0 ? $tokens : null;
    }

    /**
     * @return array{label: string, value: string, used: string, remaining: string, percent: ?int, percent_label: string, class: string, secondary: string}
     */
    private function tenantAiUsageLimitView(
        string $label,
        ?int $limitTokens,
        int $usedTokens,
        ?int $remainingTokens,
        ?int $percent,
        string $secondaryNote,
        bool $hasPolicy = true,
    ): array {
        return [
            'label' => $label,
            'value' => CommercialTokenFormatter::formatCommercialDual($limitTokens),
            'used' => CommercialTokenFormatter::formatCommercialDual($usedTokens),
            'remaining' => CommercialTokenFormatter::formatCommercialDual($remainingTokens),
            'percent' => $percent,
            'percent_label' => $percent !== null ? sprintf('%d%%', $percent) : '—',
            'class' => $percent !== null && $percent >= 100 ? 'status-warn' : 'status-ok',
            'secondary' => $secondaryNote,
        ];
    }

    private function tenantAiUsageDashboardEventView(AiUsageEvent $event): array
    {
        $feature = match ($event->getUsageType()) {
            AiUsageEvent::USAGE_TYPE_AUDIO_TRANSCRIPTION => 'Transcripción audio',
            AiUsageEvent::USAGE_TYPE_LLM_CHAT => 'Chat LLM',
            default => $event->getConversationMessage() instanceof \App\Entity\ConversationMessage
                ? 'Mensajería'
                : ($event->getConversation() instanceof \App\Entity\Conversation ? 'Conversación' : 'Evento IA'),
        };

        return [
            'createdAt' => $event->getCreatedAt()->format('Y-m-d H:i'),
            'feature' => $feature,
            'provider' => $event->getProvider() !== null && $event->getProvider() !== '' ? $event->getProvider() : '—',
            'model' => $event->getModel() !== null && $event->getModel() !== '' ? $this->shortenListText($event->getModel(), 24, '—') : '—',
            'inputTokens' => CommercialTokenFormatter::formatCommercialMillionTokens($event->getInputTokens()),
            'outputTokens' => CommercialTokenFormatter::formatCommercialMillionTokens($event->getOutputTokens()),
            'cachedTokens' => CommercialTokenFormatter::formatCommercialMillionTokens($event->getCachedTokens()),
            'totalTokens' => CommercialTokenFormatter::formatCommercialMillionTokens($event->getTotalTokens()),
            'estimatedCostEur' => $this->formatMoneyEur($event->getEstimatedCost()),
            'latencyMs' => $event->getLatencyMs() !== null ? $this->formatIntegerDisplay($event->getLatencyMs()).' ms' : '—',
            'status' => $event->getTotalTokens() !== null || $event->getEstimatedCost() !== null ? 'Registrado' : 'Sin datos',
            'status_class' => $event->getTotalTokens() !== null || $event->getEstimatedCost() !== null ? 'status-ok' : 'status-warn',
        ];
    }

    private function tenantAiUsageTopUpRequestView(TenantAiTopUpRequest $requestEntity): array
    {
        $requestedBy = $requestEntity->getRequestedBy();
        $resolvedBy = $requestEntity->getResolvedBy();
        $tenantId = $requestEntity->getTenant()->getId()->toRfc4122();
        $requestId = $requestEntity->getId()->toRfc4122();
        $approvedTokens = $requestEntity->getApprovedTokens();
        if ($approvedTokens === null && $requestEntity->getStatus() === TenantAiTopUpRequest::STATUS_APPROVED) {
            $approvedTokens = max(0, (int) round($requestEntity->getRequestedAmountEur()));
        }

        return [
            'id' => $requestId,
            'tenant_id' => $tenantId,
            'tenant_name' => $requestEntity->getTenant()->getName(),
            'createdAt' => $requestEntity->getCreatedAt()->format('Y-m-d H:i'),
            'requestedTokensInput' => (string) max(0, (int) round($requestEntity->getRequestedAmountEur())),
            'requestedTokens' => CommercialTokenFormatter::formatCommercialMillionTokens((int) round($requestEntity->getRequestedAmountEur())),
            'amountTokens' => CommercialTokenFormatter::formatCommercialMillionTokens((int) round($requestEntity->getRequestedAmountEur())),
            'approvedTokens' => $approvedTokens !== null ? CommercialTokenFormatter::formatCommercialMillionTokens($approvedTokens) : '—',
            'approve_token_options' => $this->commercialMillionOptionsWithCurrent([1, 5, 10, 25, 50, 100], (string) max(0, (int) round($requestEntity->getRequestedAmountEur()))),
            'approvedPeriodKey' => $requestEntity->getApprovedPeriodKey() ?? ($requestEntity->getResolvedAt()?->format('Y-m') ?? '—'),
            'message' => $requestEntity->getMessage(),
            'status_key' => $requestEntity->getStatus(),
            'status' => match ($requestEntity->getStatus()) {
                TenantAiTopUpRequest::STATUS_APPROVED => 'Aprobada',
                TenantAiTopUpRequest::STATUS_REJECTED => 'Rechazada',
                default => 'Pendiente',
            },
            'status_class' => match ($requestEntity->getStatus()) {
                TenantAiTopUpRequest::STATUS_APPROVED => 'status-ok',
                TenantAiTopUpRequest::STATUS_REJECTED => 'status-off',
                default => 'status-warn',
            },
            'requestedBy' => $this->userDisplayLabel($requestedBy),
            'resolvedAt' => $requestEntity->getResolvedAt()?->format('Y-m-d H:i') ?? '—',
            'resolvedBy' => $this->userDisplayLabel($resolvedBy),
            'adminNotes' => $requestEntity->getAdminNotes() ?? '—',
            'approve_url' => '/backend/super-admin/tenants/'.$tenantId.'/ai/top-up-requests/'.$requestId.'/approve',
            'reject_url' => '/backend/super-admin/tenants/'.$tenantId.'/ai/top-up-requests/'.$requestId.'/reject',
            'approve_token' => $this->tenantAiSuperAdminTokenValue('/backend/super-admin/tenants/'.$tenantId.'/ai/top-up-requests/'.$requestId.'/approve'),
            'reject_token' => $this->tenantAiSuperAdminTokenValue('/backend/super-admin/tenants/'.$tenantId.'/ai/top-up-requests/'.$requestId.'/reject'),
        ];
    }

    /**
     * @param array{requestedTokens: string, message: string} $values
     *
     * @return string[]
     */
    private function validateTenantAiUsageTopUpRequestForm(array $values): array
    {
        $errors = [];

        $requestedTokens = $this->parseCommercialBlockAmount($values['requestedTokens']);
        if ($requestedTokens === null) {
            $errors[] = 'Debes indicar una cantidad de tokens solicitada válida en bloques de 1M.';
        }

        $message = trim((string) ($values['message'] ?? ''));
        if ($message === '') {
            $errors[] = 'Debes indicar el motivo de la solicitud.';
        } elseif (mb_strlen($message) > 2000) {
            $errors[] = 'El motivo de la solicitud no puede superar 2000 caracteres.';
        }

        return $errors;
    }

    /**
     * @return array{requestedTokens: string, message: string}
     */
    private function tenantAiUsageTopUpRequestFormDefaults(): array
    {
        return [
            'requestedTokens' => '1000000',
            'message' => '',
        ];
    }

    /**
     * @return array{requestedTokens: string, message: string}
     */
    private function tenantAiUsageTopUpRequestFormValuesFromRequest(Request $request): array
    {
        return [
            'requestedTokens' => trim((string) $request->request->get('requestedTokens', $request->request->get('requestedAmountEur', ''))),
            'message' => trim((string) $request->request->get('message', '')),
        ];
    }

    private function isValidTenantAiUsageTopUpRequestToken(string $value): bool
    {
        if ($this->csrfTokenManager === null) {
            return true;
        }

        return $this->csrfTokenManager->isTokenValid(new CsrfToken('tenant_ai_top_up_request', $value));
    }

    private function aiUsageTopUpRequestTokenValue(): string
    {
        if ($this->csrfTokenManager === null) {
            return '';
        }

        return $this->csrfTokenManager->getToken('tenant_ai_top_up_request')->getValue();
    }

    private function isValidTenantAiSuperAdminToken(string $actionUrl, string $value): bool
    {
        if ($this->csrfTokenManager === null) {
            return true;
        }

        return $this->csrfTokenManager->isTokenValid(new CsrfToken($this->tenantAiSuperAdminTokenId($actionUrl), $value));
    }

    private function tenantAiSuperAdminTokenValue(string $actionUrl): string
    {
        if ($this->csrfTokenManager === null) {
            return '';
        }

        return $this->csrfTokenManager->getToken($this->tenantAiSuperAdminTokenId($actionUrl))->getValue();
    }

    private function loadTenantAiUsagePolicyForView(Tenant $tenant, ?TenantAiUsagePolicyRepository $aiUsagePolicies): ?TenantAiUsagePolicy
    {
        if ($aiUsagePolicies instanceof TenantAiUsagePolicyRepository) {
            return $aiUsagePolicies->findOneByTenant($tenant);
        }

        return null;
    }

    /**
     * @return array{id: string, name: string, slug: string, isActive: bool, status_label: string, status_class: string, edit_url: string}
     */
    private function superAdminTenantAiTenantView(Tenant $tenant): array
    {
        return [
            'id' => $tenant->getId()->toRfc4122(),
            'name' => $tenant->getName(),
            'slug' => $tenant->getSlug(),
            'isActive' => $tenant->isActive(),
            'status_label' => $tenant->isActive() ? 'Activo' : 'Inactivo',
            'status_class' => $tenant->isActive() ? 'status-ok' : 'status-off',
            'edit_url' => '/backend/tenants/'.$tenant->getId()->toRfc4122().'/edit',
        ];
    }

    /**
     * @return array{aiEnabled: bool, dailyCostLimitEur: string, monthlyCostLimitEur: string, defaultModel: string, fallbackModel: string, maxAudioTranscriptionSeconds: string, audioLimitExceededMessage: string, limitAction: string}
     */
    private function tenantAiUsagePolicyFormValuesFromRequest(Request $request): array
    {
        return [
            'aiEnabled' => $request->request->has('aiEnabled'),
            'dailyCostLimitEur' => trim((string) $request->request->get('dailyCostLimitEur', '')),
            'monthlyCostLimitEur' => trim((string) $request->request->get('monthlyCostLimitEur', '')),
            'defaultModel' => trim((string) $request->request->get('defaultModel', '')),
            'fallbackModel' => trim((string) $request->request->get('fallbackModel', '')),
            'maxAudioTranscriptionSeconds' => trim((string) $request->request->get('maxAudioTranscriptionSeconds', '')),
            'audioLimitExceededMessage' => trim((string) $request->request->get('audioLimitExceededMessage', '')),
            'limitAction' => trim((string) $request->request->get('limitAction', 'handoff_human')),
        ];
    }

    private function resolveTenantForSuperAdminAi(string $id, ?TenantRepository $tenants): ?Tenant
    {
        $repository = $tenants instanceof TenantRepository ? $tenants : $this->entityManager->getRepository(Tenant::class);
        $tenant = $repository->find($id);

        return $tenant instanceof Tenant ? $tenant : null;
    }

    private function resolveTenantAiTopUpRequestForTenant(Tenant $tenant, string $requestId, ?TenantAiTopUpRequestRepository $topUpRequests): ?TenantAiTopUpRequest
    {
        if (!$topUpRequests instanceof TenantAiTopUpRequestRepository) {
            return null;
        }

        $requestEntity = $topUpRequests->find($requestId);
        if (!$requestEntity instanceof TenantAiTopUpRequest) {
            return null;
        }

        return $requestEntity->getTenant()->getId()->toRfc4122() === $tenant->getId()->toRfc4122() ? $requestEntity : null;
    }

    /**
     * @return non-empty-string
     */
    private function applyTenantAiTopUpRequestApproval(
        Tenant $tenant,
        TenantAiTopUpRequest $requestEntity,
        int $approvedTokens,
        User $resolvedBy,
    ): string {
        $periodKey = $this->tenantAiCurrentPeriodKey();
        $messages = [];

        $requestEntity->approve($resolvedBy, $approvedTokens, $periodKey);
        $messages[] = sprintf('Solicitud aprobada para el periodo %s.', $periodKey);
        $messages[] = 'La recarga queda registrada para este periodo sin modificar el límite base del tenant.';

        return trim(implode(' ', $messages));
    }

    private function tenantAiCurrentPeriodKey(?\DateTimeImmutable $date = null): string
    {
        $date ??= new \DateTimeImmutable('now', new \DateTimeZone('Europe/Madrid'));

        return $date->format('Y-m');
    }

    private function tenantAiSuperAdminTokenId(string $actionUrl): string
    {
        return 'tenant_ai_super_admin_'.md5($actionUrl);
    }

    private function parsePositiveFloat(mixed $value): ?float
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = str_replace(',', '.', trim($value));
        if ($trimmed === '' || !is_numeric($trimmed)) {
            return null;
        }

        $number = (float) $trimmed;

        return $number > 0 ? $number : null;
    }

    private function parseDateTimeLocal(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        foreach (['Y-m-d\TH:i', 'Y-m-d\TH:i:s', \DateTimeInterface::ATOM] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $trimmed);
            if ($date instanceof \DateTimeImmutable) {
                return $date;
            }
        }

        try {
            return new \DateTimeImmutable($trimmed);
        } catch (\Throwable) {
            return null;
        }
    }

    private function userDisplayLabel(?User $user): string
    {
        if (!$user instanceof User) {
            return 'Sistema';
        }

        return $user->getName() !== '' ? $user->getName() : $user->getEmail();
    }

    /**
     * @return array{createdAt: string, provider: string, model: string, inputTokens: string, outputTokens: string, cachedTokens: string, totalTokens: string, estimatedCostEur: string, latencyMs: string}
     */
    private function tenantAiUsageEventView(AiUsageEvent $event): array
    {
        return [
            'createdAt' => $event->getCreatedAt()->format('Y-m-d H:i'),
            'provider' => $event->getProvider() !== null && $event->getProvider() !== '' ? $event->getProvider() : '—',
            'model' => $event->getModel() !== null && $event->getModel() !== '' ? $this->shortenListText($event->getModel(), 24, '—') : '—',
            'inputTokens' => $this->formatIntegerDisplay($event->getInputTokens()),
            'outputTokens' => $this->formatIntegerDisplay($event->getOutputTokens()),
            'cachedTokens' => $this->formatIntegerDisplay($event->getCachedTokens()),
            'totalTokens' => $this->formatIntegerDisplay($event->getTotalTokens()),
            'estimatedCostEur' => $this->formatMoneyEur($event->getEstimatedCost()),
            'latencyMs' => $event->getLatencyMs() !== null ? $this->formatIntegerDisplay($event->getLatencyMs()).' ms' : '—',
        ];
    }

    private function hydrateTenantAiUsagePolicyFromForm(TenantAiUsagePolicy $policy, array $values, ?float $costPerToken = null): void
    {
        $policy->setAiEnabled($values['aiEnabled']);
        $dailyTokens = $this->parseCommercialTokenAmount($values['dailyCostLimitEur']);
        $monthlyTokens = $this->parseCommercialTokenAmount($values['monthlyCostLimitEur']);
        $policy->setDailyCostLimitEur($dailyTokens !== null ? $this->costAmountFromTokens($dailyTokens, $costPerToken) : null);
        $policy->setMonthlyCostLimitEur($monthlyTokens !== null ? $this->costAmountFromTokens($monthlyTokens, $costPerToken) : null);
        $policy->setDefaultModel($values['defaultModel'] !== '' ? $values['defaultModel'] : null);
        $policy->setFallbackModel($values['fallbackModel'] !== '' ? $values['fallbackModel'] : null);
        $maxAudioTranscriptionSeconds = $values['maxAudioTranscriptionSeconds'] ?? '';
        $audioLimitExceededMessage = $values['audioLimitExceededMessage'] ?? '';
        $policy->setMaxAudioTranscriptionSeconds($maxAudioTranscriptionSeconds !== '' ? (int) round((float) $maxAudioTranscriptionSeconds) : null);
        $policy->setAudioLimitExceededMessage($audioLimitExceededMessage !== '' ? $audioLimitExceededMessage : null);
        $policy->setLimitAction($values['limitAction']);
    }

    private function parseNullableFloat(mixed $value): ?float
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        return is_numeric($trimmed) ? (float) $trimmed : null;
    }

    private function parsePositiveInt(mixed $value): ?int
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '' || !is_numeric($trimmed)) {
            return null;
        }

        $number = (int) round((float) $trimmed);

        return $number >= 0 ? $number : null;
    }

    private function parseCommercialTokenAmount(mixed $value): ?int
    {
        $amount = $this->parsePositiveInt($value);
        if ($amount === null || $amount < 1) {
            return null;
        }

        return $amount;
    }

    private function parseCommercialBlockAmount(mixed $value): ?int
    {
        $amount = $this->parsePositiveInt($value);
        if ($amount === null || $amount < 1 || $amount % 1_000_000 !== 0) {
            return null;
        }

        return $amount;
    }

    private function formatNullableFloat(?float $value): string
    {
        if ($value === null) {
            return '';
        }

        $formatted = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');

        return $formatted === '0' ? '0' : $formatted;
    }

    private function formatMoneyEur(?float $value): string
    {
        return sprintf('%s €', $this->formatMoneyValue($value));
    }

    private function formatCommercialTokenValue(mixed $value): string
    {
        if (!is_string($value)) {
            return 'Sin límite';
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return 'Sin límite';
        }

        if (!is_numeric($trimmed)) {
            return $trimmed;
        }

        return CommercialTokenFormatter::formatCommercialMillionTokens((int) round((float) $trimmed));
    }

    private function formatTokenInputFromCost(?float $cost, ?float $costPerToken): ?string
    {
        $tokens = $this->tokenAmountFromCost($cost, $costPerToken);

        return $tokens !== null ? (string) $tokens : null;
    }

    private function tokenAmountFromCost(?float $cost, ?float $costPerToken): ?int
    {
        if ($cost === null) {
            return null;
        }

        if ($costPerToken === null || $costPerToken <= 0.0) {
            return (int) round($cost);
        }

        return (int) round($cost / $costPerToken);
    }

    private function costAmountFromTokens(?int $tokens, ?float $costPerToken): ?float
    {
        if ($tokens === null) {
            return null;
        }

        if ($costPerToken === null || $costPerToken <= 0.0) {
            return (float) $tokens;
        }

        return round($tokens * $costPerToken, 8);
    }

    private function formatTokenRemainingFromCost(?float $limitCost, array $summary, ?float $costPerToken): ?int
    {
        $limitTokens = $this->tokenAmountFromCost($limitCost, $costPerToken);
        if ($limitTokens === null) {
            return null;
        }

        $usedTokens = (int) ($summary['total_tokens'] ?? 0);

        return max(0, $limitTokens - $usedTokens);
    }

    private function tenantAiUsagePercentFromCost(?float $limitCost, array $summary, ?float $costPerToken): ?int
    {
        $limitTokens = $this->tokenAmountFromCost($limitCost, $costPerToken);
        if ($limitTokens === null || $limitTokens <= 0) {
            return null;
        }

        $usedTokens = (int) ($summary['total_tokens'] ?? 0);

        return min(100, (int) round(($usedTokens / $limitTokens) * 100));
    }

    private function tenantAiUsageTokenRate(?TenantAiUsagePolicy $policy): float
    {
        return $this->tenantAiUsageModelAverageCostPerToken($policy?->getDefaultModel() ?? $policy?->getFallbackModel());
    }

    private function tenantAiUsageModelAverageCostPerToken(?string $model): float
    {
        $pricing = $this->tenantAiModelPricing($model);
        if ($pricing === null) {
            return 0.000001;
        }

        return (($pricing['input'] + $pricing['output'] + $pricing['cached_input']) / 3) / 1_000_000;
    }

    private function tenantAiModelPricing(?string $model): ?array
    {
        $normalized = strtolower(trim((string) $model));
        if ($normalized === '') {
            return null;
        }

        if ($this->aiModelCosts instanceof AiModelCostReferenceRepository) {
            $reference = $this->aiModelCosts->findOneByUsageTypeAndModel(AiModelCostReference::USAGE_TYPE_LLM_CHAT, $normalized);
            if ($reference instanceof AiModelCostReference && $reference->isActive()) {
                return [
                    'input' => $reference->getInputCostPerMillion() ?? 0.0,
                    'output' => $reference->getOutputCostPerMillion() ?? 0.0,
                    'cached_input' => $reference->getCachedInputCostPerMillion() ?? 0.0,
                ];
            }
        }

        $pricingTable = [
            'gpt-4.1' => ['input' => 2.0, 'output' => 8.0, 'cached_input' => 0.5],
            'gpt-4.1-mini' => ['input' => 0.4, 'output' => 1.6, 'cached_input' => 0.1],
            'gpt-4o' => ['input' => 2.5, 'output' => 10.0, 'cached_input' => 0.625],
            'gpt-4o-mini' => ['input' => 0.15, 'output' => 0.6, 'cached_input' => 0.0375],
            'gpt-5.4-mini' => ['input' => 0.75, 'output' => 4.5, 'cached_input' => 0.075],
        ];

        if (isset($pricingTable[$normalized])) {
            return $pricingTable[$normalized];
        }

        foreach ($pricingTable as $key => $pricing) {
            if (str_starts_with($normalized, $key)) {
                return $pricing;
            }
        }

        return null;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function tenantAiModelOptions(string $usageType): array
    {
        if ($this->aiModelCosts instanceof AiModelCostReferenceRepository) {
            $references = $this->aiModelCosts->findActiveByUsageType($usageType);
            if ($references !== []) {
                return array_map(
                    function (AiModelCostReference $reference): array {
                        $label = $reference->getModel();
                        if ($reference->getUsageType() === AiModelCostReference::USAGE_TYPE_LLM_CHAT) {
                            $label .= sprintf(
                                ' (%s/%s/%s %s)',
                                $this->formatPricingAmount($reference->getInputCostPerMillion()),
                                $this->formatPricingAmount($reference->getCachedInputCostPerMillion()),
                                $this->formatPricingAmount($reference->getOutputCostPerMillion()),
                                $reference->getCurrency()
                            );
                        } else {
                            $label .= sprintf(
                                ' (%s / %s %s)',
                                $this->formatPricingAmount($reference->getCostPerUnit()),
                                $reference->getCostUnit() ?? 'minute',
                                $reference->getCurrency()
                            );
                        }

                        return [
                            'value' => $reference->getModel(),
                            'label' => $label,
                        ];
                    },
                    $references
                );
            }
        }

        if ($usageType === AiModelCostReference::USAGE_TYPE_LLM_CHAT) {
            return [
                ['value' => 'gpt-4.1', 'label' => 'gpt-4.1 (2/0.5/8 USD)'],
                ['value' => 'gpt-4.1-mini', 'label' => 'gpt-4.1-mini (0.4/0.1/1.6 USD)'],
                ['value' => 'gpt-4o', 'label' => 'gpt-4o (2.5/0.625/10 USD)'],
                ['value' => 'gpt-4o-mini', 'label' => 'gpt-4o-mini (0.15/0.0375/0.6 USD)'],
                ['value' => 'gpt-5.4-mini', 'label' => 'gpt-5.4-mini (0.75/0.075/4.5 USD)'],
            ];
        }

        return [
            ['value' => 'gpt-4o-mini-transcribe', 'label' => 'gpt-4o-mini-transcribe (0.02 EUR / minute)'],
        ];
    }

    private function formatPricingAmount(?float $value): string
    {
        if ($value === null) {
            return '—';
        }

        $formatted = rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function tenantAiModelOptionsWithCurrent(string $usageType, ?string $currentModel): array
    {
        $options = $this->tenantAiModelOptions($usageType);
        $current = strtolower(trim((string) $currentModel));
        if ($current === '') {
            return $options;
        }

        foreach ($options as $option) {
            if (($option['value'] ?? '') === $current) {
                return $options;
            }
        }

        array_unshift($options, [
            'value' => $current,
            'label' => $current,
        ]);

        return $options;
    }

    /**
     * @param array<int, int|float> $millionValues
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function commercialMillionOptionsWithCurrent(array $millionValues, mixed $currentValue): array
    {
        $options = CommercialTokenFormatter::millionOptions($millionValues);

        $currentTokens = $this->parsePositiveInt($currentValue);
        if ($currentTokens === null) {
            return $options;
        }

        $currentValueString = (string) $currentTokens;
        foreach ($options as $option) {
            if (($option['value'] ?? '') === $currentValueString) {
                return $options;
            }
        }

        array_unshift($options, [
            'value' => $currentValueString,
            'label' => CommercialTokenFormatter::formatCommercialMillionTokens($currentTokens),
        ]);

        return $options;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function subscriptionStatusOptions(): array
    {
        return [
            ['value' => '', 'label' => 'Sin estado'],
            ['value' => 'trialing', 'label' => 'trialing'],
            ['value' => 'active', 'label' => 'active'],
            ['value' => 'past_due', 'label' => 'past_due'],
            ['value' => 'cancelled', 'label' => 'cancelled'],
            ['value' => 'manual', 'label' => 'manual'],
            ['value' => 'paused', 'label' => 'paused'],
        ];
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function commercialPlanOptionsWithCurrent(?CommercialPlanRepository $commercialPlans, mixed $currentValue): array
    {
        $options = [];

        if ($commercialPlans instanceof CommercialPlanRepository) {
            foreach ($commercialPlans->findActiveOrdered() as $plan) {
                $options[] = [
                    'value' => $plan->getId()->toRfc4122(),
                    'label' => $this->commercialPlanLabel($plan),
                ];
            }
        }

        $current = trim((string) $currentValue);
        if ($current === '') {
            array_unshift($options, [
                'value' => '',
                'label' => 'Sin plan asignado',
            ]);

            return $options;
        }

        foreach ($options as $option) {
            if (($option['value'] ?? '') === $current) {
                return $options;
            }
        }

        if ($commercialPlans instanceof CommercialPlanRepository) {
            $currentPlan = $commercialPlans->find($current);
            if ($currentPlan instanceof CommercialPlan) {
                array_unshift($options, [
                    'value' => $currentPlan->getId()->toRfc4122(),
                    'label' => $this->commercialPlanLabel($currentPlan),
                ]);

                return $options;
            }
        }

        array_unshift($options, [
            'value' => $current,
            'label' => $current,
        ]);

        return $options;
    }

    private function commercialPlanSummary(?CommercialPlan $plan): string
    {
        if (!$plan instanceof CommercialPlan) {
            return 'Sin plan asignado';
        }

        return $this->commercialPlanLabel($plan);
    }

    private function commercialPlanSummaryFromValue(?CommercialPlanRepository $commercialPlans, mixed $currentValue): string
    {
        $current = trim((string) $currentValue);
        if ($current === '') {
            return 'Sin plan asignado';
        }

        if ($commercialPlans instanceof CommercialPlanRepository) {
            $plan = $commercialPlans->find($current);
            if ($plan instanceof CommercialPlan) {
                return $this->commercialPlanLabel($plan);
            }
        }

        return $current;
    }

    private function commercialPlanLabel(CommercialPlan $plan): string
    {
        $monthly = $plan->getMonthlyPriceEur() !== null ? $this->formatMoneyValue((float) $plan->getMonthlyPriceEur()) : '—';
        $yearly = $plan->getYearlyPriceEur() !== null ? $this->formatMoneyValue((float) $plan->getYearlyPriceEur()) : '—';

        return sprintf('%s (%s €/mes, %s €/año)', $plan->getName(), $monthly, $yearly);
    }

    private function formatIntegerDisplay(?int $value): string
    {
        return number_format($value ?? 0, 0, ',', '.');
    }

    private function formatMoneyValue(?float $value): string
    {
        $formatted = rtrim(rtrim(number_format($value ?? 0.0, 6, ',', '.'), '0'), ',');

        return $formatted === '' ? '0' : $formatted;
    }

    /**
     * @param array{name: string, slug: string, businessContext: string, tone: string, whatsappPhoneNumberId: string, whatsappPublicPhone: string, positioning: string, qualificationFocus: string, handoffRules: string, salesBoundaries: string, notes: string, isActive: bool} $values
     * @return array<string, mixed>
     */
    private function tenantSalesPolicyFromForm(array $values): array
    {
        $salesBoundaries = preg_split('/\R+/', $values['salesBoundaries']) ?: [];
        $salesBoundaries = array_values(array_filter(array_map('trim', $salesBoundaries), static fn (string $value): bool => $value !== ''));

        return CommercialDomainSchema::normalizeTenantSalesPolicy([
            'positioning' => $values['positioning'],
            'qualificationFocus' => $values['qualificationFocus'],
            'handoffRules' => $values['handoffRules'],
            'salesBoundaries' => $salesBoundaries,
            'notes' => $values['notes'],
        ]);
    }

    private function tenantPolicyValue(array $salesPolicy, string $key): string
    {
        $value = $salesPolicy[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    private function tenantPolicyLines(array $salesPolicy, string $key): string
    {
        $value = $salesPolicy[$key] ?? [];
        if (!is_array($value)) {
            return '';
        }

        $lines = array_values(array_filter(array_map(static function (mixed $item): string {
            return is_string($item) ? trim($item) : '';
        }, $value), static fn (string $item): bool => $item !== ''));

        return implode("\n", $lines);
    }

    private static function iconEditSvg(): string
    {
        return '<svg width="16" height="16" viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>';
    }

    private static function iconDetailSvg(): string
    {
        return '<svg width="16" height="16" viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 17L17 7"/><path d="M8 7h9v9"/></svg>';
    }

    private static function iconDeleteSvg(): string
    {
        return '<svg width="16" height="16" viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M6 6l1 14h10l1-14"/><path d="M10 11v5"/><path d="M14 11v5"/></svg>';
    }

    private function tenantTokenValue(string $actionUrl): string
    {
        if ($this->csrfTokenManager === null) {
            return '';
        }

        return $this->csrfTokenManager->getToken($this->tenantTokenId($actionUrl))->getValue();
    }

    private function tenantDraftAssistantTokenValue(): string
    {
        if ($this->csrfTokenManager === null) {
            return '';
        }

        return $this->csrfTokenManager->getToken('tenant_ai_draft_assistant')->getValue();
    }

    private function playbookDraftAssistantTokenValue(): string
    {
        if ($this->csrfTokenManager === null) {
            return '';
        }

        return $this->csrfTokenManager->getToken('playbook_ai_draft_assistant')->getValue();
    }

    private function isValidTenantToken(string $actionUrl, string $value): bool
    {
        if ($this->csrfTokenManager === null) {
            return true;
        }

        return $this->csrfTokenManager->isTokenValid(new CsrfToken($this->tenantTokenId($actionUrl), $value));
    }

    private function tenantTokenId(string $actionUrl): string
    {
        return 'tenant_'.md5($actionUrl);
    }

    /**
     * @return array{tenantId: string, productId: string, name: string, objective: string, qualificationQuestions: string, maxScore: string, handoffThreshold: string, positiveSignals: string, negativeSignals: string, agendaRules: string, handoffRules: string, allowedActions: string, notes: string, isActive: bool}
     */
    private function playbookFormDefaults(?Playbook $playbook = null, ?Tenant $tenant = null): array
    {
        $config = $playbook?->getConfig() ?? [];
        $scoring = is_array($config['scoring'] ?? null) ? $config['scoring'] : [];

        return [
            'tenantId' => $tenant?->getId()->toRfc4122() ?? $playbook?->getTenant()?->getId()->toRfc4122() ?? '',
            'productId' => $playbook?->getProduct()?->getId()->toRfc4122() ?? '',
            'name' => $playbook?->getName() ?? '',
            'objective' => $this->playbookConfigValue($config, 'objective'),
            'qualificationQuestions' => $this->playbookConfigLines($config, 'qualificationQuestions'),
            'maxScore' => isset($scoring['maxScore']) && is_int($scoring['maxScore']) ? (string) $scoring['maxScore'] : '',
            'handoffThreshold' => isset($scoring['handoffThreshold']) && is_int($scoring['handoffThreshold']) ? (string) $scoring['handoffThreshold'] : '',
            'positiveSignals' => $this->playbookConfigLines($scoring, 'positiveSignals'),
            'negativeSignals' => $this->playbookConfigLines($scoring, 'negativeSignals'),
            'agendaRules' => $this->playbookConfigLines($config, 'agendaRules'),
            'handoffRules' => $this->playbookConfigLines($config, 'handoffRules'),
            'allowedActions' => $this->playbookConfigLines($config, 'allowedActions'),
            'notes' => $this->playbookConfigValue($config, 'notes'),
            'isActive' => $playbook?->isActive() ?? true,
        ];
    }

    /**
     * @return array{tenantId: string, productId: string, name: string, objective: string, qualificationQuestions: string, maxScore: string, handoffThreshold: string, positiveSignals: string, negativeSignals: string, agendaRules: string, handoffRules: string, allowedActions: string, notes: string, isActive: bool}
     */
    private function playbookFormValuesFromRequest(Request $request): array
    {
        return [
            'tenantId' => trim((string) $request->request->get('tenantId', '')),
            'productId' => trim((string) $request->request->get('productId', '')),
            'name' => trim((string) $request->request->get('name', '')),
            'objective' => trim((string) $request->request->get('objective', '')),
            'qualificationQuestions' => trim((string) $request->request->get('qualificationQuestions', '')),
            'maxScore' => trim((string) $request->request->get('maxScore', '10')),
            'handoffThreshold' => trim((string) $request->request->get('handoffThreshold', '7')),
            'positiveSignals' => trim((string) $request->request->get('positiveSignals', '')),
            'negativeSignals' => trim((string) $request->request->get('negativeSignals', '')),
            'agendaRules' => trim((string) $request->request->get('agendaRules', '')),
            'handoffRules' => trim((string) $request->request->get('handoffRules', '')),
            'allowedActions' => trim((string) $request->request->get('allowedActions', '')),
            'notes' => trim((string) $request->request->get('notes', '')),
            'isActive' => $request->request->has('isActive'),
        ];
    }

    private function renderPlaybookForm(
        string $pageTitle,
        string $pageSubtitle,
        string $heroTitle,
        string $submitLabel,
        string $actionUrl,
        array $values,
        ?ProductRepository $products,
        ?string $error = null,
    ): Response {
        $activeTenant = $this->resolvedActiveTenantForCurrentUser();
        $productOptions = $this->renderProductOptions($products, $values['productId'] ?? '', $activeTenant instanceof Tenant ? $activeTenant : null);
        $errorHtml = $error !== null ? sprintf(
            '<div class="form-alert form-alert-error">%s</div>',
            htmlspecialchars($error, ENT_QUOTES, 'UTF-8')
        ) : '';
        $content = $this->twig->render('backend/playbooks/form.html.twig', [
            'hero_title' => $heroTitle,
            'page_subtitle' => $pageSubtitle,
            'error_html' => $errorHtml,
            'action_url' => $actionUrl,
            'csrf_token' => $this->playbookTokenValue($actionUrl),
            'playbook_ai_assistant_endpoint' => '/backend/ai/playbook-draft-assistant',
            'playbook_ai_assistant_token' => $this->playbookDraftAssistantTokenValue(),
            'playbook_ai_assistant_initial_message' => 'Te ayudaré a definir una estrategia específica para esta guía comercial. Cuéntame el caso, el objetivo, el tipo de lead y cuándo derivar, y yo te ordenaré un borrador sobre el formulario. Yo no guardo nada: tú revisarás y guardarás manualmente.',
            'product_options_html' => $productOptions,
            'values' => $values,
            'submit_label' => $submitLabel,
            'active_tenant_name' => $activeTenant instanceof Tenant ? $activeTenant->getName() : null,
        ]);

        return $this->renderBackendShell($pageTitle, $pageSubtitle, 'playbooks', $content);
    }

    private function validatePlaybookForm(array $values, ?Playbook $playbook, Tenant $tenant, ?ProductRepository $products, ?PlaybookRepository $playbooks): ?string
    {
        if ($values['name'] === '') {
            return 'El nombre de la guía comercial es obligatorio.';
        }

        if ($values['productId'] !== '') {
            if (!$products instanceof ProductRepository || !$products->find($values['productId']) instanceof Product) {
                return 'El producto seleccionado no existe.';
            }

            $product = $products->find($values['productId']);
            if ($product instanceof Product && $product->getTenant()->getId()->toRfc4122() !== $tenant->getId()->toRfc4122()) {
                return 'El producto seleccionado no pertenece al negocio activo.';
            }
        }

        $config = $this->playbookConfigFromForm($values);
        $error = CommercialDomainSchema::validatePlaybookConfig($config);
        if ($error !== null) {
            return sprintf('La guía comercial no es válida: %s', $error);
        }

        return null;
    }

    private function hydratePlaybookFromForm(Playbook $playbook, array $values, Tenant $tenant, ?ProductRepository $products): void
    {
        $playbook->setTenant($tenant);

        $product = null;
        if ($values['productId'] !== '' && $products instanceof ProductRepository) {
            $candidate = $products->find($values['productId']);
            if ($candidate instanceof Product) {
                if ($candidate->getTenant()->getId()->toRfc4122() !== $tenant->getId()->toRfc4122()) {
                    $candidate = null;
                }
            }
            if ($candidate instanceof Product) {
                $product = $candidate;
            }
        }

        $playbook->setProduct($product);
        $playbook->setName($values['name']);
        $playbook->setConfig($this->playbookConfigFromForm($values));
        $playbook->setActive($values['isActive']);
    }

    /**
     * @param array{tenantId: string, productId: string, name: string, objective: string, qualificationQuestions: string, maxScore: string, handoffThreshold: string, positiveSignals: string, negativeSignals: string, agendaRules: string, handoffRules: string, allowedActions: string, notes: string, isActive: bool} $values
     * @return array<string, mixed>
     */
    private function playbookConfigFromForm(array $values): array
    {
        $config = [];

        if (trim($values['objective']) !== '') {
            $config['objective'] = $values['objective'];
        }

        $qualificationQuestions = $this->linesFromTextarea($values['qualificationQuestions']);
        if ($qualificationQuestions !== []) {
            $config['qualificationQuestions'] = $qualificationQuestions;
        }

        $scoring = [];
        if (trim($values['maxScore']) !== '') {
            $scoring['maxScore'] = (int) $values['maxScore'];
        }
        if (trim($values['handoffThreshold']) !== '') {
            $scoring['handoffThreshold'] = (int) $values['handoffThreshold'];
        }
        $positiveSignals = $this->linesFromTextarea($values['positiveSignals']);
        if ($positiveSignals !== []) {
            $scoring['positiveSignals'] = $positiveSignals;
        }
        $negativeSignals = $this->linesFromTextarea($values['negativeSignals']);
        if ($negativeSignals !== []) {
            $scoring['negativeSignals'] = $negativeSignals;
        }
        if ($scoring !== []) {
            $config['scoring'] = $scoring;
        }

        $agendaRules = $this->linesFromTextarea($values['agendaRules']);
        if ($agendaRules !== []) {
            $config['agendaRules'] = $agendaRules;
        }

        $handoffRules = $this->linesFromTextarea($values['handoffRules']);
        if ($handoffRules !== []) {
            $config['handoffRules'] = $handoffRules;
        }

        $allowedActions = $this->linesFromTextarea($values['allowedActions']);
        if ($allowedActions !== []) {
            $config['allowedActions'] = $allowedActions;
        }

        if (trim($values['notes']) !== '') {
            $config['notes'] = $values['notes'];
        }

        return CommercialDomainSchema::normalizePlaybookConfig($config);
    }

    private function playbookConfigValue(array $config, string $key): string
    {
        $value = $config[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    private function playbookConfigLines(array $config, string $key): string
    {
        $value = $config[$key] ?? [];
        if (!is_array($value)) {
            return '';
        }

        $lines = array_values(array_filter(array_map(static fn (mixed $item): string => is_string($item) ? trim($item) : '', $value), static fn (string $item): bool => $item !== ''));

        return implode("\n", $lines);
    }

    private function playbookTokenValue(string $actionUrl): string
    {
        if ($this->csrfTokenManager === null) {
            return '';
        }

        return $this->csrfTokenManager->getToken($this->playbookTokenId($actionUrl))->getValue();
    }

    private function isValidPlaybookToken(string $actionUrl, string $value): bool
    {
        if ($this->csrfTokenManager === null) {
            return true;
        }

        return $this->csrfTokenManager->isTokenValid(new CsrfToken($this->playbookTokenId($actionUrl), $value));
    }

    private function playbookTokenId(string $actionUrl): string
    {
        return 'playbook_'.md5($actionUrl);
    }

    /**
     * @return array{tenantId: string, slug: string, externalSource: string, externalReference: string, name: string, description: string, valueProposition: string, basePriceCents: string, currency: string, positioning: string, pricingNotes: string, objections: string, handoffRules: string, notes: string, isActive: bool}
     */
    private function productFormDefaults(?Product $product = null, ?Tenant $tenant = null): array
    {
        $salesPolicy = $product?->getSalesPolicy() ?? [];

        return [
            'id' => $product?->getId()->toRfc4122() ?? '',
            'tenantId' => $tenant?->getId()->toRfc4122() ?? $product?->getTenant()?->getId()->toRfc4122() ?? '',
            'slug' => $product?->getSlug() ?? '',
            'externalSource' => $product?->getExternalSource() ?? '',
            'externalReference' => $product?->getExternalReference() ?? '',
            'name' => $product?->getName() ?? '',
            'description' => $product?->getDescription() ?? '',
            'valueProposition' => $product?->getValueProposition() ?? '',
            'basePriceCents' => $product?->getBasePriceCents() !== null ? (string) $product->getBasePriceCents() : '',
            'currency' => $product?->getCurrency() ?? '',
            'positioning' => $this->productPolicyValue($salesPolicy, 'positioning'),
            'pricingNotes' => $this->productPolicyValue($salesPolicy, 'pricingNotes'),
            'objections' => $this->productPolicyLines($salesPolicy, 'objections'),
            'handoffRules' => $this->productPolicyValue($salesPolicy, 'handoffRules'),
            'notes' => $this->productPolicyValue($salesPolicy, 'notes'),
            'isActive' => $product?->isActive() ?? true,
        ];
    }

    /**
     * @return array{id: string, tenantId: string, slug: string, externalSource: string, externalReference: string, name: string, description: string, valueProposition: string, basePriceCents: string, currency: string, positioning: string, pricingNotes: string, objections: string, handoffRules: string, notes: string, isActive: bool}
     */
    private function productFormValuesFromRequest(Request $request): array
    {
        return [
            'tenantId' => trim((string) $request->request->get('tenantId', '')),
            'slug' => trim((string) $request->request->get('slug', '')),
            'externalSource' => trim((string) $request->request->get('externalSource', '')),
            'externalReference' => trim((string) $request->request->get('externalReference', '')),
            'name' => trim((string) $request->request->get('name', '')),
            'description' => trim((string) $request->request->get('description', '')),
            'valueProposition' => trim((string) $request->request->get('valueProposition', '')),
            'basePriceCents' => trim((string) $request->request->get('basePriceCents', '')),
            'currency' => trim((string) $request->request->get('currency', '')),
            'positioning' => trim((string) $request->request->get('positioning', '')),
            'pricingNotes' => trim((string) $request->request->get('pricingNotes', '')),
            'objections' => trim((string) $request->request->get('objections', '')),
            'handoffRules' => trim((string) $request->request->get('handoffRules', '')),
            'notes' => trim((string) $request->request->get('notes', '')),
            'isActive' => $request->request->has('isActive'),
        ];
    }

    private function renderProductForm(
        string $pageTitle,
        string $pageSubtitle,
        string $heroTitle,
        string $submitLabel,
        string $actionUrl,
        array $values,
        ?string $error = null,
    ): Response {
        $errorHtml = $error !== null ? sprintf(
            '<div class="form-alert form-alert-error">%s</div>',
            htmlspecialchars($error, ENT_QUOTES, 'UTF-8')
        ) : '';
        $content = $this->twig->render('backend/products/form.html.twig', [
            'hero_title' => $heroTitle,
            'page_subtitle' => $pageSubtitle,
            'error_html' => $errorHtml,
            'action_url' => $actionUrl,
            'csrf_token' => $this->productTokenValue($actionUrl),
            'values' => $values,
            'submit_label' => $submitLabel,
            ...$this->currentUserTemplateData(),
        ]);

        return $this->renderBackendShell($pageTitle, $pageSubtitle, 'products', $content);
    }

    private function validateProductForm(array $values, ?Product $product, Tenant $tenant, ?ProductRepository $products): ?string
    {
        if ($values['name'] === '') {
            return 'El nombre del producto o servicio es obligatorio.';
        }

        if ($values['slug'] !== '' && mb_strlen($values['slug']) > 180) {
            return 'El slug no puede superar 180 caracteres.';
        }

        if ($values['externalSource'] !== '' && mb_strlen($values['externalSource']) > 100) {
            return 'El origen externo no puede superar 100 caracteres.';
        }

        if ($values['externalReference'] !== '' && mb_strlen($values['externalReference']) > 255) {
            return 'La referencia externa no puede superar 255 caracteres.';
        }

        if ($values['basePriceCents'] !== '' && (!ctype_digit($values['basePriceCents']) || (int) $values['basePriceCents'] < 0)) {
            return 'El precio base debe ser un número entero positivo en céntimos.';
        }

        if ($values['currency'] !== '' && mb_strlen($values['currency']) > 10) {
            return 'La moneda no puede superar 10 caracteres.';
        }

        $slugToValidate = $values['slug'];
        if ($slugToValidate === '' && $product === null && $tenant instanceof Tenant) {
            $slugToValidate = (new Product($tenant, $values['name']))->getSlug();
        }

        if ($slugToValidate !== '' && $products instanceof ProductRepository) {
            $slugProduct = $products->findOneByTenantAndSlug($tenant, $slugToValidate);
            if ($slugProduct instanceof Product && ($product === null || $slugProduct->getId()->toRfc4122() !== $product->getId()->toRfc4122())) {
                return 'Ya existe otro producto o servicio con ese slug en el negocio seleccionado.';
            }
        }

        if ($values['externalSource'] !== '' && $values['externalReference'] !== '' && $products instanceof ProductRepository) {
            $externalProduct = $products->findOneByExternalIdentity($tenant, $values['externalSource'], $values['externalReference']);
            if ($externalProduct instanceof Product && ($product === null || $externalProduct->getId()->toRfc4122() !== $product->getId()->toRfc4122())) {
                return 'Ya existe otro producto o servicio con esa referencia externa en el negocio seleccionado.';
            }
        }

        $salesPolicy = $this->productPolicyFromForm($values);
        $error = CommercialDomainSchema::validateProductSalesPolicy($salesPolicy);
        if ($error !== null) {
            return sprintf('La política comercial del producto no es válida: %s', $error);
        }

        return null;
    }

    private function hydrateProductFromForm(Product $product, array $values, Tenant $tenant): void
    {
        $product->setTenant($tenant);

        $product->setName($values['name']);
        if ($values['slug'] !== '') {
            $product->setSlug($values['slug']);
        }
        $product->setExternalSource($values['externalSource'] !== '' ? $values['externalSource'] : null);
        $product->setExternalReference($values['externalReference'] !== '' ? $values['externalReference'] : null);
        $product->setDescription($values['description']);
        $product->setValueProposition($values['valueProposition']);
        $product->setBasePriceCents($values['basePriceCents'] !== '' ? (int) $values['basePriceCents'] : null);
        $product->setCurrency($values['currency'] !== '' ? $values['currency'] : null);
        $product->setSalesPolicy($this->productPolicyFromForm($values));
        $product->setActive($values['isActive']);
    }

    /**
     * @param array{tenantId: string, slug: string, externalSource: string, externalReference: string, name: string, description: string, valueProposition: string, basePriceCents: string, currency: string, positioning: string, pricingNotes: string, objections: string, handoffRules: string, notes: string, isActive: bool} $values
     * @return array<string, mixed>
     */
    private function productPolicyFromForm(array $values): array
    {
        return CommercialDomainSchema::normalizeProductSalesPolicy([
            'positioning' => $values['positioning'],
            'pricingNotes' => $values['pricingNotes'],
            'objections' => $this->linesFromTextarea($values['objections']),
            'handoffRules' => $values['handoffRules'],
            'notes' => $values['notes'],
        ]);
    }

    private function productPolicyValue(array $salesPolicy, string $key): string
    {
        $value = $salesPolicy[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    private function productPolicyLines(array $salesPolicy, string $key): string
    {
        $value = $salesPolicy[$key] ?? [];
        if (!is_array($value)) {
            return '';
        }

        $lines = array_values(array_filter(array_map(static fn (mixed $item): string => is_string($item) ? trim($item) : '', $value), static fn (string $item): bool => $item !== ''));

        return implode("\n", $lines);
    }

    /**
     * @return array{tenantId: string, format: string, payload: string}
     */
    private function productImportFormDefaults(Tenant $tenant): array
    {
        return [
            'tenantId' => $tenant->getId()->toRfc4122(),
            'format' => 'auto',
            'payload' => '',
        ];
    }

    /**
     * @return array{tenantId: string, format: string, payload: string}
     */
    private function productImportFormValuesFromRequest(Request $request): array
    {
        return [
            'tenantId' => trim((string) $request->request->get('tenantId', '')),
            'format' => trim((string) $request->request->get('format', 'auto')) ?: 'auto',
            'payload' => trim((string) $request->request->get('payload', '')),
        ];
    }

    /**
     * @param array{tenantId: string, format: string, payload: string} $values
     */
    private function validateProductImportForm(array $values, Tenant $tenant): ?string
    {
        if (!in_array($values['format'], ['auto', 'json', 'csv'], true)) {
            return 'El formato de importación no es válido.';
        }

        return null;
    }

    private function productImportPayloadFromRequest(Request $request): ?string
    {
        $payload = trim((string) $request->request->get('payload', ''));
        if ($payload !== '') {
            return $payload;
        }

        $file = $request->files->get('file');
        if ($file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile && $file->isValid()) {
            $content = file_get_contents($file->getPathname());
            return is_string($content) ? trim($content) : null;
        }

        return null;
    }

    private function renderProductImportForm(
        string $pageTitle,
        string $pageSubtitle,
        string $heroTitle,
        string $submitLabel,
        string $actionUrl,
        array $values,
        ?ProductCatalogImportResult $result = null,
        ?string $error = null,
    ): Response {
        $errorHtml = $error !== null ? sprintf(
            '<div class="form-alert form-alert-error">%s</div>',
            htmlspecialchars($error, ENT_QUOTES, 'UTF-8')
        ) : '';
        $resultHtml = '';
        if ($result instanceof ProductCatalogImportResult) {
            $errorRows = [];
            foreach ($result->errors as $importError) {
                $errorRows[] = sprintf(
                    '<li>Fila %s: %s</li>',
                    htmlspecialchars((string) $importError['row'], ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars((string) $importError['message'], ENT_QUOTES, 'UTF-8')
                );
            }

            $resultHtml = sprintf(
                '
                <section class="table-card">
                  <div class="table-header"><div><h3>Resultado de la importación</h3><p>%s filas procesadas.</p></div></div>
                  <section class="cards-grid">
                    %s
                    %s
                    %s
                    %s
                  </section>
                  %s
                </section>
                ',
                htmlspecialchars((string) $result->totalProcessed(), ENT_QUOTES, 'UTF-8'),
                $this->metricCard('Creados', (string) $result->created, 'Nuevos productos / servicios'),
                $this->metricCard('Actualizados', (string) $result->updated, 'Registros alineados con el archivo'),
                $this->metricCard('Omitidos', (string) $result->omitted, 'Sin cambios efectivos'),
                $this->metricCard('Errores', (string) count($result->errors), 'Filas inválidas o no procesables'),
                $errorRows !== [] ? sprintf('<div class="table-responsive"><table><thead><tr><th>Errores</th></tr></thead><tbody>%s</tbody></table></div>', implode('', array_map(static fn (string $row): string => sprintf('<tr><td>%s</td></tr>', $row), $errorRows))) : '<div class="empty-row">No hay errores.</div>'
            );
        }

        $content = $this->twig->render('backend/products/import.html.twig', [
            'hero_title' => $heroTitle,
            'page_subtitle' => $pageSubtitle,
            'error_html' => $errorHtml,
            'action_url' => $actionUrl,
            'csrf_token' => $this->productTokenValue($actionUrl),
            'values' => $values,
            'submit_label' => $submitLabel,
            'result_html' => $resultHtml,
            ...$this->currentUserTemplateData(),
        ]);

        return $this->renderBackendShell($pageTitle, $pageSubtitle, 'products', $content);
    }

    private function productTokenValue(string $actionUrl): string
    {
        if ($this->csrfTokenManager === null) {
            return '';
        }

        return $this->csrfTokenManager->getToken($this->productTokenId($actionUrl))->getValue();
    }

    private function isValidProductToken(string $actionUrl, string $value): bool
    {
        if ($this->csrfTokenManager === null) {
            return true;
        }

        return $this->csrfTokenManager->isTokenValid(new CsrfToken($this->productTokenId($actionUrl), $value));
    }

    private function productTokenId(string $actionUrl): string
    {
        return 'product_'.md5($actionUrl);
    }

    private function entryPointTokenValue(string $actionUrl): string
    {
        if ($this->csrfTokenManager === null) {
            return '';
        }

        return $this->csrfTokenManager->getToken($this->entryPointTokenId($actionUrl))->getValue();
    }

    private function isValidEntryPointToken(string $actionUrl, string $value): bool
    {
        if ($this->csrfTokenManager === null) {
            return true;
        }

        return $this->csrfTokenManager->isTokenValid(new CsrfToken($this->entryPointTokenId($actionUrl), $value));
    }

    private function entryPointTokenId(string $actionUrl): string
    {
        return 'entry_point_'.md5($actionUrl);
    }

    /**
     * @return string
     */
    private function renderTenantOptions(?TenantRepository $tenants, string $selectedId): string
    {
        $options = ['<option value="">Selecciona un negocio</option>'];
        if ($tenants instanceof TenantRepository) {
            foreach ($tenants->findAllOrdered() as $tenant) {
                $options[] = sprintf(
                    '<option value="%s"%s>%s</option>',
                    htmlspecialchars($tenant->getId()->toRfc4122(), ENT_QUOTES, 'UTF-8'),
                    $tenant->getId()->toRfc4122() === $selectedId ? ' selected' : '',
                    htmlspecialchars($tenant->getName(), ENT_QUOTES, 'UTF-8')
                );
            }
        }

        return implode('', $options);
    }

    /**
     * @return string
     */
    private function renderProductOptions(?ProductRepository $products, string $selectedId, ?Tenant $tenant = null): string
    {
        $options = ['<option value="">Sin producto</option>'];
        if ($products instanceof ProductRepository) {
            $items = $tenant instanceof Tenant ? $products->findByTenantOrdered($tenant) : $products->findAllOrdered();
            foreach ($items as $product) {
                $label = sprintf('%s · %s', $product->getTenant()->getName(), $product->getName());
                if ($product->getSlug() !== '') {
                    $label .= sprintf(' (%s)', $product->getSlug());
                }
                if ($product->getExternalReference() !== null) {
                    $label .= sprintf(' [crm:%s]', $product->getExternalReference());
                }

                $options[] = sprintf(
                    '<option value="%s"%s>%s</option>',
                    htmlspecialchars($product->getId()->toRfc4122(), ENT_QUOTES, 'UTF-8'),
                    $product->getId()->toRfc4122() === $selectedId ? ' selected' : '',
                    htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
                );
            }
        }

        return implode('', $options);
    }

    /**
     * @return string
     */
    private function renderPlaybookOptions(?PlaybookRepository $playbooks, string $selectedId, ?Tenant $tenant = null): string
    {
        $options = ['<option value="">Sin playbook</option>'];
        if ($playbooks instanceof PlaybookRepository) {
            $items = $tenant instanceof Tenant ? $playbooks->findByTenantOrdered($tenant) : $playbooks->findAllOrdered();
            foreach ($items as $playbook) {
                $options[] = sprintf(
                    '<option value="%s"%s>%s · %s</option>',
                    htmlspecialchars($playbook->getId()->toRfc4122(), ENT_QUOTES, 'UTF-8'),
                    $playbook->getId()->toRfc4122() === $selectedId ? ' selected' : '',
                    htmlspecialchars($playbook->getTenant()->getName(), ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars($playbook->getName(), ENT_QUOTES, 'UTF-8')
                );
            }
        }

        return implode('', $options);
    }

    /**
     * @return array{productId: string, playbookId: string, code: string, name: string, source: string, medium: string, campaign: string, content: string, term: string, crmBranchRef: string, defaultMessage: string, isActive: bool}
     */
    private function entryPointFormDefaults(?EntryPoint $entryPoint = null): array
    {
        return [
            'productId' => $entryPoint?->getProduct()?->getId()->toRfc4122() ?? '',
            'playbookId' => $entryPoint?->getPlaybook()?->getId()->toRfc4122() ?? '',
            'code' => $entryPoint?->getCode() ?? '',
            'name' => $entryPoint?->getName() ?? '',
            'source' => $entryPoint?->getSource() ?? '',
            'medium' => $entryPoint?->getMedium() ?? '',
            'campaign' => $entryPoint?->getCampaign() ?? '',
            'content' => $entryPoint?->getContent() ?? '',
            'term' => $entryPoint?->getTerm() ?? '',
            'crmBranchRef' => $entryPoint?->getCrmBranchRef() ?? '',
            'defaultMessage' => $entryPoint?->getDefaultMessage() ?? '',
            'isActive' => $entryPoint?->isActive() ?? true,
        ];
    }

    /**
     * @return array{productId: string, playbookId: string, code: string, name: string, source: string, medium: string, campaign: string, content: string, term: string, crmBranchRef: string, defaultMessage: string, isActive: bool}
     */
    private function entryPointFormValuesFromRequest(Request $request): array
    {
        return [
            'productId' => trim((string) $request->request->get('productId', '')),
            'playbookId' => trim((string) $request->request->get('playbookId', '')),
            'code' => trim((string) $request->request->get('code', '')),
            'name' => trim((string) $request->request->get('name', '')),
            'source' => trim((string) $request->request->get('source', '')),
            'medium' => trim((string) $request->request->get('medium', '')),
            'campaign' => trim((string) $request->request->get('campaign', '')),
            'content' => trim((string) $request->request->get('content', '')),
            'term' => trim((string) $request->request->get('term', '')),
            'crmBranchRef' => trim((string) $request->request->get('crmBranchRef', '')),
            'defaultMessage' => trim((string) $request->request->get('defaultMessage', '')),
            'isActive' => $request->request->has('isActive'),
        ];
    }

    /**
     * @param array{productId: string, playbookId: string, code: string, name: string, source: string, medium: string, campaign: string, content: string, term: string, crmBranchRef: string, defaultMessage: string, isActive: bool} $values
     */
    private function validateEntryPointForm(array $values, ?EntryPoint $entryPoint, Tenant $tenant, ?ProductRepository $products, ?PlaybookRepository $playbooks, ?EntryPointRepository $entryPoints): ?string
    {
        if ($values['code'] === '') {
            return 'El código del punto de entrada es obligatorio.';
        }

        if ($values['productId'] === '' || !$products instanceof ProductRepository || !$products->find($values['productId']) instanceof Product) {
            return 'Debes seleccionar un producto válido.';
        }

        $product = $products->find($values['productId']);
        if (!$product instanceof Product || $product->getTenant()->getId()->toRfc4122() !== $tenant->getId()->toRfc4122()) {
            return 'El producto seleccionado no pertenece al negocio activo.';
        }

        if ($values['name'] === '') {
            return 'El nombre del punto de entrada es obligatorio.';
        }

        if (mb_strlen($values['code']) > 120) {
            return 'El código no puede superar 120 caracteres.';
        }

        if (mb_strlen($values['name']) > 255) {
            return 'El nombre no puede superar 255 caracteres.';
        }

        foreach (['source', 'medium', 'campaign', 'content', 'term'] as $key) {
            if ($values[$key] !== '' && mb_strlen($values[$key]) > 120) {
                return sprintf('El campo %s no puede superar 120 caracteres.', $key);
            }
        }

        if ($values['crmBranchRef'] !== '' && mb_strlen($values['crmBranchRef']) > 255) {
            return 'La referencia CRM no puede superar 255 caracteres.';
        }

        if ($values['defaultMessage'] !== '' && mb_strlen($values['defaultMessage']) > 5000) {
            return 'El mensaje por defecto no puede superar 5000 caracteres.';
        }

        if ($values['playbookId'] !== '') {
            if (!$playbooks instanceof PlaybookRepository) {
                return 'La guía comercial seleccionada no existe.';
            }

            $playbook = $playbooks->find($values['playbookId']);
            if (!$playbook instanceof Playbook || $playbook->getTenant()->getId()->toRfc4122() !== $tenant->getId()->toRfc4122()) {
                return 'La guía comercial seleccionada no pertenece al negocio.';
            }
        }

        if ($entryPoints instanceof EntryPointRepository) {
            $existing = $entryPoints->findOneBy(['code' => $values['code']]);
            if ($existing instanceof EntryPoint) {
                if ($entryPoint === null || $existing->getId()->toRfc4122() !== $entryPoint->getId()->toRfc4122()) {
                    return 'Ya existe otro punto de entrada con ese código.';
                }
            }
        }

        return null;
    }

    /**
     * @param array{productId: string, playbookId: string, code: string, name: string, source: string, medium: string, campaign: string, content: string, term: string, crmBranchRef: string, defaultMessage: string, isActive: bool} $values
     */
    private function hydrateEntryPointFromForm(EntryPoint $entryPoint, array $values, Product $product, ?PlaybookRepository $playbooks): void
    {
        $entryPoint->setProduct($product);

        $entryPoint->setCode($values['code']);
        $entryPoint->setName($values['name']);
        $entryPoint->setPlaybook($values['playbookId'] !== '' && $playbooks instanceof PlaybookRepository ? $playbooks->find($values['playbookId']) : null);
        $entryPoint->setSource($values['source'] !== '' ? $values['source'] : null);
        $entryPoint->setMedium($values['medium'] !== '' ? $values['medium'] : null);
        $entryPoint->setCampaign($values['campaign'] !== '' ? $values['campaign'] : null);
        $entryPoint->setContent($values['content'] !== '' ? $values['content'] : null);
        $entryPoint->setTerm($values['term'] !== '' ? $values['term'] : null);
        $entryPoint->setCrmBranchRef($values['crmBranchRef'] !== '' ? $values['crmBranchRef'] : null);
        $entryPoint->setDefaultMessage($values['defaultMessage'] !== '' ? $values['defaultMessage'] : null);
        $entryPoint->setActive($values['isActive']);
    }

    /**
     * @param array{productId: string, playbookId: string, code: string, name: string, source: string, medium: string, campaign: string, content: string, term: string, crmBranchRef: string, defaultMessage: string, isActive: bool} $values
     */
    private function renderEntryPointForm(string $pageTitle, string $pageSubtitle, string $heroTitle, string $submitLabel, string $actionUrl, array $values, ?ProductRepository $products, ?PlaybookRepository $playbooks, ?string $error = null): Response
    {
        $activeTenant = $this->resolvedActiveTenantForCurrentUser();
        $errorHtml = $error !== null ? sprintf('<div class="form-alert form-alert-error">%s</div>', htmlspecialchars($error, ENT_QUOTES, 'UTF-8')) : '';
        $content = $this->twig->render('backend/entry_points/form.html.twig', [
            'hero_title' => $heroTitle,
            'page_subtitle' => $pageSubtitle,
            'error_html' => $errorHtml,
            'action_url' => $actionUrl,
            'csrf_token' => $this->entryPointTokenValue($actionUrl),
            'product_options_html' => $this->renderProductOptions($products, $values['productId'] ?? '', $activeTenant instanceof Tenant ? $activeTenant : null),
            'playbook_options_html' => $this->renderPlaybookOptions($playbooks, $values['playbookId'] ?? '', $activeTenant instanceof Tenant ? $activeTenant : null),
            'values' => $values,
            'submit_label' => $submitLabel,
            'active_tenant_name' => $activeTenant instanceof Tenant ? $activeTenant->getName() : null,
        ]);

        return $this->renderBackendShell($pageTitle, $pageSubtitle, 'entry-points', $content);
    }

    /**
     * @return string[]
     */
    private function linesFromTextarea(string $value, bool $required = false): array
    {
        $lines = preg_split('/\R+/', $value) ?: [];
        $lines = array_values(array_filter(array_map('trim', $lines), static fn (string $line): bool => $line !== ''));

        return $required ? $lines : $lines;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function playbookConfigValueFromArray(array $config, string $key): string
    {
        $value = $config[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    private function isValidProfileToken(string $id, string $value): bool
    {
        if ($this->csrfTokenManager === null) {
            return true;
        }

        return $this->csrfTokenManager->isTokenValid(new CsrfToken($id, $value));
    }

    private function profileTokenValue(string $id): string
    {
        if ($this->csrfTokenManager === null) {
            return '';
        }

        return $this->csrfTokenManager->getToken($id)->getValue();
    }

    private function addProfileFlash(Request $request, string $type, string $message): void
    {
        if (!$request->hasSession()) {
            return;
        }

        $request->getSession()->getFlashBag()->add($type, $message);
    }

    private function addFlashMessage(Request $request, string $type, string $message): void
    {
        if (!$request->hasSession()) {
            return;
        }

        $request->getSession()->getFlashBag()->add($type, $message);
    }

    private function renderProfileFeedback(Request $request): string
    {
        if (!$request->hasSession()) {
            return '';
        }

        $flashBag = $request->getSession()->getFlashBag();
        $success = $flashBag->get('success');
        $errors = $flashBag->get('error');

        $html = '';
        foreach ($success as $message) {
            $html .= $this->renderDismissibleAlert(
                'alert-success',
                htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8')
            );
        }
        foreach ($errors as $message) {
            $html .= $this->renderDismissibleAlert(
                'alert-error',
                htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8')
            );
        }

        return $html;
    }

    private function renderDismissibleAlert(string $class, string $content): string
    {
        $dismissibleClass = 'alert-dismissible fade show';
        $alertClass = match ($class) {
            'alert-success' => 'alert-success',
            'alert-warning' => 'alert-warning',
            default => 'alert-danger',
        };

        return sprintf(
            '<div class="alert %s %s" role="alert">%s<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button></div>',
            htmlspecialchars($alertClass, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($dismissibleClass, ENT_QUOTES, 'UTF-8'),
            $content
        );
    }

    /**
     * @return array<string, string>
     */
    private function runtimeConfigurationValuesFromRequest(Request $request): array
    {
        return [
            'llm_default_profile' => trim((string) $request->request->get('llm_default_profile', '')),
            'openai_base_url' => trim((string) $request->request->get('openai_base_url', '')),
            'openai_model' => trim((string) $request->request->get('openai_model', '')),
            'openai_api_key' => trim((string) $request->request->get('openai_api_key', '')),
            'openai_timeout_seconds' => trim((string) $request->request->get('openai_timeout_seconds', '')),
            'ollama_base_url' => trim((string) $request->request->get('ollama_base_url', '')),
            'ollama_model' => trim((string) $request->request->get('ollama_model', '')),
            'ollama_timeout_seconds' => trim((string) $request->request->get('ollama_timeout_seconds', '')),
            'audio_gateway_base_url' => trim((string) $request->request->get('audio_gateway_base_url', '')),
            'audio_gateway_bearer_token' => trim((string) $request->request->get('audio_gateway_bearer_token', '')),
            'audio_timeout_seconds' => trim((string) $request->request->get('audio_timeout_seconds', '')),
            'audio_max_bytes' => trim((string) $request->request->get('audio_max_bytes', '')),
            'openai_transcription_model' => trim((string) $request->request->get('openai_transcription_model', '')),
            'audio_transcription_cost_per_unit_eur' => trim((string) $request->request->get('audio_transcription_cost_per_unit_eur', '')),
            'audio_llm_followup_reserve_cost_eur' => trim((string) $request->request->get('audio_llm_followup_reserve_cost_eur', '')),
        ];
    }

    private function runtimeConfigurationTargetFromAction(string $action): ?string
    {
        return match ($action) {
            'test_openai' => 'openai',
            'test_ollama' => 'ollama',
            default => null,
        };
    }

    private function isValidRuntimeConfigurationToken(string $id, string $value): bool
    {
        if ($this->csrfTokenManager === null) {
            return true;
        }

        return $this->csrfTokenManager->isTokenValid(new CsrfToken($id, $value));
    }

    private function runtimeConfigurationTokenValue(string $id): string
    {
        if ($this->csrfTokenManager === null) {
            return '';
        }

        return $this->csrfTokenManager->getToken($id)->getValue();
    }

    /**
     * @param array<string, mixed> $pageData
     */
    private function renderRuntimeConfigurationPage(array $pageData, string $feedbackHtml, string $csrfToken): Response
    {
        return new Response($this->twig->render('backend/configuration/index.html.twig', $this->runtimeConfigurationTemplateData($pageData, $feedbackHtml, $csrfToken)));
    }

    /**
     * @param array<string, mixed> $pageData
     *
     * @return array<string, mixed>
     */
    private function runtimeConfigurationTemplateData(array $pageData, string $feedbackHtml, string $csrfToken): array
    {
        $status = $pageData['status'] ?? [];

        $overallStatus = $status['overall'] ?? [];
        $llmStatus = $status['llm'] ?? [];
        $openaiStatus = $status['openai'] ?? [];
        $ollamaStatus = $status['ollama'] ?? [];
        $audioStatus = $status['audio'] ?? [];
        $formState = $pageData['formState'] ?? [];

        $metrics = [
            [
                'label' => 'Estado general',
                'value' => $this->runtimeStatusLabel((string) ($overallStatus['status'] ?? 'blocked')),
                'note' => (string) ($overallStatus['message'] ?? 'Sin estado operativo.'),
            ],
            [
                'label' => 'OpenAI',
                'value' => $this->runtimeStatusLabel((string) ($openaiStatus['status'] ?? 'blocked')),
                'note' => (string) ($openaiStatus['message'] ?? 'Sin validación'),
            ],
            [
                'label' => 'Ollama',
                'value' => $this->runtimeStatusLabel((string) ($ollamaStatus['status'] ?? 'blocked')),
                'note' => (string) ($ollamaStatus['message'] ?? 'Sin validación'),
            ],
            [
                'label' => 'Audio',
                'value' => $this->runtimeStatusLabel((string) ($audioStatus['status'] ?? 'blocked')),
                'note' => (string) ($audioStatus['message'] ?? 'Sin validación'),
            ],
        ];

        return [
            'page_title' => 'Configuración',
            'page_subtitle' => 'Ajustes operativos de LLM, audio y conectividad externa.',
            'active_nav' => 'admin-configuration',
            ...$this->currentUserTemplateData(),
            'feedback_html' => $feedbackHtml,
            'csrf_token' => $csrfToken,
            'overall_status_message' => (string) (($overallStatus['message'] ?? 'Sin estado operativo.') ?: 'Sin estado operativo.'),
            'metrics' => $metrics,
            'profile' => $this->runtimeConfigurationFieldForKey($formState, 'llm_default_profile'),
            'profile_badge_label' => $this->runtimeStatusLabel((string) ($llmStatus['status'] ?? 'blocked')),
            'profile_badge_class' => $this->runtimeStatusClass((string) ($llmStatus['status'] ?? 'blocked')),
            'openai' => [
                'title' => 'OPENAI',
                'subtitle' => 'Proveedor de pago',
                'note' => 'Prueba manual: hace 1 request real a /models y no genera tokens.',
                'badge_label' => 'Cloud',
                'badge_class' => $this->runtimeStatusClass((string) ($openaiStatus['status'] ?? 'blocked')),
                'fields' => $this->runtimeConfigurationFieldsForKeys($formState, [
                    'openai_model',
                    'openai_base_url',
                    'openai_api_key',
                    'openai_timeout_seconds',
                ]),
                'actions' => [
                    ['class' => 'secondary-action', 'name' => 'action', 'value' => 'test_openai', 'label' => 'Probar conexión de OpenAI'],
                ],
            ],
            'local' => [
                'title' => 'OLLAMA Y AUDIO',
                'subtitle' => 'Autocalojado',
                'note' => 'Prueba manual: Ollama valida /api/tags y no genera tokens.',
                'badge_label' => 'Local',
                'badge_class' => $this->runtimeStatusClass((string) ($ollamaStatus['status'] ?? 'blocked')),
                'fields' => $this->runtimeConfigurationFieldsForKeys($formState, [
                    'ollama_model',
                    'ollama_base_url',
                    'ollama_timeout_seconds',
                ]),
                'actions' => [
                    ['class' => 'secondary-action', 'name' => 'action', 'value' => 'test_ollama', 'label' => 'Probar conexión de Ollama'],
                ],
            ],
            'audio' => [
                'title' => 'AUDIO',
                'subtitle' => 'Gateway y transcripción',
                'note' => 'Estos valores se usan para descargar audios desde el gateway de WhatsApp y transcribirlos antes de continuar el flujo del agente.',
                'badge_label' => 'Audio',
                'badge_class' => $this->runtimeStatusClass((string) ($audioStatus['status'] ?? 'blocked')),
                'fields' => $this->runtimeConfigurationFieldsForKeys($formState, [
                    'audio_gateway_base_url',
                    'audio_gateway_bearer_token',
                    'openai_transcription_model',
                    'audio_timeout_seconds',
                    'audio_max_bytes',
                    'audio_transcription_cost_per_unit_eur',
                    'audio_llm_followup_reserve_cost_eur',
                ]),
                'actions' => [],
            ],
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $formState
     * @param string[] $keys
     *
     * @return array<int, array<string, mixed>>
     */
    private function runtimeConfigurationFieldsForKeys(array $formState, array $keys): array
    {
        $fields = [];
        foreach ($keys as $key) {
            if (!isset($formState[$key]) || !is_array($formState[$key])) {
                continue;
            }

            $fields[] = $formState[$key];
        }

        return $fields;
    }

    /**
     * @param array<string, array<string, mixed>> $formState
     *
     * @return array<string, mixed>|null
     */
    private function runtimeConfigurationFieldForKey(array $formState, string $key): ?array
    {
        if (!isset($formState[$key]) || !is_array($formState[$key])) {
            return null;
        }

        return $formState[$key];
    }

    private function runtimeTestFeedback(\App\Service\RuntimeConnectivityTestResult $result): string
    {
        $class = match ($result->status) {
            'ready' => 'alert-success',
            'partial' => 'alert-warning',
            default => 'alert-error',
        };
        $parts = [
            htmlspecialchars($result->message, ENT_QUOTES, 'UTF-8'),
        ];
        if ($result->endpoint !== null && $result->endpoint !== '') {
            $parts[] = sprintf('<div class="subtle">Endpoint: %s</div>', htmlspecialchars($result->endpoint, ENT_QUOTES, 'UTF-8'));
        }
        if ($result->httpCode !== null) {
            $parts[] = sprintf('<div class="subtle">HTTP %d</div>', $result->httpCode);
        }

        return $this->renderDismissibleAlert($class, implode('', $parts));
    }

    private function runtimeStatusClass(string $status): string
    {
        return match ($status) {
            'ready' => 'status-ok',
            'partial' => 'status-warn',
            default => 'status-off',
        };
    }

    private function runtimeStatusLabel(string $status): string
    {
        return match ($status) {
            'ready' => 'Listo',
            'partial' => 'Parcial',
            default => 'Bloqueado',
        };
    }

    /**
     * @param string[] $roles
     */
    private function canSeeNavItem(array $roles): bool
    {
        foreach ($roles as $role) {
            if ($this->security->isGranted($role)) {
                return true;
            }
        }

        return false;
    }

    private function metricCard(string $label, string $value, string $note): string
    {
        $labelHtml = $label !== ''
            ? sprintf('<div class="metric-label">%s</div>', htmlspecialchars($label, ENT_QUOTES, 'UTF-8'))
            : '';

        return sprintf(
            '<article class="metric">%s<div class="metric-value">%s</div><div class="metric-note">%s</div></article>',
            $labelHtml,
            htmlspecialchars($value, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($note, ENT_QUOTES, 'UTF-8')
        );
    }

    private function infoCard(string $title, string $body, string $href, string $actionLabel): string
    {
        return sprintf(
            '<article class="info-card"><h3>%s</h3><p>%s</p><a class="card-action" href="%s">%s →</a></article>',
            htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($body, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($href, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($actionLabel, ENT_QUOTES, 'UTF-8')
        );
    }
}
