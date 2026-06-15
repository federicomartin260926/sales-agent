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

* SA must not interpret human text with heuristics, regex, or ad hoc parsing.
* Natural language interpretation belongs to the LLM.
* The LLM must return structured, precise, normalized data for critical fields such as intent, action, service, date, time, slot selection, and contact data.
* SA execution services must only validate structured LLM output against internal state, tools, and safety rules.
* If structured data is missing, ambiguous, or unsafe, ask for clarification or fallback safely; do not infer from free text.

## Downstream authorization rule

* `ExternalTool.bearer_token` and UI labels such as `Token CRM para n8n/MCP` represent tenant-scoped downstream authorization for `SA -> OpenAI Responses API -> MCP remoto -> n8n/CRM`.
* That token must travel only as a secure header when the MCP integration needs it.
* Do not send that token to the prompt, as a tool argument, inside JSON payloads, in traces, in logs, or in user-visible responses.
* Do not reuse that token as the credential for a handoff webhook from `SA -> n8n`.
* If a webhook needs protection, use a separate credential with an explicit name and clear ownership, and document that separation.
