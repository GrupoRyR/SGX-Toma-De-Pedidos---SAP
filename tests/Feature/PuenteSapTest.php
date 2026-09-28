<?php

namespace Tests\Feature;

use App\Enums\EstadoPedido;
use App\Models\AsesorSap;
use App\Models\Bitacora;
use App\Models\Canal;
use App\Models\Cliente;
use App\Models\Configuracion;
use App\Models\Inventario;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\TokenServicio;
use App\Models\Usuario;
use App\Models\UsuarioPermiso;
use App\Services\PuenteSap;
use App\Services\ServicioPedidos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * La API del robot puente.
 *
 * Lo que se cuida: que nadie entre sin token, y que por aqui no salga nunca un
 * pedido que no tenga el visto bueno de Cesar o Marly. Si esa segunda regla se
 * rompe, el control humano que SEGUREX puso antes de SAP deja de existir.
 */
class PuenteSapTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private Usuario $asesor;

    private Usuario $gerente;

    private Usuario $cesar;

    private Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();

        Configuracion::create(['clave' => 'iva_porcentaje', 'valor' => '19', 'tipo' => 'numero']);

        [, $this->token] = TokenServicio::generar('Robot de prueba');

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

        $this->cesar = $this->usuario('cesar.garzon@segurex.com', 'ADMIN_VENTAS');
        UsuarioPermiso::create(['usuario_id' => $this->cesar->id, 'permiso' => 'LIBERAR_SAP']);
    }

    private function usuario(string $correo, string $rol): Usuario
    {
        return Usuario::create([
            'correo' => $correo, 'nombre' => 'Prueba', 'rol' => $rol, 'activo' => true,
        ]);
    }

    private function conToken(?string $token = null): array
    {
        return ['Authorization' => 'Bearer '.($token ?? $this->token)];
    }

    private function pedidoLiberado(): Pedido
    {
        $servicio = app(ServicioPedidos::class);
        $producto = Producto::firstOrCreate(
            ['codigo' => 'PTS09040KC'],
            ['descripcion' => 'SEGUREX A-80PD CERRADURA ENTRADA SATURNO', 'precio_lista' => 64900],
        );

        $pedido = $servicio->crear($this->cliente, $this->asesor);
        $servicio->agregarLinea($pedido, $producto, cantidad: 2);
        $pedido = $servicio->enviar($pedido->fresh(), $this->asesor);
        $pedido = $servicio->aprobar($pedido, $this->gerente);

        return $servicio->liberar($pedido, $this->cesar);
    }

    // ---------- La puerta ----------

    public function test_sin_token_no_se_entra(): void
    {
        $this->getJson('/api/sap/pedidos-liberados')->assertStatus(401);
    }

    public function test_un_token_inventado_no_sirve(): void
    {
        $this->getJson('/api/sap/pedidos-liberados', $this->conToken('sgx_loquesea'))
            ->assertStatus(401);
    }

    public function test_un_token_revocado_deja_de_servir_de_inmediato(): void
    {
        [$token, $claro] = TokenServicio::generar('Robot viejo');
        $token->forceFill(['activo' => false, 'revocado_en' => now()])->save();

        $this->getJson('/api/sap/pedidos-liberados', $this->conToken($claro))->assertStatus(401);
    }

    public function test_el_token_no_se_guarda_en_claro(): void
    {
        // Quien entre a la base de datos no se lleva una llave utilizable.
        $this->assertDatabaseMissing('tokens_servicio', ['hash' => $this->token]);
        $this->assertNotNull(TokenServicio::porValor($this->token));
    }

    public function test_una_ip_fuera_de_la_lista_no_pasa(): void
    {
        [, $claro] = TokenServicio::generar('Robot con lista', null, '200.1.2.3');

        $this->getJson('/api/sap/pedidos-liberados', $this->conToken($claro))
            ->assertStatus(403)
            ->assertJsonPath('error', 'Esta IP no esta autorizada para este token.');
    }

    public function test_el_saludo_confirma_que_el_token_quedo_bien(): void
    {
        $this->getJson('/api/sap/saludo', $this->conToken())
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('token', 'Robot de prueba');
    }

    public function test_usar_el_token_deja_constancia(): void
    {
        $this->getJson('/api/sap/saludo', $this->conToken())->assertOk();

        $this->assertNotNull(TokenServicio::porValor($this->token)->ultimo_uso);
    }

    // ---------- Lo que sale hacia SAP ----------

    public function test_solo_salen_los_pedidos_liberados(): void
    {
        /*
         * La regla central. Un pedido aprobado pero sin visto bueno no puede
         * asomarse por esta API.
         */
        $servicio = app(ServicioPedidos::class);
        $producto = Producto::create(['codigo' => 'X1', 'descripcion' => 'Algo', 'precio_lista' => 1000]);

        $soloAprobado = $servicio->crear($this->cliente, $this->asesor);
        $servicio->agregarLinea($soloAprobado, $producto, cantidad: 1);
        $servicio->aprobar($servicio->enviar($soloAprobado->fresh(), $this->asesor), $this->gerente);

        $liberado = $this->pedidoLiberado();

        $respuesta = $this->getJson('/api/sap/pedidos-liberados', $this->conToken())->assertOk();

        $respuesta->assertJsonPath('cuantos', 1);
        $respuesta->assertJsonPath('pedidos.0.id', $liberado->id);
    }

    public function test_un_pedido_ya_importado_no_vuelve_a_salir(): void
    {
        $pedido = $this->pedidoLiberado();
        app(PuenteSap::class)->confirmar($pedido->id, '1042', '5000');

        $this->getJson('/api/sap/pedidos-liberados', $this->conToken())
            ->assertOk()
            ->assertJsonPath('cuantos', 0);
    }

    public function test_el_pedido_sale_con_sus_lineas_y_totales(): void
    {
        $pedido = $this->pedidoLiberado();

        $this->getJson('/api/sap/pedidos-liberados', $this->conToken())
            ->assertOk()
            ->assertJsonPath('pedidos.0.codigo_cliente', 'CN0507')
            // Un total sin centavos vuelve de JSON como entero: se compara el
            // valor, no el tipo.
            ->assertJsonPath('pedidos.0.total', fn ($total) => (float) $total === (float) $pedido->total)
            ->assertJsonCount(1, 'pedidos.0.lineas')
            ->assertJsonPath('pedidos.0.lineas.0.codigo_producto', 'PTS09040KC')
            // La numeracion de linea la usa SAP para ordenar el documento: si
            // llega nula, las lineas entran en cualquier orden.
            ->assertJsonPath('pedidos.0.lineas.0.numero', 0);
    }

    // ---------- Lo que vuelve de SAP ----------

    public function test_confirmar_cierra_el_ciclo(): void
    {
        $pedido = $this->pedidoLiberado();

        $this->postJson('/api/sap/confirmar', [
            'pedido_id' => $pedido->id, 'docentry' => '1042', 'docnum' => '30015',
        ], $this->conToken())->assertOk()->assertJsonPath('estado', 'IMPORTADO');

        $fresco = $pedido->fresh();

        $this->assertSame(EstadoPedido::IMPORTADO, $fresco->estado);
        $this->assertTrue((bool) $fresco->importado_sap);
        $this->assertSame('30015', $fresco->sap_docnum);
    }

    public function test_confirmar_dos_veces_no_rompe_nada(): void
    {
        // El robot puede perder la respuesta y reintentar; no puede duplicar.
        $pedido = $this->pedidoLiberado();
        $cuerpo = ['pedido_id' => $pedido->id, 'docentry' => '1042', 'docnum' => '30015'];

        $this->postJson('/api/sap/confirmar', $cuerpo, $this->conToken())->assertOk();
        $this->postJson('/api/sap/confirmar', $cuerpo, $this->conToken())->assertOk();

        $this->assertSame(1, Bitacora::where('accion', 'IMPORTAR_SAP')->count());
    }

    public function test_no_se_confirma_un_pedido_que_nunca_se_libero(): void
    {
        $servicio = app(ServicioPedidos::class);
        $producto = Producto::create(['codigo' => 'X2', 'descripcion' => 'Algo', 'precio_lista' => 1000]);
        $pedido = $servicio->crear($this->cliente, $this->asesor);
        $servicio->agregarLinea($pedido, $producto, cantidad: 1);

        $this->postJson('/api/sap/confirmar', [
            'pedido_id' => $pedido->id, 'docentry' => '1', 'docnum' => '1',
        ], $this->conToken())
            ->assertStatus(422)
            ->assertJsonPath('error', "El pedido {$pedido->id} no esta liberado: no debio salir a SAP.");
    }

    public function test_un_error_de_sap_no_esconde_el_pedido(): void
    {
        // Sigue LIBERADO y vuelve a aparecer, ahora con el motivo.
        $pedido = $this->pedidoLiberado();

        $this->postJson('/api/sap/error', [
            'pedido_id' => $pedido->id, 'mensaje' => 'Cliente bloqueado por cartera',
        ], $this->conToken())
            ->assertOk()
            ->assertJsonPath('intentos', 1)
            ->assertJsonPath('reintentar', true);

        $fresco = $pedido->fresh();

        $this->assertSame(EstadoPedido::LIBERADO, $fresco->estado);
        $this->assertSame('Cliente bloqueado por cartera', $fresco->sap_error);
    }

    public function test_despues_de_tres_intentos_se_deja_de_ofrecer(): void
    {
        /*
         * Reintentar en silencio para siempre esconde el problema. A la tercera
         * el pedido sale de la cola del robot y queda para que alguien lo mire.
         */
        $pedido = $this->pedidoLiberado();

        foreach (range(1, 3) as $intento) {
            $this->postJson('/api/sap/error', [
                'pedido_id' => $pedido->id, 'mensaje' => 'Item inexistente',
            ], $this->conToken())->assertOk();
        }

        $this->getJson('/api/sap/pedidos-liberados', $this->conToken())
            ->assertOk()
            ->assertJsonPath('cuantos', 0);

        $this->assertSame(3, (int) $pedido->fresh()->sap_intentos);
    }

    public function test_el_pedido_fallido_aparece_en_la_bandeja_de_liberacion(): void
    {
        // Quien lo libero tiene que enterarse. Esconderlo es peor que el error.
        $pedido = $this->pedidoLiberado();

        $this->postJson('/api/sap/error', [
            'pedido_id' => $pedido->id, 'mensaje' => 'Cliente bloqueado por cartera',
        ], $this->conToken())->assertOk();

        Livewire::actingAs($this->cesar)
            ->test('liberar')
            ->assertSee('Fallaron en SAP')
            ->assertSee('Cliente bloqueado por cartera');
    }

    public function test_el_mensaje_de_sap_se_lee_en_el_pedido(): void
    {
        $pedido = $this->pedidoLiberado();

        $this->postJson('/api/sap/error', [
            'pedido_id' => $pedido->id, 'mensaje' => 'Item PTS09040KC inexistente',
        ], $this->conToken())->assertOk();

        Livewire::actingAs($this->cesar)
            ->test('pedido', ['pedido' => $pedido->fresh()])
            ->assertSee('Item PTS09040KC inexistente');
    }

    public function test_el_numero_de_sap_se_ve_en_el_pedido(): void
    {
        // Es lo primero que se pregunta cuando hay que buscarlo alla.
        $pedido = $this->pedidoLiberado();

        $this->postJson('/api/sap/confirmar', [
            'pedido_id' => $pedido->id, 'docentry' => '1042', 'docnum' => '30015',
        ], $this->conToken())->assertOk();

        Livewire::actingAs($this->cesar)
            ->test('pedido', ['pedido' => $pedido->fresh()])
            ->assertSee('Número en SAP')
            ->assertSee('30015');
    }

    // ---------- Lo que sube de SAP ----------

    public function test_el_robot_publica_existencias(): void
    {
        $this->postJson('/api/sap/maestros/inventario', [
            'corte' => now()->toIso8601String(),
            'filas' => [
                ['codigo_producto' => 'PTS09040KC', 'bodega' => 'PRINCIPAL', 'disponible' => 14],
                ['codigo_producto' => 'PTS09040KC', 'bodega' => 'NORTE', 'disponible' => 3],
            ],
        ], $this->conToken())->assertOk()->assertJsonPath('filas', 2);

        $this->assertSame(2, Inventario::where('codigo_producto', 'PTS09040KC')->count());
    }

    public function test_publicar_existencias_dos_veces_actualiza_en_vez_de_duplicar(): void
    {
        $fila = ['codigo_producto' => 'PTS09040KC', 'bodega' => 'PRINCIPAL', 'disponible' => 14];

        $this->postJson('/api/sap/maestros/inventario', ['filas' => [$fila]], $this->conToken())->assertOk();

        $fila['disponible'] = 9;
        $this->postJson('/api/sap/maestros/inventario', ['filas' => [$fila]], $this->conToken())->assertOk();

        $this->assertSame(1, Inventario::count());
        $this->assertEquals(9, Inventario::first()->disponible);
    }

    public function test_el_robot_actualiza_precios_sin_vaciar_el_catalogo(): void
    {
        Producto::create(['codigo' => 'VIEJO', 'descripcion' => 'No viene en este envio', 'precio_lista' => 100]);
        Producto::create(['codigo' => 'PTS09040KC', 'descripcion' => 'Cerradura', 'precio_lista' => 64900]);

        $this->postJson('/api/sap/maestros/precios', [
            'filas' => [
                ['codigo' => 'PTS09040KC', 'descripcion' => 'Cerradura', 'precio_lista' => 71000],
                ['codigo' => 'NUEVO', 'descripcion' => 'Producto nuevo', 'precio_lista' => 5000],
            ],
        ], $this->conToken())
            ->assertOk()
            ->assertJsonPath('creados', 1)
            ->assertJsonPath('actualizados', 1);

        $this->assertEquals(71000, Producto::where('codigo', 'PTS09040KC')->value('precio_lista'));
        // Un envio incompleto no puede borrar lo que ya estaba.
        $this->assertTrue(Producto::where('codigo', 'VIEJO')->exists());
    }

    public function test_el_robot_actualiza_el_descuento_de_un_cliente(): void
    {
        $this->postJson('/api/sap/maestros/clientes', [
            'filas' => [[
                'codigo_sn' => 'CN0507',
                'nombre' => 'RIVERA TUTA JOSE OSVALDO',
                'porcentaje_descuento' => 31,
            ]],
        ], $this->conToken())->assertOk()->assertJsonPath('actualizados', 1);

        $this->assertEquals(31, $this->cliente->fresh()->porcentaje_descuento);
    }

    public function test_un_envio_mal_armado_se_rechaza_con_el_motivo(): void
    {
        $this->postJson('/api/sap/maestros/precios', [
            'filas' => [['codigo' => 'X', 'precio_lista' => 'mucho']],
        ], $this->conToken())
            ->assertStatus(422)
            ->assertJsonValidationErrors('filas.0.precio_lista');
    }
}
