# E2E autónomos de Sales Agent

## Objetivo

Los E2E autónomos validan el comportamiento conversacional real de Sales Agent
contra `/agent/respond`, incluyendo planificación LLM, tools MCP, persistencia,
contexto estructurado y, cuando se habilita expresamente, integraciones de
escritura reales.

El runner está en:

- `scripts/e2e/autonomous/run_scenario.py`
- `scripts/e2e/autonomous/run_suite.py`
- `scripts/e2e/autonomous/customer_simulator.py`
- `scripts/e2e/autonomous/evaluator.py`
- `scripts/e2e/autonomous/scenarios/`

No sustituyen los tests unitarios ni funcionales. Su objetivo es validar el
sistema conversacional integrado con evidencia estructurada.

## Principios

- El LLM interpreta la conversación y decide qué lecturas necesita.
- Sales Agent aporta contexto, persistencia, contratos y gating de tools.
- Los E2E no duplican mediante heurísticas las reglas semánticas del agente.
- La evaluación usa acciones estructuradas, llamadas MCP y outputs reales; no
  intenta inferir éxito buscando palabras en la respuesta al cliente.
- Las tools de escritura reales se consideran efectos externos y se protegen
  expresamente.
- Una write nunca se repite automáticamente si el resultado queda ambiguo.
- Durante debug se continúa desde el último punto válido siempre que sea
  posible, en lugar de repetir un flujo completo.

## Perfiles

### `dry`

Es el perfil por defecto:

```bash
python3 scripts/e2e/autonomous/run_suite.py --profile dry
```

Incluye escenarios de catálogo y agenda que no ejecutan writes reales.

Actualmente cubre:

- búsqueda exacta de servicios;
- refinamiento multi-turno;
- búsqueda sin resultados;
- reserva preparada sin confirmar;
- reprogramación preparada;
- cancelación preparada;
- regresión de servicio único;
- selección multiservicio;
- ambigüedad multiservicio;
- añadir o sustituir servicios durante el flujo;
- ausencia de disponibilidad;
- invitación de reserva preparada;
- verificación de cita existente.

### `full-live`

Añade al perfil `dry` las integraciones live explícitamente incluidas:

- `contact_submit_new_contact_live`;
- `handoff_explicit_request_live`.

Requiere obligatoriamente:

```bash
SA_E2E_ALLOW_WRITES=1 python3 scripts/e2e/autonomous/run_suite.py --profile full-live
```

Si falta `SA_E2E_ALLOW_WRITES=1`, la suite termina antes de ejecutar el primer
escenario.

`full-live` produce efectos externos reales. No debe ejecutarse como una suite
de regresión rutinaria.

## Guardrail de writes

Un escenario live puede declarar:

- `requires_write_opt_in_before_start`;
- `expected_write_tool`;
- `write_occurs_on_ready_turn`;
- `ready_action`.

Si `requires_write_opt_in_before_start=true` y no existe
`SA_E2E_ALLOW_WRITES=1`, el runner finaliza con:

- cero turnos;
- cero llamadas HTTP;
- cero tools;
- cero writes.

Esta barrera puede verificarse apuntando deliberadamente a una URL imposible:
si el runner termina sin error de conexión, no intentó realizar el primer POST.

## Semántica de writes

### Agenda

`appointment_confirm`, `appointment_reschedule` y `appointment_cancel`
requieren la confirmación conversacional explícita correspondiente antes de
ejecutarse.

La mera selección de un slot no autoriza la escritura.

### Contact Submit

`crm_contact_submit` puede ejecutarse en el mismo turno en el que el cliente
pide ser contactado cuando el plan estructurado autoriza
`create_or_update_crm_contact`.

No se añade una confirmación artificial específica para guardar el contacto.

Cuando `tool_plan.bootstrap_tool=contact_context`, debe consultarse primero ese
contexto antes del write.

El escenario de contacto nuevo usa identidad E2E aislada y verifica el contrato
de la respuesta real:

- `ok=true`;
- `submitted=true`;
- `status=accepted`.

La verificación posterior se realiza mediante `contact_context` y debe resolver
el contacto creado.

### Handoff

Una petición explícita del cliente de hablar con una persona puede autorizar
`handoff_request` en ese mismo turno.

Se valida:

- exactamente una llamada `handoff_request`;
- `ok=true`;
- `handoff_requested=true`;
- `status=accepted`;
- ninguna write adicional.

Mientras no exista una lectura CRM específica de handoff, la evidencia live
termina en MCP/n8n y `crm_verification` queda `SKIPPED`.

## `booking_live`

`scripts/e2e/autonomous/scenarios/booking_live.json` es un escenario live
histórico/controlado de agenda.

No forma parte de `dry` ni de `full-live`.

Crea una cita real y actualmente no implementa cleanup automático. No debe
repetirse como parte de una suite rutinaria. Cualquier futura cobertura live de
agenda debería diseñarse como un flujo controlado create -> verify -> cleanup,
con cada write protegida explícitamente.

## Evidencia generada

Cada escenario genera un directorio con, entre otros:

- `transcript.json`;
- `evaluation.json`;
- `report.md`.

La suite genera además:

- `suite-report.json`;
- `suite-report.md`.

Las trazas incluyen:

- conversación;
- acción/intención estructurada;
- tools permitidas;
- llamadas MCP reales;
- outputs decodificados;
- referencias a artifacts del contexto LLM;
- findings de seguridad y contrato.

## Resultados

### PASS

El escenario cumplió los invariantes que puede verificar con evidencia
estructurada.

### WARN

No existe un fallo funcional demostrado, pero falta una condición deseable o
el escenario se detuvo deliberadamente, por ejemplo porque un write live no
estaba habilitado.

### FAIL

Existe un incumplimiento funcional, contractual o de seguridad verificable.

Un `FAIL` después de una write real no autoriza a repetir automáticamente el
escenario. Primero debe revisarse si el efecto externo ya ocurrió.

## Datos de prueba

Los escenarios live deben usar identidades controladas de E2E.

Para contactos nuevos, el runner puede generar:

- email único;
- teléfono de test compatible con el contrato de `/agent/respond`.

No se deben usar datos de clientes reales.

## Cobertura validada

A septiembre de 2026 se ha validado manualmente:

- servicios: exacto, refinamiento y sin resultados;
- Contact Submit: guardrail sin opt-in, write real, creación de lead y lectura
  posterior del contacto;
- Handoff: guardrail sin opt-in y `handoff_request` real aceptado por n8n;
- agenda: cobertura dry de los flujos principales y una reserva live
  previamente verificada.

Waitlist no forma parte de esta suite porque actualmente no existe una tool
MCP/n8n implementada para ese flujo.

## Recomendación operativa

Durante desarrollo normal:

```bash
python3 scripts/e2e/autonomous/run_suite.py --profile dry
```

Usar `full-live` solo cuando se quiera verificar deliberadamente las
integraciones externas con efectos reales y después de revisar los datos del
tenant y de los escenarios.
