FROM php:8.2-apache
RUN apt-get update && apt-get install -y libpng-dev libjpeg-dev libicu-dev libxml2-dev libzip-dev zlib1g-dev \
    && docker-php-ext-install gd intl mysqli opcache xml zip
RUN curl -L https://download.moodle.org/download.php/direct/stable405/moodle-latest-405.tgz | tar -xz --strip-components=1 -C /var/www/html
