COMPOSE_DEV=docker compose -f docker-compose.yml -f docker-compose.dev.yml
COMPOSE_PROD=docker compose -f docker-compose.yml -f docker-compose.prod.yml

.PHONY: up down build logs restart recreate backend-shell api-shell migrate schema-update bootstrap composer-install fix-perms cache-clear dev-diagnose prod-up prod-down prod-build prod-logs prod-restart prod-bootstrap prod-schema-update prod-fix-perms prod-cache-clear

up:
	$(COMPOSE_DEV) up -d --build --remove-orphans
	$(MAKE) composer-install
	$(MAKE) fix-perms
	$(MAKE) cache-clear

down:
	$(COMPOSE_DEV) down

build:
	$(COMPOSE_DEV) build

logs:
	$(COMPOSE_DEV) logs -f

restart:
	$(COMPOSE_DEV) restart
	$(MAKE) cache-clear

recreate:
	$(COMPOSE_DEV) down --remove-orphans
	$(COMPOSE_DEV) up -d --build --force-recreate --remove-orphans
	$(MAKE) composer-install
	$(MAKE) fix-perms
	$(MAKE) cache-clear

backend-shell:
	$(COMPOSE_DEV) exec sales-agent-backend sh

api-shell:
	$(COMPOSE_DEV) exec sales-agent-api sh

migrate:
	$(COMPOSE_DEV) exec --user www-data sales-agent-backend php bin/console doctrine:schema:update --force --complete

schema-update: migrate

bootstrap:
	$(COMPOSE_DEV) run --rm --user www-data sales-agent-backend php bin/console app:bootstrap:default-data

composer-install:
	$(COMPOSE_DEV) run --rm --no-deps --entrypoint composer sales-agent-backend install

fix-perms:
	$(COMPOSE_DEV) exec --user root sales-agent-backend sh -lc 'mkdir -p var/cache var/log var/jwt && chown -R www-data:www-data var && chmod -R ug+rwX var'

cache-clear:
	$(MAKE) fix-perms
	$(COMPOSE_DEV) exec --user www-data sales-agent-backend php bin/console cache:clear --env=dev

dev-diagnose:
	$(COMPOSE_DEV) ps
	$(COMPOSE_DEV) exec --user www-data sales-agent-backend php bin/console about
	cid="$$( $(COMPOSE_DEV) ps -q sales-agent-backend )" && docker inspect "$$cid" --format '{{range .Mounts}}{{println .Destination "->" .Source}}{{end}}'
	$(COMPOSE_DEV) exec --user root sales-agent-backend sh -lc 'ls -ld var var/cache var/log'
	$(COMPOSE_DEV) exec --user root sales-agent-backend sh -lc 'grep -n "Ampliación solicitada" templates/backend/ai_usage/index.html.twig'

prod-up:
	$(COMPOSE_PROD) up -d --build --remove-orphans
	$(MAKE) prod-fix-perms
	$(MAKE) prod-cache-clear

prod-down:
	$(COMPOSE_PROD) down

prod-build:
	$(COMPOSE_PROD) build

prod-logs:
	$(COMPOSE_PROD) logs -f

prod-restart:
	$(COMPOSE_PROD) restart
	$(MAKE) prod-cache-clear

prod-bootstrap:
	$(COMPOSE_PROD) run --rm --user www-data sales-agent-backend php bin/console app:bootstrap:default-data

prod-schema-update:
	$(COMPOSE_PROD) exec --user www-data sales-agent-backend php bin/console doctrine:schema:update --force --complete --env=prod

prod-fix-perms:
	$(COMPOSE_PROD) exec --user root sales-agent-backend sh -lc 'mkdir -p var/cache var/log var/jwt && chown -R www-data:www-data var && chmod -R ug+rwX var'

prod-cache-clear:
	$(MAKE) prod-fix-perms
	$(COMPOSE_PROD) exec --user www-data sales-agent-backend php bin/console cache:clear --env=prod
