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
3. consulta el backend Symfony para cargar el contexto del negocio, producto y guía comercial
4. ejecuta el `DecisionEngine`
5. devuelve una respuesta estructurada con intención, score y acción sugerida

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

La respuesta debe ser estructurada para que `wa-gateway-api` decida cómo enviarla y cualquier componente posterior decida qué guardar.

## Integración con Symfony

FastAPI lee contexto comercial desde el backend Symfony a través de:

- `BACKEND_BASE_URL`

En Docker local el valor por defecto apunta a:

- `http://sales-agent-nginx/backend`

El runtime consulta:

- `GET /api/tenants/{tenant_id}`
- `GET /api/products`
- `GET /api/playbooks`

Si el backend no está disponible o el tenant no existe, el runtime cae a un modo de fallback basado en heurísticas simples para no romper la conversación.

## Integración con CRM

El runtime también puede leer contexto del CRM a través de:

- `CRM_BASE_URL`

En esta fase se espera este contrato:

- `GET /api/agent/contact-context?phone=<phone>`

El CRM devuelve contexto de contacto, lead y oportunidad para:

- evitar preguntas redundantes
- detectar si el lead ya está avanzado
- mejorar el handoff a humano

El runtime no escribe en el CRM. Solo consume ese contexto para enriquecer la decisión conversacional.

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

- `crm_client.py`: contexto desde el CRM
- `rag_client.py`: recuperación semántica
- `llm_client.py`: OpenAI u Ollama
