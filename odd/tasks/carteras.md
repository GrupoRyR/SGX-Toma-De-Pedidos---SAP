# Carteras SAP: crear, renombrar y reconocerlas por número

Rama: `feat/carteras`. Estrategia de entrega: `ask-on-risk`.

## Objetivo

Que el admin de ventas (y TI) pueda crear, renombrar y desactivar carteras
desde Administración, y que la carga por Excel reconozca la cartera por su
**número** y no por el texto exacto.

## Problema

- No hay pantalla para crear ni renombrar carteras. Hoy solo nacen de rebote
  al cargar un Excel de clientes.
- La carga cruza la cartera por **texto exacto**. Si en SAP cambia el asesor
  (`14 MONICA RIVERA AREVALO` → `14 OTRA PERSONA`) o el Excel trae una tilde o
  un espacio distinto, se crea una cartera nueva sin asesor y sus clientes
  dejan de verse.

## Dato confirmado por SEGUREX (2026-10-01)

El número de la cartera en SAP es **fijo y no se repite**. Cuando cambia el
asesor se conserva el número y solo cambia el nombre. Verificado en la base
local: 22 carteras, todas con número, ninguno repetido.

## Alcance autorizado

- Columna `numero` en `asesores_sap`, única, llenada desde `codigo_texto`.
- Pestaña **Administración → Carteras**: lista (número, nombre, clientes,
  asesores asignados, activa), crear, renombrar, activar/desactivar.
- La carga por Excel y los comandos de consola resuelven la cartera por número.
- Pruebas y documentación.

Fuera de alcance: zonas (hoy no hay ninguna), cambiar la visibilidad de los
clientes de una cartera inactiva.

## Decisiones

- **El número no se edita** después de crear la cartera: es la llave con SAP.
- **Renombrar no mueve nada**: clientes y asesores están ligados por id, no por
  texto. `codigo_texto` se recompone como `"<numero> <NOMBRE>"`.
- **La carga por Excel nunca renombra**. Si el archivo trae `14 OTRA PERSONA`
  y existe la 14 con otro nombre, el cliente va a la 14 existente y la vista
  previa avisa la diferencia ("para renombrarla, ve a Carteras"). Así un error
  de escritura en el Excel no cambia el nombre de una cartera.
- Si el número no existe, se crea la cartera como hoy, con el aviso de que
  nadie la tiene asignada. Un texto sin número inicial es error en la carga.
- **Desactivar** solo la saca de las listas para asignar (formulario de cliente,
  asignación a asesores) y la marca en la carga. No cambia quién ve qué.
- La migración se detiene con un mensaje claro si en producción hubiera
  números repetidos o carteras sin número, en vez de fallar a medias.

## Tareas

- [x] T1 — Migración `numero` + modelo + resolución por número compartida (carga web y consola). Pruebas.
- [x] T2 — `ServicioCarteras` (crear, renombrar, activar/desactivar, bitácora) y pestaña Carteras. Pruebas.
- [x] T3 — Formularios de cliente y asignación a asesores: solo carteras activas. Pruebas.
- [x] T4 — Documentación y estilos compilados.

## Criterios de aceptación

- Renombrar la 14 conserva sus clientes y su asesor; el asesor los sigue viendo.
- Un Excel con `14 OTRA PERSONA` asigna a la 14 existente, no crea otra, y avisa.
- Un número nuevo crea la cartera con aviso; un número repetido no se puede crear.
- Asesor y gerente no entran a la pestaña.
- `php artisan test` en verde.

## Rutas y evidencia

| Tarea | Ruta | Disparador | Commit | Revisión |
|---|---|---|---|---|
| T1a | delegada | 4+ archivos | c43ab13 | ver abajo |
| T1b | delegada | 4+ archivos | 742ef16 | ver abajo |
| T2 | delegada | 2+ archivos no triviales | ab8ad24 | ver abajo |
| T3 | delegada | 2+ archivos no triviales | ed0cd8d | ver abajo |
| T4 | inline | docs mecanicos + build | (este commit) | pasiva |

## Próximo paso

`php artisan test`: 302 en verde (antes 276). Migración corrida en la base local: 22 carteras con número, ninguno repetido. Pestaña Carteras revisada en el navegador como César.

Despliegue: **requiere `php artisan migrate --force`** en el servidor (columna `numero`), además de los archivos y `route:cache`. No cambia `vendor/`.

## Decisiones del escritor

- `numero` queda nullable en la base, con índice único; el modelo lo llena siempre.
- Se quitó `AsesorSap::numero()` (chocaba con la columna); lo reemplaza `AsesorSap::numeroDelTexto()`.
- Una fila cuya única diferencia es el nombre de la cartera (mismo número) cuenta como Igual.
- El comando de consola resuelve por número pero no imprime aviso de nombre distinto.
- Las inactivas se ocultan solo en los selectores; la carga sí puede asignar a una inactiva, con aviso.
