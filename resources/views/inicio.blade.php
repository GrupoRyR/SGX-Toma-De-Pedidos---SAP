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
            margin: 0; font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
            background: #F7F7F8; color: var(--gris);
        }
        header {
            background: var(--gris); color: #fff; padding: 16px 24px;
            display: flex; align-items: center; justify-content: space-between; gap: 16px;
        }
        header strong { font-size: 17px; }
        .marca { color: var(--naranja); }
        header a { color: #ccc; font-size: 14px; text-decoration: none; }
        main { max-width: 860px; margin: 0 auto; padding: 32px 24px; }
        .caja { background: #fff; border-radius: 12px; padding: 24px; margin-bottom: 16px; }
        .etiqueta { color: #888; font-size: 13px; }
        h2 { margin: 0 0 16px; font-size: 18px; }
    </style>
</head>
<body>
    <header>
        <strong>Pedidos <span class="marca">SEGUREX</span></strong>
        <div>
            {{ auth()->user()->nombre }}
            &middot;
            <a href="{{ route('salir') }}">Salir</a>
        </div>
    </header>

    <main>
        <div class="caja">
            <h2>Sesion iniciada</h2>
            <p class="etiqueta">Correo</p>
            <p>{{ auth()->user()->correo }}</p>
            <p class="etiqueta">Rol</p>
            <p>{{ auth()->user()->rol->value }}</p>
            <p class="etiqueta">Clientes que puedes ver</p>
            <p>{{ $clientes }}</p>
        </div>

        <div class="caja">
            <h2>En construccion</h2>
            <p class="etiqueta">Las pantallas de clientes y pedidos vienen en el siguiente paso.</p>
        </div>
    </main>
</body>
</html>
