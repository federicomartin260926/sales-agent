<?php

namespace App\Controller\Web;

use App\Entity\Playbook;
use App\Entity\Product;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\PlaybookRepository;
use App\Repository\ProductRepository;
use App\Repository\TenantRepository;
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
        if (!$this->security->isGranted('ROLE_MANAGER')) {
            return new RedirectResponse('/backend/login');
        }

        $tenantCount = $tenants ? count($tenants->findAllOrdered()) : 0;
        $userCount = $users ? count($users->findBy([], ['createdAt' => 'DESC'])) : 0;
        $playbookCount = $playbooks ? count($playbooks->findAllOrdered()) : 0;
        $productCount = $products ? count($products->findAllOrdered()) : 0;

        $content = sprintf(
            '
            <section class="hero-panel hero-panel-single">
              <div class="hero-copy">
                <div class="eyebrow-dark">Operación comercial</div>
                <h2>Panel comercial de negocios</h2>
                <p>
                  Gestiona negocios, productos/servicios y usuarios desde un mismo lugar. Aquí defines cómo se comporta el
                  agente IA por negocio o producto: su conocimiento, su tono y el enfoque comercial que aplica.
                </p>
                <div class="hero-actions">
                  <a class="primary-action" href="/backend/tenants">Ver negocios</a>
                  <a class="secondary-action" href="/backend/users">Revisar usuarios</a>
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
              %s
              %s
            </section>
            ',
            $this->metricCard('Negocios', (string) $tenantCount, 'Contextos comerciales listos'),
            $this->metricCard('Usuarios', (string) $userCount, 'Cuentas de administración'),
            $this->metricCard('Guías comerciales', (string) $playbookCount, 'Cualificación, scoring y handoff'),
            $this->metricCard('Productos / servicios', (string) $productCount, 'Catálogo comercial base'),
            $this->infoCard(
                'Usuarios',
                'Cuentas que administran negocios, productos/servicios y acceso interno.',
                '/backend/users',
                'Gestionar'
            ),
            $this->infoCard(
                'Guías comerciales',
                'Ajustes del agente para cada negocio o producto: enfoque, tono, scoring y reglas.',
                '/backend/playbooks',
                'Abrir'
            ),
            $this->infoCard(
                'Negocios',
                'Cada negocio agrupa su contexto, usuarios y reglas del agente.',
                '/backend/tenants',
                'Abrir'
            ),
            $this->infoCard(
                'Productos / servicios',
                'Propuestas comerciales asociadas al trabajo de cada negocio.',
                '/backend/products',
                'Abrir'
            ),
            $this->infoCard(
                'Integración técnica',
                'La API sigue disponible para automatizaciones y servicios internos.',
                '/backend/api/health',
                'Health check'
            ),
            $this->infoCard(
                'Estado',
                'Panel comercial activo y listo para operar.',
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

            return sprintf(
                '<tr>
                    <td><strong>%s</strong><div class="subtle">%s</div></td>
                    <td>%s</td>
                    <td>%s</td>
                    <td>%s</td>
                  </tr>',
                htmlspecialchars($playbook->getName(), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($playbook->getConfigSummary(), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($tenant->getName(), ENT_QUOTES, 'UTF-8'),
                $product ? htmlspecialchars($product->getName(), ENT_QUOTES, 'UTF-8') : 'Sin producto',
                $status
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
                <a class="secondary-action" href="/backend/dashboard">Volver al dashboard</a>
              </div>
              <div class="table-responsive">
                <table>
                  <thead>
                    <tr><th>Guía comercial</th><th>Negocio</th><th>Producto / servicio</th><th>Estado</th></tr>
                  </thead>
                  <tbody>%s</tbody>
                </table>
              </div>
            </section>
            ',
            $rows !== [] ? implode('', $rows) : '<tr><td colspan="4" class="empty-row">No hay guías comerciales todavía.</td></tr>'
        );

        return $this->renderBackendShell('Guías comerciales', 'Ajustes del agente por negocio o producto.', 'playbooks', $content);
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

            return sprintf(
                '<tr>
                    <td><strong>%s</strong><div class="subtle">%s</div></td>
                    <td><code>%s</code></td>
                    <td>%s</td>
                    <td>%s</td>
                  </tr>',
                htmlspecialchars($tenant->getName(), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($tenant->getBusinessContext(), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($tenant->getSlug(), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($policySummary, ENT_QUOTES, 'UTF-8'),
                $status
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
                <a class="secondary-action" href="/backend/dashboard">Volver al dashboard</a>
              </div>
              <div class="table-responsive">
                <table>
                  <thead>
                    <tr><th>Negocio</th><th>Slug</th><th>Política comercial</th><th>Estado</th></tr>
                  </thead>
                  <tbody>%s</tbody>
                </table>
              </div>
            </section>
            ',
            $rows !== [] ? implode('', $rows) : '<tr><td colspan="4" class="empty-row">No hay negocios todavía.</td></tr>'
        );

        return $this->renderBackendShell('Negocios', 'Negocios y contextos operativos.', 'tenants', $content);
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

            return sprintf(
                '<tr>
                    <td><strong>%s</strong><div class="subtle">%s</div></td>
                    <td>%s</td>
                    <td>%s</td>
                    <td>%s</td>
                  </tr>',
                htmlspecialchars($product->getName(), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($product->getDescription(), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($product->getTenant()->getName(), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($product->getValueProposition(), ENT_QUOTES, 'UTF-8'),
                $status
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
                <a class="secondary-action" href="/backend/dashboard">Volver al dashboard</a>
              </div>
              <div class="table-responsive">
                <table>
                  <thead>
                    <tr><th>Producto / servicio</th><th>Negocio</th><th>Propuesta de valor</th><th>Estado</th></tr>
                  </thead>
                  <tbody>%s</tbody>
                </table>
              </div>
            </section>
            ',
            $rows !== [] ? implode('', $rows) : '<tr><td colspan="4" class="empty-row">No hay productos o servicios todavía.</td></tr>'
        );

        return $this->renderBackendShell('Productos / servicios', 'Catálogo comercial por negocio.', 'products', $content);
    }

    #[Route('/profile', methods: ['GET'])]
    public function profile(Request $request): Response
    {
        if (!$this->security->isGranted('ROLE_MANAGER')) {
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
        if (!$this->security->isGranted('ROLE_MANAGER')) {
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
        if (!$this->security->isGranted('ROLE_MANAGER')) {
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
      .profile-grid {
        grid-template-columns: 1fr;
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
            'dashboard' => ['href' => '/backend/dashboard', 'label' => 'Resumen', 'roles' => ['ROLE_AGENT', 'ROLE_MANAGER', 'ROLE_ADMIN']],
            'playbooks' => ['href' => '/backend/playbooks', 'label' => 'Guías comerciales', 'roles' => ['ROLE_MANAGER', 'ROLE_ADMIN']],
            'tenants' => ['href' => '/backend/tenants', 'label' => 'Negocios', 'roles' => ['ROLE_MANAGER', 'ROLE_ADMIN']],
            'admin-users' => ['href' => '/backend/users', 'label' => 'Usuarios', 'roles' => ['ROLE_ADMIN']],
            'admin-products' => ['href' => '/backend/products', 'label' => 'Productos / servicios', 'roles' => ['ROLE_MANAGER', 'ROLE_ADMIN']],
            'admin-api-health' => ['href' => '/backend/api-health', 'label' => 'Integración técnica', 'roles' => ['ROLE_MANAGER', 'ROLE_ADMIN']],
        ];

        $html = '';
        foreach (['dashboard', 'playbooks', 'tenants'] as $key) {
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
        foreach (['admin-users', 'admin-products', 'admin-api-health'] as $key) {
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
                in_array($activeNav, ['admin-users', 'admin-products', 'admin-api-health'], true) ? ' open' : '',
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
