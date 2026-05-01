# sales-agent AGENTS

## Database workflow

- For this project, prefer `doctrine:schema:update --force --complete` over Doctrine migrations for the operational workflow.
- Use `make schema-update` in development Docker and `make prod-schema-update` in production/VPS.
- Keep `migrations/` only as historical/reference schema artifacts unless a future change explicitly reintroduces migrations as the primary path.

## Symfony UI conventions

- In Symfony development, prefer Twig templates for rendered HTML, Bootstrap for layout/components/utilities, and static stylesheet assets for presentation.
- Do not add inline CSS to Twig templates or controller-rendered HTML when a stylesheet asset can express the same UI.
- Follow Symfony recommendations for Twig, Bootstrap-based UI composition, and assets as the default pattern unless the repository documents a stronger reason not to.
