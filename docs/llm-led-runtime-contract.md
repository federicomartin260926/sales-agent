# LLM-led Runtime Contract

## Authority

The executable contract lives in:
`api/app/services/agent_orchestration/schemas.py`

This document explains the architecture rules. If code and this document diverge, update both in the same change.

## Main rule

Sales Agent is an orchestrator/context-builder. It must not interpret human language, match dates/times/text, reconstruct semantic data, or act as a complex state machine.

## Primary LLM input contract

The primary LLM receives only two main semantic context blocks:

`backend_context`
`conversation_context`

No other top-level context block should become a new primary contract.

## backend_context

Stable operational context:

* tenant
* contact
* entrypoint
* available_tools
* policies

## conversation_context

Shape:

```json
{
  "current_message": {},
  "history": []
}
```

## current_message

Contains only the current incoming customer message being processed.

It must not include:

* assistant text
* previous messages
* summaries
* combined history
* reconstructed data

## history

Contains only previously persisted turns.

It is ordered chronologically: the first item is the oldest included turn and the last item is the most recent persisted turn.

It may include:

* customer turns
* assistant turns
* domain
* intent
* action
* structured_data
* tool_results
* turn_index

It must exclude current_message.

## LLM output contract

The LLM must return:

* reply
* domain
* intent
* action
* structured_data
* next_expected

All domain data must live inside structured_data.<domain>.

No top-level offered_slots, selected_slot, existing_appointment, existing_appointments or required_next_action as primary contract.

## structured_data domains

* appointment
* services
* crm_contact
* handoff
* general

### Service selection

The service contract remains backward-compatible:

* singular selection: canonical service data and `structured_data.services.selected_service`;
* multi-service selection: the complete canonical selection and `structured_data.services.selected_services`;
* when a plural selection exists, the complete plural selection is authoritative and must never be reduced to its first element;
* prompt-visible history keeps only the minimal structured continuity needed for selected service(s) and offered/selected appointment slots;
* a multi-service booking is one visit and one agenda operation using the complete `service_ids`;
* CRM/tool remains authoritative for combined duration, buffers and availability; SA must not calculate an aggregate duration.

## Tool flow

The LLM decides which visible read tools to use. Sales Agent exposes context and capabilities. Sales Agent applies only minimal transport/write guardrails.

Primary rules:

* all configured and authorized read tools remain available;
* no write tool is exposed to the primary;
* the primary must resolve recoverable prerequisites with reads before requesting a write continuation;
* explicit verification of an existing CRM appointment uses `appointment_events`; `contact_context` may provide identity/contact/timezone context but does not replace that verification read.

Write-continuation rules:

* a continuation exists only when the primary returns an authorized `intent` + `action`;
* Sales Agent maps that pair mechanically to at most one write capability;
* the continuation keeps authorized reads visible and exposes exactly one authorized write;
* the continuation uses the primary `previous_response_id`;
* selecting a slot, identifying an appointment or returning `prepare_*` does not authorize a write.

Appointment timezone rules:

* the effective appointment timezone starts from reliable backend context;
* a valid timezone returned by `contact_context` during the primary MCP session may replace that fallback for subsequent appointment tools;
* the effective MCP configuration is propagated to a write continuation;
* this promotion is structural transport logic, not interpretation of human text.

## Minimal write guardrails

Write authorization is based on the primary `LLMFinalResponse.intent` and `LLMFinalResponse.action`.

Current mappings include:

* `request_booking_confirmation` + `confirm_booking` -> `appointment_confirm`;
* `request_booking_invitation` + `create_booking_invitation` -> `appointment_booking_invitation`;
* `request_reschedule` + `confirm_reschedule` -> `appointment_reschedule`;
* `request_cancel` + `confirm_cancel` -> `appointment_cancel`;
* `provide_contact_data` + `create_or_update_crm_contact` -> `crm_contact_submit`;
* `request_quote` + `create_or_update_crm_contact` -> `crm_contact_submit`;
* `request_handoff` + `handoff_to_human` -> `handoff_request`.

Additional guardrails:

* `appointment_confirm` must carry a valid service reference: a non-empty plural `service_ids` or a valid legacy singular service reference;
* `appointment_booking_invitation` is only externally successful when tool evidence contains `ok=true`, `created=true` and a non-empty `booking_url`; otherwise SA must not claim that an invitation/link was created;
* the normalized booking-invitation result is persisted in `structured_data.appointment.booking_invitation`; SA does not construct or rewrite the public URL returned by downstream;
* `crm_contact_submit` requires phone or email;
* an accepted write continuation must show exactly one call to the authorized write and no call to another write capability.

SA must not validate selected_slot against offered_slots or derive semantic decisions from natural-language text inside guardrails. Tool/CRM structured results remain authoritative for externally observable write success.

Authorization and execution are separate evidence: persisted `write_authorization` proves capability gating, while MCP tool traces prove execution.

## Forbidden old patterns

* required_next_action as rigid flow engine
* existing_appointment_required_before_slot
* booking_confirmation_blocked_by_existing_appointment_resolution
* appointment_events_required_but_not_called
* runtime_context as main LLM contract
* top-level appointment boxes as primary contract

## Validación E2E del runtime

Los escenarios autónomos que validan este contrato y sus reglas de seguridad se describen en [`docs/autonomous-e2e.md`](autonomous-e2e.md).
