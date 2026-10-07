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
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Plantillas de carga manual a SAP (DTW).
 *
 * Lo que se cuida aqui es lo mismo de siempre: que por aqui no salga un pedido
 * sin visto bueno, y que los campos sean exactamente los que la app vieja
 * escribia en SharePoint. Si cambian los nombres o el orden de las columnas,
 * quien importa en DTW tiene que volver a mapear todo a mano.
 */
class PlantillasSapTest extends TestCase
{
    use RefreshDatabase;

    private ServicioPedidos $servicio;

    private PlantillasSap $plantillas;

    private Usuario $asesor;

    private Usuario $gerente;

    private Usuario $cesar;

    private Usuario $ti;

    private Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->servicio = app(ServicioPedidos::class);
        $this->plantillas = app(PlantillasSap::class);

        Configuracion::create(['clave' => 'iva_porcentaje', 'valor' => '19', 'tipo' => 'numero']);

        $canal = Canal::create(['nombre' => 'Distribucion']);
        $cartera = AsesorSap::create(['codigo_texto' => '14 MONICA RIVERA AREVALO']);

        $this->cliente = Cliente::create([
            'codigo_sn' => 'CN0017', 'nombre' => 'ALMACEN EL ARQUITECTO SAS',
            'canal_id' => $canal->id, 'asesor_sap_id' => $cartera->id, 'porcentaje_descuento' => 30,
        ]);

        $this->asesor = $this->usuario('adriana.russi@segurex.com', 'ASESOR');
        $this->asesor->asesores()->attach($cartera->id);

        $this->gerente = $this->usuario('jessika.quintero@segurex.com', 'GERENTE_CANAL');
        $this->gerente->canales()->attach($canal->id);

        $this->cesar = $this->usuario('cesar.garzon@segurex.com', 'ADMIN_VENTAS');
        UsuarioPermiso::create(['usuario_id' => $this->cesar->id, 'permiso' => 'LIBERAR_SAP']);

        $this->ti = $this->usuario('leonardo.herrera@segurex.com', 'TI');
    }

    private function usuario(string $correo, string $rol): Usuario
    {
        return Usuario::create([
            'correo' => $correo, 'nombre' => 'Prueba '.$rol, 'rol' => $rol, 'activo' => true,
        ]);
    }

    private function producto(string $codigo = 'PTS09040KC', float $precio = 64900): Producto
    {
        return Producto::firstOrCreate(
            ['codigo' => $codigo],
            ['descripcion' => 'SEGUREX A-80PD CERRADURA ENTRADA SATURNO', 'precio_lista' => $precio],
        );
    }

    /** Un pedido aprobado, sin visto bueno. */
    private function pedidoAprobado(array $linea = []): Pedido
    {
        $pedido = $this->servicio->crear($this->cliente, $this->asesor);

        $this->servicio->agregarLinea(
            $pedido,
            $this->producto($linea['codigo'] ?? 'PTS09040KC'),
            cantidad: $linea['cantidad'] ?? 2,
            atp: $linea['atp'] ?? null,
            precioManual: $linea['precioManual'] ?? null,
        );

        return $this->servicio->aprobar(
            $this->servicio->enviar($pedido->fresh(), $this->asesor),
            $this->gerente,
        );
    }

    /** Un pedido aprobado con dos productos, para poder quitar uno. */
    private function pedidoAprobadoConDosLineas(): Pedido
    {
        $pedido = $this->servicio->crear($this->cliente, $this->asesor);
        $this->servicio->agregarLinea($pedido, $this->producto(), cantidad: 2);
        $this->servicio->agregarLinea($pedido->fresh(), $this->producto('B2', 30000), cantidad: 5);

        return $this->servicio->aprobar(
            $this->servicio->enviar($pedido->fresh(), $this->asesor),
            $this->gerente,
        );
    }

    /** Un pedido recorriendo todo el camino hasta LIBERADO. */
    private function pedidoLiberado(array $encabezado = [], array $linea = []): Pedido
    {
        $pedido = $this->servicio->crear($this->cliente, $this->asesor);

        if ($encabezado !== []) {
            $pedido->update($encabezado);
        }

        $this->servicio->agregarLinea(
            $pedido,
            $this->producto($linea['codigo'] ?? 'PTS09040KC'),
            cantidad: $linea['cantidad'] ?? 2,
            atp: $linea['atp'] ?? null,
            precioManual: $linea['precioManual'] ?? null,
        );

        $pedido = $this->servicio->enviar($pedido->fresh(), $this->asesor);
        $pedido = $this->servicio->aprobar($pedido, $this->gerente);

        return $this->servicio->liberar($pedido, $this->cesar);
    }

    private function filas(string $contenido): array
    {
        return array_map(
            fn (string $linea) => explode("\t", $linea),
            array_filter(explode("\r\n", trim($contenido)))
        );
    }

    /** Las columnas del archivo. */
    private function encabezado(string $contenido): array
    {
        return $this->filas($contenido)[0];
    }

    /**
     * Las filas de datos, sin el encabezado.
     *
     * El encabezado ocupa DOS lineas: asi son las plantillas de DTW. Se salta
     * aqui, en un solo sitio, para que ninguna prueba tenga que acordarse.
     */
    private function datos(string $contenido): array
    {
        return array_values(array_slice($this->filas($contenido), 2));
    }

    // ---------- Quien entra ----------

    public function test_un_asesor_no_entra(): void
    {
        $this->actingAs($this->asesor)->get(route('plantillas'))->assertForbidden();
    }

    public function test_un_gerente_de_canal_no_entra(): void
    {
        // Aprobar pedidos no es cargarlos a SAP.
        $this->actingAs($this->gerente)->get(route('plantillas'))->assertForbidden();
    }

    public function test_ti_cesar_y_marly_si_entran(): void
    {
        $marly = $this->usuario('marly.ossa@segurex.com', 'ADMIN_VENTAS');

        $this->actingAs($this->ti)->get(route('plantillas'))->assertOk();
        $this->actingAs($this->cesar)->get(route('plantillas'))->assertOk();
        $this->actingAs($marly)->get(route('plantillas'))->assertOk();
    }

    // ---------- Que sale y que no ----------

    public function test_basta_con_que_este_aprobado(): void
    {
        /*
         * El visto bueno frenaba al robot, que carga solo. Aqui la carga la
         * hace una persona que ve la lista antes de descargar, asi que ese
         * segundo candado no agregaba control.
         */
        $soloAprobado = $this->pedidoAprobado();
        $liberado = $this->pedidoLiberado();

        $ids = $this->plantillas->pendientes()->pluck('id');

        $this->assertCount(2, $ids);
        $this->assertTrue($ids->contains($soloAprobado->id));
        $this->assertTrue($ids->contains($liberado->id));
    }

    public function test_un_pedido_sin_aprobar_no_sale(): void
    {
        // Aprobar sigue siendo la puerta: lo que nadie reviso no se carga.
        $pedido = $this->servicio->crear($this->cliente, $this->asesor);
        $this->servicio->agregarLinea($pedido, $this->producto(), cantidad: 1);
        $this->servicio->enviar($pedido->fresh(), $this->asesor);

        $this->assertCount(0, $this->plantillas->pendientes());
    }

    public function test_un_pedido_rechazado_no_sale(): void
    {
        $pedido = $this->servicio->crear($this->cliente, $this->asesor);
        $this->servicio->agregarLinea($pedido, $this->producto(), cantidad: 1);
        $pedido = $this->servicio->enviar($pedido->fresh(), $this->asesor);
        $this->servicio->rechazar($pedido, $this->gerente, 'Falta la orden de compra');

        $this->assertCount(0, $this->plantillas->pendientes());
    }

    public function test_un_pedido_ya_importado_no_vuelve_a_salir(): void
    {
        $pedido = $this->pedidoLiberado();
        $this->plantillas->marcarImportados([$pedido->id], $this->ti);

        $this->assertCount(0, $this->plantillas->pendientes());
    }

    // ---------- El formato, que es lo que DTW espera ----------

    public function test_el_encabezado_va_dos_veces_en_los_tres_archivos(): void
    {
        /*
         * Asi son las plantillas de DTW: toma las DOS primeras lineas como
         * encabezado. Con una sola, se come la primera fila de datos creyendo
         * que es la segunda linea del encabezado, y el primer pedido de cada
         * tanda se pierde sin que nada avise. Es la diferencia entre que entren
         * todos los pedidos o todos menos uno.
         */
        $archivos = $this->plantillas->archivos(collect([
            $this->pedidoAprobado(),
            $this->pedidoLiberado(['direccion_2' => 'Calle 100', 'ciudad_2' => 'Bogota']),
        ]));

        foreach ($archivos as $nombre => $contenido) {
            $filas = $this->filas($contenido);

            $this->assertSame($filas[0], $filas[1], "El encabezado de {$nombre} no esta repetido.");
        }
    }

    public function test_no_se_pierde_el_primer_pedido(): void
    {
        // La consecuencia concreta del doble encabezado, dicha como la ve
        // quien importa: los dos pedidos tienen que llegar a SAP.
        $primero = $this->pedidoAprobado();
        $segundo = $this->pedidoAprobado();

        $archivos = $this->plantillas->archivos(collect([$primero, $segundo]));
        $filas = $this->datos($archivos['ENCABEZADO_PEDIDOS_SAP.txt']);

        $this->assertCount(2, $filas);
        $this->assertSame((string) $primero->id, $filas[0][0]);
        $this->assertSame((string) $segundo->id, $filas[1][0]);
    }

    public function test_el_encabezado_lleva_las_columnas_de_siempre(): void
    {
        // Si cambian los nombres o el orden, quien importa tiene que volver a
        // mapear las columnas a mano en DTW.
        $archivos = $this->plantillas->archivos(collect([$this->pedidoLiberado()]));

        $columnas = $this->encabezado($archivos['ENCABEZADO_PEDIDOS_SAP.txt']);

        $this->assertSame([
            'DocNum', 'CardCode', 'DocDate', 'DocDueDate', 'DocTime',
            'NumAtCard', 'Comments', 'U_SGX_IdPedidoApp',
        ], $columnas);
    }

    public function test_el_detalle_lleva_las_columnas_de_siempre(): void
    {
        $archivos = $this->plantillas->archivos(collect([$this->pedidoLiberado()]));

        $columnas = $this->encabezado($archivos['DETALLE_PEDIDO_SAP.txt']);

        $this->assertSame(
            ['ParentKey', 'LineNum', 'ItemCode', 'Quantity', 'DiscountPercent'],
            $columnas
        );
    }

    public function test_el_encabezado_trae_los_datos_del_pedido(): void
    {
        $pedido = $this->pedidoLiberado([
            'orden_compra' => 'OC-4471',
            'fecha_facturacion' => '2026-10-15',
            'observaciones' => 'Entregar antes del viernes',
        ]);

        $archivos = $this->plantillas->archivos(collect([$pedido->fresh()]));
        $datos = array_combine(
            $this->encabezado($archivos['ENCABEZADO_PEDIDOS_SAP.txt']),
            $this->datos($archivos['ENCABEZADO_PEDIDOS_SAP.txt'])[0],
        );

        $this->assertSame((string) $pedido->id, $datos['DocNum']);
        $this->assertSame('CN0017', $datos['CardCode']);
        $this->assertSame('2026-10-15', $datos['DocDueDate']);
        $this->assertSame('OC-4471', $datos['NumAtCard']);
        $this->assertSame('Entregar antes del viernes', $datos['Comments']);
        // El identificador con el que SAP reconoce un pedido de la app.
        $this->assertSame((string) $pedido->id, $datos['U_SGX_IdPedidoApp']);
    }

    public function test_sin_fecha_de_facturacion_la_entrega_es_la_fecha_del_pedido(): void
    {
        // DTW rechaza el encabezado si DocDueDate va vacio; cuando el asesor
        // no puso fecha de facturacion se manda la fecha en que se hizo el pedido.
        $pedido = $this->pedidoLiberado(['fecha_facturacion' => null]);
        $pedido->forceFill(['created_at' => '2026-09-20 08:30:00'])->saveQuietly();

        $archivos = $this->plantillas->archivos(collect([$pedido->fresh()]));
        $datos = array_combine(
            $this->encabezado($archivos['ENCABEZADO_PEDIDOS_SAP.txt']),
            $this->datos($archivos['ENCABEZADO_PEDIDOS_SAP.txt'])[0],
        );

        $this->assertSame('2026-09-20', $datos['DocDueDate']);
    }

    public function test_revisar_muestra_la_fecha_del_pedido_si_no_hay_fecha_de_facturacion(): void
    {
        $pedido = $this->pedidoLiberado([
            'fecha_facturacion' => null,
            'direccion_2' => 'Calle 100 # 15 - 20', 'ciudad_2' => 'Bogota',
        ]);
        $pedido->forceFill(['created_at' => '2026-09-20 08:30:00'])->saveQuietly();

        Livewire::actingAs($this->cesar)
            ->test('plantillas')
            ->call('alternarRevision', $pedido->id)
            ->assertSee('20/09/2026')
            ->assertSee('CALLE 100 # 15 - 20 · BOGOTA · CO · CO');
    }

    public function test_las_lineas_van_numeradas_desde_cero(): void
    {
        $pedido = $this->servicio->crear($this->cliente, $this->asesor);
        $this->servicio->agregarLinea($pedido, $this->producto('A1'), cantidad: 1);
        $this->servicio->agregarLinea($pedido->fresh(), $this->producto('B2'), cantidad: 3);
        $pedido = $this->servicio->enviar($pedido->fresh(), $this->asesor);
        $pedido = $this->servicio->liberar($this->servicio->aprobar($pedido, $this->gerente), $this->cesar);

        $archivos = $this->plantillas->archivos(collect([$pedido->fresh()]));
        $filas = $this->datos($archivos['DETALLE_PEDIDO_SAP.txt']);

        $this->assertSame('0', $filas[0][1]);
        $this->assertSame('1', $filas[1][1]);
    }

    public function test_el_atp_viaja_como_descuento_de_linea(): void
    {
        $pedido = $this->pedidoLiberado(linea: ['atp' => 10]);

        $archivos = $this->plantillas->archivos(collect([$pedido->fresh()]));
        $datos = array_combine(
            $this->encabezado($archivos['DETALLE_PEDIDO_SAP.txt']),
            $this->datos($archivos['DETALLE_PEDIDO_SAP.txt'])[0],
        );

        $this->assertSame('10', $datos['DiscountPercent']);
    }

    public function test_la_direccion_alterna_va_en_mayusculas_y_solo_si_existe(): void
    {
        $conDireccion = $this->pedidoLiberado([
            'direccion_2' => 'Calle 100 # 15 - 20', 'ciudad_2' => 'Bogota',
        ]);
        $sinDireccion = $this->pedidoLiberado();

        $archivos = $this->plantillas->archivos(collect([$conDireccion->fresh(), $sinDireccion->fresh()]));
        $filas = $this->datos($archivos['PLANTILLA_DIRECCIONES.txt']);

        // Una sola fila: el pedido sin direccion alterna no aparece.
        $this->assertCount(1, $filas);
        $this->assertSame((string) $conDireccion->id, $filas[0][0]);
        $this->assertSame('CALLE 100 # 15 - 20', $filas[0][1]);
        $this->assertSame('BOGOTA', $filas[0][2]);
        // Departamento y pais van fijos en CO.
        $this->assertSame('CO', $filas[0][3]);
        $this->assertSame('CO', $filas[0][4]);
    }

    public function test_el_archivo_de_direcciones_trae_encabezado_aunque_no_haya_ninguna(): void
    {
        // Un archivo vacio de verdad parece un error; uno con encabezado dice
        // "no habia direcciones alternas", que es distinto.
        $archivos = $this->plantillas->archivos(collect([$this->pedidoLiberado()]));

        $this->assertSame(
            ['DocEntry', 'ShipToStreet', 'ShipToCity', 'ShipToCounty', 'ShipToCountry'],
            $this->encabezado($archivos['PLANTILLA_DIRECCIONES.txt'])
        );
    }

    public function test_un_tabulador_en_las_observaciones_no_corre_las_columnas(): void
    {
        /*
         * Sin limpiarlo, un tabulador pegado en las observaciones corre el
         * resto del archivo y los datos entran a SAP en el campo equivocado.
         */
        $pedido = $this->pedidoLiberado(['observaciones' => "Urgente\tllamar antes\nal cliente"]);

        $archivos = $this->plantillas->archivos(collect([$pedido->fresh()]));
        $filas = $this->datos($archivos['ENCABEZADO_PEDIDOS_SAP.txt']);

        $this->assertCount(1, $filas);
        $this->assertCount(8, $filas[0]);
        $this->assertSame('Urgente llamar antes al cliente', $filas[0][6]);
    }

    // ---------- El precio manual, que el formato no sabe llevar ----------

    public function test_se_avisa_cuales_pedidos_traen_precio_manual(): void
    {
        /*
         * SEGUREX decidio no mandar el precio manual y corregir esas lineas a
         * mano en SAP. Entonces el unico trabajo de la web es decir cuales son
         * antes de importar, no despues de facturar.
         */
        $conManual = $this->pedidoLiberado(linea: ['precioManual' => 38000]);
        $normal = $this->pedidoLiberado();

        $avisados = $this->plantillas->conPrecioManual(collect([$conManual->fresh(), $normal->fresh()]));

        $this->assertCount(1, $avisados);
        $this->assertSame($conManual->id, $avisados->first()->id);
    }

    public function test_la_pantalla_avisa_del_precio_manual(): void
    {
        $pedido = $this->pedidoLiberado(linea: ['precioManual' => 38000]);

        Livewire::actingAs($this->ti)
            ->test('plantillas')
            ->set('elegidos', [(string) $pedido->id])
            ->assertSee('Corrige el precio a mano en SAP');
    }

    // ---------- Revisar el pedido contra lo que quedo en SAP ----------

    public function test_el_detalle_esta_cerrado_hasta_que_se_pide(): void
    {
        $this->pedidoLiberado(['orden_compra' => 'OC-7781']);

        Livewire::actingAs($this->cesar)
            ->test('plantillas')
            ->assertDontSee('OC-7781');
    }

    public function test_revisar_muestra_lo_que_debe_quedar_en_sap(): void
    {
        /*
         * Antes de marcar como importado, el admin de ventas abre el documento
         * en SAP y lo compara. La pantalla tiene que mostrarle los mismos datos
         * que lleva la plantilla, sin ir a buscar el pedido a otra parte.
         */
        $pedido = $this->pedidoLiberado(
            ['orden_compra' => 'OC-7781', 'observaciones' => 'Entregar en bodega 3'],
            ['codigo' => 'PTS09040KC', 'cantidad' => 4, 'atp' => 10],
        );

        Livewire::actingAs($this->cesar)
            ->test('plantillas')
            ->call('alternarRevision', $pedido->id)
            ->assertSee('CN0017')
            ->assertSee('OC-7781')
            ->assertSee('Entregar en bodega 3')
            ->assertSee('PTS09040KC')
            ->assertSee('SEGUREX A-80PD CERRADURA ENTRADA SATURNO')
            ->assertSee('10 %')
            ->call('alternarRevision', $pedido->id)
            ->assertDontSee('OC-7781');
    }

    public function test_revisar_marca_la_linea_con_precio_manual(): void
    {
        $pedido = $this->pedidoLiberado(linea: ['precioManual' => 38000]);

        Livewire::actingAs($this->cesar)
            ->test('plantillas')
            ->call('alternarRevision', $pedido->id)
            ->assertSee('38.000')
            ->assertSee('Corregir en SAP');
    }

    public function test_revisar_no_cambia_la_seleccion(): void
    {
        $pedido = $this->pedidoLiberado();

        Livewire::actingAs($this->cesar)
            ->test('plantillas')
            ->call('alternarRevision', $pedido->id)
            ->assertSet('elegidos', []);
    }

    // ---------- Ajustar un pedido ya aprobado ----------

    public function test_el_gerente_quita_una_linea_de_un_pedido_aprobado(): void
    {
        /*
         * El caso real: el cliente cancela un item despues de la aprobacion.
         * Devolver el pedido al asesor para eso es desproporcionado.
         */
        $pedido = $this->pedidoAprobadoConDosLineas();

        $linea = $pedido->lineas()->first();

        Livewire::actingAs($this->gerente)
            ->test('pedido', ['pedido' => $pedido])
            ->call('quitarAprobada', $linea->id);

        $this->assertSame(1, $pedido->fresh()->lineas()->count());
        $this->assertSame(EstadoPedido::APROBADO, $pedido->fresh()->estado);
    }

    public function test_quitar_una_linea_recalcula_los_totales(): void
    {
        $pedido = $this->pedidoAprobadoConDosLineas();

        $totalAntes = (float) $pedido->total;
        $linea = $pedido->lineas()->where('codigo_producto', 'B2')->first();

        $this->servicio->quitarLineaAprobada($pedido, $linea, $this->gerente);

        $this->assertLessThan($totalAntes, (float) $pedido->fresh()->total);
    }

    public function test_la_plantilla_sale_sin_la_linea_quitada(): void
    {
        /*
         * El punto de fondo: las plantillas se arman al descargar, leyendo el
         * pedido. No hay una copia guardada que pueda quedar desfasada, que es
         * justo lo que pasaba en la app vieja al escribirlas al aprobar.
         */
        $pedido = $this->pedidoAprobadoConDosLineas();

        $linea = $pedido->lineas()->where('codigo_producto', 'B2')->first();
        $this->servicio->quitarLineaAprobada($pedido, $linea, $this->gerente);

        $archivos = $this->plantillas->archivos($this->plantillas->pendientes());
        $detalle = $archivos['DETALLE_PEDIDO_SAP.txt'];

        $this->assertStringNotContainsString('B2', $detalle);
        $this->assertStringContainsString('PTS09040KC', $detalle);
    }

    public function test_quitar_una_linea_queda_en_el_log_con_el_antes_y_el_despues(): void
    {
        $pedido = $this->pedidoAprobadoConDosLineas();

        $linea = $pedido->lineas()->where('codigo_producto', 'B2')->first();
        $totalAntes = (float) $pedido->total;

        auth()->login($this->gerente);
        $this->servicio->quitarLineaAprobada($pedido, $linea, $this->gerente);

        $registro = Bitacora::where('accion', 'AJUSTAR_APROBADO')->latest('id')->first();

        $this->assertSame('B2', $registro->detalle['quito']);
        $this->assertEquals($totalAntes, $registro->detalle['total_antes']);
        $this->assertLessThan($totalAntes, $registro->detalle['total_despues']);
    }

    public function test_no_se_deja_el_pedido_sin_productos(): void
    {
        // Un pedido vacio no se puede importar. Si ya no va, se rechaza entero.
        $pedido = $this->pedidoAprobado();
        $linea = $pedido->lineas()->first();

        $this->expectExceptionMessage('unica linea del pedido');
        $this->servicio->quitarLineaAprobada($pedido, $linea, $this->gerente);
    }

    public function test_un_pedido_ya_en_sap_no_se_toca(): void
    {
        /*
         * A partir de ahi la verdad esta en SAP y se corrige alla. Si no, la
         * web diria una cosa y SAP otra.
         */
        $pedido = $this->pedidoAprobadoConDosLineas();
        $this->plantillas->marcarImportados([$pedido->id], $this->ti);

        $linea = $pedido->fresh()->lineas()->first();

        $this->expectExceptionMessage('ya entro a SAP');
        $this->servicio->quitarLineaAprobada($pedido->fresh(), $linea, $this->gerente);
    }

    public function test_el_asesor_no_puede_ajustar_un_aprobado(): void
    {
        $pedido = $this->pedidoAprobado();

        $this->assertFalse($this->asesor->can('ajustar', $pedido));
        $this->assertTrue($this->gerente->can('ajustar', $pedido));
    }

    public function test_un_gerente_de_otro_canal_no_puede_ajustar(): void
    {
        $otroCanal = Canal::create(['nombre' => 'Cons. Residencial']);
        $otro = $this->usuario('andrea.hincapie@segurex.com', 'GERENTE_CANAL');
        $otro->canales()->attach($otroCanal->id);

        $this->assertFalse($otro->can('ajustar', $this->pedidoAprobado()));
    }

    public function test_se_avisa_que_las_plantillas_quedaron_viejas(): void
    {
        /*
         * Si alguien ya se llevo el archivo y despues el pedido cambia, quien
         * lo va a importar tiene que enterarse antes de cargarlo.
         */
        $pedido = $this->pedidoAprobadoConDosLineas();

        $this->plantillas->registrarDescarga(collect([$pedido]), $this->ti);

        $this->assertFalse($pedido->fresh()->cambioDespuesDeDescargar());

        $linea = $pedido->fresh()->lineas()->where('codigo_producto', 'B2')->first();
        $this->servicio->quitarLineaAprobada($pedido->fresh(), $linea, $this->gerente);

        $this->assertTrue($pedido->fresh()->cambioDespuesDeDescargar());
    }

    public function test_la_pantalla_de_plantillas_marca_el_que_cambio(): void
    {
        $pedido = $this->pedidoAprobadoConDosLineas();

        $this->plantillas->registrarDescarga(collect([$pedido]), $this->ti);

        $linea = $pedido->fresh()->lineas()->where('codigo_producto', 'B2')->first();
        $this->servicio->quitarLineaAprobada($pedido->fresh(), $linea, $this->gerente);

        Livewire::actingAs($this->ti)
            ->test('plantillas')
            ->assertSee('vuelve a bajarlo');
    }

    // ---------- Descargar no es importar ----------

    public function test_descargar_no_marca_nada(): void
    {
        /*
         * Entre descargar e importar, DTW puede rechazar el archivo. Marcarlos
         * al descargar dejaria pedidos que la web da por puestos en SAP y que
         * nadie volveria a mirar.
         */
        $pedido = $this->pedidoLiberado();

        Livewire::actingAs($this->ti)
            ->test('plantillas')
            ->set('elegidos', [(string) $pedido->id])
            ->call('descargar');

        $this->assertSame(EstadoPedido::LIBERADO, $pedido->fresh()->estado);
        $this->assertFalse((bool) $pedido->fresh()->importado_sap);
    }

    public function test_la_descarga_entrega_un_archivo(): void
    {
        $pedido = $this->pedidoLiberado();

        Livewire::actingAs($this->ti)
            ->test('plantillas')
            ->set('elegidos', [(string) $pedido->id])
            ->call('descargar')
            ->assertFileDownloaded();
    }

    public function test_queda_constancia_de_quien_descargo(): void
    {
        $pedido = $this->pedidoLiberado();

        Livewire::actingAs($this->cesar)
            ->test('plantillas')
            ->set('elegidos', [(string) $pedido->id])
            ->call('descargar');

        $this->assertDatabaseHas('bitacora', [
            'accion' => 'DESCARGAR_PLANTILLAS',
            'usuario_correo' => 'cesar.garzon@segurex.com',
        ]);
    }

    public function test_sin_elegir_nada_no_se_descarga(): void
    {
        $this->pedidoLiberado();

        Livewire::actingAs($this->ti)
            ->test('plantillas')
            ->call('descargar')
            ->assertSee('Elige al menos un pedido');
    }

    // ---------- Marcar como importados ----------

    public function test_marcar_cierra_el_ciclo(): void
    {
        $pedido = $this->pedidoLiberado();

        Livewire::actingAs($this->ti)
            ->test('plantillas')
            ->set('elegidos', [(string) $pedido->id])
            ->call('marcarImportados');

        $fresco = $pedido->fresh();

        $this->assertSame(EstadoPedido::IMPORTADO, $fresco->estado);
        $this->assertTrue((bool) $fresco->importado_sap);
        $this->assertNotNull($fresco->fecha_importacion);
    }

    public function test_marcar_queda_en_el_log_con_quien_lo_hizo(): void
    {
        $pedido = $this->pedidoLiberado();

        Livewire::actingAs($this->cesar)
            ->test('plantillas')
            ->set('elegidos', [(string) $pedido->id])
            ->call('marcarImportados');

        $registro = Bitacora::where('accion', 'IMPORTAR_SAP')->latest('id')->first();

        $this->assertSame('plantillas DTW', $registro->detalle['via']);
        $this->assertSame('cesar.garzon@segurex.com', $registro->detalle['marcado_por']);
    }

    public function test_no_se_marca_dos_veces(): void
    {
        // Dos personas trabajando la misma tanda no pueden pisarse.
        $pedido = $this->pedidoLiberado();
        $this->plantillas->marcarImportados([$pedido->id], $this->ti);

        $this->expectExceptionMessage('ya no estan esperando carga');
        $this->plantillas->marcarImportados([$pedido->id], $this->cesar);
    }

    public function test_no_se_marca_un_pedido_que_nadie_aprobo(): void
    {
        $pedido = $this->servicio->crear($this->cliente, $this->asesor);
        $this->servicio->agregarLinea($pedido, $this->producto(), cantidad: 1);
        $pedido = $this->servicio->enviar($pedido->fresh(), $this->asesor);

        $this->expectExceptionMessage('ya no estan esperando carga');
        $this->plantillas->marcarImportados([$pedido->id], $this->ti);
    }

    public function test_un_aprobado_sin_visto_bueno_si_se_marca(): void
    {
        $pedido = $this->pedidoAprobado();

        $this->plantillas->marcarImportados([$pedido->id], $this->ti);

        $this->assertSame(EstadoPedido::IMPORTADO, $pedido->fresh()->estado);
    }
}
