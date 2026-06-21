#!/usr/bin/env bash
set -u

show_help() {
  cat <<'EOF'
Sales Agent E2E conversation probe

Ejecuta una conversación completa contra /agent/respond usando curl y muestra una inspección útil
por turno: reply, intent, action, tool plan, estado de cita, selected_slot, MCP traces y resultados
normalizados de appointment_confirm / appointment_reschedule / appointment_cancel.

Uso:
  scripts/e2e/agent_conversation_probe.sh "mensaje 1" "mensaje 2" "mensaje 3"

Opciones:
  -h, --help       Muestra esta ayuda.

Variables de entorno:
  TENANT_ID                    Tenant a probar.
                               Default: 019e4a9a-c85f-72d4-8748-b756073c324c

  API_PORT                     Puerto local de sales-agent-api.
                               Default: 8000

  SALES_AGENT_BEARER_TOKEN     Bearer token local.
                               Default: sales-agent-bearer-token

  CHANNEL_TYPE                 Canal conversacional.
                               Default: whatsapp

  EXTERNAL_CHANNEL_ID          Identificador externo de canal.
                               Default: test-whatsapp-e2e

  ENTRYPOINT_REF               Entry point ref.
                               Default: mary-main

  CONTACT_PHONE                Teléfono del contacto.
                               Default: +34678180164

  CONTACT_NAME                 Nombre del contacto.
                               Default: Federico Martín Peña

  CONV                         ID externo de conversación canónico. Se envía como
                               external_conversation_id. Si no se define, se genera uno.
                               Default: e2e-agent-YYYYMMDDHHMMSS

  OUT_DIR                      Directorio donde guardar respuestas JSON.
                               Default: /tmp

Ejemplo 1: crear una cita nueva

  CONV="e2e-booking-create-$(date +%Y%m%d%H%M%S)" \
  scripts/e2e/agent_conversation_probe.sh \
    "Quiero reservar láser axilas el lunes 22 de junio por la tarde con María Gutiérrez" \
    "Me va bien el primer hueco disponible" \
    "Sí, confirma la cita"

Qué revisar:
  - appointment_confirmed=True
  - appointment_confirm_status=confirmed
  - appointment_id=...
  - appointment_start_at=...
  - appointment_end_at=...

Ejemplo 2: reprogramar una cita existente

  CONV="e2e-booking-reschedule-$(date +%Y%m%d%H%M%S)" \
  scripts/e2e/agent_conversation_probe.sh \
    "Quiero cambiar mi cita de láser axilas del lunes 22 de junio a las 17:30" \
    "Quiero cambiar esa cita" \
    "Búscame otro hueco el lunes 22 por la tarde con María Gutiérrez" \
    "Me va bien el siguiente hueco disponible" \
    "Sí, confirma el cambio"

Qué revisar:
  - appointment_events se usa para localizar la cita existente si hace falta.
  - existing_appointment queda identificado antes de seleccionar nuevo slot.
  - appointment_availability devuelve huecos.
  - selected_slot aparece antes de confirmar.
  - appointment_rescheduled=True
  - appointment_reschedule_status=rescheduled
  - appointment_reschedule_error_code=None

Ejemplo 3: cancelar una cita existente

  CONV="e2e-booking-cancel-$(date +%Y%m%d%H%M%S)" \
  scripts/e2e/agent_conversation_probe.sh \
    "Quiero cancelar mi cita de láser axilas del lunes 22 de junio a las 17:30" \
    "Sí, cancélala"

Qué revisar:
  - existing_appointment queda identificado.
  - appointment_cancelled=True
  - appointment_cancel_status=cancelled
  - appointment_cancel_error_code=None

Ejemplo 4: usar otro contacto o tenant

  TENANT_ID="tenant-uuid" \
  CONTACT_PHONE="+34000000000" \
  CONTACT_NAME="Cliente Prueba" \
  CONV="e2e-custom-$(date +%Y%m%d%H%M%S)" \
  scripts/e2e/agent_conversation_probe.sh \
    "Quiero reservar una cita" \
    "Me interesa láser axilas"

Notas:
  - El script guarda cada respuesta en OUT_DIR como:
    /tmp/sa-<CONV>-<step>.json
  - No borra ni revierte datos creados en CRM.
  - Si una prueba crea una cita, cancelarla o borrarla manualmente si no debe quedar como dato fixture.
  - Este script es una herramienta de inspección manual, no un test automatizado determinista.
EOF
}

if [[ "${1:-}" == "-h" || "${1:-}" == "--help" ]]; then
  show_help
  exit 0
fi

if [[ "$#" -eq 0 ]]; then
  show_help
  exit 1
fi

TENANT_ID="${TENANT_ID:-019e4a9a-c85f-72d4-8748-b756073c324c}"
API_PORT="${API_PORT:-8000}"
SALES_AGENT_BEARER_TOKEN="${SALES_AGENT_BEARER_TOKEN:-sales-agent-bearer-token}"
CHANNEL_TYPE="${CHANNEL_TYPE:-whatsapp}"
EXTERNAL_CHANNEL_ID="${EXTERNAL_CHANNEL_ID:-test-whatsapp-e2e}"
ENTRYPOINT_REF="${ENTRYPOINT_REF:-mary-main}"
CONTACT_PHONE="${CONTACT_PHONE:-+34678180164}"
CONTACT_NAME="${CONTACT_NAME-Federico Martín Peña}"
CONV="${CONV:-e2e-agent-$(date +%Y%m%d%H%M%S)}"
OUT_DIR="${OUT_DIR:-/tmp}"

echo "CONV=$CONV"
echo "TENANT_ID=$TENANT_ID"
echo "API_PORT=$API_PORT"
echo "CHANNEL_TYPE=$CHANNEL_TYPE"
echo "EXTERNAL_CHANNEL_ID=$EXTERNAL_CHANNEL_ID"
echo "ENTRYPOINT_REF=$ENTRYPOINT_REF"
echo "CONTACT_PHONE=$CONTACT_PHONE"
echo "CONTACT_NAME=$CONTACT_NAME"
echo "OUT_DIR=$OUT_DIR"

run_turn() {
  local step="$1"
  local message="$2"
  local file="${OUT_DIR}/sa-${CONV}-${step}.json"
  local message_id="${CONV}-${step}-$(date +%s%N)"

  local payload
  payload="$(python3 - "$TENANT_ID" "$CHANNEL_TYPE" "$EXTERNAL_CHANNEL_ID" "$ENTRYPOINT_REF" "$CONV" "$message_id" "$message" "$CONTACT_PHONE" "$CONTACT_NAME" <<'PY'
import json
import sys

tenant_id, channel_type, external_channel_id, entrypoint_ref, external_conversation_id, message_id, message_text, contact_phone, contact_name = sys.argv[1:10]

payload = {
    "tenant_id": tenant_id,
    "channel_type": channel_type,
    "external_channel_id": external_channel_id,
    "entrypoint_ref": entrypoint_ref,
    "external_conversation_id": external_conversation_id,
    "message": {
        "id": message_id,
        "type": "text",
        "text": message_text,
    },
    "contact": {
        "phone": contact_phone,
        "name": contact_name,
    },
}

print(json.dumps(payload, ensure_ascii=False))
PY
)"

  curl -sS -w "\nHTTP_STATUS:%{http_code}\n" -X POST "http://localhost:${API_PORT}/agent/respond" \
    -H "Authorization: Bearer ${SALES_AGENT_BEARER_TOKEN}" \
    -H "Content-Type: application/json" \
    --data-binary "$payload" > "$file"

  python3 - "$file" "$step" <<'PY'
import json
import sys
from pprint import pprint

path = sys.argv[1]
step = sys.argv[2]

raw = open(path).read()
status = None
body = raw

if "\nHTTP_STATUS:" in raw:
    body, status = raw.rsplit("\nHTTP_STATUS:", 1)
    status = status.strip()

print("\n" + "=" * 110)
print("STEP:", step)
print("FILE:", path)
print("HTTP_STATUS:", status)

if status != "200":
    print("RAW BODY:")
    print(body[:3000])
    raise SystemExit(0)

try:
    data = json.loads(body)
except Exception as exc:
    print("JSON ERROR:", exc)
    print("RAW BODY:")
    print(body[:3000])
    raise SystemExit(0)

d = data.get("data_to_save", {}) or {}
summary = d.get("runtime_context_summary") or {}
tool_plan = d.get("tool_plan") or {}
traces = d.get("mcp_tool_traces") or []

print("reply:", data.get("reply"))
print("intent:", data.get("intent"))
print("action:", data.get("action"))
print("needs_human:", data.get("needs_human"))

print("\nTOOL PLAN:")
print("allowed_tools:", tool_plan.get("allowed_tools"))
print("must_call_tool:", tool_plan.get("must_call_tool"))
print("reason:", tool_plan.get("reason"))

print("\nSTATE:")
for field in [
    "required_next_action",
    "appointment_lookup_required",
    "existing_appointment_selection_required",
    "existing_appointment_required_before_slot",
    "booking_confirmation_blocked_by_existing_appointment_resolution",
    "appointment_events_required_but_not_called",
    "existing_appointment_resolution_blocked",
]:
    print(f"{field}:", d.get(field))

print("runtime_required_next_action:", summary.get("required_next_action"))
print("has_existing_appointment:", summary.get("has_existing_appointment"))
print("existing_appointments_count:", summary.get("existing_appointments_count"))

print("\nEXISTING APPOINTMENT:")
pprint(d.get("existing_appointment"), width=180)

print("\nEXISTING APPOINTMENTS SAMPLE:")
appointments = d.get("existing_appointments") or []
print("count:", len(appointments) if isinstance(appointments, list) else None)
if isinstance(appointments, list):
    for i, a in enumerate(appointments[:5], 1):
        print(i, a.get("id"), a.get("start"), a.get("end"), a.get("timezone"), a.get("title"))

print("\nSELECTED SLOT:")
pprint(d.get("selected_slot"), width=180)

print("\nMCP TRACES:")
for i, t in enumerate(traces, 1):
    print(f"- #{i}", t.get("type"), t.get("tool_name"), t.get("status"))
    if t.get("tool_name") in {
        "services_search",
        "appointment_events",
        "appointment_availability",
        "appointment_confirm",
        "appointment_reschedule",
        "appointment_cancel",
        "crm_contact_submit",
        "handoff_request",
    }:
        print("  arguments:")
        pprint(t.get("arguments"), width=180)

print("\nNORMALIZED RESULTS:")
for field in [
    "appointment_confirm_post_processed",
    "appointment_confirmed",
    "appointment_confirm_status",
    "appointment_reschedule_post_processed",
    "appointment_rescheduled",
    "appointment_reschedule_status",
    "appointment_cancel_post_processed",
    "appointment_cancelled",
    "appointment_cancel_status",
    "appointment_id",
    "appointment_start_at",
    "appointment_end_at",
    "appointment_old_start_at",
    "appointment_old_end_at",
    "appointment_new_start_at",
    "appointment_new_end_at",
    "appointment_confirm_error_code",
    "appointment_reschedule_error_code",
    "appointment_cancel_error_code",
]:
    print(f"{field}:", d.get(field))
PY
}

step=1
for message in "$@"; do
  run_turn "$(printf "%02d" "$step")" "$message"
  step=$((step + 1))
done
