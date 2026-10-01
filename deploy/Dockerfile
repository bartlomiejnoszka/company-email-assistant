FROM node:22-bookworm-slim AS assets
WORKDIR /build
COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts
COPY assets assets
COPY templates templates
RUN npm run build:css

FROM php:8.4-fpm-bookworm AS app
RUN apt-get update && apt-get install -y --no-install-recommends libonig-dev libzip-dev libsqlite3-dev libxml2-dev unzip ca-certificates \
    && docker-php-ext-install -j2 mbstring pdo_sqlite dom zip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --no-autoloader
COPY bin bin
COPY config config
COPY src src
COPY migrations migrations
COPY templates templates
COPY docs/panel docs/panel
COPY translations translations
COPY examples examples
COPY public public
COPY --from=assets /build/public/assets/panel.css public/assets/panel.css
COPY .env .env
RUN composer dump-autoload --no-dev --classmap-authoritative --no-interaction \
    && composer check-platform-reqs --no-dev \
    && mkdir -p var && chown -R 10001:10001 var
USER 10001:10001
ENV APP_ENV=prod APP_DEBUG=0

FROM app AS panel
ENTRYPOINT ["php-fpm", "-F"]
CMD []

FROM nginxinc/nginx-unprivileged:1.28-alpine AS web
COPY deploy/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=assets /build/public/assets /app/public/assets

FROM app AS cli
ENTRYPOINT ["php", "bin/console"]
CMD ["app:email:generate-drafts", "--limit=50"]
