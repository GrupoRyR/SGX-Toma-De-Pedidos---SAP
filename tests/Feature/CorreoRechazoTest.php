<?php

namespace Tests\Feature;

use App\Models\AsesorSap;
use App\Models\Canal;
use App\Models\Cliente;
use App\Models\Configuracion;
use App\Models\Notificacion;
use App\Models\Producto;
use App\Models\Usuario;
use App\Services\ServicioPedidos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * El correo de rechazo.
 *
 * Es la unica notificacion en alcance: todas las demas se descartaron. Va por
 * Microsoft Graph y no por SMTP porque el hosting compartido de GoDaddy suele
 * bloquear los puertos de correo saliente.
 *
 * La regla de fondo, la misma que la bitacora: avisar no puede tumbar la
 * operacion. El rechazo ya ocurrio; si el correo falla, se anota y se sigue.
 */
class CorreoRechazoTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $asesor;

    private Usuario $gerente;

    private Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();

        Configuracion::create(['clave' => 'iva_porcentaje', 'valor' => '19', 'tipo' => 'numero']);

        $canal = Canal::create(['nombre' => 'Distribucion']);
        $cartera = AsesorSap::create(['codigo_texto' => '14 MONICA RIVERA AREVALO']);

        $this->cliente = Cliente::create([
            'codigo_sn' => 'CN0507', 'nombre' => 'RIVERA TUTA JOSE OSVALDO',
            'canal_id' => $canal->id, 'asesor_sap_id' => $cartera->id, 'porcentaje_descuento' => 28,
        ]);

        $this->asesor = Usuario::create([
            'correo' => 'adriana.russi@segurex.com', 'nombre' => 'Adriana Russi',
            'rol' => 'ASESOR', 'activo' => true,
        ]);
        $this->asesor->asesores()->attach($cartera->id);

        $this->gerente = Usuario::create([
            'correo' => 'jessika.quintero@segurex.com', 'nombre' => 'Jessika Quintero',
            'rol' => 'GERENTE_CANAL', 'activo' => true,
        ]);
        $this->gerente->canales()->attach($canal->id);
    }

    private function conBuzonConfigurado(): void
    {
        Configuracion::create([
            'clave' => 'correo_remitente', 'valor' => 'pedidos@segurex.com', 'tipo' => 'texto',
        ]);

        config()->set('services.azure.client_id', 'un-id');
        config()->set('services.azure.client_secret', 'un-secreto');
        config()->set('services.azure.tenant', 'un-inquilino');
    }

    private function graphResponde(int $estado = 202): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'token-falso'], 200),
            'graph.microsoft.com/*' => Http::response('', $estado),
        ]);
    }

    private function pedidoPendiente(?Usuario $autor = null)
    {
        $servicio = app(ServicioPedidos::class);
        $producto = Producto::firstOrCreate(
            ['codigo' => 'PTS09040KC'],
            ['descripcion' => 'Cerradura', 'precio_lista' => 64900],
        );

        $autor ??= $this->asesor;
        $pedido = $servicio->crear($this->cliente, $autor);
        $servicio->agregarLinea($pedido, $producto, cantidad: 2);

        return $servicio->enviar($pedido->fresh(), $autor);
    }

    public function test_al_rechazar_le_llega_el_correo_al_asesor(): void
    {
        $this->conBuzonConfigurado();
        $this->graphResponde();

        app(ServicioPedidos::class)->rechazar($this->pedidoPendiente(), $this->gerente, 'Falta la orden de compra');

        Http::assertSent(fn (Request $p) => str_contains($p->url(), 'graph.microsoft.com')
            && $p['message']['toRecipients'][0]['emailAddress']['address'] === 'adriana.russi@segurex.com');
    }

    public function test_el_correo_lleva_el_motivo(): void
    {
        // Sin el motivo, el correo solo genera una llamada telefonica.
        $this->conBuzonConfigurado();
        $this->graphResponde();

        app(ServicioPedidos::class)->rechazar($this->pedidoPendiente(), $this->gerente, 'Falta la orden de compra');

        Http::assertSent(fn (Request $p) => str_contains($p->url(), 'sendMail')
            && str_contains($p['message']['body']['content'], 'Falta la orden de compra'));
    }

    public function test_el_correo_sale_del_buzon_de_servicio(): void
    {
        /*
         * No del buzon de quien rechaza. Es el unico buzon que la politica de
         * Exchange le permite usar al registro de Entra.
         */
        $this->conBuzonConfigurado();
        $this->graphResponde();

        app(ServicioPedidos::class)->rechazar($this->pedidoPendiente(), $this->gerente, 'Motivo');

        Http::assertSent(fn (Request $p) => str_contains($p->url(), 'users/pedidos@segurex.com/sendMail'));
    }

    public function test_el_envio_queda_registrado(): void
    {
        $this->conBuzonConfigurado();
        $this->graphResponde();

        $pedido = app(ServicioPedidos::class)
            ->rechazar($this->pedidoPendiente(), $this->gerente, 'Motivo');

        $this->assertDatabaseHas('notificaciones', [
            'evento' => 'RECHAZO',
            'destinatario' => 'adriana.russi@segurex.com',
            'pedido_id' => $pedido->id,
            'resultado' => 'ENVIADO',
        ]);
    }

    public function test_si_graph_falla_el_rechazo_igual_queda_hecho(): void
    {
        // Es el punto central: el correo es un aviso, no parte de la decision.
        $this->conBuzonConfigurado();
        $this->graphResponde(estado: 403);

        $pedido = app(ServicioPedidos::class)
            ->rechazar($this->pedidoPendiente(), $this->gerente, 'Motivo');

        $this->assertSame('RECHAZADO', $pedido->fresh()->estado->value);
        $this->assertDatabaseHas('notificaciones', ['evento' => 'RECHAZO', 'resultado' => 'ERROR']);
    }

    public function test_sin_buzon_configurado_no_se_intenta_enviar(): void
    {
        // Sin remitente no hay nada que intentar, y queda dicho por que.
        $pedido = app(ServicioPedidos::class)
            ->rechazar($this->pedidoPendiente(), $this->gerente, 'Motivo');

        $this->assertSame('RECHAZADO', $pedido->fresh()->estado->value);

        $aviso = Notificacion::where('evento', 'RECHAZO')->first();

        $this->assertSame('ERROR', $aviso->resultado);
        $this->assertStringContainsString('buzon remitente', $aviso->error);
    }

    public function test_quien_reviso_ve_si_el_aviso_salio(): void
    {
        $this->conBuzonConfigurado();
        $this->graphResponde();

        $pedido = app(ServicioPedidos::class)
            ->rechazar($this->pedidoPendiente(), $this->gerente, 'Falta la orden de compra');

        Livewire::actingAs($this->gerente)
            ->test('pedido', ['pedido' => $pedido->fresh()])
            ->assertSee('Se le avisó a adriana.russi@segurex.com');
    }

    public function test_si_el_aviso_fallo_se_dice_en_pantalla(): void
    {
        // Para que quien rechazo sepa que tiene que avisar de otra forma.
        $this->conBuzonConfigurado();
        $this->graphResponde(estado: 403);

        $pedido = app(ServicioPedidos::class)
            ->rechazar($this->pedidoPendiente(), $this->gerente, 'Motivo');

        Livewire::actingAs($this->gerente)
            ->test('pedido', ['pedido' => $pedido->fresh()])
            ->assertSee('No se pudo avisar por correo');
    }

    public function test_el_asesor_no_ve_el_detalle_del_aviso(): void
    {
        // Es informacion para quien reviso, no para quien recibe el rechazo.
        $this->conBuzonConfigurado();
        $this->graphResponde();

        $pedido = app(ServicioPedidos::class)
            ->rechazar($this->pedidoPendiente(), $this->gerente, 'Motivo');

        Livewire::actingAs($this->asesor)
            ->test('pedido', ['pedido' => $pedido->fresh()])
            ->assertDontSee('Se le avisó a');
    }

    public function test_nadie_se_avisa_a_si_mismo(): void
    {
        /*
         * TI puede aprobar y rechazar sus propios pedidos para hacer pruebas e
         * inducciones. Mandarse un correo a si mismo seria ruido.
         */
        $this->conBuzonConfigurado();
        $this->graphResponde();

        $ti = Usuario::create([
            'correo' => 'leonardo.herrera@segurex.com', 'nombre' => 'Leonardo Herrera',
            'rol' => 'TI', 'activo' => true,
        ]);

        app(ServicioPedidos::class)->rechazar($this->pedidoPendiente($ti), $ti, 'Prueba');

        Http::assertNothingSent();
        $this->assertSame(0, Notificacion::count());
    }
}
