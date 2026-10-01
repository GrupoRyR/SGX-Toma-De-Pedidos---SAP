# Despliegue en GoDaddy

Para `pedidos.segurex.com`, en cPanel compartido con SSH y **sin Node.js**.

Los pasos que piden contraseñas los haces tú. Yo no entro credenciales de
GoDaddy, cPanel ni de la base de datos.

> Después de cada paso, pégame lo que devuelva la terminal y lo reviso antes de
> seguir. Es más rápido que descubrir el problema tres pasos después.

---

## 0. Antes de empezar: lo que hay que confirmar

| Qué | Dónde | Por qué importa |
|---|---|---|
| **PHP 8.3 o superior** | cPanel → MultiPHP Manager | Laravel 13 lo exige. Con 8.2 la aplicación no arranca. |
| Extensiones `pdo_mysql`, `mbstring`, `openssl`, `curl`, `zip`, `xmlreader`, `dom`, `gd`, `bcmath`, `fileinfo` | cPanel → Select PHP Version → Extensions | Sin `curl` no hay ingreso con Microsoft. Sin `gd` no se generan los íconos. Sin `zip` y `xmlreader` no se leen los Excel de la carga de maestros. |
| **SSH habilitado** | cPanel → SSH Access | Sin SSH, composer y las migraciones se vuelven muy incómodas. |
| **Certificado SSL** en el subdominio | cPanel → SSL/TLS Status | Entra ID no acepta `http://` en producción, y el service worker necesita HTTPS. |

Si PHP 8.3 no aparece en el plan, **para aquí**: eso se resuelve con GoDaddy
antes de subir nada.

---

## 1. Crear el subdominio

cPanel → **Domains** → *Create A New Domain*:

- **Domain:** `pedidos.segurex.com`
- **Document Root:** `/home/USUARIO/pedidos_app/public`

> **Esto es lo más importante de todo el despliegue.** La raíz del sitio apunta
> a `public/`, no a la carpeta del proyecto. Si apunta a `pedidos_app/`, queda
> expuesto a internet el archivo `.env` con la contraseña de la base de datos y
> el secreto de Entra ID — y cualquiera puede descargarlo.
>
> Si cPanel no deja escribir esa ruta porque la carpeta no existe todavía,
> créala primero desde el Administrador de archivos y vuelve.

Después, en **SSL/TLS Status**, ejecuta *Run AutoSSL* sobre el subdominio y
espera a que quede en verde.

---

## 2. Crear la base de datos

cPanel → **MySQL Databases**:

1. *Create New Database*: `pedidos` → queda como `USUARIO_pedidos`.
2. *Add New User*: `pedidos` → queda como `USUARIO_pedidos`. **Usa el generador
   de contraseñas y guárdala en el gestor de contraseñas**, no en un papel ni
   en un chat.
3. *Add User To Database* → **ALL PRIVILEGES**.

Anota los tres valores tal como los muestra cPanel, **con el prefijo**. Van en
el `.env` del paso 4.

---

## 3. Subir y descomprimir

El paquete `pedidos-segurex.zip` ya trae las dependencias de PHP instaladas
(`vendor/`) y los estilos compilados (`public/build/`). **No hace falta
composer ni Node en el servidor.**

Sube el ZIP por cPanel → Administrador de archivos → `/home/USUARIO/`, o por
SSH:

```bash
scp pedidos-segurex.zip USUARIO@pedidos.segurex.com:~/
```

Y ya por SSH:

```bash
mkdir -p ~/pedidos_app
unzip -q ~/pedidos-segurex.zip -d ~/pedidos_app
rm ~/pedidos-segurex.zip
cd ~/pedidos_app
ls
```

Tienen que aparecer `app`, `bootstrap`, `config`, `public`, `vendor` y `artisan`.

---

## 4. Configurar el entorno

```bash
cd ~/pedidos_app
cp .env.produccion.example .env
nano .env
```

Llena:

- `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` con lo del paso 2.
- `AZURE_CLIENT_SECRET` con el **secreto nuevo**, después de rotarlo en el
  portal de Entra ID. El anterior viajó por un chat en texto plano.

Deja `APP_KEY` vacía; se genera sola en el paso siguiente.

Guarda con `Ctrl+O`, `Enter`, `Ctrl+X`.

```bash
chmod 600 .env
```

---

## 5. Poner la aplicación en marcha

```bash
cd ~/pedidos_app

# Si el comando `php` del servidor no es 8.3, usa la ruta completa que
# muestra cPanel, por ejemplo /opt/cpanel/ea-php83/root/usr/bin/php
php -v

php artisan key:generate --force
php artisan storage:link
php artisan migrate --force --seed
```

`migrate --seed` crea las tablas, los 22 usuarios con sus roles, los canales y
la configuración inicial. **Los pedidos arrancan desde cero**: el historial se
queda en SharePoint, por decisión de SEGUREX.

Permisos de escritura:

```bash
chmod -R 775 storage bootstrap/cache
```

Y el caché de producción, que es lo que hace que la aplicación abra rápido:

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

> Cada vez que cambies el `.env` hay que volver a correr `php artisan
> config:cache`, o el cambio no tiene efecto. Es la causa más común de "cambié
> la contraseña y sigue fallando".

---

## 6. La verificación que no se puede saltar

```bash
php artisan route:list --path=dev
```

**Tiene que decir que no encontró rutas.** Si aparece `dev/entrar/{correo}`,
hay un salto de autenticación abierto en internet: cualquiera entraría como
cualquier usuario. Revisa que en el `.env` no exista `DEV_LOGIN` y que
`APP_ENV=production`.

Después, desde el navegador:

```
https://pedidos.segurex.com
```

Debe salir la pantalla de entrada. Y esta, que confirma que el paso 1 quedó bien:

```
https://pedidos.segurex.com/.env
```

**Tiene que dar 404.** Si descarga un archivo, la raíz del sitio está mal
apuntada: vuelve al paso 1 antes de seguir, porque en ese archivo está la
contraseña de la base de datos.

---

## 7. Cargar los maestros

Sube `CLIENTES_SAP.csv` y `LISTA_PRECIOS.csv` a `~/pedidos_app/storage/importaciones/`
y corre primero en simulación:

```bash
cd ~/pedidos_app
mkdir -p storage/importaciones
php artisan pedidos:importar-clientes storage/importaciones/CLIENTES_SAP.csv --simular
```

Si el resumen se ve bien (641 clientes), repite sin `--simular`. Igual con los
precios (216 productos). Y al final, las carteras:

```bash
php artisan db:seed --class=AsignacionesSeeder
php artisan pedidos:revisar-datos
```

`revisar-datos` avisa de clientes sin canal, carteras sin dueño y demás. Es
normal que reporte la cartera `85 LILIAM HAIDEIDI HERRERA RAMIREZ`, que tiene
un solo cliente y no está asignada a nadie.

Esto es solo para la carga inicial. Después, los clientes y productos nuevos se
crean desde **Administración → Clientes / Productos / Carteras** (uno por uno) o
**Administración → Cargar archivo** (Excel o CSV, con vista previa y selección
de filas). Nada de eso necesita terminal.

---

## 8. La tarea programada

cPanel → **Cron Jobs** → *Add New Cron Job*, cada minuto (`* * * * *`):

```
cd /home/USUARIO/pedidos_app && php artisan schedule:run >> /dev/null 2>&1
```

Es lo que hace caducar los bloqueos de edición vencidos y los permisos
temporales. Sin esto la aplicación funciona, pero un pedido que alguien dejó
abierto tarda más en soltarse.

---

## 9. Primer ingreso

Entra tú primero, con tu cuenta de la empresa. Si Microsoft rechaza el ingreso:

| Error | Qué pasó |
|---|---|
| `AADSTS50011` | La URI `https://pedidos.segurex.com/auth/callback` no está registrada en el portal de Entra ID. |
| `AADSTS7000215` | El `AZURE_CLIENT_SECRET` está mal, o venció. Ojo con pegar el **Id.** del secreto en vez del **Valor**. |
| "No pudimos validar tu cuenta" | Mira `storage/logs/laravel.log`. Con `APP_DEBUG=false` la pantalla no da detalles a propósito. |
| "Tu cuenta no tiene acceso" | El correo no está en la tabla de usuarios, o está inactivo. Se arregla desde Administración → Usuarios, con otra cuenta de TI. |

Después revisa, ya dentro:

- **Mis clientes** carga los 641.
- **Administración → Usuarios** muestra los 22 con sus roles, y `Libera a SAP`
  solo en César y Marly.
- **Administración → Plantillas SAP** está vacía, que es lo correcto: todavía
  no hay pedidos aprobados.

---

## 10. Antes de contarle al equipo

- [ ] `https://pedidos.segurex.com/.env` da 404.
- [ ] `php artisan route:list --path=dev` no devuelve nada.
- [ ] El secreto de Entra ID quedó **rotado**, y su fecha de vencimiento anotada.
- [ ] `Mail.Send` está acotado a un solo buzón con una `ApplicationAccessPolicy`
      de Exchange. Sin eso, la aplicación puede enviar correo haciéndose pasar
      por cualquier buzón del tenant, incluido el de gerencia. El comando está
      en [`ENTRA_ID.md`](ENTRA_ID.md).
- [ ] El buzón remitente quedó puesto en Administración → Configuración.
- [ ] Rechazaste un pedido de prueba y el correo llegó de verdad.
- [ ] Un asesor de prueba hizo un pedido completo y lo aprobaste.
- [ ] Descargaste las plantillas y DTW las aceptó tal cual.
- [ ] Hay respaldo automático de la base de datos (cPanel → Backup).

Y deja la app de Power Apps en **solo lectura** un tiempo, para que nadie siga
creando pedidos por allá.

---

## Cómo actualizar después

```bash
cd ~/pedidos_app
php artisan down                 # muestra "volvemos enseguida"
# subir y descomprimir el ZIP nuevo encima
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan up
```

**Si la actualización trae paquetes nuevos de Composer** (`composer.lock`
cambió, como al agregar `openspout/openspout` para leer Excel), hay que subir
`vendor/` completo, no solo los archivos de la aplicación: el autoload de
Composer cambia junto con el paquete.

El caché del service worker se invalida solo: la página lo registra con el hash
del build, así que un despliegue nuevo hace que los celulares se actualicen sin
que nadie tenga que borrar nada.

---

## Si algo sale mal

**Página en blanco o error 500.** Mira el final del log:

```bash
tail -50 ~/pedidos_app/storage/logs/laravel.log
```

Casi siempre es una de tres: permisos de `storage/`, la base de datos mal
configurada, o un `config:cache` viejo. En ese último caso:

```bash
php artisan config:clear && php artisan config:cache
```

**"The stream or file could not be opened".** Permisos:

```bash
chmod -R 775 ~/pedidos_app/storage ~/pedidos_app/bootstrap/cache
```

**Los estilos no cargan.** Revisa que `public/build/manifest.json` haya subido.
Si falta, hay que volver a generar el paquete: en el servidor no hay Node para
compilarlos.

**`cURL error 60`.** El PHP del servidor no encuentra los certificados raíz. En
cPanel no debería pasar; si pasa, es del hosting y no de la aplicación.

**Volver atrás.** Guarda el ZIP anterior. Restaurar es descomprimirlo encima y
correr `config:cache`. Si una migración ya corrió, `migrate:rollback` deshace la
última tanda — pero si ya hay pedidos reales, avísame antes de tocar eso.
