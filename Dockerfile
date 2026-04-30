FROM webdevops/php-nginx:8.2

# Install Moodle PHP dependencies
RUN apt-get update && apt-get install -y \
    libpng-dev libjpeg-dev libicu-dev libxml2-dev libzip-dev zlib1g-dev \
    mariadb-client curl gettext-base \
    && docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install gd intl mysqli opcache xml zip soap \
    && rm -rf /var/lib/apt/lists/*

# PHP settings Moodle needs
RUN mkdir -p /opt/docker/etc/php/php.ini.d \
    && printf "max_input_vars = 5000\nmemory_limit = 256M\nupload_max_filesize = 100M\npost_max_size = 100M\nmax_execution_time = 300\n" > /opt/docker/etc/php/php.ini.d/moodle.ini

# Web root
ENV WEB_DOCUMENT_ROOT=/var/www/html

# Download Moodle 4.5
RUN curl -L https://download.moodle.org/download.php/direct/stable405/moodle-latest-405.tgz \
    | tar -xz --strip-components=1 -C /var/www/html

# Copy config.php template (will be rendered at startup)
COPY config.php /var/www/html/config.php.template

# Permissions for code directory
RUN chown -R application:application /var/www/html

# Create moodledata mount point
RUN mkdir -p /moodledata

# Custom entrypoint: render config.php with ONLY specific variables substituted
# (envsubst with explicit variable list won't expand $CFG, $i, etc.)
RUN cat > /usr/local/bin/init.sh << 'EOF'
#!/bin/bash
set -e
echo "[startup] Rendering config.php from template..."
envsubst '${MYSQLHOST} ${MYSQLDATABASE} ${MYSQLUSER} ${MYSQLPASSWORD} ${MYSQLPORT} ${RAILWAY_PUBLIC_DOMAIN}' \
  < /var/www/html/config.php.template > /var/www/html/config.php
chown application:application /var/www/html/config.php
echo "[startup] Verifying config.php syntax..."
php -l /var/www/html/config.php
echo "[startup] Fixing /moodledata permissions..."
chown -R application:application /moodledata
chmod -R 775 /moodledata
echo "[startup] Handing off to base entrypoint..."
exec /opt/docker/bin/entrypoint.sh "$@"
EOF
RUN chmod +x /usr/local/bin/init.sh

ENTRYPOINT ["/usr/local/bin/init.sh"]
CMD ["supervisord"]

EXPOSE 80