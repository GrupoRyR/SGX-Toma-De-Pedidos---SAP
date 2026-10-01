# Maestros desde Administración: formularios y carga por Excel

Rama: `feat/maestros-admin`. Estrategia de entrega: `ask-on-risk`.

## Objetivo

Que el admin de ventas (y TI) pueda dar de alta y editar clientes y productos
sin terminal: con un formulario uno por uno, o subiendo un Excel/CSV que se
**previsualiza** y del que se elige qué filas cargar (o todas).

## Problema

Hoy los maestros solo entran por `pedidos:importar-clientes` /
`pedidos:importar-precios` (consola en el servidor) o por el robot puente,
que está en pausa. Un cliente o producto nuevo no se puede crear desde la web.

## Alcance autorizado

- Formulario de cliente: crear y editar (código SN, nombre, dirección, ciudad,
  canal, cartera, % descuento, activo).
- Formulario de producto: crear y editar (código, descripción, familia,
  precio de lista, activo).
- Carga por archivo (.xlsx o .csv) para clientes y para productos:
  previsualización con estado por fila (nuevo / cambia / igual / error),
  selección por fila o "todos", y carga solo de lo seleccionado.
- Pestañas nuevas en Administración. Acceso: quien `administra` (ADMIN_VENTAS, TI).

Fuera de alcance: inventario, borrar maestros (se desactivan), robot puente.

## Restricciones y decisiones

- El código (CardCode / ItemCode) tiene que coincidir con SAP: es lo que viaja
  en la plantilla DTW. Se valida único y sin espacios.
- La carga por archivo **no escribe nada al subir**: primero muestra, después
  carga lo elegido. Una fila que "cambia" muestra el antes y el después.
- Una celda vacía en el archivo **no borra** el dato existente (a diferencia
  del comando actual, que escribe null/0).
- Lectura de .xlsx con `openspout/openspout` (requiere ext-zip y xmlreader).
  Implica volver a subir `vendor/` en el despliegue.
- Toda escritura queda en la bitácora; cada carga deja una fila en `importaciones`
  con el usuario.
- Un cliente nuevo solo lo ve el asesor de su cartera (`asesor_sap_id`): el
  formulario y la carga lo resuelven por el texto de la cartera.

## Tareas

- [x] T0 — Commit de la revisión de plantillas ya publicada (8c65295). Ruta: inline.
- [x] T1 — `ServicioMaestros`: crear/editar cliente y producto con validación y bitácora. Pruebas.
- [x] T2 — Pantallas de clientes y productos en Administración (lista con búsqueda + formulario). Pruebas.
- [x] T3 — Lector de archivos (.xlsx/.csv) con la normalización compartida (BOM, tildes, alias, miles). Pruebas.
- [x] T4 — Pantalla de carga: subir, previsualizar, seleccionar, cargar. Pruebas.
- [x] T5 — Documentación (ESTADO, README, DESPLIEGUE) y estilos compilados.

## Criterios de aceptación

- Un admin crea un cliente con su cartera y el asesor de esa cartera lo ve en "Mis clientes".
- Un código repetido o vacío se rechaza con un mensaje claro.
- Subir un archivo no cambia la base de datos hasta pulsar "Cargar".
- Se puede cargar una sola fila, varias o todas.
- Un asesor o gerente no entra a estas pantallas.
- `php artisan test` en verde.

## Rutas y evidencia

| Tarea | Ruta | Disparador | Commit | Revisión |
|---|---|---|---|---|
| T0 | inline | 1 archivo ya hecho | 8c65295 | ver abajo |
| T1 | delegada | 2+ archivos no triviales | 2e0cccf | ver abajo |
| T2 | delegada | 2+ archivos no triviales | e966188 | ver abajo |
| T3 | delegada | 2+ archivos no triviales | 29c02e5 | ver abajo |
| T4 | delegada | 2+ archivos no triviales | a8e36ec | ver abajo |
| T5 | inline | docs mecanicos + build | (este commit) | pasiva |

## Próximo paso

Listo para revisión y despliegue. `php artisan test`: 276 en verde (antes 237). Pantallas revisadas en el navegador como César (Clientes, Productos, Cargar archivo). Revisión RDD: evaluación pendiente sobre la rama completa.

## Decisiones del escritor

- Editar solo cambia los campos que llegan; la carga por archivo manda solo celdas no vacías.
- Una fila que cambió entre la vista previa y la carga se rechaza ("vuelve a subir el archivo").
- Producto nuevo por archivo exige descripción y precio > 0; un número ilegible es error, no 0. La carga no cambia `activo`.
- Avisos en la vista previa: cartera nueva o sin asesor, cliente sin cartera, canal nuevo.
- Máximo 5 MB y 5000 filas por archivo.
- Los comandos de consola conservan su comportamiento (vacío escribe null/0).
