# Ensamblado de contexto LLM

Este documento describe el contrato real actual de `sales-agent` para construir contexto, seleccionar tools y ejecutar el flujo LLM-led.

No es una propuesta futura. Es una referencia operativa del comportamiento vigente.

## 1. Flujo general

`POST /agent/respond`
-> `runtime.respond()`
-> routing y resolución de tenant/entrypoint/canal
-> `backend_client.fetch_tenant_context()`
-> `backend_client.fetch_mcp_config()`
-> `runtime_settings_client.effective_values()`
-> persistencia de inbound
-> carga de conversación y contexto
-> primera llamada LLM para clasificar intención
-> construcción del `tool_plan`
-> segunda llamada LLM con contexto y tools permitidas
-> ejecución MCP si aplica
-> validación estructural mínima
-> persistencia outbound y `data_to_save`

## 2. Archivos principales

### FastAPI runtime

- [api/app/services/runtime.py](/home/fede/www/sales-agent/api/app/services/runtime.py)
- [api/app/services/backend_client.py](/home/fede/www/sales-agent/api/app/services/backend_client.py)
- [api/app/services/routing_resolver.py](/home/fede/www/sales-agent/api/app/services/routing_resolver.py)
- [api/app/services/llm_client.py](/home/fede/www/sales-agent/api/app/services/llm_client.py)
- [api/app/services/runtime_settings_client.py](/home/fede/www/sales-agent/api/app/services/runtime_settings_client.py)
- [api/app/schemas/agent.py](/home/fede/www/sales-agent/api/app/schemas/agent.py)
- [api/app/schemas/llm.py](/home/fede/www/sales-agent/api/app/schemas/llm.py)

### Orquestación LLM

- [api/app/services/agent_orchestration/prompts.py](/home/fede/www/sales-agent/api/app/services/agent_orchestration/prompts.py)
- [api/app/services/agent_orchestration/schemas.py](/home/fede/www/sales-agent/api/app/services/agent_orchestration/schemas.py)
- [api/app/services/agent_orchestration/tool_selector.py](/home/fede/www/sales-agent/api/app/services/agent_orchestration/tool_selector.py)
- [api/app/services/agent_orchestration/context_builder.py](/home/fede/www/sales-agent/api/app/services/agent_orchestration/context_builder.py)

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

## 3. Contexto canónico

Los bloques semánticos canónicos son:

- `backend_context`
- `conversation_context`

El prompt final también incorpora:

- `intent_plan`
- `tool_plan`

El prompt de clasificación usa los mismos nombres canónicos con menos carga.

### Reglas de composición

- `current_message` aparece una sola vez.
- `current_message` no debe duplicarse dentro de `history` ni dentro de resúmenes.
- `history` y resúmenes deben ser cronológicos.
- `history` solo contiene turnos previos a `current_message`.
- `structured_data` y `tool_results` van pegados al turno en el que se produjeron.
- `conversation_context.history` es la base de continuidad; no existe `runtime_context` ni `latest_structured_data` como tercera fuente de verdad.

### Ventana actual

La implementación actual carga hasta 12 mensajes previos al construir el contexto conversacional.

## 4. Prioridad de contexto

La prioridad de razonamiento es guía semántica, no una máquina rígida:

1. `current_message` interpretado con `history` cronológico.
2. `structured_data` y `tool_results` unidos al turno donde nacieron.
3. Resultados recientes de tools de escritura y los turnos que reflejan ese cambio.
4. `backend_context` como snapshot operativo del turno.
5. Una nueva lectura MCP cuando el dato falta, contradice el historial o puede estar obsoleto.

Reglas prácticas:

- La información más reciente, explícita y fiable vence sobre contexto viejo.
- `backend_context` puede quedar temporalmente obsoleto después de una escritura exitosa.
- Un dato recuperable ausente no es un error terminal.
- Si hay duda relevante, el LLM debe preguntar o volver a consultar una tool de lectura.
- No se deriva a una persona solo porque falte un dato recuperable o porque haga falta aclaración.

## 5. Tools de lectura

Las tools de lectura configuradas y autorizadas están disponibles para el LLM en todos los turnos. El LLM decide si necesita utilizarlas.

Puede consultarlas cuando falte información, exista ambigüedad, haya contradicción, el contexto previo pueda estar incompleto o desactualizado, o el usuario solicite verificar información externa.

Las read tools habituales son:

- `contact_context`
- `services_search`
- `appointment_events`
- `appointment_availability`

Reglas:

- La ausencia temporal de un dato recuperable no bloquea la conversación.
- El LLM puede volver a consultar una read tool en turnos posteriores si el usuario aporta nuevos datos o si necesita verificar el estado actual.
- Una petición explícita de verificar o comprobar algo en un sistema externo prevalece sobre la regla general de no repetir consultas.
- Para verificar una cita existente, prioriza `contact_context` o `appointment_events`.
- No uses `appointment_availability` para comprobar una cita ya registrada.
- `appointment_availability` y `appointment_events` pueden reconsultarse.
- Cero resultados no es terminal; el LLM puede volver a preguntar o pedir datos.
- `offered_slots` no es una lista cerrada de verdad absoluta; es contexto útil para seguir conversando.

## 6. Tools de escritura

Las tools de escritura se exponen solo cuando el plan estructurado lo permite.

Combinaciones actuales:

- `request_booking_confirmation` + `confirm_booking` -> `appointment_confirm`
- `request_reschedule` + `confirm_reschedule` -> `appointment_reschedule`
- `request_cancel` + `confirm_cancel` -> `appointment_cancel`

Reglas:

- `prepare_*` no autoriza escrituras.
- Seleccionar un slot, identificar una cita o pedir confirmación no ejecuta la escritura.
- La escritura solo puede ejecutarse cuando el turno actual contiene una confirmación inequívoca y la tool está disponible.
- Si una tool de escritura falla, la respuesta no debe afirmar éxito.
- La selección de un horario de reprogramación no es una reserva nueva.
- La selección válida prepara la propuesta; la confirmación posterior autoriza la escritura.

## 7. Response contract

La respuesta final sigue un contrato abierto por defecto.

Reglas:

- No se usan response formats estrictos por intent para todo el flujo.
- El formato efectivo final suele ser `json_object`.
- La ausencia de `selected_slot`, `offered_slots`, `existing_appointment` u otros campos no se inventa ni se completa por postprocesado.
- Si faltan datos, el LLM debe preguntar o usar una lectura MCP.
- No se fuerza `structured_data` a contener campos cerrados cuando el estado aún no está resuelto.
- `response_format` no debe convertirse en una máquina de estados por intent.

## 8. Agendas y citas

### Selección de servicios

- El contrato mantiene compatibilidad singular mediante `service_id`, `service_name`, `service_ref` y `structured_data.services.selected_service`.
- Una selección de varios servicios se representa mediante `service_ids`, `service_names` y `structured_data.services.selected_services`.
- Para una selección efectiva singular se usa `selected_service`; para una selección plural se usa la lista completa `selected_services`. Una selección plural nunca se reduce al primer elemento.
- La proyección compacta de `conversation_context.history` conserva únicamente la continuidad estructurada necesaria de servicios seleccionados y slots ofrecidos/seleccionados; no crea un estado paralelo ni reconstruye semántica desde texto.
- Varios servicios de una reserva representan una sola visita y una sola operación de agenda. Las tools compatibles reciben conjuntamente `service_ids`.
- CRM/tool es la fuente de verdad para duración efectiva, buffers y disponibilidad. SA no suma duraciones de servicios ni inventa una duración agregada.

### Disponibilidad

- Si el usuario pide reservar, agendar o consultar disponibilidad, puede usarse `appointment_availability` si está autorizada.
- `appointment_availability` devuelve contexto útil para conversación y seguimiento.
- El hecho de tener slots ofrecidos no obliga a confirmar una escritura.

### Citas existentes

- Para consultar una cita ya registrada, usa `contact_context` o `appointment_events`.
- Si el usuario pide comprobar el estado o la fecha de una cita existente, consulta la fuente externa relevante aunque el historial ya tenga una pista fiable.
- Si la lectura externa contradice el historial, prevalece el resultado actual de la tool.

### Reprogramación

- Seleccionar un nuevo horario no confirma la reprogramación.
- La selección válida debe presentarse como propuesta pendiente de confirmación explícita.
- Solo una respuesta posterior e inequívoca del usuario puede autorizar `appointment_reschedule`.

### Cancelación

- Antes de cancelar, identifica la cita mediante historial o `appointment_events`.
- Si existe una sola cita compatible, la conversación debe pedir confirmación explícita antes de ejecutar la escritura.
- Si hay varias, el LLM debe pedir aclaración.
- Si no hay cita, no se inventa `appointment_id`.

## 9. Llamadas LLM

### Clasificación

`AgentRuntime._classify_intent()` prepara el prompt de intención con:

- contexto de backend;
- contexto conversacional;
- resumen temporal;
- historial previo;
- mensaje actual.

### Ejecución final

`AgentRuntime._execute_llm_turn()` construye:

- `backend_context`
- `conversation_context`
- `intent_plan`
- `tool_plan`
- `mcp_config` filtrada por tools permitidas

La segunda llamada puede usar `previous_response_id` cuando el flujo de OpenAI Responses lo permite.

### OpenAI Responses y MCP

`LLMClient.generate_with_mcp()` y `LLMClient.generate()` aceptan `response_format`, pero el runtime actual no impone schemas estrictos por intent.

`LLMClient._build_openai_mcp_tools()` arma el bloque MCP remoto con:

- `server_label`
- `server_url`
- `allowed_tools`
- `require_approval`
- `authorization`

El token downstream no va al prompt ni a `data_to_save`.

## 10. Persistencia

`AgentRuntime.respond()` persiste:

- inbound
- outbound
- `structured_data`
- `tool_results`
- intent
- action
- metadatos de uso de IA

`data_to_save` es trazabilidad interna, no una fuente semántica independiente.

## 11. Debug y artefactos

El sistema de debug existente se activa con `SA_LLM_CONTEXT_DEBUG`.

- Valor por defecto del setting: `false` en [api/app/config.py](/home/fede/www/sales-agent/api/app/config.py).
- Sobrescritura local y Docker: `SA_LLM_CONTEXT_DEBUG=true` en [.env](/home/fede/www/sales-agent/.env) y en `docker-compose.yml`.
- Ruta base del runtime: `SA_LLM_CONTEXT_DEBUG_DIR`.
- Valor por defecto del path: `/tmp/sa-llm/context` en [api/app/config.py](/home/fede/www/sales-agent/api/app/config.py).
- Valor Docker/local habitual: `/app/var/sa-llm/context` en [.env](/home/fede/www/sales-agent/.env) y `docker-compose.yml`.
- Si el directorio no existe, el runtime lo crea con `mkdir(parents=True, exist_ok=True)`.
- La nomenclatura por conversación y turno usa `conversation_id` o `external_conversation_id` y un `turn_slug` derivado de `message.id` o `message.timestamp`.

Cuando el debug está habilitado, se guardan artefactos por turno en la ruta base configurada. Los nombres existentes son:

- `01-intent-request.json`
- `01-intent-system-prompt.txt`
- `01-intent-user-prompt.txt`
- `02-intent-response.json`
- `03-final-request.json`
- `03-final-system-prompt.txt`
- `03-final-user-prompt.txt`
- `04-final-response.json`

Cómo inspeccionarlo:

- `01-intent-request.json`: contexto exacto enviado a la clasificación.
- `02-intent-response.json`: intención y acción devueltas.
- `03-final-request.json`: contexto final, tools permitidas y `response_format`.
- `04-final-response.json`: respuesta final y `tool_traces`.
- Los archivos `.txt` contienen los prompts exactos enviados a OpenAI.
- El campo `llm_context_debug` en `data_to_save` referencia los archivos generados para ese turno.

El probe manual usa otro directorio distinto:

- `var/sa-llm/probe/`
- `OUT_DIR` por defecto en `scripts/e2e/agent_conversation_probe.sh`: `./var/sa-llm/probe`
- Cada turno se guarda como `sa-<CONV>-<step>.json`
- El probe imprime la respuesta, el tool plan, el estado de agenda y las trazas MCP del turno

### Available tools vs executed MCP calls

- Una tool incluida en `tool_plan.allowed_tools` está disponible para el modelo.
- Estar en `allowed_tools` no significa que se haya ejecutado.
- Un catálogo o metadato con `status: null` o equivalente no demuestra una llamada MCP real.
- Una llamada MCP real debe verse como una traza real con nombre de tool, tipo de llamada como `mcp_call` o equivalente real, argumentos, status final y output o error.
- `04-final-response.json` y `tool_traces` son la referencia principal para confirmar ejecución real.
- No asumir ejecución solo porque la tool aparezca anunciada en la request o en el catálogo.

### Verifying an appointment write

Para verificar una escritura real de agenda:

1. Revisar `02-intent-response.json`.
2. Confirmar que `intent` y `action` corresponden a una confirmación explícita: `confirm_booking`, `confirm_reschedule` o `confirm_cancel`.
3. Revisar `03-final-request.json`.
4. Confirmar que la write tool correspondiente aparece en `tool_plan.allowed_tools`.
5. Revisar `04-final-response.json`.
6. Confirmar que existe una llamada MCP real para `appointment_confirm`, `appointment_reschedule` o `appointment_cancel`.
7. Revisar los argumentos exactos enviados.
8. Revisar el status real de la llamada.
9. Revisar el output real del MCP.
10. Confirmar el flag de éxito correspondiente cuando exista: `confirmed=true`, `rescheduled=true` o `cancelled=true`.
11. Confirmar que la respuesta final del LLM refleja el resultado real.
12. Confirmar que el resultado queda disponible en `structured_data`, `tool_results` o `tool_traces`, según la forma real existente.

No repetir una escritura porque el probe no muestre `NORMALIZED RESULTS`.
No repetir `appointment_confirm`, `appointment_reschedule` ni `appointment_cancel` solo porque un resumen esté vacío.
Revisar siempre `04-final-response.json` y la trace MCP real antes de concluir que una escritura no ocurrió.
Durante debug, una escritura exitosa no debe repetirse.

### Practical debug commands

Listar todos los artefactos de contexto:

```bash
find var/sa-llm/context -type f | sort
```

Localizar los artefactos de una conversación:

```bash
find var/sa-llm/context \
  -path '*<conversation-id>*' \
  -type f \
  | sort
```

Buscar una conversación en context y probe:

```bash
grep -Rni '<conversation-id>' \
  var/sa-llm/context \
  var/sa-llm/probe
```

Inspeccionar los JSON principales:

```bash
jq . <turn-dir>/01-intent-request.json
jq . <turn-dir>/02-intent-response.json
jq . <turn-dir>/03-final-request.json
jq . <turn-dir>/04-final-response.json
```

Inspeccionar los prompts exactos:

```bash
sed -n '1,240p' <turn-dir>/01-intent-system-prompt.txt
sed -n '1,240p' <turn-dir>/01-intent-user-prompt.txt
sed -n '1,260p' <turn-dir>/03-final-system-prompt.txt
sed -n '1,260p' <turn-dir>/03-final-user-prompt.txt
```

Ejecutar el probe actual:

```bash
scripts/e2e/agent_conversation_probe.sh "mensaje"
```

Si quieres controlar la conversación o el archivo de salida, el script usa `CONV`, `TENANT_ID`, `CONTACT_PHONE`, `CONTACT_NAME` y `OUT_DIR` como variables de entorno opcionales.

Salida del probe:

- Directorio por defecto: `var/sa-llm/probe/`
- Variable `OUT_DIR`: existe y por defecto apunta a `./var/sa-llm/probe`
- Formato de archivo: `sa-<CONV>-<step>.json`
- Un JSON por turno o paso
- El probe es un resumen de conveniencia
- Los artefactos de `var/sa-llm/context/` son la fuente principal para diagnóstico detallado

### Mapa breve de artefactos

`01-intent-request.json`

- Revisar:
  - payload real enviado a clasificación
  - `backend_context`
  - `conversation_context`
  - `current_message`
  - `history`
  - contexto temporal
  - metadata incluida

`01-intent-system-prompt.txt`

- Revisar:
  - instrucciones exactas del clasificador
  - reglas de `intent` y `action`
  - reglas de contexto y razonamiento

`01-intent-user-prompt.txt`

- Revisar:
  - prompt de usuario exacto para clasificación
  - contexto serializado que vio el modelo

`02-intent-response.json`

- Revisar:
  - `intent`
  - `action`
  - `confidence`
  - `needs_tools`
  - explicación o reason si existe

`03-final-request.json`

- Revisar:
  - `backend_context`
  - `conversation_context`
  - `intent_plan`
  - `tool_plan`
  - `tool_plan.allowed_tools`
  - `response_format`
  - `bootstrap_tool`

`03-final-system-prompt.txt`

- Revisar:
  - reglas operativas del turno final
  - gating de tools
  - reglas de confirmación
  - reglas de razonamiento abiertas

`03-final-user-prompt.txt`

- Revisar:
  - prompt de usuario exacto para el turno final
  - contexto serializado completo que vio el LLM

`04-final-response.json`

- Revisar:
  - respuesta final del LLM
  - `tool_traces`
  - trazas MCP reales
  - `structured_data`
  - `data_to_save`
  - resultado confirmado o fallido

Reglas de seguridad:

- No registrar tokens, bearer tokens ni secretos.
- No usar el debug para repetir escrituras.
- No confundir tools anunciadas con llamadas MCP realmente ejecutadas.
- Si el diagnóstico necesita el estado real, revisar los requests y responses persistidos.

## 12. Checklist de cambios futuros

- ¿La regla depende de `allowed_tools`?
- ¿La semántica viene del LLM y no de heurística SA?
- ¿La lectura puede repetirse si el dato falta o está desactualizado?
- ¿La escritura solo ocurre con confirmación explícita?
- ¿Se preserva la trazabilidad y se redactan secretos?
- ¿El cambio mantiene el flujo recuperable por corrección del usuario?

## 13. Notas operativas

- `conversation_context.history` debe seguir siendo el registro canónico de continuidad.
- Los resultados estructurados pueden ir creciendo por turnos; no hace falta resolver todo de una sola vez.
- `handoff` debe reservarse para política explícita, solicitud del usuario o imposibilidad real de continuar de forma autónoma.
