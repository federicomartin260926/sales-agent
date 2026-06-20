# LLM-led Runtime Contract

## Authority

The executable contract lives in:
`api/app/services/agent_orchestration/schemas.py`

This document explains the architecture rules. If code and this document diverge, update both in the same change.

## Main rule

Sales Agent is an orchestrator/context-builder. It must not interpret human language, match dates/times/text, reconstruct semantic data, or act as a complex state machine.

## Second LLM input contract

The second LLM receives only two main context blocks:

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

## Second LLM output contract

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

## Tool flow

The LLM decides which tools to use.
Sales Agent exposes context and tools.
Sales Agent applies only minimal write guardrails.

## Minimal write guardrails

* appointment_confirm, appointment_reschedule and appointment_cancel are gated by planner intent/action and tool availability.
* crm_contact_submit requires phone or email.

SA must not validate selected_slot against offered_slots or generate customer-facing conversational replies from guardrails. Tool/CRM results and the LLM final reply are authoritative.

## Forbidden old patterns

* required_next_action as rigid flow engine
* existing_appointment_required_before_slot
* booking_confirmation_blocked_by_existing_appointment_resolution
* appointment_events_required_but_not_called
* runtime_context as main LLM contract
* top-level appointment boxes as primary contract
