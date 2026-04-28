<?php

namespace App\Controller\Web;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class BackendUiController
{
    public function __construct(
        private readonly Security $security,
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
      --bg: #08111f;
      --panel: rgba(10, 19, 34, 0.92);
      --panel-border: rgba(255, 255, 255, 0.08);
      --text: #e5eefc;
      --muted: #8ea4c1;
      --accent: #7dd3fc;
      --accent-strong: #38bdf8;
      --danger: #fca5a5;
      --shadow: 0 30px 90px rgba(0, 0, 0, 0.35);
      --radius: 24px;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      min-height: 100vh;
      font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      color: var(--text);
      background:
        radial-gradient(circle at top left, rgba(56, 189, 248, 0.24), transparent 34%),
        radial-gradient(circle at top right, rgba(99, 102, 241, 0.22), transparent 30%),
        linear-gradient(160deg, #020617 0%, #0f172a 48%, #111827 100%);
      display: grid;
      place-items: center;
      padding: 32px 18px;
    }
    .shell {
      width: min(1080px, 100%);
      display: grid;
      grid-template-columns: 1.1fr 0.9fr;
      gap: 24px;
      align-items: stretch;
    }
    .hero, .card {
      border: 1px solid var(--panel-border);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      backdrop-filter: blur(16px);
    }
    .hero {
      padding: 34px;
      background: linear-gradient(180deg, rgba(15, 23, 42, 0.84), rgba(2, 6, 23, 0.82));
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      min-height: 560px;
    }
    .eyebrow {
      display: inline-flex;
      align-items: center;
      gap: 10px;
      color: var(--accent);
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
      background: var(--accent-strong);
      box-shadow: 0 0 0 6px rgba(56, 189, 248, 0.14);
    }
    h1 {
      margin: 18px 0 14px;
      font-size: clamp(36px, 5vw, 58px);
      line-height: 0.95;
      letter-spacing: -0.06em;
    }
    p {
      margin: 0;
      color: var(--muted);
      font-size: 16px;
      line-height: 1.7;
      max-width: 58ch;
    }
    .stack {
      display: grid;
      gap: 14px;
      margin-top: 28px;
    }
    .pill-row {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      margin-top: 26px;
    }
    .pill {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 10px 14px;
      border-radius: 999px;
      background: rgba(148, 163, 184, 0.09);
      border: 1px solid rgba(148, 163, 184, 0.14);
      color: #dbeafe;
      font-size: 13px;
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
      background: rgba(248, 113, 113, 0.12);
      border: 1px solid rgba(248, 113, 113, 0.28);
      color: #fecaca;
    }
    label {
      display: block;
      margin-bottom: 8px;
      font-size: 14px;
      color: #dbeafe;
      font-weight: 600;
    }
    input {
      width: 100%;
      border-radius: 14px;
      border: 1px solid rgba(148, 163, 184, 0.24);
      background: rgba(15, 23, 42, 0.82);
      color: var(--text);
      padding: 14px 16px;
      font-size: 15px;
      outline: none;
      transition: border-color 120ms ease, box-shadow 120ms ease;
    }
    input:focus {
      border-color: rgba(56, 189, 248, 0.65);
      box-shadow: 0 0 0 4px rgba(56, 189, 248, 0.12);
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
      background: linear-gradient(135deg, var(--accent-strong), #22c55e);
      color: #02111f;
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
      color: #dbeafe;
    }
    @media (max-width: 960px) {
      .shell {
        grid-template-columns: 1fr;
      }
      .hero {
        min-height: auto;
      }
    }
  </style>
</head>
<body>
  <main class="shell">
    <section class="hero">
      <div>
        <div class="eyebrow">Sales Agent Backend</div>
        <h1>Acceso administrativo para proyectos, usuarios y playbooks.</h1>
        <p>
          Este panel es la entrada humana al backend Symfony. Se separa del API JSON y del servicio FastAPI
          para evitar confusión entre navegación de administrador e integración técnica.
        </p>
        <div class="pill-row">
          <span class="pill">Panel humano</span>
          <span class="pill">Sesión de navegador</span>
          <span class="pill">Backend Symfony</span>
        </div>
      </div>
      <div class="stack">
        <div class="pill">Ruta externa: <code>/backend/login</code></div>
        <div class="pill">Ruta del dashboard: <code>/backend/dashboard</code></div>
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
      <div class="footer">
        Credenciales iniciales:
        <br>
        <code>federicomartin2609@gmail.com</code>
        <br>
        <code>1234</code>
      </div>
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

    #[Route('/dashboard', methods: ['GET'])]
    public function dashboard(): Response
    {
        if (!$this->security->getUser() instanceof UserInterface) {
            return new RedirectResponse('/backend/login');
        }

        $html = <<<'HTML'
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Sales Agent Backend - Dashboard</title>
  <style>
    :root {
      --bg: #08111f;
      --sidebar: rgba(6, 11, 20, 0.96);
      --panel: rgba(15, 23, 42, 0.86);
      --panel-strong: rgba(12, 18, 31, 0.95);
      --border: rgba(148, 163, 184, 0.16);
      --border-strong: rgba(148, 163, 184, 0.24);
      --text: #e5eefc;
      --muted: #8ea4c1;
      --accent: #38bdf8;
      --accent-2: #22c55e;
      --warn: #f59e0b;
      --danger: #ef4444;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      min-height: 100vh;
      font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      background:
        radial-gradient(circle at top left, rgba(56, 189, 248, 0.16), transparent 28%),
        radial-gradient(circle at bottom right, rgba(34, 197, 94, 0.14), transparent 24%),
        linear-gradient(160deg, #020617 0%, #0f172a 55%, #111827 100%);
      color: var(--text);
    }
    a { color: inherit; }
    .shell {
      min-height: 100vh;
      display: grid;
      grid-template-columns: 280px minmax(0, 1fr);
    }
    .sidebar {
      background: linear-gradient(180deg, rgba(6, 11, 20, 0.98), rgba(8, 17, 31, 0.96));
      border-right: 1px solid var(--border);
      padding: 26px 20px;
      display: flex;
      flex-direction: column;
      gap: 24px;
    }
    .brand {
      display: flex;
      flex-direction: column;
      gap: 6px;
      padding-bottom: 18px;
      border-bottom: 1px solid var(--border);
    }
    .brand-name {
      font-size: 20px;
      font-weight: 800;
      letter-spacing: -0.04em;
    }
    .brand-sub {
      color: var(--muted);
      font-size: 13px;
      line-height: 1.5;
    }
    .nav {
      display: grid;
      gap: 8px;
    }
    .nav a, .nav .item {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 12px 14px;
      border-radius: 14px;
      border: 1px solid transparent;
      text-decoration: none;
      color: #dbeafe;
      background: rgba(148, 163, 184, 0.06);
    }
    .nav a.active {
      background: rgba(56, 189, 248, 0.14);
      border-color: rgba(56, 189, 248, 0.24);
    }
    .nav small {
      color: var(--muted);
      font-size: 12px;
    }
    .sidebar-footer {
      margin-top: auto;
      display: grid;
      gap: 10px;
    }
    .logout {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      padding: 12px 16px;
      border-radius: 14px;
      text-decoration: none;
      background: linear-gradient(135deg, #ef4444, #f97316);
      color: white;
      font-weight: 800;
    }
    .content {
      padding: 28px;
    }
    .topbar {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 18px;
      margin-bottom: 24px;
    }
    .title {
      margin: 0;
      font-size: clamp(30px, 4vw, 46px);
      letter-spacing: -0.06em;
    }
    .muted { color: var(--muted); }
    .hero-strip {
      display: grid;
      grid-template-columns: 1.4fr 0.6fr;
      gap: 16px;
      margin-bottom: 18px;
    }
    .panel {
      background: var(--panel);
      border: 1px solid var(--border);
      border-radius: 22px;
      padding: 20px;
      box-shadow: 0 24px 80px rgba(0, 0, 0, 0.24);
    }
    .stats {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 16px;
      margin-bottom: 18px;
    }
    .stat {
      background: linear-gradient(180deg, rgba(15, 23, 42, 0.94), rgba(11, 18, 32, 0.88));
      border: 1px solid var(--border-strong);
      border-radius: 22px;
      padding: 18px;
    }
    .stat-label {
      color: var(--muted);
      font-size: 13px;
      margin-bottom: 10px;
    }
    .stat-value {
      font-size: 28px;
      font-weight: 800;
      letter-spacing: -0.05em;
    }
    .stat-note {
      margin-top: 8px;
      color: #cbd5e1;
      font-size: 13px;
    }
    .grid {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 16px;
    }
    .card {
      background: var(--panel);
      border: 1px solid var(--border);
      border-radius: 22px;
      padding: 22px;
      min-height: 170px;
    }
    .card h2 {
      margin: 0 0 8px;
      font-size: 18px;
    }
    .card p {
      margin: 0;
      color: var(--muted);
      line-height: 1.6;
    }
    .links {
      display: grid;
      gap: 10px;
      margin-top: 14px;
    }
    a.btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      padding: 12px 14px;
      border-radius: 14px;
      text-decoration: none;
      background: rgba(56, 189, 248, 0.12);
      border: 1px solid rgba(56, 189, 248, 0.22);
      color: #dbeafe;
      font-weight: 700;
    }
    .section-title {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin: 16px 0 12px;
    }
    .section-title h2 {
      margin: 0;
      font-size: 18px;
      letter-spacing: -0.03em;
    }
    .badge {
      padding: 6px 10px;
      border-radius: 999px;
      background: rgba(34, 197, 94, 0.12);
      border: 1px solid rgba(34, 197, 94, 0.22);
      color: #bbf7d0;
      font-size: 12px;
      font-weight: 700;
    }
    @media (max-width: 1100px) {
      .shell {
        grid-template-columns: 1fr;
      }
      .sidebar {
        border-right: 0;
        border-bottom: 1px solid var(--border);
      }
      .hero-strip, .stats, .grid {
        grid-template-columns: 1fr;
      }
    }
  </style>
</head>
<body>
  <main class="shell">
    <aside class="sidebar">
      <div class="brand">
        <div class="brand-name">Sales Agent CRM</div>
        <div class="brand-sub">Backend Symfony para configuración y gestión interna.</div>
      </div>
      <nav class="nav">
        <a class="active" href="/backend/dashboard"><span>Dashboard</span><small>Resumen</small></a>
        <a href="/backend/api/playbooks"><span>Playbooks</span><small>Flujos</small></a>
        <a href="/backend/api/tenants"><span>Tenants</span><small>Proyectos</small></a>
        <a href="/backend/api/products"><span>Usuarios / Productos</span><small>Catálogo</small></a>
        <a href="/backend/api/health"><span>API Health</span><small>Status</small></a>
      </nav>
      <div class="sidebar-footer">
        <div class="panel">
          <div class="muted" style="font-size:12px; text-transform:uppercase; letter-spacing:0.14em;">Sesión</div>
          <div style="margin-top:10px; font-size:14px; line-height:1.5;">
            Sesión de navegador activa para gestión humana.
          </div>
        </div>
        <a class="logout" href="/backend/logout">Cerrar sesión</a>
      </div>
    </aside>

    <section class="content">
      <div class="topbar">
        <div>
          <h1 class="title">Backend administrativo</h1>
          <div class="muted">Sales Agent Symfony panel para gestión interna.</div>
        </div>
      </div>

      <div class="hero-strip">
        <div class="panel">
          <div class="section-title">
            <h2>Operación CRM</h2>
            <span class="badge">Online</span>
          </div>
          <p>
            Desde aquí se crean tenants, usuarios, playbooks y la configuración que consume el runtime del agente.
            La API técnica queda separada y el tráfico de WhatsApp entra por `wa-gateway-api`.
          </p>
        </div>
        <div class="panel">
          <div class="section-title">
            <h2>Acceso</h2>
            <span class="badge">Admin</span>
          </div>
          <p>
            Este panel usa sesión de navegador. Los sistemas entre servicios usan bearer token.
          </p>
        </div>
      </div>

      <div class="stats">
        <article class="stat">
          <div class="stat-label">Tenants</div>
          <div class="stat-value">1</div>
          <div class="stat-note">Arranque inicial listo</div>
        </article>
        <article class="stat">
          <div class="stat-label">Usuarios</div>
          <div class="stat-value">1</div>
          <div class="stat-note">Admin bootstrap</div>
        </article>
        <article class="stat">
          <div class="stat-label">Playbooks</div>
          <div class="stat-value">1</div>
          <div class="stat-note">Playbook de prueba</div>
        </article>
        <article class="stat">
          <div class="stat-label">Integración</div>
          <div class="stat-value">API</div>
          <div class="stat-note">Bearer token de máquina</div>
        </article>
      </div>

      <section class="grid">
        <article class="card">
          <h2>Usuarios</h2>
          <p>Administración de cuentas, roles y acceso interno.</p>
        </article>
        <article class="card">
          <h2>Playbooks</h2>
          <p>Catálogo de playbooks para automatización y flujos del backend.</p>
        </article>
        <article class="card">
          <h2>Tenants</h2>
          <p>Separación de proyectos y contexto operativo por tenant.</p>
        </article>
        <article class="card">
          <h2>API administrativa</h2>
          <p>Los endpoints JSON siguen disponibles bajo <code>/backend/api</code> para integraciones internas.</p>
          <div class="links">
            <a class="btn" href="/backend/api/health">Health check</a>
            <a class="btn" href="/backend/api/playbooks">Playbooks API</a>
            <a class="btn" href="/backend/api/tenants">Tenants API</a>
          </div>
        </article>
        <article class="card">
          <h2>Flujo</h2>
          <p>Panel humano con sesión de navegador separado de la API técnica y del servicio FastAPI.</p>
        </article>
        <article class="card">
          <h2>Estado</h2>
          <p>Backend Symfony activo y listo para administración.</p>
        </article>
      </section>
    </section>
  </main>
</body>
</html>
HTML;

        return new Response($html);
    }
}
