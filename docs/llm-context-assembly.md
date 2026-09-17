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
-> construcción de `backend_context` y `conversation_context`
-> primary LLM con catálogo MCP estable y capacidad de invocación limitada a las read tools autorizadas
-> respuesta final directa si no se autoriza ninguna escritura
-> o gating mecánico por `intent` + `action`
-> write continuation mediante `previous_response_id`, con reads y exactamente una write autorizada
-> validación estructural mínima
-> persistencia outbound y `data_to_save`

La mayoría de los turnos requieren una sola llamada conversacional al LLM.
Solo los turnos que autorizan una escritura requieren una continuación.

Sales Agent no ejecuta una etapa LLM separada de clasificación antes del primary.

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

El primary recibe además un `tool_plan` de solo lectura.

No existe un bloque semántico paralelo generado por un planner previo.

### Reglas de composición

- `current_message` aparece una sola vez.
- `current_message` no debe duplicarse dentro de `history` ni dentro de resúmenes.
- `history` y resúmenes deben ser cronológicos.
- `history` solo contiene turnos previos a `current_message`.
- `structured_data` y `tool_results` van pegados al turno en el que se produjeron.
- `conversation_context.history` es la base de continuidad; no existe `runtime_context` ni `latest_structured_data` como tercera fuente de verdad.
- La semántica del turno procede del `LLMFinalResponse` del primary: `domain`, `intent`, `action` y `structured_data`.

### Compatibilidad temporal

`data_to_save` puede conservar un `intent_plan` derivado mecánicamente del resultado del primary para compatibilidad y diagnóstico.

Ese objeto:

- no procede de una llamada LLM separada;
- no es una etapa de razonamiento;
- no es la autoridad para habilitar escrituras;
- no debe tratarse como una tercera fuente semántica.

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

Las tools de lectura configuradas y autorizadas están disponibles para el primary LLM en todos los turnos. El LLM decide si necesita utilizarlas.

Las read tools habituales son:

- `contact_context`
- `services_search`
- `appointment_events`
- `appointment_availability`

Reglas:

- La ausencia temporal de un dato recuperable no bloquea la conversación.
- El LLM puede volver a consultar una read tool si el dato falta, está obsoleto, es contradictorio o necesita verificación externa.
- Una petición explícita de verificar algo en un sistema externo prevalece sobre la regla general de no repetir consultas.
- Para verificar explícitamente en CRM qué cita tiene reservada el contacto, usa `appointment_events`.
- `contact_context` puede resolver identidad, contacto o timezone, pero no sustituye `appointment_events` cuando se pide verificar una cita.
- No uses `appointment_availability` para comprobar una cita ya registrada.
- Cero resultados no es terminal; el LLM puede preguntar o volver a consultar.
- `offered_slots` es contexto de continuidad, no una lista cerrada de verdad absoluta.
- Antes de solicitar una write continuation, el primary debe resolver mediante reads todos los prerrequisitos recuperables necesarios.

## 6. Tools de escritura

El catálogo MCP declarado al primary puede incluir writes configuradas para mantenerse estable durante una eventual continuación. El `tool_choice` del primary limita la capacidad invocable exclusivamente a reads autorizadas, por lo que ninguna write es ejecutable en esa fase.

Cuando su salida estructurada contiene un par `intent` + `action` autorizado, Sales Agent aplica únicamente gating mecánico y puede iniciar una write continuation con `previous_response_id`.

Mappings actuales:

- `request_booking_confirmation` + `confirm_booking` -> `appointment_confirm`
- `request_booking_invitation` + `create_booking_invitation` -> `appointment_booking_invitation`
- `request_reschedule` + `confirm_reschedule` -> `appointment_reschedule`
- `request_cancel` + `confirm_cancel` -> `appointment_cancel`
- `provide_contact_data` + `create_or_update_crm_contact` -> `crm_contact_submit`
- `request_quote` + `create_or_update_crm_contact` -> `crm_contact_submit`
- `request_handoff` + `handoff_to_human` -> `handoff_request`

La continuación recibe:

- las read tools configuradas y autorizadas;
- exactamente una write tool autorizada;
- el `previous_response_id` del primary.

Reglas:

- `prepare_*` no autoriza escrituras.
- Seleccionar un slot, identificar una cita o pedir confirmación no ejecuta una escritura.
- La confirmación explícita posterior es la que puede autorizar `appointment_confirm`, `appointment_reschedule` o `appointment_cancel`.
- `appointment_booking_invitation` puede autorizarse ante una petición explícita de enlace/invitación sin exigir `selected_slot`.
- Si una tool de escritura falla o no existe evidencia estructurada de éxito, la respuesta no debe afirmar éxito.
- Autorización y ejecución son evidencias distintas: `write_authorization` demuestra gating y una traza MCP real demuestra ejecución.
- Una continuación aceptada debe contener exactamente una llamada a la write autorizada y ninguna llamada a otra write.

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

### Invitación de reserva

- Una invitación multiservicio se crea con una única llamada a `appointment_booking_invitation` y todos los `service_ids` seleccionados; SA no crea una invitación por servicio.
- En multiservicio SA omite una duración agregada y deja que CRM/tool resuelva duración y buffers.
- La timezone fiable del CRM/contacto prevalece sobre fallbacks locales. Si `contact_context` devuelve una timezone válida durante la misma sesión MCP, puede actualizar la timezone efectiva usada por las tools de agenda posteriores.
- El resultado normalizado se conserva en `structured_data.appointment.booking_invitation`.
- La invitación solo se considera utilizable cuando downstream aporta evidencia estructurada coherente de éxito: `ok=true`, `created=true` y `booking_url` no vacío.
- SA transporta `booking_url` como dato autoritativo de downstream; no construye, corrige ni reescribe el host público.

### Citas existentes

- Para contexto general puede usarse `contact_context`; para consultar o verificar explícitamente en CRM una cita ya registrada, usa `appointment_events`.
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

### Primary

`AgentRuntime._execute_primary_turn()` recibe:

- `backend_context`
- `conversation_context`
- `tool_plan` de solo lectura
- `mcp_config` con el catálogo MCP configurado completo y estable
- `tool_choice` limitado a las read tools autorizadas

El primary interpreta el mensaje actual junto con el historial, puede usar únicamente reads y devuelve un `LLMFinalResponse`.

Si su `intent` + `action` no autoriza ninguna write, ese resultado termina el turno.

### Write continuation

Cuando el primary autoriza una escritura, Sales Agent:

1. aplica el mapping mecánico `intent` + `action` -> write tool;
2. habilita las reads y exactamente esa write;
3. continúa mediante `previous_response_id`;
4. no expone la reply provisional del primary;
5. devuelve al cliente el resultado final de la continuación.

La continuación no puede solicitar otra autorización de escritura. Los prerrequisitos recuperables deben haber sido resueltos por el primary antes de autorizarla.

### OpenAI Responses y MCP

`LLMClient.generate_with_mcp()` y `LLMClient.generate()` aceptan `response_format`, pero el runtime no impone schemas estrictos por intent.

`LLMClient._build_openai_mcp_tools()` construye el MCP remoto con:

- `server_label`
- `server_url`
- `allowed_tools`
- `require_approval`
- `authorization`

El catálogo de `allowed_tools` del servidor MCP se mantiene igual entre primary y write continuation. La capacidad efectiva se acota con `tool_choice`: `allowed_tools` de solo lectura en primary, una MCP write exacta al iniciar la continuación y de nuevo solo reads después de aprobar esa write. Un retry rechazado puede volver a seleccionar únicamente esa misma write autorizada.

El token downstream no va al prompt ni a `data_to_save`.

Cuando `contact_context` actualiza la timezone efectiva durante el primary, esa configuración MCP efectiva se propaga a una eventual write continuation.

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

El sistema de debug se activa con `SA_LLM_CONTEXT_DEBUG`.

Los artefactos se organizan por conversación y turno bajo `SA_LLM_CONTEXT_DEBUG_DIR`.

### Primary

Todos los turnos LLM normales generan:

- `01-primary-request.json`
- `01-primary-system-prompt.txt`
- `01-primary-user-prompt.txt`
- `02-primary-response.json`

`01-primary-request.json` permite revisar `backend_context`, `conversation_context`, `primary_tool_plan`, catálogo MCP declarado, `primary_invocable_tools`, `tool_choice`, `post_approval_tool_choice` y bootstrap read cuando aplica.

`02-primary-response.json` contiene el `final_response` del primary y sus `tool_traces`.

El primary puede declarar writes en el catálogo estable, pero debe mantener cero writes invocables.

### Write continuation

Solo existe cuando hay `write_authorization`.

Artefactos:

- `03-write-continuation-request.json`
- `03-write-continuation-system-prompt.txt`
- `03-write-continuation-user-prompt.txt`
- `04-write-continuation-response.json`

`03-write-continuation-request.json` permite revisar autorización, write tool exacta, `write_tool_plan`, tools MCP anunciadas, `previous_response_id` y `tool_choice`.

`04-write-continuation-response.json` contiene la respuesta final y sus `tool_traces`.

La ausencia de artifacts 03/04 es normal cuando el turno no autoriza ninguna write.

### Autorización vs ejecución

Una tool anunciada no demuestra ejecución.

- `write_authorization` demuestra que SA autorizó una capability.
- `write_tool_plan` demuestra qué capability fue expuesta.
- `tool_traces` demuestra qué operación MCP se ejecutó realmente.

Estas evidencias no son intercambiables.

### Verificar una escritura

1. Revisar `02-primary-response.json`.
2. Confirmar el `intent` + `action` que autorizó la operación.
3. Revisar `data_to_save.write_authorization`.
4. Revisar `03-write-continuation-request.json`.
5. Confirmar que el primary no tenía writes.
6. Confirmar que la continuación expuso exactamente la write autorizada.
7. Revisar `04-write-continuation-response.json`.
8. Confirmar exactamente una traza MCP de la write autorizada y ninguna otra write.
9. Revisar argumentos, status y output reales.
10. Confirmar que la respuesta final refleja el resultado downstream real.

Nunca repetir una escritura únicamente porque un resumen auxiliar esté vacío. Ante un resultado incierto, revisar primero las trazas ya persistidas.

### Orden recomendado de diagnóstico

1. `01-primary-request.json`
2. `02-primary-response.json`
3. `write_authorization`, si existe
4. `03-write-continuation-request.json`, si hubo write
5. `04-write-continuation-response.json`, si hubo write
6. `tool_traces`
7. persistencia del turno

### Seguridad

- No registrar tokens, bearer tokens ni secretos.
- No usar el debug para repetir escrituras.
- No confundir tools anunciadas con llamadas MCP ejecutadas.
- Ante una write de resultado incierto, revisar primero la evidencia persistida.

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
