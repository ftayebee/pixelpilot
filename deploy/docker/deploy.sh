#!/usr/bin/env bash
# Updates a running Docker deployment: pull, rebuild, migrate, restart.
# Never runs destructive migrations.
set -euo pipefail
cd "$(dirname "$0")/../.."

COMPOSE="docker compose -f docker-compose.prod.yml"

git pull --ff-only
$COMPOSE build
$COMPOSE up -d
$COMPOSE exec -T app php artisan migrate --force
# Workers and the scheduler pick up new code and .env changes on restart.
$COMPOSE restart worker scheduler
$COMPOSE ps
echo "Deployed $(git rev-parse --short HEAD)"
