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

# Disable PHP-FPM clear_env so env vars pass through to PHP
RUN sed -i 's/^clear_env = .*/clear_env = no/' /opt/docker/etc/php/fpm/pool.d/application.conf || true \
    && echo "clear_env = no" >> /usr/local/etc/php-fpm.d/zz-docker.conf 2>/dev/null || true

# Download Moodle 4.5
RUN curl -L https://download.moodle.org/download.php/direct/stable405/moodle-latest-405.tgz \
    | tar -xz --strip-components=1 -C /var/www/html

# Copy config.php template (will be rendered at startup)
COPY config.php /var/www/html/config.php.template

# Permissions for code directory
RUN chown -R application:application /var/www/html

# Create moodledata mount point
RUN mkdir -p /moodledata

# Custom entrypoint:
# 1. Render config.php from template using current env vars (bypasses PHP-FPM clear_env)
# 2. Fix /moodledata permissions (Railway volume mount overrides build-time chown)
# 3. Hand off to the image's normal entrypoint
RUN printf '#!/bin/bash\nset -e\necho "[startup] Rendering config.php from template..."\nenvsubst < /var/www/html/config.php.template > /var/www/html/config.php\nchown application:application /var/www/html/config.php\necho "[startup] Fixing /moodledata permissions..."\nchown -R application:application /moodledata\nchmod -R 775 /moodledata\necho "[startup] Handing off to base entrypoint..."\nexec /opt/docker/bin/entrypoint.sh "$@"\n' > /usr/local/bin/init.sh \
    && chmod +x /usr/local/bin/init.sh

ENTRYPOINT ["/usr/local/bin/init.sh"]
CMD ["supervisord"]

EXPOSE 80