<?php

namespace Tests\Feature;

use App\Models\Bitacora;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as UsuarioSocialite;
use Mockery;
use Tests\TestCase;

/**
 * Entrada a la aplicacion.
 *
 * Microsoft dice quien es la persona; la tabla `usuarios` dice si tiene lugar
 * aqui y con que rol. Estas pruebas cubren la segunda parte, que es la nuestra.
 */
class AutenticacionTest extends TestCase
{
    use RefreshDatabase;

    private function respuestaDeMicrosoft(string $correo, string $nombre = 'Persona de prueba'): void
    {
        $cuenta = new UsuarioSocialite;
        $cuenta->map([
            'id' => 'objeto-entra-'.md5($correo),
            'name' => $nombre,
            'email' => $correo,
        ]);

        $proveedor = Mockery::mock('Laravel\Socialite\Contracts\Provider');
        $proveedor->shouldReceive('user')->andReturn($cuenta);

        Socialite::shouldReceive('driver')->with('azure')->andReturn($proveedor);
    }

    public function test_un_correo_registrado_y_activo_entra(): void
    {
        $usuario = Usuario::create([
            'correo' => 'maria.uribe@segurex.com', 'nombre' => 'Maria Uribe',
            'rol' => 'ASESOR', 'activo' => true,
        ]);

        $this->respuestaDeMicrosoft('maria.uribe@segurex.com');

        $this->get(route('auth.callback'))->assertRedirect(route('inicio'));
        $this->assertAuthenticatedAs($usuario);
    }

    public function test_guarda_el_ultimo_acceso_y_el_id_de_entra(): void
    {
        $usuario = Usuario::create([
            'correo' => 'maria.uribe@segurex.com', 'nombre' => 'Maria Uribe',
            'rol' => 'ASESOR', 'activo' => true,
        ]);

        $this->respuestaDeMicrosoft('maria.uribe@segurex.com');
        $this->get(route('auth.callback'));

        $usuario->refresh();
        $this->assertNotNull($usuario->ultimo_acceso);
        $this->assertNotNull($usuario->entra_object_id);
    }

    public function test_un_correo_que_no_esta_en_la_tabla_no_entra(): void
    {
        // Tener cuenta de Microsoft de SEGUREX no basta: el acceso se otorga
        // desde administracion, una persona a la vez.
        $this->respuestaDeMicrosoft('alguien.nuevo@segurex.com');

        $this->get(route('auth.callback'))->assertRedirect(route('sin-acceso'));
        $this->assertGuest();
    }

    public function test_un_usuario_inactivo_no_entra(): void
    {
        Usuario::create([
            'correo' => 'exempleado@segurex.com', 'nombre' => 'Ex empleado',
            'rol' => 'ASESOR', 'activo' => false,
        ]);

        $this->respuestaDeMicrosoft('exempleado@segurex.com');

        $this->get(route('auth.callback'))->assertRedirect(route('sin-acceso'));
        $this->assertGuest();
    }

    public function test_un_correo_de_otro_dominio_no_entra_aunque_este_en_la_tabla(): void
    {
        // Doble barrera: aunque alguien invite una cuenta externa al tenant y
        // aunque por error quede una fila en `usuarios`, el dominio la frena.
        Usuario::create([
            'correo' => 'externo@otraempresa.com', 'nombre' => 'Externo',
            'rol' => 'ADMIN_VENTAS', 'activo' => true,
        ]);

        $this->respuestaDeMicrosoft('externo@otraempresa.com');

        $this->get(route('auth.callback'))->assertRedirect(route('sin-acceso'));
        $this->assertGuest();
    }

    public function test_el_correo_entra_en_mayusculas_y_se_reconoce_igual(): void
    {
        $usuario = Usuario::create([
            'correo' => 'maria.uribe@segurex.com', 'nombre' => 'Maria Uribe',
            'rol' => 'ASESOR', 'activo' => true,
        ]);

        $this->respuestaDeMicrosoft('Maria.Uribe@SEGUREX.com');

        $this->get(route('auth.callback'));
        $this->assertAuthenticatedAs($usuario);
    }

    public function test_el_ingreso_rechazado_queda_en_bitacora(): void
    {
        $this->respuestaDeMicrosoft('alguien.nuevo@segurex.com');
        $this->get(route('auth.callback'));

        $registro = Bitacora::where('accion', 'INGRESO_RECHAZADO')->first();

        $this->assertNotNull($registro, 'Un intento de ingreso rechazado tiene que quedar registrado');
        $this->assertSame('alguien.nuevo@segurex.com', $registro->detalle['correo']);
    }

    public function test_sin_sesion_no_se_llega_al_inicio(): void
    {
        $this->get(route('inicio'))->assertRedirect('/entrar');
    }

    public function test_desactivar_a_alguien_corta_su_sesion_en_curso(): void
    {
        // Sin esto, desactivar a una persona no surte efecto hasta que cierre
        // sesion, y una sesion recordada puede durar semanas.
        $usuario = Usuario::create([
            'correo' => 'maria.uribe@segurex.com', 'nombre' => 'Maria Uribe',
            'rol' => 'ASESOR', 'activo' => true,
        ]);

        // Se prueba contra una pantalla real: la raiz solo redirige a clientes.
        $this->actingAs($usuario)->get(route('clientes'))->assertOk();

        $usuario->update(['activo' => false]);

        $this->actingAs($usuario)->get(route('clientes'))->assertRedirect(route('sin-acceso'));
    }

    public function test_salir_cierra_la_sesion(): void
    {
        $usuario = Usuario::create([
            'correo' => 'maria.uribe@segurex.com', 'nombre' => 'Maria Uribe',
            'rol' => 'ASESOR', 'activo' => true,
        ]);

        $this->actingAs($usuario)->get(route('salir'))->assertRedirect(route('entrar'));
        $this->assertGuest();
    }

    public function test_la_pantalla_de_entrada_es_publica(): void
    {
        $this->get(route('entrar'))->assertOk()->assertSee('Entrar con Microsoft');
    }
}
