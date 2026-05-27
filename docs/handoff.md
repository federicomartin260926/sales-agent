# Handoff Humano

`sales-agent` distingue entre derivación manual al equipo humano y la autorización downstream usada por MCP/CRM.

## Configuración funcional

La política funcional de handoff vive en `Tenant` y se expone al runtime por `commercial-context`.

Campos:

- `humanHandoffEnabled`
- `humanHandoffWhatsappPublic`
- `humanHandoffMessage`
- `humanHandoffStrategy`

Estrategias:

- `disabled`
- `manual_wa_link`
- `n8n_webhook`
- `manual_wa_link_and_n8n`

## Autorización

- `SA -> n8n`: credencial del webhook, si se usa, debe ser separada de cualquier token downstream.
- `n8n/MCP -> CRM`: token tenant-scoped cifrado, sin exponer en prompt, payload ni logs.

`ExternalTool.bearer_token` no se reutiliza como auth del webhook de handoff.

## Flujo downstream real

El runtime LLM mantiene la semántica actual de autorización downstream:

- `api/app/services/llm_client.py` construye la configuración de herramientas MCP para OpenAI Responses API.
- Cuando existe un token downstream para el tenant, se pasa como autorización segura hacia el servidor MCP remoto.
- Ese token representa acceso tenant-scoped para el flujo `SA -> OpenAI Responses API -> MCP remoto -> n8n/CRM`.
- No se serializa en el prompt, no viaja como argumento de tool, no se inserta en el payload de handoff y no debe aparecer en logs o traces visibles.
- Ese mismo token no debe reutilizarse como credencial del webhook de handoff `SA -> n8n`.

Si una integración necesita proteger el webhook de handoff, debe usar una credencial separada y nombrada explícitamente.

## Payload n8n

El evento estable es:

- `event = sales_agent.handoff_requested`

El payload debe viajar sin secretos. Si n8n necesita crear tareas en CRM o notificaciones humanas, lo hace con su propia configuración. SA sólo entrega contexto operativo.

Ejemplo resumido:

```json
{
  "event": "sales_agent.handoff_requested",
  "event_id": "uuid",
  "occurred_at": "2026-05-27T12:00:00+00:00",
  "tenant": {
    "id": "tenant-id",
    "name": "Tenant Demo",
    "slug": "tenant-demo"
  },
  "conversation": {
    "id": "conversation-id",
    "status": "pending_human",
    "channel": "whatsapp",
    "external_conversation_id": "external-id",
    "last_messages": []
  },
  "contact": {
    "name": "Ana",
    "phone": "+34999999999",
    "email": null,
    "external_id": null
  },
  "entry_point": {
    "id": "entry-point-id",
    "name": "WhatsApp inbound",
    "channel": "whatsapp",
    "external_ref": "abc123"
  },
  "product": {
    "id": "product-id",
    "name": "Producto",
    "slug": "producto",
    "external_ref": "pack-starter",
    "source": "crm"
  },
  "decision": {
    "intent": "handoff",
    "action": "handoff_to_human",
    "needs_human": true,
    "score": 0.95,
    "reason": "Human handoff requested",
    "trigger": "user_requested_human"
  },
  "llm": {
    "provider": "openai",
    "model": "gpt-4.1-mini",
    "response_id": "resp_123",
    "latency_ms": 1234
  },
  "metadata": {
    "source": "sales-agent"
  }
}
```

## Contrato futuro CRM

Cuando n8n quiera crear una tarea o handoff en CRM, el contrato esperado será:

- `POST /api/integrations/handoffs`
- `tenantId`
- `source=sales_agent`
- `externalConversationId`
- `contact` con `phone`, `email`, `name`
- `title`
- `description`
- `priority`
- `reason`
- `lastMessages`
- `metadata`
- `assignedOwnerId` opcional
- `dueAt` opcional
- `status=pending/open`
