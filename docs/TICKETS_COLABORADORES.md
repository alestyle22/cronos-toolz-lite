# Colaboradores y menciones en los tickets

Sumar personas a un ticket y nombrar personas o contenidos en las respuestas (2026-11-12).
Código: `site/trabajo/tk_collab_lib.php` (lógica), `site/trabajo/api.php` (`wk_ticket`, `ticket_get`, `ticket_msg`, `ticket_collab`,
pestaña `collab` en `tickets`), `site/trabajo/trabajo.js` (tarjeta Colaboradores, `@` y Vincular en el escritor, `tkRich`).
Prueba: `php dev/test_tk_colab.php` (contra la demo :8154).

## Colaboradores

- Son personas sumadas a un ticket, además de quien lo pidió y quien lo atiende (`g7_wk_ticket_collabs`).
- **Ven** el ticket, **responden** y **reciben los avisos** (respuestas y cambios de estado), porque `wk_tk_people()` los incluye.
- **No ven las notas internas** ni reciben sus avisos, salvo que ya atiendan el ticket (su departamento, el responsable o la gente
  de su tarea). Tampoco cambian el estado, el responsable ni la prioridad.
- Los **suman o quitan** quien atiende el ticket y quien lo pidió, desde la tarjeta **Colaboradores** del detalle. Cada
  colaborador puede **salirse** (la ✕ junto a su nombre).
- Al sumarlo le llega un aviso. Todo queda registrado en el ticket ("sumó a … como colaborador", "dejó de colaborar").
- En Trabajo › Tickets, la pestaña **Donde colaboro** los lista. "Los que abro" incluye también los tickets donde colaboro.

## Menciones (@persona)

- En el escritor de respuestas, `@` abre la lista de personas (flechas y Enter o Tab para elegir). Se guarda como `@usuario` y se
  muestra resaltado con el nombre.
- En una **respuesta**, cada mencionado recibe el aviso "te mencionó" (`tk_mentions` / evento `mention`). Si no tenía acceso al
  ticket, queda sumado como colaborador para poder verlo.
- En una **nota interna**, solo se avisa a quien puede verla. Los demás no reciben nada y a quien escribe se le avisa
  ("… no ve las notas internas").

## Contenidos y otros vínculos

El botón **Vincular** del escritor busca contenidos, clientes, tareas o tickets (el mismo buscador que el chat y la tarjeta
"Vinculados") e inserta `[[tipo:id]]` en el texto. En la respuesta se ve como tarjetita con el título; quien no tiene acceso a
ese elemento ve "sin acceso" (`chat/refs_lib.php`, `chat/refs.js`).
