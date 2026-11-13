# Respuestas rápidas y respuesta automática de los tickets

## Respuestas rápidas

- En el detalle de un ticket, quien lo atiende tiene el botón **Respuestas rápidas** en el cuadro de respuesta.
- Al elegir una, el texto se escribe en el cuadro, ya completado, para revisarlo o cambiarlo antes de enviar.
- Si la respuesta tiene un estado asociado (por ejemplo "Solucionado" → Resuelto), aparece el aviso **"Al enviar pasa a Resuelto"**. Se quita con la ×. En una nota interna no cambia el estado.
- Cada respuesta puede ser para **todas las áreas** o para **un área** (equipo). En cada ticket se ven las generales más las del área del ticket.

**Variables:**

| Variable | Qué pone |
|---|---|
| `{saludo}` | Buenos días, Buenas tardes o Buenas noches, según la hora en que se responde |
| `{nombre}` | Nombre de quien abrió el ticket (en los del sitio base, el contacto o la agencia) |
| `{agente}` | Nombre de quien responde |
| `{codigo}` | Código del ticket (T-0001) |
| `{asunto}` | Asunto del ticket |

**De fábrica** vienen 6, todas con "Muchas gracias":

| Respuesta | Cambia el estado a |
|---|---|
| Recibido | En curso |
| Necesitamos más datos | Esperando |
| Seguimos trabajando | — |
| Solucionado | Resuelto |
| Sin respuesta | — |
| Cierre | Cerrado |

## Respuesta automática

- Cuando entra un ticket nuevo, se responde solo con un mensaje que se ve como **"Respuesta automática"**, con un robot.
- **No cuenta como primera respuesta del plazo (SLA)**: el plazo lo sigue marcando la primera respuesta de una persona.
- Si el ticket vino del **sitio base**, la respuesta automática también le llega a la agencia, después del aviso de que el ticket se gestiona en Trabajo.
- No se manda en tickets que ya llegan resueltos o cerrados, ni en los que nacen de una tarea.
- **Por área**, cada una puede:
  - usar la **general**;
  - tener un **texto propio**;
  - no tener respuesta automática.
- Viene **apagada**. Se prende en el editor.

## Quién edita

Las editan **admins y directores**, desde el menú de Respuestas rápidas › "Editar respuestas y respuesta automática". Se guardan en `g7_config` (`tk_canned` y `tk_autoreply`).

## Archivos

- `site/trabajo/tk_canned_lib.php`: valores de fábrica, validación, por área, respuesta automática y `tk_autoreply_send()`.
- `site/trabajo/lib.php` (`wk_create_ticket`) y `site/trabajo/base_sync.php` (tickets del base): llaman a la respuesta automática.
- `site/trabajo/api.php`: `tk_meta` devuelve `canned`, `canned_edit` y `autoreply`; `tk_canned_save` las guarda.
- `site/trabajo/trabajo.js` y `trabajo.css`: botón, menú, aviso de estado, editor y mensaje automático en la conversación.
- Pruebas: `php dev/test_tk_canned.php`.
