# TODO del proyecto sales-agent

Lista ordenada y actualizada de trabajo para llevar `sales-agent` desde el hito actual hasta un runtime comercial funcional integrado con WhatsApp, CRM, n8n/MCP, RAG, planes comerciales, Stripe y observabilidad.

## Estado actual del hito

Completado/validado recientemente:

- [x] Integración LLM con OpenAI como proveedor principal.
- [x] Fallback seguro a heurística cuando aplica; Ollama queda fuera del fallback automático y limitado a uso manual/dev.
- [x] `commercial-context` interno funcionando para cargar contexto comercial desde backend Symfony.
- [x] Resolución comercial de producto antes de playbook, con fallback MCP controlado cuando no hay catálogo local.
- [x] MCP remoto validado end-to-end con OpenAI Responses API y downstream authorization tenant-scoped.
- [x] ExternalTools/MCP configurables por tenant, con token downstream cifrado y no expuesto en prompt/payload/logs.
- [x] MCP principal de Mary validado con `services_search`.
- [x] Handoff humano implementado:
  - short-circuit por petición explícita de humano.
  - estrategias `manual_wa_link`, `n8n_webhook`, `manual_wa_link_and_n8n`.
  - tool MCP `handoff_request`.
  - separación correcta entre downstream authorization y token propio de webhook.
- [x] Routing multi-tenant por prioridad:
  - `entrypoint_ref`.
  - `phone_number_id` / canal externo.
  - `tenant_id` explícito.
  - error controlado ante routing inconsistente.
- [x] Audio WhatsApp implementado a nivel SA/wa-gateway-api:
  - normalización de mensajes audio.
  - descarga segura de media vía endpoint interno.
  - transcripción OpenAI.
  - billing/uso IA para `audio_transcription`.
  - límite de duración configurable.
  - bloqueo por plan comercial si `audio_transcription=false`.
- [x] Gestión IA/tokens:
  - modelos IA desde `Modelos IA`, no hardcodeados.
  - costes IA editables.
  - conversión estable € ↔ tokens por modelo.
  - límites diario/mensual por tenant.
  - top-ups/recargas manuales aprobadas.
  - tokens de plan + top-ups del mes actual.
- [x] Planes comerciales completados:
  - catálogo Starter/Growth/Pro en PLATAFORMA.
  - asignación de plan al tenant.
  - suscripción manual y periodo.
  - features/limits JSON.
  - tokens/mes visibles.
  - Stripe preparado en modelo/UI pero no implementado.
  - enforcement básico de productos, guías, entry points, MCP y audio.
- [x] Commits recientes:
  - `4744ca1 Add commercial plans management`.
  - `30f0430 Add commercial plans and AI token entitlements`.
  - `e07960f Enforce commercial plan limits`.

Pendiente inmediato recomendado:

- [ ] Push de los commits recientes a `origin/main`.
- [ ] Revisión manual final de planes comerciales:
  - Starter bloquea audio y MCP.
  - Growth permite audio y hasta 3 MCP.
  - límites de productos, guías y puntos de entrada aplican al crear.
- [ ] Definir próxima prioridad: Stripe, WhatsApp real, conversación persistente o CRM/agenda.

---

## 1. Push y cierre del hito de planes comerciales

Objetivo: dejar el hito actual respaldado en remoto antes de abrir nuevas fases.

Tareas:

- [ ] Ejecutar `git status --short`.
- [ ] Confirmar que la rama `main` contiene:
  - `4744ca1 Add commercial plans management`.
  - `30f0430 Add commercial plans and AI token entitlements`.
  - `e07960f Enforce commercial plan limits`.
- [ ] Ejecutar `git push origin main`.
- [ ] Registrar en README/TODO el hito funcional cerrado.

Comandos sugeridos:

```bash
cd ~/www/sales-agent
git status --short
git log --oneline -5
git push origin main
```

---

## 2. Stripe para planes comerciales y tokens IA

Objetivo: preparar e implementar, en fase futura, la monetización real de Sales Agent con suscripción mensual/anual por plan y recargas de tokens IA.

Estado actual:

- [x] `CommercialPlan` existe.
- [x] Planes Starter/Growth/Pro existen.
- [x] Cada plan tiene precio mensual/anual, moneda y campos Stripe futuros.
- [x] El tenant tiene plan asignado, estado de suscripción y periodo manual.
- [x] Los tokens IA incluidos por plan se usan como base mensual efectiva.
- [x] Los top-ups aprobados del mes se suman como extra temporal.
- [ ] Stripe todavía no está implementado.
- [ ] No existe checkout.
- [ ] No existen webhooks Stripe.
- [ ] No existe sincronización automática de estado de suscripción.

Modelo comercial objetivo:

```text
Plan base mensual/anual
+ tokens IA incluidos por plan
+ recargas/top-ups puntuales
+ posible overage o ampliación manual/automática futura
```

Tareas fase Stripe 1: configuración y catálogo

- [ ] Añadir configuración segura:
  - `STRIPE_SECRET_KEY`.
  - `STRIPE_WEBHOOK_SECRET`.
  - `STRIPE_SUCCESS_URL`.
  - `STRIPE_CANCEL_URL`.
  - modo test/live.
- [ ] Validar que `CommercialPlan` tiene:
  - `stripeProductId`.
  - `stripeMonthlyPriceId`.
  - `stripeYearlyPriceId`.
- [ ] Añadir validación/UI para que PLATAFORMA pueda completar IDs Stripe por plan.
- [ ] Decidir si el catálogo Stripe se crea manualmente en Stripe Dashboard o con comando sync.
- [ ] Si se automatiza, crear comando:
  - `app:stripe:sync-plans`.
  - crear/actualizar product y prices.
  - nunca borrar precios antiguos si ya hay suscripciones.
- [ ] Documentar workflow test mode.

Tareas fase Stripe 2: checkout de suscripción

- [ ] Crear endpoint interno para iniciar checkout de suscripción por tenant y plan.
- [ ] Soportar mensual/anual.
- [ ] Asociar metadata:
  - `tenant_id`.
  - `plan_code`.
  - `billing_interval`.
- [ ] Crear entidad opcional `Subscription` o ampliar tenant si basta:
  - `stripeCustomerId`.
  - `stripeSubscriptionId`.
  - `subscriptionStatus`.
  - `currentPeriodStart`.
  - `currentPeriodEnd`.
  - `cancelAtPeriodEnd`.
- [ ] No mezclar todavía la compra de tokens con checkout de plan salvo decisión explícita.
- [ ] UI mínima en PLATAFORMA para lanzar checkout o registrar manualmente datos de Stripe.

Tareas fase Stripe 3: webhooks

- [ ] Crear endpoint webhook Stripe.
- [ ] Validar firma con `STRIPE_WEBHOOK_SECRET`.
- [ ] Procesar eventos mínimos:
  - `checkout.session.completed`.
  - `customer.subscription.created`.
  - `customer.subscription.updated`.
  - `customer.subscription.deleted`.
  - `invoice.payment_failed`.
  - `invoice.payment_succeeded`.
- [ ] Actualizar estado de suscripción del tenant.
- [ ] Actualizar plan asignado cuando corresponda.
- [ ] No degradar/eliminar recursos existentes automáticamente ante downgrade.
- [ ] Registrar eventos recibidos para idempotencia.
- [ ] Añadir tests con payloads firmados/mocks.

Tareas fase Stripe 4: recargas/top-ups IA

- [ ] Definir productos/precios Stripe para paquetes de tokens:
  - 1M.
  - 5M.
  - 10M.
  - custom/manual.
- [ ] Mantener la regla ya decidida:
  - top-up aprobado se suma solo al mes/periodo actual.
  - no modifica el plan.
  - no se arrastra al mes siguiente.
- [ ] Crear checkout puntual para top-ups.
- [ ] Webhook `checkout.session.completed` para acreditar tokens extra al tenant.
- [ ] Mostrar en UI:
  - tokens base por plan.
  - tokens extra comprados/aprobados este mes.
  - límite mensual efectivo.
  - consumo actual.
- [ ] Mantener aprobación manual disponible para casos comerciales.

Decisiones pendientes antes de implementar Stripe:

- [ ] ¿Stripe será visible para clientes/tenant admins o solo operado por Super Admin/PLATAFORMA?
- [ ] ¿Habrá self-service checkout público o alta manual asistida?
- [ ] ¿Downgrade inmediato o al fin de periodo?
- [ ] ¿Qué ocurre si el tenant supera límites de recursos tras bajar de plan?
- [ ] ¿Se permitirá overage automático o solo top-ups prepagados?
- [ ] ¿Se factura por organización/tenant o por cuenta de usuario?
- [ ] ¿La promoción/free trial se gestiona manualmente como en CRM o con Stripe trial?

---

## 3. Planes comerciales: mejoras posteriores no-Stripe

Objetivo: cerrar detalles operativos alrededor del enforcement de planes sin abrir facturación.

Ya completado:

- [x] Guard común `PlanUsageGuard`.
- [x] Bloqueo de creación si no hay plan comercial.
- [x] Enforcement de:
  - productos.
  - guías/playbooks.
  - puntos de entrada.
  - MCP/ExternalTools.
  - audio transcription.
- [x] Policy interna expone `audio_transcription_enabled_by_plan`.
- [x] FastAPI corta audio antes de transcribir si el plan no lo permite.

Pendiente posible:

- [ ] Añadir contadores visibles en listados:
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

---

## 4. WhatsApp real: `wa-gateway-api` → `sales-agent` → WhatsApp

Objetivo: cerrar el circuito real de WhatsApp Cloud API con Sales Agent.

Estado conocido:

- `wa-gateway-api` es adaptador técnico.
- Sales Agent no debe enviar WhatsApp directamente.
- Flujo correcto:
  - WhatsApp Cloud API → `wa-gateway-api`.
  - `wa-gateway-api` → `sales-agent`.
  - `sales-agent` devuelve decisión/respuesta.
  - `wa-gateway-api` envía físicamente a WhatsApp.

Tareas:

- [ ] Validar webhook público HTTPS de Meta apuntando a `wa-gateway-api`.
- [ ] Añadir/configurar `SALES_AGENT_URL`.
- [ ] Mapear inbound normalizado a `/agent/respond`.
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

---

## 5. Persistencia completa de conversación

Objetivo: guardar conversaciones reales antes de escalar el canal WhatsApp.

Tareas:

- [ ] Revisar modelo actual de `Conversation`.
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

---

## 6. Runtime y resolución tenant/producto/playbook

Objetivo: mantener robusta la selección de tenant y contexto comercial.

Ya avanzado:

- [x] Resolución de producto antes de playbook.
- [x] No depender exclusivamente de catálogo local.
- [x] Fallback MCP cuando no hay catálogo local y está permitido/configurado.
- [x] Routing por `entrypoint_ref`, canal y tenant explícito.

Pendiente:

- [ ] Añadir más tests de resolución de tenant por `phone_number_id`.
- [ ] Añadir tests de routing inconsistente.
- [ ] Revisar comportamiento cuando no hay producto local y MCP está caído.
- [ ] Revisar selección de playbook cuando hay múltiples candidatos.
- [ ] Mejorar trazas de `product_selection`.
- [ ] Documentar contrato final de `/agent/respond`.

---

## 7. MCP / herramientas externas / n8n

Objetivo: usar MCP/n8n como capa de herramientas externas sin convertir SA en CRM/ERP.

Ya avanzado:

- [x] ExternalTools configurables por tenant.
- [x] MCP remoto con OpenAI Responses API validado.
- [x] Downstream authorization tenant-scoped.
- [x] Tool `contact_context`.
- [x] Tool `services_search`.
- [x] Tools de agenda CRM disponibles a través de MCP/n8n.
- [x] Tool `handoff_request`.
- [x] Gestión UI de servidores MCP en Administración técnica.
- [x] Enforcement de plan para MCP.

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

---

## 8. Handoff humano

Objetivo: derivar a humano de forma controlada cuando el usuario lo pide o cuando el agente detecta necesidad comercial/riesgo.

Ya avanzado:

- [x] Petición explícita de humano con short-circuit rule-based.
- [x] Estrategias:
  - `manual_wa_link`.
  - `n8n_webhook`.
  - `manual_wa_link_and_n8n`.
- [x] Handoff webhook separado de downstream authorization.
- [x] MCP tool `handoff_request`.
- [x] Documentación `docs/handoff.md`.

Pendiente:

- [ ] Probar manualmente estrategia final de Mary con n8n publicado.
- [ ] Afinar señales automáticas de handoff por riesgo/queja/cierre.
- [ ] Evitar que SA siga contestando si la conversación quedó en estado humano pendiente.
- [ ] Crear aviso/tarea en CRM cuando aplique.
- [ ] Registrar motivo de handoff en conversación.
- [ ] Añadir tests e2e con conversación persistente.

---

## 9. CRM contact-context y agenda/citas

Objetivo: enriquecer conversación y permitir reservas sin duplicar en SA la fuente maestra.

Ya avanzado:

- [x] `contact_context` vía n8n/MCP implementado previamente.
- [x] Downstream authorization validado.
- [x] Tools de servicios y agenda existen en MCP/n8n/CRM.
- [x] Sales Agent puede usar catálogo externo cuando no hay productos locales.

Pendiente:

- [ ] Revisar si queda algún 401 legacy real o si está desfasado.
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

---

## 10. RAG por tenant/producto

Objetivo: consultar documentación comercial solo cuando aporte valor real.

Tareas:

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
- [ ] Respetar límites de plan si RAG se convierte en feature comercial futura.

---

## 11. Audio WhatsApp

Objetivo: consolidar audio real en canal WhatsApp.

Ya avanzado:

- [x] `wa-gateway-api` normaliza audio y reenvía media metadata.
- [x] Endpoint interno seguro de media en `wa-gateway-api`.
- [x] SA descarga y transcribe audio con OpenAI.
- [x] Uso/coste de audio registrado como `audio_transcription`.
- [x] Límite de duración configurable.
- [x] Bloqueo por plan comercial.
- [x] Tests focalizados en runtime/policy.

Pendiente:

- [ ] Prueba real con WhatsApp Cloud API y audio de móvil.
- [ ] Revisar UX del mensaje cuando audio supera límite.
- [ ] Revisar UX del mensaje cuando plan no permite audio.
- [ ] Persistir audio/transcripción como mensaje inbound cuando conversación esté cerrada.
- [ ] Decidir si permitir respuesta por voz en fase futura.
- [ ] Mantener fuera de alcance conversación telefónica realtime.

---

## 12. Observabilidad avanzada

Objetivo: poder auditar decisiones, controlar coste y diagnosticar errores por conversación/tenant.

Ya avanzado parcialmente:

- [x] Telemetría LLM y traces MCP básicas.
- [x] Registro de uso IA/tokens/costes.
- [x] Uso IA visible por tenant.
- [x] Policy interna expone límites efectivos.

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

---

## 13. Endpoints y contratos

Objetivo: estabilizar contratos públicos/internos para evitar roturas entre proyectos.

Tareas:

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

---

## 14. Administración y UX interna

Objetivo: hacer operable el sistema desde panel sin depender de DB manual.

Ya avanzado:

- [x] Tenants/negocios.
- [x] Productos/servicios.
- [x] Guías comerciales/playbooks.
- [x] Puntos de entrada.
- [x] Servidores MCP.
- [x] Uso IA.
- [x] Modelos IA.
- [x] Planes comerciales.
- [x] Configuración básica.
- [x] Sidebar PLATAFORMA colapsable.

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

---

## 15. Calidad y tests

Objetivo: evitar regresiones conforme el runtime se vuelve multi-sistema.

Ya avanzado:

- [x] Tests de planes comerciales.
- [x] Tests de límites IA por plan.
- [x] Tests de enforcement de productos/MCP/audio.
- [x] Tests de audio policy.
- [x] Tests focalizados backend + FastAPI en cada fase.

Pendiente:

- [ ] Tests e2e de runtime por tenant.
- [ ] Tests de resolución por `tenant_id`, `entrypoint_ref` y `phone_number_id`.
- [ ] Tests de persistencia inbound/outbound.
- [ ] Tests de CRM contact-context con mocks 401/500.
- [ ] Tests de agenda/citas con mock MCP/n8n.
- [ ] Tests de handoff con conversación persistente.
- [ ] Automatizar flujo principal en Docker.
- [ ] Revisar warnings/deprecations PHPUnit cuando sea oportuno.

---

## 16. Operación y despliegue

Objetivo: mantener flujo claro de dev/prod y evitar saturar VPS.

Tareas:

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

---

## Orden resumido recomendado actualizado

```text
1. Push de commits actuales.
2. Pruebas manuales finales de planes Starter/Growth/Pro.
3. Decidir si el siguiente frente es Stripe o WhatsApp real.
4. Si Stripe:
   4.1 configurar IDs Stripe en planes.
   4.2 checkout suscripción.
   4.3 webhooks.
   4.4 top-ups IA vía Stripe.
5. Si WhatsApp:
   5.1 conectar wa-gateway-api → SA.
   5.2 texto real.
   5.3 audio real.
   5.4 persistencia inbound/outbound.
6. Conversaciones y observabilidad.
7. CRM/agenda/waitlist end-to-end.
8. RAG tenant/producto.
9. Calidad/tests e2e.
10. Operación/despliegue.
```