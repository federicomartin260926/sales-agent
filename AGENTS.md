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
* The LLM may continue the conversation over multiple turns and repeated MCP reads when that is the correct way to resolve missing, contradictory, or stale information. A missing recoverable field is not a terminal error for SA.
* Write/action tools must be gated by tenant configuration, tool availability, downstream authorization, and the structured intent/action produced by the primary LLM turn. Do not reintroduce SA-side conversational guardrails such as slot matching, appointment selection, or free-text interpretation.
* For appointments:

  * `appointment_confirm` is exposed only when the structured LLM intent/action indicates `request_booking_confirmation` + `confirm_booking` and the tool is configured.
  * `appointment_reschedule` is exposed only when the structured LLM intent/action indicates `request_reschedule` + `confirm_reschedule` and the tool is configured.
  * `appointment_cancel` is exposed only when the structured LLM intent/action indicates `request_cancel` + `confirm_cancel` and the tool is configured.
  * `prepare_booking_confirmation` does not authorize `appointment_confirm`.
  * `prepare_reschedule` does not authorize `appointment_reschedule`.
  * `prepare_cancel` does not authorize `appointment_cancel`.
  * Selecting a slot does not equal confirming a write.
  * Identifying an appointment does not equal confirming a write.
  * Gathering data does not equal confirming a write.
  * Answering an informational question does not equal confirming a write.
  * `appointment_booking_invitation`, `crm_contact_submit`, and `handoff_request` keep their existing intent/action gating.
  * All configured and authorized read-capable tools are available to the LLM on every turn. This includes `contact_context`, `services_search`, `appointment_events`, and `appointment_availability`, plus future tools classified as read-capable.
  * Read-capable tools remain available even when a write tool is also exposed. Availability does not imply obligation.
  * The LLM may re-query a read tool in later turns when the user provides new data, a contradiction appears, or current state must be verified.
* CRM is the operational source of truth for appointment availability, bookings, rescheduling, cancellation, and agenda timezone when CRM integration is available.
* SA may keep a conversational timezone fallback, but must not override CRM timezone for agenda operations.
* Keep runtime code boring and explicit. Prefer small context extraction, tool configuration, serialization, and persistence helpers over branching conversational workflows.
* Do not add new legacy/shadow orchestration paths. When changing the runtime, move toward the LLM-led flow instead of reintroducing rule-based decision engines.

## Single-turn-first LLM runtime

* The target runtime is single-turn-first:
  * normally, one LLM call handles the customer turn;
  * the primary LLM call receives all configured and authorized read-capable tools and no write/action tools;
  * if no write/action is required, that same call produces the final customer response;
  * only when the LLM explicitly requests a write/action through the structured response contract may SA authorize a second continuation for that same logical turn.
* The primary LLM call is the conversational agent. Do not add a separate intent-planner LLM call whose only purpose is to classify the message before the main agent call.
* Intent, action, entities, references and next-step decisions are still produced by the LLM and may be persisted for diagnostics, continuity and authorization.
* SA must not reinterpret the human message after the LLM has produced structured intent/action.
* When a write/action is requested, SA may only perform a mechanical authorization mapping from the structured LLM output to the corresponding configured write capability.
* SA must expose at most the write tool authorized for that structured intent/action. Do not expose all write tools just because the LLM requested some write capability.
* Read-capable tools remain available during an authorized write continuation.
* Prefer continuing the same logical LLM turn using the Responses API continuation mechanism such as `previous_response_id` when technically appropriate, rather than reconstructing an unrelated second conversation.
* Do not send a user-visible intermediate response before an authorized write continuation has completed.
* A write continuation must preserve the context and safety rules needed to execute the operation correctly, but must not duplicate large context blocks merely because a second API call occurs.
* If a requested write is not configured or not authorized for the tenant, SA must not substitute another write tool and must not infer an alternative action from free text.
* Write authorization is least-privilege and mechanical; conversational reasoning remains entirely with the LLM.

## Prompt and context economy

* Every LLM request must contain all information needed to perform its responsibility correctly, and nothing beyond what is needed for that responsibility.
* Optimize for correctness first, then token efficiency. Do not remove context merely because it is large.
* Do not duplicate the current customer message in multiple context blocks.
* Do not duplicate equivalent structured data, tool results, allowed values, contracts or instructions in the same request unless the duplication has a demonstrated functional reason.
* Keep static instructions concise, explicit and behavior-oriented. Avoid architecture commentary, implementation history and examples that merely restate an already explicit rule.
* Preserve conversation history, structured data and tool results when they are needed for natural-language references, continuity, confirmation, appointment selection, service selection, timezone, stale-data recovery or other validated behavior.
* Do not truncate or summarize history only to reduce token usage unless the replacement preserves the semantic information required by the LLM and has been validated against the autonomous E2E scenarios.
* Prefer objective mechanical compaction, such as omitting empty fields or exact duplicate representations, over semantic rewriting by SA.
* If history compaction is introduced, it must not create an independent third source of truth or hidden conversational state machine.
* Measure actual API usage before and after material prompt/context changes. Do not infer savings only from debug artifact file size.
* A token optimization is acceptable only if functional behavior and write safety remain unchanged in focused validation and autonomous E2E coverage.

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
* All configured and authorized read-capable tools are available to the LLM on every primary turn. The LLM decides whether to use them.
* Write/action tools are absent from the primary turn and may be exposed only after the LLM has produced the structured intent/action that mechanically authorizes the corresponding write capability.
* During an authorized write continuation, expose only the corresponding write tool plus the configured read-capable tools.

## Runtime change policy

* Prefer small, surgical changes over broad rewrites.
* Avoid large uncontrolled refactors.
* When adding a new capability, first audit:

  * current schemas;
  * prompt instructions;
  * context builder;
  * tool selector/gating;
  * Responses API continuation flow;
  * runtime persistence;
  * debug artifacts;
  * autonomous E2E assumptions;
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
* When `llm_context_debug` is enabled, preserve the existing debug artifacts and inspect the real requests and responses, not only the public endpoint response.
* Distinguish announced tools from executed MCP calls when diagnosing behavior.
* Never expose secrets or bearer tokens in debug artifacts. Treat debug artifacts as sensitive operational data because they may contain customer, contact, and conversation context; restrict their access, sharing, and retention accordingly.
* Do not add extra logging or repeat writes just to diagnose a bug.

## Sales Agent LLM-led runtime contract

Before changing runtime, prompts, schemas, context builder or tool selection, read:
`docs/llm-context-assembly.md`

If an approved runtime refactor changes the flow described there, update that document in the same change so documentation and implementation do not diverge.

Hard rules:

* The canonical semantic context blocks are `backend_context` and `conversation_context`.
* The primary LLM request uses the canonical context blocks plus the minimum structured runtime/tool authorization information needed for the turn.
* If an approved write continuation is required, continuation context must preserve the original LLM decision and authorization evidence without reconstructing conversational semantics in SA.
* `conversation_context` contains `current_message` plus only mechanically derived conversation information needed for the turn, such as `state`, `temporal_context`, `recent_turns_summary` and/or ordered `history`.
* `current_message` must appear only once and must never be duplicated inside `history` or `recent_turns_summary`.
* Any state or summary must be derived from persisted messages and structured tool/LLM data. It must not become an independent third source of truth or a `runtime_context` replacement.
* `history` and summaries must be chronological and must contain only turns previous to `current_message`.
* Domain data must live inside `structured_data.<domain>` attached to the history turn where it was produced.
* Tool results must stay attached to the history turn where they were produced.
* Sales Agent correctness is primarily measured by whether it persists and replays enriched conversation history accurately.
* SA must persist every relevant inbound customer message, outbound assistant reply, `structured_data`, `tool_results`, intent, action, and metadata needed by the LLM to continue the conversation.
* SA must provide the LLM with `conversation_context.history` in chronological order, with structured data attached to the turn where it was produced.
* Priority of context and history:

  1. `current_message` interpreted with the chronological `history`.
  2. `structured_data` and tool results attached to the turns where they were produced.
  3. Recent successful write-tool results and the turns that reflect them.
  4. `backend_context` as the operational snapshot for the turn.
  5. A fresh MCP read when information is missing, contradictory, or stale.
* The most recent, explicit, and reliable information takes precedence over older context.
* `backend_context` built before a successful write can be temporarily stale.
* Recent successful booking, reschedule, or cancel results take precedence over earlier state.
* If the LLM needs to verify current state or resolve a contradiction, it should consult a read tool or ask the customer.
* SA must not infer which slot, service, appointment or date the customer meant from human text.
* The LLM selects the exact structured slot or appointment from context.
* SA may validate structural integrity and transport safety, including required fields, tenant/tool authorization, and write-tool prerequisites.
* SA must not validate slot membership against prior conversational candidates, because later MCP reads may extend or replace that context.
* This validation must not parse or reinterpret natural language and must not alter the LLM semantic decision.
* If the LLM has enough ordered history, it is responsible for interpreting it, using tools, answering, or asking the customer for clarification.
* If the LLM misinterprets something, the conversation must be recoverable through normal customer correction and follow-up, not through SA-side conversational rules.
* SA may keep mechanical compatibility helpers only when needed for persistence, serialization, tool configuration, or transport. These helpers must not become a conversational state machine or a hidden latest-state index.
* Sales Agent must not interpret human language, match dates/times/text, reconstruct semantic data, or act as a complex state machine.
* The final response contract should stay open by default. Do not introduce strict per-intent response formats unless there is an explicit, technical, and approved need.
* The final response schema should validate transport and structure, not force conversational completeness.
* Do not require `selected_slot` when it is not yet resolved.
* Do not require `offered_slots` when it is not yet resolved.
* Do not require `existing_appointment` or similar fields when they are not yet resolved.
* If data is missing, the LLM should ask or consult MCP instead of relying on postprocessing to invent or complete semantics.
* Do not add postprocessing that invents, selects, or semantically completes missing structured data.

## Autonomous E2E contract

* Treat the autonomous E2E scenarios as the behavioral regression net for the LLM-led runtime.
* Architectural runtime changes must preserve the business scenarios and safety invariants even if internal artifact names, call counts or orchestration phases change.
* Do not rewrite scenarios merely to make a changed runtime pass. Update only harness assumptions that are inherently tied to the old internal architecture.
* A migration from a separate intent-planner call to the single-turn-first runtime may require changing evaluator expectations for:
  * intent-request/final-request artifact names;
  * exact LLM call count;
  * planner-specific phases;
  * `intent_plan` presence;
  * write authorization evidence.
* Preserve scenario-level assertions for:
  * no unintended writes;
  * at most the authorized write capability for the turn;
  * explicit confirmation for appointment writes;
  * multiservice continuity;
  * read-tool availability;
  * real MCP evidence;
  * CRM verification for live writes.
* Keep dry scenarios useful without external side effects.
* Live write scenarios must remain explicitly gated by `SA_E2E_ALLOW_WRITES=1`.
* Never rerun a live scenario blindly when a previous attempt may already have executed a write; inspect persisted/debug evidence first.

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
