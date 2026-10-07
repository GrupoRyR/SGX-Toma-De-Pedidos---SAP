<?php

namespace Tests\Feature;

use App\Enums\EstadoPedido;
use App\Models\AsesorSap;
use App\Models\Canal;
use App\Models\Cliente;
use App\Models\Configuracion;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\Usuario;
use App\Models\UsuarioPermiso;
use App\Services\ServicioPedidos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Bandeja de aprobacion y bandeja de liberacion a SAP.
 *
 * Lo que mas importa: que cada quien vea solo lo suyo, y que la bandeja de
 * liberacion sea inalcanzable para quien no tiene el permiso.
 */
class BandejasTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $asesor;

    private Usuario $gerente;

    private Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();

        Configuracion::create(['clave' => 'iva_porcentaje', 'valor' => '19', 'tipo' => 'numero']);
        Configuracion::create(['clave' => 'minutos_bloqueo', 'valor' => '30', 'tipo' => 'numero']);

        $canal = Canal::create(['nombre' => 'Distribucion']);
        $cartera = AsesorSap::create(['codigo_texto' => '14 MONICA RIVERA AREVALO']);

        $this->cliente = Cliente::create([
            'codigo_sn' => 'CN0507', 'nombre' => 'RIVERA TUTA JOSE OSVALDO',
            'canal_id' => $canal->id, 'asesor_sap_id' => $cartera->id, 'porcentaje_descuento' => 28,
        ]);

        $this->asesor = $this->usuario('asesor@segurex.com', 'ASESOR');
        $this->asesor->asesores()->attach($cartera->id);

        $this->gerente = $this->usuario('jessika.quintero@segurex.com', 'GERENTE_CANAL');
        $this->gerente->canales()->attach($canal->id);
    }

    private function usuario(string $correo, string $rol): Usuario
    {
        return Usuario::create([
            'correo' => $correo, 'nombre' => 'Prueba '.$rol, 'rol' => $rol, 'activo' => true,
        ]);
    }

    private function pedidoPendiente(?Usuario $autor = null): Pedido
    {
        $autor ??= $this->asesor;
        $servicio = app(ServicioPedidos::class);
        $producto = Producto::firstOrCreate(
            ['codigo' => 'PTS09040KC'],
            ['descripcion' => 'SEGUREX A-80PD CERRADURA ENTRADA SATURNO', 'precio_lista' => 64900],
        );

        $pedido = $servicio->crear($this->cliente, $autor);
        $servicio->agregarLinea($pedido, $producto, cantidad: 2);

        return $servicio->enviar($pedido->fresh(), $autor);
    }

    // ---------- Bandeja de aprobacion ----------

    public function test_el_gerente_ve_los_pendientes_de_su_canal(): void
    {
        $this->pedidoPendiente();

        Livewire::actingAs($this->gerente)
            ->test('bandeja')
            ->assertSee('RIVERA TUTA JOSE OSVALDO');
    }

    public function test_un_gerente_de_otro_canal_no_ve_el_pedido(): void
    {
        $this->pedidoPendiente();

        $otroCanal = Canal::create(['nombre' => 'Cons. Residencial']);
        $otroGerente = $this->usuario('andrea.hincapie@segurex.com', 'GERENTE_CANAL');
        $otroGerente->canales()->attach($otroCanal->id);

        Livewire::actingAs($otroGerente)
            ->test('bandeja')
            ->assertDontSee('RIVERA TUTA JOSE OSVALDO');
    }

    public function test_la_bandeja_muestra_el_valor_sin_iva(): void
    {
        // Quien aprueba mira el valor antes de impuestos, no el total con IVA.
        $pedido = $this->pedidoPendiente()->fresh();
        $this->assertNotEquals((float) $pedido->subtotal, (float) $pedido->total);

        Livewire::actingAs($this->gerente)
            ->test('bandeja')
            ->assertSee('$ '.number_format($pedido->subtotal, 0, ',', '.'))
            ->assertSee('Valor sin IVA')
            ->assertDontSee('$ '.number_format($pedido->total, 0, ',', '.'));
    }

    public function test_la_bandeja_arranca_en_pendientes(): void
    {
        Livewire::actingAs($this->gerente)->test('bandeja')->assertSet('estado', 'PENDIENTE');
    }

    public function test_un_borrador_no_aparece_entre_los_pendientes(): void
    {
        // Un borrador todavia no se envio: nadie tiene que revisarlo.
        app(ServicioPedidos::class)->crear($this->cliente, $this->asesor);

        Livewire::actingAs($this->gerente)
            ->test('bandeja')
            ->assertSee('No hay pedidos esperando aprobación');
    }

    public function test_se_busca_por_numero_de_pedido(): void
    {
        $pedido = $this->pedidoPendiente();

        Livewire::actingAs($this->gerente)
            ->test('bandeja')
            ->set('buscar', (string) $pedido->id)
            ->assertSee('RIVERA TUTA JOSE OSVALDO');
    }

    public function test_un_asesor_no_entra_a_la_bandeja(): void
    {
        $this->actingAs($this->asesor)->get(route('bandeja'))->assertForbidden();
    }

    public function test_el_gerente_si_entra_a_la_bandeja(): void
    {
        $this->actingAs($this->gerente)->get(route('bandeja'))->assertOk();
    }

    // ---------- Bandeja de liberacion ----------

    public function test_sin_el_permiso_la_bandeja_de_liberacion_esta_cerrada(): void
    {
        // Ni siquiera TI: liberar es un permiso aparte del rol.
        foreach (['ASESOR', 'GERENTE_CANAL', 'ADMIN_VENTAS', 'TI'] as $rol) {
            $usuario = $this->usuario(strtolower($rol).'.sinpermiso@segurex.com', $rol);

            $this->actingAs($usuario)
                ->get(route('liberar'))
                ->assertForbidden();
        }
    }

    public function test_cesar_entra_y_ve_los_aprobados_esperando(): void
    {
        $cesar = $this->usuario('cesar.garzon@segurex.com', 'ADMIN_VENTAS');
        UsuarioPermiso::create(['usuario_id' => $cesar->id, 'permiso' => 'LIBERAR_SAP']);

        app(ServicioPedidos::class)->aprobar($this->pedidoPendiente(), $this->gerente);

        Livewire::actingAs($cesar)
            ->test('liberar')
            ->assertSee('RIVERA TUTA JOSE OSVALDO');
    }

    public function test_un_pedido_solo_pendiente_no_llega_a_la_bandeja_de_liberacion(): void
    {
        $cesar = $this->usuario('cesar.garzon@segurex.com', 'ADMIN_VENTAS');
        UsuarioPermiso::create(['usuario_id' => $cesar->id, 'permiso' => 'LIBERAR_SAP']);

        $this->pedidoPendiente();

        Livewire::actingAs($cesar)
            ->test('liberar')
            ->assertSee('No hay nada esperando tu visto bueno');
    }

    // ---------- Bloqueo y eliminacion en la pantalla del pedido ----------

    public function test_abrir_el_pedido_para_editarlo_lo_bloquea(): void
    {
        $pedido = app(ServicioPedidos::class)->crear($this->cliente, $this->asesor);

        Livewire::actingAs($this->asesor)->test('pedido', ['pedido' => $pedido]);

        $this->assertTrue($pedido->fresh()->bloqueadoAhora());
    }

    public function test_un_aprobador_que_solo_revisa_no_bloquea_el_pedido(): void
    {
        // Si el aprobador bloqueara al abrir, se estaria impidiendo aprobar a si mismo.
        $pedido = $this->pedidoPendiente();

        Livewire::actingAs($this->gerente)->test('pedido', ['pedido' => $pedido]);

        $this->assertFalse($pedido->fresh()->bloqueadoAhora());
    }

    public function test_un_administrador_que_solo_revisa_tampoco_bloquea(): void
    {
        // Un administrador si puede editar cualquier pedido, asi que sin esto
        // bloqueaba con solo abrirlo y despues no podia ni aprobarlo el mismo
        // ni dejarselo al asesor.
        $pedido = $this->pedidoPendiente();
        $admin = $this->usuario('cesar.garzon@segurex.com', 'ADMIN_VENTAS');

        Livewire::actingAs($admin)->test('pedido', ['pedido' => $pedido]);

        $this->assertFalse($pedido->fresh()->bloqueadoAhora());
    }

    public function test_el_administrador_toma_la_edicion_a_proposito(): void
    {
        $pedido = $this->pedidoPendiente();
        $admin = $this->usuario('cesar.garzon@segurex.com', 'ADMIN_VENTAS');

        Livewire::actingAs($admin)
            ->test('pedido', ['pedido' => $pedido])
            ->call('tomarEdicion');

        $this->assertSame($admin->id, $pedido->fresh()->bloqueado_por);
    }

    public function test_ti_aprueba_su_propio_pedido_aunque_lo_tenga_abierto(): void
    {
        // TI abre su pedido, con lo cual lo bloquea por ser el autor. El
        // bloqueo propio no puede impedirle aprobar: seria encerrarse solo.
        $ti = $this->usuario('leonardo.herrera@segurex.com', 'TI');
        $pedido = $this->pedidoPendiente($ti);

        Livewire::actingAs($ti)
            ->test('pedido', ['pedido' => $pedido])
            ->assertSet('versionVista', (int) $pedido->version)
            ->call('aprobar');

        $this->assertSame(EstadoPedido::APROBADO, $pedido->fresh()->estado);
    }

    public function test_quien_libera_no_se_traba_con_su_propio_bloqueo(): void
    {
        $cesar = $this->usuario('cesar.garzon@segurex.com', 'ADMIN_VENTAS');
        UsuarioPermiso::create(['usuario_id' => $cesar->id, 'permiso' => 'LIBERAR_SAP']);

        $pedido = app(ServicioPedidos::class)->aprobar($this->pedidoPendiente(), $this->gerente);

        // Un bloqueo suyo que quedo de antes no puede dejarlo sin liberar.
        $pedido->forceFill([
            'bloqueado_por' => $cesar->id,
            'bloqueado_hasta' => now()->addMinutes(30),
        ])->save();

        Livewire::actingAs($cesar)
            ->test('pedido', ['pedido' => $pedido->fresh()])
            ->call('liberar');

        $this->assertSame(EstadoPedido::LIBERADO, $pedido->fresh()->estado);
    }

    public function test_un_pedido_ya_aprobado_no_vuelve_a_ofrecer_el_boton(): void
    {
        $pedido = app(ServicioPedidos::class)->aprobar($this->pedidoPendiente(), $this->gerente);

        Livewire::actingAs($this->gerente)
            ->test('pedido', ['pedido' => $pedido])
            ->assertSet('pidiendoMotivo', false)
            ->assertDontSee('Aprobar</button>', escape: false);
    }

    public function test_el_aprobador_ve_la_orden_de_compra_y_las_observaciones(): void
    {
        // Sin esto estaria aprobando solo la lista de productos.
        $pedido = $this->pedidoPendiente();
        $pedido->update([
            'orden_compra' => 'OC-4471',
            'observaciones' => 'Entregar antes del viernes',
        ]);

        Livewire::actingAs($this->gerente)
            ->test('pedido', ['pedido' => $pedido->fresh()])
            ->assertSee('OC-4471')
            ->assertSee('Entregar antes del viernes');
    }

    public function test_el_asesor_lee_el_motivo_del_rechazo(): void
    {
        $pedido = app(ServicioPedidos::class)
            ->rechazar($this->pedidoPendiente(), $this->gerente, 'Falta la orden de compra');

        Livewire::actingAs($this->asesor)
            ->test('pedido', ['pedido' => $pedido->fresh()])
            ->assertSee('Falta la orden de compra');
    }

    public function test_devolver_un_liberado_pide_motivo_y_lo_regresa(): void
    {
        $cesar = $this->usuario('cesar.garzon@segurex.com', 'ADMIN_VENTAS');
        UsuarioPermiso::create(['usuario_id' => $cesar->id, 'permiso' => 'LIBERAR_SAP']);

        $servicio = app(ServicioPedidos::class);
        $pedido = $servicio->liberar($servicio->aprobar($this->pedidoPendiente(), $this->gerente), $cesar);

        Livewire::actingAs($cesar)
            ->test('pedido', ['pedido' => $pedido->fresh()])
            ->call('devolver')
            ->assertSee('necesita un motivo')
            ->set('motivoDevolucion', 'Confirmar precios con el cliente')
            ->call('devolver');

        $this->assertSame(EstadoPedido::APROBADO, $pedido->fresh()->estado);
    }

    public function test_un_gerente_no_puede_devolver_un_liberado(): void
    {
        $cesar = $this->usuario('cesar.garzon@segurex.com', 'ADMIN_VENTAS');
        UsuarioPermiso::create(['usuario_id' => $cesar->id, 'permiso' => 'LIBERAR_SAP']);

        $servicio = app(ServicioPedidos::class);
        $pedido = $servicio->liberar($servicio->aprobar($this->pedidoPendiente(), $this->gerente), $cesar);

        Livewire::actingAs($this->gerente)
            ->test('pedido', ['pedido' => $pedido->fresh()])
            ->assertDontSee('Devolver a aprobado');
    }

    public function test_la_ciudad_alterna_se_ve_desde_el_principio(): void
    {
        /*
         * Antes solo aparecia despues de que la direccion llegara al servidor,
         * asi que quien escribia la direccion y enviaba de una veia el error
         * sin haber visto nunca el campo.
         */
        $pedido = app(ServicioPedidos::class)->crear($this->cliente, $this->asesor);

        Livewire::actingAs($this->asesor)
            ->test('pedido', ['pedido' => $pedido])
            ->assertSee('Entrega en otra dirección', escape: false)
            ->assertSee('placeholder="Ciudad"', escape: false);
    }

    public function test_con_direccion_alterna_y_sin_ciudad_no_deja_enviar(): void
    {
        $servicio = app(ServicioPedidos::class);
        $producto = Producto::firstOrCreate(
            ['codigo' => 'PTS09040KC'],
            ['descripcion' => 'Cerradura', 'precio_lista' => 64900],
        );

        $pedido = $servicio->crear($this->cliente, $this->asesor);
        $servicio->agregarLinea($pedido, $producto, cantidad: 1);

        Livewire::actingAs($this->asesor)
            ->test('pedido', ['pedido' => $pedido->fresh()])
            ->set('direccion_2', 'CALLE 100 # 15 - 20')
            ->assertSee('Falta la ciudad')
            ->call('enviar');

        $this->assertSame(EstadoPedido::BORRADOR, $pedido->fresh()->estado);
    }

    public function test_con_direccion_y_ciudad_alterna_si_se_envia(): void
    {
        $servicio = app(ServicioPedidos::class);
        $producto = Producto::firstOrCreate(
            ['codigo' => 'PTS09040KC'],
            ['descripcion' => 'Cerradura', 'precio_lista' => 64900],
        );

        $pedido = $servicio->crear($this->cliente, $this->asesor);
        $servicio->agregarLinea($pedido, $producto, cantidad: 1);

        Livewire::actingAs($this->asesor)
            ->test('pedido', ['pedido' => $pedido->fresh()])
            ->set('direccion_2', 'CALLE 100 # 15 - 20')
            ->set('ciudad_2', 'BOGOTA')
            ->call('enviar');

        $fresco = $pedido->fresh();

        $this->assertSame(EstadoPedido::PENDIENTE, $fresco->estado);
        $this->assertSame('BOGOTA', $fresco->ciudad_2);
    }

    public function test_terminar_edicion_suelta_el_bloqueo(): void
    {
        $pedido = app(ServicioPedidos::class)->crear($this->cliente, $this->asesor);

        Livewire::actingAs($this->asesor)
            ->test('pedido', ['pedido' => $pedido])
            ->call('terminarEdicion');

        $this->assertFalse($pedido->fresh()->bloqueadoAhora());
    }

    public function test_el_asesor_elimina_su_pedido_no_aprobado(): void
    {
        $pedido = app(ServicioPedidos::class)->crear($this->cliente, $this->asesor);

        Livewire::actingAs($this->asesor)
            ->test('pedido', ['pedido' => $pedido])
            ->call('eliminar');

        $this->assertSoftDeleted('pedidos', ['id' => $pedido->id]);
    }

    public function test_aprobar_desde_la_pantalla_del_pedido(): void
    {
        $pedido = $this->pedidoPendiente();

        Livewire::actingAs($this->gerente)
            ->test('pedido', ['pedido' => $pedido])
            ->call('aprobar');

        $this->assertSame(EstadoPedido::APROBADO, $pedido->fresh()->estado);
    }

    public function test_rechazar_sin_motivo_avisa_en_pantalla(): void
    {
        $pedido = $this->pedidoPendiente();

        Livewire::actingAs($this->gerente)
            ->test('pedido', ['pedido' => $pedido])
            ->set('motivoRechazo', '')
            ->call('rechazar')
            ->assertSee('necesita un motivo');

        $this->assertSame(EstadoPedido::PENDIENTE, $pedido->fresh()->estado);
    }

    public function test_rechazar_con_motivo_lo_devuelve_al_asesor(): void
    {
        $pedido = $this->pedidoPendiente();

        Livewire::actingAs($this->gerente)
            ->test('pedido', ['pedido' => $pedido])
            ->set('motivoRechazo', 'Falta la orden de compra')
            ->call('rechazar');

        $this->assertSame(EstadoPedido::RECHAZADO, $pedido->fresh()->estado);
        $this->assertSame('Falta la orden de compra', $pedido->fresh()->motivo_rechazo);
    }
}
