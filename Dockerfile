FROM composer:2 AS composer
FROM node:22-bookworm-slim AS node
FROM php:8.3-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libzip-dev libgmp-dev \
    && docker-php-ext-install pdo_mysql bcmath gmp zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/bin/composer
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -sf /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -sf /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

WORKDIR /var/www/html

COPY docker/entrypoint.sh /usr/local/bin/smackchat-entrypoint
RUN chmod +x /usr/local/bin/smackchat-entrypoint

EXPOSE 8000

ENTRYPOINT ["smackchat-entrypoint"]
