#!/bin/bash
set -e

# El cron NO hereda las env vars del contenedor. Las volcamos a un archivo
# que los jobs de cron cargan antes de correr.
cat > /data/env.sh <<EOF
export DATA_DIR="/data"
export BITRIX_WEBHOOK="${BITRIX_WEBHOOK}"
export OUTBOUND_TOKEN="${OUTBOUND_TOKEN}"
EOF
chmod 600 /data/env.sh

if [[ "${LAB_READ_ONLY:-0}" == "1" ]]; then
  # Laboratorio: solo lectura. Construye la fotografía desde Bitrix y jamás ejecuta
  # los procesos de conciliación, allowlist o mapa que pueden escribir relaciones.
  printf '%s\n' \
    '*/30 * * * * root . /data/env.sh; php /var/www/html/warm-catalogo.php >> /data/cron.log 2>&1' \
    > /etc/cron.d/inv-cron
  chmod 0644 /etc/cron.d/inv-cron
  cron
  (
    sleep 5
    php /var/www/html/warm-catalogo.php >> /data/cron.log 2>&1
  ) &
else
  # Producción conserva exactamente su arranque actual.
  cron

  # AL ARRANCAR (cubre el hueco del deploy/reinicio, sin esperar los crons):
  #   1. rebuild  -> allowlist lista de una (no esperar 6h)
  #   2. reconcile -> recupera CUALQUIER evento perdido durante el downtime del deploy
  #   3. mapa48   -> sin el mapa, el evento del precio final no sabe a qué unidad va
  #                  y se descartaría TODO el 48 pensando que no es del 48
  # Secuencial y en background para no bloquear apache. Espera 5s a que el
  # webhook entrante esté resoluble.
  (
    sleep 5
    php /var/www/html/rebuild.php   >> /data/cron.log 2>&1
    php /var/www/html/reconcile.php >> /data/cron.log 2>&1
    php /var/www/html/mapa48.php    >> /data/cron.log 2>&1
  ) &
fi

exec "$@"
