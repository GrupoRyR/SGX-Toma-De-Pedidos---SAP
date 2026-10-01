<?php

namespace Tests\Feature;

use App\Models\AsesorSap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Carteras SAP reconocidas por su numero.
 *
 * El numero es fijo en SAP y no se repite; el nombre cambia cuando cambia el
 * asesor. Por eso la llave es el numero y no el texto completo.
 */
class CarterasTest extends TestCase
{
    use RefreshDatabase;

    private function migracionDelNumero(): object
    {
        return require database_path('migrations/2026_10_01_000100_agregar_numero_a_carteras.php');
    }

    // ---------- Modelo y resolucion por numero ----------

    public function test_al_crear_una_cartera_toma_el_numero_del_texto(): void
    {
        $cartera = AsesorSap::create(['codigo_texto' => '14 MONICA RIVERA AREVALO']);

        $this->assertSame(14, $cartera->refresh()->numero);
    }

    public function test_saca_el_numero_del_texto_de_sap(): void
    {
        $this->assertSame(14, AsesorSap::numeroDelTexto('14 MONICA RIVERA AREVALO'));
        $this->assertSame(7, AsesorSap::numeroDelTexto('  7 ALGUIEN'));
        $this->assertNull(AsesorSap::numeroDelTexto('MONICA RIVERA AREVALO'));
        $this->assertNull(AsesorSap::numeroDelTexto(''));
    }

    public function test_resolver_usa_la_cartera_del_mismo_numero_sin_renombrarla(): void
    {
        $existente = AsesorSap::create(['codigo_texto' => '14 MONICA RIVERA AREVALO']);

        $resuelta = AsesorSap::resolverDesdeTexto('14 OTRA PERSONA');

        $this->assertSame($existente->id, $resuelta->id);
        $this->assertFalse($resuelta->wasRecentlyCreated);
        $this->assertSame('14 MONICA RIVERA AREVALO', $resuelta->codigo_texto);
        $this->assertSame(1, AsesorSap::count());
    }

    public function test_resolver_crea_la_cartera_si_el_numero_no_existe(): void
    {
        $nueva = AsesorSap::resolverDesdeTexto('30 ASESOR NUEVO');

        $this->assertTrue($nueva->wasRecentlyCreated);
        $this->assertSame(30, $nueva->numero);
        $this->assertSame('30 ASESOR NUEVO', $nueva->codigo_texto);
        $this->assertSame('ASESOR NUEVO', $nueva->nombre);
        $this->assertTrue($nueva->activo);
    }

    public function test_resolver_rechaza_un_texto_sin_numero(): void
    {
        $this->expectException(RuntimeException::class);

        AsesorSap::resolverDesdeTexto('MONICA RIVERA AREVALO');
    }

    // ---------- Migracion ----------

    public function test_la_migracion_llena_el_numero_de_las_carteras_que_ya_existian(): void
    {
        $migracion = $this->migracionDelNumero();
        $migracion->down();
        $this->assertFalse(Schema::hasColumn('asesores_sap', 'numero'));

        DB::table('asesores_sap')->insert([
            ['codigo_texto' => '14 MONICA RIVERA AREVALO', 'activo' => true],
            ['codigo_texto' => '2 PEPE', 'activo' => true],
        ]);

        $migracion->up();

        $this->assertSame(
            ['14 MONICA RIVERA AREVALO' => 14, '2 PEPE' => 2],
            DB::table('asesores_sap')->pluck('numero', 'codigo_texto')->map(fn ($n) => (int) $n)->all(),
        );
    }

    public function test_la_migracion_se_detiene_si_hay_numeros_repetidos_o_sin_numero(): void
    {
        $migracion = $this->migracionDelNumero();
        $migracion->down();

        DB::table('asesores_sap')->insert([
            ['codigo_texto' => '14 MONICA RIVERA AREVALO', 'activo' => true],
            ['codigo_texto' => '14 OTRA PERSONA', 'activo' => true],
            ['codigo_texto' => 'SIN NUMERO', 'activo' => true],
        ]);

        try {
            $migracion->up();
            $this->fail('La migracion debia detenerse.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('14 OTRA PERSONA', $e->getMessage());
            $this->assertStringContainsString('SIN NUMERO', $e->getMessage());
        }

        // Se detuvo antes de tocar la tabla.
        $this->assertFalse(Schema::hasColumn('asesores_sap', 'numero'));
    }
}
