<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Aplicacion instalable.
 *
 * Alcance reducido por decision de SEGUREX: se instala y abre rapido, pero no
 * guarda catalogo ni crea pedidos sin conexion.
 */
class PwaTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_manifiesto_existe_y_apunta_a_los_iconos(): void
    {
        $ruta = public_path('pwa/manifest.webmanifest');

        $this->assertFileExists($ruta);

        $manifiesto = json_decode(file_get_contents($ruta), true);

        $this->assertSame('Pedidos SEGUREX', $manifiesto['name']);
        $this->assertSame('standalone', $manifiesto['display']);

        foreach ($manifiesto['icons'] as $icono) {
            $this->assertFileExists(public_path(ltrim($icono['src'], '/')));
        }
    }

    public function test_hay_un_icono_maskable(): void
    {
        // Sin el, Android recorta el icono y se lleva parte del dibujo.
        $manifiesto = json_decode(file_get_contents(public_path('pwa/manifest.webmanifest')), true);

        $maskables = array_filter($manifiesto['icons'], fn ($i) => ($i['purpose'] ?? '') === 'maskable');

        $this->assertNotEmpty($maskables);
    }

    public function test_la_pantalla_de_sin_conexion_es_publica(): void
    {
        // El service worker tiene que poder cachearla antes de que nadie entre.
        $this->get(route('sin-conexion'))
            ->assertOk()
            ->assertSee('Sin conexión');
    }

    public function test_el_service_worker_se_registra_con_la_version_del_build(): void
    {
        /*
         * Sin el `?v=`, un despliegue nuevo deja a los asesores con la interfaz
         * vieja en el cache y sin forma de enterarse. El valor es el hash del
         * manifiesto de Vite, asi que cambia solo.
         */
        $usuario = Usuario::create([
            'correo' => 'leonardo.herrera@segurex.com', 'nombre' => 'Leonardo',
            'rol' => 'TI', 'activo' => true,
        ]);

        $this->actingAs($usuario)
            ->get(route('clientes'))
            ->assertSee("serviceWorker.register('/sw.js?v=", escape: false);
    }

    public function test_el_service_worker_toma_la_version_de_su_propia_url(): void
    {
        $sw = file_get_contents(public_path('sw.js'));

        $this->assertStringContainsString("searchParams.get('v')", $sw);
    }

    public function test_el_service_worker_no_cachea_datos(): void
    {
        /*
         * La regla que no se puede romper: nada de pedidos ni de sesion en el
         * cache, y ninguna escritura respondida desde alli. El numero de pedido
         * lo asigna siempre el servidor.
         */
        $sw = file_get_contents(public_path('sw.js'));

        $this->assertStringContainsString("peticion.method !== 'GET'", $sw);
        $this->assertStringContainsString("startsWith('/api/')", $sw);
    }
}
