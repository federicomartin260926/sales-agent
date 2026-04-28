# Contrato de CRM

Este documento define el contrato que `sales-agent` espera del CRM para enriquecer respuestas antes de contestar.

## Objetivo

El CRM aporta contexto del contacto y del lead para que el agente:

- no repita preguntas que ya fueron respondidas
- adapte el tono según el estado comercial
- derive antes a humano cuando la oportunidad ya está avanzada

## Lectura

### Endpoint esperado

`sales-agent/api` consulta:

- `GET /api/agent/contact-context?phone=<phone>`

### Respuesta esperada

El CRM debería responder con un objeto que pueda incluir:

- `contact`
- `lead`
- `opportunity`
- `flags`
- `recentNotes`
- `lastActivityAt`
- `summary`

### `contact`

- `phone` obligatorio
- `name` opcional
- `email` opcional

### `lead`

- `id` opcional
- `status` opcional
- `stage` opcional
- `ownerName` opcional
- `score` opcional
- `source` opcional
- `isQualified` opcional
- `lastInteractionAt` opcional
- `lastTouchSummary` opcional
- `notes` opcional

### `opportunity`

- `id` opcional
- `pipeline` opcional
- `stage` opcional
- `nextAction` opcional
- `amount` opcional

### `flags`

- `alreadyContacted` opcional
- `askedForPrice` opcional
- `askedForDemo` opcional
- `needsHuman` opcional

## Salida interna

`sales-agent` devuelve una respuesta estructurada para que otros componentes del stack decidan si guardan o no eventos, resúmenes o estados derivados.

La salida del runtime incluye:

- `reply`
- `intent`
- `score`
- `action`
- `needs_human`
- `data_to_save`

`data_to_save` es un paquete de contexto operacional para integraciones posteriores. No implica escritura directa en el CRM desde `sales-agent`.

## Reglas

- El CRM sigue siendo la fuente maestra.
- `sales-agent` usa el CRM para evitar redundancia y mejorar handoff.
- Si el CRM no responde, el runtime sigue funcionando con el contexto del backend y fallback heurístico.
