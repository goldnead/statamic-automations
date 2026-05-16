# Microsoft's PHP devcontainer image already ships PHP 8.3, Composer,
# Xdebug, and the common extensions Statamic needs (gd, intl, zip,
# mbstring, exif, pdo_sqlite, etc.).
FROM mcr.microsoft.com/devcontainers/php:1-8.3-bookworm

# Imagick — recommended over GD for Statamic image manipulation.
RUN apt-get update && apt-get install -y --no-install-recommends \
        libmagickwand-dev \
    && pecl install imagick \
    && docker-php-ext-enable imagick \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# System dependencies for Playwright (used by Pest v4 browser tests).
RUN apt-get update && apt-get install -y --no-install-recommends \
        libnss3 libnspr4 libatk1.0-0 libatk-bridge2.0-0 libcups2 \
        libxkbcommon0 libatspi2.0-0 libxcomposite1 libxdamage1 \
        libxfixes3 libxrandr2 libgbm1 libpango-1.0-0 libcairo2 \
        libasound2 \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install the Statamic CLI globally so `statamic new` is available.
USER vscode
RUN composer global require statamic/cli
ENV PATH="/home/vscode/.composer/vendor/bin:${PATH}"
USER root
