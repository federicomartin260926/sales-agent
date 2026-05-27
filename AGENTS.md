# sales-agent AGENTS

## Database workflow

- For this project, prefer `doctrine:schema:update --force --complete` over Doctrine migrations for the operational workflow.
- Use `make schema-update` in development Docker and `make prod-schema-update` in production/VPS.
- Keep `migrations/` only as historical/reference schema artifacts unless a future change explicitly reintroduces migrations as the primary path.

## Symfony UI conventions

- In Symfony development, prefer Twig templates for rendered HTML, Bootstrap for layout/components/utilities, and static stylesheet assets for presentation.
- Do not add inline CSS to Twig templates or controller-rendered HTML when a stylesheet asset can express the same UI.
- Follow Symfony recommendations for Twig, Bootstrap-based UI composition, and assets as the default pattern unless the repository documents a stronger reason not to.

## Downstream authorization rule

- `ExternalTool.bearer_token` and any UI label such as `Token CRM para n8n/MCP` represent tenant-scoped downstream authorization for the flow `SA -> OpenAI Responses API -> MCP remoto -> n8n/CRM`.
- That token must travel only as a secure header when the MCP integration needs it.
- Do not send that token to the prompt, as a tool argument, inside JSON payloads, in traces, in logs, or in user-visible responses.
- Do not reuse that token as the credential for a handoff webhook from `SA -> n8n`.
- If a webhook needs protection, use a separate credential with an explicit name and clear ownership, and document that separation.
