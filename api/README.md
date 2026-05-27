# FastAPI Agent Runtime

Este directorio contiene el runtime del agente conversacional.

## Qué hace

- expone `GET /health`
- expone `POST /agent/respond`
- decide respuestas simples sin invocar todavía un LLM real
- deja listos los clientes para LLM, CRM, RAG y backend
- consulta la snapshot operativa de `runtime_settings` en el backend cuando necesita resolver la configuración LLM
- obtiene configuración MCP remota por tenant desde Symfony para abrir herramientas nativas en OpenAI Responses API
- protege el runtime con `Authorization: Bearer <token>` para tráfico service-to-service
- resuelve routing explícito por `entrypoint_ref`, `phone_number_id` o `tenant_id`
- persiste o actualiza una conversación mínima cuando el routing está resuelto

## Stack

- FastAPI
- uvicorn
- httpx
- pydantic-settings
- Python 3.12

## Flujo

1. recibe un mensaje de un tenant
2. valida el bearer token de integración
3. resuelve routing por `entrypoint_ref`, `phone_number_id` o `tenant_id`
4. consulta el backend Symfony para cargar el contexto del negocio, producto y guía comercial
5. consulta el CRM por teléfono si está disponible
6. consulta la configuración MCP remota del tenant si existe
7. ejecuta el `DecisionEngine`
8. devuelve una respuesta estructurada con intención, score y acción sugerida

## Contrato de dominio

### Entrada

`POST /agent/respond` acepta un payload normalizado con:

- `tenant_id`
- `message`
- `contact`
- `conversation`

#### `message`

`message` se normaliza internamente a una estructura con:

- `id` opcional
- `type`
- `text`
- `timestamp` opcional

El runtime también acepta el formato simple `message: "..."` para pruebas o integraciones ligeras.

#### `contact`

`contact` requiere un teléfono y puede incluir:

- `phone`
- `wa_id`
- `from`
- `name`

#### `conversation`

`conversation.last_messages` es una lista de textos ya normalizados.
El runtime recorta el contexto enviado al LLM a los últimos 8 mensajes y limita el tamaño total antes de serializarlo.
Si llega `conversation.summary`, se envía antes de `last_messages` para reducir repetición de contexto.

## Gestión de tokens y coste LLM

La conversación completa se guarda en backend para auditoría, revisión humana y eventual envío al CRM.
Ese historial no se recorta en persistencia: el límite solo aplica al contexto que se manda al LLM.

`Conversation.summary` queda disponible como resumen opcional persistido. Si existe, se usa antes del historial reciente para comprimir contexto sin perder trazabilidad.

La llamada al modelo debe registrar por turno:

- `provider`
- `model`
- `input_tokens`
- `output_tokens`
- `cached_tokens`
- `total_tokens`
- `latency_ms`
- `estimated_cost`

El prompt debe ordenarse para favorecer prompt caching:

- primero contexto estable: `tenant`, `product`, `playbook`, `rules`, `sales_runtime`
- después contexto dinámico: `summary`, últimos mensajes y `current_message`

`previous_response_id` de OpenAI Responses API no se usa en esta fase.

Antes de activar RAG, ampliar el contexto CRM o sumar más tools, hay que mantener control de tamaño, medición de usage y límites por tenant.

`AI_BILLING_MODE=byok|managed` es una configuración global/documental. En `managed` se recomienda una API key o proyecto OpenAI por instalación para aislar consumo y reporting.

### Routing

`POST /agent/respond` también acepta, de forma opcional:

- `external_channel_id`
- `phone_number_id` como alias de `external_channel_id`
- `entrypoint_ref`
- `raw_event`

El runtime extrae `entrypoint_ref` explícito o desde el texto del mensaje usando patrones como:

- `Ref: abc123`
- `ref abc123`
- `#abc123`

### Salida

`POST /agent/respond` devuelve siempre:

- `reply`
- `intent`
- `score`
- `action`
- `needs_human`
- `data_to_save`

La respuesta debe ser estructurada para que `wa-gateway-api` decida cómo enviarla y cualquier componente posterior decida qué guardar.

## Integración con Symfony

FastAPI lee contexto comercial desde el backend Symfony a través de:

- `BACKEND_BASE_URL`

En Docker local el valor por defecto apunta a:

- `http://sales-agent-nginx`

El runtime consulta:

- `GET /api/tenants/{tenant_id}`
- `GET /api/products?tenant_id=<tenant_id>`
- `GET /api/playbooks?tenant_id=<tenant_id>`

Las APIs REST legacy de productos y guías comerciales son tenant-scoped: requieren `tenant_id` y no permiten reasignar entidades entre tenants por `update`.

El catálogo de productos devuelto por el backend incluye además:

- `slug`
- `externalSource`
- `externalReference`
- `basePriceCents`
- `currency`

Si el backend no está disponible o el tenant no existe, el runtime cae a un modo de fallback basado en heurísticas simples para no romper la conversación.

Además, para resolver la configuración LLM y audio usa:

- `GET /api/internal/runtime-settings`

Ese endpoint devuelve la snapshot operativa que sale de base de datos y se protege con `Authorization: Bearer <SALES_AGENT_BEARER_TOKEN>`.
Las rutas internas de Symfony se consumen como `BACKEND_BASE_URL + /api/internal/...`, por ejemplo:

- `GET /api/internal/runtime-settings`
- `GET /api/internal/routing/entrypoint-ref/{ref}`
- `GET /api/internal/routing/whatsapp-phone/{phoneNumberId}`
- `GET /api/internal/mcp/{tenantId}/config`
- `POST /api/internal/conversations/upsert`

Si el backend no responde, el runtime usa los valores bootstrap del entorno como fallback.
La snapshot incluye también `openai_timeout_seconds`, `ollama_timeout_seconds` y `audio_timeout_seconds` para que el runtime pueda consumirlos cuando se cableen las integraciones activas.

## Integración con CRM

El runtime también puede leer contexto del CRM a través de:

- `CRM_BASE_URL`

En esta fase se espera este contrato:

- `GET /api/agent/contact-context?phone=<phone>`

El CRM devuelve contexto de contacto, lead y oportunidad para:

- evitar preguntas redundantes
- detectar si el lead ya está avanzado
- mejorar el handoff a humano

Si el routing aporta `crm_branch_ref`, el runtime lo conserva en `data_to_save` como texto opaco.
Si el contexto comercial incluye un producto, `data_to_save` también conserva `product_slug`, `product_external_source`, `product_external_reference`, `product_base_price_cents` y `product_currency` cuando existen.

El runtime no escribe en el CRM. Solo consume ese contexto para enriquecer la decisión conversacional.

## Integración con MCP remoto

Si un tenant tiene `ExternalTool` activo de tipo `mcp_remote`, el runtime lee la configuración interna en Symfony y, cuando el perfil LLM activo es OpenAI compatible con Responses API, puede exponer ese MCP como herramienta nativa para el modelo.
La API interna prioriza el MCP marcado como principal (`is_runtime_default = true`). Si no existe principal, sólo usa un único MCP activo como fallback conservador; no selecciona uno arbitrario por fecha.
Si entre las herramientas autorizadas del MCP está `handoff_request`, el sistema de instrucciones del LLM le indica que puede usarla para escalados inferidos por frustración, queja, bloqueo, riesgo o complejidad, pero no para peticiones explícitas de hablar con una persona: ese caso sigue resuelto por el runtime rule-based con `wa.me`.
Para pruebas técnicas temporales de autorización contra un MCP remoto, el runtime acepta `MCP_TEST_AUTHORIZATION` en el entorno de `sales-agent-api` y lo envía en la propiedad `authorization` del tool MCP de OpenAI. El valor puede venir como token puro o como `Bearer ...`; internamente se normaliza para no exponerlo en prompt, logs ni respuestas.
En producción, el valor preferente llega desde la config interna de MCP del tenant como `downstream_authorization_token` y se mantiene cifrado en Symfony; `bearer_token` sigue existiendo como compatibilidad interna.
Si necesitas validar el pasaje extremo a extremo con un gateway temporal, define también `MCP_ENABLE_DEBUG_TOOLS=true` en `mcp-gateway` para exponer la tool `debug_auth_context`.
Tras cambiar `MCP_TEST_AUTHORIZATION` en `.env`, recrea el contenedor con `docker compose up -d --force-recreate sales-agent-api` para que la variable entre realmente en el entorno del servicio.

En esta fase:

- `contact_context` legacy vía n8n queda deprecado y ya no forma parte del runtime principal
- `ollama` sigue en flujo legacy sin MCP
- `heuristic` sigue sin LLM
- la configuración MCP se ignora si el proveedor activo no es compatible con Responses API

El contrato histórico de `contact_context` con n8n, incluyendo la separación entre `Authorization` y `X-Downstream-Authorization`, está documentado en [docs/mcp-n8n-contact-context.md](../docs/mcp-n8n-contact-context.md).

Prueba manual recomendada, una vez que `sales-agent` tenga un tenant con `mcp_remote` activo y `mcp-gateway` desplegado detrás de NPM:

```bash
curl -X POST http://localhost:8080/api/agent/respond \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer <SALES_AGENT_BEARER_TOKEN>' \
  -d '{
    "tenant_id": "tenant-1",
    "channel_type": "whatsapp",
    "external_channel_id": "test",
    "contact": {
      "wa_id": "34600000000",
      "from": "34600000000",
      "name": "Cliente Demo"
    },
    "message": {
      "text": "Busca el contexto del contacto con teléfono +34600000000 usando la herramienta disponible."
    }
  }'
```

La validación end-to-end real contra `https://mcp.tech-investments.net/mcp` queda pendiente hasta que `mcp-gateway` esté expuesto públicamente y accesible desde OpenAI Responses API.

## Formato de entrada

`POST /agent/respond` acepta:

- el formato simple del runtime, con `message` como texto y `contact.phone`
- el formato normalizado que envía `wa-gateway-api`, con:
  - `message.id`
  - `message.type`
  - `message.text`
  - `message.timestamp`
  - `contact.wa_id`
  - `contact.from`
  - `contact.name`

Internamente se normaliza a la forma que consume `DecisionEngine`.

## Autenticación

`POST /agent/respond` exige un token enviado en:

- `Authorization: Bearer <SALES_AGENT_BEARER_TOKEN>`

Este token no representa un usuario humano. Es para la comunicación entre `wa-gateway-api` y `sales-agent/api`.
En local y en producción se configura con la variable `SALES_AGENT_BEARER_TOKEN`.

## Extensión futura

- `crm_client.py`: contexto desde el CRM
- `rag_client.py`: recuperación semántica
- `llm_client.py`: OpenAI u Ollama
- `runtime_settings_client.py`: snapshot operativa del backend con fallback a env
