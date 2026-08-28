FROM php:8.2-apache

# curl para hablar con Bitrix
RUN apt-get update \
 && apt-get install -y --no-install-recommends libcurl4-openssl-dev libsqlite3-dev \
 && docker-php-ext-install curl pdo_sqlite \
 && rm -rf /var/lib/apt/lists/*

# volumen persistente para allowlist.json + sync.log (montar en EasyPanel como /data)
RUN mkdir -p /data && chmod 777 /data

# código de la app
COPY . /var/www/html/
COPY apache-tests-deny.conf /etc/apache2/conf-available/tests-deny.conf
RUN test ! -e /var/www/html/.git \
 && test ! -e /var/www/html/app_auth.json \
 && ! find /var/www/html -type f \( -name '*.bak' -o -name '*.bak-*' -o -name '*.pre-*' -o -name '*.orig' -o -name '*.rej' -o -name '*.pem' -o -name '*.key' \) -print -quit | grep -q . \
 && a2enconf tests-deny \
 && rm -f /var/www/html/Dockerfile /var/www/html/README.md /var/www/html/apache-tests-deny.conf

# crons (cargan env desde /data/env.sh que escribe entrypoint):
#  - reconcile cada 15 min (~5 llamadas): red de seguridad, re-sincroniza parentId2.
#  - conciliar cada 5 min (1 llamada): publica la cola madura y retira del buzon
#    las historias de unidades liberadas. Ver conciliar-cron.php.
#    El caso real (deploy) ya lo cubre el reconcile-al-arrancar del entrypoint;
#    esto es solo para caídas raras no controladas. NO usa la allowlist -> correcto
#    aunque el rebuild sea espaciado.
#  - warm-catalogo cada 30 min (~26 llamadas): refresca el catálogo de unidades
#    que dibuja el campo. Sin esto una unidad NUEVA del SPA no aparecía nunca en
#    la lista (medido: caché de 5.4 h con TTL de 15 min).
#  - mapa48 cada 6 h (~50 llamadas): conserje del mapa deal48 -> unidad, que es lo
#    que le permite al evento resolver la unidad con CERO llamadas. Desfasado 20 min
#    del rebuild para no pedir dos pipelines completos en el mismo minuto.
#  - rebuild cada 6 h (~27 llamadas): conserje de la allowlist. Poco frecuente a
#    propósito: el evento ONCRMDEALADD la mantiene fresca en vivo; esto solo limpia
#    deals borrados. Espaciado para NO saturar el API de Bitrix.
RUN apt-get update && apt-get install -y --no-install-recommends cron && rm -rf /var/lib/apt/lists/* \
 && printf '%s\n%s\n%s\n%s\n%s\n' \
    '*/15 * * * * root . /data/env.sh; php /var/www/html/reconcile.php >> /data/cron.log 2>&1' \
    '0 */6 * * * root . /data/env.sh; php /var/www/html/rebuild.php >> /data/cron.log 2>&1' \
    '*/30 * * * * root . /data/env.sh; php /var/www/html/warm-catalogo.php >> /data/cron.log 2>&1' \
    '20 */6 * * * root . /data/env.sh; php /var/www/html/mapa48.php >> /data/cron.log 2>&1' \
    '*/5 * * * * root . /data/env.sh; php /var/www/html/conciliar-cron.php >> /data/cron.log 2>&1' \
    > /etc/cron.d/inv-cron \
 && chmod 0644 /etc/cron.d/inv-cron

# arrancar cron + apache
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint.sh \
 && chmod +x /usr/local/bin/entrypoint.sh \
 && rm -f /var/www/html/entrypoint.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]
