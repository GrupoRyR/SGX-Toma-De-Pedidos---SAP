<?php

namespace Database\Seeders;

use App\Models\Canal;
use App\Models\Usuario;
use App\Models\UsuarioPermiso;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Usuarios iniciales confirmados por SEGUREX.
 *
 * Esta lista es solo la semilla: despues se administra desde la aplicacion. No
 * debe existir ningun correo escrito en el codigo de la logica de permisos.
 *
 * Reglas aplicadas aqui:
 *  - ADMIN_VENTAS ya incluye administrar la plataforma.
 *  - TI = ADMIN_VENTAS + configuracion del sistema e integracion con SAP.
 *  - LIBERAR_SAP (el visto bueno sin el cual nada llega a SAP) lo tienen
 *    unicamente Cesar Garzon y Marly Ossa. Ni siquiera TI.
 *  - mauricio.cortes ya no continua: no se crea.
 */
class UsuariosSeeder extends Seeder
{
    /** @var array<int, array{correo: string, nombre: string, rol: string, canal?: string}> */
    private array $usuarios = [
        ['correo' => 'cesar.garzon@segurex.com',    'nombre' => 'Cesar Garzon',      'rol' => 'ADMIN_VENTAS'],
        ['correo' => 'marly.ossa@segurex.com',      'nombre' => 'Marly Ossa',        'rol' => 'ADMIN_VENTAS'],
        ['correo' => 'jhon.castro@segurex.com',     'nombre' => 'Jhon Castro',       'rol' => 'TI'],
        ['correo' => 'leonardo.herrera@segurex.com', 'nombre' => 'Leonardo Herrera',  'rol' => 'TI'],
        ['correo' => 'segurex.info@segurex.com',    'nombre' => 'Cuenta de servicio', 'rol' => 'TI'],

        ['correo' => 'jessika.quintero@segurex.com', 'nombre' => 'Jessika Quintero',  'rol' => 'GERENTE_CANAL', 'canal' => 'Distribucion'],
        ['correo' => 'andrea.hincapie@segurex.com', 'nombre' => 'Andrea Hincapie',   'rol' => 'GERENTE_CANAL', 'canal' => 'Cons. Residencial'],

        ['correo' => 'distribucion.boyaca@segurex.com',     'nombre' => 'Distribucion Boyaca',      'rol' => 'ASESOR'],
        ['correo' => 'paola.vargas@segurex.com',            'nombre' => 'Paola Vargas',             'rol' => 'ASESOR'],
        ['correo' => 'construccionantioquia@segurex.com',   'nombre' => 'Construccion Antioquia',   'rol' => 'ASESOR'],
        ['correo' => 'carolina.ramirez@segurex.com',        'nombre' => 'Carolina Ramirez',         'rol' => 'ASESOR'],
        ['correo' => 'maria.uribe@segurex.com',             'nombre' => 'Maria Uribe',              'rol' => 'ASESOR'],
        ['correo' => 'lina.chacon@segurex.com',             'nombre' => 'Lina Chacon',              'rol' => 'ASESOR'],
        ['correo' => 'jhony.tordecilla@segurex.com',        'nombre' => 'Jhony Tordecilla',         'rol' => 'ASESOR'],
        ['correo' => 'maria.pena@segurex.com',              'nombre' => 'Maria Pena',               'rol' => 'ASESOR'],
        ['correo' => 'harold.salazar@segurex.com',          'nombre' => 'Harold Salazar',           'rol' => 'ASESOR'],
        ['correo' => 'cristina.rodriguez@segurex.com',      'nombre' => 'Cristina Rodriguez',       'rol' => 'ASESOR'],
        ['correo' => 'jenny.buitrago@segurex.com',          'nombre' => 'Jenny Buitrago',           'rol' => 'ASESOR'],
        ['correo' => 'adriana.russi@segurex.com',           'nombre' => 'Adriana Russi',            'rol' => 'ASESOR'],
        ['correo' => 'distribucion.norte@segurex.com',      'nombre' => 'Distribucion Norte',       'rol' => 'ASESOR'],
        ['correo' => 'luis.daza@segurex.com',               'nombre' => 'Luis Daza',                'rol' => 'ASESOR'],
        ['correo' => 'distribucion.occidente1@segurex.com', 'nombre' => 'Distribucion Occidente 1', 'rol' => 'ASESOR'],
    ];

    /** Unicos con el visto bueno para SAP. */
    private array $puedenLiberar = [
        'cesar.garzon@segurex.com',
        'marly.ossa@segurex.com',
    ];

    public function run(): void
    {
        DB::transaction(function () {
            foreach ($this->usuarios as $fila) {
                $usuario = Usuario::updateOrCreate(
                    ['correo' => strtolower($fila['correo'])],
                    ['nombre' => $fila['nombre'], 'rol' => $fila['rol'], 'activo' => true],
                );

                if (isset($fila['canal'])) {
                    $canal = Canal::where('nombre', $fila['canal'])->first();
                    if ($canal) {
                        $usuario->canales()->syncWithoutDetaching([$canal->id]);
                    }
                }
            }

            foreach ($this->puedenLiberar as $correo) {
                $usuario = Usuario::where('correo', $correo)->first();
                if ($usuario) {
                    UsuarioPermiso::updateOrCreate(
                        ['usuario_id' => $usuario->id, 'permiso' => 'LIBERAR_SAP'],
                        ['vigente_desde' => null, 'vigente_hasta' => null],
                    );
                }
            }
        });

        $this->command->info('Usuarios sembrados: '.count($this->usuarios));
        $this->command->warn('LIBERAR_SAP otorgado solo a: '.implode(', ', $this->puedenLiberar));
    }
}
