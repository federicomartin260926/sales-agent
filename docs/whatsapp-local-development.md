# WhatsApp Cloud API en desarrollo local

Esta guía documenta el entorno local utilizado para probar el circuito real de WhatsApp Cloud API contra Sales Agent, MCP/n8n y CRM.

Última validación E2E conocida: **21/09/2026**.

## Topología

El flujo local validado es:

```text
WhatsApp
  -> Meta WhatsApp Cloud API
  -> https://lavish-supply-custodian.ngrok-free.dev
  -> mcp-gateway-ngrok
  -> mcp-dev-router:8088
  -> host.docker.internal:8001
  -> wa-gateway-api
  -> host.docker.internal:8000/agent/respond
  -> sales-agent/api
  -> LLM / MCP
  -> mcp-gateway
  -> n8n
  -> CRM
  -> sales-agent/api
  -> wa-gateway-api
  -> Meta Graph API
  -> WhatsApp
```

Callback público de Meta:

```text
https://lavish-supply-custodian.ngrok-free.dev/webhooks/whatsapp
```

## Componentes locales

### Sales Agent

Repositorio:

```text
~/www/sales-agent
```

API local:

```text
http://localhost:8000
```

### WhatsApp Gateway

Repositorio:

```text
~/www/wa-gateway-api
```

En desarrollo debe levantarse con el Compose base y el override de desarrollo:

```bash
cd ~/www/wa-gateway-api

docker compose \
  -f docker-compose.yml \
  -f docker-compose.dev.yml \
  up -d --force-recreate wa-gateway-api
```

El override publica:

```text
localhost:8001 -> container:8000
```

No recrear el gateway únicamente con `docker-compose.yml` durante pruebas locales, porque se pierde el binding `8001:8000` utilizado por `mcp-dev-router`.

El gateway llama al Sales Agent local mediante:

```text
http://host.docker.internal:8000
```

### MCP gateway, router y ngrok

Repositorio:

```text
~/www/mcp-gateway
```

Servicios implicados:

```text
mcp-gateway
mcp-dev-router
mcp-gateway-ngrok
```

`dev-router` y `ngrok` están definidos en `docker-compose.dev.yml` bajo el profile `tunnel`.

El túnel local puede levantarse con:

```bash
cd ~/www/mcp-gateway

docker compose \
  -f docker-compose.yml \
  -f docker-compose.dev.yml \
  --profile tunnel \
  up -d --no-deps dev-router ngrok
```

El router Nginx escucha en `8088` dentro de la red Docker y enruta WhatsApp hacia:

```text
/webhooks/whatsapp
    -> http://host.docker.internal:8001/webhooks/whatsapp

/internal/media/whatsapp/
    -> http://host.docker.internal:8001/internal/media/whatsapp/
```

ngrok publica actualmente:

```text
https://lavish-supply-custodian.ngrok-free.dev
```

## Configuración Meta validada

Configuración validada el 21/09/2026:

```text
META_API_VERSION=v26.0
META_PHONE_NUMBER_ID=1148269451693531
```

El número sandbox anterior continúa operativo.

El access token utilizado es de tipo `SYSTEM_USER`. Durante la validación Meta devolvió:

```text
is_valid=true
expires_at=0
data_access_expires_at=0
```

No tiene una fecha de expiración programada, aunque puede invalidarse por revocación o cambios de permisos, aplicación, System User o activos.

Scopes observados:

```text
whatsapp_business_management
whatsapp_business_messaging
public_profile
```

## Seguridad de credenciales

Nunca mostrar ni copiar en logs compartidos valores de:

```text
META_ACCESS_TOKEN
META_APP_SECRET
META_VERIFY_TOKEN
SALES_AGENT_BEARER_TOKEN
NGROK_AUTHTOKEN
```

Evitar especialmente compartir la salida completa de:

```bash
docker compose config
```

porque expande variables de entorno y puede revelar secretos.

Las comprobaciones deben ocultar o redactar cualquier credencial.

## Health local

Comprobar:

```bash
curl -fsS http://localhost:8001/health
```

Resultado esperado:

```json
{"status":"ok","service":"wa-gateway-api"}
```

Después de recrear el contenedor puede existir una carrera corta mientras Uvicorn termina de arrancar. Esperar a `Application startup complete` o reintentar el health antes de diagnosticar un fallo.

## Verificación del callback público

El callback puede probarse obteniendo internamente el verify token sin imprimirlo:

```bash
VERIFY_TOKEN="$(
  docker inspect wa-gateway-api \
    --format '{{range .Config.Env}}{{println .}}{{end}}' \
  | sed -n 's/^META_VERIFY_TOKEN=//p'
)"

curl -sS \
  --get \
  'https://lavish-supply-custodian.ngrok-free.dev/webhooks/whatsapp' \
  --data-urlencode 'hub.mode=subscribe' \
  --data-urlencode "hub.verify_token=${VERIFY_TOKEN}" \
  --data-urlencode 'hub.challenge=123456789'
```

Resultado esperado:

```text
123456789
```

## Routing sandbox

Para WhatsApp Cloud real, `phone_number_id` es la señal principal del inbound orgánico.

Cuando se utiliza un único número sandbox de Meta:

- asignar `Tenant.whatsappPhoneNumberId` solo al tenant que se quiere probar;
- dejarlo vacío en los demás tenants;
- no inferir el tenant desde el teléfono del cliente.

## E2E real validado

El 21/09/2026 se validó desde un teléfono real el mensaje:

```text
Hola, quiero información
```

Circuito observado:

```text
WhatsApp
-> Meta
-> ngrok
-> mcp-dev-router
-> wa-gateway-api
-> sales-agent/api
-> contact_context vía MCP
-> mcp-gateway
-> n8n workflow T&I
-> contexto comercial
-> Sales Agent
-> wa-gateway-api
-> Graph API v26.0
-> WhatsApp
```

La ejecución n8n asociada fue:

```text
workflow: T&I
execution: 2762
mode: webhook
status: success
```

La respuesta outbound recibió de Meta:

```text
sent
delivered
read
```

Durante el flujo también se verificó:

- resolución del tenant mediante `phone_number_id`;
- recuperación del commercial context;
- `Conversation` upsert;
- persistencia del mensaje inbound;
- ejecución MCP `contact_context`;
- persistencia del mensaje outbound;
- envío por Meta Graph API v26.0.

La prueba real de audio no se revalidó en esta sesión.

## Pre-demo check

Antes de una demostración comprobar:

1. Sales Agent, CRM, MCP gateway, n8n y WA gateway levantados.
2. `wa-gateway-api` publicado en `localhost:8001`.
3. `mcp-dev-router` y ngrok activos.
4. health local del WA gateway.
5. callback público de Meta.
6. `META_API_VERSION` soportada y no próxima a retirada.
7. token Meta válido.
8. acceso al `META_PHONE_NUMBER_ID`.
9. tenant de demo asociado al `whatsappPhoneNumberId` sandbox.
10. mensaje real de smoke test antes de presentar.

## Mantenimiento pendiente

- Actualizar producción a la versión Graph API validada.
- Actualizar defaults y ejemplos de `wa-gateway-api` que todavía usen versiones antiguas.
- Revisar los flags deprecated de ngrok:
  - `--domain` -> `--url`;
  - `--host-header` -> traffic policy.
- Revalidar audio real.
- Mantener un smoke test previo a cada demo.
