FROM webdevops/php-nginx:8.2

# Install Moodle PHP dependencies
RUN apt-get update && apt-get install -y \
    libpng-dev libjpeg-dev libicu-dev libxml2-dev libzip-dev zlib1g-dev \
    mariadb-client curl gettext-base \
    && docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install gd intl mysqli opcache xml zip soap \
    && rm -rf /var/lib/apt/lists/*

# PHP settings — INCREASED limits to handle long Moodle upgrades on slow Railway MySQL
RUN mkdir -p /opt/docker/etc/php/php.ini.d \
    && printf "max_input_vars = 5000\nmemory_limit = 512M\nupload_max_filesize = 100M\npost_max_size = 100M\nmax_execution_time = 1800\nmax_input_time = 1800\ndefault_socket_timeout = 1800\n" > /opt/docker/etc/php/php.ini.d/moodle.ini

# Also increase nginx and PHP-FPM timeouts
RUN echo "fastcgi_read_timeout 1800s;\nfastcgi_send_timeout 1800s;\nproxy_read_timeout 1800s;\nproxy_send_timeout 1800s;\nclient_body_timeout 1800s;\nclient_max_body_size 100M;" > /opt/docker/etc/nginx/conf.d/timeouts.conf

# Increase PHP-FPM request_terminate_timeout
RUN echo "request_terminate_timeout = 1800" >> /opt/docker/etc/php/fpm/pool.d/application.conf 2>/dev/null || \
    find /opt/docker/etc/php/fpm -name "*.conf" -exec sh -c 'echo "request_terminate_timeout = 1800" >> "$1"' _ {} \; 2>/dev/null || true

ENV WEB_DOCUMENT_ROOT=/var/www/html

# Download Moodle 4.5
RUN curl -L https://download.moodle.org/download.php/direct/stable405/moodle-latest-405.tgz \
    | tar -xz --strip-components=1 -C /var/www/html

COPY config.php /var/www/html/config.php.template

RUN chown -R application:application /var/www/html
RUN mkdir -p /moodledata

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

echo "[startup] Waiting for MySQL to be ready..."
for i in {1..30}; do
  if mysql -h"${MYSQLHOST}" -P"${MYSQLPORT}" -u"${MYSQLUSER}" -p"${MYSQLPASSWORD}" -e "SELECT 1" "${MYSQLDATABASE}" > /dev/null 2>&1; then
    echo "[startup] MySQL is ready."
    break
  fi
  echo "[startup] MySQL not ready yet, retrying ($i/30)..."
  sleep 2
done

TABLE_EXISTS=$(mysql -h"${MYSQLHOST}" -P"${MYSQLPORT}" -u"${MYSQLUSER}" -p"${MYSQLPASSWORD}" -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${MYSQLDATABASE}' AND table_name='mdl_config'" 2>/dev/null || echo "0")

if [ "$TABLE_EXISTS" -eq "0" ]; then
  echo "[startup] Database is empty. Running Moodle CLI installer as 'application' user..."
  su application -s /bin/bash -c "php /var/www/html/admin/cli/install_database.php \
    --agree-license \
    --fullname=\"${MOODLE_FULLNAME:-Benzaiten LMS}\" \
    --shortname=\"${MOODLE_SHORTNAME:-Benzaiten}\" \
    --adminuser=\"${MOODLE_ADMIN_USER:-admin}\" \
    --adminpass=\"${MOODLE_ADMIN_PASS:-Admin@123456}\" \
    --adminemail=\"${MOODLE_ADMIN_EMAIL:-admin@example.com}\""
  echo "[startup] Moodle install completed."
else
  echo "[startup] Moodle already installed, checking for pending upgrades..."
  # Run upgrade automatically if needed (handles the version mismatch case)
  su application -s /bin/bash -c "php /var/www/html/admin/cli/upgrade.php --non-interactive --allow-unstable" || echo "[startup] Upgrade not needed or already complete."
fi

echo "[startup] Handing off to base entrypoint..."
exec /opt/docker/bin/entrypoint.sh "$@"
EOF
RUN chmod +x /usr/local/bin/init.sh

ENTRYPOINT ["/usr/local/bin/init.sh"]
CMD ["supervisord"]

EXPOSE 80
