# Robot puente SEGUREX ↔ web

Contrato de la API que consume el robot. Esta es la única forma en que los
pedidos llegan a SAP y en que los maestros de SAP llegan a la web.

## Por qué existe

GoDaddy no puede llegar al SAP on-premise: está detrás de la VPN Sophos y no se
va a exponer a internet. Así que la integración **sale desde adentro de
SEGUREX**: el robot corre en la máquina que ya tiene VPN y tarea programada, y
llama a la web.

## La regla que no se negocia

**El robot nunca lee pedidos en `APROBADO`.** Solo salen los que César Garzón o
Marly Ossa pasaron a `LIBERADO`. Si alguna vez alguien relaja esta condición, se
pierde el control humano que SEGUREX puso antes de SAP, y los pedidos empiezan a
crearse solos con la aprobación de un gerente de canal.

Está cubierto por la prueba `test_solo_salen_los_pedidos_liberados`.

## Autenticación

Cada llamada lleva el token en la cabecera:

```
Authorization: Bearer sgx_...
```

El token se crea por consola en el servidor y **se muestra una sola vez**:

```bash
php artisan pedidos:token-puente crear --nombre="Robot puente SAP" --ips="181.x.x.x"
```

- Se guarda el hash SHA-256, nunca el token. Si se pierde, se genera otro.
- `--ips` limita el token a la IP de salida de SEGUREX. **Conviene usarlo**: sin
  lista, cualquiera con el token entra desde donde sea.
- Para ver los tokens y su último uso: `php artisan pedidos:token-puente`
- Para cortar el acceso de inmediato: `php artisan pedidos:token-puente revocar --id=1`

Límite de peticiones: 120 por minuto.

## Prueba de vida

Antes de mover un solo pedido, confirmar que el token quedó bien:

```
GET /api/sap/saludo
→ { "ok": true, "token": "Robot puente SAP", "hora_servidor": "..." }
```

## Bajada: de la web a SAP

### 1. Pedir lo que está listo

```
GET /api/sap/pedidos-liberados?limite=50
```

```json
{
  "pedidos": [
    {
      "id": 5000,
      "codigo_cliente": "CN0017",
      "nombre_cliente": "ALMACEN EL ARQUITECTO SAS",
      "orden_compra": "OC-4471",
      "fecha_facturacion": "2026-09-30",
      "direccion_entrega": "CALLE 83 N 14 19",
      "ciudad_entrega": "BOGOTA",
      "observaciones": null,
      "subtotal": 163548, "iva": 31074, "total": 194622,
      "fecha_liberacion": "2026-09-25T16:29:00-05:00",
      "intentos_previos": 0,
      "lineas": [
        {
          "numero": 0,
          "codigo_producto": "PTS09040KC",
          "descripcion": "SEGUREX A-80PD CERRADURA ENTRADA SATURNO SATIN NIQUEL",
          "cantidad": 4, "precio_unitario": 40887, "subtotal": 163548
        }
      ]
    }
  ],
  "cuantos": 1,
  "consultado_en": "2026-09-25T16:45:00-05:00"
}
```

**Una lista vacía es el resultado normal la mayor parte del día.** Si viene
vacía, el robot no hace nada.

El `id` del pedido es el número que el usuario ve en pantalla, y el que va en
`U_SGX_IdPedidoApp`. Arranca en 5000 para no chocar con los que la app de Power
Apps ya dejó en SAP.

### 2. Confirmar que SAP lo creó

```
POST /api/sap/confirmar
{ "pedido_id": 5000, "docentry": "1042", "docnum": "30015" }
```

El pedido pasa a `IMPORTADO` y se guarda el número real de SAP.

**Es idempotente:** si el robot pierde la respuesta y confirma otra vez, la
segunda llamada devuelve 200 sin duplicar nada. No hace falta manejarlo del lado
del robot.

### 3. Reportar que SAP lo rechazó

```
POST /api/sap/error
{ "pedido_id": 5000, "mensaje": "Cliente bloqueado por cartera" }
```

```json
{ "pedido_id": 5000, "intentos": 1, "reintentar": true }
```

- El pedido **no cambia de estado**: sigue `LIBERADO`.
- Vuelve a aparecer en la bandeja de liberación, arriba, bajo "Fallaron en SAP",
  con el mensaje textual que mandó el robot. Por eso el mensaje tiene que ser el
  de SAP, sin traducir ni resumir: quien lo va a arreglar necesita el original.
- A los **3 intentos** el pedido sale de la cola: deja de venir en
  `/pedidos-liberados` y queda esperando que una persona lo resuelva.
  `"reintentar": false` avisa de esto sin que el robot tenga que contar.

## Subida: de SAP a la web

Todas son upsert por código. **Ninguna borra lo que no venga en el envío**: un
envío incompleto no puede vaciar el catálogo ni el maestro de clientes.

### Existencias

```
POST /api/sap/maestros/inventario
{
  "corte": "2026-09-25T06:00:00-05:00",
  "filas": [
    { "codigo_producto": "PTS09040KC", "bodega": "PRINCIPAL", "disponible": 14 }
  ]
}
```

`corte` es opcional; si no viene se usa la hora del servidor. La web muestra
siempre esa fecha junto a la cantidad, porque lo que ve el asesor es una foto y
no una reserva. Pasadas 24 horas se muestra atenuado con advertencia.

### Lista de precios

```
POST /api/sap/maestros/precios
{ "filas": [ { "codigo": "PTS09040KC", "descripcion": "...", "precio_lista": 71000, "familia": "CERRADURAS" } ] }
```

Ojo con el formato: **el precio va como número**, `71000`, no como `"71.000"` ni
`"71,000"`. La coma en los CSV de SharePoint separa miles, y leerla como decimal
divide los precios por mil.

### Clientes

```
POST /api/sap/maestros/clientes
{ "filas": [ { "codigo_sn": "CN0017", "nombre": "...", "direccion": "...", "ciudad": "...", "porcentaje_descuento": 30 } ] }
```

**No se sube cartera, cupo ni costo.** SEGUREX lo dejó fuera de alcance.

## Errores

| Código | Qué pasó |
|---|---|
| 401 | Falta el token, o no es válido, o fue revocado |
| 403 | El token es válido pero la IP no está en su lista |
| 422 | El cuerpo no pasó validación, o el pedido no estaba en el estado esperado. El motivo viene en `error` o en `errors` |
| 429 | Más de 120 peticiones por minuto |

Los 422 por estado (`"El pedido 5000 no esta liberado: no debio salir a SAP."`)
no se reintentan: significan que el robot está trabajando sobre algo que ya
cambió. Volver a consultar `/pedidos-liberados`.

## Qué queda registrado

Cada confirmación, cada error y cada publicación de maestros queda en el log de
registros (`/administracion/registros`), con la hora y el conteo de filas. El uso
del token queda en la tabla `tokens_servicio` (`ultimo_uso`, `ultima_ip`), que se
ve con `php artisan pedidos:token-puente`.

## Plan B

Si el robot está caído, los pedidos se quedan en `LIBERADO` esperando, sin
perderse. La bandeja de liberación avisa cuando el más antiguo lleva más de 24
horas. La carga manual por DTW sigue siendo posible con los comandos de
importación.
