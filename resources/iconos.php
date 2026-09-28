<?php
/*
 * Icono de Pedidos SEGUREX.
 *
 * Un pasador echado cruzando su placa: es literalmente lo que fabrica la
 * empresa, y a 48 pixeles sigue leyendose como una forma y no como un
 * logotipo apretado.
 */
function barraRedondeada($img, int $x1, int $y1, int $x2, int $y2, int $color): void
{
    $alto = $y2 - $y1;
    $r = (int) ($alto / 2);
    imagefilledrectangle($img, $x1 + $r, $y1, $x2 - $r, $y2, $color);
    imagefilledellipse($img, $x1 + $r, $y1 + $r, $alto, $alto, $color);
    imagefilledellipse($img, $x2 - $r, $y1 + $r, $alto, $alto, $color);
}

function icono(int $lado, bool $maskable, string $destino): void
{
    // Se dibuja al cuadruple y se reduce: GD no suaviza los bordes de las
    // formas rellenas, y sin esto salen dentados.
    $s = $lado * 4;
    $img = imagecreatetruecolor($s, $s);

    $grafito = imagecolorallocate($img, 0x23, 0x27, 0x2b);
    $naranja = imagecolorallocate($img, 0xf6, 0x58, 0x10);
    $niquel = imagecolorallocate($img, 0xd6, 0xd9, 0xdd);

    imagefilledrectangle($img, 0, 0, $s, $s, $grafito);

    // En un icono maskable el sistema recorta los bordes: el dibujo se queda
    // dentro del centro para que no lo corten.
    $margen = (int) ($s * ($maskable ? 0.26 : 0.18));
    $util = $s - 2 * $margen;

    $grosor = (int) ($util * 0.30);
    $medio = (int) ($s / 2);
    $arriba = $medio - (int) ($grosor / 2);
    $abajo = $arriba + $grosor;

    $placaAncho = (int) ($util * 0.24);
    $placaAlto = (int) ($util * 0.92);
    $placaDerecha = $margen + $placaAncho;

    // La placa primero: el pasador se apoya en ella, no la atraviesa por
    // detras. Al reves, el extremo redondo del pasador asoma por el lado
    // equivocado y parece un error de dibujo.
    imagefilledrectangle($img, $margen, $medio - (int) ($placaAlto / 2),
        $placaDerecha, $medio + (int) ($placaAlto / 2), $niquel);

    /*
     * El pasador: primero el tramo recto, que arranca dentro de la ranura y se
     * pasa del borde de la placa, y encima la barra con el extremo redondo. El
     * traslape es a proposito: si las dos piezas solo se tocaran, cualquier
     * redondeo al reducir la imagen dejaria una rendija oscura en la union.
     */
    imagefilledrectangle($img, $margen + (int) ($placaAncho * 0.42), $arriba,
        $placaDerecha + $grosor, $abajo, $naranja);

    barraRedondeada($img, $placaDerecha, $arriba, $margen + $util, $abajo, $naranja);

    $final = imagecreatetruecolor($lado, $lado);
    imagecopyresampled($final, $img, 0, 0, 0, 0, $lado, $lado, $s, $s);
    imagepng($final, $destino);
    imagedestroy($img);
    imagedestroy($final);
}

$destino = $argv[1];
icono(192, false, $destino.'/icono-192.png');
icono(512, false, $destino.'/icono-512.png');
icono(512, true, $destino.'/icono-maskable-512.png');
icono(180, false, $destino.'/apple-touch-icon.png');
echo "listo\n";
