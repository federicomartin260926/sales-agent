# sales-agent

`sales-agent` es una base híbrida para construir un sistema de agentes comerciales multi-tenant con:

- backend administrativo en Symfony 7 + PHP 8.3
- backend administrativo con layouts Twig y migración progresiva desde controladores inline
- API de runtime de agente en FastAPI + Python 3.12
- PostgreSQL
- Docker y Docker Compose

El proyecto está preparado para integrarse más adelante con:

- `wa-gateway-api` para WhatsApp Cloud
- `ai-stack` para RAG
- OpenAI u Ollama para LLM
- routing explícito de WhatsApp mediante entrypoints y atribución por click
- selector de negocio activo en la UI backend para trabajar dentro de una ficha tenant concreta

## Documentación común

- [Platform hub](../platform/README.md)
- [Projects](../platform/projects.md)
- [Networking](../platform/networking.md)
- [Tokens](../platform/tokens.md)
- [Handoff humano](docs/handoff.md)
- [MCP - n8n contact context](docs/mcp-n8n-contact-context.md)

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

Comandos útiles:

```bash
make recreate
make fix-perms
make dev-diagnose
```

- `make recreate` baja y vuelve a crear la stack de desarrollo.
- `make fix-perms` corrige permisos de `var/` dentro del backend.
- `make dev-diagnose` muestra contenedores, mounts reales, permisos y una comprobación rápida de que el contenedor ve cambios del host.

`restart` sólo reinicia contenedores existentes; no recrea mounts ni aplica cambios de Compose.

En desarrollo Symfony corre con `APP_ENV=dev` y `APP_DEBUG=1`, con WebProfilerBundle activo. La toolbar/profiler debe cargar en `http://localhost:8080/backend/login` y en el resto de páginas del backend.

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
La imagen de nginx de producción copia `backend/public` en build; no usa bind mounts de código.

En producción, `sales-agent-nginx` se publica por la red Docker compartida `proxy` y el runtime de la API usa `Authorization: Bearer ...` para llamadas de otros servicios.
El contexto externo del runtime se obtiene vía `ExternalTool` y MCP/n8n, no con una conexión directa a CRM.

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
  - `GET /backend/users` y `GET /backend/users/new` para plataforma global
  - `GET /backend/ai-usage` y `POST /backend/ai-usage/top-up-requests` para uso IA del tenant activo
  - `GET /backend/super-admin/tenants/{id}/ai` para administración técnica IA por tenant como super admin
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
La UI también mantiene un `negocio activo` en sesión para orientar secciones dependientes como productos, guías comerciales, puntos de entrada y servidores MCP.
La plataforma también incluye una vista de `Uso IA` por tenant con consumo, límites y solicitudes de ampliación pendientes, visible sólo cuando el usuario puede gestionar el negocio activo.
Para `ROLE_SUPER_ADMIN` existe además la vista técnica de IA por tenant en `/backend/super-admin/tenants/{id}/ai`, donde se edita la policy que consume el runtime y se resuelven solicitudes de ampliación.
La UI heredada todavía conserva compatibilidad con piezas antiguas, pero el modelo canónico de routing ya es `EntryPoint -> EntryPointUtm -> Conversation`.
El catálogo de productos puede importarse desde CRM con `externalSource = crm` y `externalReference = integration_key`.
La configuración operativa de LLM y audio ahora vive en `runtime_settings` y se edita desde `GET /backend/configuration`.
Los secretos de esa pantalla se cifran en base de datos y el runtime consulta la snapshot interna en `GET /api/internal/runtime-settings` con `Authorization: Bearer ...`.
Todas las rutas `/api/internal/*` usan `Authorization: Bearer <SALES_AGENT_BEARER_TOKEN>` y no JWT de usuario.

## CRM integrado

`sales-agent` puede funcionar con o sin contexto externo conectado.

- Sin contexto externo, el runtime sigue operando con contexto local, playbooks y herramientas disponibles.
- Con `contact_context` vía ExternalTool/MCP/n8n, el agente conversa, cualifica y entrega contexto estructurado sin acoplarse al sistema aguas abajo.
- El sistema externo detrás de `contact_context` es responsable de contactos, agenda, notas, actividades y pipeline.
- El flujo recomendado es `WhatsApp -> wa-gateway-api -> Sales Agent -> LLM/MCP -> mcp-gateway -> n8n -> sistema externo`.

Handoff humano:

- la política funcional vive en `Tenant`
- el handoff explícito rule-based añade un enlace `wa.me` hacia un número humano configurado por tenant y no llama al LLM
- el handoff inferido por LLM usa la tool MCP `handoff_request` cuando está disponible en MCP y está en `allowed_tools`
- cuando `contact_context` está disponible, el runtime la consulta primero para no tratar como nuevo a un lead o customer ya existente
- cuando `crm_contact_submit` está disponible, el LLM la usa para enviar contexto comercial útil al flujo externo; el sistema externo decide si el resultado acaba en lead, customer, note o activity
- el webhook operativo de n8n se mantiene como `ExternalTool` separado como alternativa/fallback
- el token downstream CRM/MCP sigue siendo tenant-scoped y cifrado; no se reutiliza como auth del webhook de handoff
- la autorización downstream para `crm_contact_submit` requiere scope `contacts:write`
- `api/app/services/llm_client.py` sigue pasando la autorización downstream al MCP remoto como header seguro, sin llevar ese token al prompt ni al payload operativo
- el evento de handoff es `sales_agent.handoff_requested` y viaja sin secretos; n8n decide si crea tareas, avisa por email/Telegram/WhatsApp o mapea a CRM

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

El archivo `.env` está ignorado por Git y no debe contener secretos versionados. `OPENAI_API_KEY` debe quedar vacío en `.env.example`; cualquier clave real debe rotarse y mantenerse fuera del repositorio.
La timezone base de negocio para cálculo temporal se configura con `SA_DEFAULT_BUSINESS_TIMEZONE`; si no se define, el sistema usa `Europe/Madrid`.

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
- `ROLE_SUPER_ADMIN`

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
5. `sales-agent/api` resuelve primero `entrypoint_ref`, luego `phone_number_id` / `external_channel_id` y por último `tenant_id` como fallback técnico
6. el runtime obtiene `Tenant` desde `EntryPoint -> Product` o desde `Tenant.whatsappPhoneNumberId`; si el `entrypoint_ref` y el `phone_number_id` apuntan a tenants distintos, el runtime corta con un error controlado de routing
7. la conversación mínima queda persistida en `Conversation`
8. los productos importados desde CRM usan `slug` como fallback local y `externalReference` como clave estable

Para WhatsApp real, cada tenant debe tener un `whatsappPhoneNumberId` único. En pruebas con un único número de Meta, la forma operativa es dejar el campo vacío en los tenants no usados y asignarlo manualmente solo al tenant que se esté probando.

## Documentación adicional

- [Modelo de dominio](docs/domain-model.md)
- [Contrato de CRM](docs/crm-contract.md)
- [Ensamblado de contexto LLM](docs/llm-context-assembly.md)
- [MCP - n8n contact context](docs/mcp-n8n-contact-context.md)
- [Matriz de acceso](docs/access-matrix.md)
- [Guía funcional del sistema](docs/operating-model.md)
- [Glosario oficial](docs/glossary.md)
- [TODO del proyecto](docs/todo.md)
- [Backend Symfony](backend/README.md)
- [API FastAPI](api/README.md)
