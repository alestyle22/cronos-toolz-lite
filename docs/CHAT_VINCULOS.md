# Vínculos y emojis en el chat

## Qué se puede hacer

- **Emojis en la burbuja**: botón de carita en el chat flotante, con buscador en castellano ("risa", "gracias", "ok"…). El chat grande ya lo tenía.
- **Vincular** desde los dos chats (burbuja y chat grande) con el botón del eslabón: clientes, contenidos, tareas y tickets.
  - Pestañas por tipo y buscador por nombre o por número (`#45` para una tarea, `T-0003` para un ticket).
  - Solo aparece lo que la persona puede ver.
- En el mensaje, el vínculo se ve como una **tarjetita** con ícono, código, nombre y estado. Un clic abre el cliente, la tarea o el ticket en Trabajo. Los contenidos abren Promos, que todavía no tiene enlace directo a cada contenido.
- En las vistas previas de la lista de conversaciones, un vínculo se lee como "🔗 tarea".

## Privacidad

- El texto del mensaje solo guarda la marca `[[tipo:id]]` (por ejemplo `[[tarea:45]]`), nunca el nombre.
- Cada persona que lee el mensaje pide el nombre al servidor (`ref_resolve`). Si no puede ver ese elemento, o ya no existe, la tarjetita dice **"Tarea sin acceso"** con un candado y no navega.
- Las reglas de quién ve qué son las mismas del resto de la plataforma:
  - **Clientes:** el módulo Clientes.
  - **Contenidos:** Promos, respetando las marcas de cada persona.
  - **Tareas:** los tableros de Trabajo.
  - **Tickets:** quien lo pidió, quien lo atiende y los equipos.

## Tickets: "Vinculados"

En el detalle de un ticket (Trabajo › Tickets) hay una tarjeta **Vinculados**, donde se agregan clientes y contenidos relacionados con el botón "Vincular cliente o contenido" y se quitan con la ×.

- La pueden editar quienes atienden el ticket y quien lo pidió.
- Solo se puede vincular lo que uno puede ver.
- Hay un máximo de 30 vínculos por ticket.
- Cada persona ve los nombres según su acceso.

## Archivos

- `site/chat/refs_lib.php`: tipos, permisos, `ch_refs_resolve()` y `ch_refs_search()`.
- `site/chat/refs.js` y `site/chat/refs.css`: tarjetitas, selector "Vincular" y selector de emojis. Los usan la burbuja, el chat grande y Trabajo.
- `site/chat/api.php`: acciones `ref_kinds`, `ref_search` y `ref_resolve`.
- `site/trabajo/tk_links_lib.php`: tabla `g7_wk_ticket_links`.
- `site/trabajo/api.php`: acciones `tk_link`, `ref_kinds`, `ref_search` y `ref_resolve`. `ticket_get` devuelve `links`.
- Pruebas: `php dev/test_chat_refs.php` (MariaDB de pruebas, puerto 3407).
