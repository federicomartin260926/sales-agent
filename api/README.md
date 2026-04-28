# FastAPI Agent Runtime

Este directorio contiene el runtime del agente conversacional.

## Qué hace

- expone `GET /health`
- expone `POST /agent/respond`
- decide respuestas simples sin invocar todavía un LLM real
- deja listos los clientes para LLM, CRM, RAG y backend
- protege el runtime con `Authorization: Bearer <token>` para tráfico service-to-service

## Stack

- FastAPI
- uvicorn
- httpx
- pydantic-settings
- Python 3.12

## Flujo

1. recibe un mensaje de un tenant
2. valida el bearer token de integración
3. ejecuta el `DecisionEngine`
4. devuelve una respuesta estructurada con intención, score y acción sugerida

## Contrato de dominio

### Entrada

`POST /agent/respond` acepta un payload normalizado con:

- `tenant_id`
- `message`
- `contact`
- `conversation`

#### `message`

`message` se normaliza internamente a una estructura con:

- `id` opcional
- `type`
- `text`
- `timestamp` opcional

El runtime también acepta el formato simple `message: "..."` para pruebas o integraciones ligeras.

#### `contact`

`contact` requiere un teléfono y puede incluir:

- `phone`
- `wa_id`
- `from`
- `name`

#### `conversation`

`conversation.last_messages` es una lista de textos ya normalizados.

### Salida

`POST /agent/respond` devuelve siempre:

- `reply`
- `intent`
- `score`
- `action`
- `needs_human`
- `data_to_save`

La respuesta debe ser estructurada para que `wa-gateway-api` decida cómo enviarla y el CRM decida qué persistir.

## Formato de entrada

`POST /agent/respond` acepta:

- el formato simple del runtime, con `message` como texto y `contact.phone`
- el formato normalizado que envía `wa-gateway-api`, con:
  - `message.id`
  - `message.type`
  - `message.text`
  - `message.timestamp`
  - `contact.wa_id`
  - `contact.from`
  - `contact.name`

Internamente se normaliza a la forma que consume `DecisionEngine`.

## Autenticación

`POST /agent/respond` exige un token enviado en:

- `Authorization: Bearer <SALES_AGENT_BEARER_TOKEN>`

Este token no representa un usuario humano. Es para la comunicación entre `wa-gateway-api` y `sales-agent/api`.
En local y en producción se configura con la variable `SALES_AGENT_BEARER_TOKEN`.

## Extensión futura

- `llm_client.py`: OpenAI u Ollama
- `crm_client.py`: contexto desde el CRM
- `rag_client.py`: recuperación semántica
- `backend_client.py`: lectura de configuración del backend Symfony
