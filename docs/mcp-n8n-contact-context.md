# MCP - n8n Contact Context

Este documento define el contrato operativo para consultar contexto de cliente a través de n8n cuando el runtime usa `contact_context` como herramienta externa.

La idea es separar claramente:

- autenticación del webhook `SA/MCP -> n8n`
- autorización downstream `n8n -> CRM`

## Endpoint

Ejemplo de webhook:

```text
POST http://localhost:5680/webhook/sa-contact-context
```

La URL real puede ser distinta por tenant o entorno, pero el contrato del request es el mismo.

## Headers

La llamada debe incluir dos credenciales distintas:

- `Authorization: Bearer <N8N_WEBHOOK_TOKEN>`
- `X-Downstream-Authorization: Bearer <CRM_DOWNSTREAM_TOKEN>`

Reglas:

- `Authorization` protege el webhook de n8n
- `X-Downstream-Authorization` transporta el token tenant-scoped que n8n puede usar para consultar CRM
- ninguno de los dos tokens va en el body
- ninguno de los dos tokens va al prompt
- ninguno de los dos tokens se usa como argumento de tool

## Request body

El body debe ser JSON y mantenerse estable.

Ejemplo:

```json
{
  "tool": "contact_context",
  "contact": {
    "phone": "+34601208579",
    "email": null,
    "name": "Lucia Garcia"
  },
  "channel": "whatsapp",
  "source": "mcp-gateway"
}
```

Campos esperados:

- `tool`: siempre `contact_context`
- `contact.phone`: teléfono del cliente en formato internacional
- `contact.email`: opcional
- `contact.name`: opcional
- `channel`: normalmente `whatsapp`
- `source`: origen del request, por ejemplo `mcp-gateway`

Si el runtime o el gateway enriquecen más datos, pueden añadirse como extensiones del contrato, pero los campos anteriores deben mantenerse.

## Respuesta esperada

Respuesta recomendada desde n8n:

```json
{
  "ok": true,
  "found": true,
  "data": {
    "contact": {
      "phone": "+34601208579",
      "name": "Lucia Garcia",
      "email": null
    },
    "lead": {
      "id": "lead-123",
      "status": "qualified",
      "stage": "proposal"
    },
    "opportunity": {
      "id": "opp-123",
      "pipeline": "default",
      "stage": "proposal"
    },
    "flags": {
      "needsHuman": false,
      "alreadyContacted": true
    },
    "summary": "Lead cualificado y en seguimiento."
  }
}
```

Si no hay contexto útil:

```json
{
  "ok": true,
  "found": false,
  "data": null
}
```

Si hay error funcional:

```json
{
  "ok": false,
  "error_code": "tool_error",
  "error_message": "CRM downstream unavailable"
}
```

## Uso en Sales Agent

`sales-agent` consume este contrato como herramienta externa `contact_context`.

El cliente de herramientas externas:

- llama al webhook del tenant
- envía `Authorization` con la credencial del webhook si está configurada
- envía `X-Downstream-Authorization` con el token downstream del tenant cuando corresponda
- normaliza la respuesta a `available`, `configured`, `ok`, `found`, `error_code` y `data`

## Relación con CRM

`Sales Agent` no llama al CRM directamente desde este contrato.

El flujo esperado es:

1. Sales Agent consulta n8n.
2. n8n usa el token downstream tenant-scoped para consultar CRM u otra fuente.
3. n8n normaliza la respuesta.
4. Sales Agent consume la respuesta normalizada.

## Notas de seguridad

- no reutilizar `ExternalTool.bearer_token` como credencial de webhook si ya representa autorización downstream
- no exponer tokens en logs, trazas ni payloads visibles
- si una integración no necesita downstream, puede omitir `X-Downstream-Authorization`
