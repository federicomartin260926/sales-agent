<?php

namespace App\Controller\Web;

use App\Domain\CommercialDomainSchema;
use App\Entity\EntryPoint;
use App\Entity\Playbook;
use App\Entity\Product;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\PlaybookRepository;
use App\Repository\ProductRepository;
use App\Repository\EntryPointRepository;
use App\Repository\TenantRepository;
use App\Repository\UserRepository;
use App\Service\RuntimeConfigurationService;
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
        private readonly Environment $twig,
        private readonly ?ProductCatalogImportService $productCatalogImportService = null,
        private readonly ?CsrfTokenManagerInterface $csrfTokenManager = null,
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
        if (!$this->security->isGranted('ROLE_ADMIN')) {
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
    ): Response {
        if (!$this->security->isGranted('ROLE_AGENT')) {
            return new RedirectResponse('/backend/login');
        }

        $tenantCount = $tenants ? count($tenants->findAllOrdered()) : 0;
        $userCount = $users ? count($users->findBy([], ['createdAt' => 'DESC'])) : 0;
        $playbookCount = $playbooks ? count($playbooks->findAllOrdered()) : 0;
        $productCount = $products ? count($products->findAllOrdered()) : 0;
        $canManageUsers = $this->security->isGranted('ROLE_ADMIN');
        $heroActions = sprintf(
            '<a class="primary-action" href="/backend/tenants">Ver negocios</a>%s',
            $canManageUsers ? '<a class="secondary-action" href="/backend/users">Revisar usuarios</a>' : ''
        );

        $content = sprintf(
            '
            <section class="hero-panel hero-panel-single">
              <div class="hero-copy">
                <div class="eyebrow-dark">Operación comercial</div>
                <h2>Dashboard comercial de negocios</h2>
                <p>
                  Consulta el estado operativo de tus negocios y, si tienes permisos de gestión, accede a productos/servicios,
                  guías comerciales y usuarios. Aquí defines cómo se comporta el agente IA por negocio o producto: su conocimiento,
                  su tono y el enfoque comercial que aplica.
                </p>
                <div class="hero-actions">
                  %s
                </div>
              </div>
            </section>

            <section class="stats-grid">
              %s
              %s
              %s
              %s
            </section>

            <section class="cards-grid">
              %s
              %s
              %s
              %s
            </section>
            ',
            $heroActions,
            $this->metricCard('Negocios', (string) $tenantCount, 'Contextos comerciales listos'),
            $this->metricCard('Guías comerciales', (string) $playbookCount, 'Cualificación, scoring y handoff'),
            $this->metricCard('Productos / servicios', (string) $productCount, 'Catálogo comercial base'),
            $this->metricCard('Usuarios', (string) $userCount, 'Cuentas de administración'),
            $this->infoCard(
                'Negocios',
                'Cada negocio agrupa su contexto, usuarios y reglas del agente.',
                '/backend/tenants',
                'Abrir'
            ),
            $this->infoCard(
                'Guías comerciales',
                'Ajustes del agente para cada negocio o producto: enfoque, tono, scoring y reglas.',
                '/backend/playbooks',
                'Abrir'
            ),
            $this->infoCard(
                'Productos / servicios',
                'Propuestas comerciales asociadas al trabajo de cada negocio.',
                '/backend/products',
                'Abrir'
            ),
            $canManageUsers ? $this->infoCard(
                'Usuarios',
                'Cuentas que administran negocios, productos/servicios y acceso interno.',
                '/backend/users',
                'Gestionar'
            ) : $this->infoCard(
                'Usuarios',
                'La gestión de usuarios está reservada al rol admin.',
                '/backend/profile',
                'Mi perfil'
            ),
        );

        $content = $this->twig->render('backend/dashboard.html.twig', [
            'hero_actions_html' => $heroActions,
            'metric_cards_html' => implode('', [
                $this->metricCard('Negocios', (string) $tenantCount, 'Contextos comerciales listos'),
                $this->metricCard('Guías comerciales', (string) $playbookCount, 'Cualificación, scoring y handoff'),
                $this->metricCard('Productos / servicios', (string) $productCount, 'Catálogo comercial base'),
                $this->metricCard('Usuarios', (string) $userCount, 'Cuentas de administración'),
            ]),
            'info_cards_html' => implode('', [
                $this->infoCard('Negocios', 'Cada negocio agrupa su contexto, usuarios y reglas del agente.', '/backend/tenants', 'Abrir'),
                $this->infoCard('Guías comerciales', 'Ajustes del agente para cada negocio o producto: enfoque, tono, scoring y reglas.', '/backend/playbooks', 'Abrir'),
                $this->infoCard('Productos / servicios', 'Propuestas comerciales asociadas al trabajo de cada negocio.', '/backend/products', 'Abrir'),
                $canManageUsers ? $this->infoCard('Usuarios', 'Cuentas que administran negocios, productos/servicios y acceso interno.', '/backend/users', 'Gestionar') : $this->infoCard('Usuarios', 'La gestión de usuarios está reservada al rol admin.', '/backend/profile', 'Mi perfil'),
            ]),
        ]);

        return $this->renderBackendShell(
            'Panel comercial',
            'Resumen de negocios, usuarios y configuración comercial del agente.',
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
        }, $playbooks ? $playbooks->findAllOrdered() : []);

        $content = sprintf(
            '
            <section class="hero-panel">
              <div class="hero-copy">
                <div class="eyebrow-dark">Configuración comercial</div>
                <h2>Guías comerciales</h2>
                <p>Ajustes del comportamiento del agente por negocio o producto. Aquí defines el enfoque, el tono, el scoring y las reglas de respuesta.</p>
              </div>
              <div class="hero-aside">
                <div class="badge-live">Manager</div>
                <div class="hero-aside-title">Reglas</div>
                <p>Los ajustes se activan y mantienen desde este panel, por negocio y sin mezclar la API técnica.</p>
              </div>
            </section>
            %s
            <section class="table-card">
              <div class="table-header">
                <div>
                  <h3>Guías comerciales activas e inactivas</h3>
                  <p>Vista rápida del catálogo y su contexto.</p>
                </div>
                <a class="primary-action" href="/backend/playbooks/new">Crear guía comercial</a>
              </div>
              <div class="table-responsive">
                <table>
                  <thead>
                    <tr><th>Guía comercial</th><th>Negocio</th><th>Producto / servicio</th><th>Estado</th><th class="text-right">Acciones</th></tr>
                  </thead>
                  <tbody>%s</tbody>
                </table>
              </div>
            </section>
            ',
            $feedbackHtml,
            $rows !== [] ? implode('', $rows) : '<tr><td colspan="5" class="empty-row">No hay guías comerciales todavía.</td></tr>'
        );

        $content = $this->twig->render('backend/playbooks/index.html.twig', [
            'feedback_html' => $feedbackHtml,
            'rows_html' => $rows !== [] ? implode('', $rows) : '<tr><td colspan="5" class="empty-row">No hay guías comerciales todavía.</td></tr>',
        ]);

        return $this->renderBackendShell('Guías comerciales', 'Ajustes del agente por negocio o producto.', 'playbooks', $content);
    }

    #[Route('/playbooks/new', methods: ['GET', 'POST'])]
    public function playbookCreate(Request $request, ?TenantRepository $tenants = null, ?ProductRepository $products = null, ?PlaybookRepository $playbooks = null): Response
    {
        if (!$this->security->isGranted('ROLE_MANAGER')) {
            return new RedirectResponse('/backend/login');
        }

        $values = $this->playbookFormDefaults();
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isValidPlaybookToken('/backend/playbooks/new', (string) $request->request->get('_csrf_token'))) {
                $error = 'La sesión del formulario ha expirado. Vuelve a intentarlo.';
            } else {
                $values = $this->playbookFormValuesFromRequest($request);
                $error = $this->validatePlaybookForm($values, null, $tenants, $products, $playbooks);

                if ($error === null) {
                    $tenant = $tenants instanceof TenantRepository ? $tenants->find($values['tenantId']) : null;
                    $playbook = new Playbook($tenant, $values['name']);
                    $this->hydratePlaybookFromForm($playbook, $values, $tenant, $products);
                    $this->entityManager->persist($playbook);
                    $this->entityManager->flush();

                    return new RedirectResponse('/backend/playbooks');
                }
            }
        }

        return $this->renderPlaybookForm(
            'Crear guía comercial',
            'Define la estrategia conversacional, el scoring y las reglas de handoff del negocio.',
            'Crear guía comercial',
            'Crear guía comercial',
            '/backend/playbooks/new',
            $values,
            $tenants,
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

        if (!$playbooks instanceof PlaybookRepository) {
            return new RedirectResponse('/backend/playbooks');
        }

        $playbook = $playbooks->find($id);
        if (!$playbook instanceof Playbook) {
            return new RedirectResponse('/backend/playbooks');
        }

        $values = $this->playbookFormDefaults($playbook);
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isValidPlaybookToken('/backend/playbooks/'.$playbook->getId()->toRfc4122().'/edit', (string) $request->request->get('_csrf_token'))) {
                $error = 'La sesión del formulario ha expirado. Vuelve a intentarlo.';
            } else {
                $values = $this->playbookFormValuesFromRequest($request);
                $error = $this->validatePlaybookForm($values, $playbook, $tenants, $products, $playbooks);

                if ($error === null) {
                    $tenant = $tenants instanceof TenantRepository ? $tenants->find($values['tenantId']) : $playbook->getTenant();
                    $this->hydratePlaybookFromForm($playbook, $values, $tenant, $products);
                    $this->entityManager->persist($playbook);
                    $this->entityManager->flush();

                    return new RedirectResponse('/backend/playbooks');
                }
            }
        }

        return $this->renderPlaybookForm(
            'Editar guía comercial',
            'Ajusta el playbook para que el agente siga la estrategia correcta del negocio.',
            'Editar guía comercial',
            'Guardar cambios',
            '/backend/playbooks/'.$playbook->getId()->toRfc4122().'/edit',
            $values,
            $tenants,
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
        if (!$playbook instanceof Playbook) {
            return new RedirectResponse('/backend/playbooks');
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
        $rows = array_map(function (Tenant $tenant): string {
            $contextSummary = $this->shortenListText($tenant->getBusinessContext(), 110, 'Sin contexto');
            $toneSummary = $this->shortenListText($tenant->getTone() ?? '', 36, 'Sin tono');
            $policySummary = $this->shortenListText($tenant->getSalesPolicySummary(), 130, 'Sin política comercial');
            $status = $tenant->isActive() ? '<span class="status-ok">Activo</span>' : '<span class="status-off">Inactivo</span>';
            $editUrl = sprintf('/backend/tenants/%s/edit', rawurlencode($tenant->getId()->toRfc4122()));
            $deleteUrl = sprintf('/backend/tenants/%s/delete', rawurlencode($tenant->getId()->toRfc4122()));

            return sprintf(
                '<tr>
                    <td><strong>%s</strong><div class="subtle">Contexto: %s</div><div class="subtle">Tono: %s</div></td>
                    <td><code>%s</code></td>
                    <td>Política: %s</td>
                    <td>%s</td>
                    <td class="text-right">
                      <div style="display:inline-flex;align-items:center;gap:10px;flex-wrap:wrap;justify-content:flex-end">
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
                $status,
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
                <div class="eyebrow-dark">Negocios y contexto</div>
                <h2>Negocios</h2>
                <p>Cada negocio agrupa usuarios, reglas y contexto comercial del agente IA. Así mantienes separada la relación con cada cliente.</p>
              </div>
              <div class="hero-aside">
                <div class="badge-live">Admin</div>
                <div class="hero-aside-title">Contexto</div>
                <p>Cada negocio agrupa contexto, usuarios y reglas comerciales de forma aislada.</p>
              </div>
            </section>
            %s
            <section class="table-card">
              <div class="table-header">
                <div>
                  <h3>Negocios registrados</h3>
                  <p>Nombre, slug y política comercial de arranque.</p>
                </div>
                <a class="primary-action" href="/backend/tenants/new">Crear negocio</a>
              </div>
              <div class="table-responsive">
                <table>
                  <thead>
                    <tr><th>Negocio</th><th>Slug</th><th>Política comercial</th><th>Estado</th><th class="text-right">Acciones</th></tr>
                  </thead>
                  <tbody>%s</tbody>
                </table>
              </div>
            </section>
            ',
            $feedbackHtml,
            $rows !== [] ? implode('', $rows) : '<tr><td colspan="5" class="empty-row">No hay negocios todavía.</td></tr>'
        );

        $content = $this->twig->render('backend/tenants/index.html.twig', [
            'feedback_html' => $feedbackHtml,
            'rows_html' => $rows !== [] ? implode('', $rows) : '<tr><td colspan="5" class="empty-row">No hay negocios todavía.</td></tr>',
        ]);

        return $this->renderBackendShell('Negocios', 'Negocios y contextos operativos.', 'tenants', $content);
    }

    #[Route('/tenants/{id}/delete', methods: ['POST'])]
    public function tenantDelete(string $id, Request $request, ?TenantRepository $tenants = null): Response
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

        $deleteUrl = '/backend/tenants/'.$tenant->getId()->toRfc4122().'/delete';
        if (!$this->isValidTenantToken($deleteUrl, (string) $request->request->get('_csrf_token'))) {
            return new RedirectResponse('/backend/tenants');
        }

        $this->entityManager->remove($tenant);
        $this->entityManager->flush();
        $this->addFlashMessage($request, 'success', 'Negocio eliminado.');

        return new RedirectResponse('/backend/tenants');
    }

    #[Route('/tenants/new', methods: ['GET', 'POST'])]
    public function tenantCreate(Request $request, ?TenantRepository $tenants = null): Response
    {
        if (!$this->security->isGranted('ROLE_MANAGER')) {
            return new RedirectResponse('/backend/login');
        }

        $values = $this->tenantFormDefaults();
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isValidTenantToken('/backend/tenants/new', (string) $request->request->get('_csrf_token'))) {
                $error = 'La sesión del formulario ha expirado. Vuelve a intentarlo.';
            } else {
                $values = $this->tenantFormValuesFromRequest($request);
                $error = $this->validateTenantForm($values, null, $tenants);

                if ($error === null) {
                    $tenant = new Tenant();
                    $this->hydrateTenantFromForm($tenant, $values);
                    $this->entityManager->persist($tenant);
                    $this->entityManager->flush();

                    return new RedirectResponse('/backend/tenants');
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
            $error
        );
    }

    #[Route('/tenants/{id}/edit', methods: ['GET', 'POST'])]
    public function tenantEdit(string $id, Request $request, ?TenantRepository $tenants = null): Response
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

        $values = $this->tenantFormDefaults($tenant);
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isValidTenantToken('/backend/tenants/'.$tenant->getId()->toRfc4122().'/edit', (string) $request->request->get('_csrf_token'))) {
                $error = 'La sesión del formulario ha expirado. Vuelve a intentarlo.';
            } else {
                $values = $this->tenantFormValuesFromRequest($request);
                $error = $this->validateTenantForm($values, $tenant, $tenants);

                if ($error === null) {
                    $this->hydrateTenantFromForm($tenant, $values);
                    $this->entityManager->persist($tenant);
                    $this->entityManager->flush();

                    return new RedirectResponse('/backend/tenants');
                }
            }
        }

        return $this->renderTenantForm(
            'Editar negocio',
            'Ajusta el contexto comercial, el tono y la política de venta del negocio seleccionado.',
            'Editar negocio',
            'Guardar cambios',
            '/backend/tenants/'.$tenant->getId()->toRfc4122().'/edit',
            $values,
            $error
        );
    }

    #[Route('/users', methods: ['GET'])]
    public function users(): Response
    {
        if (!$this->security->isGranted('ROLE_ADMIN')) {
            return new RedirectResponse('/backend/dashboard');
        }

        /** @var \Doctrine\Persistence\ObjectRepository<User> $users */
        $users = $this->entityManager->getRepository(User::class);
        $rows = array_map(
            static function (User $user): array {
                return [
                    'email' => $user->getEmail(),
                    'roles' => implode(', ', array_map(static fn (string $role): string => strtoupper($role), $user->getRoles())),
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

    #[Route('/products', methods: ['GET'])]
    public function products(Request $request, ?ProductRepository $products = null, ?TenantRepository $tenants = null): Response
    {
        if (!$this->security->isGranted('ROLE_MANAGER')) {
            return new RedirectResponse('/backend/login');
        }

        $feedbackHtml = $this->renderProfileFeedback($request);
        $tenantFilter = trim((string) $request->query->get('tenantId', ''));
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
        }, array_values(array_filter($products ? $products->findAllOrdered() : [], function (Product $product) use ($tenantFilter, $productFilter): bool {
            if ($tenantFilter !== '' && $product->getTenant()->getId()->toRfc4122() !== $tenantFilter) {
                return false;
            }

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

        $tenantOptions = $this->renderTenantOptions($tenants, $tenantFilter);

        $content = sprintf(
            '
            <section class="hero-panel">
              <div class="hero-copy">
                <div class="eyebrow-dark">Catálogo comercial</div>
                <h2>Productos / servicios</h2>
                <p>Catálogo de productos y servicios asociados a cada negocio. Esto alimenta la lógica comercial del backend.</p>
              </div>
              <div class="hero-aside">
                <div class="badge-live">Manager</div>
                <div class="hero-aside-title">Oferta</div>
                <p>Los productos y servicios agrupan descripción, propuesta de valor y política de venta para el runtime.</p>
              </div>
            </section>
            %s
            <section class="table-card filters-card">
              <div class="table-header">
                <div>
                  <h3>Filtros</h3>
                  <p>Filtra el catálogo por negocio o por producto / servicio.</p>
                </div>
              </div>
              <form method="get" action="/backend/products" class="tenant-form">
                <div class="form-grid">
                  <div class="field">
                    <label for="product-filter-tenant">Negocio</label>
                    <select id="product-filter-tenant" name="tenantId">%s</select>
                    <div class="field-note">Filtra el catálogo por negocio asociado.</div>
                  </div>
                  <div class="field">
                    <label for="product-filter-product">Producto / servicio</label>
                    <input id="product-filter-product" name="product" type="text" value="%s" placeholder="Nombre, slug o referencia externa">
                    <div class="field-note">Busca por nombre, slug, origen o referencia externa.</div>
                  </div>
                </div>
                <div class="form-actions" style="justify-content:flex-start;gap:12px;flex-wrap:wrap;">
                  <button class="primary-action" type="submit">Filtrar</button>
                  <a class="secondary-action" href="/backend/products">Limpiar</a>
                </div>
              </form>
            </section>
            <section class="table-card">
              <div class="table-header">
                <div>
                  <h3>Productos / servicios registrados</h3>
                  <p>Negocio, slug, referencia externa y estado.</p>
                </div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;justify-content:flex-end">
                  <a class="secondary-action" href="/backend/products/import">Importar catálogo</a>
                  <a class="primary-action" href="/backend/products/new">Crear producto / servicio</a>
                </div>
              </div>
              <div class="table-responsive">
                <table>
                  <thead>
                    <tr><th>Producto / servicio</th><th>Negocio</th><th>Identidad</th><th>Estado</th><th class="text-right">Acciones</th></tr>
                  </thead>
                  <tbody>%s</tbody>
                </table>
              </div>
            </section>
            ',
            $feedbackHtml,
            $tenantOptions,
            htmlspecialchars($productFilter, ENT_QUOTES, 'UTF-8'),
            $rows !== [] ? implode('', $rows) : '<tr><td colspan="5" class="empty-row">No hay productos o servicios todavía.</td></tr>'
        );

        $content = $this->twig->render('backend/products/index.html.twig', [
            'feedback_html' => $feedbackHtml,
            'tenant_options_html' => $tenantOptions,
            'product_filter' => $productFilter,
            'rows_html' => $rows !== [] ? implode('', $rows) : '<tr><td colspan="5" class="empty-row">No hay productos o servicios todavía.</td></tr>',
        ]);

        return $this->renderBackendShell('Productos / servicios', 'Catálogo comercial por negocio.', 'products', $content);
    }

    #[Route('/products/import', methods: ['GET', 'POST'])]
    public function productImport(Request $request, ?TenantRepository $tenants = null): Response
    {
        if (!$this->security->isGranted('ROLE_MANAGER')) {
            return new RedirectResponse('/backend/login');
        }

        $values = $this->productImportFormDefaults();
        $error = null;
        $result = null;

        if ($request->isMethod('POST')) {
            if (!$this->isValidProductToken('/backend/products/import', (string) $request->request->get('_csrf_token'))) {
                $error = 'La sesión del formulario ha expirado. Vuelve a intentarlo.';
            } else {
                $values = $this->productImportFormValuesFromRequest($request);
                $error = $this->validateProductImportForm($values, $tenants);

                if ($error === null) {
                    $tenant = $tenants instanceof TenantRepository ? $tenants->find($values['tenantId']) : null;
                    $payload = $this->productImportPayloadFromRequest($request);

                    if (!$tenant instanceof Tenant) {
                        $error = 'El negocio seleccionado no existe.';
                    } elseif ($payload === null || trim($payload) === '') {
                        $error = 'Debes pegar un CSV/JSON o subir un archivo con el catálogo.';
                    } elseif (!$this->productCatalogImportService instanceof ProductCatalogImportService) {
                        $error = 'El servicio de importación no está disponible.';
                    } else {
                        $result = $this->productCatalogImportService->import($tenant, $payload, $values['format']);
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
            $tenants,
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

        $values = $this->productFormDefaults();
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isValidProductToken('/backend/products/new', (string) $request->request->get('_csrf_token'))) {
                $error = 'La sesión del formulario ha expirado. Vuelve a intentarlo.';
            } else {
                $values = $this->productFormValuesFromRequest($request);
                $error = $this->validateProductForm($values, null, $tenants, $products);

                if ($error === null) {
                    $tenant = $tenants instanceof TenantRepository ? $tenants->find($values['tenantId']) : null;
                    $product = new Product($tenant, $values['name']);
                    $this->hydrateProductFromForm($product, $values, $tenant);
                    $this->entityManager->persist($product);
                    $this->entityManager->flush();

                    return new RedirectResponse('/backend/products');
                }
            }
        }

        return $this->renderProductForm(
            'Crear producto / servicio',
            'Define la oferta, la propuesta de valor y la política comercial específica de este negocio.',
            'Crear producto / servicio',
            'Crear producto / servicio',
            '/backend/products/new',
            $values,
            $tenants,
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

        if (!$products instanceof ProductRepository) {
            return new RedirectResponse('/backend/products');
        }

        $product = $products->find($id);
        if (!$product instanceof Product) {
            return new RedirectResponse('/backend/products');
        }

        $values = $this->productFormDefaults($product);
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isValidProductToken('/backend/products/'.$product->getId()->toRfc4122().'/edit', (string) $request->request->get('_csrf_token'))) {
                $error = 'La sesión del formulario ha expirado. Vuelve a intentarlo.';
            } else {
                $values = $this->productFormValuesFromRequest($request);
                $error = $this->validateProductForm($values, $product, $tenants, $products);

                if ($error === null) {
                    $tenant = $tenants instanceof TenantRepository ? $tenants->find($values['tenantId']) : $product->getTenant();
                    $this->hydrateProductFromForm($product, $values, $tenant);
                    $this->entityManager->persist($product);
                    $this->entityManager->flush();

                    return new RedirectResponse('/backend/products');
                }
            }
        }

        return $this->renderProductForm(
            'Editar producto / servicio',
            'Ajusta el producto, su propuesta de valor y la política comercial que lo acompaña.',
            'Editar producto / servicio',
            'Guardar cambios',
            '/backend/products/'.$product->getId()->toRfc4122().'/edit',
            $values,
            $tenants,
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
        if (!$product instanceof Product) {
            return new RedirectResponse('/backend/products');
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
        }, $entryPoints ? $entryPoints->findAllOrdered() : []);

        $content = sprintf(
            '
            <section class="hero-panel">
              <div class="hero-copy">
                <div class="eyebrow-dark">Routing comercial</div>
                <h2>Puntos de entrada</h2>
                <p>Define campañas, botones y QR que crean contexto comercial antes de abrir WhatsApp.</p>
              </div>
              <div class="hero-aside">
                <div class="badge-live">Campaigns</div>
                <div class="hero-aside-title">Contexto</div>
                <p>Un punto de entrada enlaza producto, playbook y atribución técnica con una URL pública estable.</p>
              </div>
            </section>
            <section class="table-card">
              <div class="table-header">
                <div>
                  <h3>Puntos de entrada registrados</h3>
                  <p>Usa estos códigos para campañas, landings y botones de contacto.</p>
                </div>
                <a class="primary-action" href="/backend/entry-points/new">Crear punto de entrada</a>
              </div>
              <div class="table-responsive">
                <table>
                  <thead>
                    <tr><th>Punto de entrada</th><th>Código</th><th>Negocio</th><th>Producto</th><th>Estado</th><th class="text-right">Acciones</th></tr>
                  </thead>
                  <tbody>%s</tbody>
                </table>
              </div>
            </section>
            ',
            $rows !== [] ? implode('', $rows) : '<tr><td colspan="6" class="empty-row">No hay puntos de entrada todavía.</td></tr>'
        );

        $content = $this->twig->render('backend/entry_points/index.html.twig', [
            'rows_html' => $rows !== [] ? implode('', $rows) : '<tr><td colspan="6" class="empty-row">No hay puntos de entrada todavía.</td></tr>',
        ]);

        return $this->renderBackendShell('Puntos de entrada', 'Códigos de campaña y enlaces públicos hacia WhatsApp.', 'entry-points', $content);
    }

    #[Route('/entry-points/new', methods: ['GET', 'POST'])]
    public function entryPointCreate(Request $request, ?ProductRepository $products = null, ?PlaybookRepository $playbooks = null, ?EntryPointRepository $entryPoints = null): Response
    {
        if (!$this->security->isGranted('ROLE_MANAGER')) {
            return new RedirectResponse('/backend/login');
        }

        $values = $this->entryPointFormDefaults();
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isValidEntryPointToken('/backend/entry-points/new', (string) $request->request->get('_csrf_token'))) {
                $error = 'La sesión del formulario ha expirado. Vuelve a intentarlo.';
            } else {
                $values = $this->entryPointFormValuesFromRequest($request);
                $error = $this->validateEntryPointForm($values, null, $products, $playbooks, $entryPoints);

                if ($error === null) {
                    $product = $products instanceof ProductRepository ? $products->find($values['productId']) : null;
                    $entryPoint = new EntryPoint($product, $values['code'], $values['name']);
                    $this->hydrateEntryPointFromForm($entryPoint, $values, $product, $playbooks);
                    $this->entityManager->persist($entryPoint);
                    $this->entityManager->flush();

                    return new RedirectResponse('/backend/entry-points');
                }
            }
        }

        return $this->renderEntryPointForm(
            'Crear punto de entrada',
            'Define el código público y su contexto comercial antes de crear tráfico.',
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

        if (!$entryPoints instanceof EntryPointRepository) {
            return new RedirectResponse('/backend/entry-points');
        }

        $entryPoint = $entryPoints->find($id);
        if (!$entryPoint instanceof EntryPoint) {
            return new RedirectResponse('/backend/entry-points');
        }

        $values = $this->entryPointFormDefaults($entryPoint);
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isValidEntryPointToken('/backend/entry-points/'.$entryPoint->getId()->toRfc4122().'/edit', (string) $request->request->get('_csrf_token'))) {
                $error = 'La sesión del formulario ha expirado. Vuelve a intentarlo.';
            } else {
                $values = $this->entryPointFormValuesFromRequest($request);
                $error = $this->validateEntryPointForm($values, $entryPoint, $products, $playbooks, $entryPoints);

                if ($error === null) {
                    $product = $products instanceof ProductRepository ? $products->find($values['productId']) : $entryPoint->getProduct();
                    $this->hydrateEntryPointFromForm($entryPoint, $values, $product, $playbooks);
                    $this->entityManager->persist($entryPoint);
                    $this->entityManager->flush();

                    return new RedirectResponse('/backend/entry-points/'.$entryPoint->getId()->toRfc4122());
                }
            }
        }

        return $this->renderEntryPointForm(
            'Editar punto de entrada',
            'Ajusta el código, las UTM por defecto y la relación con canal, producto o playbook.',
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

        if (!$entryPoints instanceof EntryPointRepository) {
            return new RedirectResponse('/backend/entry-points');
        }

        $entryPoint = $entryPoints->find($id);
        if (!$entryPoint instanceof EntryPoint) {
            return new RedirectResponse('/backend/entry-points');
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
        if (!$this->security->isGranted('ROLE_MANAGER')) {
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

    /**
     * @param array<int, array{email: string, roles: string, status_label: string, status_class: string, created_at: string, login_label: string}> $users
     */
    private function renderUsersPage(array $users): Response
    {
        return new Response($this->twig->render('backend/users/index.html.twig', [
            'page_title' => 'Usuarios',
            'page_subtitle' => 'Cuentas y roles de acceso interno.',
            'active_nav' => 'admin-users',
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
            'admin-users' => ['href' => '/backend/users', 'label' => 'Usuarios', 'roles' => ['ROLE_ADMIN']],
            'admin-configuration' => ['href' => '/backend/configuration', 'label' => 'Configuración', 'roles' => ['ROLE_ADMIN']],
            'admin-api-health' => ['href' => '/backend/api-health', 'label' => 'Integración técnica', 'roles' => ['ROLE_MANAGER', 'ROLE_ADMIN']],
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
        foreach (['admin-users', 'admin-configuration'] as $key) {
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

        if ($adminItems !== []) {
            $html .= sprintf(
                '<details class="nav-group"%s><summary>Administración <span class="nav-caret">▾</span></summary><div class="nav-subitems">%s</div></details>',
                in_array($activeNav, ['admin-users', 'admin-configuration'], true) ? ' open' : '',
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
     * @return array{current_user_display_name: string, current_user_initials: string}
     */
    private function currentUserTemplateData(): array
    {
        return [
            'current_user_display_name' => $this->currentUserDisplayName(),
            'current_user_initials' => $this->currentUserInitials(),
        ];
    }

    private function currentUser(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
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
     * @return array{name: string, slug: string, businessContext: string, tone: string, whatsappPhoneNumberId: string, whatsappPublicPhone: string, positioning: string, qualificationFocus: string, handoffRules: string, salesBoundaries: string, notes: string, isActive: bool}
     */
    private function tenantFormDefaults(?Tenant $tenant = null): array
    {
        $salesPolicy = $tenant?->getSalesPolicy() ?? [];

        return [
            'name' => $tenant?->getName() ?? '',
            'slug' => $tenant?->getSlug() ?? '',
            'businessContext' => $tenant?->getBusinessContext() ?? '',
            'tone' => $tenant?->getTone() ?? '',
            'whatsappPhoneNumberId' => $tenant?->getWhatsappPhoneNumberId() ?? '',
            'whatsappPublicPhone' => $tenant?->getWhatsappPublicPhone() ?? '',
            'positioning' => $this->tenantPolicyValue($salesPolicy, 'positioning'),
            'qualificationFocus' => $this->tenantPolicyValue($salesPolicy, 'qualificationFocus'),
            'handoffRules' => $this->tenantPolicyValue($salesPolicy, 'handoffRules'),
            'salesBoundaries' => $this->tenantPolicyLines($salesPolicy, 'salesBoundaries'),
            'notes' => $this->tenantPolicyValue($salesPolicy, 'notes'),
            'isActive' => $tenant?->isActive() ?? true,
        ];
    }

    /**
     * @return array{name: string, slug: string, businessContext: string, tone: string, whatsappPhoneNumberId: string, whatsappPublicPhone: string, positioning: string, qualificationFocus: string, handoffRules: string, salesBoundaries: string, notes: string, isActive: bool}
     */
    private function tenantFormValuesFromRequest(Request $request): array
    {
        return [
            'name' => trim((string) $request->request->get('name', '')),
            'slug' => trim((string) $request->request->get('slug', '')),
            'businessContext' => trim((string) $request->request->get('businessContext', '')),
            'tone' => trim((string) $request->request->get('tone', '')),
            'whatsappPhoneNumberId' => trim((string) $request->request->get('whatsappPhoneNumberId', '')),
            'whatsappPublicPhone' => trim((string) $request->request->get('whatsappPublicPhone', '')),
            'positioning' => trim((string) $request->request->get('positioning', '')),
            'qualificationFocus' => trim((string) $request->request->get('qualificationFocus', '')),
            'handoffRules' => trim((string) $request->request->get('handoffRules', '')),
            'salesBoundaries' => trim((string) $request->request->get('salesBoundaries', '')),
            'notes' => trim((string) $request->request->get('notes', '')),
            'isActive' => $request->request->has('isActive'),
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
    ): Response {
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
            'values' => $values,
            'submit_label' => $submitLabel,
        ]);

        return $this->renderBackendShell($pageTitle, $pageSubtitle, 'tenants', $content);
    }

    private function validateTenantForm(array $values, ?Tenant $tenant, ?TenantRepository $tenants): ?string
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
        }

        return null;
    }

    private function hydrateTenantFromForm(Tenant $tenant, array $values): void
    {
        $tenant->setName($values['name']);
        $tenant->setSlug($values['slug']);
        $tenant->setBusinessContext($values['businessContext']);
        $tenant->setTone($values['tone'] !== '' ? $values['tone'] : null);
        $tenant->setWhatsappPhoneNumberId($values['whatsappPhoneNumberId'] !== '' ? $values['whatsappPhoneNumberId'] : null);
        $tenant->setWhatsappPublicPhone($values['whatsappPublicPhone'] !== '' ? $values['whatsappPublicPhone'] : null);
        $tenant->setSalesPolicy($this->tenantSalesPolicyFromForm($values));
        $tenant->setActive($values['isActive']);
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
    private function playbookFormDefaults(?Playbook $playbook = null): array
    {
        $config = $playbook?->getConfig() ?? [];
        $scoring = is_array($config['scoring'] ?? null) ? $config['scoring'] : [];

        return [
            'tenantId' => $playbook?->getTenant()->getId()->toRfc4122() ?? '',
            'productId' => $playbook?->getProduct()?->getId()->toRfc4122() ?? '',
            'name' => $playbook?->getName() ?? '',
            'objective' => $this->playbookConfigValue($config, 'objective'),
            'qualificationQuestions' => $this->playbookConfigLines($config, 'qualificationQuestions'),
            'maxScore' => isset($scoring['maxScore']) && is_int($scoring['maxScore']) ? (string) $scoring['maxScore'] : '10',
            'handoffThreshold' => isset($scoring['handoffThreshold']) && is_int($scoring['handoffThreshold']) ? (string) $scoring['handoffThreshold'] : '7',
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
        ?TenantRepository $tenants,
        ?ProductRepository $products,
        ?string $error = null,
    ): Response {
        $tenantOptions = $this->renderTenantOptions($tenants, $values['tenantId'] ?? '');
        $productOptions = $this->renderProductOptions($products, $values['productId'] ?? '');
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
            'tenant_options_html' => $tenantOptions,
            'product_options_html' => $productOptions,
            'values' => $values,
            'submit_label' => $submitLabel,
        ]);

        return $this->renderBackendShell($pageTitle, $pageSubtitle, 'playbooks', $content);
    }

    private function validatePlaybookForm(array $values, ?Playbook $playbook, ?TenantRepository $tenants, ?ProductRepository $products, ?PlaybookRepository $playbooks): ?string
    {
        if ($values['tenantId'] === '') {
            return 'Debes seleccionar un negocio.';
        }

        if (!$tenants instanceof TenantRepository || !$tenants->find($values['tenantId']) instanceof Tenant) {
            return 'El negocio seleccionado no existe.';
        }

        if ($values['productId'] !== '') {
            if (!$products instanceof ProductRepository || !$products->find($values['productId']) instanceof Product) {
                return 'El producto seleccionado no existe.';
            }
        }

        if ($values['name'] === '') {
            return 'El nombre de la guía comercial es obligatorio.';
        }

        $config = $this->playbookConfigFromForm($values);
        $error = CommercialDomainSchema::validatePlaybookConfig($config);
        if ($error !== null) {
            return sprintf('La guía comercial no es válida: %s', $error);
        }

        return null;
    }

    private function hydratePlaybookFromForm(Playbook $playbook, array $values, ?Tenant $tenant, ?ProductRepository $products): void
    {
        if ($tenant instanceof Tenant) {
            $playbook->setTenant($tenant);
        }

        $product = null;
        if ($values['productId'] !== '' && $products instanceof ProductRepository) {
            $candidate = $products->find($values['productId']);
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
        return CommercialDomainSchema::normalizePlaybookConfig([
            'objective' => $values['objective'],
            'qualificationQuestions' => $this->linesFromTextarea($values['qualificationQuestions'], true),
            'scoring' => [
                'maxScore' => (int) $values['maxScore'],
                'handoffThreshold' => (int) $values['handoffThreshold'],
                'positiveSignals' => $this->linesFromTextarea($values['positiveSignals']),
                'negativeSignals' => $this->linesFromTextarea($values['negativeSignals']),
            ],
            'agendaRules' => $this->linesFromTextarea($values['agendaRules']),
            'handoffRules' => $this->linesFromTextarea($values['handoffRules'], true),
            'allowedActions' => $this->linesFromTextarea($values['allowedActions'], true),
            'notes' => $values['notes'],
        ]);
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
    private function productFormDefaults(?Product $product = null): array
    {
        $salesPolicy = $product?->getSalesPolicy() ?? [];

        return [
            'id' => $product?->getId()->toRfc4122() ?? '',
            'tenantId' => $product?->getTenant()->getId()->toRfc4122() ?? '',
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
        ?TenantRepository $tenants,
        ?string $error = null,
    ): Response {
        $tenantOptions = $this->renderTenantOptions($tenants, $values['tenantId'] ?? '');
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
            'tenant_options_html' => $tenantOptions,
            'values' => $values,
            'submit_label' => $submitLabel,
        ]);

        return $this->renderBackendShell($pageTitle, $pageSubtitle, 'products', $content);
    }

    private function validateProductForm(array $values, ?Product $product, ?TenantRepository $tenants, ?ProductRepository $products): ?string
    {
        if ($values['tenantId'] === '') {
            return 'Debes seleccionar un negocio.';
        }

        if (!$tenants instanceof TenantRepository || !$tenants->find($values['tenantId']) instanceof Tenant) {
            return 'El negocio seleccionado no existe.';
        }

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

        $tenant = $tenants->find($values['tenantId']);
        $slugToValidate = $values['slug'];
        if ($slugToValidate === '' && $product === null && $tenant instanceof Tenant) {
            $slugToValidate = (new Product($tenant, $values['name']))->getSlug();
        }

        if ($slugToValidate !== '' && $products instanceof ProductRepository && $tenant instanceof Tenant) {
            $slugProduct = $products->findOneByTenantAndSlug($tenant, $slugToValidate);
            if ($slugProduct instanceof Product && ($product === null || $slugProduct->getId()->toRfc4122() !== $product->getId()->toRfc4122())) {
                return 'Ya existe otro producto o servicio con ese slug en el negocio seleccionado.';
            }
        }

        if ($values['externalSource'] !== '' && $values['externalReference'] !== '' && $products instanceof ProductRepository) {
            $tenant = $tenants->find($values['tenantId']);
            if ($tenant instanceof Tenant) {
                $externalProduct = $products->findOneByExternalIdentity($tenant, $values['externalSource'], $values['externalReference']);
                if ($externalProduct instanceof Product && ($product === null || $externalProduct->getId()->toRfc4122() !== $product->getId()->toRfc4122())) {
                    return 'Ya existe otro producto o servicio con esa referencia externa en el negocio seleccionado.';
                }
            }
        }

        $salesPolicy = $this->productPolicyFromForm($values);
        $error = CommercialDomainSchema::validateProductSalesPolicy($salesPolicy);
        if ($error !== null) {
            return sprintf('La política comercial del producto no es válida: %s', $error);
        }

        return null;
    }

    private function hydrateProductFromForm(Product $product, array $values, ?Tenant $tenant): void
    {
        if ($tenant instanceof Tenant) {
            $product->setTenant($tenant);
        }

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
    private function productImportFormDefaults(): array
    {
        return [
            'tenantId' => '',
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
    private function validateProductImportForm(array $values, ?TenantRepository $tenants): ?string
    {
        if ($values['tenantId'] === '') {
            return 'Debes seleccionar un negocio.';
        }

        if (!$tenants instanceof TenantRepository || !$tenants->find($values['tenantId']) instanceof Tenant) {
            return 'El negocio seleccionado no existe.';
        }

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
        ?TenantRepository $tenants,
        ?ProductCatalogImportResult $result = null,
        ?string $error = null,
    ): Response {
        $tenantOptions = $this->renderTenantOptions($tenants, $values['tenantId'] ?? '');
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
            'tenant_options_html' => $tenantOptions,
            'values' => $values,
            'submit_label' => $submitLabel,
            'result_html' => $resultHtml,
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
    private function renderProductOptions(?ProductRepository $products, string $selectedId): string
    {
        $options = ['<option value="">Sin producto</option>'];
        if ($products instanceof ProductRepository) {
            foreach ($products->findAllOrdered() as $product) {
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
    private function renderPlaybookOptions(?PlaybookRepository $playbooks, string $selectedId): string
    {
        $options = ['<option value="">Sin playbook</option>'];
        if ($playbooks instanceof PlaybookRepository) {
            foreach ($playbooks->findAllOrdered() as $playbook) {
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
    private function validateEntryPointForm(array $values, ?EntryPoint $entryPoint, ?ProductRepository $products, ?PlaybookRepository $playbooks, ?EntryPointRepository $entryPoints): ?string
    {
        if ($values['code'] === '') {
            return 'El código del punto de entrada es obligatorio.';
        }

        if ($values['productId'] === '' || !$products instanceof ProductRepository || !$products->find($values['productId']) instanceof Product) {
            return 'Debes seleccionar un producto válido.';
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
            $product = $products->find($values['productId']);
            if (!$playbook instanceof Playbook || !$product instanceof Product || $playbook->getTenant()->getId()->toRfc4122() !== $product->getTenant()->getId()->toRfc4122()) {
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
    private function hydrateEntryPointFromForm(EntryPoint $entryPoint, array $values, ?Product $product, ?PlaybookRepository $playbooks): void
    {
        if ($product instanceof Product) {
            $entryPoint->setProduct($product);
        }

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
        $errorHtml = $error !== null ? sprintf('<div class="form-alert form-alert-error">%s</div>', htmlspecialchars($error, ENT_QUOTES, 'UTF-8')) : '';
        $content = $this->twig->render('backend/entry_points/form.html.twig', [
            'hero_title' => $heroTitle,
            'page_subtitle' => $pageSubtitle,
            'error_html' => $errorHtml,
            'action_url' => $actionUrl,
            'csrf_token' => $this->entryPointTokenValue($actionUrl),
            'product_options_html' => $this->renderProductOptions($products, $values['productId'] ?? ''),
            'playbook_options_html' => $this->renderPlaybookOptions($playbooks, $values['playbookId'] ?? ''),
            'values' => $values,
            'submit_label' => $submitLabel,
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
        return sprintf(
            '<div class="alert %s">%s<button class="alert-dismiss" type="button" aria-label="Cerrar mensaje" onclick="this.parentElement.remove()">×</button></div>',
            htmlspecialchars($class, ENT_QUOTES, 'UTF-8'),
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
            'audio_timeout_seconds' => trim((string) $request->request->get('audio_timeout_seconds', '')),
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
                    'audio_gateway_base_url',
                    'ollama_timeout_seconds',
                    'audio_timeout_seconds',
                ]),
                'actions' => [
                    ['class' => 'secondary-action', 'name' => 'action', 'value' => 'test_ollama', 'label' => 'Probar conexión de Ollama'],
                ],
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
