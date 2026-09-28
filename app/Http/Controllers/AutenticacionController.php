<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use App\Services\Bitacorero;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;

/**
 * Entrada y salida de la aplicacion.
 *
 * No hay contrasenas: la identidad la da Microsoft Entra ID y el correo es la
 * llave. Lo que decide el acceso y el rol es la tabla `usuarios`, nunca una
 * lista de correos dentro del codigo.
 */
class AutenticacionController extends Controller
{
    public function __construct(private readonly Bitacorero $bitacora) {}

    /** Pantalla con el boton de entrar. */
    public function mostrarEntrar(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('inicio');
        }

        return view('auth.entrar');
    }

    /** Manda al usuario a Microsoft. */
    public function redirigir(): RedirectResponse
    {
        return Socialite::driver('azure')
            ->scopes(['openid', 'profile', 'email', 'User.Read'])
            ->redirect();
    }

    /**
     * Vuelta desde Microsoft.
     *
     * Microsoft ya confirmo quien es la persona. Aqui solo se decide si esa
     * persona tiene lugar en la aplicacion.
     */
    public function volver(Request $request): RedirectResponse
    {
        try {
            $cuenta = Socialite::driver('azure')->user();
        } catch (InvalidStateException) {
            // Sesion vencida o alguien reusando un enlace viejo: no es un error
            // que valga la pena mostrarle al usuario, solo se reintenta.
            return redirect()->route('entrar')->with('aviso', 'La sesion expiro. Intenta de nuevo.');
        } catch (\Throwable $e) {
            Log::error('Fallo la autenticacion con Entra ID', ['excepcion' => $e->getMessage()]);

            /*
             * Al usuario final no se le cuenta el detalle tecnico. Pero en
             * desarrollo si: un fallo aqui casi siempre es de configuracion
             * (certificados, secret vencido, URI de redireccion sin registrar)
             * y esconderlo detras de "intenta de nuevo" cuesta un viaje entero
             * de ida y vuelta para descubrir algo que ya estaba en el log.
             */
            $aviso = app()->isLocal() && config('app.debug')
                ? 'No pudimos validar tu cuenta: '.$e->getMessage()
                : 'No pudimos validar tu cuenta. Intenta de nuevo.';

            return redirect()->route('entrar')->with('aviso', $aviso);
        }

        $correo = strtolower(trim((string) ($cuenta->getEmail() ?: $cuenta->getNickname())));

        if ($correo === '') {
            return redirect()->route('sin-acceso')->with('correo', '');
        }

        /*
         * Segunda barrera, ademas de que el registro de la aplicacion sea de un
         * solo inquilino: el correo tiene que ser del dominio de SEGUREX. Si
         * alguna vez se invita a un usuario externo al tenant, no entra aqui.
         */
        $dominio = config('auth.dominio_permitido');

        if ($dominio && ! str_ends_with($correo, '@'.$dominio)) {
            Log::warning('Intento de ingreso con dominio ajeno', ['correo' => $correo]);

            return redirect()->route('sin-acceso')->with('correo', $correo);
        }

        $usuario = Usuario::where('correo', $correo)->first();

        // Sin fila en `usuarios`, o inactivo, no entra. Es deliberado: el acceso
        // se otorga desde administracion, no por tener cuenta de Microsoft.
        if (! $usuario || ! $usuario->activo) {
            $this->bitacora->registrar('INGRESO_RECHAZADO', 'usuario', $usuario?->id, [
                'correo' => $correo,
                'motivo' => $usuario ? 'usuario inactivo' : 'correo no registrado',
            ]);

            return redirect()->route('sin-acceso')->with('correo', $correo);
        }

        // El object id de Entra es estable aunque cambie el correo; guardarlo
        // permite reconocer a la persona si Recursos Humanos le cambia el alias.
        $usuario->forceFill([
            'entra_object_id' => $cuenta->getId(),
            'ultimo_acceso' => now(),
        ])->save();

        Auth::login($usuario, remember: true);
        $request->session()->regenerate();

        $this->bitacora->registrar('INGRESO', 'usuario', $usuario->id, ['correo' => $correo]);

        return redirect()->intended(route('inicio'));
    }

    public function sinAcceso(): View
    {
        return view('auth.sin-acceso', ['correo' => session('correo')]);
    }

    public function salir(Request $request): RedirectResponse
    {
        $usuario = Auth::user();

        if ($usuario) {
            $this->bitacora->registrar('SALIDA', 'usuario', $usuario->id, ['correo' => $usuario->correo]);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('entrar')->with('aviso', 'Cerraste sesion.');
    }
}
