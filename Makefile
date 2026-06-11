# The Absence Run — dev task runner.
#
# Console targets run under APP_ENV (default: dev), set inline so they ignore any
# stray APP_ENV exported in your shell. Override per command — `make run ENV=test` —
# or persist a default with `make setup-env ENV=dev`. Run `make help` for a list.

ENV      ?= dev
HR_URL   := http://127.0.0.1:8081
HR_TOKEN := demo-secret-token-7Qx2
DATE     ?= 2025-04-15
DB_FILE  := var/absence.sqlite
CONSOLE  := APP_ENV=$(ENV) php bin/console

.DEFAULT_GOAL := help
.PHONY: help install setup setup-env db migrate fresh mock run test stan ci hr-reset sql

help: ## List the available targets
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) \
		| awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-10s\033[0m %s\n", $$1, $$2}'

install: ## Install Composer dependencies
	composer install

setup: install db ## First-time setup: install deps, create schema, seed data

setup-env: ## Persist the environment to .env.local (ENV=dev|test|prod, default dev)
	@grep -v '^APP_ENV=' .env.local 2>/dev/null > .env.local.tmp || true
	@echo "APP_ENV=$(ENV)" >> .env.local.tmp
	@mv .env.local.tmp .env.local
	@echo "Set APP_ENV=$(ENV) in .env.local"

db: ## Rebuild the dev DB from migrations and load fixtures → fresh "pending" state
	# The dev DB is a SQLite file; deleting it and re-running migrations is the
	# reliable reset (doctrine:database:drop is a no-op for this SQLite DSN).
	rm -f $(DB_FILE)
	$(CONSOLE) doctrine:migrations:migrate --no-interaction
	$(CONSOLE) doctrine:fixtures:load --no-interaction

migrate: ## Apply pending Doctrine migrations (no reseed)
	$(CONSOLE) doctrine:migrations:migrate --no-interaction

fresh: db hr-reset ## Reset DB to pending AND clear the HR ledger — ready for a clean run

mock: ## Start the mock HR API (foreground — run in its own terminal, Ctrl-C to stop)
	php -S 127.0.0.1:8081 mock-hr-api/server.php

run: ## Run the absence run (needs the mock running). Override with: make run DATE=2025-04-15
	$(CONSOLE) app:absence:run --date=$(DATE)

hr-reset: ## Wipe the mock HR recorded decisions
	@curl -s -X POST $(HR_URL)/v1/_reset -H "Authorization: Bearer $(HR_TOKEN)" >/dev/null \
		&& echo "HR ledger reset" || echo "HR API not reachable (is 'make mock' running?)"

test: ## Run the test suite
	php bin/phpunit

stan: ## Run static analysis (PHPStan, level 8 on src/)
	vendor/bin/phpstan analyse --no-progress

ci: stan test ## Run everything CI runs (static analysis + tests)

sql: ## Open a SQLite shell on the dev database
	sqlite3 var/absence.sqlite
