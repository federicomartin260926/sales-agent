COMPOSE_DEV=docker compose -f docker-compose.yml -f docker-compose.dev.yml
COMPOSE_PROD=docker compose -f docker-compose.yml -f docker-compose.prod.yml

.PHONY: up down build logs restart backend-shell api-shell migrate schema-update bootstrap composer-install cache-clear prod-up prod-down prod-build prod-logs prod-restart prod-bootstrap prod-schema-update prod-cache-clear

up:
	$(COMPOSE_DEV) up -d --build
	$(MAKE) composer-install
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
	$(COMPOSE_DEV) run --rm sales-agent-backend composer install

cache-clear:
	$(COMPOSE_DEV) exec --user www-data sales-agent-backend php bin/console cache:clear

prod-up:
	$(COMPOSE_PROD) up -d --build --remove-orphans
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

prod-cache-clear:
	$(COMPOSE_PROD) run --rm --user www-data sales-agent-backend php bin/console cache:clear --env=prod
