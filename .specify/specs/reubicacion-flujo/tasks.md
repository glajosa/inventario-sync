# Tasks — Flujo completo de REUBICACIÓN

Orden de entrega: cada tramo se despliega con su perilla **apagada**, se prueba y se enciende.

- [ ] **T0** Decisiones de Jesua: [D1] el destino del deal nuevo · [D2] cuándo se vacía · el despliegue de b488e7c (¿junto con Sun Bay?).
- [ ] **T1** Tramo 2 primero (lo más seguro): REUBICACIÓN fuera del motor, del barrido, de `$activas`, de las llamadas y del puntaje en cobranza2. Pruebas: test_agenda y test_gestion con la etapa nueva.
- [ ] **T2** Tramo 1: REUBICACIÓN en las listas de inventario-sync (protocolo, botón, stagelib) + pruebas de cobranza-protocolo.
- [ ] **T3** Tramo 1: reubicalib mueve el 48 a REUBICACIÓN (perilla `REUBICA_ETAPA_48`). Va con el despliegue de b488e7c.
- [ ] **T4** Tramo 4: la foto base del puntaje + `score_fifo_hist` con la base. Pruebas con la plata completa (caso 2525) y un mes bueno que baja la racha.
- [ ] **T5** Tramo 3: emparejamiento + fusión (perilla `reubica_fusion_on` en 0). Pruebas puras; después, prueba real con un deal de prueba de Jesua.
- [ ] **T6** Tramo 5: vigilante de REUBICACIÓN sin fusión → falla.php.
- [ ] **T7** Los dos casos reales en curso: Marcel Salvador 9610 (E-1-20) y 354001 (E-1-21). Hoy están FINAL_INVOICE/LOSE; decidir con Jesua si entran al flujo nuevo o se cierran a mano.
- [ ] **T8** Avisos: VIGILANTE (cada commit), la sesión de inventario (cada despliegue) y la del dashboard (la etapa nueva).
