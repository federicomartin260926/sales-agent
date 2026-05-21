# sales-agent

`sales-agent` es una base híbrida para construir un sistema de agentes comerciales multi-tenant con:

- backend administrativo en Symfony 7 + PHP 8.3
- backend administrativo con layouts Twig y migración progresiva desde controladores inline
- API de runtime de agente en FastAPI + Python 3.12
- PostgreSQL
- Docker y Docker Compose

El proyecto está preparado para integrarse más adelante con:

- `wa-gateway-api` para WhatsApp Cloud
- el CRM existente
- `ai-stack` para RAG
- OpenAI u Ollama para LLM
- routing explícito de WhatsApp mediante entrypoints y atribución por click
- importación de productos/servicios desde CRM usando `integration_key`

## Documentación común

- [Platform hub](../platform/README.md)
- [Projects](../platform/projects.md)
- [Networking](../platform/networking.md)
- [Tokens](../platform/tokens.md)

## Arquitectura

- `backend/`: Symfony clásico con Controllers, Entities, Repositories y Services
  - `backend/templates/`: base Twig y vistas HTML del panel administrativo
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

`make up` levanta los servicios, instala dependencias PHP del backend y limpia la caché de Symfony para evitar que queden vistas compiladas antiguas.

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

`make prod-up` levanta la stack de producción y recompila la caché de Symfony en el contenedor del backend.

En producción, `sales-agent-nginx` se publica por la red Docker compartida `proxy` y el runtime de la API usa `Authorization: Bearer ...` para llamadas de otros servicios.
Cuando `sales-agent` y `crm` conviven en el mismo VPS, la API lee el CRM por la red interna compartida `commercial_internal` usando `CRM_BASE_URL=http://crm-nginx`.

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
  - `GET /backend/entry-points`
  - `GET /backend/profile`
- `GET /backend/api/health`
- CRUD REST básico:
  - `/backend/api/tenants` para negocios
  - `/backend/api/products`
  - `/backend/api/playbooks` para guías comerciales opcionales
- Routing y atribución:
  - `/backend/api/r/wa/{entrypointCode}`
  - `/backend/api/internal/routing/entrypoint-ref/{ref}`
  - `/backend/api/internal/routing/whatsapp-phone/{phoneNumberId}`
  - `/backend/api/internal/conversations/upsert`
- Catálogo comercial:
  - `/backend/products`
  - `/backend/products/import`
- `POST /backend/api/login` para el patrón de seguridad basado en JWT

El backend humano sigue un layout tipo CRM con sidebar, métricas, navegación por módulos y perfil editable para nombre y clave.
La UI heredada todavía conserva compatibilidad con piezas antiguas, pero el modelo canónico de routing ya es `EntryPoint -> EntryPointUtm -> Conversation`.
El catálogo de productos puede importarse desde CRM con `externalSource = crm` y `externalReference = integration_key`.
La configuración operativa de LLM y audio ahora vive en `runtime_settings` y se edita desde `GET /backend/configuration`.
Los secretos de esa pantalla se cifran en base de datos y el runtime consulta la snapshot interna en `GET /api/internal/runtime-settings` con `Authorization: Bearer ...`.
Todas las rutas `/api/internal/*` usan `Authorization: Bearer <SALES_AGENT_BEARER_TOKEN>` y no JWT de usuario.

## Bootstrap inicial del backend

El backend Symfony incluye un bootstrap idempotente para crear el primer usuario administrador y datos semilla mínimos del dominio comercial.
Hoy ese seed cubre `tenant`, `product` y una guía comercial de ejemplo para casos opcionales o complementarios.
El routing canónico se configura aparte con:

- `Tenant.whatsappPhoneNumberId`
- `Tenant.whatsappPublicPhone`
- `EntryPoint` asociado a `Product`
- `EntryPointUtm` generado por click
- `Conversation` como hilo operativo
- `Conversation.summary` como resumen opcional para recorte futuro de contexto

```bash
make schema-update
make bootstrap
```

En producción usa:

```bash
make prod-schema-update
make prod-bootstrap
```

Credenciales iniciales:

- email: `federicomartin2609@gmail.com`
- password: `1234`

En local con Docker, el login de navegador está en:

- `http://localhost:8080/backend/login`

La raíz `http://localhost:8080/` y `/login` redirigen al login canónico del backend.

El login JSON para integraciones queda en:

- `http://localhost:8080/backend/api/login`

La comunicación entre `wa-gateway-api` y `sales-agent/api` debe usar `Authorization: Bearer ...` con un token de integración de máquina.
Ese token se configura con `SALES_AGENT_BEARER_TOKEN`.

La misma variable `SALES_AGENT_BEARER_TOKEN` también se publica en el backend para proteger la snapshot interna de runtime settings.

## Modelo inicial

### Symfony

- `User`
- `Negocio` (`Tenant`)
- `Producto / servicio` (`Product`)
- `Guía comercial` (`Playbook`, opcional)

### Roles

Se mantiene el contrato conceptual del CRM:

- `ROLE_AGENT`
- `ROLE_MANAGER`
- `ROLE_ADMIN`

## Integraciones previstas

- `wa-gateway-api`: entrada de mensajes y eventos de WhatsApp
- CRM: lectura de contexto comercial y datos de cuenta
- `ai-stack`: recuperación semántica y contexto documental
- LLM: proveedor intercambiable entre OpenAI y Ollama

## Gestión LLM

La conversación completa se persiste en backend para auditoría, revisión humana y posible envío al CRM.
El contexto enviado al LLM se limita de forma explícita: se usa `Conversation.summary` si existe y después solo los últimos mensajes relevantes, nunca todo el historial.

El prompt se ordena para favorecer prompt caching: primero contexto estable (`tenant`, `product`, `playbook` opcional, `rules`, `sales_runtime`) y después contexto dinámico (`summary`, últimos mensajes y `current_message`).
Los límites aplican solo al contexto enviado al LLM, no al historial persistido.

`previous_response_id` de OpenAI Responses API queda fuera de esta fase y se evaluará más adelante.

Por llamada se debe medir y guardar, al menos: `provider`, `model`, `input_tokens`, `output_tokens`, `cached_tokens`, `total_tokens`, `latency_ms` y `estimated_cost`.

Antes de activar RAG, un contexto CRM más amplio o más tools, debe existir control de tamaño, medición de usage y límites por tenant.

## Límites de uso IA

`sales-agent` soporta dos modos globales de facturación/configuración:

- `AI_BILLING_MODE=byok`
- `AI_BILLING_MODE=managed`

En `managed` se recomienda una API key o proyecto OpenAI por instalación para aislar consumo y reporting. Los límites de uso se aplican siempre por tenant dentro de la instancia.

`AiUsageEvent` es la fuente de reporting y de cálculo de límites. `ConversationMessage.metadata` mantiene la trazabilidad por mensaje y conserva la telemetría útil para auditoría.
La ficha de `Negocio` muestra también un bloque de solo lectura con el consumo IA reciente del tenant: coste hoy/mes y últimos eventos.

## Routing de WhatsApp

El flujo esperado es:

1. un enlace o QR apunta a `GET /backend/api/r/wa/{entrypointCode}`
2. el backend crea un `EntryPointUtm` con `ref` corto y UTMs
3. el redirect lleva al usuario a `wa.me` con `Ref: <ref>` dentro del mensaje
4. `wa-gateway-api` recibe la respuesta entrante
5. `sales-agent/api` resuelve `entrypoint_ref` o `phone_number_id`
6. el runtime obtiene `Tenant` desde `EntryPoint -> Product` o desde `Tenant.whatsappPhoneNumberId`
7. la conversación mínima queda persistida en `Conversation`
8. los productos importados desde CRM usan `slug` como fallback local y `externalReference` como clave estable

## Documentación adicional

- [Modelo de dominio](docs/domain-model.md)
- [Contrato de CRM](docs/crm-contract.md)
- [Matriz de acceso](docs/access-matrix.md)
- [Guía funcional del sistema](docs/operating-model.md)
- [Glosario oficial](docs/glossary.md)
- [TODO del proyecto](docs/todo.md)
- [Backend Symfony](backend/README.md)
- [API FastAPI](api/README.md)
