<?php
/**
 * Opciones de Promos que también usa Estadísticas (Producción y el reporte del mes).
 * Se guarda la clave en la base; el texto se puede cambiar acá sin tocar los datos.
 */

// Complicaciones que se marcan al cargar un pedido (g7_content.complications)
if (!defined('PR_COMPLICATIONS')) define('PR_COMPLICATIONS', [
    'brief'          => 'Brief poco claro',
    'materiales'     => 'Faltaban materiales',
    'copys'          => 'Faltaban copys',
    'medidas'        => 'No indicaba medidas',
    'plazo'          => 'Plazo de entrega muy corto',
    'cambios'        => 'Cambios de último momento',
    'correcciones'   => 'Muchas rondas de correcciones',
    'contradictorio' => 'Indicaciones contradictorias',
    'calidad'        => 'Materiales en baja calidad',
    'aprobacion'     => 'Demora en la aprobación',
    'referencias'    => 'Sin referencias visuales',
    'otro'           => 'Otro',
]);

// Motivos de una revisión (el cliente pidió cambios) o de un rechazo (g7_content.edit_reasons)
if (!defined('PR_EDIT_REASONS')) define('PR_EDIT_REASONS', [
    'revision' => [
        'textos'   => 'Cambios de textos o copys',
        'datos'    => 'Datos incorrectos (montos, fechas, premios)',
        'colores'  => 'Colores o estilo',
        'imagenes' => 'Imágenes o personajes',
        'logos'    => 'Logos o marcas',
        'medidas'  => 'Medidas o formatos',
        'legales'  => 'Legales o términos',
        'idea'     => 'Cambió la idea del pedido',
        'error'    => 'Error nuestro de diseño',
        'otro'     => 'Otro',
    ],
    'rechazo' => [
        'brief'      => 'No respetaba el brief',
        'gusto'      => 'No gustó la propuesta visual',
        'info'       => 'Información incorrecta',
        'calidad'    => 'Calidad insuficiente',
        'marca'      => 'No respetaba la identidad de marca',
        'plazo'      => 'Llegó fuera de plazo',
        'cancelada'  => 'Se canceló la campaña',
        'estrategia' => 'Cambio de estrategia',
        'otro'       => 'Otro',
    ],
]);
