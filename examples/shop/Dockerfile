# FrankenPHP in worker mode. Build from the repository root:
#   docker build -f examples/shop/Dockerfile -t keel-shop .
#   docker run -p 8080:8080 -e METRICS_TOKEN=change-me -e REDIS_URL=... keel-shop
FROM dunglas/frankenphp:1-php8.4

RUN install-php-extensions pdo_sqlite pcntl redis opcache
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . /app
RUN composer install --no-dev --classmap-authoritative --no-interaction \
 && cd examples/shop \
 && KEEL_ENV=prod php ../../bin/keel build --env=prod

ENV KEEL_ENV=prod
WORKDIR /app/examples/shop
EXPOSE 8080
CMD ["frankenphp", "run", "--config", "Caddyfile"]
