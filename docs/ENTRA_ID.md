# App registration de Entra ID — para qué es y cómo crearlo

## Qué es, en una frase

Es un registro dentro del Microsoft 365 de SEGUREX que le dice a Microsoft *"esta web de pedidos existe, es nuestra, y le autorizamos hacer estas cosas"*. Sin él, Microsoft no tiene forma de saber que `pedidos.segurex.com` es una aplicación legítima de la empresa y le negaría todo.

En Power Apps esto nunca hizo falta porque la app vivía **dentro** de Microsoft y heredaba la identidad del usuario automáticamente. Al salirnos a un servidor propio en GoDaddy, hay que declarar la aplicación nosotros.

## Para qué lo vamos a usar

### 1. Que los asesores entren con su cuenta de SEGUREX

El asesor abre `pedidos.segurex.com` y ve un solo botón: **"Entrar con Microsoft"**.

1. Lo presiona y va a la pantalla de Microsoft, la misma de siempre.
2. Microsoft verifica su cuenta `@segurex.com` — con MFA, si SEGUREX lo tiene activo.
3. Microsoft devuelve a la web el correo de esa persona, confirmado.
4. La web busca ese correo en la tabla `usuarios` y de ahí saca el rol: asesor, gerente, admin de ventas o TI.
5. Si el correo no está en la tabla, o está inactivo, ve la pantalla "sin acceso".

**Lo importante de esto:** la web **nunca ve ni guarda contraseñas**. No hay "olvidé mi contraseña", no hay contraseñas débiles, no hay una base de datos de credenciales que robar. Cuando alguien sale de la empresa y TI desactiva su cuenta de Microsoft, queda fuera de la web al instante, sin que nadie tenga que acordarse de hacerlo.

La autenticación se limita **al tenant de segurex.com**: una cuenta Microsoft personal o de otra empresa no puede entrar aunque conozca la dirección.

### 2. Enviar el correo de rechazo

Cuando un aprobador rechaza un pedido, la web le avisa al asesor por correo con el motivo. Ese correo sale de un buzón de servicio de SEGUREX usando **Microsoft Graph**, no por SMTP.

Va por Graph y no por SMTP por una razón práctica: **el hosting compartido de GoDaddy suele bloquear los puertos de correo saliente** (25, 465, 587). Graph viaja por HTTPS normal, que nunca está bloqueado.

## Qué me tienen que entregar

Cuatro datos. Los tres primeros los genera el portal; el cuarto lo definimos nosotros:

| Dato | Qué es | Dónde va |
|---|---|---|
| **Tenant ID** | Identificador del Microsoft 365 de SEGUREX | `.env` |
| **Client ID** | Identificador de esta aplicación. Es público, no es secreto | `.env` |
| **Client secret** | La contraseña de la aplicación. **Esto sí es secreto** | `.env`, nunca en el repositorio |
| **Redirect URI** | `https://pedidos.segurex.com/auth/callback` | Se configura en el portal |

El **client secret tiene fecha de vencimiento** (máximo 24 meses). Cuando vence, la web deja de dejar entrar a nadie de un día para otro. Hay que anotar la fecha y renovarlo antes — lo dejo registrado en la configuración de la app para que avise.

## Permisos a solicitar

### Para el inicio de sesión (permisos delegados)

- `openid`, `profile`, `email` — leer quién es la persona que entra.
- `User.Read` — su nombre y correo.

Son los permisos mínimos. **No pedimos leer correos, ni archivos, ni calendarios.**

### Para enviar correo (permiso de aplicación)

- `Mail.Send`

> ⚠️ **Cuidado con este, es el punto delicado.** `Mail.Send` como permiso de aplicación, tal como se otorga por defecto, permite enviar correo **haciéndose pasar por cualquier buzón del tenant** — incluido el de gerencia. No es lo que queremos.
>
> Hay que limitarlo a un solo buzón con una **ApplicationAccessPolicy** de Exchange. Es un comando de PowerShell que corre TI una vez:
>
> ```powershell
> New-ApplicationAccessPolicy -AppId <client-id> `
>   -PolicyScopeGroupId pedidos@segurex.com `
>   -AccessRight RestrictAccess `
>   -Description "La web de pedidos solo puede enviar desde este buzon"
> ```
>
> Después de eso, la aplicación solo puede enviar desde `pedidos@segurex.com` y desde ningún otro buzón. Si alguien roba el client secret, lo peor que puede hacer es mandar correos desde ese buzón.

Conviene crear un buzón de servicio dedicado (`pedidos@segurex.com`) en vez de usar el de una persona.

## Quién lo crea

Alguien con rol de **Administrador global** o **Desarrollador de aplicaciones** en Entra ID. En SEGUREX eso sería Jhon Castro o Leonardo Herrera.

El permiso `Mail.Send` de aplicación necesita **consentimiento del administrador** — un botón que solo puede presionar un admin global.

## Pasos en el portal

1. Entrar a [entra.microsoft.com](https://entra.microsoft.com) con una cuenta administradora.
2. **Identidad → Aplicaciones → Registros de aplicaciones → Nuevo registro**.
3. Nombre: `Web de Pedidos SEGUREX`.
4. Tipos de cuenta admitidos: **solo cuentas de este directorio organizativo** (un solo inquilino). Esto es lo que impide que entren cuentas de afuera.
5. URI de redirección: tipo **Web**, valor `https://pedidos.segurex.com/auth/callback`.
6. Crear. Copiar el **Id. de aplicación (cliente)** y el **Id. de directorio (inquilino)**.
7. **Certificados y secretos → Nuevo secreto de cliente**. Copiar el **Valor** (no el Id.) — solo se muestra una vez, después ya no se puede volver a ver. Anotar la fecha de vencimiento.
8. **Permisos de API → Agregar permiso → Microsoft Graph**:
   - Delegados: `openid`, `profile`, `email`, `User.Read`.
   - De aplicación: `Mail.Send`.
9. **Conceder consentimiento del administrador**.
10. Correr el comando de `ApplicationAccessPolicy` de arriba.

Para probar en local antes de publicar, agregar también `http://localhost:8000/auth/callback` como segunda URI de redirección, y quitarla cuando salga a producción.

## Qué pasa si SEGUREX no quiere usarlo

La alternativa es usuario y contraseña propios dentro de la web. Se puede hacer, pero significa: guardar contraseñas (aunque sean cifradas), construir recuperación de contraseña, no tener MFA, y que una persona que sale de la empresa conserve el acceso hasta que alguien se acuerde de desactivarla a mano.

Para una aplicación que crea pedidos que terminan en SAP, entrar con la cuenta corporativa es bastante mejor. Pero es una decisión de SEGUREX, no un bloqueo técnico.

## Cómo queda en el `.env`

```
AZURE_CLIENT_ID=...
AZURE_CLIENT_SECRET=...
AZURE_TENANT_ID=...
AZURE_REDIRECT_URI=https://pedidos.segurex.com/auth/callback

GRAPH_BUZON_REMITENTE=pedidos@segurex.com
```

Nada de esto va al repositorio. En el servidor, el `.env` vive fuera de `public_html` para que no sea descargable por web.

## El buzón remitente

El correo de rechazo no sale hasta que la clave `correo_remitente` tenga un buzón. Se pone en la aplicación, en **Administración → Configuración**, no en el `.env`.

Tiene que ser **el mismo buzón** al que apunta la `ApplicationAccessPolicy` de Exchange. Si no coinciden, Graph responde `ErrorAccessDenied` y el rechazo queda sin aviso: el pedido se devuelve igual, y la pantalla le dice a quien rechazó que avise de otra forma.

Para comprobar que quedó bien, rechazar un pedido de prueba y mirar la tabla `notificaciones`: `resultado` dice `ENVIADO` o trae el error textual de Graph.
