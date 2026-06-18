# sales-agent AGENTS

## Database workflow

* Prefer `doctrine:schema:update --force --complete` over Doctrine migrations for the operational workflow.
* Use `make schema-update` in development Docker and `make prod-schema-update` in production/VPS.
* Keep `migrations/` only as historical/reference schema artifacts unless migrations are explicitly reintroduced as the primary path.

## Symfony UI conventions

* Prefer Twig templates, Bootstrap layout/components/utilities, and static stylesheet assets.
* Do not add inline CSS to Twig templates or controller-rendered HTML when a stylesheet asset can express the same UI.
* Follow Symfony/Twig/Bootstrap/assets conventions unless the repository documents a stronger reason not to.

## LLM vs Sales Agent responsibilities

* SA must act as an orchestrator, context-builder, tool-gater, validator, and persistence layer.
* SA must not become a conversational reasoning engine.
* SA must not interpret human text with heuristics, regex, keyword matching, string contains checks, or ad hoc parsing.
* Natural language interpretation belongs to the LLM.
* The LLM must extract intent, action, entities, references, dates, times, slot selections, contact data, and next-step decisions from the human message and return them as structured, precise, normalized data.
* SA must not resolve expressions such as “mañana”, “el viernes”, “a las 5”, “el primero”, “la anterior”, “la de María”, “quiero cambiarla”, or similar natural-language references by code.
* SA must provide the LLM with enough structured context to make those decisions safely:

  * tenant and entry point context;
  * product/service context when available;
  * contact context;
  * conversation state;
  * temporal context and timezone;
  * previously offered slots;
  * selected slot;
  * existing appointments;
  * required next action when already known;
  * available tools and tool restrictions.
* SA execution services may only validate structured LLM output against:

  * internal persisted state;
  * tool outputs;
  * allowed tools;
  * tenant configuration;
  * safety and consistency guardrails.
* SA may normalize structured tool outputs so they are not buried inside traces, for example:

  * offered slots from `appointment_availability`;
  * existing appointments from `appointment_events`;
  * contact context from `contact_context`;
  * CRM/contact submission results.
* This normalization must stay objective and mechanical. SA may persist “0 / 1 / many records returned by a tool”, but must not infer which record the user meant from free text.
* If a tool returns exactly one structured record, SA may persist it as the only candidate for LLM context. SA must not decide the next conversational step from that alone unless the LLM already returned an explicit structured next action.
* If a tool returns multiple candidates, SA must pass them to the LLM as structured context and let the LLM ask for clarification or select using explicit structured user intent.
* If structured data is missing, ambiguous, contradictory, or unsafe, SA must ask for clarification through the LLM or fallback safely; it must not infer missing data from free text.
* Write/action tools must be gated by explicit structured state and strong guardrails. Do not expose write tools merely because the user intent category sounds related.
* For appointments:

  * `appointment_confirm` must only be exposed when a selected slot has been validated and the user clearly confirms booking.
  * `appointment_reschedule` must only be exposed when there is an existing appointment, a new selected slot validated against offered availability, and an explicit structured reschedule confirmation/next action.
  * `appointment_cancel` must only be exposed when there is an existing appointment identified safely and an explicit structured cancellation confirmation/next action.
  * `appointment_availability` and `appointment_events` are read tools and may be exposed when they help the LLM reason.
* CRM is the operational source of truth for appointment availability, bookings, rescheduling, cancellation, and agenda timezone when CRM integration is available.
* SA may keep a conversational timezone fallback, but must not override CRM timezone for agenda operations.
* Keep runtime code boring and explicit. Prefer small context extraction, validation, and persistence helpers over branching conversational workflows.
* Do not add new legacy/shadow orchestration paths. When changing the runtime, move toward the LLM-led flow instead of reintroducing rule-based decision engines.

## MCP and tool execution architecture

* The preferred architecture for tools is `SA -> OpenAI Responses API -> MCP remoto -> n8n/CRM`.
* Do not implement direct `SA -> MCP tools/call` execution as the primary solution unless explicitly requested.
* If MCP tool execution behaves unexpectedly, investigate the OpenAI Responses MCP configuration first:

  * allowed tools;
  * tool gating;
  * `previous_response_id`;
  * `tool_choice`;
  * max tool rounds;
  * tool outputs;
  * traces;
  * prompts and structured context.
* SA controls which tools the LLM can see. The LLM decides whether and how to use the allowed tools.
* Read tools can be exposed when they help the LLM reason.
* Write/action tools must be exposed only when the structured state is sufficient and safe.

## Runtime change policy

* Prefer small, surgical changes over broad rewrites.
* Avoid large uncontrolled refactors.
* When adding a new capability, first audit:

  * current schemas;
  * prompt instructions;
  * context builder;
  * tool selector;
  * runtime persistence;
  * existing tool output shape.
* Do not touch CRM, MCP, n8n, or wa-gateway-api unless the bug is proven to be there.
* Do not add broad tests while debugging a narrow runtime issue unless they are necessary.
* Preferred validation commands:

  * `python3 -m py_compile <changed-python-files>`;
  * `python3 -m compileall -q api/app`;
  * `git diff --check`;
  * focused lints;
  * focused manual curls.
* During debugging, prefer focused conversation turns over full end-to-end flows.
* Reuse a prepared conversation state when validating a specific turn, unless the state is contaminated.
* Run a clean end-to-end flow only after the focused bug is fixed.

## Codex usage policy

* Use Codex mainly for focused audits or very surgical changes.
* Prompts to Codex must be narrow and explicit.
* Always specify:

  * files allowed to edit;
  * files not to edit;
  * exact objective;
  * architectural constraints;
  * validation commands;
  * expected output.
* Do not ask Codex for broad rewrites of the runtime unless explicitly decided.
* Do not let Codex introduce natural-language heuristics in SA.
* Do not let Codex bypass OpenAI MCP by adding direct MCP tool execution from SA unless explicitly requested.

## Downstream authorization rule

* `ExternalTool.bearer_token` and UI labels such as `Token CRM para n8n/MCP` represent tenant-scoped downstream authorization for `SA -> OpenAI Responses API -> MCP remoto -> n8n/CRM`.
* That token must travel only as a secure header when the MCP integration needs it.
* Do not send that token to the prompt, as a tool argument, inside JSON payloads, in traces, in logs, or in user-visible responses.
* Do not reuse that token as the credential for a handoff webhook from `SA -> n8n`.
* If a webhook needs protection, use a separate credential with an explicit name and clear ownership, and document that separation.
