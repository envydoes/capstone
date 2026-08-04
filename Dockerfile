FROM php:8.2-apache
ARG APACHE_DOCUMENT_ROOT=/var/www/html
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
RUN set -eux; \
  apt-get update; \
  apt-get install -y --no-install-recommends default-mysql-client ca-certificates curl unzip git; \
  curl -sSLf https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions \
    -o /usr/local/bin/install-php-extensions; \
  chmod +x /usr/local/bin/install-php-extensions; \
  rm -rf /var/lib/apt/lists/*
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - && apt-get install -y nodejs
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN set -eux; \
  sed -ri "s!/var/www/html!${APACHE_DOCUMENT_ROOT}!g" \
    /etc/apache2/sites-available/*.conf \
    /etc/apache2/apache2.conf \
    /etc/apache2/conf-available/*.conf
WORKDIR /var/www/html
RUN set -eux; \
  install-php-extensions gd pdo_mysql mysqli exif calendar bcmath pcntl zip
COPY . /var/www/html
RUN composer install --no-dev --optimize-autoloader
RUN npm install
RUN mkdir -p /var/www/html/uploads \
  && chown -R www-data:www-data /var/www/html/uploads \
  && chmod 2775 /var/www/html/uploads
RUN chown -R www-data:www-data /var/www/html
USER www-data