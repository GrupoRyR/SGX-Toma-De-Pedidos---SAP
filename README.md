# Web de Pedidos SEGUREX — Fase 1

Reemplazo de la app de Power Apps `AppPedidosSap`. **Laravel 13.33 + PHP 8.3 + MySQL**, para GoDaddy cPanel compartido con SSH y **sin Node.js en el servidor**.

> **Verificar antes de desplegar: el plan de GoDaddy debe ofrecer PHP 8.3 o superior** (cPanel -> MultiPHP Manager). Laravel 13 lo exige. La especificacion decia Laravel 11, pero esa rama ya arrastra avisos de seguridad y Composer se niega a instalarla; por eso se uso la version vigente.

La especificación completa está en [`../PROMPT_WEB_PEDIDOS_GODADDY.md`](../PROMPT_WEB_PEDIDOS_GODADDY.md) y las mejoras acordadas en [`../PROPUESTAS_MEJORAS_WEB_PEDIDOS.md`](../PROPUESTAS_MEJORAS_WEB_PEDIDOS.md).

---

## Estado

**Funcionando y verificado:** 233 pruebas en verde (`php artisan test`).

| Componente | Estado |
|---|---|
| Esqueleto Laravel 13 + dependencias | Instalado |
| Esquema completo (10 migraciones) | Migra sin errores |
| Usuarios, roles y permisos sembrados | 22 usuarios, `LIBERAR_SAP` solo para Cesar y Marly |
| Modelos con visibilidad en la consulta | Probados |
| `CalculadoraPrecios` (descuento, ATP, precio manual, IVA) | Probada |
| Numerador arrancando en 5000 | Probado |
| Comandos de importacion de CSV | **Corridos con los archivos reales**: 216 productos y 641 clientes |
| Asignacion directa de clientes a usuarios | Implementada y probada |
| Revision de salud de datos (`pedidos:revisar-datos`) | Funcionando |
| Ingreso con Microsoft Entra ID | **Probado de punta a punta con cuentas reales** |
| Pantalla "Mis clientes" (Livewire) | Funcionando con los 641 clientes reales |
| Ficha del cliente con sus pedidos | Funcionando |
| Armado del pedido (buscador, lineas, totales) | Verificado en navegador con datos reales |
| `ServicioPedidos` (crear, lineas, renumerar, enviar) | Probado |
| Policies de `Cliente`, `Pedido` y `Usuario` | Registradas y probadas |
| Aprobar, rechazar, liberar y devolver | Probado |
| Bandeja de aprobacion y bandeja de liberacion | **Recorridas en navegador**: #5000 llego a `LIBERADO` |
| Bloqueo de edicion enganchado a la pantalla | Funcionando |
| Eliminar pedidos desde la interfaz | Funcionando (papeleria de 30 dias) |
| Modulo de administracion (usuarios, permisos, configuracion) | Funcionando, 30 pruebas |
| Log de registros con filtros | Funcionando |
| Plantillas de carga manual a SAP (DTW) | Funcionando, 38 pruebas. Mismos campos que las listas de SharePoint de la app vieja |
| API del robot puente | Escrita y probada (22 pruebas), **en pausa por decision de SEGUREX**. Contrato en [`docs/ROBOT_PUENTE.md`](docs/ROBOT_PUENTE.md) |
| Inventario en el buscador de productos | Funcionando, verificado publicando existencias por la API |
| Correo de rechazo por Microsoft Graph | Funcionando, 10 pruebas. Falta configurar el buzon remitente |
| Aplicacion instalable (PWA) | **Probada en Chrome**: el service worker sirve la interfaz y la pantalla de sin conexion desde el cache |

Para desarrollo local se usa **SQLite** (`database/database.sqlite`), ya configurado. En produccion es MySQL: las migraciones detectan el motor y ajustan lo que cambia entre uno y otro.

## Puesta en marcha

En esta maquina PHP 8.3.35 y Composer 2.10.3 quedaron instalados en `C:/Users/Claude/php83`, ya agregado al `PATH` del usuario.

### 1. Instalar dependencias (si se clona en otra maquina)

```bash
composer install
```

### 2. Configurar el entorno

Copiar `.env.example` a `.env` y ajustar:

```
DB_CONNECTION=mysql
DB_DATABASE=segurex_pedidos
DB_USERNAME=...
DB_PASSWORD=...

# El numerador NO puede empezar en 1: en SAP ya existen pedidos de la app vieja
# con numeros bajos en U_SGX_IdPedidoApp. Confirmar el ultimo numero emitido en
# Power Apps y dejar este valor por encima, con margen.
PEDIDOS_NUMERO_INICIAL=5000

# Hosting compartido: sin Redis ni procesos permanentes.
CACHE_STORE=database
SESSION_DRIVER=database
QUEUE_CONNECTION=database
```

### 3. Migrar y sembrar

```bash
php artisan migrate --seed
```

### 4. Importar los maestros

Exportar de SharePoint `CLIENTES_SAP` y `LISTA_PRECIOS` a CSV, dejarlos en `storage/importaciones/` y correr primero en simulación:

```bash
php artisan pedidos:importar-clientes storage/importaciones/CLIENTES_SAP.csv --simular
```

Si el resumen se ve bien, repetir sin `--simular`. Igual con los precios:

```bash
php artisan pedidos:importar-precios storage/importaciones/LISTA_PRECIOS.csv --simular
```

Eso es para la carga inicial. En el día a día los maestros se mantienen desde
la web: **Administración → Clientes / Productos** para crear o editar uno, y
**Administración → Cargar archivo** para un Excel o CSV. La carga primero
muestra qué es nuevo, qué cambia (antes → después), qué es igual y qué tiene
error; solo se guarda lo que se seleccione. Una celda vacía no borra el dato
que ya existe (el comando de consola sí lo hace). Lee `.xlsx` con
`openspout/openspout`, que necesita las extensiones `zip` y `xmlreader`.

### 5. Enlazar las carteras

Los asesores SAP **no se siembran a mano**: se crean solos al importar clientes, con el texto exacto que trae SAP. Por eso este paso va al final:

```bash
php artisan db:seed --class=AsignacionesSeeder
```

El comando reporta qué números de asesor no encontró, en vez de fallar. Casi siempre significa que esa cartera ya no tiene clientes o que el texto cambió en SAP.

---

## Decisiones que ya están dentro del código

- **Los pedidos arrancan desde cero.** No se migran `PEDIDO_SAP` ni `DETALLE_PEDIDO_SAP`; el historial se queda en SharePoint. Conviene dejar la app de Power Apps en solo lectura un tiempo.
- **El numerador arranca en 5000**, no en 1, para no repetir identificadores que SAP ya tiene guardados en `U_SGX_IdPedidoApp`.
- **Un asesor ve sus carteras de SAP mas los clientes que le asignen directamente** (tabla `cliente_usuario`). Asi puede trabajar aunque no tenga cartera. Lo que no pasa es que la falta de configuracion abra la puerta: sin nada asignado ve cero, no los 641.
- **`LIBERAR_SAP` es un permiso, no un rol**, y lo tienen únicamente César Garzón y Marly Ossa. Soporta vigencia por si algún día se designa un reemplazo temporal, pero no se crea ninguno.
- **El pedido lleva `version`** para el control de concurrencia: el asesor puede editar mientras no esté aprobado, y al aprobar se verifica que nadie lo haya cambiado en el entretanto.
- **ATP y precio manual no tienen tope.** El control es humano.
- **Nadie aprueba su propio pedido, salvo `TI`**, que si puede para probar el flujo y hacer inducciones. Queda marcado en la bitacora como `autoaprobacion`, y no abre camino a SAP: liberar es un permiso aparte que `TI` no tiene.
- **Al aprobar se guarda una copia congelada** del pedido (`snapshot_aprobado`), para poder responder que fue exactamente lo que se aprobo si despues cambia la lista de precios.
- **No se trae cartera, cupo ni costo** desde SAP.
- **`bloqueado_por` / `bloqueado_hasta`** reemplazan el estado `EDITANDO` de la app vieja, que dejaba pedidos atascados.
- **Abrir un pedido no es lo mismo que editarlo.** El bloqueo lo toma automaticamente el autor; un administrador, que tambien puede editar, entra a revisar y solo bloquea si pulsa "Editar este pedido". Sin esta distincion, un aprobador que abria el pedido se impedia a si mismo aprobarlo.
- **Un bloqueo propio nunca estorba a quien lo tiene.** Las verificaciones preguntan por `bloqueadoPorOtro`, no por `bloqueadoAhora`.
- **Repartir el permiso `LIBERAR_SAP` es de TI, no del admin de ventas.** Es la ultima puerta antes de SAP: quien la usa no la reparte. TI, a su vez, no puede liberar.
- **Nadie se edita ni se desactiva a si mismo** desde el modulo de administracion.
- **Los usuarios no se borran, se desactivan.** Sus pedidos tienen que seguir diciendo quien los hizo.
- **El inventario es una foto, no una reserva.** Siempre se muestra con la fecha del corte al lado; pasadas las horas configuradas se atenua, pero no se esconde. Pedir mas de lo disponible avisa y no bloquea: a veces se pide contra reposicion.
- **El cache del service worker se versiona solo** con el hash del manifiesto de Vite (`/sw.js?v=...`). No hay ningun numero que subir a mano al desplegar: si lo hubiera, el dia que se olvidara los asesores se quedarian con la interfaz vieja sin manera de enterarse.
- **Zona horaria `America/Bogota` y locale `es`.** Con la zona en UTC toda hora en pantalla salia cinco horas adelantada.

---

## Lo que falta

1. **Confirmar que llega el correo de rechazo.** El buzon remitente ya quedo configurado (`segurex.info@segurex.com`). Falta rechazar un pedido de prueba y verificar en la tabla `notificaciones` que `resultado` dice `ENVIADO`. Si dice `ErrorAccessDenied`, es que la ApplicationAccessPolicy de Exchange no apunta a ese mismo buzon.
2. **Probar la instalacion en un celular.** El service worker ya se verifico en Chrome sobre localhost, pero instalar la app en un telefono necesita el dominio real con HTTPS, asi que queda para despues del despliegue.
3. **Confirmar el formato de las plantillas contra DTW.** Salen con los mismos campos que hoy escribe la app vieja en SharePoint, separados por tabuladores. Falta importar una tanda de prueba para confirmar que DTW las acepta tal cual.
4. **El robot puente del lado de SEGUREX**, cuando se retome. La web ya expone la API y esta probada; falta el script que corre adentro. Contrato en [`docs/ROBOT_PUENTE.md`](docs/ROBOT_PUENTE.md).
5. **Despliegue en GoDaddy.** Paquete y manual listos: [`docs/DESPLIEGUE_GODADDY.md`](docs/DESPLIEGUE_GODADDY.md).

## El correo de rechazo

Es la unica notificacion que quedo en alcance. Sale por **Microsoft Graph y no por SMTP** porque el hosting compartido de GoDaddy suele bloquear los puertos de correo saliente (25, 465, 587); Graph viaja por HTTPS normal.

- Se envia desde un **buzon de servicio**, no desde el buzon de quien rechaza.
- Si Graph falla, **el rechazo igual queda hecho**: el correo es un aviso, no parte de la decision. El intento queda en la tabla `notificaciones`, y quien rechazo lo ve en la pantalla del pedido.
- Nadie se avisa a si mismo: TI rechaza sus propios pedidos en las inducciones y no tiene sentido mandarse un correo.

## Las plantillas para SAP

Mientras no exista el robot, la carga a SAP es manual y sale de **Administracion -> Plantillas SAP**, que alcanzan TI, Cesar y Marly.

Aparecen ahi **todos los pedidos aprobados** que falten por cargar. **No se exige el visto bueno**: ese candado existia para frenar al robot, que carga solo y sin que nadie mire. Cuando la carga la hace una persona que ve la lista completa antes de descargar, el segundo paso no agregaba control, solo trabajo. Los pedidos que igual tengan visto bueno tambien salen.

Genera los tres archivos con **los mismos campos, nombres y orden** que la app vieja escribia en las listas `ENCABEZADO PEDIDOS SAP`, `DETALLE PEDIDO SAP` y `PLANTILLA DIRECCIONES`. Eso es deliberado: quien importa en DTW no tiene que volver a mapear columnas. Son de texto separado por tabuladores, que es el formato de las plantillas de DTW y ademas abre derecho en Excel sin depender de si el separador de listas del equipo es coma o punto y coma.

**El encabezado va dos veces, repetido igual.** No es un adorno: DTW toma las dos primeras lineas como encabezado, asi que con una sola se come la primera fila de datos creyendo que es la segunda linea del encabezado. El resultado es que **el primer pedido de cada tanda se pierde, y nada avisa**: el archivo se importa sin errores y simplemente falta un pedido. Lo fijan las pruebas `test_el_encabezado_va_dos_veces_en_los_tres_archivos` y `test_no_se_pierde_el_primer_pedido`.

Dos diferencias con la app vieja, las dos a favor:

- **Las plantillas se generan al liberar, no al aprobar.** Un pedido aprobado sin el visto bueno de Cesar o Marly no aparece en esa pantalla.
- **Nada se borra despues.** La app vieja vaciaba las listas tras importar, asi que no quedaba forma de saber que se habia mandado. Aqui el pedido queda `IMPORTADO` con quien lo marco y cuando, y la descarga tambien queda en el log.

**El precio manual se corrige a mano en SAP.** La plantilla de detalle manda el ATP como `DiscountPercent` y no lleva el precio escrito a mano, asi que SAP calcula el suyo para esas lineas. SEGUREX decidio (2026-09-28) dejarlo asi y ajustarlas en el documento despues de importar, igual que se hace hoy con la app vieja. Lo que aporta la web es avisar **cuales** pedidos lo traen antes de importar, en vez de que la diferencia aparezca al facturar. Si alguna vez se decide mandarlo, va como columna `Price` en `PlantillasSap::detalles()`.

### Ajustar un pedido ya aprobado

El caso real: el cliente cancela un item cuando el pedido ya paso por aprobacion. Devolverlo al asesor para que lo corrija y que vuelva a recorrer todo el camino es desproporcionado, asi que **quien aprueba puede quitar la linea directamente** desde la pantalla del pedido, mientras no haya entrado a SAP.

- **Solo quitar, nunca agregar.** Agregar un producto es cambiar hacia arriba lo que se aprobo, y eso si tiene que volver a pasar por aprobacion.
- **No se puede dejar el pedido sin productos.** Si ya no va, se rechaza entero.
- **Una vez en SAP no se toca**: a partir de ahi la verdad esta alla, y si la web dijera otra cosa tendriamos dos versiones del mismo pedido.
- **`snapshot_aprobado` no se reescribe.** Esa foto dice que fue lo que se aprobo y sigue siendo cierta; el ajuste posterior queda en el log como `AJUSTAR_APROBADO`, con el total de antes y el de despues. Reescribirla borraria la unica evidencia de que el pedido cambio.

**No hay dos copias que sincronizar.** Las plantillas se arman al descargar, leyendo el pedido, asi que la siguiente descarga ya sale corregida. Ese era justamente el problema de la app vieja, que escribia una copia en SharePoint al aprobar y despues habia que acordarse de arreglarla en los dos lados.

Lo que si puede quedar viejo es un archivo **que alguien ya se llevo**. Por eso el pedido guarda la version que tenia al descargarse (`version_al_descargar`), y tanto la pantalla del pedido como la de plantillas avisan "cambio, vuelve a bajarlo". Se compara la version y no la hora: dos cambios dentro del mismo segundo tienen la misma marca de tiempo, pero nunca la misma version.

### Reversar un aprobado a borrador

Cuando hay que **agregar** o cambiar cantidades en un pedido ya aprobado, quitar lineas no alcanza. Quien puede aprobar ese pedido (la gerente de su canal, ADMIN_VENTAS o TI) lo **reversa a borrador** desde la pantalla del pedido: el asesor lo corrige, lo envia y vuelve a pasar por aprobacion.

- Solo desde APROBADO o LIBERADO, y **nunca si ya entro a SAP**.
- **Motivo obligatorio.** El asesor lo ve en el pedido y le llega por correo, igual que un rechazo. Queda en el log como `REVERSAR_APROBADO`.
- Se limpian la aprobacion, la liberacion y la marca de descarga de plantillas. La copia congelada anterior (`snapshot_aprobado`) queda en el detalle del log: es la evidencia de lo que se habia aprobado. Al volver a aprobarlo se toma una nueva.
- **Si las plantillas ya se descargaron**, pide confirmar que el pedido no se importo en DTW. Si ya se importo, lo correcto es marcarlo como importado y corregirlo en SAP; reversarlo y volverlo a aprobar lo metería dos veces.

**Descargar no marca nada.** Marcar es un segundo paso, a proposito: entre una cosa y otra DTW puede rechazar el archivo, y marcarlos al descargar dejaria pedidos que la web da por puestos en SAP y que nadie volveria a mirar.

## El robot puente (en pausa)

SEGUREX decidio dejarlo para despues y cargar a SAP con las plantillas de arriba. La API ya esta escrita y probada, y no estorba: si nadie crea un token, nadie puede entrar.

Cuando se retome, es la forma en que un pedido llega a SAP y en que los maestros de SAP llegan a la web. GoDaddy no alcanza el SAP on-premise: la integracion sale **desde adentro de SEGUREX**.

La regla que no se negocia: **el robot solo ve pedidos `LIBERADO`**. Un pedido `APROBADO` no existe para la API. Esta cubierto por `test_solo_salen_los_pedidos_liberados`.

Para crear el token del robot en el servidor:

```bash
php artisan pedidos:token-puente crear --nombre="Robot puente SAP" --ips="<IP de salida de SEGUREX>"
```

El valor se muestra **una sola vez** y solo se guarda su hash. Para ver los tokens y su ultimo uso, `php artisan pedidos:token-puente`; para cortar el acceso, `... revocar --id=N`.

## Trampas de Livewire 4 y Blade, ya resueltas

- Los componentes son **de un solo archivo** y viven en `resources/views/components/` con un rayo en el nombre. Se enrutan con `Route::livewire('/ruta', 'nombre-del-componente')`, no con una clase.
- **Nunca poner `use RuntimeException;`** ni ningun `use` de una clase raiz en el bloque PHP del componente: Livewire lo compila dentro de un namespace, PHP avisa que ese `use` no tiene efecto y Laravel lo escala a excepcion. La pantalla responde 404 sin explicacion. Se escribe `\RuntimeException` en su lugar.
- **`@if ... @else ... @endif` en una sola linea dentro de un parrafo rompe el `@elseif` que lo envuelve**, con un `ParseError` que apunta a la plantilla compilada y no al origen. Si hace falta texto condicional en linea, se resuelve con un ternario dentro de `{{ }}`.

## Trampas de los CSV de SharePoint, ya resueltas

Quedan anotadas porque las cuatro rompieron la importacion la primera vez:

1. **La coma de `PRECIO LIST` separa miles, no decimales.** `"64,900"` son $64.900. Leerla como decimal divide todos los precios por mil y los pedidos salen regalados.
2. **Los archivos traen BOM.** Hay que saltarlo antes de parsear; si no, la primera columna llega como `"CODIGO"` con las comillas dentro.
3. **Los encabezados llevan tilde** (`CODIGO`, `Titulo`, `Direccion`): sin quitar acentos antes de normalizar, la letra acentuada desaparece y la columna no se reconoce.
4. **El asesor y la descripcion vienen en la columna `Titulo`** (la columna Title de SharePoint), no en columnas con ese nombre.

## Datos limpiados

- Canales mal escritos del maestro de SAP unificados con `pedidos:unificar-canal`: `Cons. Resicencial` (2 clientes) y `Distribuidor` (1 cliente). Quedaron 267 en Cons. Residencial y 374 en Distribucion.
- **Pendiente:** la cartera `85 LILIAM HAIDEIDI HERRERA RAMIREZ` tiene 1 cliente y no esta asignada a nadie.

## Lenguaje visual

Vive en `resources/css/app.css` como tokens de Tailwind, para que el resto de las pantallas lo herede.

- **Paleta de acabados metalicos**, tomada de lo que vende la empresa (satin niquel, negro mate, cromado): grises frios de acero, no el beige de plantilla. El **naranja corporativo `#F65810` se reserva para una sola cosa por pantalla**, la accion principal. Si aparece dos veces, una sobra.
- **Archivo** para titulos (grotesca industrial) e **IBM Plex Sans** para datos. Plex sostiene los nombres de cliente, que vienen en MAYUSCULAS desde SAP y son largos.
- Fuentes **autoalojadas** via `@fontsource`, no desde un CDN: el asesor abre esto en la calle con mala senal.
- Listas densas con filetes en vez de tarjetas con sombra: en un celular buscando entre cien clientes, la tarjeta desperdicia altura.

## Certificados TLS en la maquina de desarrollo

PHP instalado desde el zip de windows.php.net **no trae paquete de certificados raiz**, y sin el toda llamada HTTPS saliente falla con `cURL error 60: unable to get local issuer certificate`. Se nota al intentar entrar: Microsoft autentica bien, pero el canje del codigo por el token revienta.

Ya quedo resuelto en esta maquina (`C:/Users/Claude/php83/cacert.pem`, referenciado desde `php.ini` en `curl.cainfo` y `openssl.cafile`). Si se monta el proyecto en otro equipo Windows con PHP del zip, hay que repetirlo:

```bash
curl -o C:/ruta/php/cacert.pem https://curl.se/ca/cacert.pem
```

y en `php.ini`:

```ini
curl.cainfo = "C:/ruta/php/cacert.pem"
openssl.cafile = "C:/ruta/php/cacert.pem"
```

En GoDaddy no aplica: el PHP de cPanel viene con su paquete de certificados del sistema. Si aun asi apareciera el mismo error al desplegar, es esto y no un problema de la aplicacion.

## Acceso de desarrollo

Existe `/dev/entrar/{correo}` para entrar sin pasar por Microsoft mientras se construyen pantallas. Es un salto de autenticacion y lleva tres candados: `APP_ENV=local`, `APP_DEBUG=true` y `DEV_LOGIN=true`. La tercera **no existe en `.env.example`**, asi que en el servidor la ruta ni siquiera se registra.

**Antes de cada despliegue, confirmar que no aparece:**

```bash
php artisan route:list --path=dev
```

## Pendientes en el portal de Entra ID

El registro de aplicacion **PedidosSap** ya existe (`a3e52e84-...`) con `User.Read` y `Mail.Send` consentidos. Faltan tres cosas, ninguna de codigo:

1. **Registrar las URI de redireccion** en Authentication del registro:
   - `http://localhost:8000/auth/callback` para probar
   - `https://pedidos.segurex.com/auth/callback` para produccion

   Sin esto Microsoft rechaza el ingreso con `AADSTS50011`.

2. **Limitar `Mail.Send` a un solo buzon.** En el portal aparece como *"Send mail as any user"*, y eso es literal: hoy la aplicacion podria enviar correo haciendose pasar por cualquier buzon del tenant. Se acota con una ApplicationAccessPolicy de Exchange (comando en `docs/ENTRA_ID.md`).

3. **Rotar el client secret** antes de produccion: el actual viajo por un chat en texto plano.

Conviene tambien confirmar que el registro sea de **un solo inquilino** y anotar la fecha de vencimiento del secret: el dia que vence, nadie entra.

## Datos que faltan de SEGUREX

- Version de PHP disponible en el plan de GoDaddy (debe ser 8.3 o superior).
