# Build stage just to get Composer (optional)
FROM composer:2 AS composer

# Runtime stage
FROM php:8.3-apache

# ---- System packages ----
# - JPEG/PNG/Freetype for GD
# - ImageMagick libs for Imagick
# - pgsql, zip, tzdata, etc.
RUN apt-get update && apt-get install -y --no-install-recommends \
      libpq-dev \
      libzip-dev \
      libjpeg62-turbo-dev \
      libpng-dev \
      libfreetype6-dev \
      imagemagick \
      libmagickwand-dev \
      pkg-config unzip git curl tzdata \
      nfdump \
      libsnmp-dev \
      snmp \
      iputils-ping \
      traceroute \
  && chmod u+s /usr/bin/ping /usr/bin/traceroute \
  && rm -rf /var/lib/apt/lists/*

# ---- PHP extensions ----
# GD (jpeg+freetype), EXIF (for JPEG metadata), PDO_pgsql, ZIP, opcache, sockets, snmp
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
  && docker-php-ext-install -j"$(nproc)" gd exif pdo pdo_pgsql zip opcache sockets snmp

# ---- Imagick (PHP extension) ----
RUN pecl install imagick \
  && docker-php-ext-enable imagick

# ---- Apache modules ----
RUN a2enmod rewrite ssl headers proxy proxy_http proxy_wstunnel && a2ensite 


# ---- Configure Apache DocumentRoot (honours env var from compose) ----
ARG APACHE_DOCUMENT_ROOT=/var/www/html/public
ENV APACHE_DOCUMENT_ROOT=${APACHE_DOCUMENT_ROOT}
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/000-default.conf \
 && sed -ri -e 's!<Directory /var/www/>!<Directory ${APACHE_DOCUMENT_ROOT}/>!g' /etc/apache2/apache2.conf

# Drop in your hardened vhost (allows .htaccess) and port config
# Login sessions live here (a volume in docker-compose); www-data must be able to write to it.
RUN mkdir -p /var/lib/app-sessions && chown www-data:www-data /var/lib/app-sessions && chmod 700 /var/lib/app-sessions

COPY vhost.conf /etc/apache2/sites-available/000-default.conf
COPY ports.conf /etc/apache2/ports.conf

# PHP settings
COPY php.ini /usr/local/etc/php/conf.d/99-app.ini

# Use Composer from the builder (optional)
COPY --from=composer /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html
