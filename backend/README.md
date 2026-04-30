# Backend Symfony

Este directorio contiene el backend administrativo de `sales-agent`.

## Qué hace

- expone la API REST administrativa para negocios, productos/servicios, guías comerciales y routing de WhatsApp
- ofrece un formulario de login en navegador para operar el backend como panel humano
- incluye `Mi perfil` para actualizar nombre visible y clave de acceso
- permite crear y editar `negocios`, `guías comerciales`, `productos / servicios` y `puntos de entrada` desde la vista humana con formularios guiados
- permite editar la configuración operativa de LLM y audio desde `/backend/configuration`
- permite importar catálogos de productos/servicios desde CRM con `integration_key` como referencia externa
- la UI heredada ya no expone `canales` ni `tracking`; el flujo canónico se configura con `EntryPoint`, `EntryPointUtm` y `Conversation`
- presenta un layout tipo CRM para navegación operativa por módulos
- mantiene el modelo de usuarios y roles compatible conceptualmente con el CRM
- prepara la autenticación basada en JWT con `json_login`
- incluye un bootstrap idempotente para crear el primer administrador y datos semilla mínimos de negocio, producto y guía comercial
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
  - `/api/products`
  - `/api/playbooks` para guías comerciales
- `GET /api/internal/runtime-settings` para la snapshot operativa que consume el runtime
- `POST /api/login`
- Routing público:
  - `GET /api/r/wa/{entrypointCode}`
- Routing interno:
  - `GET /api/internal/routing/entrypoint-ref/{ref}`
  - `GET /api/internal/routing/whatsapp-phone/{phoneNumberId}`
  - `POST /api/internal/conversations/upsert`

## Bootstrap inicial

Para crear el primer usuario administrador, el negocio de prueba, el producto de prueba y las guías comerciales de prueba:

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
- `ollama_base_url`
- `ollama_model`
- `audio_mode`
- `audio_gateway_base_url`
- `audio_gateway_token` cifrado en BD

Reglas operativas:

- `.env` queda para bootstrap, infraestructura y secretos no editables desde UI
- los secretos se cifran antes de persistirse en `runtime_settings`
- la snapshot interna que usa el runtime se expone en `GET /api/internal/runtime-settings`
- la pantalla muestra estado `listo`, `parcial` o `bloqueado` por proveedor y en global
- los botones de prueba para OpenAI, Ollama y audio realizan requests reales y solo están visibles para administradores

Para que el runtime pueda consultar la snapshot interna, el backend también recibe `SALES_AGENT_BEARER_TOKEN` como secreto de infraestructura.

El routing de WhatsApp ya no depende del bootstrap base. Los `EntryPoint` y `EntryPointUtm` deben configurarse explícitamente con datos reales de campaña y número público.
Los productos importados desde CRM deben guardar `externalSource = crm` y `externalReference = integration_key`; `slug` queda como identificador local y fallback.

## Layout

El backend humano está pensado como un CRM clásico:

- sidebar con navegación por módulos
- estado activo con fondo gris claro en el módulo seleccionado
- menú superior con dropdown de usuario, `Mi perfil` y `Salir`
- dashboard con tarjetas, métricas y accesos directos
- secciones específicas para `Puntos de entrada` y atribución técnica
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

## Routing y atribución

- `Tenant.whatsappPhoneNumberId` resuelve el negocio cuando llega un mensaje desde Meta
- `Tenant.whatsappPublicPhone` define el número público usado en el `wa.me` redirect
- `EntryPoint` representa una campaña, botón, QR o enlace y apunta a un `Product`
- `EntryPointUtm` conserva UTMs, `gclid`, `fbclid` y el `ref` generado por click
- `Conversation` conserva el hilo mínimo operativo y copia la primera atribución
- `ConversationMessage` queda disponible para registrar mensajes futuros
- `Product.externalSource` y `Product.externalReference` permiten alinear catálogos importados con CRM sin usar UUIDs ajenos

El backend no gestiona ramas CRM como entidad propia. Solo conserva `crmBranchRef` como texto opaco cuando viene desde un entrypoint o una atribución externa.

Consulta el glosario oficial en [docs/glossary.md](../docs/glossary.md).
La matriz de permisos está en [docs/access-matrix.md](../docs/access-matrix.md).
