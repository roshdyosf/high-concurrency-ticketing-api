FROM php:8.5-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
        git curl unzip zip libpq-dev libzip-dev libpng-dev \
    && docker-php-ext-install -j$(nproc) pdo_pgsql bcmath gd zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
