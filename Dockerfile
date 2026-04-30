FROM php:8.2-apache

# Install Moodle PHP dependencies
RUN apt-get update && apt-get install -y \
    libpng-dev libjpeg-dev libicu-dev libxml2-dev libzip-dev zlib1g-dev \
    mariadb-client curl \
    && docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install gd intl mysqli opcache xml zip soap \
    && rm -rf /var/lib/apt/lists/*

# Force exactly ONE MPM by removing all MPM symlinks first, then enabling only prefork
RUN rm -f /etc/apache2/mods-enabled/mpm_*.conf /etc/apache2/mods-enabled/mpm_*.load \
    && ln -sf /etc/apache2/mods-available/mpm_prefork.conf /etc/apache2/mods-enabled/mpm_prefork.conf \
    && ln -sf /etc/apache2/mods-available/mpm_prefork.load /etc/apache2/mods-enabled/mpm_prefork.load

# Enable rewrite and headers (needed for Moodle clean URLs)
RUN a2enmod rewrite headers

# Verify only one MPM is loaded (build will fail clearly if not)
RUN ls /etc/apache2/mods-enabled/mpm_*.load | wc -l | grep -q "^1$"

# PHP settings Moodle needs
RUN echo "max_input_vars = 5000\n\
memory_limit = 256M\n\
upload_max_filesize = 100M\n\
post_max_size = 100M\n\
max_execution_time = 300" > /usr/local/etc/php/conf.d/moodle.ini

# Download Moodle 4.5 at BUILD time
RUN curl -L https://download.moodle.org/download.php/direct/stable405/moodle-latest-405.tgz \
    | tar -xz --strip-components=1 -C /var/www/html

# Copy your config.php into the image
COPY config.php /var/www/html/config.php

# Permissions
RUN chown -R www-data:www-data /var/www/html

# Create moodledata mount point with correct ownership
RUN mkdir -p /moodledata && chown -R www-data:www-data /moodledata

EXPOSE 80
CMD ["apache2-foreground"]