# Backend Symfony

Este directorio contiene el backend administrativo de `sales-agent`.

## Qué hace

- expone la API REST administrativa para tenants, products y playbooks
- ofrece un formulario de login en navegador para operar el backend como panel humano
- presenta un layout tipo CRM para navegación operativa por módulos
- mantiene el modelo de usuarios y roles compatible conceptualmente con el CRM
- prepara la autenticación basada en JWT con `json_login`
- incluye un bootstrap idempotente para crear el primer admin y el primer playbook de prueba

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
- `migrations/`: migraciones Doctrine

## Endpoints

- Panel humano:
  - `GET /backend/login`
  - `POST /backend/login-check`
  - `GET /backend/dashboard`
- `GET /api/health`
- CRUD:
  - `/api/tenants`
  - `/api/products`
  - `/api/playbooks`
- `POST /api/login`

## Bootstrap inicial

Para crear el primer usuario administrador y el playbook de prueba:

```bash
make bootstrap
```

Credenciales iniciales:

- email: `federicomartin2609@gmail.com`
- password: `1234`

En desarrollo local con Docker, el login de navegador queda en:

- `http://localhost:8080/backend/login`

El login técnico JSON sigue disponible en:

- `http://localhost:8080/backend/api/login`

El panel HTML usa sesión de navegador y el login JSON responde con JWT.

## Layout

El backend humano está pensado como un CRM clásico:

- sidebar con navegación por módulos
- dashboard con tarjetas, métricas y accesos directos
- shell visual separado de la API técnica

## Notas de seguridad

- los roles lógicos se almacenan como `agent`, `manager` y `admin`
- Symfony los expone como `ROLE_AGENT`, `ROLE_MANAGER` y `ROLE_ADMIN`
- el patrón sigue la referencia del CRM existente
