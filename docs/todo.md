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

## 5. Runtime y resolución tenant/producto/playbook

Objetivo: mantener robusta la selección de tenant y contexto comercial.

Pendiente:

- [ ] Añadir más tests de resolución de tenant por `phone_number_id`.
- [ ] Añadir tests de routing inconsistente.
- [ ] Revisar comportamiento cuando no hay producto local y MCP está caído.
- [ ] Revisar selección de playbook cuando hay múltiples candidatos.
- [ ] Mejorar trazas de `product_selection`.
- [ ] Documentar contrato final de `/agent/respond`.

## 6. MCP / herramientas externas / n8n

Objetivo: usar MCP/n8n como capa de herramientas externas sin convertir SA en CRM/ERP.

Pendiente:

- [ ] Revisar y limpiar legacy:
  - `crm_client.py` si ya no se usa.
  - variables antiguas CRM si quedaron obsoletas.
- [ ] Añadir vista de trazas MCP más cómoda.
- [ ] Añadir test de tools MCP con mock de n8n/CRM.
- [ ] Mejorar mensajes cuando MCP no está configurado o falla.
- [ ] Definir catálogo/inventario externo como tool MCP/n8n genérica.
- [ ] Mantener separación de tokens:
  - downstream authorization ≠ webhook token ≠ tokens OpenAI.

## 7. Handoff humano

Objetivo: derivar a humano de forma controlada cuando el usuario lo pide o cuando el agente detecta necesidad comercial/riesgo.

Pendiente:

- [ ] Probar manualmente la estrategia final de Mary con n8n publicado.
- [ ] Afinar señales automáticas de handoff por riesgo/queja/cierre.
- [ ] Evitar que SA siga contestando si la conversación quedó en estado humano pendiente.
- [ ] Crear aviso/tarea en CRM cuando aplique.
- [ ] Registrar motivo de handoff en conversación.
- [ ] Añadir tests e2e con conversación persistente.

## 8. CRM contact-context y agenda/citas

Objetivo: enriquecer conversación y permitir reservas sin duplicar en SA la fuente maestra.

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
- [ ] Documentar contratos MCP/n8n definitivos.

## 9. RAG por tenant/producto

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

## 10. Audio WhatsApp

Objetivo: consolidar audio real en canal WhatsApp.

Pendiente:

- [ ] Prueba real con WhatsApp Cloud API y audio de móvil.
- [ ] Revisar UX del mensaje cuando audio supera límite.
- [ ] Revisar UX del mensaje cuando plan no permite audio.
- [ ] Persistir audio/transcripción como mensaje inbound cuando la conversación esté cerrada.
- [ ] Decidir si permitir respuesta por voz en fase futura.
- [ ] Mantener fuera de alcance conversación telefónica realtime.

## 11. Observabilidad avanzada

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

## 12. Endpoints y contratos

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

## 13. Administración y UX interna

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

## 14. Calidad y tests

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

## 15. Operación y despliegue

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
1. Stripe.
2. WhatsApp real.
3. Persistencia completa de conversación.
4. CRM/agenda y handoff estable.
5. RAG tenant/producto.
6. Observabilidad y contratos.
7. Calidad/tests e2e.
8. Operación y despliegue.
```
