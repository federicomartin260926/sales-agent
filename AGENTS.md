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

* SA must act as a context-builder, tool configurator/gater, transport layer, and persistence layer.
* SA must not become a conversational reasoning engine.
* SA must not interpret human text with heuristics, regex, keyword matching, string contains checks, or ad hoc parsing.
* Natural language interpretation belongs to the LLM.
* The LLM must extract intent, action, entities, references, dates, times, slot selections, contact data, and next-step decisions from the human message and the ordered conversation history.
* SA must not resolve expressions such as “mañana”, “el viernes”, “a las 5”, “el primero”, “la anterior”, “la de María”, “quiero cambiarla”, or similar natural-language references by code.
* SA must provide the LLM with enough structured context to continue the conversation safely:

  * tenant and entry point context;
  * product/service context when available;
  * contact context when available;
  * temporal context and effective timezone;
  * ordered conversation history;
  * structured data attached to the turn where it was produced;
  * tool results attached to the turn where they were produced;
  * available tools and tool restrictions.
* SA execution services may only validate technical and authorization constraints, such as:

  * tenant configuration;
  * available/configured tools;
  * downstream authorization;
  * required transport fields;
  * schema/serialization integrity;
  * safe persistence of structured data and tool results.
* SA must not validate conversational consistency, such as whether a selected slot belongs to previously offered slots, whether a service mention matches a previous user phrase, or which appointment the user meant. The LLM and the operational tools/CRM are responsible for conversational reasoning and business truth.
* SA may normalize structured tool outputs so they are not buried inside traces, for example:

  * offered slots from `appointment_availability`;
  * existing appointments from `appointment_events`;
  * contact context from `contact_context`;
  * CRM/contact submission results.
* This normalization must stay objective and mechanical. SA may persist “0 / 1 / many records returned by a tool”, but must not infer which record the user meant from free text.
* If a tool returns exactly one structured record, SA may persist it as structured context for the LLM. SA must not decide the next conversational step from that alone.
* If a tool returns multiple candidates, SA must pass them to the LLM as structured context and let the LLM ask for clarification or select using the conversation and explicit structured intent.
* If structured data is missing, ambiguous, contradictory, or insufficient, the LLM must ask the customer for clarification or use tools to resolve it. SA must not infer missing data from free text.
* Write/action tools must be gated by tenant configuration, tool availability, downstream authorization, and the structured intent/action produced by the LLM planner. Do not reintroduce SA-side conversational guardrails such as slot matching, appointment selection, or free-text interpretation.
* For appointments:

  * `appointment_confirm` may be exposed when the planner intent/action indicates booking confirmation and the tool is configured.
  * `appointment_reschedule` may be exposed when the planner intent/action indicates reprogramming and the tool is configured.
  * `appointment_cancel` may be exposed when the planner intent/action indicates cancellation and the tool is configured.
  * `appointment_availability` and `appointment_events` are read tools and may be exposed when they help the LLM reason.
* CRM is the operational source of truth for appointment availability, bookings, rescheduling, cancellation, and agenda timezone when CRM integration is available.
* SA may keep a conversational timezone fallback, but must not override CRM timezone for agenda operations.
* Keep runtime code boring and explicit. Prefer small context extraction, tool configuration, serialization, and persistence helpers over branching conversational workflows.
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
* Write/action tools must be exposed only when the planner intent/action and tool configuration allow them.

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
* During the current development phase, do not add or expand automated tests unless explicitly requested by the user.
* Prefer `py_compile`, `compileall`, `git diff --check`, focused manual curl/script probes, and inspection of persisted structured data.
* Keep any future automated tests minimal and focused.
* Preferred validation commands:

  * `python3 -m py_compile <changed-python-files>`;
  * `python3 -m compileall -q api/app`;
  * `git diff --check`;
  * focused lints;
  * focused manual curls.
* During debugging, prefer focused conversation turns over full end-to-end flows.
* Reuse a prepared conversation state when validating a specific turn, unless the state is contaminated.
* Run a clean end-to-end flow only after the focused bug is fixed.

## Sales Agent LLM-led runtime contract

Before changing runtime, prompts, schemas, context builder or tool selection, read:
`docs/llm-context-assembly.md`

Hard rules:

* The canonical semantic context blocks are `backend_context` and `conversation_context`.
* The final LLM prompt also includes `intent_plan` and `tool_plan` when applicable.
* The intent-classification prompt uses the same canonical context names with a reduced payload.
* `conversation_context` contains `current_message` plus only mechanically derived conversation information needed for the turn, such as `state`, `temporal_context`, `recent_turns_summary` and/or ordered `history`.
* `current_message` must appear only once and must never be duplicated inside `history` or `recent_turns_summary`.
* Any state or summary must be derived from persisted messages and structured tool/LLM data. It must not become an independent third source of truth or a `runtime_context` replacement.
* `history` and summaries must be chronological and must contain only turns previous to `current_message`.
* Domain data must live inside `structured_data.<domain>` attached to the history turn where it was produced.
* Tool results must stay attached to the history turn where they were produced.
* Sales Agent correctness is primarily measured by whether it persists and replays enriched conversation history accurately.
* SA must persist every relevant inbound customer message, outbound assistant reply, `structured_data`, `tool_results`, intent, action, and metadata needed by the LLM to continue the conversation.
* SA must provide the LLM with `conversation_context.history` in chronological order, with structured data attached to the turn where it was produced.
* SA must not infer which slot, service, appointment or date the customer meant from human text.
* The LLM selects the exact structured slot or appointment from context.
* SA may validate structural integrity and safety against structured sources of truth, including that a `selected_slot` exactly belongs to `offered_slots`, required fields exist, tenant/tool authorization is valid, and write-tool prerequisites are satisfied.
* This validation must not parse or reinterpret natural language and must not alter the LLM semantic decision.
* If the LLM has enough ordered history, it is responsible for interpreting it, using tools, answering, or asking the customer for clarification.
* If the LLM misinterprets something, the conversation must be recoverable through normal customer correction and follow-up, not through SA-side conversational rules.
* SA may keep mechanical compatibility helpers only when needed for persistence, serialization, tool configuration, or transport. These helpers must not become a conversational state machine or a hidden latest-state index.
* Sales Agent must not interpret human language, match dates/times/text, reconstruct semantic data, or act as a complex state machine.

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
