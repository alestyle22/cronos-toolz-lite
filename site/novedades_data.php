<?php
/**
 * novedades_data.php — Lo nuevo de cada versión, para la ventana "Qué hay de nuevo" (novedades_lib.php, assets/g7-whatsnew.js).
 * La versión más nueva va primero. Se escribe a mano en cada versión que tenga algo para mostrar a la gente
 * (una versión solo con arreglos internos no hace falta sumarla: la ventana no aparece).
 *
 * Cada diapositiva:
 *   icon   ícono de Font Awesome (fa-…)        color  color principal (#rrggbb)
 *   title  título corto                        text   una o dos frases, en lenguaje de la persona
 *   chips  hasta 3 etiquetas flotantes del dibujo: [ícono, texto]
 *   link   a dónde lleva "Probarlo" (opcional) role   'admin' | 'super' para mostrarla solo a ellos (opcional)
 * Doc: toolz/NOVEDADES.md
 */
return [
    // Transferencia con varias cuentas por moneda, Binance Pay personal y portal de comprobantes (2026-11-24). Solo admins con pagos.
    '1.0.84' => [
        'date' => '2026-11-25',
        'title' => 'Subdepartamentos, estados, un chat a tu gusto y cobros',
        'slides' => [
            ['icon' => 'fa-sitemap', 'color' => '#8b5cf6', 'title' => 'Subdepartamentos y subequipos',
             'text' => 'Un departamento ahora puede tener subdepartamentos, y un equipo, subequipos. Cada uno tiene su jefe, su gente, su configuración y sus propios formularios, que arma su jefe.',
             'chips' => [['fa-user-tie', 'Un jefe por área'], ['fa-file-lines', 'Sus formularios'], ['fa-layer-group', 'Hasta 4 niveles']],
             'link' => '/toolz/trabajo/#solicitudes', 'module' => 'tareas'],
            ['icon' => 'fa-face-smile', 'color' => '#fb923c', 'title' => 'Tu estado y tu insignia',
             'text' => 'Desde tu perfil decí si estás almorzando, en reunión, en el médico o de vacaciones, y hasta cuándo. Se ve en tu foto, y avisa a quien te escribe o te asigna algo.',
             'chips' => [['fa-clock', 'Vuelve solo a Disponible'], ['fa-certificate', 'Insignia en la foto'], ['fa-list', '19 estados']],
             'link' => '/toolz/perfil.php#estado'],
            ['icon' => 'fa-palette', 'color' => '#ec4899', 'title' => 'El chat, a tu gusto',
             'text' => 'Elegí fondo y color de burbujas, y un fondo y un sonido distinto para cada conversación. Las imágenes de la burbuja se abren en grande, y los mensajes llegan más rápido.',
             'chips' => [['fa-image', 'Fondos'], ['fa-bell', 'Sonido propio'], ['fa-magnifying-glass-plus', 'Imágenes en grande']],
             'link' => '/toolz/chat/', 'module' => 'chat'],
            ['icon' => 'fa-music', 'color' => '#14b8a6', 'title' => '53 sonidos para tus avisos',
             'text' => 'Diez estilos para elegir, volumen propio y un sonido para cada tipo de aviso. Están en Mi configuración › Notificaciones.',
             'chips' => [['fa-sliders', 'Volumen'], ['fa-gamepad', '10 estilos'], ['fa-volume-xmark', 'O sin sonido']],
             'link' => '/toolz/user_config.php#notificaciones'],
            ['icon' => 'fa-right-left', 'color' => '#6366f1', 'title' => 'Pasar contenido de una persona a otra',
             'text' => 'Si alguien se va, o si algo quedó a nombre de quien no era, pasá su contenido a otra persona desde Admin › Contenido. Se puede deshacer durante 30 días.',
             'chips' => [['fa-user-slash', 'Usuarios eliminados'], ['fa-list-check', 'Todo o elegido'], ['fa-rotate-left', 'Deshacer']],
             'link' => '/toolz/admin.php#content', 'role' => 'admin'],
            ['icon' => 'fa-building-columns', 'color' => '#0ea5e9', 'title' => 'Varias cuentas para transferir',
             'text' => 'Cargá todas las cuentas bancarias que uses, cada una con su moneda y sus datos completos. Si hay más de una en la misma moneda, el comprador elige a cuál transferir.',
             'chips' => [['fa-coins', 'Una moneda por cuenta'], ['fa-list-check', 'Datos completos'], ['fa-globe', 'Pagos del exterior']],
             'link' => '/toolz/pagos/admin/#medios', 'module' => 'pagos', 'role' => 'admin'],
            ['icon' => 'fa-file-invoice-dollar', 'color' => '#22c55e', 'title' => 'Comprobantes para aprobar',
             'text' => 'Quien paga por transferencia sube el comprobante. En Finanzas y pagos › Comprobantes los ves en grande, filtrás por cuenta o moneda y aprobás o rechazás cada pago.',
             'chips' => [['fa-upload', 'Comprobante obligatorio'], ['fa-filter', 'Filtros'], ['fa-check', 'Aprobación a mano']],
             'link' => '/toolz/pagos/admin/#transferencias', 'module' => 'pagos', 'role' => 'admin'],
            ['icon' => 'fa-wallet', 'color' => '#c99400', 'title' => 'Binance Pay con tu cuenta personal',
             'text' => 'Un medio nuevo para cobrar en USDT a tu cuenta de Binance sin la API de comercios: el comprador envía, sube la captura y vos lo aprobás.',
             'chips' => [['fa-dollar-sign', 'USDT'], ['fa-image', 'Captura'], ['fa-user-check', 'Con revisión']],
             'link' => '/toolz/pagos/admin/#medios', 'module' => 'pagos', 'role' => 'admin'],
        ],
    ],
    // Blog Studio + Your Web (solo admins de entrada) y Mockup Studio, Paletas y marco de la foto para todos (2026-11-18).
    '1.0.81' => [
        'date' => '2026-11-18',
        'title' => 'Blog Studio, Your Web, Mockup Studio y Paletas',
        'slides' => [
            ['icon' => 'fa-feather-pointed', 'color' => '#a855f7', 'title' => 'Nuevo: Blog Studio',
             'text' => 'Armá los blogs de cada marca o cliente: redacción en equipo, revisión, SEO, diseño propio y publicación programada, con ayuda de la IA.',
             'chips' => [['fa-pen-nib', 'Editor en equipo'], ['fa-magnifying-glass-chart', 'SEO'], ['fa-calendar-check', 'Programar']],
             'link' => '/toolz/blog/', 'role' => 'admin'],
            ['icon' => 'fa-globe', 'color' => '#10b981', 'title' => 'Nuevo: Your Web',
             'text' => 'Juntá landings, mailings, el blog y la tienda de cada marca en una sola web, con su dominio propio y las visitas sumadas.',
             'chips' => [['fa-link', 'Dominio propio'], ['fa-layer-group', 'Todo en un lugar'], ['fa-chart-line', 'Visitas']],
             'link' => '/toolz/yourweb/', 'role' => 'admin'],
            ['icon' => 'fa-layer-group', 'color' => '#06b6d4', 'title' => 'Mockup Studio y Paletas',
             'text' => 'Poné tus diseños sobre productos, pantallas, carteles y ropa, y armá paletas de color sabiendo qué transmite cada color.',
             'chips' => [['fa-shirt', 'Plantillas'], ['fa-palette', 'Paletas'], ['fa-share-nodes', 'Compartir']],
             'link' => '/toolz/mockups/'],
            ['icon' => 'fa-circle-user', 'color' => '#f97316', 'title' => 'Un marco para tu foto',
             'text' => 'Elegí un marco para tu foto de perfil: se ve en la barra, el chat, tu perfil y la comunidad. Cambialo en Mi configuración › Mi perfil.',
             'chips' => [['fa-border-all', 'Marcos'], ['fa-comments', 'En el chat'], ['fa-user', 'En tu perfil']],
             'link' => '/toolz/user_config.php'],
        ],
    ],
    // Profile Studio dentro de Social Studio (2026-11-18).
    '1.0.79' => [
        'date' => '2026-11-18',
        'title' => 'Profile Studio: tus fotos de perfil',
        'slides' => [
            ['icon' => 'fa-circle-user', 'color' => '#ec4899', 'title' => 'Nuevo: Profile Studio',
             'text' => 'Armá fotos de perfil para WhatsApp, Instagram, Facebook y más: subí tu foto, sacale el fondo y sumale fondos, marcos y efectos, o empezá de una de las 21 plantillas.',
             'chips' => [['fa-scissors', 'Quitar fondo'], ['fa-border-all', 'Plantillas'], ['fa-download', 'Medida de cada red']],
             'link' => '/toolz/social/perfiles/', 'role' => 'admin'],
            ['icon' => 'fa-wand-magic-sparkles', 'color' => '#8b5cf6', 'title' => 'Tu foto, en 27 estilos con IA',
             'text' => 'Convertí tu foto en estilo anime, 3D, cómic o profesional, manteniendo tu cara. Después usala como tu foto en Cronos o mandala a una publicación.',
             'chips' => [['fa-face-smile', 'Mantiene tu cara'], ['fa-users', 'Galería de la agencia'], ['fa-paperclip', 'A una publicación']],
             'link' => '/toolz/social/perfiles/', 'role' => 'admin'],
        ],
    ],
    // Catálogo Cronos en Biblioteca + conexión central de Social Studio (2026-11-18).
    '1.0.78' => [
        'date' => '2026-11-18',
        'title' => 'Catálogo Cronos y redes sin crear apps',
        'slides' => [
            ['icon' => 'fa-toolbox', 'color' => '#ff6a1a', 'title' => 'Nuevo: Catálogo Cronos en Biblioteca',
             'text' => 'Más de 150 herramientas listas para usar: calculadoras, checklists, plantillas, generadores y conversores. Elegí las que quieras y quedan en tu Biblioteca, con el estilo de Cronos.',
             'chips' => [['fa-calculator', 'Calculadoras'], ['fa-list-check', 'Checklists'], ['fa-file-lines', 'Plantillas']],
             'link' => '/toolz/links/'],
            ['icon' => 'fa-tower-broadcast', 'color' => '#ec4899', 'title' => 'Social Studio: conectá tus redes sin crear apps',
             'text' => 'Con la conexión central de Cronos, tus cuentas de Facebook, Instagram, TikTok, LinkedIn, X y YouTube se conectan con las apps de Cronos. Se activa en Social Studio › Cuentas.',
             'chips' => [['fa-link', 'Un código y listo'], ['fa-lock', 'Permisos cifrados'], ['fa-share-nodes', '6 redes']],
             'link' => '/toolz/social/#cuentas', 'role' => 'admin'],
        ],
    ],
    // Social Studio + Tablero de informes de Trabajo (2026-11-17). Social Studio arranca solo para admins (lo abren desde Módulos y permisos).
    '1.0.77' => [
        'date' => '2026-11-17',
        'title' => 'Social Studio y nuevos informes de trabajo',
        'slides' => [
            ['icon' => 'fa-share-nodes', 'color' => '#ec4899', 'title' => 'Nuevo: Social Studio',
             'text' => 'Planifica las publicaciones de cada marca o cliente en un calendario, conviértelas en tickets para diseño o video y publícalas en Facebook, Instagram, TikTok, LinkedIn, X y YouTube.',
             'chips' => [['fa-calendar-days', 'Calendario editorial'], ['fa-ticket', 'Convertir en ticket'], ['fa-paper-plane', 'Publicar en las redes']],
             'link' => '/toolz/social/', 'role' => 'admin'],
            ['icon' => 'fa-inbox', 'color' => '#8b5cf6', 'title' => 'Mensajes, métricas e informes de redes',
             'text' => 'Responde mensajes y comentarios en equipo desde un solo Inbox, mira las métricas de todas las cuentas y arma informes en PDF para tus clientes.',
             'chips' => [['fa-comments', 'Inbox en equipo'], ['fa-chart-line', 'Métricas'], ['fa-file-pdf', 'Informes en PDF']],
             'link' => '/toolz/social/#analytics', 'role' => 'admin'],
            ['icon' => 'fa-chart-pie', 'color' => '#0ea5e9', 'title' => 'Tablero de informes en Trabajo',
             'text' => 'Los informes de Gestión de trabajo ahora muestran entregas, plazos, personas y tiempos como en Estadísticas, con filtros y exportación.',
             'chips' => [['fa-filter', 'Filtros'], ['fa-clock', 'Plazos y tiempos'], ['fa-file-export', 'Exportar']],
             'link' => '/toolz/trabajo/informes.php'],
        ],
    ],
    // Cronos en el celular (2026-11-17). La app de Android se anuncia recién cuando se pueda descargar.
    '1.0.76' => [
        'date' => '2026-11-17',
        'title' => 'Cronos en tu celular',
        'slides' => [
            ['icon' => 'fa-mobile-screen-button', 'color' => '#ff6a1a', 'title' => 'Todo Cronos, cómodo en el celular',
             'text' => 'Las pantallas se acomodan al teléfono: los menús se deslizan, las listas usan todo el ancho y nada queda cortado. Los editores muestran un panel por vez.',
             'chips' => [['fa-hand-pointer', 'Menús que se deslizan'], ['fa-table-columns', 'Un panel por vez'], ['fa-expand', 'Nada cortado']]],
            ['icon' => 'fa-cart-shopping', 'color' => '#22c55e', 'title' => 'La tienda cobra en línea',
             'text' => 'Si tienes un medio de pago en línea activo, el comprador va directo a pagar y el pedido se confirma solo. La transferencia queda como última opción.',
             'chips' => [['fa-credit-card', 'Pago en línea primero'], ['fa-circle-check', 'Se confirma solo'], ['fa-building-columns', 'Transferencia al final']],
             'link' => '/toolz/tienda/admin/', 'role' => 'admin'],
        ],
    ],
    // Afiliados + medios de pago (2026-11-16, sin publicar). Si sale con otro número de versión, cambiar la clave.
    '1.0.75' => [
        'date' => '2026-11-16',
        'title' => 'Recomienda Cronos y cobra de más formas',
        'slides' => [
            ['icon' => 'fa-handshake', 'color' => '#ff6a1a', 'title' => 'Nuevo: Recomienda Cronos',
             'text' => 'Comparte tu link con otras agencias. Cuando una empieza a usar Cronos, ganas un porcentaje de su primer pago: lo descuentas de tu plan o lo cobras.',
             'chips' => [['fa-link', 'Tu link'], ['fa-percent', 'Del primer pago'], ['fa-tags', 'Descuento en tu plan']], 'role' => 'admin'],
            ['icon' => 'fa-wallet', 'color' => '#22c55e', 'title' => 'Más medios de pago',
             'text' => 'Pago Móvil, Zelle y Zinli para Venezuela; USDT y Binance Pay; dLocal Go para Uruguay y la región; tarjeta y Bizum con Redsys en España. Se prenden en Finanzas y pagos.',
             'chips' => [['fa-mobile-screen-button', 'Pago Móvil'], ['fa-coins', 'USDT'], ['fa-globe', 'dLocal Go']], 'link' => '/toolz/pagos/admin/#medios', 'role' => 'admin'],
            ['icon' => 'fa-circle-check', 'color' => '#0ea5e9', 'title' => 'Pagos que se confirman solos',
             'text' => 'Si Mercado Pago o Stripe no avisan a tiempo, Toolz les pregunta y confirma el pago: ya no queda "Verificando" cuando la plata ya entró.',
             'chips' => [['fa-rotate', 'Consulta automática'], ['fa-shield-halved', 'Sin cobros dobles'], ['fa-money-bill-wave', 'Bolívar y más monedas']], 'module' => 'pagos', 'role' => 'admin'],
        ],
    ],
    '1.0.74' => [
        'date' => '2026-11-16',
        'title' => 'Tu cuenta, más segura',
        'slides' => [
            ['icon' => 'fa-spa', 'color' => '#22c55e', 'title' => 'Nuevo: Bienestar',
             'text' => 'Cuenta cómo estás, suma hábitos saludables, súmate a retos del equipo, agradece a tus compañeros y pide tus vacaciones. Lo tuyo es privado.',
             'chips' => [['fa-seedling', 'Hábitos'], ['fa-person-running', 'Retos'], ['fa-award', 'Reconocimientos']],
             'link' => '/toolz/bienestar/'],
            ['icon' => 'fa-location-dot', 'color' => '#ff6a1a', 'title' => 'Dónde iniciaste sesión',
             'text' => 'Mira en qué dispositivos tienes la cuenta abierta, desde dónde entraste y ciérrala a distancia en el que no reconozcas.',
             'chips' => [['fa-laptop', 'Chrome en Windows'], ['fa-map-pin', 'Córdoba, Argentina'], ['fa-circle', 'Activa ahora']],
             'link' => '/toolz/user_config.php#sesiones'],
            ['icon' => 'fa-envelope-circle-check', 'color' => '#22c55e', 'title' => '"Fui yo" o "No fui yo", desde el email',
             'text' => 'Si alguien entra desde un dispositivo o un país nuevo, te avisamos. Con "No fui yo" cerramos esa sesión al instante, sin que tengas que entrar.',
             'chips' => [['fa-check', 'Fui yo'], ['fa-xmark', 'No fui yo'], ['fa-bell', 'Ingreso nuevo']]],
            ['icon' => 'fa-shield-halved', 'color' => '#60a5fa', 'title' => 'Recordar este dispositivo',
             'text' => 'Con la verificación en dos pasos, marca tu computadora o tu teléfono y no te pedimos el código por 30 días.',
             'chips' => [['fa-mobile-screen', 'Mi teléfono'], ['fa-calendar-check', '30 días'], ['fa-key', 'Sin código']],
             'link' => '/toolz/user_config.php#seguridad'],
            ['icon' => 'fa-lock', 'color' => '#ef4444', 'title' => 'Botón de emergencia',
             'text' => '¿Crees que alguien tiene tu contraseña? "Bloquear mi cuenta" cierra todo y nadie entra hasta que elijas una nueva.',
             'chips' => [['fa-power-off', 'Todo cerrado'], ['fa-envelope', 'Email para volver'], ['fa-user-shield', 'Protegida']],
             'link' => '/toolz/user_config.php#sesiones'],
            ['icon' => 'fa-envelope-open-text', 'color' => '#a78bfa', 'title' => 'Admin › Mails',
             'text' => 'Cambia los textos y el diseño de los emails que reciben las personas (avisos de seguridad, tareas y tickets) y mira qué se mandó.',
             'chips' => [['fa-pen', 'Textos'], ['fa-palette', 'Diseño'], ['fa-paper-plane', 'Enviados']],
             'link' => '/toolz/admin.php#mails', 'role' => 'admin'],
            ['icon' => 'fa-map-location-dot', 'color' => '#f59e0b', 'title' => 'Ingresos del equipo',
             'text' => 'Mira los intentos fallidos de todo el equipo, bloquea una IP o un país y cierra las sesiones de quien deja la empresa.',
             'chips' => [['fa-triangle-exclamation', 'Intentos fallidos'], ['fa-ban', 'Bloquear IP'], ['fa-users', 'Equipo']],
             'link' => '/toolz/user_config.php#sesiones', 'role' => 'super'],
        ],
    ],
];
