FROM php:8.5-apache
RUN apt-get update && apt-get install -y --no-install-recommends unzip && docker-php-ext-install pdo_mysql && a2enmod rewrite headers && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-progress --no-scripts --no-autoloader
COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative && mkdir -p var/sessions var/cache var/log && chown -R www-data:www-data var && printf 'Listen 8080\n' > /etc/apache2/ports.conf && printf '<VirtualHost *:8080>\nDocumentRoot /app/public\n<Directory /app/public>\nAllowOverride None\nRequire all granted\nFallbackResource /index.php\n</Directory>\n</VirtualHost>\n' > /etc/apache2/sites-available/000-default.conf
RUN printf 'display_errors=Off\nexpose_php=Off\nsession.use_strict_mode=1\nmemory_limit=256M\n' > /usr/local/etc/php/conf.d/bibliotheque.ini
EXPOSE 8080
CMD ["sh", "/app/docker/start.sh"]
