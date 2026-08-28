#!/bin/bash
set -e

# El cron NO hereda las env vars del contenedor. Las volcamos a un archivo
# que los jobs de cron cargan antes de correr.
# 🔴 Toda variable que necesite un job de cron tiene que estar ACA. Apache si hereda
# el entorno del contenedor, asi que una ruta probada por HTTP funciona igual aunque
# falte: el fallo aparece solo por cron, y en silencio. Paso exactamente eso con
# conciliar-cron.php — sin NORAL_URL salia por un `exit` y no hacia nada cada 5 min.
cat > /data/env.sh <<EOF
export DATA_DIR="/data"
export BITRIX_WEBHOOK="${BITRIX_WEBHOOK}"
export OUTBOUND_TOKEN="${OUTBOUND_TOKEN}"
export NORAL_URL="${NORAL_URL}"
export NORAL_SYNC_TOKEN="${NORAL_SYNC_TOKEN}"
EOF
chmod 600 /data/env.sh

# arrancar cron en background
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

exec "$@"
