# Contrato de CRM

Este documento define el comportamiento esperado de `sales-agent` cuando trabaja integrado con CRM.

La regla principal es simple:

- `sales-agent` conversa, cualifica y estructura contexto.
- el CRM es el sistema maestro de contactos, leads, clientes, agenda, notas, actividades y pipeline.
- `sales-agent` no debe decidir internamente si crear lead, cliente, nota o cambio de estado CRM.
- si no hay CRM configurado, el agente debe seguir funcionando con contexto local, playbooks y fallback de handoff.

## Principios

- CRM como fuente maestra.
- SA como orquestador conversacional.
- lógica CRM compleja fuera de SA.
- integración segura y tenant-scoped.
- no romper tenants sin CRM.

## Lectura de contexto

Cuando el tenant está integrado con CRM y el agente tiene teléfono o email, el runtime debe consultar primero `contact_context` antes de asumir que el contacto es nuevo.

Objetivo de esa lectura:

- evitar pedir datos ya conocidos
- continuar la conversación con criterio comercial
- detectar señales de lead avanzado, cliente existente o necesidad de humano

Campos útiles que puede devolver el contexto:

- `contact`
- `lead`
- `opportunity`
- `flags`
- `recentNotes`
- `lastActivityAt`
- `summary`

Reglas:

- `phone` debe viajar normalizado cuando exista
- `name` y `email` son opcionales
- SA debe tratar la respuesta como contexto, no como mandato para crear o actualizar entidades
- si no hay contexto útil, SA sigue conversando y cualificando con naturalidad

## Flujo recomendado con CRM

1. Entra una consulta por WhatsApp.
2. SA resuelve tenant, entry point, contexto propio, política comercial y conversación.
3. Si hay teléfono o email y el tenant usa CRM, el LLM debe consultar `contact_context`.
4. Si el CRM devuelve contacto existente, SA usa ese contexto y evita repreguntar.
5. Si no hay contacto o faltan datos básicos, SA cualifica según la política comercial del negocio.
6. Cuando haya información comercial útil, intención de cita, waitlist, handoff, resumen final o datos nuevos relevantes, SA debe llamar a `crm_contact_submit` para entregar contexto estructurado al flujo de sincronización configurado.
7. El CRM decide si crea o actualiza lead, cliente, actividad, nota, agenda o pipeline.

## Información mínima a recopilar antes de sincronizar

SA debería intentar reunir, sin saturar al usuario, este conjunto mínimo cuando aporte valor:

- teléfono, normalmente ya disponible por WhatsApp
- nombre, si no viene del canal
- servicio, producto o interés
- necesidad o motivo de consulta
- si es primera vez o cliente habitual, si aplica
- disponibilidad aproximada si quiere cita
- resumen breve de la conversación
- intención detectada
- si requiere humano
- si se ofreció reserva, waitlist o handoff
- `external_conversation_id`, canal y `entry_point` cuando estén disponibles

## Uso de tools y servicios externos

El runtime puede usar otras tools MCP según la conversación:

- servicios
- disponibilidad
- reservas
- waitlist
- handoff
- contexto de contacto

La integración CRM no debe acoplar a SA a decisiones como:

- `lead_upsert`
- `customer_upsert`
- `conversation_summary_upsert`
- `contact_activity_create`

Esas decisiones pertenecen al CRM.

## Tool de sincronización CRM

La tool de escritura comercial recomendada es `crm_contact_submit`.

Uso previsto:

- enviar contexto comercial útil hacia n8n/CRM
- no consultar contexto
- no decidir si crear lead, customer, note o activity

Requisitos:

- downstream authorization tenant-scoped con scope `contacts:write`
- payload sin secretos
- separación clara entre auth de transporte y autorización downstream

Endpoint operativo esperado:

- `POST /api/integrations/contacts`

Ese contrato debe transportar contexto, no reglas rígidas de SA.

Debe permitir que el CRM decida si el resultado acaba en:

- lead
- customer
- note
- activity
- timeline
- pipeline

Campos recomendados para `crm_contact_submit`:

- `contact.name`
- `contact.phone` y `contact.email` si existen
- `source` y `channel`
- `qualification`
- `conversation.summary`
- `conversation.intent`
- `actions.booking_requested`, `actions.handoff_requested` y `actions.waitlist_requested` cuando apliquen
- `metadata.origin=sales_agent`
- `metadata.sa_conversation_id` si está disponible
- `metadata.service_slug` y `metadata.service_integration_key` si están disponibles

No usar `crm_contact_submit` en cada mensaje si no hay información nueva útil.
La idea es sincronizar cuando el contacto queda cualificado, se solicita cita, se deriva a humano, se finaliza o resume una conversación, o aparecen datos relevantes nuevos.

## Seguridad

- la autorización downstream es tenant-scoped
- no enviar tokens al prompt
- no enviar tokens como argumento de tool si no hace falta
- no volcar tokens en logs, trazas ni respuestas visibles
- separar auth del webhook y autorización downstream

`ExternalTool.bearer_token` y cualquier token downstream CRM/MCP no deben reutilizarse para webhook de handoff.

## Riesgos a evitar

- crear contactos por conversaciones basura
- pedir datos ya conocidos si `contact_context` ya los devolvió
- sincronizar en cada mensaje sin necesidad
- duplicar reglas CRM en SA
- romper tenants sin CRM

## Relación con otros documentos

- [MCP - n8n contact context](mcp-n8n-contact-context.md)
- [Handoff humano](handoff.md)
- [TODO del proyecto](todo.md)
