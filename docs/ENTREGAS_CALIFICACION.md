# Entregas y calificación de pedidos

Del contenido cargado en Promos al pedido que lo pidió, y de ahí a la aprobación y la calificación (2026-11-12).
Código: `site/trabajo/entrega_lib.php` (lógica), `site/trabajo/api.php` (acciones), `site/assets/calificar.js` (aprobar, pedir
cambios y calificar: un solo componente para Trabajo e Inicio), `site/promos/entrega.js` (buscador en Promos), `site/assets/home-pedidos.js` (Inicio).
Prueba: `php dev/test_entregas.php` (contra la demo :8154).

## 1. Entregar desde Promos

- Al cargar un contenido, el campo **"Entrega de un pedido" (opcional)** busca los tickets abiertos que atiendes (los tuyos primero,
  después los de tu departamento; admins y directores, todos) y tus tareas abiertas que no son de un ticket. Acción `deliver_targets`.
- Al guardar (`promos/index.php` › `wk_deliver()`):
  - **Ticket**: el contenido queda vinculado (tarjeta "Vinculados", `g7_wk_ticket_links` tipo `contenido`). Se publica una respuesta
    **visible para quien lo pidió** con el mensaje de entrega y los enlaces (imagen, gráficas, landings, mailing y "Ver en Promos").
    El ticket pasa a **Resuelto** (su tarea se actualiza) y la gente del ticket recibe el aviso "Pedido entregado".
  - **Tarea sin ticket**: recibe el mensaje como comentario y pasa al estado terminado de su tablero. Si la tarea es de un ticket,
    se entrega el ticket.
  - Solo entrega quien atiende el ticket (mismo criterio que Trabajo). Si no puede, el contenido se guarda igual y se avisa por qué
    no se entregó.
- El mensaje se edita en **Trabajo › Tickets › Configuración › Mensaje de entrega** (`work_delivery_tpl`). Por defecto:
  "Hola, ya están los elementos. Los enlaces son:" seguido de `{enlaces}`. También acepta `{nombre}`.
- También se puede entregar por API: `deliver {content_id, kind: 'ticket'|'tarea', id}`.

## 2. Aprobar y cerrar, o pedir cambios

Con el ticket **Resuelto**, quien lo pidió ve **Aprobar y cerrar** y **Pedir cambios**, en el detalle del ticket, en Mis solicitudes
y en el Inicio. Pedir cambios deja un comentario (visible para el equipo) y lo vuelve a **En curso**. Aprobar lo pasa a **Cerrado** y
abre la calificación. No hay cierre automático.

## 3. Calificar

- Con el ticket **Cerrado**, quien lo pidió le pone de **1 a 5 estrellas** (nota general), estrellas por cada **punto** que su
  departamento califica (por ejemplo Rapidez y Calidad) y un **comentario opcional**. Una sola vez por ticket (`g7_wk_ratings`).
- Se puede calificar al cerrar, después desde el detalle, desde **Mis solicitudes** (pestaña **Sin calificar**) o desde el **Inicio**.
- La ven quien la puso, el responsable, su departamento y los admins (`wk_ev_can_see`). Al responsable (o al departamento) le llega
  un aviso con las estrellas.
- No se califican los tickets que nacen como tarea (`origin = 'tarea'`).

## 4. Puntos por departamento (Trabajo › Tickets › Calificaciones)

- **Admins y directores** configuran todos los departamentos. El **jefe de un departamento** (rol "admin" dentro del equipo),
  solo los suyos. Nadie más (`wk_ev_can_config`).
- Se agregan, renombran, ordenan y apagan puntos (hasta 12). Si se quita un punto que ya tiene notas, queda apagado para no perderlas.
- La misma pantalla muestra el promedio general y el de cada punto (últimos 90 días) y los últimos comentarios.
- Tablas: `g7_wk_rating_criteria (team_id, name, position, active)`, `g7_wk_ratings (ticket_id, user_id, team_id, assignee_id,
  stars, scores JSON {id del punto: 1..5}, comment)`. Se crean solas (`wk_ev_install`, `work_ev_schema`).

## 5. Inicio

El bloque "En qué estoy trabajando" tiene ahora estas pestañas:

| Pestaña | Qué muestra |
|---|---|
| **Mis tareas en curso** | Antes "Mías". |
| **Mis pedidos en curso** | Abiertos, en curso o esperando respuesta. |
| **Mis pedidos resueltos** | Los entregados, para **aprobar o pedir cambios**, y los **cerrados sin calificar** (últimos 30 días), con estrellas para calificar ahí mismo. Un punto naranja avisa que hay pendientes. |
| **Todo el equipo** | Sin cambios. |

Si hay pedidos para aprobar o calificar y no tienes tareas en curso, se abre en esa pestaña. Después de cada acción el Inicio
se recarga en la misma pestaña.

## Acciones de la API (`trabajo/api.php`)

| Acción | Para qué |
|---|---|
| `ev_get` | Lo necesario para calificar un ticket, o su calificación. |
| `ev_save` | Guarda la calificación (solo quien lo pidió, cerrado, una vez). |
| `ev_pending` | Los cerrados sin calificar. |
| `ev_config` | Puntos y promedios por departamento. |
| `ev_criteria_save` | Guarda los puntos de un departamento. |
| `deliver_targets` | Tickets y tareas a los que se puede entregar. |
| `deliver` | Entrega un contenido. |
| `home_work` | Tareas y pedidos para el Inicio (API). |
| `my_requests` | Ahora acepta `status: 'sin_calificar'` y devuelve `rating` y `summary.unrated`. |
