<?php

namespace App\Services;

use App\Models\Configuracion;
use App\Models\Notificacion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Envio de correo por Microsoft Graph.
 *
 * No se usa SMTP a proposito: el hosting compartido de GoDaddy suele bloquear
 * los puertos de correo saliente (25, 465, 587). Graph viaja por HTTPS normal,
 * que nunca esta bloqueado.
 *
 * El correo sale de un buzon de servicio, no del buzon de quien rechaza. Ese
 * buzon es el unico que el registro de Entra puede usar: `Mail.Send` se acota
 * con una ApplicationAccessPolicy de Exchange, porque tal como se otorga
 * permite enviar haciendose pasar por cualquier buzon del tenant.
 *
 * Igual que la bitacora, enviar nunca puede tumbar la operacion: si el correo
 * falla, el rechazo ya ocurrio y el pedido ya cambio de estado. El fallo queda
 * anotado en `notificaciones` para poder responder "a mi nunca me llego".
 */
class CorreoGraph
{
    public function __construct(private Bitacorero $bitacora) {}

    /** Si hay con que enviar. Sin remitente configurado no se intenta nada. */
    public function configurado(): bool
    {
        return $this->remitente() !== ''
            && (string) config('services.azure.client_id') !== ''
            && (string) config('services.azure.client_secret') !== '';
    }

    private function remitente(): string
    {
        return trim((string) Configuracion::valor('correo_remitente', ''));
    }

    /**
     * Manda un correo y deja constancia del intento.
     *
     * Devuelve true o false, nunca lanza: quien llama esta terminando una
     * operacion que ya se completo.
     */
    public function enviar(string $para, string $asunto, string $cuerpoHtml, string $evento, ?int $pedidoId = null): bool
    {
        $registro = Notificacion::create([
            'evento' => $evento,
            'canal' => 'correo',
            'destinatario' => $para,
            'pedido_id' => $pedidoId,
            'resultado' => 'PENDIENTE',
        ]);

        if (! $this->configurado()) {
            $registro->update([
                'resultado' => 'ERROR',
                'error' => 'Falta configurar el buzon remitente o las credenciales de Entra ID.',
            ]);

            return false;
        }

        try {
            $respuesta = Http::withToken($this->token())
                ->timeout(15)
                ->post("https://graph.microsoft.com/v1.0/users/{$this->remitente()}/sendMail", [
                    'message' => [
                        'subject' => $asunto,
                        'body' => ['contentType' => 'HTML', 'content' => $cuerpoHtml],
                        'toRecipients' => [['emailAddress' => ['address' => $para]]],
                    ],
                    'saveToSentItems' => true,
                ]);

            if ($respuesta->failed()) {
                throw new RuntimeException('Graph respondio '.$respuesta->status().': '.$respuesta->body());
            }

            $registro->update(['resultado' => 'ENVIADO', 'enviado_en' => now()]);

            return true;
        } catch (\Throwable $e) {
            $registro->update([
                'resultado' => 'ERROR',
                'error' => substr($e->getMessage(), 0, 2000),
            ]);

            Log::error('No se pudo enviar correo por Graph', [
                'evento' => $evento,
                'destinatario' => $para,
                'excepcion' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Token de aplicacion.
     *
     * Se guarda en cache cinco minutos menos de lo que dura, para no pedir uno
     * nuevo en cada correo ni usar uno que acaba de vencer.
     */
    private function token(): string
    {
        return Cache::remember('graph:token', 3300, function () {
            $inquilino = config('services.azure.tenant');

            $respuesta = Http::asForm()
                ->timeout(15)
                ->post("https://login.microsoftonline.com/{$inquilino}/oauth2/v2.0/token", [
                    'client_id' => config('services.azure.client_id'),
                    'client_secret' => config('services.azure.client_secret'),
                    'scope' => 'https://graph.microsoft.com/.default',
                    'grant_type' => 'client_credentials',
                ]);

            if ($respuesta->failed()) {
                throw new RuntimeException('No se pudo obtener el token de Graph: '.$respuesta->body());
            }

            return (string) $respuesta->json('access_token');
        });
    }
}
