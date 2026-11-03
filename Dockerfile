# Pin to a digest for reproducible builds: docker buildx imagetools inspect php:8.4-apache
FROM php:8.4-apache

RUN a2enmod headers remoteip \
 && a2dismod -f autoindex status \
 && a2disconf other-vhosts-access-log \
 && sed -ri 's/^Listen 80$//' /etc/apache2/ports.conf \
 && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY apache/site.conf /etc/apache2/sites-available/000-default.conf
COPY php/app.ini "$PHP_INI_DIR/conf.d/zz-app.ini"
COPY --chown=root:root src /var/www/app/src
COPY --chown=root:root bin /var/www/app/bin
COPY --chown=root:root public /var/www/app/public

RUN mkdir -p /var/www/data && chown www-data:www-data /var/www/data && chmod 0700 /var/www/data

ENV APP_DB_PATH=/var/www/data/app.sqlite
USER www-data
EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
  CMD curl -fsS --max-time 4 http://127.0.0.1:8080/health.php || exit 1
