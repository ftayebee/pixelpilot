#!/usr/bin/env bash
# First-time setup of the Docker stack on a server that already runs Traefik.
# Usage: bash deploy/docker/install.sh pixelpilot.example.com
# Creates .env with a fresh APP_KEY and database password (Pixelforge keys
# are left empty for you to fill in), builds and starts the stack, migrates.
set -euo pipefail

HOST="${1:?usage: install.sh <hostname>}"
cd "$(dirname "$0")/../.."

if [ ! -f .env ]; then
    cp deploy/docker/env.production.example .env
    sed -i "s#^APP_URL=.*#APP_URL=https://${HOST}#" .env
    sed -i "s#^APP_HOST=.*#APP_HOST=${HOST}#" .env
    sed -i "s#^MAIL_FROM_ADDRESS=.*#MAIL_FROM_ADDRESS=\"no-reply@${HOST}\"#" .env
    sed -i "s#^APP_KEY=.*#APP_KEY=base64:$(openssl rand -base64 32)#" .env
    sed -i "s#^DB_PASSWORD=.*#DB_PASSWORD=$(openssl rand -hex 24)#" .env
    chmod 600 .env
    echo "Created .env for ${HOST}"
fi

docker compose -f docker-compose.prod.yml up -d --build
docker compose -f docker-compose.prod.yml exec -T app php artisan migrate --force
docker compose -f docker-compose.prod.yml ps

echo
echo "Next: add PIXELFORGE_API_KEY and PIXELFORGE_WEBHOOK_SECRET to .env, then run:"
echo "  bash deploy/docker/deploy.sh"
