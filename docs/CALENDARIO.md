# Mi calendario (2026-11-10, versión 1.0.8)

Calendario personal de cada persona, en `/toolz/calendario/` (módulo `calendario`, prendido para todos).

- **Eventos privados:** nadie más los ve, ni un admin. Cada evento tiene:
  - título, todo el día o con horario, y hasta 31 días de duración;
  - color, lugar o link, y notas;
  - repetición diaria, semanal, mensual o anual, con fecha de fin opcional;
  - un aviso antes, de "a la hora" hasta "1 semana antes".
- **Repeticiones:** cambiar o borrar un evento que se repite toca toda la serie. Si el mes no tiene ese día (31, o 29 de febrero), ese mes no aparece.
- **Mis tareas:** muestra las tareas con fecha de vencimiento asignadas a la persona, o creadas por ella sin nadie asignado. Se ven pero no se editan ahí; un clic abre la tarea. Se prende y se apaga con la casilla "Mis tareas", que queda guardada en el navegador.
- **Vistas:**
  - Mes: en celular los eventos se ven como barritas de color.
  - Semana: en celular es una lista por día.
  - Agenda: muestra los próximos 60 días.
- **Atajos:** N crea un evento, T va a hoy, las flechas cambian de período y Esc cierra.
- **Avisos:** los dispara `notif.php` cada vez que la página consulta la campana, solo para esa persona.
  - Se guardan en `g7_notifications` con tipo `cal_remind` y `from_id` NULL.
  - Tocarlo abre `#e/ID`.
  - `reminded_at` guarda la última ocurrencia avisada, así no se repite.
  - Avisan solo con alguna página de la plataforma abierta.

Archivos: `site/calendario/` (`lib.php`, `api.php`, `index.php`, `cal.js`, `cal.css`). La tabla `g7_cal_events` se crea sola (`cal_schema`). El menú de arriba suma "Calendario" una vez por sitio, después de Trabajo (`nav_calendario_added`).

## Usuarios (misma versión)
- **Admin › Usuarios:**
  - El botón **Generar** abre el generador: largo de 8 a 32, números, símbolos, opción "fácil de leer", medidor de seguridad, "Usar esta" y "Copiar".
  - Al guardar un usuario nuevo, o una contraseña nueva, se abre el **mensaje de acceso** con la dirección, el usuario y la contraseña, para copiar o abrir en el correo.
  - El botón **Copiar datos** está siempre en la ficha. Sin contraseña nueva, el mensaje dice "la que te pasamos por separado".
- **`users.php`:** botón Generar y mensaje para copiar después de crear. También se arregló el nombre de usuario que se mostraba sin escapar en el aviso.
- **Instalador:** en el paso Administrador está "Generar una".

## Pruebas (local)
- **Instalación limpia** con Trabajo, Calendario, Clientes y una persona del equipo.
- **Calendario:**
  - Validaciones: sin título y hora de fin antes del inicio.
  - Repetición mensual del día 31 y repetición semanal.
  - El aviso llega a la campana una sola vez.
  - Otra persona no ve ni borra eventos ajenos.
- **Usuarios:** generador y mensaje de acceso en Admin; el usuario creado entra con esa contraseña.
- **Regresión:** `test_update.php` da 35/35 y `test_cronos_sim.sh` abre todas las páginas sin errores.
