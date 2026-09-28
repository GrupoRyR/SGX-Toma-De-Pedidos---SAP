<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pedidos SEGUREX</title>
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
            padding: 40px 32px; width: 100%; max-width: 380px; text-align: center;
            box-shadow: 0 20px 60px rgba(0,0,0,.35);
        }
        h1 { margin: 0 0 4px; font-size: 26px; letter-spacing: -.02em; }
        .marca { color: var(--naranja); }
        p { margin: 0 0 28px; color: #666; font-size: 15px; line-height: 1.5; }
        .boton {
            display: flex; align-items: center; justify-content: center; gap: 10px;
            width: 100%; padding: 16px; border-radius: 10px; border: 0;
            background: var(--naranja); color: #fff; font-size: 16px; font-weight: 600;
            text-decoration: none; cursor: pointer;
        }
        .boton:hover { background: #d94a0b; }
        .aviso {
            background: #FFF4E5; color: #7A4100; border-radius: 8px;
            padding: 12px; margin-bottom: 20px; font-size: 14px;
            text-align: left; line-height: 1.5; word-break: break-word;
        }
    </style>
</head>
<body>
    <main class="tarjeta">
        <h1>Pedidos <span class="marca">SEGUREX</span></h1>
        <p>Entra con tu cuenta de correo de la empresa.</p>

        @if (session('aviso'))
            <div class="aviso">{{ session('aviso') }}</div>
        @endif

        <a class="boton" href="{{ route('auth.redirigir') }}">
            Entrar con Microsoft
        </a>
    </main>
</body>
</html>
