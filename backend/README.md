# Backend Symfony

Este directorio contiene el backend administrativo de `sales-agent`.

## Qué hace

- expone la API REST administrativa para negocios, productos/servicios y guías comerciales
- ofrece un formulario de login en navegador para operar el backend como panel humano
- incluye `Mi perfil` para actualizar nombre visible y clave de acceso
- presenta un layout tipo CRM para navegación operativa por módulos
- mantiene el modelo de usuarios y roles compatible conceptualmente con el CRM
- prepara la autenticación basada en JWT con `json_login`
- incluye un bootstrap idempotente para crear el primer administrador y una guía comercial de prueba

## Stack

- Symfony 7
- PHP 8.3
- Doctrine ORM
- PostgreSQL

## Estructura

- `src/Entity/`: entidades Doctrine
- `src/Repository/`: repositorios Doctrine
- `src/Controller/Web/`: páginas HTML para login y dashboard del backend
- `src/Controller/Api/`: controladores REST
- `src/Security/`: handlers de seguridad
- `migrations/`: definiciones históricas de esquema Doctrine, no forman parte del flujo operativo principal

## Endpoints

- Panel humano:
  - `GET /backend/login`
  - `POST /backend/login-check`
  - `GET /backend/dashboard`
  - `GET /backend/profile`
  - `POST /backend/profile/name`
  - `POST /backend/profile/password`
- `GET /api/health`
- CRUD:
  - `/api/tenants` para negocios
  - `/api/products`
  - `/api/playbooks` para guías comerciales
- `POST /api/login`

## Bootstrap inicial

Para crear el primer usuario administrador y la guía comercial de prueba:

```bash
make schema-update
make bootstrap
```

En producción, el esquema se sincroniza con:

```bash
make prod-schema-update
make prod-bootstrap
```

Credenciales iniciales:

- email: `federicomartin2609@gmail.com`
- password: `1234`

En desarrollo local con Docker, el login de navegador queda en:

- `http://localhost:8080/backend/login`

La raíz `http://localhost:8080/` y `/login` redirigen al login canónico del backend.

El login técnico JSON sigue disponible en:

- `http://localhost:8080/backend/api/login`

El panel HTML usa sesión de navegador y el login JSON responde con JWT.

## Layout

El backend humano está pensado como un CRM clásico:

- sidebar con navegación por módulos
- estado activo con fondo gris claro en el módulo seleccionado
- menú superior con dropdown de usuario, `Mi perfil` y `Salir`
- dashboard con tarjetas, métricas y accesos directos
- perfil de usuario con cambio de nombre y contraseña
- shell visual separado de la API técnica

## Notas de seguridad

- los roles lógicos se almacenan como `agent`, `manager` y `admin`
- Symfony los expone como `ROLE_AGENT`, `ROLE_MANAGER` y `ROLE_ADMIN`
- el patrón sigue la referencia del CRM existente

## Terminología visible

La UI del backend usa esta terminología para usuarios no técnicos:

- `negocio` para el `tenant`
- `guía comercial` para el `playbook`
- `producto / servicio` para el `product`

Consulta el glosario oficial en [docs/glossary.md](../docs/glossary.md).
