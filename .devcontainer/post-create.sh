#!/usr/bin/env bash
set -euo pipefail

ADDON_PACKAGE="goldnead/statamic-automations"

ADDON_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLAYGROUND_DIR="${ADDON_DIR}/playground"

cd "${ADDON_DIR}"

echo "==> Installing addon Composer dependencies"
composer install --no-interaction --no-progress

# Build addon CP assets if the addon ships a frontend bundle.
if [ -f "${ADDON_DIR}/package.json" ]; then
    echo "==> Installing addon Node dependencies"
    npm install --silent
    if node -e "process.exit(require('./package.json').scripts && require('./package.json').scripts.build ? 0 : 1)" 2>/dev/null; then
        echo "==> Building addon CP assets"
        npm run build
    fi
fi

if [ ! -d "${PLAYGROUND_DIR}" ]; then
    echo "==> Scaffolding Statamic playground site (this takes a few minutes)"
    composer create-project --prefer-dist statamic/statamic playground --no-interaction --no-progress

    cd "${PLAYGROUND_DIR}"

    echo "==> Configuring SQLite for the playground"
    sed -i 's/^DB_CONNECTION=.*/DB_CONNECTION=sqlite/' .env
    sed -i '/^DB_HOST=/d'     .env
    sed -i '/^DB_PORT=/d'     .env
    sed -i '/^DB_DATABASE=/d' .env
    sed -i '/^DB_USERNAME=/d' .env
    sed -i '/^DB_PASSWORD=/d' .env
    touch database/database.sqlite

    echo "==> Linking ${ADDON_PACKAGE} as path repository"
    composer config repositories.local-addon path "../"
    composer require "${ADDON_PACKAGE}:@dev" --no-interaction --no-progress

    echo "==> Running migrations"
    php artisan migrate --force

    echo "==> Installing playground Node dependencies"
    npm install --silent

    cd "${ADDON_DIR}"
else
    echo "==> Playground already exists, skipping scaffold"
fi

# Only install Playwright browsers if the addon actually uses them.
if [ -d "${ADDON_DIR}/tests/Browser" ] || grep -q '"playwright"' "${ADDON_DIR}/package.json" 2>/dev/null; then
    echo "==> Installing Playwright browsers"
    npx --yes playwright install chromium >/dev/null 2>&1 || true
fi

cat <<BANNER

================================================================
  Codespace ready for ${ADDON_PACKAGE}

  Start the Statamic dev server:
      cd playground && php artisan serve --host=0.0.0.0 --port=8000

  Create a Control Panel user (interactive):
      cd playground && php please make:user

  Run addon tests:
      composer test

  Watch & rebuild addon CP assets:
      npm run dev

  Source:     src/
  Playground: playground/  (gitignored; per-codespace scaffold)
================================================================
BANNER
