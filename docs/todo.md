# TODO del proyecto sales-agent

Lista ordenada de trabajo para llevar `sales-agent` desde el hito actual hasta un runtime comercial funcional integrado con WhatsApp, CRM, n8n, RAG y observabilidad.

## Estado actual del hito

Completado o validado recientemente:

- [x] Integración LLM con OpenAI como proveedor principal.
- [x] `commercial-context` interno funcionando para cargar contexto comercial desde backend Symfony.
- [x] Routing de proveedor LLM validado.
- [x] Postman validado para el flujo principal.
- [x] Opciones de Ollama corregidas para evitar uso accidental pesado.
- [x] `auto` cambiado a flujo seguro: OpenAI → heurística, sin Ollama como fallback automático.
- [x] Ollama limitado a uso manual/dev, con `llama3.2:3b` y timeout bajo recomendado de 10-15s.

Pendiente inmediato:

- [ ] Commit + push del hito actual.

---

## 1. Commit + push del hito actual

Objetivo: cerrar el hito técnico actual antes de avanzar con integración WhatsApp real.

Incluye:

- OpenAI como proveedor principal.
- Fallback seguro a heurística cuando `provider=auto` y OpenAI falla.
- Ollama excluido del fallback automático para no saturar el VPS.
- Ollama disponible solo como proveedor explícito/manual/dev.
- `commercial-context` interno funcionando.
- Routing LLM corregido.
- Pruebas Postman validadas.
- Configuración Ollama corregida.

Comandos sugeridos:

```bash
git status
git add .
git commit -m "feat: integrate OpenAI LLM routing and internal commercial context"
git push
```

---

## 2. Conectar `wa-gateway-api` → `sales-agent` con webhook simulado

Objetivo: cerrar el primer circuito conversacional sin depender todavía de WhatsApp real.

Flujo objetivo:

```text
Payload tipo WhatsApp
→ wa-gateway-api
→ sales-agent /agent/respond
→ wa-gateway-api
→ respuesta simulada/logueada
```

Tareas:

- [ ] Añadir/configurar `SALES_AGENT_URL` en `wa-gateway-api`.
- [ ] En el inbound normalizado de `wa-gateway-api`, llamar a `POST /agent/respond`.
- [ ] Mapear mensaje entrante de WhatsApp a contrato de SA:
  - `tenant_id` o identificador técnico para resolver tenant.
  - `message`.
  - `contact`.
  - `conversation.last_messages` cuando existan.
  - metadatos mínimos del canal.
- [ ] Recibir respuesta estructurada de SA:
  - `reply`.
  - `intent`.
  - `score`.
  - `action`.
  - `needs_human`.
  - `data_to_save`.
- [ ] Loguear respuesta sin enviar todavía WhatsApp real.
- [ ] Probar con Postman/cURL contra `wa-gateway-api`, no directamente contra SA.
- [ ] Documentar payload simulado.

---

## 3. Persistencia completa de conversación

Objetivo: guardar conversaciones reales antes de conectar el canal WhatsApp productivo.

Tareas:

- [ ] Revisar modelo actual de `Conversation`.
- [ ] Completar persistencia de `ConversationMessage` para mensajes inbound.
- [ ] Completar persistencia de `ConversationMessage` para mensajes outbound.
- [ ] Guardar relación con tenant, producto/playbook si aplica y canal.
- [ ] Guardar identificadores externos del canal:
  - `whatsapp_message_id`.
  - `wa_id` / teléfono normalizado.
  - `phone_number_id`.
  - `conversation_reference` si aplica.
- [ ] Guardar trazabilidad mínima por mensaje/respuesta:
  - provider LLM usado.
  - modelo.
  - latencia.
  - intent.
  - score.
  - action.
  - `needs_human`.
  - errores.
- [ ] Evitar que solo se cree una conversación mínima sin mensajes asociados.
- [ ] Definir idempotencia para no duplicar inbound si WhatsApp reintenta webhooks.
- [ ] Añadir tests de persistencia inbound/outbound.

---

## 4. WhatsApp real: inbound → SA → outbound

Objetivo: salir de Postman y cerrar el circuito real con WhatsApp Cloud API.

Flujo objetivo:

```text
WhatsApp Cloud API
→ wa-gateway-api /webhooks/whatsapp
→ sales-agent /agent/respond
→ wa-gateway-api /messages/send
→ WhatsApp Cloud API
```

Tareas:

- [ ] Validar que `wa-gateway-api` recibe webhooks reales de Meta.
- [ ] Normalizar eventos entrantes de texto.
- [ ] Ignorar eventos no soportados o duplicados.
- [ ] Llamar a SA con el contrato definitivo.
- [ ] Enviar `reply` real usando `/messages/send`.
- [ ] Persistir inbound y outbound.
- [ ] Manejar errores de Meta y de SA de forma segura.
- [ ] Añadir logs por `message_id` / conversación.
- [ ] Documentar el flujo real.

---

## 5. Resolver contexto del tenant en runtime

Objetivo: asegurar que cada mensaje entra en el tenant correcto y no se seleccionan productos/playbooks de forma implícita o frágil.

Tareas:

- [ ] Buscar `Tenant` por `tenant_id` cuando venga explícito.
- [ ] Buscar `Tenant` por `whatsappPhoneNumberId` cuando llegue tráfico desde Meta.
- [ ] Validar tenant activo.
- [ ] Cargar productos activos del tenant.
- [ ] Cargar playbooks activos del tenant.
- [ ] Elegir playbook aplicable por tenant y, si existe, por producto.
- [ ] Eliminar cualquier selección implícita de producto por orden de lista.
- [ ] Devolver error estructurado si el tenant no existe o está inactivo.
- [ ] Añadir tests de resolución de tenant por canal.

---

## 6. Revisar y cerrar modelo de dominio administrativo

Objetivo: dejar estable la configuración comercial administrada desde Symfony.

Tareas:

- [ ] Revisar esquema final de `Tenant.salesPolicy`.
- [ ] Revisar esquema final de `Product.salesPolicy`.
- [ ] Revisar esquema final de `Playbook.config`.
- [ ] Confirmar campos obligatorios y opcionales en cada entidad.
- [ ] Añadir validaciones de dominio donde falten.
- [ ] Mejorar gestión de tenants, products y playbooks.
- [ ] Asegurar que el panel humano refleja el estado real del modelo.
- [ ] Añadir validación estricta de formato internacional para `whatsappPublicPhone` cuando se endurezca la captura de canales.
- [ ] Añadir validación del identificador técnico de Meta cuando sea necesario.

---

## 7. CRM contact-context y resolver 401

Objetivo: enriquecer la respuesta con historial real del lead/contacto sin duplicar en SA la fuente maestra del CRM.

Tareas:

- [ ] Resolver el 401 actual al consultar CRM.
- [ ] Definir contrato de lectura contra CRM.
- [ ] Consultar contacto/lead por teléfono, email o referencia externa.
- [ ] Recuperar:
  - datos de contacto.
  - estado del lead.
  - pipeline.
  - actividad previa.
  - próxima cita si existe.
  - owner/agente asignado si existe.
- [ ] Evitar duplicar en `sales-agent` la fuente maestra del negocio.
- [ ] Definir qué datos se cachean temporalmente y cuáles se consultan siempre.
- [ ] Relacionar conversación con `lead_id`, `customer_id` o referencia externa cuando aplique.
- [ ] Preparar fallback si CRM no está contratado por el tenant.

---

## 8. Funcionalidad genérica de inventario/catálogo externo vía n8n

Objetivo: permitir consultas a inventario o catálogo vivo sin convertir `sales-agent` en ERP, ecommerce ni catálogo universal.

Principio arquitectónico:

- SA mantiene el enfoque comercial: tenant, producto/servicio, guía, playbook, objeciones, scoring y tono.
- El inventario real vive fuera de SA.
- n8n actúa como adaptador hacia la DB/API/sistema externo del cliente.
- SA envía una intención de búsqueda abstracta y recibe resultados normalizados.

Flujo objetivo:

```text
Usuario pregunta por disponibilidad, stock, precio, unidades, alternativas o similares
→ SA detecta intent external_catalog_search
→ SA construye consulta abstracta normalizada
→ SA llama ExternalTool configurada para tenant/product/playbook
→ n8n adapta la consulta al sistema real del cliente
→ n8n consulta DB/API externa
→ n8n devuelve resultados normalizados
→ SA responde comercialmente usando esos resultados
```

Tareas de modelo/configuración:

- [ ] Crear soporte para `ExternalTool` configurable por tenant.
- [ ] Permitir asociar una `ExternalTool` a producto o playbook cuando aplique.
- [ ] Definir tipo inicial `n8n_webhook`.
- [ ] Guardar configuración segura:
  - nombre.
  - tipo.
  - webhook URL.
  - secret/token.
  - timeout.
  - enabled.
  - tenant.
  - product/playbook opcional.
- [ ] Evitar exponer secretos en vistas o logs.
- [ ] Añadir CRUD administrativo mínimo.

Tareas de runtime:

- [ ] Detectar intención de búsqueda externa:
  - disponibilidad.
  - stock.
  - precio actual.
  - unidades reales.
  - productos similares.
  - alternativas.
  - búsqueda por presupuesto o características.
- [ ] Construir payload abstracto para n8n.
- [ ] Incluir contexto mínimo:
  - `tenant_id`.
  - `product_id` si aplica.
  - `playbook_id` si aplica.
  - mensaje original.
  - filtros inferidos.
  - presupuesto si se detecta.
  - ubicación si se detecta.
  - preferencias.
  - conversation/contact metadata.
- [ ] Llamar webhook n8n con timeout bajo y errores controlados.
- [ ] Procesar respuesta normalizada de n8n.
- [ ] Permitir que SA responda con resultados, alternativas o solicitud de más datos.
- [ ] Definir fallback cuando n8n no responde o no hay resultados.
- [ ] Registrar la llamada externa en trazas de decisión.

Contrato sugerido de request a n8n:

```json
{
  "tenant_id": "...",
  "product_id": "...",
  "playbook_id": "...",
  "conversation_id": "...",
  "contact": {
    "name": "...",
    "phone": "...",
    "email": "..."
  },
  "query": {
    "intent": "external_catalog_search",
    "original_message": "Busco un coche automático por menos de 15000 euros",
    "filters": {
      "category": "vehicle",
      "budget_max": 15000,
      "features": ["automatic"]
    }
  }
}
```

Contrato sugerido de respuesta desde n8n:

```json
{
  "ok": true,
  "results": [
    {
      "external_id": "veh_123",
      "title": "Toyota Corolla 1.8 Hybrid Automatic",
      "description": "Vehículo híbrido automático en buen estado",
      "price": 14500,
      "currency": "EUR",
      "availability": "available",
      "url": "https://...",
      "metadata": {
        "year": 2019,
        "km": 82000
      }
    }
  ],
  "summary": "Encontré 1 vehículo compatible con el presupuesto y preferencia automática.",
  "source": "client_inventory"
}
```

Ejemplo de uso:

- En SA existe el producto/servicio comercial: `Venta de vehículos de ocasión`.
- La guía comercial vive en SA.
- El inventario real vive en una base/API externa.
- n8n traduce la intención abstracta a la consulta real del cliente.

---

## 9. Handoff humano

Objetivo: derivar a humano cuando el agente detecta intención de cierre, riesgo, falta de confianza o necesidad comercial.

Tareas:

- [ ] Definir umbrales de derivación a humano.
- [ ] Detectar señales de intención de cierre.
- [ ] Detectar señales de queja, riesgo o usuario molesto.
- [ ] Detectar petición explícita de hablar con una persona.
- [ ] Incluir reglas por producto y por tenant.
- [ ] Entregar motivo de handoff de forma estructurada.
- [ ] Si `needs_human=true`, marcar conversación como pendiente.
- [ ] Crear aviso/tarea en CRM si el tenant usa CRM.
- [ ] Permitir alternativa vía n8n si el tenant no usa CRM.
- [ ] Evaluar aviso a WhatsApp personal mediante canal permitido/plantilla si aplica.
- [ ] Evitar que SA siga contestando sin control cuando la conversación está en estado humano pendiente.

---

## 10. Agenda/citas CRM vía n8n/CRM

Objetivo: permitir que SA ofrezca disponibilidad y genere link de reserva sin acoplarse obligatoriamente al CRM propio.

Tareas:

- [ ] Detectar intención de cita/reunión/demo.
- [ ] Definir contrato de disponibilidad.
- [ ] Consultar disponibilidad vía n8n o CRM según configuración del tenant.
- [ ] Ofrecer slots concretos.
- [ ] Generar link de reserva.
- [ ] Registrar intención de cita en conversación.
- [ ] Guardar datos necesarios en `data_to_save`.
- [ ] Manejar respuesta del usuario eligiendo slot.
- [ ] Crear evento/cita mediante CRM/n8n.
- [ ] Añadir fallback a handoff humano si no hay disponibilidad o falla la integración.

---

## 11. RAG por tenant/producto

Objetivo: consultar documentación comercial solo cuando aporte valor real a la respuesta.

Tareas:

- [ ] Definir contrato de consulta documental contra `ai-stack/rag-api`.
- [ ] Soportar scoping por tenant/producto/knowledge base.
- [ ] Indexar FAQs, objeciones y material comercial.
- [ ] Recuperar contexto semántico por tenant/producto.
- [ ] Usar RAG solo para preguntas específicas o información documental.
- [ ] No usar RAG para inventario vivo/stock/precios rotativos.
- [ ] Añadir trazabilidad de fuentes recuperadas.
- [ ] Controlar latencia y fallback.

---

## 12. Audio WhatsApp

Objetivo: soportar audio entrante de WhatsApp convirtiéndolo a texto antes de enviarlo a SA.

Primera versión:

```text
Audio entrante
→ wa-gateway-api descarga media
→ transcripción OpenAI
→ sales-agent recibe texto
→ respuesta texto por WhatsApp
```

Tareas:

- [ ] Detectar mensajes de audio en webhook de WhatsApp.
- [ ] Descargar media desde Meta.
- [ ] Enviar audio a transcripción OpenAI.
- [ ] Guardar transcripción como inbound message.
- [ ] Enviar texto transcrito a SA.
- [ ] Responder en texto.
- [ ] Registrar coste/latencia de transcripción.
- [ ] Manejar errores de audio no descargable o transcripción fallida.

Fuera de alcance inicial:

- Respuesta por voz.
- Conversación telefónica realtime.

---

## 13. Observabilidad avanzada

Objetivo: poder auditar decisiones, controlar coste y diagnosticar errores por conversación/tenant.

Tareas:

- [ ] Registrar `conversation_id` en todos los logs relevantes.
- [ ] Registrar `tenant_id`, `product_id`, `playbook_id` si aplican.
- [ ] Registrar proveedor LLM usado.
- [ ] Registrar modelo.
- [ ] Registrar latencia total y latencias parciales.
- [ ] Registrar tokens y coste estimado cuando sea posible.
- [ ] Registrar intent, score, action y `needs_human`.
- [ ] Registrar errores clasificados.
- [ ] Registrar llamadas a herramientas externas, incluyendo n8n inventario, CRM, agenda y RAG.
- [ ] Preparar vista mínima de trazas en panel interno o logs consultables.
- [ ] Evaluar integración posterior con Langfuse si aporta valor.

---

## 14. Endpoints y contratos

Objetivo: estabilizar contratos públicos/internos para que los proyectos conectados no rompan.

Tareas:

- [ ] Estabilizar contrato de `POST /agent/respond`.
- [ ] Documentar payloads normalizados de `wa-gateway-api`.
- [ ] Documentar respuesta estructurada de SA.
- [ ] Definir contratos de error.
- [ ] Añadir healthchecks y endpoints de soporte si faltan.
- [ ] Versionar contratos si empieza a haber clientes reales.
- [ ] Mantener ejemplos Postman/cURL actualizados.

---

## 15. Administración y UX interna

Objetivo: hacer operable el sistema desde panel sin depender de edición manual de DB.

Tareas:

- [ ] Mejorar gestión de tenants, products y playbooks.
- [ ] Completar CRUD administrativo para `EntryPoint`, `EntryPointUtm` y `Conversation`.
- [ ] Afinar UX del importador de catálogo CRM para revisión masiva y errores por fila.
- [ ] Mantener el importador de catálogo CRM alineado con `integration_key` y `externalReference`.
- [ ] Definir sincronización opcional con CRM para `crmBranchRef` y atribución externa.
- [ ] Completar edición de perfiles y roles internos.
- [ ] Añadir UI para ExternalTools/n8n webhooks.
- [ ] Mostrar estado de conversación: activa, pendiente humano, cerrada, error, etc.

---

## 16. Calidad y tests

Objetivo: evitar regresiones conforme el runtime se vuelve multi-sistema.

Tareas:

- [ ] Añadir tests de integración del runtime por tenant.
- [ ] Añadir tests de resolución de tenant por `tenant_id` y por `whatsappPhoneNumberId`.
- [ ] Añadir tests de regresión para scoring y handoff.
- [ ] Cubrir validaciones de entidades y contratos de API.
- [ ] Testear fallback OpenAI → heurística.
- [ ] Testear que Ollama no se ejecuta en modo auto.
- [ ] Testear persistencia inbound/outbound.
- [ ] Testear ExternalTool/n8n con mock.
- [ ] Testear CRM contact-context con mock y con errores 401/500.
- [ ] Automatizar el flujo principal en Docker.

---

## 17. Operación y despliegue

Objetivo: mantener un flujo claro de dev/prod y evitar saturar recursos del VPS.

Tareas:

- [ ] Revisar workflow de producción y bootstrap.
- [ ] Confirmar separación final entre dev y prod.
- [ ] Documentar variables de entorno críticas.
- [ ] Mantener `make` como entrada principal para operar el proyecto.
- [ ] Revisar límites/timeouts de OpenAI, Ollama, CRM, n8n y RAG.
- [ ] Evitar puertos públicos innecesarios.
- [ ] Confirmar redes Docker internas/externas.
- [ ] Documentar perfiles dev/manual para Ollama.
- [ ] Añadir checklist de deploy.

---

## Orden resumido recomendado

```text
1. Commit + push del hito actual
2. wa-gateway-api → sales-agent con webhook simulado
3. Persistencia completa ConversationMessage inbound/outbound
4. WhatsApp real inbound → SA → outbound
5. Resolver tenant runtime por tenant_id / whatsappPhoneNumberId
6. Cerrar modelo administrativo Tenant/Product/Playbook
7. CRM contact-context y resolver 401
8. Inventario/catálogo externo vía n8n
9. Handoff humano
10. Agenda/citas CRM vía n8n/CRM
11. RAG tenant/producto
12. Audio WhatsApp
13. Observabilidad avanzada
14. Endpoints/contratos
15. Administración/UX interna
16. Calidad/tests
17. Operación/despliegue
```