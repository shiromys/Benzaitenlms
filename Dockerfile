FROM php:8.2-apache

# Install Moodle dependencies
RUN apt-get update && apt-get install -y libpng-dev libjpeg-dev libicu-dev libxml2-dev libzip-dev zlib1g-dev mariadb-client curl \
    && docker-php-ext-install gd intl mysqli opcache xml zip

# Create a script to download Moodle if it's missing (helps with empty volumes)
RUN echo '#!/bin/bash\n\
if [ ! -f /var/www/html/index.php ]; then\n\
  curl -L https://download.moodle.org/download.php/direct/stable405/moodle-latest-405.tgz | tar -xz --strip-components=1 -C /var/www/html\n\
  chown -R www-data:www-data /var/www/html\n\
fi\n\
a2dismod mpm_event || true\n\
exec apache2-foreground' > /usr/local/bin/start-moodle.sh

RUN chmod +x /usr/local/bin/start-moodle.sh

CMD ["/usr/local/bin/start-moodle.sh"]
