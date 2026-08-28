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
# 🔴 Y dejar dicho si arranco. `cron` falla en silencio dentro de un contenedor por
# media docena de motivos —no esta instalado, el archivo de /etc/cron.d tiene el modo
# o el dueño mal, el demonio muere al no encontrar syslog— y desde afuera todos se
# ven igual: los trabajos simplemente no pasan. Sin este diagnostico se buscan las
# causas de a una, adivinando.
cron; CRON_RC=$?
sleep 1
{
  echo "{"
  echo "  \"arranque\": \"$(date -Iseconds)\","
  echo "  \"cron_binario\": \"$(command -v cron || echo NO_INSTALADO)\","
  echo "  \"cron_salida\": $CRON_RC,"
  echo "  \"cron_vivo\": $( { pgrep -c cron 2>/dev/null || echo 0; } | head -1 ),"
  echo "  \"crond_archivo\": \"$(ls -l /etc/cron.d/inv-cron 2>&1 | tr -d '\"')\","
  echo "  \"crond_lineas\": $( { grep -c . /etc/cron.d/inv-cron 2>/dev/null || echo 0; } | head -1 ),"
  echo "  \"conciliar_en_crond\": $( { grep -c conciliar /etc/cron.d/inv-cron 2>/dev/null || echo 0; } | head -1 ),"
  echo "  \"env_sh_vars\": $( { grep -c '^export' /data/env.sh 2>/dev/null || echo 0; } | head -1 ),"
  # Si esto NO es 0, el sed del Dockerfile no corrio y cron sigue rechazando los
  # trabajos: es la comprobacion de que la causa era esa y no otra.
  echo "  \"pam_loginuid_presente\": $( { grep -c pam_loginuid /etc/pam.d/cron 2>/dev/null || echo 0; } | head -1 )"
  echo "}"
} > /data/arranque.json 2>&1

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

# ── EL RELOJ PROPIO ─────────────────────────────────────────────────────────
# El portero de la casa (cron) esta despierto y la nota esta bien escrita, pero no
# arranca: medido, 12 minutos con tres turnos vencidos y cero papelitos. Ya se
# descartaron el archivo, los permisos, las variables de entorno y el tramite de
# fichaje (PAM). Antes de seguir adivinando causas de a una, se pone un despertador
# propio: un bucle que hace la tarea cada 5 minutos sin depender de nadie.
#
# 🔴 Es ADITIVO: no se toca cron ni las otras cuatro tareas. Si cron reviviera, la
# tarea correria dos veces — y eso ya es seguro: conciliar.php tiene su candado y
# "ya se envio" impide que una historia salga repetida. Correr de mas no rompe.
#
# `|| true` para que un fallo de una vuelta no mate el contenedor (arriba hay
# `set -e`), y `sleep` DESPUES del trabajo para no depender de cuanto tarde.
(
  sleep 45                       # dejar que apache y el arranque terminen
  while true; do
    . /data/env.sh 2>/dev/null || true
    php /var/www/html/conciliar-cron.php >> /data/cron.log 2>&1 || true
    sleep 300
  done
) &

exec "$@"
