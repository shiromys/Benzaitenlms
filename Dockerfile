FROM webdevops/php-nginx:8.2

# Install Moodle PHP dependencies
RUN apt-get update && apt-get install -y \
    libpng-dev libjpeg-dev libicu-dev libxml2-dev libzip-dev zlib1g-dev \
    mariadb-client curl gettext-base unzip \
    && docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install gd intl mysqli opcache xml zip soap \
    && rm -rf /var/lib/apt/lists/*

# PHP settings
RUN mkdir -p /opt/docker/etc/php/php.ini.d \
    && printf "max_input_vars = 5000\nmemory_limit = 512M\nupload_max_filesize = 100M\npost_max_size = 100M\nmax_execution_time = 1800\nmax_input_time = 1800\ndefault_socket_timeout = 1800\n" > /opt/docker/etc/php/php.ini.d/moodle.ini

# nginx timeouts
RUN echo "fastcgi_read_timeout 1800s;\nfastcgi_send_timeout 1800s;\nproxy_read_timeout 1800s;\nproxy_send_timeout 1800s;\nclient_body_timeout 1800s;\nclient_max_body_size 100M;" > /opt/docker/etc/nginx/conf.d/timeouts.conf

ENV WEB_DOCUMENT_ROOT=/var/www/html

# Download Moodle 4.5.x stable
RUN curl -L https://download.moodle.org/download.php/direct/stable405/moodle-latest-405.tgz \
    | tar -xz --strip-components=1 -C /var/www/html

# Install Snap Theme - wildcard mv handles any folder name
RUN curl -L -o /tmp/snap.zip \
    "https://github.com/open-lms-open-source/moodle-theme_snap/archive/refs/heads/master.zip" \
    && unzip -q /tmp/snap.zip -d /tmp/snap_out \
    && mv /tmp/snap_out/moodle-theme_snap-* /var/www/html/theme/snap \
    && rm -rf /tmp/snap.zip /tmp/snap_out

# Install Stripe Payment Gateway - wildcard mv handles any folder name
RUN curl -L -o /tmp/stripe.zip \
    "https://github.com/alexmorrisnz/moodle-paygw_stripe/archive/refs/heads/master.zip" \
    && unzip -q /tmp/stripe.zip -d /tmp/stripe_out \
    && mv /tmp/stripe_out/moodle-paygw_stripe-* /var/www/html/payment/gateway/stripe \
    && rm -rf /tmp/stripe.zip /tmp/stripe_out

# Install Custom Certificate - wildcard mv handles any folder name
RUN curl -L -o /tmp/customcert.zip \
    "https://github.com/mdjnelson/moodle-mod_customcert/archive/refs/heads/master.zip" \
    && unzip -q /tmp/customcert.zip -d /tmp/customcert_out \
    && mv /tmp/customcert_out/moodle-mod_customcert-* /var/www/html/mod/customcert \
    && rm -rf /tmp/customcert.zip /tmp/customcert_out

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
  echo "[startup] Database is empty. Running Moodle CLI installer..."
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
  su application -s /bin/bash -c "php /var/www/html/admin/cli/upgrade.php --non-interactive --allow-unstable" || echo "[startup] No upgrade needed."
  echo "[startup] Purging Moodle caches..."
  su application -s /bin/bash -c "php /var/www/html/admin/cli/purge_caches.php" || echo "[startup] Cache purge skipped."
fi
echo "[startup] Handing off to base entrypoint..."
exec /opt/docker/bin/entrypoint.sh "$@"
EOF

RUN chmod +x /usr/local/bin/init.sh
ENTRYPOINT ["/usr/local/bin/init.sh"]
CMD ["supervisord"]
EXPOSE 80
