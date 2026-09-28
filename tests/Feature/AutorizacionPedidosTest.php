<?php

namespace Tests\Feature;

use App\Enums\EstadoPedido;
use App\Models\AsesorSap;
use App\Models\Canal;
use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\Usuario;
use App\Models\UsuarioPermiso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Quien puede aprobar, editar y liberar cada pedido.
 *
 * Es la capa que protege las rutas que reciben un id. Sin ella, cambiar el
 * numero en la URL alcanzaria pedidos ajenos aunque las listas filtren bien.
 */
class AutorizacionPedidosTest extends TestCase
{
    use RefreshDatabase;

    private Cliente $cliente;

    private Usuario $asesor;

    protected function setUp(): void
    {
        parent::setUp();

        $canal = Canal::create(['nombre' => 'Distribucion']);
        $cartera = AsesorSap::create(['codigo_texto' => '14 MONICA RIVERA AREVALO']);

        $this->cliente = Cliente::create([
            'codigo_sn' => 'CN0507', 'nombre' => 'RIVERA TUTA JOSE OSVALDO',
            'canal_id' => $canal->id, 'asesor_sap_id' => $cartera->id,
        ]);

        $this->asesor = $this->usuario('asesor@segurex.com', 'ASESOR');
        $this->asesor->asesores()->attach($cartera->id);
    }

    private function usuario(string $correo, string $rol): Usuario
    {
        return Usuario::create([
            'correo' => $correo, 'nombre' => 'Prueba', 'rol' => $rol, 'activo' => true,
        ]);
    }

    private function pedidoDe(Usuario $autor, EstadoPedido $estado = EstadoPedido::PENDIENTE): Pedido
    {
        return Pedido::create([
            'cliente_id' => $this->cliente->id,
            'codigo_cliente' => $this->cliente->codigo_sn,
            'nombre_cliente' => $this->cliente->nombre,
            'creado_por' => $autor->id,
            'estado' => $estado,
        ]);
    }

    public function test_ti_puede_aprobar_su_propio_pedido(): void
    {
        // Excepcion pedida por SEGUREX: permite probar el flujo completo y
        // hacer inducciones sin necesitar un segundo usuario.
        $ti = $this->usuario('leonardo.herrera@segurex.com', 'TI');
        $pedido = $this->pedidoDe($ti);

        $this->assertTrue(Gate::forUser($ti)->allows('aprobar', $pedido));
        $this->assertTrue(Gate::forUser($ti)->allows('esAutoaprobacion', $pedido));
    }

    public function test_admin_ventas_no_puede_aprobar_su_propio_pedido(): void
    {
        $admin = $this->usuario('cesar.garzon@segurex.com', 'ADMIN_VENTAS');
        $pedido = $this->pedidoDe($admin);

        $this->assertFalse(Gate::forUser($admin)->allows('aprobar', $pedido));
    }

    public function test_un_gerente_no_puede_aprobar_su_propio_pedido(): void
    {
        $gerente = $this->usuario('jessika.quintero@segurex.com', 'GERENTE_CANAL');
        $gerente->canales()->attach(Canal::first()->id);
        $pedido = $this->pedidoDe($gerente);

        $this->assertFalse(Gate::forUser($gerente)->allows('aprobar', $pedido));
    }

    public function test_ti_puede_aprobar_el_pedido_de_otro(): void
    {
        $ti = $this->usuario('leonardo.herrera@segurex.com', 'TI');

        $this->assertTrue(Gate::forUser($ti)->allows('aprobar', $this->pedidoDe($this->asesor)));
    }

    public function test_un_asesor_no_aprueba_nada(): void
    {
        $otroAsesor = $this->usuario('otro@segurex.com', 'ASESOR');
        $otroAsesor->asesores()->attach(AsesorSap::first()->id);

        $this->assertFalse(Gate::forUser($this->asesor)->allows('aprobar', $this->pedidoDe($otroAsesor)));
    }

    public function test_aprobar_su_propio_pedido_no_lo_lleva_a_sap(): void
    {
        // El limite real de la excepcion: TI puede aprobar lo suyo, pero liberar
        // es un permiso aparte que TI no tiene. El pedido sigue necesitando el
        // visto bueno de Cesar o Marly para salir.
        $ti = $this->usuario('leonardo.herrera@segurex.com', 'TI');
        $pedido = $this->pedidoDe($ti, EstadoPedido::APROBADO);

        $this->assertFalse(Gate::forUser($ti)->allows('liberar', $pedido));
    }

    public function test_solo_quien_tiene_el_permiso_libera_a_sap(): void
    {
        $cesar = $this->usuario('cesar.garzon@segurex.com', 'ADMIN_VENTAS');
        UsuarioPermiso::create(['usuario_id' => $cesar->id, 'permiso' => 'LIBERAR_SAP']);
        $pedido = $this->pedidoDe($this->asesor, EstadoPedido::APROBADO);

        $this->assertTrue(Gate::forUser($cesar)->allows('liberar', $pedido));
    }

    public function test_no_se_libera_un_pedido_que_no_esta_aprobado(): void
    {
        $cesar = $this->usuario('cesar.garzon@segurex.com', 'ADMIN_VENTAS');
        UsuarioPermiso::create(['usuario_id' => $cesar->id, 'permiso' => 'LIBERAR_SAP']);

        foreach ([EstadoPedido::BORRADOR, EstadoPedido::PENDIENTE, EstadoPedido::RECHAZADO] as $estado) {
            $pedido = $this->pedidoDe($this->asesor, $estado);

            $this->assertFalse(
                Gate::forUser($cesar)->allows('liberar', $pedido),
                "No deberia poder liberar un {$estado->value}",
            );
        }
    }

    public function test_no_se_aprueba_un_pedido_que_alguien_esta_editando(): void
    {
        $ti = $this->usuario('leonardo.herrera@segurex.com', 'TI');
        $pedido = $this->pedidoDe($this->asesor);
        $pedido->forceFill([
            'bloqueado_por' => $this->asesor->id,
            'bloqueado_hasta' => now()->addMinutes(30),
        ])->save();

        $this->assertFalse(Gate::forUser($ti)->allows('aprobar', $pedido->fresh()));
    }

    public function test_un_asesor_no_alcanza_el_pedido_de_otra_cartera(): void
    {
        $ajena = AsesorSap::create(['codigo_texto' => '15 PAOLA ANDREA VARGAS MARIN']);
        $clienteAjeno = Cliente::create([
            'codigo_sn' => 'CN0491', 'nombre' => 'PUERTAS METALICAS COLOMBIANAS SAS',
            'canal_id' => Canal::first()->id, 'asesor_sap_id' => $ajena->id,
        ]);
        $otroAsesor = $this->usuario('otro@segurex.com', 'ASESOR');
        $otroAsesor->asesores()->attach($ajena->id);

        $pedidoAjeno = Pedido::create([
            'cliente_id' => $clienteAjeno->id,
            'codigo_cliente' => $clienteAjeno->codigo_sn,
            'nombre_cliente' => $clienteAjeno->nombre,
            'creado_por' => $otroAsesor->id,
            'estado' => EstadoPedido::PENDIENTE,
        ]);

        $this->assertFalse(Gate::forUser($this->asesor)->allows('ver', $pedidoAjeno));
        $this->assertFalse(Gate::forUser($this->asesor)->allows('editar', $pedidoAjeno));
    }

    public function test_un_pedido_importado_no_se_elimina(): void
    {
        $ti = $this->usuario('leonardo.herrera@segurex.com', 'TI');
        $pedido = $this->pedidoDe($this->asesor, EstadoPedido::IMPORTADO);
        $pedido->forceFill(['importado_sap' => true])->save();

        $this->assertFalse(Gate::forUser($ti)->allows('eliminar', $pedido->fresh()));
    }

    public function test_un_usuario_inactivo_no_puede_nada(): void
    {
        $ti = $this->usuario('leonardo.herrera@segurex.com', 'TI');
        $pedido = $this->pedidoDe($this->asesor);
        $ti->update(['activo' => false]);

        $this->assertFalse(Gate::forUser($ti->fresh())->allows('ver', $pedido));
        $this->assertFalse(Gate::forUser($ti->fresh())->allows('aprobar', $pedido));
    }
}
