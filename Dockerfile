FROM php:8.3-cli
RUN apt-get update && apt-get install -y --no-install-recommends libsqlite3-dev libpq-dev libonig-dev libxml2-dev libpng-dev libzip-dev unzip git && docker-php-ext-install pdo_sqlite pdo_pgsql mbstring dom xml gd zip && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN printf '%s\n' 'variables_order = "EGPCS"' > "$PHP_INI_DIR/conf.d/99-fitensis-env.ini"
WORKDIR /app
COPY . .
RUN composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
EXPOSE 8000
CMD ["php", "-S", "0.0.0.0:8000", "-t", "public", "public/router.php"]
