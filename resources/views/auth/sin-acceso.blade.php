<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sin acceso | Pedidos SEGUREX</title>
    <style>
        :root { --naranja: #F65810; --gris: #2B2B2B; }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; display: grid; place-items: center;
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
            background: var(--gris); color: #fff; padding: 24px;
        }
        .tarjeta {
            background: #fff; color: var(--gris); border-radius: 16px;
            padding: 40px 32px; width: 100%; max-width: 420px; text-align: center;
            box-shadow: 0 20px 60px rgba(0,0,0,.35);
        }
        h1 { margin: 0 0 12px; font-size: 22px; }
        p { margin: 0 0 16px; color: #666; font-size: 15px; line-height: 1.6; }
        code { background: #F3F3F3; padding: 3px 8px; border-radius: 5px; font-size: 14px; }
        a { color: var(--naranja); }
    </style>
</head>
<body>
    <main class="tarjeta">
        <h1>Tu cuenta no tiene acceso</h1>

        @if ($correo)
            <p>Entraste como <code>{{ $correo }}</code>, pero esa cuenta no esta habilitada en la aplicacion de pedidos.</p>
        @else
            <p>Esa cuenta no esta habilitada en la aplicacion de pedidos.</p>
        @endif

        <p>Pide que te habiliten al area de TI o al administrador de ventas.</p>
        <p><a href="{{ route('entrar') }}">Entrar con otra cuenta</a></p>
    </main>
</body>
</html>
