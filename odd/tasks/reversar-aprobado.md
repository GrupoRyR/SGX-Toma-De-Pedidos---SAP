# Reversar un pedido aprobado a borrador

Rama: `feat/reversar-aprobado` (desde `main`, independiente de `feat/carteras`).
Estrategia de entrega: `ask-on-risk`.

## Objetivo

Que quien puede aprobar un pedido (gerente de su canal, ADMIN_VENTAS, TI) pueda
devolverlo de APROBADO o LIBERADO a BORRADOR, para que el asesor lo modifique
y lo envíe de nuevo a aprobación.

## Problema

Hoy, una vez aprobado, el pedido solo admite quitar líneas (`AJUSTAR_APROBADO`).
Si hay que agregar un producto o cambiar cantidades, no hay forma de devolverlo
al asesor: el rechazo solo existe para pedidos PENDIENTE.

## Alcance autorizado

- Acción "Reversar a borrador" en la pantalla del pedido.
- Servicio, política, bitácora, correo al asesor, pruebas.

## Decisiones

- **Quién:** la misma regla que para aprobar ese pedido (`puedeAprobar()` y lo
  ve), sin la restricción de autoaprobación: devolver no aprueba nada.
- **Cuándo:** estado APROBADO o LIBERADO y `importado_sap = false`. Nunca un
  pedido que ya entró a SAP. No se reversa uno que otra persona está editando.
- **Motivo obligatorio.** Se guarda en `motivo_rechazo` para que el asesor lo
  vea en el pedido igual que un rechazo, va en la bitácora (`REVERSAR_APROBADO`)
  y se le avisa por correo (mismo mecanismo que el rechazo, nunca bloquea).
- **Se limpia** aprobado_por, fecha_aprobacion, liberado_por, fecha_liberacion,
  snapshot_aprobado, plantillas_descargadas_en y version_al_descargar. La copia
  congelada anterior y los datos de la aprobación quedan en el detalle de la
  bitácora: es la evidencia de lo que se había aprobado. Al volver a aprobarlo
  se toma una copia nueva.
- **Plantillas ya descargadas:** si `plantillas_descargadas_en` tiene fecha, la
  pantalla advierte que ese pedido puede estar ya en un archivo de DTW y pide
  confirmar ("si ya lo importaste, no lo reverses: márcalo como importado").
  El servicio exige esa confirmación explícita; sin ella, rechaza.
- `version` se incrementa: cualquier pantalla abierta con la versión anterior
  queda vieja.

## Tareas

- [x] T1 — `ServicioPedidos::reversarABorrador` + `PedidoPolicy::reversar`, con bitácora y correo. Pruebas.
- [x] T2 — Botón y confirmación en `⚡pedido` (motivo, advertencia de plantillas). Pruebas.
- [x] T3 — Documentación y estilos compilados.
- [x] T4 — Correcciones de la revisión: el servicio valida el canal; Cancelar limpia la confirmación de DTW; prueba de que el correo no dice "rechazado". 301 pruebas en verde.

## Criterios de aceptación

- Un pedido aprobado reversado queda en BORRADOR, el asesor lo edita, lo envía y vuelve a la bandeja de aprobación.
- Un pedido importado a SAP no se puede reversar.
- Una gerente no reversa pedidos de otro canal; un asesor nunca.
- Sin motivo no se reversa.
- Con plantillas descargadas, sin confirmación no se reversa.
- `php artisan test` en verde.

## Rutas y evidencia

| Tarea | Ruta | Disparador | Commit | Revisión |
|---|---|---|---|---|
| T1 | delegada | 2+ archivos no triviales | 832c4c0 | ver abajo |
| T2 | delegada | 2+ archivos no triviales | 84e0d92 | ver abajo |
| T1 | — | revisión RDD por partes | 832c4c0 | aprobada (review-7fba4b8d72da8fb2), 3 observaciones |
| T2 | — | revisión RDD por partes | 84e0d92 | aprobada (review-16e8a109c324623b), 2 observaciones |
| T3 | inline | docs mecanicos + build | c692cc8 | pasiva |
| T4 | inline | 3 archivos, correcciones de la revisión | (ver git log) | pendiente |

## Próximo paso

`php artisan test`: 298 en verde (276 + 22 nuevas). Revisado en el navegador: la gerente de otro canal recibe 403 en el pedido; César ve "Reversar a borrador" en el #5000 con la advertencia de plantillas descargadas y la casilla de confirmación (no se ejecutó la reversa sobre datos de desarrollo).

Despliegue: solo archivos + `view:cache`; sin migraciones ni `vendor/`. Independiente de `feat/carteras`.

## Pendiente para cuando se retome el robot puente

La revisión señaló que un pedido LIBERADO se puede reversar mientras
`importado_sap` sea falso. Si el robot ya tomó el pedido pero todavía no lo
marcó como importado, la web lo mostraría en borrador mientras SAP lo crea.
Hoy no aplica (el robot está en pausa y la carga es manual por DTW). Al
retomarlo, reversar debe exigir que el robot no lo haya tomado, igual que
`PedidoPolicy::devolver`.
