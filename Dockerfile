FROM php:8.4-cli

WORKDIR /var/www/html

RUN apt-get update && apt-get install -y \
    git \
    curl \
    libzip-dev \
    unzip \
    && docker-php-ext-configure zip \
    && docker-php-ext-install -j$(nproc) \
        mysqli \
        zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Configure PHP
RUN echo "memory_limit = 512M" >> /usr/local/etc/php/conf.d/memory-limit.ini \
    && echo "max_execution_time = 300" >> /usr/local/etc/php/conf.d/execution-time.ini

COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --prefer-dist
COPY . .

# Create required directories and set permissions
RUN mkdir -p var/cache var/log data \
    && chown -R www-data:www-data var data \
    && chmod -R 755 var data

# Complete composer installation with autoloader
RUN composer dump-autoload --optimize --no-dev

USER www-data

RUN touch .env

EXPOSE 8000

CMD ["php","-S","0.0.0.0:8000"]
