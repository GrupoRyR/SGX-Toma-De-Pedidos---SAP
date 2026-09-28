<?php

namespace App\Providers;

use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\Usuario;
use App\Policies\ClientePolicy;
use App\Policies\PedidoPolicy;
use App\Policies\UsuarioPolicy;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Azure\AzureExtendSocialite;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        /*
         * En el servidor, toda URL que genere la aplicacion es https.
         *
         * Importa mas de lo que parece: la cookie de sesion va marcada como
         * segura, asi que un enlace http no la manda y el usuario aparece
         * deslogueado sin ninguna explicacion. Pasa con el enlace del correo de
         * rechazo y con el regreso de Microsoft.
         */
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Socialite no trae el proveedor de Entra ID de fabrica; se engancha aqui.
        Event::listen(SocialiteWasCalled::class, [AzureExtendSocialite::class, 'handle']);

        // Las policies usan nombres en espanol, asi que se registran a mano en
        // vez de dejarlo al descubrimiento por convencion.
        Gate::policy(Cliente::class, ClientePolicy::class);
        Gate::policy(Pedido::class, PedidoPolicy::class);
        Gate::policy(Usuario::class, UsuarioPolicy::class);
    }
}
