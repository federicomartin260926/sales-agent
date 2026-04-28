# sales-agent AGENTS

## Database workflow

- For this project, prefer `doctrine:schema:update --force --complete` over Doctrine migrations for the operational workflow.
- Use `make schema-update` in development Docker and `make prod-schema-update` in production/VPS.
- Keep `migrations/` only as historical/reference schema artifacts unless a future change explicitly reintroduces migrations as the primary path.
