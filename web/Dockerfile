FROM php:8.3-apache

ENV DEBIAN_FRONTEND=noninteractive

# Build the extension set this project needs. The official image already ships
# curl, mbstring, openssl, sodium and pdo_sqlite; these are the missing ones.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libwebp-dev \
        libzip-dev \
        libicu-dev \
        unzip; \
    docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp; \
    docker-php-ext-install -j"$(nproc)" gd pdo_mysql mysqli zip intl opcache; \
    rm -rf /var/lib/apt/lists/*

# mod_rewrite is what most PHP frameworks and CMS front controllers expect.
RUN a2enmod rewrite headers expires

# Apache's default /var/www/ block sets AllowOverride None, which silently
# ignores .htaccess files. The more specific /var/www/html block wins.
COPY conf/apache-dev.conf /etc/apache2/conf-available/dev.conf
RUN a2enconf dev

# Apache creates cache, view and log files at runtime. The default umask (022)
# makes them group read-only, so the FTP account — which shares the web server's
# group rather than its uid — could not manage them afterwards.
RUN echo 'umask 002' >> /etc/apache2/envvars

WORKDIR /var/www/html

EXPOSE 80
