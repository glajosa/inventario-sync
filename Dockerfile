FROM php:8.2-apache

# curl para hablar con Bitrix
RUN apt-get update \
 && apt-get install -y --no-install-recommends libcurl4-openssl-dev \
 && docker-php-ext-install curl \
 && rm -rf /var/lib/apt/lists/*

# volumen persistente para allowlist.json + sync.log (montar en EasyPanel como /data)
RUN mkdir -p /data && chmod 777 /data

# código de la app
COPY . /var/www/html/
RUN rm -f /var/www/html/Dockerfile /var/www/html/README.md

# cron: rebuild cada 30 min (conserje). Carga env desde /data/env.sh (lo escribe entrypoint).
RUN apt-get update && apt-get install -y --no-install-recommends cron && rm -rf /var/lib/apt/lists/* \
 && echo '*/30 * * * * root . /data/env.sh; php /var/www/html/rebuild.php >> /data/cron.log 2>&1' > /etc/cron.d/inv-rebuild \
 && chmod 0644 /etc/cron.d/inv-rebuild

# arrancar cron + apache
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh \
 && rm -f /var/www/html/entrypoint.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]
