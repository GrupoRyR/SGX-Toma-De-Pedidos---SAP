{{--
    Correo de rechazo. Es la única notificación en alcance.

    Se escribe en HTML plano y con estilos en línea: los clientes de correo no
    respetan hojas de estilo, y Outlook menos. Nada de imágenes remotas, que
    Outlook bloquea por defecto y dejarían el correo con huecos.

    Lo que el asesor necesita para actuar va arriba: qué pedido y por qué. El
    resto es contexto.
--}}
<!DOCTYPE html>
<html lang="es">
<head><meta charset="utf-8"></head>
<body style="margin:0;padding:24px;background:#e8e9eb;font-family:'Segoe UI',Arial,sans-serif;color:#23272b;">
    <table role="presentation" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:8px;">
        <tr>
            <td style="padding:24px;">
                <p style="margin:0 0 4px;font-size:14px;color:#7d868f;">Pedidos SEGUREX</p>

                <h1 style="margin:0 0 16px;font-size:20px;font-weight:600;">
                    Te devolvieron el pedido #{{ $pedido->id }}
                </h1>

                <p style="margin:0 0 6px;font-size:14px;color:#7d868f;">Motivo</p>
                <p style="margin:0 0 20px;padding:12px 14px;background:#fef2f2;border-radius:6px;font-size:15px;line-height:1.5;color:#991b1b;">
                    {{ $pedido->motivo_rechazo }}
                </p>

                <table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;font-size:14px;">
                    <tr>
                        <td style="padding:4px 0;color:#7d868f;">Cliente</td>
                        <td style="padding:4px 0;text-align:right;">{{ $pedido->nombre_cliente }}</td>
                    </tr>
                    <tr>
                        <td style="padding:4px 0;color:#7d868f;">Total</td>
                        <td style="padding:4px 0;text-align:right;">$ {{ number_format($pedido->total, 0, ',', '.') }}</td>
                    </tr>
                    <tr>
                        <td style="padding:4px 0;color:#7d868f;">Lo revisó</td>
                        <td style="padding:4px 0;text-align:right;">{{ $quienRechazo }}</td>
                    </tr>
                </table>

                <p style="margin:20px 0 0;">
                    <a href="{{ $enlace }}"
                       style="display:inline-block;padding:12px 20px;background:#f65810;color:#ffffff;
                              text-decoration:none;border-radius:6px;font-weight:600;font-size:15px;">
                        Corregir el pedido
                    </a>
                </p>

                <p style="margin:20px 0 0;font-size:14px;line-height:1.5;color:#7d868f;">
                    El pedido sigue siendo tuyo: corriges lo que haga falta y lo vuelves a enviar.
                    No hace falta crear uno nuevo.
                </p>
            </td>
        </tr>
    </table>

    <p style="max-width:560px;margin:16px auto 0;font-size:12px;color:#7d868f;text-align:center;">
        Este correo lo envía la aplicación de pedidos. No hace falta responderlo.
    </p>
</body>
</html>
