# Ensamblado de contexto LLM

Este documento describe cómo `sales-agent` construye el contexto que termina en el LLM hoy, con la implementación actual de FastAPI + backend Symfony + MCP remoto.

No es una propuesta futura. Es una auditoría del comportamiento real del código actual.

## 1. Visión general

Flujo alto nivel:

`POST /agent/respond`
-> `runtime.respond()`
-> routing y resolución de tenant/entrypoint/canal
-> `backend_client.fetch_tenant_context()`
-> `backend_client.fetch_mcp_config()`
-> `runtime_settings_client.effective_values()`
-> posible carga de summaries y `previous_response_id`
-> `build_intent_user_prompt()` / `build_final_user_prompt()`
-> `ToolSelector.select()`
-> `LLMClient.generate_with_mcp()` o `LLMClient.generate()`
-> OpenAI Responses API
-> tool traces MCP en la respuesta
-> persistencia de conversación/mensajes/uso IA
-> `data_to_save`

## 2. Archivos principales

### FastAPI runtime

- [api/app/services/runtime.py](/home/fede/www/sales-agent/api/app/services/runtime.py)
- [api/app/services/backend_client.py](/home/fede/www/sales-agent/api/app/services/backend_client.py)
- [api/app/services/routing_resolver.py](/home/fede/www/sales-agent/api/app/services/routing_resolver.py)
- [api/app/services/llm_client.py](/home/fede/www/sales-agent/api/app/services/llm_client.py)
- [api/app/services/runtime_settings_client.py](/home/fede/www/sales-agent/api/app/services/runtime_settings_client.py)
- [api/app/schemas/agent.py](/home/fede/www/sales-agent/api/app/schemas/agent.py)
- [api/app/schemas/llm.py](/home/fede/www/sales-agent/api/app/schemas/llm.py)

### Symfony backend

- [backend/src/Controller/Api/InternalCommercialContextController.php](/home/fede/www/sales-agent/backend/src/Controller/Api/InternalCommercialContextController.php)
- [backend/src/Controller/Api/InternalMcpConfigController.php](/home/fede/www/sales-agent/backend/src/Controller/Api/InternalMcpConfigController.php)
- [backend/src/Controller/Api/InternalRuntimeSettingsController.php](/home/fede/www/sales-agent/backend/src/Controller/Api/InternalRuntimeSettingsController.php)
- [backend/src/Controller/Api/RoutingController.php](/home/fede/www/sales-agent/backend/src/Controller/Api/RoutingController.php)
- [backend/src/Controller/Api/InternalConversationSummaryController.php](/home/fede/www/sales-agent/backend/src/Controller/Api/InternalConversationSummaryController.php)
- [backend/src/Controller/Api/InternalAiUsageController.php](/home/fede/www/sales-agent/backend/src/Controller/Api/InternalAiUsageController.php)
- [backend/src/Service/ProductContextResolver.php](/home/fede/www/sales-agent/backend/src/Service/ProductContextResolver.php)
- [backend/src/Entity/Tenant.php](/home/fede/www/sales-agent/backend/src/Entity/Tenant.php)
- [backend/src/Entity/Product.php](/home/fede/www/sales-agent/backend/src/Entity/Product.php)
- [backend/src/Entity/Playbook.php](/home/fede/www/sales-agent/backend/src/Entity/Playbook.php)
- [backend/src/Entity/EntryPoint.php](/home/fede/www/sales-agent/backend/src/Entity/EntryPoint.php)
- [backend/src/Entity/Conversation.php](/home/fede/www/sales-agent/backend/src/Entity/Conversation.php)
- [backend/src/Entity/ConversationMessage.php](/home/fede/www/sales-agent/backend/src/Entity/ConversationMessage.php)
- [backend/src/Entity/ExternalTool.php](/home/fede/www/sales-agent/backend/src/Entity/ExternalTool.php)

### Tests útiles

- [api/tests/test_llm_context_hardening.py](/home/fede/www/sales-agent/api/tests/test_llm_context_hardening.py)
- [api/tests/test_llm_client_mcp.py](/home/fede/www/sales-agent/api/tests/test_llm_client_mcp.py)
- [api/tests/test_routing_runtime.py](/home/fede/www/sales-agent/api/tests/test_routing_runtime.py)
- [api/tests/test_agent_llm_telemetry_e2e.py](/home/fede/www/sales-agent/api/tests/test_agent_llm_telemetry_e2e.py)
- [backend/tests/Unit/InternalMcpConfigControllerTest.php](/home/fede/www/sales-agent/backend/tests/Unit/InternalMcpConfigControllerTest.php)
- [backend/tests/Unit/InternalCommercialContextControllerTest.php](/home/fede/www/sales-agent/backend/tests/Unit/InternalCommercialContextControllerTest.php)

## 3. Orden real de carga

### 1. Entrada

La entrada operativa llega como `AgentRequest` en [api/app/schemas/agent.py](/home/fede/www/sales-agent/api/app/schemas/agent.py) y entra por `AgentRuntime.respond()` en [api/app/services/runtime.py](/home/fede/www/sales-agent/api/app/services/runtime.py).

`AgentRequest` contiene, entre otros:

- `tenant_id`
- `channel_type`
- `external_channel_id`
- `entrypoint_ref`
- `message`
- `contact`
- `conversation`
- `raw_event`

`Conversation` en el request lleva:

- `external_id`
- `summary`
- `last_messages` como `list[str]`

### 2. Routing

`RuntimeRoutingResolver.resolve()` en [api/app/services/routing_resolver.py](/home/fede/www/sales-agent/api/app/services/routing_resolver.py) decide el tenant con este orden:

- `entrypoint_ref`
- `external_channel_id` / `phone_number_id`
- `tenant_id` explícito como fallback controlado

Si `entrypoint_ref` y canal apuntan a tenants distintos, devuelve `status="misconfigured_routing"`.

### 3. Contexto comercial

`AgentRuntime.respond()` llama a `BackendClient.fetch_tenant_context()` en [api/app/services/backend_client.py](/home/fede/www/sales-agent/api/app/services/backend_client.py).

Ese cliente consulta `GET /api/internal/commercial-context` en el backend Symfony con:

- `tenant_id`
- `product_id`
- `playbook_id`
- `entry_point_id`
- `entrypoint_ref`
- `customer_phone`
- `external_channel_id`
- `current_message`

El backend responde con `CommercialContext`, que incluye:

- `tenant`
- `products`
- `product_selection`
- `playbooks`
- `entry_point`
- `sales_runtime`
- `selected_product`
- `selected_playbook`
- flags de fallback

### 4. Selección de producto

La selección real se hace en `ProductContextResolver.resolve()` en [backend/src/Service/ProductContextResolver.php](/home/fede/www/sales-agent/backend/src/Service/ProductContextResolver.php).

Orden observado:

- si hay `EntryPoint` con `Product` activo del mismo tenant, se prioriza
- si hay `product_id` explícito, se usa
- si el mensaje permite extraer una query, se hace búsqueda en catálogo local
- si no hay match local y existe MCP `services_search`, se marca `fallback_to_mcp_allowed`
- si hay varios candidatos, el contexto devuelve `needs_service_clarification=true`

### 5. MCP config

`AgentRuntime.respond()` llama a `BackendClient.fetch_mcp_config()` para obtener la configuración MCP del tenant desde `GET /api/internal/mcp/{tenantId}/config`.

`InternalMcpConfigController` devuelve:

- `enabled`
- `tool_id`
- `tenant_id`
- `provider`
- `type`
- `server_label`
- `server_url`
- `auth_type`
- `bearer_token`
- `downstream_authorization_token`
- `downstream_authorization_configured`
- `allowed_tools`
- `require_approval`
- `timeout_seconds`
- `config`

`bearer_token` / `downstream_authorization_token` son secretos internos. No se mandan al prompt.

### 6. Runtime settings y policy

`AgentRuntime.respond()` llama a `LLMClient.resolve_configuration()` a través de `RuntimeSettingsClient.effective_values()`.

`RuntimeSettingsClient` consulta `GET /api/internal/runtime-settings` con `Authorization: Bearer <SALES_AGENT_BEARER_TOKEN>`.

Si no hay backend disponible o el token no está configurado, usa fallback local.

Las keys efectivas incluyen:

- `llm_default_profile`
- `openai_api_key`
- `openai_base_url`
- `openai_model`
- `openai_timeout_seconds`
- `openai_responses_timeout_seconds`
- `openai_responses_max_attempts`
- `openai_responses_retry_delay_seconds`
- `ollama_*`
- audio settings

### 7. Conversación previa y summaries

`AgentRuntime.respond()` persiste la conversación con:

- `BackendClient.upsert_conversation()`
- `BackendClient.create_conversation_message()` para inbound

`BackendConversationUpsertPayload` usa:

- `tenant_id`
- `product_id`
- `entry_point_id`
- `entry_point_utm_id`
- `customer_phone`
- `customer_name`
- `first_message`
- UTMs y `crm_branch_ref`

`Conversation` en backend conserva:

- `summary`
- `lastOpenAiResponseId`
- `lastOpenAiResponseAt`
- `status`
- `customerPhone`
- `customerName`
- `entryPoint`, `product`, `tenant`

`previous_response_id` se calcula en `AgentRuntime._previous_response_id_from_conversation_result()` solo si:

- `openai_conversation_state_enabled` está activo
- la conversación está `active`
- el id anterior tiene prefijo `resp_`
- la marca temporal está dentro del TTL configurado

### 8. Prompt

`build_intent_user_prompt()` y `build_final_user_prompt()` construyen:

- `system_prompt`
- `user_prompt` JSON

El `user_prompt` incluye:

- `tenant`
- `product`
- `products`
- `product_selection`
- `playbook`
- `entry_point`
- `sales_runtime`
- `routing`
- `contact`
- `conversation`
- `current_message`

`conversation.last_messages` viene del request y se sanea en `AgentRequest.Conversation`.

`ToolSelector.select()` también inserta reglas hardcoded según `mcp_config.allowed_tools`.

### 9. LLM / MCP

`LLMClient.generate_with_mcp()` elige provider:

- `openai`
- `ollama`
- `heuristic`

Si `mcp_config.enabled` y el provider es `openai`, llama a `LLMClient.generate_with_mcp()`.

`LLMClient._generate_openai_responses()` envía a OpenAI Responses API:

- `model`
- `instructions`
- `input`
- `temperature`
- `text.format`
- `previous_response_id` si aplica
- `tools` si hay MCP remoto

La tool MCP remota que se manda a OpenAI se construye en `_build_openai_mcp_tools()` con:

- `type: mcp`
- `server_label`
- `server_url`
- `allowed_tools`
- `require_approval`
- `authorization`

`authorization` sale de:

- `settings.mcp_test_authorization`
- `mcp_config.downstream_authorization_token`
- fallback a `mcp_config.bearer_token`

### 10. Respuesta y tool traces

`LLMClient._extract_tool_traces()` lee `output` de OpenAI Responses y captura entradas tipo:

- `mcp_*`
- `tool_call`
- `function_call`

Cada trace guarda:

- `type`
- `server_label`
- `tool_name`
- `arguments`
- `output`
- `status`
- `raw`

### 11. Persistencia final

`AgentRuntime.respond()` persiste el outbound con:

- `BackendClient.create_conversation_message()`
- `BackendClient.create_ai_usage_event()`

La decisión de generar summary hoy se activa cuando:

- `response.needs_human` es `true`
- o `response.action == "handoff_to_human"`

## 12. Contexto operativo que SA entrega al LLM

Sales Agent no pasa únicamente texto al modelo. Construye un contexto operativo estructurado que mezcla:

- contexto de tenant y negocio
- contexto de producto / servicio
- contexto de contacto externo
- contexto de agenda y timezone
- continuidad conversacional
- estado operativo del turno

El bloque clave es `operational_context`, que resume la verdad operativa del turno y se inyecta en el prompt junto con `temporal_context`.

Ejemplo simplificado:

```json
{
  "tenant_id": "019e4a9a-c85f-72d4-8748-b756073c324c",
  "channel": "whatsapp",
  "contact": {
    "phone": "+34...",
    "email": null,
    "name": "Cliente"
  },
  "effective_timezone": "Atlantic/Canary",
  "appointment_tool_timezone": "Atlantic/Canary",
  "contact_context_available": true,
  "effective_timezone_source": "crm_tenant",
  "contact_context_source": "external_tool:n8n"
}
```

### Qué significa cada campo

- `effective_timezone`: timezone operativa real del turno
- `effective_timezone_source`: fuente de esa timezone
- `appointment_tool_timezone`: timezone que debe usar `appointment_availability` / `appointment_confirm`
- `technical_fallback_timezone`: fallback técnico de seguridad cuando no hay timezone fiable
- `technical_fallback_timezone_source`: origen del fallback técnico
- `timezone_guardrail_blocked`: indica si se bloqueó una tool por inconsistencia temporal
- `timezone_guardrail_reason`: motivo del bloqueo
- `timezone_mismatch_detected`: diferencia entre timezone esperada y la usada por la tool
- `expected_timezone`: timezone que SA esperaba que usara la tool
- `actual_timezone`: timezone realmente observada en la tool trace
- `mismatched_tool`: nombre de la tool conflictiva cuando el guardrail bloquea

Regla crítica:

- el fallback técnico, por ejemplo `Europe/Madrid`, solo se usa si no hay una timezone operativa fiable
- si el CRM o el contexto del negocio aporta `Atlantic/Canary` u otro timezone válido, ese valor gana
- las tools de agenda deben seguir `appointment_tool_timezone`, no el fallback técnico

## 13. `contact_context` vía ExternalTool n8n

El contexto externo del contacto se obtiene a través de una `ExternalTool` por tenant con:

- `type = contact_context`
- `provider = n8n_webhook`

La llamada operacional es:

`SA -> ExternalTool contact_context -> n8n -> CRM`

### Separación de tokens

Hay dos credenciales distintas:

- `Authorization: Bearer <N8N_WEBHOOK_TOKEN>` para proteger el webhook de n8n
- `X-Downstream-Authorization: Bearer <CRM_DOWNSTREAM_TOKEN>` para que n8n consulte CRM u otra fuente aguas abajo

No se deben mezclar:

- webhook auth ≠ downstream auth
- el token downstream no va al prompt
- el token downstream no va en `data_to_save`
- el token downstream no debe aparecer en logs ni respuestas

### Flags que SA conserva

Cuando se resuelve `contact_context`, SA conserva y/o publica:

- `contact_context_resolver_called`
- `contact_context_available`
- `contact_context_source`
- `contact_context_external_tool_called`
- `contact_context_external_tool_available`
- `contact_context_cache_lookup`
- `contact_context_cache_hit`
- `contact_context_error_code`
- `contact_context_error_message`
- `effective_timezone`
- `effective_timezone_source`
- `operational_context`

## 14. Continuidad de conversación

Hay dos niveles de continuidad:

### A. Continuidad interna de SA

SA conserva:

- `conversation_id`
- `messages`
- `context_messages`
- `summary`
- `traces`
- `data_to_save`
- `llm_response_id`

Ese estado sirve para:

- recuperar el hilo
- conservar slots ofrecidos
- guardar contexto externo
- postprocesar resultados de tool calls

### B. Continuidad de OpenAI

Cuando el runtime usa OpenAI Responses API, también puede reutilizar:

- `previous_response_id`
- `lastOpenAiResponseId` / `last_openai_response_id`

Eso ayuda a mantener continuidad del hilo LLM, pero no sustituye al estado estructurado del runtime.

### Regla práctica

- `previous_response_id` ayuda a continuar una conversación
- `context_messages`, `operational_context` y `appointment_context` son la verdad operativa
- si el modelo se desvía, SA sigue postprocesando y corrigiendo el resultado final

## 15. Offered slots y `selected_slot`

Cuando `appointment_availability` devuelve slots, SA conserva ese resultado como contexto estructurado en `context_messages` y en `conversation.appointment_context`.

Un slot típico puede verse así:

```json
{
  "start": "2026-06-16T19:30:00+01:00",
  "end": "2026-06-16T21:00:00+01:00",
  "owner": {
    "id": "019eb05e-5bab-7038-877b-3c73f988ab68",
    "name": "María Gutiérrez"
  }
}
```

Con ese bloque, SA puede resolver follow-ups como:

- `Prefiero el de las 19:30 con María Gutiérrez.`

### Selección determinista

SA resuelve `selected_slot` de forma determinista cuando el mensaje es inequívoco:

- hora + owner correcto -> `selected_slot`
- hora única sin owner -> `selected_slot`
- hora ambigua sin owner -> no `selected_slot`
- owner incorrecto -> no `selected_slot`

### Campo de acción obligatoria

Cuando el slot es confirmable, SA añade `required_next_action` para reforzar al LLM que debe llamar `appointment_confirm` inmediatamente con el slot seleccionado.

## 16. Confirmación de cita

La confirmación de cita sigue un patrón seguro:

1. el usuario elige un slot inequívoco
2. SA prepara `selected_slot` y `required_next_action`
3. el LLM llama `appointment_confirm`
4. SA inspecciona `mcp_tool_traces`
5. si `ok=true` y `confirmed=true`, SA reescribe la reply final a una confirmación clara
6. si falla, SA no afirma que la cita quedó cerrada

Regla de postproceso:

- si la tool confirma con éxito, la respuesta final debe ser explícita, breve y sin duplicar mensajes del tool
- si la tool devuelve error o `confirmed=false`, SA no debe decir que la cita está reservada

## 4. Qué contexto viene de BD/backend

### Tenant

En [backend/src/Entity/Tenant.php](/home/fede/www/sales-agent/backend/src/Entity/Tenant.php) y en `InternalCommercialContextController` se usa:

- `business_context`
- `tone`
- `sales_policy`
- `whatsapp_phone_number_id`
- `whatsapp_public_phone`
- `handoff`

### Product

En [backend/src/Entity/Product.php](/home/fede/www/sales-agent/backend/src/Entity/Product.php):

- `name`
- `slug`
- `description`
- `value_proposition`
- `base_price_cents`
- `currency`
- `external_source`
- `external_reference`
- `sales_policy`

### Playbook

En [backend/src/Entity/Playbook.php](/home/fede/www/sales-agent/backend/src/Entity/Playbook.php):

- `name`
- `config`
- `product_id`

### Entry point

En [backend/src/Entity/EntryPoint.php](/home/fede/www/sales-agent/backend/src/Entity/EntryPoint.php):

- `code`
- `name`
- `description`
- `initial_message`
- `crm_branch_ref`
- relación con `Product`

### ExternalTool / MCP

En [backend/src/Entity/ExternalTool.php](/home/fede/www/sales-agent/backend/src/Entity/ExternalTool.php):

- `server_label`
- `server_url`
- `allowed_tools`
- `require_approval`
- `enabled_for_llm`
- `timeout_seconds`
- `is_runtime_default`
- `bearer_token` cifrado

### Runtime settings / AI policy

En [backend/src/Controller/Api/InternalRuntimeSettingsController.php](/home/fede/www/sales-agent/backend/src/Controller/Api/InternalRuntimeSettingsController.php) y [backend/src/Controller/Api/InternalAiUsageController.php](/home/fede/www/sales-agent/backend/src/Controller/Api/InternalAiUsageController.php):

- modelos
- límites
- provider profile
- audio
- policy por tenant

### Conversation

En [backend/src/Entity/Conversation.php](/home/fede/www/sales-agent/backend/src/Entity/Conversation.php):

- `summary`
- `lastOpenAiResponseId`
- `lastOpenAiResponseAt`
- `status`
- `customerPhone`
- `customerName`
- `entryPoint`
- `product`

En [backend/src/Entity/ConversationMessage.php](/home/fede/www/sales-agent/backend/src/Entity/ConversationMessage.php):

- `direction`
- `role`
- `messageType`
- `body`
- `provider`
- `model`
- `latencyMs`
- `intent`
- `score`
- `action`
- `needsHuman`
- `rawPayload`
- `metadata`

## 5. Qué está hardcodeado en código

La mayor parte de las reglas conversacionales vive en [api/app/services/agent_orchestration/prompts.py](/home/fede/www/sales-agent/api/app/services/agent_orchestration/prompts.py).

### Reglas generales hardcoded

- responder en español
- devolver solo JSON válido
- no inventar precios, plazos ni funcionalidades
- pedir 1-2 datos si el precio no está claro
- marcar `needs_human=true` cuando se pide humano/persona/asesor/comercial
- mantener continuidad usando contexto previo relevante
- no arrastrar detalles irrelevantes

### Reglas condicionales por MCP

Se activan solo si `mcp_config.enabled` y la tool está en `allowed_tools`.

- `contact_context`
- `services_search`
- `appointment_availability`
- `appointment_events`
- `appointment_confirm`
- `appointment_booking_invitation`
- `handoff_request`
- `crm_contact_submit`

### `contact_context`

Solo se guía si la tool está autorizada.

Regla actual:

- si hay teléfono o email, usarlo primero para detectar lead/customer existente
- si no devuelve contexto suficiente, seguir cualificando de forma natural

### `crm_contact_submit`

Solo se guía si la tool está autorizada.

Regla actual:

- usarla cuando haya información comercial útil, cualificación, cita, handoff, waitlist, resumen o datos nuevos relevantes
- `source` y `channel` son el canal/origen comercial, no el tenant
- nunca usar `tenant_id` como `source`
- en WhatsApp usar `source="whatsapp"` y `channel="whatsapp"`
- si no se conoce el canal, usar el canal del contacto o conversación si existe, o omitirlo antes que inventar `tenant_id`
- `tenant_id` solo si la tool lo pide como argumento separado
- CRM decide si el resultado termina como lead/customer/note

### `services_search`

Reglas actuales:

- usar queries cortas y amplias
- `bookable=null` por defecto
- `bookable=true` solo si hay intención clara de reserva
- usar `item.id` como `service_id` canónico cuando exista
- `service_ref` solo como fallback

### `appointment_*`

Reglas actuales:

- si `appointment_availability` está disponible y el usuario pide reservar/agendar/consultar disponibilidad, usarla
- si `appointment_events` está disponible y el usuario pregunta por citas registradas, usarla
- no decir que no se puede consultar agenda cuando esas tools existen

### `handoff_request`

Reglas actuales:

- disponible cuando la estrategia de handoff del tenant es `n8n_webhook` o `manual_wa_link_and_n8n`
- incluir contexto útil sin mandar el historial completo
- no afirmar que se avisó nada si falla

## 6. MCP/tools

### Cómo se obtiene MCP config

`BackendClient.fetch_mcp_config()` llama a:

`GET /api/internal/mcp/{tenantId}/config`

### Cómo `allowed_tools` limita tools

`allowed_tools` vive en `ExternalTool.config` y llega a `McpRemoteConfig.allowed_tools`.

En el prompt, la lógica condicional solo añade guías si la tool aparece en `allowed_tools_list`.

En OpenAI Responses API, `allowed_tools` también se envía dentro del bloque MCP remoto.

### Cómo OpenAI recibe remote MCP

`LLMClient._build_openai_mcp_tools()` arma el bloque `tools` para `/responses`.

### Cómo se pasa downstream authorization

`LLMClient._mcp_authorization_token()` elige el token downstream y lo serializa como `authorization` para OpenAI Responses.

Ese token:

- no se manda al prompt
- no se manda como argumento de tool
- no se registra en traces visibles

### Qué se guarda en `mcp_tool_traces`

`mcp_tool_traces` entra en `data_to_save` cuando OpenAI devuelve `output` con traces MCP.

### Ejemplo de tools

- `contact_context`
- `services_search`
- `appointment_availability`
- `appointment_events`
- `appointment_confirm`
- `appointment_reschedule`
- `appointment_cancel`
- `appointment_booking_invitation`
- `handoff_request`
- `crm_contact_submit`

## 7. CRM context/sync

Regla actual:

- SA no crea lead/customer directamente
- SA puede consultar contexto con `contact_context` si la tool está disponible
- SA puede enviar contexto con `crm_contact_submit` si la tool está disponible
- CRM decide lead/customer/note según su configuración
- `source/channel` son canal/origen comercial
- WhatsApp usa `source=whatsapp`, `channel=whatsapp`
- `tenant_id` no debe usarse como source
- requiere downstream CRM token con scope `contacts:write`

La documentación contractual relacionada vive en [docs/crm-contract.md](/home/fede/www/sales-agent/docs/crm-contract.md).

## 8. Conversación, memoria y summaries

### `conversation.last_messages`

En el request del runtime es `list[str]`.

Se sanea en `api/app/schemas/agent.py`:

- se eliminan strings vacíos
- se recortan espacios
- el prompt usa el helper de contexto para limitar cantidad y tamaño

### Summaries

SA carga contexto de conversación desde `BackendClient.get_conversation_summary_context()` para construir el histórico que ve el LLM.

### `previous_response_id`

Se usa solo cuando:

- `openai_conversation_state_enabled` está activo
- la conversación está `active`
- el id anterior empieza por `resp_`
- no expiró el TTL

### Impacto en el contexto

`previous_response_id` se pasa a OpenAI Responses API.
No reemplaza el prompt ni el contexto de backend.

## 9. `data_to_save`

`data_to_save` sale de `AgentRuntime._build_agent_response()` y se mezcla con telemetría y metadatos del runtime.

Suele incluir:

- payload de la decisión del LLM
- telemetry (`provider`, `model`, `response_id`, tokens, cost, latencia)
- `mcp_tool_traces`
- `mcp_enabled`
- `mcp_server_label`
- `mcp_server_url`
- `mcp_allowed_tools`
- `mcp_require_approval`
- `mcp_errors`
- `mcp_skipped_reason`
- `openai_previous_response_id_invalid`
- datos de contexto operativo como `tenant_id`, `entry_point_id`, `product_slug`, etc. cuando se agregan desde el runtime/decision engine

`data_to_save` no equivale a guardar en CRM. Es contexto operativo para persistencia interna y trazabilidad.

## 10. Fallos y fallback

Comportamiento documentado cuando:

- no hay commercial context: el runtime puede continuar con fallback local o heurístico según el caso
- no hay MCP config: `McpRemoteConfig.enabled=false`
- MCP está deshabilitado: se sigue sin MCP
- MCP no responde: `BackendClient.fetch_mcp_config()` devuelve MCP deshabilitado
- `allowed_tools` no contiene una tool: la guía no entra en el prompt y OpenAI no recibe esa tool en el allowlist
- OpenAI falla: `LLMClient.generate_with_mcp()` devuelve un fallo estructurado del proveedor tras reintentos limitados
- provider no es OpenAI: MCP remoto se omite
- no hay downstream authorization: el MCP puede seguir sin `authorization` o con token ausente según configuración
- CRM no está integrado: el flujo sigue con contexto local
- no hay agenda: las reglas agenda/handoff siguen con fallback textual
- no hay producto local: puede activarse fallback a MCP de servicios si está permitido

## 11. Ejemplo resumido real

Caso conceptual:

WhatsApp lead pregunta por láser axilas
-> SA identifica tenant por `phone_number_id`
-> carga commercial context de Mary
-> carga MCP config `mary_main_mcp`
-> LLM usa `services_search` / `appointment_availability` / `crm_contact_submit`
-> CRM crea lead y nota
-> la respuesta persiste tool traces en `data_to_save`

No se incluyen tokens reales.

## 12. Checklist para futuras modificaciones

- ¿La regla es global o condicional?
- ¿Depende de `allowed_tools`?
- ¿Funciona sin CRM?
- ¿Funciona sin MCP?
- ¿Está cubierta por test?
- ¿Se evita exponer tokens?
- ¿Se mantiene CRM como fuente de verdad?
- ¿Se documentó si es hardcoded o configurable?

## Dudas / TODO

- `previous_response_id` depende de `openai_conversation_state_enabled`; no se documentó aquí dónde se configura ese flag en backend porque no fue necesario para el flujo principal.
- El backend de summaries usa `GET /api/internal/conversations/{conversation_id}/summary-context`; si cambia la forma del contexto, este documento deberá actualizarse.
- No se documenta aquí la semántica completa de `conversation.message.metadata` porque depende de la capa de persistencia y de futuras integraciones.
