<?php

namespace Tests\Feature;

use App\Models\AsesorSap;
use App\Models\Canal;
use App\Models\Cliente;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Pantalla "Mis clientes".
 *
 * Lo que mas importa aqui no es que la lista se vea, sino que no se vea de mas:
 * la busqueda y la paginacion corren en el servidor con la regla de visibilidad
 * aplicada, asi que ningun texto de busqueda puede sacar clientes ajenos.
 */
class MisClientesTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $asesor;

    protected function setUp(): void
    {
        parent::setUp();

        $canal = Canal::create(['nombre' => 'Distribucion']);
        $mia = AsesorSap::create(['codigo_texto' => '14 MONICA RIVERA AREVALO']);
        $ajena = AsesorSap::create(['codigo_texto' => '15 PAOLA ANDREA VARGAS MARIN']);

        Cliente::create([
            'codigo_sn' => 'CN0507', 'nombre' => 'RIVERA TUTA JOSE OSVALDO',
            'ciudad' => 'PAIPA', 'direccion' => 'DIAGONAL 31A 31-65',
            'canal_id' => $canal->id, 'asesor_sap_id' => $mia->id, 'porcentaje_descuento' => 28,
        ]);
        Cliente::create([
            'codigo_sn' => 'CN0491', 'nombre' => 'PUERTAS METALICAS COLOMBIANAS SAS',
            'ciudad' => 'BOGOTA', 'canal_id' => $canal->id, 'asesor_sap_id' => $ajena->id,
        ]);

        $this->asesor = Usuario::create([
            'correo' => 'asesor@segurex.com', 'nombre' => 'Asesor',
            'rol' => 'ASESOR', 'activo' => true,
        ]);
        $this->asesor->asesores()->attach($mia->id);
    }

    public function test_muestra_solo_los_clientes_del_asesor(): void
    {
        Livewire::actingAs($this->asesor)
            ->test('mis-clientes')
            ->assertSee('RIVERA TUTA JOSE OSVALDO')
            ->assertDontSee('PUERTAS METALICAS COLOMBIANAS SAS');
    }

    public function test_busca_por_nombre(): void
    {
        Livewire::actingAs($this->asesor)
            ->test('mis-clientes')
            ->set('buscar', 'RIVERA')
            ->assertSee('RIVERA TUTA JOSE OSVALDO');
    }

    public function test_busca_por_codigo_sn(): void
    {
        Livewire::actingAs($this->asesor)
            ->test('mis-clientes')
            ->set('buscar', 'CN0507')
            ->assertSee('RIVERA TUTA JOSE OSVALDO');
    }

    public function test_busca_por_ciudad(): void
    {
        Livewire::actingAs($this->asesor)
            ->test('mis-clientes')
            ->set('buscar', 'PAIPA')
            ->assertSee('RIVERA TUTA JOSE OSVALDO');
    }

    public function test_la_busqueda_no_alcanza_clientes_ajenos(): void
    {
        // Buscar el nombre exacto de un cliente de otra cartera no lo revela.
        Livewire::actingAs($this->asesor)
            ->test('mis-clientes')
            ->set('buscar', 'PUERTAS METALICAS')
            ->assertDontSee('PUERTAS METALICAS COLOMBIANAS SAS')
            ->assertSee('Ningún cliente coincide');
    }

    public function test_los_clientes_inactivos_no_aparecen(): void
    {
        Cliente::where('codigo_sn', 'CN0507')->update(['activo' => false]);

        Livewire::actingAs($this->asesor)
            ->test('mis-clientes')
            ->assertDontSee('RIVERA TUTA JOSE OSVALDO');
    }

    public function test_un_asesor_sin_clientes_ve_una_invitacion_a_pedir_su_cartera(): void
    {
        $nuevo = Usuario::create([
            'correo' => 'nuevo@segurex.com', 'nombre' => 'Nuevo',
            'rol' => 'ASESOR', 'activo' => true,
        ]);

        Livewire::actingAs($nuevo)
            ->test('mis-clientes')
            ->assertSee('Todavía no tienes clientes asignados');
    }

    public function test_limpiar_devuelve_la_lista_completa(): void
    {
        Livewire::actingAs($this->asesor)
            ->test('mis-clientes')
            ->set('buscar', 'no-existe-nada')
            ->call('limpiar')
            ->assertSet('buscar', '')
            ->assertSee('RIVERA TUTA JOSE OSVALDO');
    }

    public function test_la_pagina_carga_para_un_usuario_con_sesion(): void
    {
        $this->actingAs($this->asesor)
            ->get(route('clientes'))
            ->assertOk()
            ->assertSee('Mis clientes');
    }
}
