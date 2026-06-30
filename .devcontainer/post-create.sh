#!/usr/bin/env bash
#
# Codespaces / devcontainer bootstrap for goldnead/statamic-automations.
#
# Delegates to scripts/setup-playground.sh, which builds a persistent,
# runnable Statamic 6 playground with this addon wired in as a path repo.
set -euo pipefail

ADDON_PACKAGE="goldnead/statamic-automations"
ADDON_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

cd "${ADDON_DIR}"

echo "==> Building the Statamic playground"
bash scripts/setup-playground.sh

cat <<BANNER

================================================================
  Codespace ready for ${ADDON_PACKAGE}

  Start the Statamic dev server:
      cd playground && php artisan serve --host=0.0.0.0 --port=8000

  Control Panel:  http://127.0.0.1:8000/cp
      Login:      admin@example.com / password

  Run addon tests:
      composer test

  Source:     src/
  Playground: playground/  (gitignored; per-codespace scaffold)
================================================================
BANNER
