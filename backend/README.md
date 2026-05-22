# Backend Symfony

Este directorio contiene el backend administrativo de `sales-agent`.

## Qué hace

- expone la API REST administrativa para negocios, productos/servicios, guías comerciales opcionales y routing de WhatsApp
- ofrece un formulario de login en navegador para operar el backend como panel humano
- incluye `Mi perfil` para actualizar nombre visible y clave de acceso
- permite crear y editar `negocios`, `guías comerciales` opcionales, `productos / servicios` y `puntos de entrada` desde la vista humana con formularios guiados
- permite usar asistentes IA de borrador en la creación/edición de `negocios` y `guías comerciales` para rellenar formularios sin guardar automáticamente
- mantiene un selector de negocios en sesión y una ficha del negocio activo para trabajar por contexto
- permite editar la configuración operativa de LLM y audio desde `/backend/configuration`
- usa Twig como base de render para el layout común y la primera pantalla migrada de configuración
- sirve los estilos del panel desde `public/assets/backend.css` para evitar CSS embebido en Twig y en el login
- permite registrar `ExternalTool` de `contact_context` legacy vía n8n y `mcp_remote` para preparar herramientas nativas del LLM
- permite editar en `Negocio` `whatsappPhoneNumberId` y `whatsappPublicPhone` para routing técnico y enlaces `wa.me`
- permite importar catálogos de productos/servicios desde CRM con `integration_key` como referencia externa
- la UI heredada ya no expone `canales` ni `tracking`; el flujo canónico se configura con `EntryPoint`, `EntryPointUtm` y `Conversation`
- presenta un layout tipo CRM para navegación operativa por módulos
- mantiene el modelo de usuarios y roles compatible conceptualmente con el CRM
- prepara la autenticación basada en JWT con `json_login`
- incluye un bootstrap idempotente para crear el primer administrador y datos semilla mínimos de negocio, producto y una guía comercial de ejemplo
- expone routing y atribución explícitos para WhatsApp mediante `EntryPoint`, `EntryPointUtm` y `Conversation`

## Stack

- Symfony 7
- PHP 8.3
- Doctrine ORM
- PostgreSQL

## Estructura

- `src/Entity/`: entidades Doctrine
- `src/Repository/`: repositorios Doctrine
- `src/Controller/Web/`: páginas HTML para login y dashboard del backend
- `templates/`: base Twig, layout backend y plantillas de la pantalla de configuración
- `templates/backend/users/index.html.twig`: primera migración de la lista de usuarios a Twig
- `public/assets/backend.css`: estilos del backend y del login, cargados como asset estático
- `src/Controller/Api/`: controladores REST
- `src/Security/`: handlers de seguridad
- `src/Service/`: catálogo, cifrado y presentadores de configuración operativa
- `migrations/`: definiciones históricas de esquema Doctrine, no forman parte del flujo operativo principal

## Endpoints

- Panel humano:
  - `GET /backend/login`
  - `POST /backend/login-check`
- `GET /backend/dashboard`
- `GET /backend/tenants`
- `GET /backend/tenants/new`
- `POST /backend/tenants/new`
- `GET /backend/tenants/{id}/edit`
- `POST /backend/tenants/{id}/edit`
- `GET /backend/entry-points`
- `GET /backend/entry-points/new`
- `POST /backend/entry-points/new`
- `GET /backend/entry-points/{id}/edit`
- `POST /backend/entry-points/{id}/edit`
- `GET /backend/entry-points/{id}`
- `GET /backend/products`
- `GET /backend/products/import`
- `GET /backend/profile`
- `POST /backend/profile/name`
- `POST /backend/profile/password`
- `GET /backend/configuration`
- `POST /backend/configuration`
- `GET /api/health`
- CRUD:
  - `/api/tenants` para negocios
  - `/api/products` para productos/servicios, con `tenant_id` obligatorio en list/show/update/delete
  - `/api/playbooks` para guías comerciales, con `tenant_id` obligatorio en list/show/update/delete
    y `productId` limitado al mismo tenant en create/update
- `GET /api/internal/runtime-settings` para la snapshot operativa que consume el runtime
- `POST /api/login`
- Routing público:
  - `GET /api/r/wa/{entrypointCode}`
- Routing interno:
  - `GET /api/internal/routing/entrypoint-ref/{ref}`
  - `GET /api/internal/routing/whatsapp-phone/{phoneNumberId}`
  - `POST /api/internal/conversations/upsert`
  - `GET /api/internal/ai-usage/{tenantId}/policy`
  - `GET /api/internal/ai-usage/{tenantId}/usage`
  - `POST /api/internal/ai-usage/events`

## Bootstrap inicial

Para crear el primer usuario administrador, el negocio de prueba, el producto de prueba y las guías comerciales de ejemplo:

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

## Configuración operativa

La configuración editable ya no vive en `.env`.
La pantalla `/backend/configuration` permite editar:

- `llm_default_profile`
- `openai_base_url`
- `openai_model`
- `openai_api_key` cifrada en BD
- `openai_timeout_seconds`
- `ollama_base_url` con valor base `http://ollama-vpn-bridge:11434`
- `ollama_model`
- `ollama_timeout_seconds`
- `audio_gateway_base_url`
- `audio_timeout_seconds`

Las `Herramientas externas` permiten registrar:

- `contact_context` legacy con `n8n_webhook`
- `mcp_remote` para dejar configurado el servidor MCP remoto del tenant que usará OpenAI Responses API

La configuración MCP queda guardada en `ExternalTool.config` con campos como `server_label`, `allowed_tools`, `require_approval`, `enabled_for_llm` y `notes`.
Además, `ExternalTool.is_runtime_default` marca el MCP principal del tenant para runtime. Si no hay un principal explícito, la API interna sólo cae a un único MCP activo; no elige arbitrariamente por fecha.
Cuando el runtime usa un perfil no compatible con Responses API, el MCP se ignora y se registra la causa en la traza del mensaje.

Reglas operativas:

- `.env` queda para bootstrap, infraestructura y secretos no editables desde UI
- los secretos se cifran antes de persistirse en `runtime_settings`
- la snapshot interna que usa el runtime se expone en `GET /api/internal/runtime-settings`
- la pantalla muestra estado `listo`, `parcial` o `bloqueado` por proveedor y en global
- los botones de prueba para OpenAI y Ollama realizan requests reales y solo están visibles para administradores

Para que el runtime pueda consultar la snapshot interna, el backend también recibe `SALES_AGENT_BEARER_TOKEN` como secreto de infraestructura.
Todas las rutas `/api/internal/*` usan ese mismo bearer de servicio. No pasan por JWT de usuario ni por `json_login`.

## Logs y rotación

Symfony escribe sus logs en `backend/var/log/`.
En desarrollo se rota con una retención corta para mantener trazas útiles sin crecer sin límite.
En producción se rota con una política más conservadora y solo se conservan los últimos archivos relevantes.

Si necesitas liberar espacio rápido, para el backend y trunca el log activo con `truncate -s 0` en lugar de borrar el directorio completo.
Eso conserva permisos e inodos y evita problemas al reiniciar Symfony.

El routing de WhatsApp ya no depende del bootstrap base. Los `EntryPoint` y `EntryPointUtm` deben configurarse explícitamente con datos reales de campaña y número público.
Los productos importados desde CRM deben guardar `externalSource = crm` y `externalReference = integration_key`; `slug` queda como identificador local y fallback.

## Layout

El backend humano está pensado como un CRM clásico:

- sidebar con navegación por módulos
- estado activo con fondo gris claro en el módulo seleccionado
- menú superior con dropdown de usuario, `Mi perfil` y `Salir`
- `negocio activo` en sesión para orientar productos, guías comerciales, puntos de entrada y servidores MCP
- dashboard con tarjetas, métricas y accesos directos
- secciones específicas para `Puntos de entrada` y atribución técnica
- perfil de usuario con cambio de nombre y contraseña
- shell visual separado de la API técnica
- Twig como base de render para layouts y formularios nuevos, con estilos en assets estáticos

## TODO

- migrar el resto de pantallas inline del controlador a plantillas Twig
- eliminar progresivamente el HTML embebido en `BackendUiController`

## Notas de seguridad

- los roles lógicos se almacenan como `agent`, `manager` y `admin`
- Symfony los expone como `ROLE_AGENT`, `ROLE_MANAGER` y `ROLE_ADMIN`
- el patrón sigue la referencia del CRM existente

## Terminología visible

La UI del backend usa esta terminología para usuarios no técnicos:

- `negocio` para el `tenant`
- `guía comercial` para el `playbook` opcional
- `producto / servicio` para el `product`

## Routing y atribución

- `Tenant.whatsappPhoneNumberId` resuelve el negocio cuando llega un mensaje desde Meta
- `Tenant.whatsappPublicPhone` define el número público usado en el `wa.me` redirect
- `EntryPoint` representa una campaña, botón, QR o enlace y apunta a un `Product`
- `EntryPointUtm` conserva UTMs, `gclid`, `fbclid` y el `ref` generado por click
- `Conversation` conserva el hilo mínimo operativo y copia la primera atribución
- `Conversation.summary` queda disponible como resumen opcional persistido para futuras fases de compresión de contexto
- `ConversationMessage` queda disponible para registrar mensajes futuros
- `TenantAiUsagePolicy` define si la IA está habilitada por tenant y los límites de coste diarios/mensuales
- `AiUsageEvent` es la fuente de reporting y de cálculo de límites por tenant
- `ConversationMessage.metadata` conserva la telemetría de cada mensaje y mantiene la trazabilidad operativa
- `Product.externalSource` y `Product.externalReference` permiten alinear catálogos importados con CRM sin usar UUIDs ajenos

`AI_BILLING_MODE=byok|managed` se documenta como modo global de despliegue. En `managed` se recomienda una API key o proyecto OpenAI por instalación para aislar consumo y reporting; la limitación efectiva sigue siendo por tenant.

La política IA por tenant se edita desde la ficha de `Negocio` en `/backend/tenants/{id}/edit`, dentro del bloque `Uso IA`.
Ese bloque incluye métricas de consumo de solo lectura basadas en `AiUsageEvent`: coste hoy/mes, tokens hoy/mes y los 5 eventos más recientes.

El backend no gestiona ramas CRM como entidad propia. Solo conserva `crmBranchRef` como texto opaco cuando viene desde un entrypoint o una atribución externa.

Consulta el glosario oficial en [docs/glossary.md](../docs/glossary.md).
La matriz de permisos está en [docs/access-matrix.md](../docs/access-matrix.md).
