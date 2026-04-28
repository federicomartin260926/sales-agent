# sales-agent

`sales-agent` es una base híbrida para construir un sistema de agentes comerciales multi-tenant con:

- backend administrativo en Symfony 7 + PHP 8.3
- API de runtime de agente en FastAPI + Python 3.12
- PostgreSQL
- Docker y Docker Compose

El proyecto está preparado para integrarse más adelante con:

- `wa-gateway-api` para WhatsApp Cloud
- el CRM existente
- `ai-stack` para RAG
- OpenAI u Ollama para LLM

## Arquitectura

- `backend/`: Symfony clásico con Controllers, Entities, Repositories y Services
- `api/`: FastAPI para runtime del agente y decisiones conversacionales
- `docker/`: configuración compartida de Nginx y runtime
- `docker-compose.yml`: base común
- `docker-compose.dev.yml`: desarrollo local con Docker
- `docker-compose.prod.yml`: producción sin mounts de código

La separación se mantiene explícita:

- producción: imagen construida, sin bind mounts de código
- desarrollo local: bind mounts y hot reload donde aporta valor
- desarrollo host: no se usa como ruta principal

## Stack

- Symfony 7
- PHP 8.3
- Doctrine ORM
- PostgreSQL 15
- FastAPI
- uvicorn
- httpx
- pydantic-settings
- Docker Compose

## Cómo levantarlo

### Desarrollo

```bash
make up
```

Para ver logs:

```bash
make logs
```

Para parar:

```bash
make down
```

### Producción

```bash
make prod-up
```

En producción, `sales-agent-nginx` se publica por la red Docker compartida `proxy` y el runtime de la API usa `Authorization: Bearer ...` para llamadas de otros servicios.

## Puertos y rutas

En desarrollo local:

- Nginx: `http://localhost:8080`
- FastAPI directo: `http://localhost:8000`
- PostgreSQL: `localhost:5432`

Rutas públicas a través de Nginx:

- `/backend` -> Symfony
- `/api` -> FastAPI

## Endpoints

### FastAPI

- `GET /health`
- `POST /agent/respond`

### Symfony

- Panel humano:
  - `GET /backend/login`
  - `GET /backend/dashboard`
- `GET /backend/api/health`
- CRUD REST básico:
  - `/backend/api/tenants`
  - `/backend/api/products`
  - `/backend/api/playbooks`
- `POST /backend/api/login` para el patrón de seguridad basado en JWT

El backend humano sigue un layout tipo CRM con sidebar, métricas y navegación por módulos.

## Bootstrap inicial del backend

El backend Symfony incluye un bootstrap idempotente para crear el primer usuario administrador y un playbook de prueba.

```bash
make bootstrap
```

Credenciales iniciales:

- email: `federicomartin2609@gmail.com`
- password: `1234`

En local con Docker, el login de navegador está en:

- `http://localhost:8080/backend/login`

El login JSON para integraciones queda en:

- `http://localhost:8080/backend/api/login`

La comunicación entre `wa-gateway-api` y `sales-agent/api` debe usar `Authorization: Bearer ...` con un token de integración de máquina.
Ese token se configura con `SALES_AGENT_BEARER_TOKEN`.

## Modelo inicial

### Symfony

- `User`
- `Tenant`
- `Product`
- `Playbook`

### Roles

Se mantiene el contrato conceptual del CRM:

- `ROLE_AGENT`
- `ROLE_MANAGER`
- `ROLE_ADMIN`

## Integraciones previstas

- `wa-gateway-api`: entrada de mensajes y eventos de WhatsApp
- CRM: sincronización de contexto comercial y datos de cuenta
- `ai-stack`: recuperación semántica y contexto documental
- LLM: proveedor intercambiable entre OpenAI y Ollama

## Documentación adicional

- [Backend Symfony](backend/README.md)
- [API FastAPI](api/README.md)
