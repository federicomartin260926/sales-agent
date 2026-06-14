# Debug Python API in Docker

Esta configuración permite depurar `sales-agent-api` con VS Code usando `debugpy` en modo attach.

## Arranque

Levanta el servicio dev:

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d --force-recreate sales-agent-api
```

El contenedor expone:

- `8000` para la API
- `5678` para debugpy

## Attach en VS Code

1. Abre el proyecto en VS Code desde `~/www/sales-agent`.
2. Selecciona la configuración `Attach sales-agent-api Docker`.
3. Inicia el attach.
4. Pon breakpoints en:
   - `api/app/services/runtime.py`
   - `api/app/services/agent_orchestration/shadow/shadow_planning_service.py`
   - `api/app/services/agent_orchestration/planning/intent_planner.py`
   - `api/app/services/agent_orchestration/context/context_expansion_router.py`
   - `api/app/services/agent_orchestration/tool_policy/tool_policy_service.py`
   - `api/app/services/agent_orchestration/execution/catalog_execution_service.py`

## Pruebas rápidas

Comprueba que la API responde:

```bash
curl -s http://localhost:8000/health
```

Comprueba que debugpy escucha en `5678`:

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml exec sales-agent-api python - <<'PY'
import socket
s = socket.socket()
print(s.connect_ex(("127.0.0.1", 5678)))
PY
```

`0` significa que el puerto está abierto.

## Request de ejemplo

Cuando el debugger ya esté conectado, lanza una request real a `/agent/respond` y el breakpoint debería frenar dentro de `runtime.py`.

```bash
curl -s http://localhost:8000/agent/respond \
  -H 'Content-Type: application/json' \
  -d '{"tenant_id":"tenant-1","entrypoint_ref":"abc123","message":"Quiero información sobre láser cuerpo entero","contact":{"phone":"+34999999999"}}'
```

## Nota

`--wait-for-client` no está activado por defecto para no bloquear el arranque normal. Si necesitas detenerte antes de que la app procese tráfico, puedes añadirlo temporalmente al `command` de `docker-compose.dev.yml`.
