<?php

namespace Tests\Feature;

use App\Enums\EstadoPedido;
use App\Models\AsesorSap;
use App\Models\Canal;
use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El numerador no puede empezar en 1.
 *
 * En SAP ya existen ordenes creadas por la app de Power Apps cuyo campo
 * U_SGX_IdPedidoApp guarda numeros bajos. Si la web nueva repitiera esos
 * numeros, dos pedidos distintos apuntarian al mismo identificador y seria
 * imposible saber cual es cual.
 */
class NumeracionDePedidosTest extends TestCase
{
    use RefreshDatabase;

    private function pedidoNuevo(): Pedido
    {
        $canal = Canal::create(['nombre' => 'Distribucion']);
        $asesor = AsesorSap::create(['codigo_texto' => '14 PRUEBA']);
        $cliente = Cliente::create([
            'codigo_sn' => 'TEST01', 'nombre' => 'Cliente de prueba',
            'canal_id' => $canal->id, 'asesor_sap_id' => $asesor->id,
        ]);
        $usuario = Usuario::create([
            'correo' => 'asesor@segurex.com', 'nombre' => 'Asesor', 'rol' => 'ASESOR', 'activo' => true,
        ]);

        return Pedido::create([
            'cliente_id' => $cliente->id,
            'codigo_cliente' => $cliente->codigo_sn,
            'nombre_cliente' => $cliente->nombre,
            'creado_por' => $usuario->id,
            'estado' => EstadoPedido::BORRADOR,
        ]);
    }

    public function test_el_primer_pedido_no_arranca_en_uno(): void
    {
        $pedido = $this->pedidoNuevo();

        $this->assertGreaterThanOrEqual(
            5000,
            $pedido->id,
            'El numerador arranco bajo y podria chocar con los U_SGX_IdPedidoApp que SAP ya tiene.',
        );
    }

    public function test_el_numero_lo_asigna_la_base_de_datos_no_el_ultimo_de_la_lista(): void
    {
        // Error 1 de la app vieja: tomaba "el ultimo de la lista" como numero
        // nuevo, y con dos asesores creando a la vez se repetian.
        $primero = $this->pedidoNuevo();
        $segundo = Pedido::create([
            'cliente_id' => $primero->cliente_id,
            'codigo_cliente' => $primero->codigo_cliente,
            'nombre_cliente' => $primero->nombre_cliente,
            'creado_por' => $primero->creado_por,
            'estado' => EstadoPedido::BORRADOR,
        ]);

        $this->assertSame($primero->id + 1, $segundo->id);
    }

    public function test_el_pedido_nace_en_version_uno(): void
    {
        // La version es lo que impide que un aprobador apruebe algo distinto de
        // lo que leyo, cuando el asesor edita un pedido ya enviado.
        $this->assertSame(1, (int) $this->pedidoNuevo()->version);
    }

    public function test_solo_los_liberados_van_a_sap(): void
    {
        $pedido = $this->pedidoNuevo();

        foreach ([EstadoPedido::PENDIENTE, EstadoPedido::APROBADO] as $estado) {
            $pedido->update(['estado' => $estado]);
            $this->assertSame(0, Pedido::listosParaSap()->count(), "Un {$estado->value} no puede ir a SAP");
        }

        $pedido->update(['estado' => EstadoPedido::LIBERADO]);
        $this->assertSame(1, Pedido::listosParaSap()->count());

        // importado_sap no es mass-assignable a proposito: lo marca la
        // integracion, nunca un formulario.
        $pedido->importado_sap = true;
        $pedido->save();
        $this->assertSame(0, Pedido::listosParaSap()->count());
    }

    public function test_el_asesor_edita_mientras_nadie_haya_aprobado(): void
    {
        $pedido = $this->pedidoNuevo();
        $asesor = Usuario::find($pedido->creado_por);

        foreach ([EstadoPedido::BORRADOR, EstadoPedido::PENDIENTE, EstadoPedido::RECHAZADO] as $estado) {
            $pedido->update(['estado' => $estado]);
            $this->assertTrue($pedido->fresh()->puedeEditarlo($asesor), "Deberia poder editar un {$estado->value}");
        }

        foreach ([EstadoPedido::APROBADO, EstadoPedido::LIBERADO, EstadoPedido::IMPORTADO] as $estado) {
            $pedido->update(['estado' => $estado]);
            $this->assertFalse($pedido->fresh()->puedeEditarlo($asesor), "No deberia poder editar un {$estado->value}");
        }
    }

    public function test_un_asesor_no_puede_editar_el_pedido_de_otro(): void
    {
        $pedido = $this->pedidoNuevo();
        $otro = Usuario::create([
            'correo' => 'otro@segurex.com', 'nombre' => 'Otro', 'rol' => 'ASESOR', 'activo' => true,
        ]);

        $this->assertFalse($pedido->puedeEditarlo($otro));
    }

    public function test_el_bloqueo_de_edicion_expira_solo(): void
    {
        // Reemplaza el estado EDITANDO de la app vieja, que dejaba pedidos
        // atascados cuando el asesor cerraba sin guardar.
        $pedido = $this->pedidoNuevo();
        $otro = Usuario::create([
            'correo' => 'otro@segurex.com', 'nombre' => 'Otro', 'rol' => 'ADMIN_VENTAS', 'activo' => true,
        ]);

        // El bloqueo tampoco es mass-assignable: lo pone el servicio de edicion.
        $pedido->forceFill(['bloqueado_por' => $otro->id, 'bloqueado_hasta' => now()->addMinutes(30)])->save();
        $this->assertTrue($pedido->fresh()->bloqueadoAhora());
        $this->assertTrue($pedido->fresh()->bloqueadoPorOtro(Usuario::find($pedido->creado_por)));

        $pedido->forceFill(['bloqueado_hasta' => now()->subMinute()])->save();
        $this->assertFalse($pedido->fresh()->bloqueadoAhora());
    }
}
