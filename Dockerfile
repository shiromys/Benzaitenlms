FROM webdevops/php-nginx:8.2

# Install additional Moodle PHP dependencies
RUN apt-get update && apt-get install -y \
    libpng-dev libjpeg-dev libicu-dev libxml2-dev libzip-dev zlib1g-dev \
    mariadb-client curl \
    && docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install gd intl mysqli opcache xml zip soap \
    && rm -rf /var/lib/apt/lists/*

# Create PHP config directory (webdevops image doesn't create it at build time)
# and write Moodle's required PHP settings into it
RUN mkdir -p /opt/docker/etc/php/php.ini.d \
    && printf "max_input_vars = 5000\nmemory_limit = 256M\nupload_max_filesize = 100M\npost_max_size = 100M\nmax_execution_time = 300\n" > /opt/docker/etc/php/php.ini.d/moodle.ini

# Web root that webdevops/php-nginx serves from
ENV WEB_DOCUMENT_ROOT=/var/www/html

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