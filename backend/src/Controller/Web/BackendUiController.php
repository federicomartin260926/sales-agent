<?php

namespace App\Controller\Web;

use App\Domain\CommercialDomainSchema;
use App\Entity\Playbook;
use App\Entity\Product;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\PlaybookRepository;
use App\Repository\ProductRepository;
use App\Repository\TenantRepository;
use App\Repository\UserRepository;
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

final class BackendUiController
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
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

        $html = <<<'HTML'
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Sales Agent Backend</title>
  <style>
    :root {
      color-scheme: light;
      --page-bg: #f5f7fb;
      --panel: #ffffff;
      --panel-soft: #f8fafc;
      --panel-dark: #1f242a;
      --panel-darker: #151a20;
      --border: #d9e0ea;
      --border-strong: #c7d0dc;
      --text: #182433;
      --muted: #637287;
      --accent: #0f6ec7;
      --accent-strong: #134fbf;
      --success: #0f6ec7;
      --danger: #c33434;
      --shadow: 0 18px 42px rgba(15, 23, 42, 0.08);
      --radius: 18px;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      min-height: 100vh;
      font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      color: var(--text);
      background:
        radial-gradient(circle at top left, rgba(15, 110, 199, 0.12), transparent 28%),
        radial-gradient(circle at bottom right, rgba(19, 79, 191, 0.08), transparent 26%),
        var(--page-bg);
      padding: 28px 18px;
    }
    .shell {
      width: min(1160px, 100%);
      margin: 0 auto;
      display: grid;
      grid-template-columns: 1.08fr 0.92fr;
      gap: 24px;
      align-items: stretch;
    }
    .hero, .card {
      border: 1px solid var(--border);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
    }
    .hero {
      padding: 34px;
      background: linear-gradient(180deg, #20272f 0%, #131922 100%);
      color: #f8fbff;
      min-height: 560px;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
    }
    .eyebrow {
      display: inline-flex;
      align-items: center;
      gap: 10px;
      color: #9ed0ff;
      text-transform: uppercase;
      letter-spacing: 0.18em;
      font-size: 12px;
      font-weight: 700;
    }
    .eyebrow:before {
      content: "";
      width: 10px;
      height: 10px;
      border-radius: 999px;
      background: #4fc3f7;
      box-shadow: 0 0 0 6px rgba(79, 195, 247, 0.14);
    }
    h1 {
      margin: 18px 0 14px;
      font-size: clamp(36px, 5vw, 58px);
      line-height: 0.96;
      letter-spacing: -0.06em;
    }
    p {
      margin: 0;
      color: rgba(248, 251, 255, 0.76);
      font-size: 16px;
      line-height: 1.7;
      max-width: 60ch;
    }
    .card {
      background: var(--panel);
      padding: 30px;
      display: flex;
      flex-direction: column;
      justify-content: center;
    }
    .card h2 {
      margin: 0 0 8px;
      font-size: 28px;
      letter-spacing: -0.04em;
    }
    .card .subtitle {
      color: var(--muted);
      margin-bottom: 26px;
    }
    .alert {
      border-radius: 16px;
      padding: 14px 16px;
      margin-bottom: 16px;
      font-size: 14px;
    }
    .alert-error {
      background: rgba(195, 52, 52, 0.08);
      border: 1px solid rgba(195, 52, 52, 0.22);
      color: #a42222;
    }
    label {
      display: block;
      margin-bottom: 8px;
      font-size: 14px;
      color: var(--muted);
      font-weight: 700;
    }
    input {
      width: 100%;
      border-radius: 14px;
      border: 1px solid var(--border);
      background: var(--panel-soft);
      color: var(--text);
      padding: 14px 16px;
      font-size: 15px;
      outline: none;
      transition: border-color 120ms ease, box-shadow 120ms ease;
    }
    input:focus {
      border-color: rgba(15, 110, 199, 0.55);
      box-shadow: 0 0 0 4px rgba(15, 110, 199, 0.1);
    }
    .field + .field {
      margin-top: 16px;
    }
    .button {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 100%;
      border: 0;
      border-radius: 14px;
      margin-top: 22px;
      padding: 14px 18px;
      background: linear-gradient(135deg, var(--accent), #134fbf);
      color: white;
      font-weight: 800;
      cursor: pointer;
      text-decoration: none;
    }
    .footer {
      margin-top: 20px;
      color: var(--muted);
      font-size: 13px;
      line-height: 1.6;
    }
    .footer code {
      color: var(--text);
    }
    @media (max-width: 960px) {
      body { padding: 18px 14px; }
      .shell { grid-template-columns: 1fr; }
      .hero { min-height: auto; }
    }
  </style>
</head>
<body>
  <main class="shell">
    <section class="hero">
      <div>
        <div class="eyebrow">Sales Agent Backend</div>
        <h1>Gestiona negocios y agentes IA desde un solo lugar.</h1>
        <p>
          Desde aquí defines cómo se comporta cada negocio, producto o servicio: qué usuarios lo administran,
          qué conocimiento utiliza el agente y qué enfoque comercial aplica con cada cliente.
        </p>
      </div>
    </section>
    <section class="card">
      <h2>Iniciar sesión</h2>
      <div class="subtitle">Usa tu usuario de administración para entrar al backend.</div>
      {{ERROR}}
      <form method="post" action="/backend/login-check">
        <div class="field">
          <label for="email">Email</label>
          <input id="email" name="email" type="email" value="{{LAST_USERNAME}}" autocomplete="username" required>
        </div>
        <div class="field">
          <label for="password">Clave</label>
          <input id="password" name="password" type="password" autocomplete="current-password" required>
        </div>
        <button class="button" type="submit">Entrar al backend</button>
      </form>
    </section>
  </main>
</body>
</html>
HTML;

        $html = str_replace(
            ['{{ERROR}}', '{{LAST_USERNAME}}'],
            [$errorHtml, htmlspecialchars($lastUsername, ENT_QUOTES, 'UTF-8')],
            $html
        );

        return new Response($html);
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

        return $this->renderBackendShell(
            'Panel comercial',
            'Resumen de negocios, usuarios y configuración comercial del agente.',
            'dashboard',
            $content
        );
    }

    #[Route('/playbooks', methods: ['GET'])]
    public function playbooks(?PlaybookRepository $playbooks = null): Response
    {
        if (!$this->security->isGranted('ROLE_MANAGER')) {
            return new RedirectResponse('/backend/login');
        }

        $rows = array_map(static function (Playbook $playbook): string {
            $tenant = $playbook->getTenant();
            $product = $playbook->getProduct();
            $status = $playbook->isActive() ? '<span class="status-ok">Activo</span>' : '<span class="status-off">Inactivo</span>';
            $editUrl = sprintf('/backend/playbooks/%s/edit', rawurlencode($playbook->getId()->toRfc4122()));

            return sprintf(
                '<tr>
                    <td><strong>%s</strong><div class="subtle">%s</div></td>
                    <td>%s</td>
                    <td>%s</td>
                    <td>%s</td>
                    <td class="text-right"><a class="icon-action" href="%s" title="Editar guía comercial" aria-label="Editar guía comercial">%s</a></td>
                  </tr>',
                htmlspecialchars($playbook->getName(), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($playbook->getConfigSummary(), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($tenant->getName(), ENT_QUOTES, 'UTF-8'),
                $product ? htmlspecialchars($product->getName(), ENT_QUOTES, 'UTF-8') : 'Sin producto',
                $status,
                htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8'),
                self::iconEditSvg()
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
            $rows !== [] ? implode('', $rows) : '<tr><td colspan="5" class="empty-row">No hay guías comerciales todavía.</td></tr>'
        );

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

    #[Route('/tenants', methods: ['GET'])]
    public function tenants(?TenantRepository $tenants = null): Response
    {
        if (!$this->security->isGranted('ROLE_MANAGER')) {
            return new RedirectResponse('/backend/login');
        }

        $rows = array_map(static function (Tenant $tenant): string {
            $policySummary = $tenant->getSalesPolicySummary();
            $status = $tenant->isActive() ? '<span class="status-ok">Activo</span>' : '<span class="status-off">Inactivo</span>';
            $editUrl = sprintf('/backend/tenants/%s/edit', rawurlencode($tenant->getId()->toRfc4122()));

            return sprintf(
                '<tr>
                    <td><strong>%s</strong><div class="subtle">%s</div></td>
                    <td><code>%s</code></td>
                    <td>%s</td>
                    <td>%s</td>
                    <td class="text-right"><a class="icon-action" href="%s" title="Editar negocio" aria-label="Editar negocio">%s</a></td>
                  </tr>',
                htmlspecialchars($tenant->getName(), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($tenant->getBusinessContext(), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($tenant->getSlug(), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($policySummary, ENT_QUOTES, 'UTF-8'),
                $status,
                htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8'),
                self::iconEditSvg()
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
            $rows !== [] ? implode('', $rows) : '<tr><td colspan="5" class="empty-row">No hay negocios todavía.</td></tr>'
        );

        return $this->renderBackendShell('Negocios', 'Negocios y contextos operativos.', 'tenants', $content);
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
    public function users(?UserRepository $users = null): Response
    {
        if (!$this->security->isGranted('ROLE_ADMIN')) {
            return new RedirectResponse('/backend/dashboard');
        }

        $rows = array_map(static function (User $user): string {
            $roles = implode(', ', array_map(static fn (string $role): string => strtoupper($role), $user->getRoles()));
            $status = $user->isActive() ? '<span class="status-ok">Activo</span>' : '<span class="status-off">Inactivo</span>';

            return sprintf(
                '<tr>
                    <td><strong>%s</strong></td>
                    <td>%s</td>
                    <td>%s</td>
                    <td>%s</td>
                    <td class="text-right">%s</td>
                  </tr>',
                htmlspecialchars($user->getEmail(), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($roles, ENT_QUOTES, 'UTF-8'),
                $status,
                htmlspecialchars($user->getCreatedAt()->format('Y-m-d H:i'), ENT_QUOTES, 'UTF-8'),
                $user->isActive() ? 'Login ok' : 'Sin acceso'
            );
        }, $users ? $users->findBy([], ['createdAt' => 'DESC']) : []);

        $content = sprintf(
            '
            <section class="hero-panel">
              <div class="hero-copy">
                <div class="eyebrow-dark">Acceso interno</div>
                <h2>Usuarios</h2>
                <p>Administración de cuentas, roles y acceso interno. Este panel separa la sesión de navegador de la API técnica.</p>
              </div>
              <div class="hero-aside">
                <div class="badge-live">Admin</div>
                <div class="hero-aside-title">Control de acceso</div>
                <p>Los usuarios con rol admin pueden gestionar cuentas; el resto de roles se mantiene orientado a operación.</p>
              </div>
            </section>
            <section class="table-card">
              <div class="table-header">
                <div>
                  <h3>Usuarios registrados</h3>
                  <p>Email, roles y estado de acceso.</p>
                </div>
                <a class="secondary-action" href="/backend/dashboard">Volver al dashboard</a>
              </div>
              <div class="table-responsive">
                <table>
                  <thead>
                    <tr><th>Email</th><th>Roles</th><th>Estado</th><th>Creado</th><th class="text-right">Login</th></tr>
                  </thead>
                  <tbody>%s</tbody>
                </table>
              </div>
            </section>
            ',
            $rows !== [] ? implode('', $rows) : '<tr><td colspan="5" class="empty-row">No hay usuarios todavía.</td></tr>'
        );

        return $this->renderBackendShell('Usuarios', 'Cuentas y roles de acceso interno.', 'users', $content);
    }

    #[Route('/products', methods: ['GET'])]
    public function products(?ProductRepository $products = null): Response
    {
        if (!$this->security->isGranted('ROLE_MANAGER')) {
            return new RedirectResponse('/backend/login');
        }

        $rows = array_map(static function (Product $product): string {
            $status = $product->isActive() ? '<span class="status-ok">Activo</span>' : '<span class="status-off">Inactivo</span>';
            $editUrl = sprintf('/backend/products/%s/edit', rawurlencode($product->getId()->toRfc4122()));

            return sprintf(
                '<tr>
                    <td><strong>%s</strong><div class="subtle">%s</div></td>
                    <td>%s</td>
                    <td>%s</td>
                    <td>%s</td>
                    <td class="text-right"><a class="icon-action" href="%s" title="Editar producto / servicio" aria-label="Editar producto / servicio">%s</a></td>
                  </tr>',
                htmlspecialchars($product->getName(), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($product->getDescription(), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($product->getTenant()->getName(), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($product->getValueProposition(), ENT_QUOTES, 'UTF-8'),
                $status,
                htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8'),
                self::iconEditSvg()
            );
        }, $products ? $products->findAllOrdered() : []);

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
            <section class="table-card">
              <div class="table-header">
                <div>
                  <h3>Productos / servicios registrados</h3>
                  <p>Negocio, propuesta de valor y estado.</p>
                </div>
                <a class="primary-action" href="/backend/products/new">Crear producto / servicio</a>
              </div>
              <div class="table-responsive">
                <table>
                  <thead>
                    <tr><th>Producto / servicio</th><th>Negocio</th><th>Propuesta de valor</th><th>Estado</th><th class="text-right">Acciones</th></tr>
                  </thead>
                  <tbody>%s</tbody>
                </table>
              </div>
            </section>
            ',
            $rows !== [] ? implode('', $rows) : '<tr><td colspan="5" class="empty-row">No hay productos o servicios todavía.</td></tr>'
        );

        return $this->renderBackendShell('Productos / servicios', 'Catálogo comercial por negocio.', 'products', $content);
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

        return $this->renderBackendShell('API Health', 'Estado de la API técnica y rutas internas.', 'api-health', $content);
    }

    private function renderBackendShell(string $pageTitle, string $pageSubtitle, string $activeNav, string $contentHtml): Response
    {
        $navHtml = $this->renderNav($activeNav);
        $html = <<<'HTML'
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{{PAGE_TITLE}} - Sales Agent CRM</title>
  <style>
    :root {
      --page-bg: #f5f7fb;
      --sidebar: #ffffff;
      --topbar: #1f242a;
      --topbar-soft: #2d343d;
      --panel: #ffffff;
      --panel-soft: #f8fafc;
      --border: #d9e0ea;
      --border-strong: #c7d0dc;
      --text: #182433;
      --muted: #637287;
      --accent: #0f6ec7;
      --accent-strong: #134fbf;
      --success: #0f6ec7;
      --danger: #c33434;
      --shadow: 0 14px 36px rgba(15, 23, 42, 0.08);
      --radius: 18px;
    }
    * { box-sizing: border-box; }
    html, body { min-height: 100%; }
    body {
      margin: 0;
      font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      color: var(--text);
      background: var(--page-bg);
    }
    a { color: inherit; }
    .layout {
      min-height: 100vh;
      display: grid;
      grid-template-columns: 270px minmax(0, 1fr);
    }
    .sidebar {
      background: var(--sidebar);
      border-right: 1px solid var(--border);
      padding: 24px 18px;
      display: flex;
      flex-direction: column;
      gap: 18px;
    }
    .brand {
      padding-bottom: 16px;
      border-bottom: 1px solid var(--border);
    }
    .brand-name {
      font-size: 20px;
      font-weight: 800;
      letter-spacing: -0.04em;
      color: #0f172a;
    }
    .brand-sub {
      margin-top: 6px;
      color: var(--muted);
      font-size: 13px;
      line-height: 1.5;
    }
    .nav {
      display: grid;
      gap: 8px;
      margin-top: 4px;
    }
    .nav a {
      display: flex;
      align-items: center;
      justify-content: flex-start;
      gap: 8px;
      padding: 12px 14px;
      border-radius: 14px;
      border: 1px solid transparent;
      text-decoration: none;
      color: #334155;
      background: transparent;
    }
    .nav a.active {
      background: #eef1f5;
      border-color: #e2e8f0;
      color: #0f172a;
      font-weight: 700;
    }
    .nav a:hover,
    .nav summary:hover {
      background: #f6f8fb;
    }
    .nav-group {
      display: grid;
      gap: 8px;
    }
    .nav-group summary {
      list-style: none;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 12px 14px;
      border-radius: 14px;
      color: #334155;
      user-select: none;
    }
    .nav-group summary::-webkit-details-marker {
      display: none;
    }
    .nav-group[open] > summary {
      background: #f6f8fb;
      color: #0f172a;
      font-weight: 700;
    }
    .nav-subitems {
      display: grid;
      gap: 6px;
      margin-left: 12px;
      padding-left: 10px;
      border-left: 1px solid #e5eaf1;
    }
    .nav-subitems a {
      background: transparent;
      border-color: transparent;
      padding: 10px 12px;
      border-radius: 12px;
    }
    .nav-subitems a.active {
      background: #eef1f5;
      border-color: #e2e8f0;
    }
    .nav-caret {
      color: var(--muted);
      font-size: 12px;
    }
    .content-shell {
      min-width: 0;
      display: flex;
      flex-direction: column;
    }
    .topbar {
      background: var(--topbar);
      color: #fff;
      padding: 12px 22px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      box-shadow: 0 4px 24px rgba(15, 23, 42, 0.2);
    }
    .page-title {
      margin: 0;
      font-size: 18px;
      font-weight: 800;
      letter-spacing: -0.03em;
    }
    .page-subtitle {
      margin-top: 4px;
      color: rgba(255, 255, 255, 0.62);
      font-size: 13px;
      line-height: 1.4;
    }
    .user-area {
      display: flex;
      align-items: center;
      gap: 12px;
      color: #fff;
      flex-wrap: wrap;
      justify-content: flex-end;
    }
    .user-dropdown {
      position: relative;
    }
    .user-summary {
      display: inline-flex;
      align-items: center;
      gap: 10px;
      cursor: pointer;
      list-style: none;
      user-select: none;
    }
    .user-summary::-webkit-details-marker {
      display: none;
    }
    .user-chip {
      display: inline-flex;
      align-items: center;
      gap: 10px;
      padding: 8px 12px;
      border-radius: 999px;
      background: rgba(255, 255, 255, 0.07);
      border: 1px solid rgba(255, 255, 255, 0.12);
      white-space: nowrap;
    }
    .avatar {
      width: 28px;
      height: 28px;
      border-radius: 999px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      background: linear-gradient(135deg, #0f6ec7, #134fbf);
      font-size: 12px;
      font-weight: 900;
    }
    .user-meta {
      display: flex;
      flex-direction: column;
      line-height: 1.1;
    }
    .user-meta strong {
      font-size: 14px;
      font-weight: 700;
    }
    .user-links {
      display: grid;
      gap: 4px;
      position: absolute;
      top: calc(100% + 10px);
      right: 0;
      min-width: 190px;
      background: #fff;
      border: 1px solid var(--border);
      border-radius: 14px;
      padding: 8px;
      box-shadow: 0 18px 36px rgba(15, 23, 42, 0.15);
      z-index: 20;
    }
    .user-links a {
      display: flex;
      align-items: center;
      gap: 10px;
      text-decoration: none;
      color: #1f2937;
      background: transparent;
      padding: 10px 12px;
      border-radius: 10px;
      font-size: 14px;
    }
    .user-links a:hover {
      background: #f6f8fb;
    }
    .main {
      padding: 24px;
      min-width: 0;
    }
    .hero-panel,
    .table-card {
      background: var(--panel);
      border: 1px solid var(--border);
      border-radius: 18px;
      box-shadow: var(--shadow);
    }
    .hero-panel {
      display: grid;
      grid-template-columns: minmax(0, 1.45fr) minmax(260px, 0.75fr);
      gap: 18px;
      padding: 24px;
      margin-bottom: 18px;
      background:
        linear-gradient(135deg, rgba(15, 110, 199, 0.08), rgba(19, 79, 191, 0.05)),
        #ffffff;
    }
    .hero-panel-single {
      grid-template-columns: minmax(0, 1fr);
    }
    .eyebrow-dark {
      color: var(--accent);
      font-size: 12px;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 0.12em;
      margin-bottom: 10px;
    }
    .hero-copy h2 {
      margin: 0 0 10px;
      font-size: clamp(32px, 4vw, 48px);
      letter-spacing: -0.05em;
      line-height: 0.98;
      color: #0f172a;
    }
    .hero-copy p,
    .hero-aside p,
    .table-header p {
      margin: 0;
      color: #475569;
      line-height: 1.65;
    }
    .hero-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      margin-top: 22px;
    }
    .primary-action,
    .secondary-action,
    .card-action {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 44px;
      padding: 0 16px;
      border-radius: 12px;
      text-decoration: none;
      font-weight: 700;
      transition: transform 120ms ease, box-shadow 120ms ease;
    }
    .primary-action {
      background: linear-gradient(135deg, var(--accent), #134fbf);
      color: white;
      box-shadow: 0 12px 24px rgba(15, 110, 199, 0.18);
    }
    .secondary-action {
      background: var(--accent);
      border: 1px solid transparent;
      color: #fff;
      box-shadow: 0 12px 24px rgba(15, 110, 199, 0.14);
    }
    .card-action {
      background: var(--accent);
      border: 1px solid transparent;
      color: #fff;
      min-height: 40px;
      padding: 0 14px;
      box-shadow: 0 10px 20px rgba(15, 110, 199, 0.14);
    }
    .primary-action:hover,
    .secondary-action:hover,
    .card-action:hover {
      transform: translateY(-1px);
    }
    .hero-aside {
      background: #0f172a;
      color: #fff;
      border-radius: 16px;
      padding: 18px;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      min-height: 170px;
    }
    .badge-live {
      align-self: flex-start;
      display: inline-flex;
      padding: 6px 10px;
      border-radius: 999px;
      background: rgba(15, 110, 199, 0.14);
      border: 1px solid rgba(15, 110, 199, 0.24);
      color: #d9ecff;
      font-size: 12px;
      font-weight: 800;
    }
    .hero-aside-title {
      margin-top: 10px;
      font-size: 18px;
      font-weight: 800;
      letter-spacing: -0.03em;
    }
    .hero-aside p {
      color: rgba(255, 255, 255, 0.72);
      margin-top: 6px;
    }
    .alert {
      border-radius: 16px;
      padding: 14px 16px;
      margin-bottom: 16px;
      font-size: 14px;
      border: 1px solid transparent;
    }
    .alert-success {
      background: rgba(15, 110, 199, 0.08);
      border-color: rgba(15, 110, 199, 0.18);
      color: #134fbf;
    }
    .alert-error {
      background: rgba(195, 52, 52, 0.08);
      border-color: rgba(195, 52, 52, 0.18);
      color: #a42222;
    }
    .stats-grid {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 16px;
      margin-bottom: 18px;
    }
    .metric {
      background: #fff;
      border: 1px solid var(--border);
      border-radius: 16px;
      padding: 18px;
      box-shadow: var(--shadow);
    }
    .metric-label {
      color: var(--muted);
      font-size: 13px;
      margin-bottom: 10px;
    }
    .metric-value {
      font-size: 32px;
      font-weight: 800;
      letter-spacing: -0.06em;
      color: #0f172a;
    }
    .metric-note {
      margin-top: 8px;
      color: #475569;
      font-size: 13px;
      line-height: 1.5;
    }
    .cards-grid {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 16px;
    }
    .profile-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 16px;
    }
    .profile-card {
      background: #fff;
      border: 1px solid var(--border);
      border-radius: 16px;
      box-shadow: var(--shadow);
      overflow: hidden;
    }
    .profile-card-header {
      padding: 16px 18px;
      border-bottom: 1px solid var(--border);
      font-size: 18px;
      font-weight: 800;
      letter-spacing: -0.03em;
      color: #0f172a;
    }
    .profile-card-body {
      padding: 18px;
    }
    .profile-meta {
      display: grid;
      gap: 10px;
      margin-bottom: 18px;
      color: #0f172a;
      line-height: 1.5;
    }
    .profile-meta strong {
      color: #0f172a;
    }
    .profile-form {
      display: grid;
      gap: 16px;
    }
    .form-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 18px;
    }
    .field-full {
      grid-column: 1 / -1;
    }
    .field-check {
      display: flex;
      flex-direction: column;
      justify-content: flex-start;
      gap: 8px;
    }
    .field label {
      display: block;
      margin-bottom: 8px;
      font-size: 14px;
      color: var(--muted);
      font-weight: 700;
    }
    .field input {
      width: 100%;
      border-radius: 14px;
      border: 1px solid var(--border);
      background: var(--panel-soft);
      color: var(--text);
      padding: 14px 16px;
      font-size: 15px;
      outline: none;
      transition: border-color 120ms ease, box-shadow 120ms ease;
    }
    .field input:focus {
      border-color: rgba(15, 110, 199, 0.55);
      box-shadow: 0 0 0 4px rgba(15, 110, 199, 0.1);
    }
    .field select {
      width: 100%;
      border-radius: 14px;
      border: 1px solid var(--border);
      background: var(--panel-soft);
      color: var(--text);
      padding: 14px 16px;
      font-size: 15px;
      outline: none;
      transition: border-color 120ms ease, box-shadow 120ms ease;
      min-height: 52px;
      font-family: inherit;
    }
    .field select:focus {
      border-color: rgba(15, 110, 199, 0.55);
      box-shadow: 0 0 0 4px rgba(15, 110, 199, 0.1);
    }
    .field textarea {
      width: 100%;
      border-radius: 14px;
      border: 1px solid var(--border);
      background: var(--panel-soft);
      color: var(--text);
      padding: 14px 16px;
      font-size: 15px;
      outline: none;
      transition: border-color 120ms ease, box-shadow 120ms ease;
      resize: vertical;
      min-height: 160px;
      font-family: inherit;
    }
    .field textarea:focus {
      border-color: rgba(15, 110, 199, 0.55);
      box-shadow: 0 0 0 4px rgba(15, 110, 199, 0.1);
    }
    .checkbox-inline {
      display: inline-flex;
      align-items: center;
      gap: 12px;
      min-height: 0;
      padding: 2px 0;
      border: 0;
      background: transparent;
      font-size: 15px;
      color: var(--text);
      font-weight: 600;
      cursor: pointer;
      width: fit-content;
    }
    .checkbox-inline input {
      appearance: none;
      width: 42px;
      height: 24px;
      margin: 0;
      padding: 0;
      border-radius: 999px;
      border: 1px solid #cbd5e1;
      background: #d7e1ec;
      position: relative;
      outline: none;
      transition: background 140ms ease, border-color 140ms ease, box-shadow 140ms ease;
      flex: 0 0 auto;
      cursor: pointer;
    }
    .checkbox-inline span {
      display: inline-flex;
      align-items: center;
      min-height: 24px;
    }
    .checkbox-inline input::before {
      content: '';
      position: absolute;
      top: 2px;
      left: 2px;
      width: 18px;
      height: 18px;
      border-radius: 999px;
      background: #fff;
      box-shadow: 0 2px 6px rgba(15, 23, 42, 0.18);
      transition: transform 140ms ease;
    }
    .checkbox-inline input:checked {
      background: linear-gradient(135deg, var(--accent), #134fbf);
      border-color: transparent;
    }
    .checkbox-inline input:checked::before {
      transform: translateX(18px);
    }
    .checkbox-inline input:focus-visible {
      box-shadow: 0 0 0 4px rgba(15, 110, 199, 0.12);
    }
    .field-check .field-note {
      margin-top: 4px;
      padding-left: 16px;
    }
    .section-label {
      font-size: 14px;
      color: var(--muted);
      font-weight: 700;
      margin-bottom: 6px;
    }
    .section-note {
      font-size: 13px;
      color: var(--muted);
      line-height: 1.5;
      margin-bottom: 14px;
    }
    .policy-tabs {
      margin-top: 14px;
    }
    .policy-tablist {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      margin-bottom: 16px;
    }
    .policy-tab {
      appearance: none;
      border: 1px solid #d7e1ec;
      background: #f7f9fc;
      color: #334155;
      border-radius: 999px;
      padding: 10px 14px;
      font-weight: 700;
      font-size: 14px;
      cursor: pointer;
      transition: background 120ms ease, color 120ms ease, border-color 120ms ease, transform 120ms ease;
    }
    .policy-tab:hover {
      transform: translateY(-1px);
    }
    .policy-tab.active {
      background: linear-gradient(135deg, var(--accent), #134fbf);
      color: #fff;
      border-color: transparent;
      box-shadow: 0 10px 22px rgba(15, 110, 199, 0.16);
    }
    .policy-panels {
      display: grid;
      gap: 14px;
    }
    .policy-panel {
      border: 1px solid var(--border);
      border-radius: 16px;
      background: var(--panel-soft);
      padding: 16px;
    }
    .policy-panel label {
      display: block;
      margin-bottom: 8px;
      font-size: 14px;
      color: var(--muted);
      font-weight: 700;
    }
    .policy-panel .form-grid {
      gap: 14px;
    }
    .field-note {
      margin-top: 8px;
      font-size: 13px;
      color: var(--muted);
    }
    .profile-actions {
      display: flex;
      justify-content: flex-end;
      margin-top: 2px;
    }
    .form-actions {
      display: flex;
      justify-content: flex-end;
      margin-top: 24px;
      padding-top: 14px;
    }
    .form-alert {
      border-radius: 14px;
      padding: 14px 16px;
      margin-bottom: 18px;
      font-size: 14px;
      line-height: 1.5;
    }
    .form-alert-error {
      background: rgba(195, 52, 52, 0.08);
      border: 1px solid rgba(195, 52, 52, 0.18);
      color: #a61b1b;
    }
    .info-card {
      background: #fff;
      border: 1px solid var(--border);
      border-radius: 16px;
      padding: 18px;
      box-shadow: var(--shadow);
      min-height: 160px;
    }
    .info-card h3 {
      margin: 0 0 8px;
      font-size: 18px;
      letter-spacing: -0.03em;
      color: #0f172a;
    }
    .info-card p {
      margin: 0;
      color: #475569;
      line-height: 1.65;
    }
    .table-card {
      padding: 18px;
    }
    .table-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 16px;
      margin-bottom: 14px;
    }
    .table-header-actions {
      display: flex;
      gap: 10px;
      align-items: flex-start;
      flex-wrap: wrap;
      justify-content: flex-end;
    }
    .icon-action {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 42px;
      height: 42px;
      border-radius: 14px;
      background: #f1f5fb;
      border: 1px solid #d8e2ee;
      color: #0f172a;
      text-decoration: none;
      transition: transform 120ms ease, box-shadow 120ms ease, background 120ms ease;
      box-shadow: 0 8px 18px rgba(15, 23, 42, 0.06);
    }
    .icon-action:hover {
      transform: translateY(-1px);
      background: #eaf1fb;
    }
    .icon-action svg {
      display: block;
    }
    .table-header h3 {
      margin: 0 0 6px;
      font-size: 18px;
      letter-spacing: -0.03em;
      color: #0f172a;
    }
    .table-responsive {
      overflow-x: auto;
    }
    table {
      width: 100%;
      border-collapse: collapse;
      min-width: 760px;
    }
    thead th {
      text-align: left;
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: 0.1em;
      color: var(--muted);
      padding: 12px 12px;
      border-bottom: 1px solid var(--border);
    }
    tbody td {
      padding: 14px 12px;
      border-bottom: 1px solid #edf1f7;
      vertical-align: top;
    }
    tbody tr:last-child td {
      border-bottom: 0;
    }
    .subtle {
      color: var(--muted);
      font-size: 13px;
      margin-top: 4px;
      line-height: 1.4;
    }
    .status-ok,
    .status-off {
      display: inline-flex;
      align-items: center;
      padding: 6px 10px;
      border-radius: 999px;
      font-size: 12px;
      font-weight: 800;
    }
    .status-ok {
      background: rgba(15, 110, 199, 0.12);
      color: var(--success);
    }
    .status-off {
      background: rgba(195, 52, 52, 0.08);
      color: var(--danger);
    }
    .empty-row {
      text-align: center;
      color: var(--muted);
      padding: 28px 12px;
    }
    code {
      background: #f3f6fb;
      border: 1px solid #e3eaf2;
      border-radius: 8px;
      padding: 2px 6px;
      font-size: 0.95em;
    }
    .text-right { text-align: right; }
    @media (max-width: 1180px) {
      .layout { grid-template-columns: 1fr; }
      .sidebar { border-right: 0; border-bottom: 1px solid var(--border); }
      .hero-panel,
      .stats-grid,
      .cards-grid,
      .profile-grid,
      .form-grid {
        grid-template-columns: 1fr;
      }
      .policy-tablist {
        flex-direction: column;
      }
      .policy-tab {
        width: 100%;
        text-align: left;
      }
      .user-links {
        right: auto;
        left: 0;
      }
    }
  </style>
</head>
<body>
  <main class="layout">
    <aside class="sidebar">
      <div class="brand">
        <div class="brand-name">Sales Agent CRM</div>
        <div class="brand-sub">Panel para definir negocios, productos/servicios y comportamiento del agente IA.</div>
      </div>
      <nav class="nav">
        {{NAV}}
      </nav>
    </aside>

    <section class="content-shell">
      <header class="topbar">
        <div>
          <h1 class="page-title">{{PAGE_TITLE}}</h1>
          <div class="page-subtitle">{{PAGE_SUBTITLE}}</div>
        </div>
        {{USER_MENU}}
      </header>
      <main class="main">
        {{CONTENT}}
      </main>
    </section>
  </main>
</body>
</html>
HTML;

        return new Response(strtr($html, [
            '{{PAGE_TITLE}}' => htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'),
            '{{PAGE_SUBTITLE}}' => htmlspecialchars($pageSubtitle, ENT_QUOTES, 'UTF-8'),
            '{{NAV}}' => $navHtml,
            '{{CONTENT}}' => $contentHtml,
            '{{USER_MENU}}' => $this->renderUserMenu(),
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
            'admin-users' => ['href' => '/backend/users', 'label' => 'Usuarios', 'roles' => ['ROLE_ADMIN']],
            'admin-api-health' => ['href' => '/backend/api-health', 'label' => 'Integración técnica', 'roles' => ['ROLE_MANAGER', 'ROLE_ADMIN']],
        ];

        $html = '';
        foreach (['dashboard', 'tenants', 'playbooks', 'admin-products'] as $key) {
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
        foreach (['admin-users'] as $key) {
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
                in_array($activeNav, ['admin-users'], true) ? ' open' : '',
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

    private function currentUser(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }

    /**
     * @return array{name: string, slug: string, businessContext: string, tone: string, positioning: string, qualificationFocus: string, handoffRules: string, salesBoundaries: string, notes: string, isActive: bool}
     */
    private function tenantFormDefaults(?Tenant $tenant = null): array
    {
        $salesPolicy = $tenant?->getSalesPolicy() ?? [];

        return [
            'name' => $tenant?->getName() ?? '',
            'slug' => $tenant?->getSlug() ?? '',
            'businessContext' => $tenant?->getBusinessContext() ?? '',
            'tone' => $tenant?->getTone() ?? '',
            'positioning' => $this->tenantPolicyValue($salesPolicy, 'positioning'),
            'qualificationFocus' => $this->tenantPolicyValue($salesPolicy, 'qualificationFocus'),
            'handoffRules' => $this->tenantPolicyValue($salesPolicy, 'handoffRules'),
            'salesBoundaries' => $this->tenantPolicyLines($salesPolicy, 'salesBoundaries'),
            'notes' => $this->tenantPolicyValue($salesPolicy, 'notes'),
            'isActive' => $tenant?->isActive() ?? true,
        ];
    }

    /**
     * @return array{name: string, slug: string, businessContext: string, tone: string, positioning: string, qualificationFocus: string, handoffRules: string, salesBoundaries: string, notes: string, isActive: bool}
     */
    private function tenantFormValuesFromRequest(Request $request): array
    {
        return [
            'name' => trim((string) $request->request->get('name', '')),
            'slug' => trim((string) $request->request->get('slug', '')),
            'businessContext' => trim((string) $request->request->get('businessContext', '')),
            'tone' => trim((string) $request->request->get('tone', '')),
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

        $content = sprintf(
            '
            <section class="hero-panel">
              <div class="hero-copy">
                <div class="eyebrow-dark">Negocios y contexto</div>
                <h2>%s</h2>
                <p>%s</p>
              </div>
              <div class="hero-aside">
                <div class="badge-live">Manager</div>
                <div class="hero-aside-title">Contexto comercial</div>
                <p>Define el negocio con su tono, contexto, política comercial y estado operativo para que el agente IA actúe con criterio.</p>
              </div>
            </section>
            <section class="table-card">
              <div class="table-header">
                <div>
                  <h3>Ficha del negocio</h3>
                  <p>Completa los campos principales y guarda el contexto comercial.</p>
                </div>
              </div>
              %s
              <form method="post" action="%s" class="tenant-form">
                <input type="hidden" name="_csrf_token" value="%s">
                <div class="form-grid">
                  <div class="field">
                    <label for="tenant-name">Nombre del negocio</label>
                    <input id="tenant-name" name="name" type="text" value="%s" maxlength="255" required>
                    <div class="field-note">Nombre visible para el equipo. Ejemplo: "Clínica Demo".</div>
                  </div>
                  <div class="field">
                    <label for="tenant-slug">Slug</label>
                    <input id="tenant-slug" name="slug" type="text" value="%s" maxlength="180" required>
                    <div class="field-note">Identificador corto, en minúsculas y sin espacios. Ejemplo: "clinica-demo".</div>
                  </div>
                  <div class="field">
                    <label for="tenant-tone">Tono</label>
                    <input id="tenant-tone" name="tone" type="text" value="%s" maxlength="120" placeholder="Cercano, profesional, directo...">
                    <div class="field-note">Cómo debe expresarse el agente en este negocio.</div>
                  </div>
                  <div class="field field-check">
                    <label for="tenant-active">Estado</label>
                    <label class="checkbox-inline" for="tenant-active">
                      <input id="tenant-active" name="isActive" type="checkbox" value="1"%s>
                      <span>Negocio activo</span>
                    </label>
                  </div>
                  <div class="field field-full">
                    <label for="tenant-context">Contexto del negocio</label>
                    <textarea id="tenant-context" name="businessContext" rows="7" maxlength="5000" placeholder="Qué vende, a quién y cómo trabaja.">%s</textarea>
                    <div class="field-note">Describe el negocio de forma simple para que el agente entienda el escenario comercial.</div>
                  </div>
                  <div class="field field-full">
                    <div class="section-label">Política comercial</div>
                    <div class="section-note">Define aquí la forma de vender, de calificar y de derivar a una persona cuando haga falta. Puedes completar solo lo que necesites.</div>
                    <div class="policy-tabs" data-policy-tabs>
                      <div class="policy-tablist" role="tablist" aria-label="Política comercial">
                        <button class="policy-tab active" type="button" role="tab" aria-selected="true" data-policy-tab="positioning">Welcome</button>
                        <button class="policy-tab" type="button" role="tab" aria-selected="false" data-policy-tab="qualification">Qualification</button>
                        <button class="policy-tab" type="button" role="tab" aria-selected="false" data-policy-tab="handoff">Handoff</button>
                        <button class="policy-tab" type="button" role="tab" aria-selected="false" data-policy-tab="boundaries">Límites</button>
                        <button class="policy-tab" type="button" role="tab" aria-selected="false" data-policy-tab="notes">Notas</button>
                      </div>
                      <div class="policy-panels">
                        <div class="policy-panel active" role="tabpanel" data-policy-panel="positioning">
                          <label for="tenant-policy-positioning">Welcome / posicionamiento</label>
                          <textarea id="tenant-policy-positioning" name="positioning" rows="4" maxlength="2000" required placeholder="Cómo se presenta este negocio y cuál es su enfoque comercial.">%s</textarea>
                          <div class="field-note">La idea principal de venta y cómo debe arrancar la conversación.</div>
                        </div>
                        <div class="policy-panel" role="tabpanel" data-policy-panel="qualification" hidden>
                          <label for="tenant-policy-qualification">Qualification / cualificación</label>
                          <textarea id="tenant-policy-qualification" name="qualificationFocus" rows="4" maxlength="2000" required placeholder="Qué datos o señales quiere descubrir el agente.">%s</textarea>
                          <div class="field-note">Lo que el agente debe averiguar para saber si hay encaje real.</div>
                        </div>
                        <div class="policy-panel" role="tabpanel" data-policy-panel="handoff" hidden>
                          <label for="tenant-policy-handoff">Handoff / derivación</label>
                          <textarea id="tenant-policy-handoff" name="handoffRules" rows="4" maxlength="2000" required placeholder="Cuándo y cómo debe pasar el caso a una persona.">%s</textarea>
                          <div class="field-note">Reglas para escalar a humano sin fricción.</div>
                        </div>
                        <div class="policy-panel" role="tabpanel" data-policy-panel="boundaries" hidden>
                          <label for="tenant-policy-boundaries">Sales boundaries / límites</label>
                          <textarea id="tenant-policy-boundaries" name="salesBoundaries" rows="4" maxlength="2000" placeholder="Una regla por línea.">%s</textarea>
                          <div class="field-note">Límites de venta, promesas prohibidas o condiciones que no se pueden saltar.</div>
                        </div>
                        <div class="policy-panel" role="tabpanel" data-policy-panel="notes" hidden>
                          <label for="tenant-policy-notes">Notas</label>
                          <textarea id="tenant-policy-notes" name="notes" rows="4" maxlength="2000" placeholder="Cualquier matiz extra para el equipo.">%s</textarea>
                          <div class="field-note">Observaciones generales, ejemplos o aclaraciones adicionales.</div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
                <div class="form-actions">
                  <button class="primary-action" type="submit">%s</button>
                </div>
              </form>
            </section>
            <script>
              (() => {
                const root = document.querySelector("[data-policy-tabs]");
                if (!root) {
                  return;
                }

                const tabs = Array.from(root.querySelectorAll("[data-policy-tab]"));
                const panels = Array.from(root.querySelectorAll("[data-policy-panel]"));

                const setActive = (key) => {
                  tabs.forEach((tab) => {
                    const isActive = tab.dataset.policyTab === key;
                    tab.classList.toggle("active", isActive);
                    tab.setAttribute("aria-selected", isActive ? "true" : "false");
                  });

                  panels.forEach((panel) => {
                    const isActive = panel.dataset.policyPanel === key;
                    panel.classList.toggle("active", isActive);
                    if (isActive) {
                      panel.removeAttribute("hidden");
                    } else {
                      panel.setAttribute("hidden", "");
                    }
                  });
                };

                tabs.forEach((tab) => {
                  tab.addEventListener("click", () => setActive(tab.dataset.policyTab));
                });
              })();
            </script>
            ',
            htmlspecialchars($heroTitle, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($pageSubtitle, ENT_QUOTES, 'UTF-8'),
            $errorHtml,
            htmlspecialchars($actionUrl, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($this->tenantTokenValue($actionUrl), ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($values['name'], ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($values['slug'], ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($values['tone'], ENT_QUOTES, 'UTF-8'),
            $values['isActive'] ? ' checked' : '',
            htmlspecialchars($values['businessContext'], ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($values['positioning'], ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($values['qualificationFocus'], ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($values['handoffRules'], ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($values['salesBoundaries'], ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($values['notes'], ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($submitLabel, ENT_QUOTES, 'UTF-8')
        );

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
        $tenant->setSalesPolicy($this->tenantSalesPolicyFromForm($values));
        $tenant->setActive($values['isActive']);
    }

    /**
     * @param array{name: string, slug: string, businessContext: string, tone: string, positioning: string, qualificationFocus: string, handoffRules: string, salesBoundaries: string, notes: string, isActive: bool} $values
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

        $content = sprintf(
            '
            <section class="hero-panel">
              <div class="hero-copy">
                <div class="eyebrow-dark">Guías comerciales</div>
                <h2>%s</h2>
                <p>%s</p>
              </div>
              <div class="hero-aside">
                <div class="badge-live">Manager</div>
                <div class="hero-aside-title">Playbook</div>
                <p>Organiza la estrategia comercial por negocio o por producto, con scoring, reglas y derivación a humano.</p>
              </div>
            </section>
            <section class="table-card">
              <div class="table-header">
                <div>
                  <h3>Ficha de la guía comercial</h3>
                  <p>Selecciona el negocio y completa el playbook con un enfoque humano.</p>
                </div>
              </div>
              %s
              <form method="post" action="%s" class="tenant-form">
                <input type="hidden" name="_csrf_token" value="%s">
                <div class="form-grid">
                  <div class="field">
                    <label for="playbook-tenant">Negocio</label>
                    <select id="playbook-tenant" name="tenantId" required>%s</select>
                    <div class="field-note">El playbook vive dentro de un negocio concreto.</div>
                  </div>
                  <div class="field">
                    <label for="playbook-product">Producto / servicio</label>
                    <select id="playbook-product" name="productId">%s</select>
                    <div class="field-note">Opcional: vincúlalo a un producto específico si aplica.</div>
                  </div>
                  <div class="field field-full">
                    <label for="playbook-name">Nombre de la guía comercial</label>
                    <input id="playbook-name" name="name" type="text" value="%s" maxlength="255" required>
                    <div class="field-note">Nombre visible para distinguir la estrategia comercial.</div>
                  </div>
                  <div class="field field-check">
                    <label for="playbook-active">Estado</label>
                    <label class="checkbox-inline" for="playbook-active">
                      <input id="playbook-active" name="isActive" type="checkbox" value="1"%s>
                      <span>Guía activa</span>
                    </label>
                  </div>
                  <div class="field field-full">
                    <div class="section-label">Contenido de la guía</div>
                    <div class="section-note">Trabaja por pestañas para dejar clara la intención comercial, la cualificación y las reglas de handoff.</div>
                    <div class="policy-tabs" data-policy-tabs>
                      <div class="policy-tablist" role="tablist" aria-label="Guía comercial">
                        <button class="policy-tab active" type="button" role="tab" aria-selected="true" data-policy-tab="objective">Objetivo</button>
                        <button class="policy-tab" type="button" role="tab" aria-selected="false" data-policy-tab="qualification">Cualificación</button>
                        <button class="policy-tab" type="button" role="tab" aria-selected="false" data-policy-tab="scoring">Scoring</button>
                        <button class="policy-tab" type="button" role="tab" aria-selected="false" data-policy-tab="agenda">Agenda</button>
                        <button class="policy-tab" type="button" role="tab" aria-selected="false" data-policy-tab="handoff">Handoff</button>
                        <button class="policy-tab" type="button" role="tab" aria-selected="false" data-policy-tab="actions">Acciones</button>
                        <button class="policy-tab" type="button" role="tab" aria-selected="false" data-policy-tab="notes">Notas</button>
                      </div>
                      <div class="policy-panels">
                        <div class="policy-panel active" role="tabpanel" data-policy-panel="objective">
                          <label for="playbook-objective">Objetivo</label>
                          <textarea id="playbook-objective" name="objective" rows="4" maxlength="2000" required placeholder="Qué persigue la guía comercial.">%s</textarea>
                          <div class="field-note">Qué debe conseguir el agente cuando este playbook está activo.</div>
                        </div>
                        <div class="policy-panel" role="tabpanel" data-policy-panel="qualification" hidden>
                          <label for="playbook-qualification-questions">Preguntas de cualificación</label>
                          <textarea id="playbook-qualification-questions" name="qualificationQuestions" rows="4" maxlength="4000" required placeholder="Una pregunta por línea.">%s</textarea>
                          <div class="field-note">Escribe las preguntas que el agente debe usar para cualificar.</div>
                        </div>
                        <div class="policy-panel" role="tabpanel" data-policy-panel="scoring" hidden>
                          <div class="form-grid">
                            <div class="field">
                              <label for="playbook-max-score">Puntuación máxima</label>
                              <input id="playbook-max-score" name="maxScore" type="number" min="1" step="1" value="%s" required>
                            </div>
                            <div class="field">
                              <label for="playbook-handoff-threshold">Umbral de handoff</label>
                              <input id="playbook-handoff-threshold" name="handoffThreshold" type="number" min="0" step="1" value="%s" required>
                            </div>
                            <div class="field">
                              <label for="playbook-positive-signals">Señales positivas</label>
                              <textarea id="playbook-positive-signals" name="positiveSignals" rows="4" maxlength="4000" placeholder="Una señal por línea.">%s</textarea>
                            </div>
                            <div class="field">
                              <label for="playbook-negative-signals">Señales negativas</label>
                              <textarea id="playbook-negative-signals" name="negativeSignals" rows="4" maxlength="4000" placeholder="Una señal por línea.">%s</textarea>
                            </div>
                          </div>
                          <div class="field-note">Scoring y señales para decidir cuándo avanzar y cuándo derivar.</div>
                        </div>
                        <div class="policy-panel" role="tabpanel" data-policy-panel="agenda" hidden>
                          <label for="playbook-agenda-rules">Reglas de agenda</label>
                          <textarea id="playbook-agenda-rules" name="agendaRules" rows="4" maxlength="4000" placeholder="Una regla por línea.">%s</textarea>
                          <div class="field-note">Define cuándo el agente debe proponer agenda, llamada o siguiente paso.</div>
                        </div>
                        <div class="policy-panel" role="tabpanel" data-policy-panel="handoff" hidden>
                          <label for="playbook-handoff-rules">Reglas de handoff</label>
                          <textarea id="playbook-handoff-rules" name="handoffRules" rows="4" maxlength="4000" required placeholder="Una regla por línea.">%s</textarea>
                          <div class="field-note">Cuándo el caso pasa a una persona y qué debe indicar el agente.</div>
                        </div>
                        <div class="policy-panel" role="tabpanel" data-policy-panel="actions" hidden>
                          <label for="playbook-allowed-actions">Acciones permitidas</label>
                          <textarea id="playbook-allowed-actions" name="allowedActions" rows="4" maxlength="4000" required placeholder="Una acción por línea.">%s</textarea>
                          <div class="field-note">Acciones que este playbook permite al runtime comercial.</div>
                        </div>
                        <div class="policy-panel" role="tabpanel" data-policy-panel="notes" hidden>
                          <label for="playbook-notes">Notas</label>
                          <textarea id="playbook-notes" name="notes" rows="4" maxlength="4000" placeholder="Aclaraciones adicionales.">%s</textarea>
                          <div class="field-note">Aterriza matices o excepciones que el equipo deba conocer.</div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
                <div class="form-actions">
                  <button class="primary-action" type="submit">%s</button>
                </div>
              </form>
            </section>
            <script>
              (() => {
                const root = document.querySelector("[data-policy-tabs]");
                if (!root) {
                  return;
                }

                const tabs = Array.from(root.querySelectorAll("[data-policy-tab]"));
                const panels = Array.from(root.querySelectorAll("[data-policy-panel]"));

                const setActive = (key) => {
                  tabs.forEach((tab) => {
                    const isActive = tab.dataset.policyTab === key;
                    tab.classList.toggle("active", isActive);
                    tab.setAttribute("aria-selected", isActive ? "true" : "false");
                  });

                  panels.forEach((panel) => {
                    const isActive = panel.dataset.policyPanel === key;
                    panel.classList.toggle("active", isActive);
                    if (isActive) {
                      panel.removeAttribute("hidden");
                    } else {
                      panel.setAttribute("hidden", "");
                    }
                  });
                };

                tabs.forEach((tab) => {
                  tab.addEventListener("click", () => setActive(tab.dataset.policyTab));
                });
              })();
            </script>
            ',
            htmlspecialchars($heroTitle, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($pageSubtitle, ENT_QUOTES, 'UTF-8'),
            $errorHtml,
            htmlspecialchars($actionUrl, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($this->playbookTokenValue($actionUrl), ENT_QUOTES, 'UTF-8'),
            $tenantOptions,
            $productOptions,
            htmlspecialchars($values['name'], ENT_QUOTES, 'UTF-8'),
            $values['isActive'] ? ' checked' : '',
            htmlspecialchars($values['objective'], ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($values['qualificationQuestions'], ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($values['maxScore'], ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($values['handoffThreshold'], ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($values['positiveSignals'], ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($values['negativeSignals'], ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($values['agendaRules'], ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($values['handoffRules'], ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($values['allowedActions'], ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($values['notes'], ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($submitLabel, ENT_QUOTES, 'UTF-8')
        );

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
     * @return array{tenantId: string, name: string, description: string, valueProposition: string, positioning: string, pricingNotes: string, objections: string, handoffRules: string, notes: string, isActive: bool}
     */
    private function productFormDefaults(?Product $product = null): array
    {
        $salesPolicy = $product?->getSalesPolicy() ?? [];

        return [
            'tenantId' => $product?->getTenant()->getId()->toRfc4122() ?? '',
            'name' => $product?->getName() ?? '',
            'description' => $product?->getDescription() ?? '',
            'valueProposition' => $product?->getValueProposition() ?? '',
            'positioning' => $this->productPolicyValue($salesPolicy, 'positioning'),
            'pricingNotes' => $this->productPolicyValue($salesPolicy, 'pricingNotes'),
            'objections' => $this->productPolicyLines($salesPolicy, 'objections'),
            'handoffRules' => $this->productPolicyValue($salesPolicy, 'handoffRules'),
            'notes' => $this->productPolicyValue($salesPolicy, 'notes'),
            'isActive' => $product?->isActive() ?? true,
        ];
    }

    /**
     * @return array{tenantId: string, name: string, description: string, valueProposition: string, positioning: string, pricingNotes: string, objections: string, handoffRules: string, notes: string, isActive: bool}
     */
    private function productFormValuesFromRequest(Request $request): array
    {
        return [
            'tenantId' => trim((string) $request->request->get('tenantId', '')),
            'name' => trim((string) $request->request->get('name', '')),
            'description' => trim((string) $request->request->get('description', '')),
            'valueProposition' => trim((string) $request->request->get('valueProposition', '')),
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

        $content = sprintf(
            '
            <section class="hero-panel">
              <div class="hero-copy">
                <div class="eyebrow-dark">Productos / servicios</div>
                <h2>%s</h2>
                <p>%s</p>
              </div>
              <div class="hero-aside">
                <div class="badge-live">Manager</div>
                <div class="hero-aside-title">Oferta comercial</div>
                <p>Ordena cada producto o servicio con su mensaje, precio orientativo, objeciones y límites de venta.</p>
              </div>
            </section>
            <section class="table-card">
              <div class="table-header">
                <div>
                  <h3>Ficha del producto / servicio</h3>
                  <p>Define la oferta asociada al negocio y cómo debe venderla el agente.</p>
                </div>
              </div>
              %s
              <form method="post" action="%s" class="tenant-form">
                <input type="hidden" name="_csrf_token" value="%s">
                <div class="form-grid">
                  <div class="field">
                    <label for="product-tenant">Negocio</label>
                    <select id="product-tenant" name="tenantId" required>%s</select>
                    <div class="field-note">Cada producto pertenece a un negocio concreto.</div>
                  </div>
                  <div class="field">
                    <label for="product-name">Nombre del producto / servicio</label>
                    <input id="product-name" name="name" type="text" value="%s" maxlength="255" required>
                    <div class="field-note">Nombre visible para el catálogo comercial.</div>
                  </div>
                  <div class="field field-check">
                    <label for="product-active">Estado</label>
                    <label class="checkbox-inline" for="product-active">
                      <input id="product-active" name="isActive" type="checkbox" value="1"%s>
                      <span>Producto activo</span>
                    </label>
                  </div>
                  <div class="field field-full">
                    <label for="product-description">Descripción</label>
                    <textarea id="product-description" name="description" rows="4" maxlength="5000" placeholder="Qué es, a quién va dirigido y qué resuelve.">%s</textarea>
                    <div class="field-note">Explica el producto o servicio de forma clara y comercial.</div>
                  </div>
                  <div class="field field-full">
                    <label for="product-value">Propuesta de valor</label>
                    <textarea id="product-value" name="valueProposition" rows="4" maxlength="5000" placeholder="Por qué debería comprarlo.">%s</textarea>
                    <div class="field-note">Resumen de la promesa comercial y la diferencia respecto a otras opciones.</div>
                  </div>
                  <div class="field field-full">
                    <div class="section-label">Política comercial</div>
                    <div class="section-note">La política del producto se organiza en pestañas para facilitar la edición y mantener el criterio comercial visible.</div>
                    <div class="policy-tabs" data-policy-tabs>
                      <div class="policy-tablist" role="tablist" aria-label="Política de producto">
                        <button class="policy-tab active" type="button" role="tab" aria-selected="true" data-policy-tab="positioning">Welcome</button>
                        <button class="policy-tab" type="button" role="tab" aria-selected="false" data-policy-tab="pricing">Pricing</button>
                        <button class="policy-tab" type="button" role="tab" aria-selected="false" data-policy-tab="objections">Objeciones</button>
                        <button class="policy-tab" type="button" role="tab" aria-selected="false" data-policy-tab="handoff">Handoff</button>
                        <button class="policy-tab" type="button" role="tab" aria-selected="false" data-policy-tab="notes">Notas</button>
                      </div>
                      <div class="policy-panels">
                        <div class="policy-panel active" role="tabpanel" data-policy-panel="positioning">
                          <label for="product-positioning">Welcome / posicionamiento</label>
                          <textarea id="product-positioning" name="positioning" rows="4" maxlength="2000" required placeholder="Cómo se presenta este producto o servicio.">%s</textarea>
                          <div class="field-note">Cómo debe arrancar la conversación comercial sobre esta oferta.</div>
                        </div>
                        <div class="policy-panel" role="tabpanel" data-policy-panel="pricing" hidden>
                          <label for="product-pricing">Pricing notes</label>
                          <textarea id="product-pricing" name="pricingNotes" rows="4" maxlength="2000" placeholder="Notas de precio, rangos o condiciones.">%s</textarea>
                          <div class="field-note">Aclara precios, condiciones o matices que el agente deba tener presentes.</div>
                        </div>
                        <div class="policy-panel" role="tabpanel" data-policy-panel="objections" hidden>
                          <label for="product-objections">Objeciones</label>
                          <textarea id="product-objections" name="objections" rows="4" maxlength="4000" placeholder="Una objeción por línea.">%s</textarea>
                          <div class="field-note">Respuestas o señales para gestionar dudas comunes del cliente.</div>
                        </div>
                        <div class="policy-panel" role="tabpanel" data-policy-panel="handoff" hidden>
                          <label for="product-handoff">Handoff / derivación</label>
                          <textarea id="product-handoff" name="handoffRules" rows="4" maxlength="2000" placeholder="Cuándo derivar a una persona.">%s</textarea>
                          <div class="field-note">Cuándo el agente debe pasar la conversación a humano.</div>
                        </div>
                        <div class="policy-panel" role="tabpanel" data-policy-panel="notes" hidden>
                          <label for="product-notes">Notas</label>
                          <textarea id="product-notes" name="notes" rows="4" maxlength="2000" placeholder="Matices adicionales.">%s</textarea>
                          <div class="field-note">Observaciones generales para el equipo comercial.</div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
                <div class="form-actions">
                  <button class="primary-action" type="submit">%s</button>
                </div>
              </form>
            </section>
            <script>
              (() => {
                const root = document.querySelector("[data-policy-tabs]");
                if (!root) {
                  return;
                }

                const tabs = Array.from(root.querySelectorAll("[data-policy-tab]"));
                const panels = Array.from(root.querySelectorAll("[data-policy-panel]"));

                const setActive = (key) => {
                  tabs.forEach((tab) => {
                    const isActive = tab.dataset.policyTab === key;
                    tab.classList.toggle("active", isActive);
                    tab.setAttribute("aria-selected", isActive ? "true" : "false");
                  });

                  panels.forEach((panel) => {
                    const isActive = panel.dataset.policyPanel === key;
                    panel.classList.toggle("active", isActive);
                    if (isActive) {
                      panel.removeAttribute("hidden");
                    } else {
                      panel.setAttribute("hidden", "");
                    }
                  });
                };

                tabs.forEach((tab) => {
                  tab.addEventListener("click", () => setActive(tab.dataset.policyTab));
                });
              })();
            </script>
            ',
            htmlspecialchars($heroTitle, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($pageSubtitle, ENT_QUOTES, 'UTF-8'),
            $errorHtml,
            htmlspecialchars($actionUrl, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($this->productTokenValue($actionUrl), ENT_QUOTES, 'UTF-8'),
            $tenantOptions,
            htmlspecialchars($values['name'], ENT_QUOTES, 'UTF-8'),
            $values['isActive'] ? ' checked' : '',
            htmlspecialchars($values['description'], ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($values['valueProposition'], ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($values['positioning'], ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($values['pricingNotes'], ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($values['objections'], ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($values['handoffRules'], ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($values['notes'], ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($submitLabel, ENT_QUOTES, 'UTF-8')
        );

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
        $product->setDescription($values['description']);
        $product->setValueProposition($values['valueProposition']);
        $product->setSalesPolicy($this->productPolicyFromForm($values));
        $product->setActive($values['isActive']);
    }

    /**
     * @param array{tenantId: string, name: string, description: string, valueProposition: string, positioning: string, pricingNotes: string, objections: string, handoffRules: string, notes: string, isActive: bool} $values
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
                $options[] = sprintf(
                    '<option value="%s"%s>%s · %s</option>',
                    htmlspecialchars($product->getId()->toRfc4122(), ENT_QUOTES, 'UTF-8'),
                    $product->getId()->toRfc4122() === $selectedId ? ' selected' : '',
                    htmlspecialchars($product->getTenant()->getName(), ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars($product->getName(), ENT_QUOTES, 'UTF-8')
                );
            }
        }

        return implode('', $options);
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
            $html .= sprintf(
                '<div class="alert alert-success">%s</div>',
                htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8')
            );
        }
        foreach ($errors as $message) {
            $html .= sprintf(
                '<div class="alert alert-error">%s</div>',
                htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8')
            );
        }

        return $html;
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
        return sprintf(
            '<article class="metric"><div class="metric-label">%s</div><div class="metric-value">%s</div><div class="metric-note">%s</div></article>',
            htmlspecialchars($label, ENT_QUOTES, 'UTF-8'),
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
