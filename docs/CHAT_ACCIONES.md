# Acciones rápidas del chat

Desde el chat se crean tickets, tareas y notas sin salir de la conversación (2026-11-12, versión 1.0.48).
Código: `site/chat/chat.js` (botón ⚡, comandos y formularios), `site/chat/api2.php` (`quick_ticket`, `quick_task`, `note_targets`,
`ticket_note`, `task_cfg` con `message`, `ticket_from_msg`), `site/chat/lib2.php` (`ch_ticket_roles`, `ch_make_ticket`, `ch_ticket_note`,
`ch_note_targets`). Prueba: `php dev/test_chat_acciones.php` (contra la demo :8154).

## Cómo se usan

- **Botón ⚡** en el escritor (junto al +): lista de acciones. Con texto ya escrito, la acción lo usa directo; sin texto, deja el
  comando listo para completar.
- **Comandos**: escribir `/` al principio del mensaje muestra las acciones (flechas y Enter o Tab para elegir, Esc para cerrar).
  Al enviar, un comando conocido no se manda como texto. Un `/` que no es un comando se envía normal.

| Comando | Qué hace |
|---|---|
| `/ticket texto` | Ticket con ese texto como asunto (primera línea, sin formato). El mensaje se publica y queda enlazado al ticket. |
| `/tarea texto` | Tarea en Trabajo asignada a la otra persona (tablero General). El mensaje queda enlazado. |
| `/nota T-0012 texto` | Nota **interna** en ese ticket. Sin número, se elige el ticket (primero los enlazados en la conversación y después los abiertos que atiendo). No se publica en el chat. |
| `/recordar texto` | Recordatorio para mí (se elige cuándo). |
| `/programar texto` | El mensaje se envía más tarde. |
| `/encuesta pregunta` | Encuesta con la pregunta cargada. |
| `/ayuda` | Lista de comandos. |

En el menú ⋯ de un mensaje: **Abrir un ticket…** (o **Abrir ticket (lo atiendo yo)…** si el mensaje es de otra persona) y
**Añadir nota a ticket…** (la nota es el mensaje con su enlace, o lo que se escriba).

Ticket, tarea y nota solo aparecen para quien tiene acceso a Gestión de trabajo (`S.canTasks` / `g7_can('tareas')`).

## Quién pide, quién atiende y qué departamento (`ch_ticket_roles`)

- **Mensaje mío** (o `/ticket`): lo pido yo; lo atiende **la otra persona** del mensaje directo. En un grupo o canal, la que elija
  (tiene que ser miembro). En "mis notas" no hay a quién asignarlo: da error y no queda el mensaje suelto.
- **Mensaje de otra persona**: lo pidió ella (solicitante) y lo **atiendo yo**.
- **Departamento** = equipo principal de quien atiende (`g7_team_members.is_primary`). **Categoría** = la primera activa de ese equipo.
  Si el equipo no tiene categoría, o la persona no tiene equipo, se usa una general, y nunca la de "Tareas" (`work_task_cat`). En el
  formulario se puede cambiar.
- Se asigna el responsable, se actualiza la tarea del ticket (mismo responsable) y le llega el aviso de asignación.

## Plazo: tareas rápidas

Los tickets del chat tienen `origin = 'chat'` ("Chat (tarea rápida)" en Trabajo). Su plazo **no** sale de la categoría ni de la
prioridad: es uno solo, configurable en **Trabajo › Tickets › Configuración › Tickets desde el chat (tareas rápidas)**, en horas
(`g7_config.work_tk_chat_min`, en minutos; por defecto 240 = 4 horas). No llevan plazo de primera respuesta. Con 0 usan los plazos
de su categoría. `wk_tk_dues()` (trabajo/link_lib.php) lo respeta también si después se cambia la prioridad o la categoría. La fecha
de la tarea del ticket sale de ese plazo.

## Notas internas (`ch_ticket_note`)

Solo quien atiende el ticket (su departamento, el responsable, la gente de su tarea o un admin: el mismo criterio que Trabajo) puede
agregar notas. Se guardan como en Trabajo (`wk_tk_post` interno), quedan también en los comentarios de su tarea y avisan a la gente
del ticket menos a quien lo pidió.
