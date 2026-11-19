# Trabajo: ticket = tarea, Tickets, avisos y panel de solicitudes

Pedido del usuario (2026-11-10). La sección es **Gestión de trabajo** (`site/trabajo/`). **Publicado en la 1.0.33** (2026-11-11 02:05); falta que cada agencia la instale.

## Qué cambió, en una línea por tema
- **Ticket y tarea son el mismo elemento.** Todo ticket crea su tarea y toda tarea nueva crea su ticket. Los dos comparten la conversación, el estado, el responsable, la prioridad, el título y el plazo.
- **Tickets.** Tiene buscador, filtros (responsable, origen, prioridad, categoría y departamento), orden y páginas de 50. Hay una pestaña **Del base** y una vista de **tablero** por estado, con arrastrar.
- **SLA.** "Esperando respuesta" frena el reloj. Los plazos por categoría ya se editan en pantalla y no se borran al guardar.
- **Avisos.** Usan los avisos de Tareas (campana, email y navegador, según elija cada persona). Avisan antes de vencer y mandan un resumen diario.
- **Panel de solicitudes.** Cada departamento publica sus formularios, que se arman con un editor sobre la vista (como el de Promos). Lo que se pide entra directo como ticket con su tarea.
- **Informes.** Hay informes de tickets por origen, por responsable y por semana.

Pendiente a pedido del usuario: la **asignación automática** ("más adelante lo vemos").

## Ticket = tarea (`trabajo/link_lib.php`)
**Cómo nace cada uno.**
- **Ticket nuevo:** puede venir de Trabajo › Tickets, del sitio base, del panel de solicitudes o del chat. Crea su tarea con `wk_tk_make_task()`:
  - el **tablero** sale de `wk_tk_board()`: el que se eligió para el departamento en la configuración, si no el primero del departamento, si no el General. Un formulario del panel puede forzar el suyo;
  - el título, la prioridad y el responsable son los del ticket, y la fecha es el día del plazo de resolución;
  - quien abrió el ticket queda como creador de la tarea, así la ve aunque no esté en el tablero.
- **Tarea nueva:** puede crearse en Tareas, con una plantilla, al repetirse o desde Vencimientos. Crea su ticket con `wk_tk_from_new_task()`:
  - el origen es **tarea** y la categoría es **Tareas**, que se crea sola (`work_task_cat`);
  - el departamento es el del tablero;
  - no lleva primera respuesta y la resolución vence el día de la tarea a las 23:59;
  - **las subtareas no tienen ticket.**
- **Las que ya existían** quedan como estaban. El usuario eligió "solo las nuevas". En un ticket sin tarea, el botón **Crear su tarea** se la crea.
- **Excepción:** las tareas de ejemplo de Admin › Rubro no crean ticket, porque se borran con el rubro.

**Qué se sincroniza (en los dos sentidos).** Ningún cambio de la sincronización la vuelve a disparar (`wk_link_busy`).

| En la tarea | En el ticket |
|---|---|
| Terminarla (estado "terminado" del tablero) | Pasa a **Resuelto**; si vino del base, el cambio llega a la agencia |
| Reabrirla | Pasa a **En curso** |
| — | Resuelto o Cerrado → la tarea queda terminada (primer estado "terminado" del tablero) |
| — | Reabierto → la tarea vuelve al primer estado abierto (Abierto) o al segundo (resto) |
| Título, prioridad | Asunto, prioridad. Con SLA, cambiar la prioridad recalcula los plazos y la fecha de la tarea los sigue |
| Responsables (varios) | Responsable (uno): si el del ticket ya no está, pasa a ser el primero de la tarea |
| — | Asignar el ticket suma a esa persona en la tarea y saca a la anterior |
| Fecha (solo origen tarea) | Plazo de resolución |
| Mover a otro tablero (origen tarea) | Departamento del ticket |
| Borrarla | El ticket queda con su historia, sin tarea, con un evento interno |

Las automatizaciones que cambian una tarea (`wk_auto_do`) también ponen al día su ticket.

**Una sola conversación.** El vínculo está en `g7_wk_ticket_msgs.comment_id`.
- Un comentario en la tarea es una **respuesta pública** del ticket. Si el ticket vino del base, le llega a la agencia. El usuario eligió "todo público".
- En la tarea, debajo del cuadro de comentario, se ve quién va a leer lo que se escribe:
  - si el ticket es de una agencia, dice "Los ve la agencia (…) en su página Soporte";
  - si es de la web, aclara que el contacto no lo ve y que hay que escribirle por email.
- Una respuesta o una nota interna del ticket queda como comentario de la tarea. Las notas internas se ven con un candado y no salen de Trabajo.
- Las respuestas que llegan del base entran a la tarea con el nombre de la agencia (`g7_task_comments.user_id` NULL y `ext_name`). No se pueden editar.
- Editar o borrar un comentario cambia o borra el mensaje del ticket. Lo que ya se entregó al base queda allá.

**En la pantalla.**
- La tarea muestra el chip **T-0012** con el estado del ticket, con un ícono si el plazo se venció o está en pausa.
- El ticket muestra la tarjeta **Su tarea**: tablero, estado, responsables, fecha y "Abrir la tarea".
- Quien es responsable de la tarea también puede atender el ticket.

## Pausa del SLA en "Esperando respuesta"
- Al pasar a "Esperando", el ticket guarda `paused_at`. Lo hace desde Tickets, desde el tablero, por el estado que llega del base o con la respuesta de quien lo abrió.
- Al salir, se suman los minutos a `paused_min` y se corren los plazos que todavía corrían (primera respuesta sin responder y resolución).
- Mientras está en pausa no se escala ni cuenta como vencido. Los tickets que nacieron como tarea no se pausan.
- Cambiar la prioridad recalcula desde la apertura más el tiempo en pausa (`wk_tk_dues`).

## Lista de Tickets
- **Bandejas:** Los que abro, Del departamento, Asignados a mí, Sin asignar y **Del base** (solo en el Toolz conectado al base).
- **Buscador:** "T-0012" o "12" buscan el número. Cualquier otro texto se busca en el asunto, la descripción y los datos del contacto o la agencia del base. `%` y `_` se buscan como texto.
- **Filtros:** estado, responsable (o "sin asignar"), origen, prioridad, categoría y departamento.
  - Los orígenes son Demo web, Soporte de agencia, Ticket interno, Tarea, Panel de solicitudes, Chat y **Juego** (reportes que mandan los jugadores desde el juego: ver `JUEGOS_REPORTES.md`).
- **Orden:** vencimiento (el de siempre), prioridad, última actividad o más nuevos. La lista va en páginas de 50 con el total.
- **Tablero:** columnas Abierto, En curso, Esperando y Resuelto (los resueltos se ven 14 días). Quien atiende cambia el estado arrastrando una tarjeta, lo que llama a `ticket_update` y mueve también la tarea.
- **Escalar** abre un diálogo propio con el motivo. Antes era el cuadro del navegador.
- **Tabla** (2026-11-11, la vista por defecto). Pedido del usuario: una página de Tickets muy visual, con tablas personalizables, íconos y colores según la prioridad, la fecha límite, el ingreso y si está abierto o cerrado. Referencia: una lista de clientes tipo mesa de ayuda.
  - **Columnas:** Ticket, Solicitante, Estado, Prioridad, Fecha límite, Ingreso, Responsable y Categoría. Además se pueden sumar 1.ª respuesta, Departamento, Origen, Tarea y Actividad. "Columnas" permite elegirlas y ordenarlas; Ticket siempre se ve.
  - **Orden:** tocar un encabezado ordena descendente, después ascendente y después vuelve al orden de siempre. Ordena el servidor (`sort` + `dir`), así que vale para todas las páginas.
  - **Indicadores:**
    - la franja de la izquierda es la prioridad;
    - la fila vencida se tiñe de rojo;
    - la fecha límite va con chip: vencido (rojo lleno), vence hoy (ámbar), en 2 días o menos (amarillo), en pausa (violeta), y a tiempo o fuera de plazo al resolverse;
    - el punto de **Ingreso** muestra la antigüedad: verde si es reciente, ámbar desde 3 días y rojo desde 7 (solo los abiertos);
    - los resueltos y cerrados se atenúan y el asunto va tachado;
    - el estado tiene un ícono propio (cerrado con candado).
  - **Fila:** toda la fila abre el ticket; con Ctrl o Cmd se abre en otra pestaña. La estrella marca un ticket como destacado. El menú "…" ofrece abrir, abrir en otra pestaña, tomarlo, pasar a cualquier estado y copiar el enlace.
  - **Editar en la tabla** (solo quien atiende, `tkEdit`): tocar el **estado**, la **prioridad** o el **responsable** abre un selector en el lugar y guarda enseguida con `ticket_update`.
    - Teclado: las flechas mueven, Enter elige y Escape cierra.
    - Cambiar la prioridad recalcula los plazos; el aviso lo dice.
    - Asignar un ticket "Abierto" lo pasa a "En curso", igual que "Tomarlo".
    - El selector de personas tiene buscador y "Asignármelo a mí". Muestra primero la gente del departamento del ticket: `tk_meta` devuelve `team_people` y `assign_any`. Solo administradores y directores ven a todos, porque el servidor solo deja asignar dentro del departamento.
  - **Acciones en lote** (solo quien atiende): con las casillas se pueden tomar varios, cambiarles el estado o destacarlos.
  - **Barra:**
    - buscador, que se enfoca con "/" (Ctrl + K es el buscador de Toolz);
    - filtros con ícono: estado, ingreso (hoy, 7 o 30 días), prioridad, responsable, origen, categoría y departamento;
    - "Destacados", que muestra solo los marcados con estrella;
    - densidad compacta o cómoda;
    - 25, 50 o 100 por página.
  - **Qué se guarda y dónde:** en el navegador de cada persona, `localStorage` `wk_tk_prefs` (columnas, densidad, vista, tamaño de página y destacados). No viaja entre computadoras.
  - **API `tickets`:** nuevos `dir`, `since`, `ids` y `per`. Más columnas ordenables, incluido el nombre del responsable (`LEFT JOIN g7_users ua`).
  - **Pantalla:** la columna Ticket toma el espacio que sobra y queda fija al desplazar la tabla a los costados (en celular).

## Interfaz v2 (2026-11-11)
Pedido del usuario: más íconos y colores, mejor jerarquía visual y una interfaz más moderna y dinámica, primero en Tickets y después en todo Gestión de trabajo. Son solo cambios visuales: los datos, las acciones y la API siguen iguales.

**Colores con significado.** Cada color siempre significa lo mismo:
- **Origen de un ticket** (`TK_SRC`): web violeta, agencia azul, interno gris, tarea naranja, panel de solicitudes verde agua, chat rosa.
- **Prioridad:** la franja de color a la izquierda de cada fila y de cada tarjeta.
- **Plazos:** rojo si está vencido, ámbar si vence en menos de una hora, violeta si está en pausa y verde si se cumplió.
- **Cada sección** tiene su color, el mismo del menú lateral. `route()` lo pone en `#wk-main[data-sec]` y se usa en el título, los separadores y los vacíos.

**Tickets** (estilos `tk-*` y funciones `tkSla`, `tkMeter`, `tkConvHtml`, `tkReplyBox` y `bindTkCompose` en `trabajo.js`):
- KPIs con ícono en burbuja. El de la bandeja abierta queda marcado. "SLA vencido" late cuando hay vencidos. "Resueltos a tiempo" tiene una barra.
- Pestañas con íconos.
- Filas como tarjetas, con:
  - código `T-0000`;
  - ícono del origen;
  - chips de categoría, escalado y plazos;
  - avatar del responsable, estado y hora;
  - animación de entrada escalonada.
- Vacío con ícono y "Limpiar filtros".
- Detalle:
  - **Encabezado** con el color del estado, chips (estado, prioridad, categoría, escalado y pausa) y quién lo abrió y quién lo atiende.
  - **Plazos con barras** que muestran cuánto tiempo ya pasó.
  - **Conversación como chat:** lo propio a la derecha, la agencia en azul y las notas internas en ámbar con candado.
  - **Respuesta o nota interna** se elige con un selector; Ctrl + Enter envía.
  - **Estados de un clic** en Atender, que se guardan solos. "Guardar" queda para el responsable y la prioridad.

**Ficha del ticket v3** (pedido del usuario: conversación, colores según el estado, botones y selectores más modernos):
- **Color del estado:** la ficha entera toma el color del estado actual (`--st` en `.tk-detail`): encabezado, bordes de las tarjetas, ruta de navegación y foco del cuadro para escribir. Arriba hay una ruta "Tickets › T-0000".
- **Recorrido de estados** en el encabezado: Abierto → En curso → Esperando → Resuelto → Cerrado. Cada estado tiene su color e ícono; los anteriores quedan con tilde y el actual va lleno. Un clic lo cambia; quien abrió el ticket solo lo ve.
- **Atender:**
  - Responsable, Prioridad y Estado son botones con avatar o ícono que abren el mismo selector de la tabla (`tkEdit`, que ahora recibe qué recargar al guardar).
  - Se guardan solos: ya no hay "Guardar".
  - "Tomarlo" y "Escalar" pasaron al encabezado, junto a "Copiar enlace".
- **Botones `tk-b`:** con relieve, gradiente en el principal, rojo suave para escalar, verde para cerrar y animación al tocarlos.
- **Conversación:**
  - separadores por día (Hoy, Ayer, fecha);
  - los mensajes seguidos del mismo autor, con menos de 10 minutos entre uno y otro, se agrupan;
  - cada mensaje muestra la hora, y los propios llevan doble tilde;
  - la agencia va en azul con la etiqueta "Agencia" y las notas internas en ámbar con candado;
  - los eventos se parten en piezas con ícono y color: el cambio de estado lleva el chip del estado nuevo; la asignación va en violeta, la prioridad en ámbar, el escalado y los vencimientos en rojo, y la apertura en verde.
- **Para escribir:**
  - el cuadro crece con el texto;
  - Ctrl + Enter envía;
  - "Enviar" tiene una flecha con "Enviar y pasar a…" cada estado. Para eso manda `ticket_msg` y después `ticket_update`; con nota interna, la flecha se esconde.
- **Pantalla:** en pantallas de menos de 1000 px va en una sola columna (`.tk-cols2`; antes el ancho estaba en el HTML y no cambiaba).

**Resto de Trabajo** (bloque "Trabajo v2" al final de `trabajo.css`, solo CSS más `data-sec`):
- Menú lateral con íconos de color.
- KPIs con ícono en burbuja, coloreado según lo que mide (`:has()` sobre el ícono).
- Títulos de tarjetas con ícono en burbuja.
- Filas, tableros, proyectos y formularios que se elevan al pasar el mouse y entran con animación.
- Barras de avance animadas.
- Vacíos con ícono grande.

**Resumen** (clases `sm-*`, funciones `sm*` en `trabajo.js`): rehecho como un tablero de mesa de ayuda, inspirado en el tema Etikto (index-7) que pasó el usuario. Las tarjetas no tienen borde, tienen sombra suave y los números son grandes. Los gráficos son SVG propios, sin librerías.
- Arriba, **Lo tuyo** en una franja de chips: vencidas, vencen hoy, próximos 7 días, abiertas, terminadas y horas. Quien no tiene equipo a cargo ve esas cuatro como tarjetas, más Lo próximo y Novedades.
- **Con equipo** (jefes, directores y administradores), en este orden:
  - **4 KPIs:** tareas creadas, abiertas, terminadas y tiempo de cierre. Muestran la variación de los últimos 30 días contra los 30 anteriores, en verde o rojo según convenga (en el tiempo de cierre, bajar es bueno).
  - **Tareas terminadas:** área suave con tooltip y punto.
  - **Abiertas por prioridad:** medidor semicircular por segmentos.
  - **Cumplimiento:** a tiempo, vencidas, sin responsable y sin fecha, con ícono y barra.
  - **Terminadas por persona:** al tocar a alguien se abren sus tareas.
  - **Lo próximo:** fecha grande, barra con el color de la prioridad, estado y flecha al pasar el mouse.
  - **Abiertas por estado:** torta con porcentajes.
  - **Creadas y terminadas:** barras apiladas, con los totales al costado.
  - **Novedades** y **Carga por persona**.
- Los dos gráficos de serie tienen el mismo selector: 14 días, 8 semanas o 12 meses (`W.smPer`).
- La API `summary` suma `team.days` (14 días), `team.months` (12 meses) y `team.cmp`. Las fechas salen de PHP, no de `NOW()` de MariaDB.

**Cuidados.** Todo usa las variables del tema, así que anda en claro y en oscuro. Con "reducir movimiento" no hay animaciones. Al imprimir, el título vuelve a texto plano. En celular las filas se apilan sin desplazamiento horizontal.

## Configuración (Tickets › Configuración, solo admins)
- **Categorías:** cada una con su departamento. **Plazos propios** abre la tabla de esa categoría; sin ellos, usa los generales.
  - Antes, guardar borraba los plazos por categoría; ahora vienen todos.
- **Tablero de cada departamento:** `work_team_boards`, guardado como `{team_id: board_id}`, donde "0" es sin departamento.
- **Avisar antes de vencer:** `work_tk_warn_min`, en minutos. Por defecto 60.
- **Resumen diario:** `work_tk_digest_hour`, de 0 a 23, o -1 para apagarlo. Por defecto a las 9.

## Avisos
Los eventos nuevos están en `TK_EVENTS` (`tareas_lib.php`). Aparecen solos en Tareas › Mis avisos (cada persona) y en Configurar (admin):

| Evento | A quién | Campana / email por defecto |
|---|---|---|
| `tk_new` Entra un ticket a mi departamento | Gente del departamento | sí / sí |
| `tk_reply` Responden un ticket mío | Quien lo abrió, responsable (o jefes), gente de la tarea | sí / sí |
| `tk_status` Cambia un ticket mío (estado, responsable, escalado) | Ídem | sí / no |
| `tk_due_soon` Por vencer | Responsable (o el departamento) | sí / sí |
| `tk_breach` Se venció el plazo | Jefes del departamento + responsable | sí / sí |
| `tk_digest` Resumen diario | Admins y jefes de departamento, con lo de su alcance | no / sí |

- La campana usa el tipo `wk_tk` con el id del ticket, así el clic abre el ticket. El email dice "Toolz · Tickets" y su botón "Abrir el ticket".
- **Sin avisos repetidos:** los cambios que hace la sincronización no avisan otra vez, y comentar en la tarea avisa con los avisos de Tareas.
- El aviso **por vencer** usa los flags 4 (respuesta) y 8 (resolución) de `breach_flags`, así sale una sola vez. Al salir de una pausa con el plazo otra vez por delante, puede volver a avisar.
- El **resumen** sale una vez por día (`work_tk_digest_day`), en el primer barrido después de la hora elegida. Corre desde `trabajo/cron.php` o, sin cron, con la primera visita a Trabajo. Trae cuántos tickets hay abiertos, vencidos, que vencen hoy y sin asignar, más hasta 12 tickets.

## Panel de solicitudes (Trabajo › Solicitudes)
> **Reemplazado el 2026-11-13 por el Portal de Solicitudes (`SOLICITUDES.md`).** Los formularios de abajo pasaron solos al formato nuevo. Lo que sigue describe el panel anterior.

- **Panel:** una sección por departamento con sus formularios publicados, y abajo **Lo que pediste** con el estado de cada ticket.
- **Enviar un pedido** (`form_submit`):
  - Nace el ticket con origen `form`, `form_id` y `answers`, y con su tarea.
  - **Sin paso de aprobación.**
  - La categoría es la del formulario. Si no tiene, va a la primera del departamento, y si el departamento no tiene ninguna, se crea "Pedidos a …".
  - La prioridad es la fija del formulario o la elige quien pide.
  - El responsable de entrada, si el formulario tiene uno, queda asignado en el ticket y en la tarea.
  - Las respuestas pasan a la descripción como "Pregunta: respuesta", con los títulos de sección en mayúsculas. El ticket también las muestra en "Lo que pidió".
- **Editor** (jefes del departamento y admins):
  - Se edita sobre la vista que ve quien pide: el nombre, la descripción, cada pregunta, su ayuda y las opciones de las listas.
  - Al pasar el mouse por un campo aparecen sus herramientas: asa para **arrastrar y ordenar**, tipo, Obligatorio u Opcional, y quitar.
  - El ícono abre la paleta de colores e íconos.
  - Tipo nuevo: **Título de sección**.
  - Ajustes al costado: departamento, categoría (sus plazos), prioridad, responsable de entrada, tablero de la tarea y publicado.
- **Historial:** las solicitudes de antes, con aprobación, se siguen viendo en Solicitudes › Historial. Un formulario con pedidos no se borra: se desactiva.

## Mis solicitudes: filtro "En curso" (2026-11-12, sin publicar)
- Pedido del usuario: "que en mis solicitudes este tambien la opcion de "En curso" para ver que tareas estan en curso".
- Pestañas: Abiertas, **En curso**, Esperan tu respuesta, Atrasadas, Resueltas y Todas. "En curso" muestra los tickets en ese estado y su cantidad (`my_requests`: `status = en_curso`, `summary.progress`).
- Para que "En curso" refleje el trabajo real: cuando la tarea de un ticket **Abierto** sale del primer estado de su tablero (por ejemplo, de "Pedidos" a "En proceso"), el ticket pasa a **En curso** y lo anota en la conversación (`wk_tk_from_task`).

## Mis tareas: la tarea de un ticket abre el ticket (2026-11-13, sin publicar)
- Pedido del usuario: "que el contenido de mis tareas lleve al ticket y no al tablero".
- En Trabajo › Mis tareas, si la tarea es la de un ticket, la fila abre ese ticket (`#tickets/ID`) en vez del tablero, y muestra su código (por ejemplo T-0032) con un ícono de ticket. Las tareas sueltas siguen abriendo el tablero.
- `api.php` (`mine`) devuelve `ticket_id` en cada tarea; `trabajo.js` (`renderMine`) arma el link. Prueba: `test_trabajo_tickets` (89).
- El Inicio ya lo hacía así (Mis tareas del escritorio).

## Informes
En Trabajo › Informes, además de "Tickets por categoría", hay tres informes nuevos:
- **Tickets por origen.**
- **Tickets por responsable.** No incluye los que nacieron como tarea, porque ya están en "Por persona".
- **Tickets por semana.** Va de lunes a domingo, hasta 26 semanas, con creados, resueltos y el porcentaje dentro del SLA, más una barra.

Todos se exportan a CSV.

### Tablero de informes (2026-11-17, sin publicar)
`trabajo/informes.php`. Se abre con el botón **"Tablero de informes"** de Trabajo › Informes, que pasa el período, el departamento y el tablero elegidos. Es el mismo tablero que Estadísticas › Jira, pero con las tareas de Cronos (ticket = tarea, así que también entran los tickets).

**Qué tiene:**
- **Período:** rangos rápidos o desde/hasta.
- **Filtros:** departamento y tablero.
- **Indicadores:**
  - creadas;
  - entregadas (con el porcentaje sobre lo creado);
  - a tiempo;
  - sin entregar hoy;
  - tiempo hasta entregar (promedio y mediana);
  - plazo que se da.
- **Resumen por situación:** a tiempo, tarde, atrasadas, en curso y sin empezar. Al tocarlo muestra la lista.
- **Avisos rápidos:** vencen en 7 días, sin fecha, sin responsable, esperando aprobación y fin de semana.
- **Pestañas:** Resumen, Entregas, Personas, Tiempos, Plazos y prioridad, Calendario, Origen, Explorar (tabla dinámica) y Lista. Cada tarea tiene su link.
- **Exportar:** Excel o PDF, eligiendo secciones, columnas, cuáles tareas y el orden.
- **"¿Qué cuenta como entregado?"** (admin o dirección):
  - Por defecto cuentan las columnas terminadas y las que se llaman como "aprobación", "revisión del cliente" o "entregado".
  - Se pueden elegir los estados. Así, una tarea que llegó a "Para aprobar" a tiempo y se aprobó después cuenta como entregada a tiempo.
  - Se guarda en `g7_config.work_stats_cfg` y queda en la auditoría.

**Cómo se arma cada tarea:**

| Dato del informe | De dónde sale |
|---|---|
| Estado | El nombre de la columna de su tablero. La primera columna es "sin empezar"; las marcadas como terminadas, "terminada"; el resto, "en curso". |
| Tipo | El tablero. |
| Proyecto | El cliente o la marca de la tarea. |
| Componente | El departamento del tablero. |
| Responsable | El primero asignado. |
| Quién la pidió | Quien abrió el ticket o, si no hay ticket, quien creó la tarea. |
| Etiquetas | Las de la tarea, más "Origen: …" si viene de un ticket que no nació como tarea (por ejemplo, Social Studio). |
| Fecha de entrega | La última vez que llegó a un estado de entrega, según el historial de estados (`g7_task_activity`). |

- No cuentan las tareas archivadas, las subtareas ni las cerradas antes del período.
- Máximo 5000 tareas por informe; si hay más, avisa.

**Alcance:** el mismo de los informes de antes.
- Admin y dirección ven todo.
- Una jefa ve lo de su gente y lo de los tableros de sus departamentos, y filtra solo por sus departamentos.
- El resto ve lo suyo: lo que tiene asignado o creó.

**Un solo motor:** las cuentas son `g7_jira_stats()` (`jira_stats_lib.php`), la pantalla es `assets/stats-jira.js` y la exportación, `stats_jira_export.php`. Para eso, Jira y Trabajo comparten código:
- `stats-jira.js` acepta `R.words` (vocabulario), `R.urls` (link de cada fila) y `R.deliver_url`.
- `stats_jira_export.php` acepta `$XS`: el informe ya armado y sus textos.
- Sin esos datos, Jira se ve y exporta igual que antes.

**Archivos:**
- Nuevos: `trabajo/report_lib.php`, `trabajo/informes.php`, `trabajo/informes.js`, `trabajo/informes.css`.
- Tocados: `trabajo/trabajo.js` (el botón), `assets/stats-jira.js`, `stats_jira_export.php`.
- Respaldo de antes: `dev/tmp/stats-jira.antes.js`, `dev/tmp/stats_jira_export.antes.php`.

**Pruebas:**
- `dev/test_trabajo_informes.php`: 34/34.
- Siguen bien `test_jira_use` (76) y `test_trabajo_tickets` (89).
- Navegador, base local: los indicadores y la pestaña Personas con datos reales, y la exportación a Excel y a PDF.
- Estadísticas › Jira no se pudo ver en local (no hay Jira conectado). Su código de exportación cambió solo para recibir textos: conviene mirarla una vez publicada.

## Esquema (v8, `wk_install_v8`)
- `g7_wk_tickets`: `origin`, `paused_at`, `paused_min`, `form_id`, `answers`, más `base_id` y `base_info`, que ahora existen en todas las instalaciones (vacías fuera del Toolz principal). Índices `task_id`, `resolution_due` y `updated_at`.
- `g7_wk_ticket_msgs`: `comment_id` y `ext_name`.
- `g7_task_comments`: `user_id` pasa a aceptar NULL (mensajes del base); columnas nuevas `internal` y `ext_name`.
- `g7_wk_forms`: `category_id` y `priority`.
- `g7_config`: `work_team_boards`, `work_task_cat`, `work_tk_warn_min`, `work_tk_digest_hour` y `work_tk_digest_day`.

## Archivos
- **Nuevo:** `site/trabajo/link_lib.php`.
- **Cambiados en `site/trabajo/`:** `lib.php`, `api.php`, `base_sync.php`, `trabajo.js` y `trabajo.css`.
- **Cambiados en `site/`:** `tareas_lib.php`, `tareas_api.php`, `tareas.js`, `tareas2.css` y `vencimientos/lib.php`.
- **Respaldo de antes:** `dev/antes_ticket_tarea/`.
- **Código nuevo sin scripts en línea:** todo va con `addEventListener`, como pide la política de `SEGURIDAD_CSP.md`. Los `style="--c:…"` son como los de siempre (style-src permite inline).

## Probar
```bash
G7_TEST_DB="127.0.0.1;port=3407" php -d extension=gd dev/test_trabajo_tickets.php
```
- Crea la base `wk_tt_test` y levanta su propio Toolz en :8163. Son 77 casos.
- `dev/test_base_tickets.php` (49) cambió solo en el texto que busca en dos avisos, porque ahora el aviso trae el código y el asunto del ticket.
- **Zona horaria en local:** PHP usa la de `php.ini` (Europe/Berlin) y MariaDB la de Windows. Los plazos se guardan con la hora de PHP (`tk_now()`), así que la prueba arma los plazos con `date()`, no con `NOW()`.
