# TODO del proyecto sales-agent

Lista viva de lo que queda por cerrar en `sales-agent`. Se ha limpiado el histórico para dejar solo trabajo pendiente real.

## Estado actual

Ya está cerrado y validado:

- [x] Integración LLM con OpenAI como proveedor principal.
- [x] Fallback seguro a heurística cuando aplica.
- [x] `commercial-context` interno funcionando desde backend Symfony.
- [x] Resolución comercial de producto antes de playbook.
- [x] MCP remoto validado end-to-end con OpenAI Responses API.
- [x] ExternalTools/MCP configurables por tenant.
- [x] Handoff humano con estrategias y tool MCP.
- [x] Routing multi-tenant por prioridad.
- [x] Audio WhatsApp implementado y medido como `audio_transcription`.
- [x] Gestión IA/tokens y límites por tenant.
- [x] Modelos IA y costes editables desde backend.
- [x] Planes comerciales Starter/Growth/Pro en PLATAFORMA.
- [x] Enforcement base de plan para productos, playbooks, entry points, MCP y audio.
- [x] UI comercial/administrativa principal actualizada.
- [x] Eliminada la integración directa `SA -> CRM` para `contact-context`; el contexto externo queda delegado a herramientas/MCP/n8n.
- [x] Contrato MCP/n8n de `contact_context` ampliado con `business_context`, `timezone`, `timezone_source`, `branch`, `branches` y `needs_branch_selection`.
- [x] `appointment_confirm` endurecido para exigir payload completo antes de confirmar citas.

## 1. Stripe

Objetivo: preparar la monetización real de planes y top-ups IA.

Pendiente:

- [ ] Añadir configuración segura:
  - `STRIPE_SECRET_KEY`.
  - `STRIPE_WEBHOOK_SECRET`.
  - `STRIPE_SUCCESS_URL`.
  - `STRIPE_CANCEL_URL`.
  - modo test/live.
- [ ] Decidir si el catálogo Stripe se crea manualmente o con comando de sync.
- [ ] Crear checkout de suscripción por tenant y plan.
- [ ] Definir si la suscripción es mensual/anual o ambas.
- [ ] Crear webhook Stripe e idempotencia de eventos.
- [ ] Sincronizar estado de suscripción del tenant.
- [ ] Definir top-ups IA vía Stripe.
- [ ] Mantener aprobación manual disponible para casos comerciales.

## 2. Planes comerciales

Objetivo: cerrar detalles operativos del enforcement sin meter facturación real.

Pendiente:

- [ ] Mostrar contadores visibles en listados:
  - productos usados / límite.
  - guías usadas / límite.
  - entry points usados / límite.
  - MCP usados / límite.
- [ ] Mejorar mensajes de upgrade en UI.
- [ ] Añadir estado visual del plan actual en dashboard del tenant.
- [ ] Definir comportamiento ante downgrade:
  - no borrar recursos.
  - permitir editar existentes.
  - impedir crear nuevos.
  - impedir activar recursos bloqueados por plan.
- [ ] Aplicar `monthly_conversations` cuando la persistencia de conversaciones esté estable.
- [ ] Aplicar `whatsapp_numbers` cuando la gestión real de números/canales esté cerrada.
- [ ] Aplicar `conversation_history_days` cuando exista política de retención.
- [ ] Aplicar `advanced_analytics` cuando exista vista de analítica avanzada.
- [ ] Aplicar `priority_support` solo como flag comercial/interno.

## 3. WhatsApp real

Objetivo: cerrar el circuito real `WhatsApp Cloud API -> wa-gateway-api -> sales-agent -> WhatsApp`.

Pendiente:

- [ ] Validar webhook público HTTPS de Meta apuntando a `wa-gateway-api`.
- [ ] Resolver tenant por:
  - `entrypoint_ref`.
  - `phone_number_id`.
  - tenant explícito si aplica.
- [ ] Enviar respuesta real por `/messages/send`.
- [ ] Manejar duplicados por `wamid`.
- [ ] Manejar errores de Meta y de SA.
- [ ] Loguear por `message_id`, tenant y conversación.
- [ ] Probar texto real.
- [ ] Probar audio real.
- [ ] Documentar payload final.

## 4. Persistencia completa de conversación

Objetivo: guardar conversaciones reales antes de escalar el canal WhatsApp.

Pendiente:

- [ ] Revisar el modelo actual de `Conversation`.
- [ ] Completar persistencia inbound/outbound.
- [ ] Guardar:
  - tenant.
  - contacto.
  - canal.
  - `wamid`.
  - `wa_id` / teléfono normalizado.
  - `phone_number_id`.
  - `entrypoint_ref`.
  - producto/playbook si aplica.
- [ ] Guardar trazabilidad:
  - provider LLM.
  - modelo.
  - latencia.
  - tokens/coste estimado.
  - intent.
  - score.
  - action.
  - `needs_human`.
  - errores.
  - MCP/tool traces.
- [ ] Evitar conversación sin mensajes asociados.
- [ ] Implementar idempotencia de inbound.
- [ ] Añadir tests de persistencia inbound/outbound.
- [ ] Exponer estado de conversación en UI:
  - activa.
  - pendiente humano.
  - cerrada.
  - error.

## 5. Contexto base, memoria operativa y `contact_context`

Objetivo: que el LLM reciba siempre un contexto útil y compacto antes de responder, tanto en tenants integrados con CRM como en modo SA standalone.

Principios:

- El contexto del negocio debe ser local y obligatorio en SA:
  - nombre del tenant.
  - descripción del negocio.
  - tono.
  - políticas de venta.
  - guías/playbooks.
  - productos/servicios locales si existen.
  - dirección/ubicación.
  - zona horaria del negocio.
  - idioma por defecto.
  - reglas de handoff.
- El contexto del contacto debe ser externo/cacheado cuando exista integración:
  - nombre.
  - teléfono/email conocidos.
  - si es lead/cliente.
  - resumen externo.
  - preferencias.
  - últimas oportunidades/citas si vienen de la fuente externa.
  - sucursal/branch.
  - timezone efectiva.
- SA puede cachear contexto para conversar mejor, pero no debe convertirse en CRM ni fuente maestra del cliente.

Pendiente:

- [ ] Auditar `ExternalToolClient.fetch_contact_context()`:
  - confirmar si llama n8n directo o usa MCP real.
  - si es HTTP directo, dejarlo fuera de la ruta principal MCP o marcarlo como legacy/opcional.
- [ ] Implementar cache persistente de `contact_context` en SA:
  - entidad/tabla sugerida: `ContactContextCache` o `ExternalContactContextCache`.
  - clave por tenant + teléfono/email/conversación.
  - `context_json`.
  - `status`.
  - `source` (`mcp`, `cache`, `fallback`).
  - `fetched_at`.
  - `expires_at`.
- [ ] Añadir TTL configurable:
  - `CONTACT_CONTEXT_CACHE_TTL_MINUTES`.
  - default inicial sugerido: 360 minutos.
- [ ] Implementar `ContactContextResolver`:
  - buscar contexto válido en cache.
  - si existe, inyectarlo al prompt principal.
  - si falta o caducó, obtenerlo vía OpenAI Responses + MCP `contact_context`.
  - guardar resultado normalizado.
  - devolver fallo controlado si no se puede refrescar.
- [ ] Implementar refresh vía MCP/LLM restringido:
  - llamada previa sólo cuando no haya cache válido.
  - intentar restringir tools a `contact_context`.
  - usar `tool_choice=required` si el cliente actual lo soporta.
  - no responder al usuario en esa llamada previa.
- [ ] Inyectar siempre al prompt principal el contexto base compuesto:
  - tenant context local.
  - contact_context cacheado/refrescado si existe.
  - resumen persistido de conversación.
  - últimos mensajes relevantes.
  - datos pendientes/confirmados.
- [ ] Ajustar reglas del prompt:
  - si `contact.name` existe, permitir personalización sin abusar.
  - no pedir teléfono si viene de WhatsApp.
  - no pedir email si ya existe.
  - para agenda usar `business_context.timezone` antes que fallback local.
  - si `needs_branch_selection=true`, preguntar sucursal antes de disponibilidad.
  - no decir “reservado/confirmado” sin `appointment_confirm` exitoso.
- [ ] Invalidar o refrescar cache cuando:
  - `appointment_confirm` confirme una cita.
  - `crm_contact_submit` actualice contacto/resumen.
  - el usuario aporte email/teléfono/nombre nuevo.
  - el usuario seleccione sucursal.
  - expire el TTL.
- [ ] Mantener modo SA standalone:
  - si no hay CRM/MCP/contact_context, usar tenant context local.
  - tenant debe tener dirección y timezone local suficientes.
  - si no hay agenda integrada, activar handoff humano o respuesta controlada.
- [ ] Añadir TODO de campos estructurados en `Tenant` para modo standalone:
  - dirección.
  - ciudad/país.
  - timezone.
  - idioma por defecto.
  - teléfono/email públicos.
  - horarios comerciales opcionales.
- [ ] Añadir tests:
  - cache válido evita refresh.
  - cache caducado fuerza refresh.
  - refresh vía MCP/LLM guarda contexto.
  - prompt recibe contexto cacheado.
  - no se piden datos ya conocidos.
  - `needs_branch_selection` bloquea availability.
  - `appointment_confirm` y `crm_contact_submit` invalidan cache.
  - no hay llamadas directas a CRM desde SA.

## 6. Runtime y resolución tenant/producto/playbook

Objetivo: mantener robusta la selección de tenant y contexto comercial.

Pendiente:

- [ ] Añadir más tests de resolución de tenant por `phone_number_id`.
- [ ] Añadir tests de routing inconsistente.
- [ ] Revisar comportamiento cuando no hay producto local y MCP está caído.
- [ ] Revisar selección de playbook cuando hay múltiples candidatos.
- [ ] Mejorar trazas de `product_selection`.
- [ ] Documentar contrato final de `/agent/respond`.

## 7. MCP / herramientas externas / n8n

Objetivo: usar MCP/n8n como capa de herramientas externas sin convertir SA en CRM/ERP.

Pendiente:

- [ ] Mantener verificación de arquitectura:
  - SA no debe reintroducir `crm_client.py`.
  - SA no debe reintroducir `CRM_BASE_URL` ni `CRM_INTEGRATIONS_BEARER_TOKEN`.
  - SA no debe llamar `/api/integrations/contact-context` directamente.
- [ ] Añadir vista de trazas MCP más cómoda.
- [ ] Añadir test de tools MCP con mock de n8n/CRM.
- [ ] Mejorar mensajes cuando MCP no está configurado o falla.
- [ ] Definir catálogo/inventario externo como tool MCP/n8n genérica.
- [ ] Mantener `crm_contact_submit` como contrato de escritura CRM:
  - enviar resumen persistido de conversación cuando aporte valor.
  - enviar datos cualificados/actualizados del contacto.
  - n8n debe usar `POST /api/integrations/contacts`.
  - evaluar más adelante una variante más genérica (`contact_sync`).
- [ ] Mantener separación de tokens:
  - downstream authorization ≠ webhook token ≠ tokens OpenAI.
- [ ] Evaluar en el futuro una sección separada de integraciones/webhooks operativos directos con n8n para casos deterministas muy puntuales. Por defecto, SA debe seguir delegando tools conversacionales en LLM + MCP remoto. Cualquier integración directa con n8n deberá implementarse como herramienta externa separada, no dentro de Servidores MCP.

## 8. Handoff humano

Objetivo: derivar a humano de forma controlada cuando el usuario lo pide o cuando el agente detecta necesidad comercial/riesgo.

Pendiente:

- [ ] Probar manualmente la estrategia final de Mary con n8n publicado.
- [ ] Afinar señales automáticas de handoff por riesgo/queja/cierre.
- [ ] Evitar que SA siga contestando si la conversación quedó en estado humano pendiente.
- [ ] Crear aviso/tarea en CRM cuando aplique.
- [ ] Registrar motivo de handoff en conversación.
- [ ] Añadir tests e2e con conversación persistente.

## 9. CRM contact-context y agenda/citas

Objetivo: enriquecer conversación y permitir reservas sin duplicar en SA la fuente maestra, usando MCP/n8n como capa de integración.

Pendiente:

- [ ] Revisar si queda algún `401` legacy real o si está desfasado.
- [ ] Validar flujo completo:
  - usuario pide cita.
  - SA consulta disponibilidad.
  - SA ofrece slots.
  - usuario elige.
  - SA confirma o genera link/invitación.
- [ ] Integrar waitlist CRM como posible fallback cuando no hay huecos.
- [ ] Registrar `lead_id`, `customer_id`, cita o booking invitation en conversación.
- [ ] Añadir fallback a handoff si CRM/n8n falla.
- [ ] Consumir `contact_context` como contexto externo normalizado vía MCP/n8n, preferentemente desde cache persistente en SA.
- [ ] Documentar contratos MCP/n8n definitivos.

## 10. RAG por tenant/producto

Objetivo: consultar documentación comercial solo cuando aporte valor real.

Pendiente:

- [ ] Definir contrato contra `ai-stack/rag-api`.
- [ ] Soportar scoping por:
  - tenant.
  - product.
  - knowledge base.
- [ ] Indexar FAQs, objeciones y material comercial.
- [ ] Recuperar contexto semántico cuando el usuario pregunta por información documental.
- [ ] No usar RAG para stock, precios rotativos o inventario vivo.
- [ ] Añadir trazabilidad de fuentes.
- [ ] Controlar latencia y fallback.

## 11. Audio WhatsApp

Objetivo: consolidar audio real en canal WhatsApp.

Pendiente:

- [ ] Prueba real con WhatsApp Cloud API y audio de móvil.
- [ ] Revisar UX del mensaje cuando audio supera límite.
- [ ] Revisar UX del mensaje cuando plan no permite audio.
- [ ] Persistir audio/transcripción como mensaje inbound cuando la conversación esté cerrada.
- [ ] Decidir si permitir respuesta por voz en fase futura.
- [ ] Mantener fuera de alcance conversación telefónica realtime.

## 12. Observabilidad avanzada

Objetivo: poder auditar decisiones, controlar coste y diagnosticar errores por conversación/tenant.

Pendiente:

- [ ] Registrar `conversation_id` en todos los logs relevantes.
- [ ] Registrar `tenant_id`, `product_id`, `playbook_id`.
- [ ] Registrar latencias parciales:
  - backend context.
  - OpenAI.
  - MCP/tools.
  - CRM/n8n.
  - audio.
- [ ] Registrar errores clasificados.
- [ ] Crear vista mínima de trazas por conversación.
- [ ] Evaluar Langfuse solo si aporta valor real y no complica dev/prod.

## 13. Endpoints y contratos

Objetivo: estabilizar contratos públicos/internos para evitar roturas entre proyectos.

Pendiente:

- [ ] Estabilizar contrato de `POST /agent/respond`.
- [ ] Documentar payload normalizado de `wa-gateway-api`.
- [ ] Documentar respuesta estructurada SA.
- [ ] Documentar `/api/internal/ai-usage/{tenantId}/policy` con campos de plan:
  - `commercial_plan_code`.
  - `commercial_plan_name`.
  - `plan_monthly_ai_tokens`.
  - `approved_extra_tokens_current_month`.
  - `effective_monthly_ai_token_limit`.
  - `monthly_limit_source`.
  - `audio_transcription_enabled_by_plan`.
- [ ] Definir contratos de error.
- [ ] Añadir/versionar ejemplos Postman/cURL.
- [ ] Versionar contratos si empieza a haber clientes reales.

## 14. Administración y UX interna

Objetivo: hacer operable el sistema desde panel sin depender de DB manual.

Pendiente:

- [ ] Completar CRUD administrativo para conversaciones.
- [ ] Mostrar estado de conversación:
  - activa.
  - pendiente humano.
  - cerrada.
  - error.
- [ ] Mejorar contadores de límites por plan.
- [ ] Afinar importador/sync de catálogo CRM si sigue siendo necesario.
- [ ] Completar edición de perfiles y roles internos si queda pendiente.
- [ ] Añadir ayudas/empty states para tenants sin plan comercial.
- [ ] Añadir vista resumen de plan/consumo en dashboard.

## 15. Calidad y tests

Objetivo: evitar regresiones conforme el runtime se vuelve multi-sistema.

Pendiente:

- [ ] Tests e2e de runtime por tenant.
- [ ] Tests de resolución por `tenant_id`, `entrypoint_ref` y `phone_number_id`.
- [ ] Tests de persistencia inbound/outbound.
- [ ] Tests de CRM contact-context con mocks `401`/`500`.
- [ ] Tests de agenda/citas con mock MCP/n8n.
- [ ] Tests de handoff con conversación persistente.
- [ ] Automatizar flujo principal en Docker.
- [ ] Revisar warnings/deprecations PHPUnit cuando sea oportuno.

## 16. Operación y despliegue

Objetivo: mantener flujo claro de dev/prod y evitar saturar VPS.

Pendiente:

- [ ] Revisar workflow de producción y bootstrap.
- [ ] Confirmar separación dev/prod.
- [ ] Documentar variables críticas:
  - OpenAI.
  - MCP.
  - CRM downstream auth.
  - n8n webhooks.
  - WhatsApp/Meta.
  - Stripe futuro.
- [ ] Mantener `make` como entrada principal.
- [ ] Revisar límites/timeouts de:
  - OpenAI.
  - MCP.
  - CRM.
  - n8n.
  - RAG.
  - audio.
- [ ] Evitar puertos públicos innecesarios.
- [ ] Confirmar redes Docker internas/externas.
- [ ] Documentar perfiles dev/manual para Ollama.
- [ ] Añadir checklist de deploy.

## Orden resumido recomendado

```text
1. Contexto base, memoria operativa y contact_context cacheado.
2. WhatsApp real con persistencia completa de conversación.
3. CRM/agenda, confirmación de citas y handoff estable.
4. crm_contact_submit con resumen útil hacia CRM vía MCP/n8n.
5. Planes comerciales operativos y límites pendientes.
6. RAG tenant/producto.
7. Observabilidad, contratos y tests e2e.
8. Operación/despliegue.
9. Stripe y top-ups IA cuando toque monetización real.
```