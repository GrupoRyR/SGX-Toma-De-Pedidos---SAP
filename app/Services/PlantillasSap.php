<?php

namespace App\Services;

use App\Enums\EstadoPedido;
use App\Models\Pedido;
use App\Models\Usuario;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Plantillas de carga manual a SAP (DTW).
 *
 * Reemplaza las tres listas de SharePoint que la app vieja llenaba al aprobar
 * ('ENCABEZADO PEDIDOS SAP', 'DETALLE PEDIDO SAP' y 'PLANTILLA DIRECCIONES'),
 * y genera los mismos campos, con los mismos nombres y en el mismo orden. Eso
 * es deliberado: quien hoy importa en DTW no tiene que aprender nada nuevo ni
 * volver a mapear columnas.
 *
 * Dos diferencias con la app vieja, las dos a favor:
 *  - Las plantillas se generan al **liberar**, no al aprobar. Un pedido
 *    aprobado sin el visto bueno de Cesar o Marly no aparece aqui.
 *  - Nada se borra despues. La app vieja vaciaba las listas tras importar, asi
 *    que no quedaba forma de saber que se mando. Aqui el pedido queda marcado
 *    como IMPORTADO, con quien lo marco y cuando.
 *
 * Los archivos son de texto separado por tabuladores, que es el formato de las
 * plantillas de DTW y ademas abre derecho en Excel sin importar si el separador
 * de listas del equipo es coma o punto y coma.
 */
class PlantillasSap
{
    /** Separador de las plantillas de DTW. */
    private const TAB = "\t";

    public function __construct(private Bitacorero $bitacora) {}

    /**
     * Pedidos aprobados que todavia no entraron a SAP.
     *
     * No se exige el visto bueno. Ese candado existia para frenar al robot,
     * que carga solo y sin que nadie mire; aqui la carga la hace una persona
     * que ve la lista completa antes de descargar, asi que el segundo paso no
     * agregaba control, solo trabajo. Los que ya tienen visto bueno tambien
     * aparecen: siguen pendientes de entrar a SAP.
     */
    public function pendientes(): Collection
    {
        return Pedido::query()
            ->paraPlantillas()
            ->with(['lineas', 'aprobador:id,nombre'])
            ->orderBy('fecha_aprobacion')
            ->get();
    }

    /**
     * Pedidos que traen alguna linea con precio escrito a mano.
     *
     * La plantilla manda el ATP como `DiscountPercent` y no tiene donde poner
     * un precio manual, asi que SAP le aplica al item el descuento del cliente
     * y llega a otro numero distinto del que se aprobo.
     *
     * SEGUREX decidio (2026-09-28) no mandar el precio y corregir esas lineas
     * a mano en SAP, igual que se hace hoy con la app vieja. Entonces el unico
     * trabajo de la web es decir cuales son, antes de importar y no despues de
     * facturar. Si algun dia se decide mandarlo, va como columna `Price` en
     * detalles().
     */
    public function conPrecioManual(Collection $pedidos): Collection
    {
        return $pedidos->filter(
            fn (Pedido $pedido) => $pedido->lineas->contains(fn ($linea) => $linea->precio_manual !== null)
        )->values();
    }

    /** Las tres plantillas, como [nombre de archivo => contenido]. */
    public function archivos(Collection $pedidos): array
    {
        return [
            'ENCABEZADO_PEDIDOS_SAP.txt' => $this->encabezados($pedidos),
            'DETALLE_PEDIDO_SAP.txt' => $this->detalles($pedidos),
            'PLANTILLA_DIRECCIONES.txt' => $this->direcciones($pedidos),
        ];
    }

    private function encabezados(Collection $pedidos): string
    {
        $filas = $pedidos->map(fn (Pedido $pedido) => [
            'DocNum' => $pedido->id,
            'CardCode' => $pedido->codigo_cliente,
            'DocDate' => $pedido->created_at?->format('Y-m-d'),
            'DocDueDate' => $pedido->fecha_facturacion?->format('Y-m-d'),
            // La app vieja manda la fecha de creacion en DocTime, no una hora.
            // Se replica tal cual: cambiarlo aqui es cambiar lo que entra a SAP.
            'DocTime' => $pedido->created_at?->format('Y-m-d'),
            'NumAtCard' => $pedido->orden_compra,
            'Comments' => $pedido->observaciones,
            'U_SGX_IdPedidoApp' => $pedido->id,
        ]);

        return $this->comoTexto($filas);
    }

    private function detalles(Collection $pedidos): string
    {
        $filas = collect();

        foreach ($pedidos as $pedido) {
            // Las lineas van numeradas 0..n-1 en el orden en que se capturaron.
            foreach ($pedido->lineas->sortBy('linea_num')->values() as $numero => $linea) {
                $filas->push([
                    'ParentKey' => $pedido->id,
                    'LineNum' => $numero,
                    'ItemCode' => $linea->codigo_producto,
                    'Quantity' => $this->numero($linea->cantidad),
                    'DiscountPercent' => $this->numero($linea->atp_descuento_pct ?? 0),
                ]);
            }
        }

        return $this->comoTexto($filas);
    }

    private function direcciones(Collection $pedidos): string
    {
        $filas = $pedidos
            ->filter(fn (Pedido $pedido) => filled($pedido->direccion_2))
            ->map(fn (Pedido $pedido) => [
                'DocEntry' => $pedido->id,
                // En mayusculas, como las escribe la app vieja.
                'ShipToStreet' => mb_strtoupper((string) $pedido->direccion_2),
                'ShipToCity' => mb_strtoupper((string) $pedido->ciudad_2),
            ])
            ->values();

        return $this->comoTexto($filas, ['DocEntry', 'ShipToStreet', 'ShipToCity']);
    }

    /**
     * Arma el archivo.
     *
     * **El encabezado va dos veces, repetido igual.** Asi son las plantillas de
     * DTW: toma las dos primeras lineas como encabezado. Con una sola, DTW se
     * come la primera fila de datos creyendo que es la segunda linea del
     * encabezado, y el primer pedido de cada tanda se pierde sin que nada
     * avise. No es un adorno del formato: es la diferencia entre que entren
     * todos los pedidos o todos menos uno.
     *
     * Lleva encabezado aunque no haya filas: un archivo vacio de verdad se ve
     * como un error, y uno con solo el encabezado dice "no habia nada que
     * mandar", que es distinto.
     */
    private function comoTexto(Collection $filas, array $columnas = []): string
    {
        $columnas = $columnas ?: array_keys($filas->first() ?? []);

        if ($columnas === []) {
            return '';
        }

        $encabezado = implode(self::TAB, $columnas);
        $lineas = [$encabezado, $encabezado];

        foreach ($filas as $fila) {
            $lineas[] = collect($columnas)
                ->map(fn (string $columna) => $this->limpiar($fila[$columna] ?? ''))
                ->implode(self::TAB);
        }

        return implode("\r\n", $lineas)."\r\n";
    }

    /**
     * Deja el valor apto para un archivo separado por tabuladores.
     *
     * Un tabulador o un salto de linea dentro de las observaciones correria las
     * columnas del resto del archivo, y el error aparaceria en SAP como datos
     * en el campo equivocado.
     */
    private function limpiar(mixed $valor): string
    {
        return trim(preg_replace('/[\t\r\n]+/u', ' ', (string) $valor));
    }

    /** Numero con punto decimal y sin ceros de relleno, como lo espera DTW. */
    private function numero(mixed $valor): string
    {
        $numero = (float) $valor;

        return rtrim(rtrim(number_format($numero, 3, '.', ''), '0'), '.') ?: '0';
    }

    /**
     * Marca los pedidos como importados despues de que DTW los cargo.
     *
     * Es un paso aparte y manual a proposito: descargar no es importar. Entre
     * una cosa y otra DTW puede rechazar el archivo, y marcarlos al descargar
     * dejaria pedidos que nadie volveria a mirar porque la web los da por
     * puestos en SAP.
     */
    public function marcarImportados(array $ids, Usuario $usuario): int
    {
        if ($ids === []) {
            throw new RuntimeException('Elige al menos un pedido.');
        }

        return DB::transaction(function () use ($ids, $usuario) {
            $pedidos = Pedido::whereIn('id', $ids)
                ->paraPlantillas()
                ->lockForUpdate()
                ->get();

            if ($pedidos->isEmpty()) {
                throw new RuntimeException('Esos pedidos ya no estan esperando carga: alguien los marco antes.');
            }

            foreach ($pedidos as $pedido) {
                $pedido->forceFill([
                    'estado' => EstadoPedido::IMPORTADO,
                    'importado_sap' => true,
                    'fecha_importacion' => now(),
                    'sap_error' => null,
                ])->save();

                $this->bitacora->registrar('IMPORTAR_SAP', 'pedido', $pedido->id, [
                    'via' => 'plantillas DTW',
                    'marcado_por' => $usuario->correo,
                ]);
            }

            return $pedidos->count();
        });
    }

    /**
     * Deja constancia de quien se llevo las plantillas y con que pedidos.
     *
     * Ademas marca la hora en cada pedido. Eso es lo que despues permite
     * avisar "este cambio despues de que lo descargaste": el archivo que
     * alguien tiene en la mano puede haber quedado viejo.
     */
    public function registrarDescarga(Collection $pedidos, Usuario $usuario): void
    {
        $momento = now();

        foreach ($pedidos as $pedido) {
            Pedido::whereKey($pedido->getKey())->update([
                'plantillas_descargadas_en' => $momento,
                'version_al_descargar' => $pedido->version,
            ]);
        }

        $this->bitacora->registrar('DESCARGAR_PLANTILLAS', 'pedido', null, [
            'pedidos' => $pedidos->pluck('id')->all(),
            'cuantos' => $pedidos->count(),
            'descargado_por' => $usuario->correo,
        ]);
    }
}
