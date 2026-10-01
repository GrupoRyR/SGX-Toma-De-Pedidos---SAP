<?php

namespace Tests\Feature;

use App\Enums\EstadoPedido;
use App\Models\AsesorSap;
use App\Models\Bitacora;
use App\Models\Canal;
use App\Models\Cliente;
use App\Models\Configuracion;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\Usuario;
use App\Models\UsuarioPermiso;
use App\Services\PlantillasSap;
use App\Services\ServicioPedidos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Devolver un pedido aprobado a borrador.
 *
 * Despues de aprobado, el pedido solo admitia quitar lineas. Si hacia falta
 * agregar un producto o cambiar cantidades no habia forma de devolverselo al
 * asesor. Reversar lo regresa a BORRADOR para que lo corrija y vuelva a pasar
 * por aprobacion, siempre que no haya entrado a SAP.
 */
class ReversarAprobadoTest extends TestCase
{
    use RefreshDatabase;

    private ServicioPedidos $servicio;

    private Cliente $cliente;

    private Producto $producto;

    private Usuario $asesor;

    private Usuario $gerente;

    protected function setUp(): void
    {
        parent::setUp();

        Configuracion::create(['clave' => 'iva_porcentaje', 'valor' => '19', 'tipo' => 'numero']);

        $this->servicio = app(ServicioPedidos::class);

        $canal = Canal::create(['nombre' => 'Distribucion']);
        $cartera = AsesorSap::create(['codigo_texto' => '14 MONICA RIVERA AREVALO']);

        $this->cliente = Cliente::create([
            'codigo_sn' => 'CN0507', 'nombre' => 'RIVERA TUTA JOSE OSVALDO',
            'canal_id' => $canal->id, 'asesor_sap_id' => $cartera->id, 'porcentaje_descuento' => 28,
        ]);

        $this->producto = Producto::create([
            'codigo' => 'PTS09040KC', 'descripcion' => 'Cerradura', 'precio_lista' => 64900,
        ]);

        $this->asesor = $this->usuario('adriana.russi@segurex.com', 'ASESOR');
        $this->asesor->asesores()->attach($cartera->id);

        $this->gerente = $this->usuario('jessika.quintero@segurex.com', 'GERENTE_CANAL');
        $this->gerente->canales()->attach($canal->id);
    }

    private function usuario(string $correo, string $rol): Usuario
    {
        return Usuario::create([
            'correo' => $correo, 'nombre' => 'Usuario '.$rol, 'rol' => $rol, 'activo' => true,
        ]);
    }

    private function pedidoAprobado(): Pedido
    {
        $pedido = $this->servicio->crear($this->cliente, $this->asesor);
        $this->servicio->agregarLinea($pedido, $this->producto, cantidad: 2);
        $this->servicio->enviar($pedido->fresh(), $this->asesor);

        return $this->servicio->aprobar($pedido->fresh(), $this->gerente);
    }

    private function pedidoLiberado(): Pedido
    {
        $liberador = $this->usuario('cesar.garzon@segurex.com', 'ADMIN_VENTAS');
        UsuarioPermiso::create(['usuario_id' => $liberador->id, 'permiso' => 'LIBERAR_SAP']);

        return $this->servicio->liberar($this->pedidoAprobado(), $liberador);
    }

    private function conPlantillasDescargadas(Pedido $pedido): Pedido
    {
        $admin = $this->usuario('marly.ossa@segurex.com', 'ADMIN_VENTAS');
        app(PlantillasSap::class)->registrarDescarga(collect([$pedido->fresh()]), $admin);

        return $pedido->fresh();
    }

    public function test_la_gerente_del_canal_lo_devuelve_a_borrador(): void
    {
        $pedido = $this->pedidoAprobado();
        $versionAntes = (int) $pedido->version;

        $this->servicio->reversarABorrador($pedido, $this->gerente, '  Falta agregar las bisagras  ');

        $pedido->refresh();

        $this->assertSame(EstadoPedido::BORRADOR, $pedido->estado);
        $this->assertSame('Falta agregar las bisagras', $pedido->motivo_rechazo);
        $this->assertNull($pedido->aprobado_por);
        $this->assertNull($pedido->fecha_aprobacion);
        $this->assertNull($pedido->snapshot_aprobado);
        $this->assertSame($versionAntes + 1, (int) $pedido->version);
    }

    public function test_la_bitacora_guarda_lo_que_se_habia_aprobado(): void
    {
        // La copia congelada desaparece del pedido; la bitacora es lo que
        // queda como evidencia de lo que se aprobo.
        $pedido = $this->pedidoAprobado();
        $snapshot = $pedido->snapshot_aprobado;

        $this->servicio->reversarABorrador($pedido, $this->gerente, 'Cambian cantidades');

        $registro = Bitacora::where('accion', 'REVERSAR_APROBADO')->where('entidad_id', $pedido->id)->first();

        $this->assertNotNull($registro);
        $this->assertSame('Cambian cantidades', $registro->detalle['motivo']);
        $this->assertSame('APROBADO', $registro->detalle['estado_anterior']);
        $this->assertSame($this->gerente->id, $registro->detalle['aprobado_por']);
        $this->assertNotNull($registro->detalle['fecha_aprobacion']);
        $this->assertEquals($snapshot, $registro->detalle['snapshot_aprobado']);
        $this->assertArrayHasKey('plantillas_descargadas_en', $registro->detalle);
    }

    public function test_el_asesor_lo_corrige_y_vuelve_a_aprobacion(): void
    {
        $pedido = $this->pedidoAprobado();
        $tomadaAntes = $pedido->snapshot_aprobado['tomada_en'];

        $this->servicio->reversarABorrador($pedido, $this->gerente, 'Agrega otra cerradura');

        $this->assertTrue(Gate::forUser($this->asesor)->allows('editar', $pedido->fresh()));

        $this->servicio->agregarLinea($pedido->fresh(), $this->producto, cantidad: 1);
        $enviado = $this->servicio->enviar($pedido->fresh(), $this->asesor);

        $this->assertSame(EstadoPedido::PENDIENTE, $enviado->estado);
        $this->assertNull($enviado->motivo_rechazo);
        $this->assertTrue(Gate::forUser($this->gerente)->allows('aprobar', $enviado));
        $this->assertTrue(
            Pedido::visiblePara($this->gerente)->where('estado', EstadoPedido::PENDIENTE)->whereKey($pedido->id)->exists()
        );

        $this->travel(1)->minutes();
        $reaprobado = $this->servicio->aprobar($enviado, $this->gerente);

        $this->assertCount(2, $reaprobado->snapshot_aprobado['lineas']);
        $this->assertNotSame($tomadaAntes, $reaprobado->snapshot_aprobado['tomada_en']);
    }

    public function test_un_pedido_liberado_que_no_entro_a_sap_tambien_se_reversa(): void
    {
        $pedido = $this->pedidoLiberado();

        $this->assertTrue(Gate::forUser($this->gerente)->allows('reversar', $pedido));

        $this->servicio->reversarABorrador($pedido, $this->gerente, 'El cliente cambio el pedido');

        $pedido->refresh();

        $this->assertSame(EstadoPedido::BORRADOR, $pedido->estado);
        $this->assertNull($pedido->liberado_por);
        $this->assertNull($pedido->fecha_liberacion);
    }

    public function test_un_pedido_importado_no_se_reversa(): void
    {
        $pedido = $this->pedidoAprobado();
        app(PlantillasSap::class)->marcarImportados([$pedido->id], $this->gerente);
        $pedido->refresh();

        $this->assertFalse(Gate::forUser($this->gerente)->allows('reversar', $pedido));

        $this->expectException(RuntimeException::class);
        $this->servicio->reversarABorrador($pedido, $this->gerente, 'Motivo', confirmaPlantillas: true);
    }

    public function test_si_ya_entro_a_sap_no_se_reversa_aunque_siga_aprobado(): void
    {
        // El robot marca importado_sap; nunca se confia solo en el estado.
        $pedido = $this->pedidoAprobado();
        $pedido->forceFill(['importado_sap' => true])->save();

        $this->assertFalse(Gate::forUser($this->gerente)->allows('reversar', $pedido));

        $this->expectException(RuntimeException::class);
        $this->servicio->reversarABorrador($pedido, $this->gerente, 'Motivo');
    }

    public function test_un_pedido_pendiente_no_se_reversa(): void
    {
        // Pendiente ya tiene su camino: rechazarlo.
        $pedido = $this->servicio->crear($this->cliente, $this->asesor);
        $this->servicio->agregarLinea($pedido, $this->producto, cantidad: 2);
        $pedido = $this->servicio->enviar($pedido->fresh(), $this->asesor);

        $this->assertFalse(Gate::forUser($this->gerente)->allows('reversar', $pedido));

        $this->expectException(RuntimeException::class);
        $this->servicio->reversarABorrador($pedido, $this->gerente, 'Motivo');
    }

    public function test_la_gerente_de_otro_canal_no_lo_reversa(): void
    {
        $otraGerente = $this->usuario('otra.gerente@segurex.com', 'GERENTE_CANAL');
        $otraGerente->canales()->attach(Canal::create(['nombre' => 'Construccion'])->id);

        $this->assertFalse(Gate::forUser($otraGerente)->allows('reversar', $this->pedidoAprobado()));
    }

    public function test_el_asesor_no_lo_reversa(): void
    {
        $pedido = $this->pedidoAprobado();

        $this->assertFalse(Gate::forUser($this->asesor)->allows('reversar', $pedido));

        $this->expectException(RuntimeException::class);
        $this->servicio->reversarABorrador($pedido, $this->asesor, 'Lo quiero cambiar');
    }

    public function test_admin_de_ventas_y_ti_si_lo_reversan(): void
    {
        $pedido = $this->pedidoAprobado();

        $this->assertTrue(Gate::forUser($this->usuario('admin@segurex.com', 'ADMIN_VENTAS'))->allows('reversar', $pedido));
        $this->assertTrue(Gate::forUser($this->usuario('ti@segurex.com', 'TI'))->allows('reversar', $pedido));
    }

    public function test_no_se_reversa_si_otra_persona_lo_esta_editando(): void
    {
        $pedido = $this->pedidoAprobado();
        $admin = $this->usuario('admin@segurex.com', 'ADMIN_VENTAS');
        $pedido->forceFill(['bloqueado_por' => $admin->id, 'bloqueado_hasta' => now()->addMinutes(10)])->save();

        $this->assertFalse(Gate::forUser($this->gerente)->allows('reversar', $pedido));

        $this->expectException(RuntimeException::class);
        $this->servicio->reversarABorrador($pedido, $this->gerente, 'Motivo');
    }

    public function test_sin_motivo_no_se_reversa(): void
    {
        $pedido = $this->pedidoAprobado();

        try {
            $this->servicio->reversarABorrador($pedido, $this->gerente, '   ');
            $this->fail('Se reverso sin motivo.');
        } catch (RuntimeException) {
            // Esperado.
        }

        $this->assertSame(EstadoPedido::APROBADO, $pedido->fresh()->estado);
    }

    public function test_con_plantillas_descargadas_sin_confirmar_no_se_reversa(): void
    {
        // Ese pedido puede estar ya en un archivo de DTW listo para importar.
        $pedido = $this->conPlantillasDescargadas($this->pedidoAprobado());

        try {
            $this->servicio->reversarABorrador($pedido, $this->gerente, 'Motivo');
            $this->fail('Se reverso sin confirmar las plantillas.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('plantillas', $e->getMessage());
        }

        $this->assertSame(EstadoPedido::APROBADO, $pedido->fresh()->estado);
    }

    public function test_con_plantillas_descargadas_y_confirmando_si_se_reversa(): void
    {
        $pedido = $this->conPlantillasDescargadas($this->pedidoAprobado());
        $descargadas = $pedido->plantillas_descargadas_en;

        $this->servicio->reversarABorrador($pedido, $this->gerente, 'Motivo', confirmaPlantillas: true);

        $pedido->refresh();

        $this->assertSame(EstadoPedido::BORRADOR, $pedido->estado);
        $this->assertNull($pedido->plantillas_descargadas_en);
        $this->assertNull($pedido->version_al_descargar);
        $this->assertFalse(app(PlantillasSap::class)->pendientes()->contains('id', $pedido->id));

        $registro = Bitacora::where('accion', 'REVERSAR_APROBADO')->first();
        $this->assertSame($descargadas->toIso8601String(), $registro->detalle['plantillas_descargadas_en']);
    }

    public function test_se_le_avisa_al_asesor_por_correo(): void
    {
        $this->conBuzonConfigurado();
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'token-falso'], 200),
            'graph.microsoft.com/*' => Http::response('', 202),
        ]);

        $pedido = $this->servicio->reversarABorrador($this->pedidoAprobado(), $this->gerente, 'Faltan las bisagras');

        Http::assertSent(fn (Request $p) => str_contains($p->url(), 'sendMail')
            && $p['message']['toRecipients'][0]['emailAddress']['address'] === 'adriana.russi@segurex.com'
            && str_contains($p['message']['body']['content'], 'Faltan las bisagras'));

        $this->assertDatabaseHas('notificaciones', [
            'evento' => 'REVERSAR', 'pedido_id' => $pedido->id, 'resultado' => 'ENVIADO',
        ]);
    }

    public function test_si_graph_falla_el_pedido_igual_queda_en_borrador(): void
    {
        $this->conBuzonConfigurado();
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'token-falso'], 200),
            'graph.microsoft.com/*' => Http::response('', 403),
        ]);

        $pedido = $this->servicio->reversarABorrador($this->pedidoAprobado(), $this->gerente, 'Motivo');

        $this->assertSame(EstadoPedido::BORRADOR, $pedido->fresh()->estado);
        $this->assertDatabaseHas('notificaciones', ['evento' => 'REVERSAR', 'resultado' => 'ERROR']);
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
}
