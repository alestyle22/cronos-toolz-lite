# Sitio base desde Toolz (Admin › Red Cronos)

Pedido del usuario (2026-11-10): manejar cronostoolz.com/base desde cronostoolz.com/toolz con la interfaz de Toolz, sin el iframe del panel propio del base.

## Cómo funciona
- En el Admin hay un grupo de menú **Red Cronos**. Lo ve solo el superadmin, y solo en el sitio cuyo `_private/site.php` tiene `base_url` y `base_sso_secret` (hoy, cronostoolz.com/toolz).
- Secciones:
  - **Sitios y versiones**: estado, versión, PHP, uso contra el plan, en línea o caído. Desde ahí se revisa e instala una versión con barra de progreso, se vuelve atrás, se ordena la lista (el primero hace de prueba), se agrega un sitio por código de conexión y se quita uno. También muestra las versiones publicadas.
  - **Licencias**: plan, vencimiento (+1 mes / +1 año), estado, topes propios, herramientas propias, notas. "Guardar y enviar" y "Quitar licencia".
  - **Planes**: crear, editar (llega a las agencias del plan) y borrar (no deja si alguna agencia lo usa).
  - **Banners**: crear y editar con vista previa, imagen, fechas, público (todas, algunas agencias o por plan), reenviar los pendientes y borrar.
  - **Tickets**: filtros por área, tipo, estado, agencia y texto. Detalle con capturas, responder (a la agencia le llega con el nombre de quien respondió), estado, tipo, prioridad, notas internas y borrar. Los no leídos se marcan en el menú.
  - **Estadísticas de la red**: totales, tabla por agencia, gráficos de los últimos 12 meses e historial mensual.
- En cada sección queda **"Abrir el panel del base"** (`base_sso.php`, el SSO de siempre) como respaldo.
- La barra de progreso de la instalación es estimada: el base no informa avance mientras instala. Muestra las etapas y los segundos, y se completa cuando el sitio responde.

## Tickets del base en Trabajo
> Desde los ajustes de Trabajo (2026-11-10, sin publicar): cada ticket del base que llega abierto crea también su **tarea** en el tablero del departamento, y la conversación es una sola (lo que se comenta en la tarea le llega a la agencia). Detalle en `TRABAJO.md`.

Pedido del usuario (2026-11-10): atender desde **Trabajo › Tickets** de cronostoolz.com/toolz todos los tickets que llegan al base.

**Qué entra.** Todos los tickets de `cronos-base/base/tickets_lib.php`:
- los de la web (formulario de demo de cronostoolz.com) van a la categoría **Comercial › Demo**;
- los que crea cada agencia desde su página Soporte (problema, mejora o plan) van a **Soporte**.

Las dos categorías se crean solas la primera vez. Después se pueden renombrar o asignar a un departamento en Tickets › Categorías y SLA: Trabajo guarda el id en `g7_config` (`work_base_cat_web` y `work_base_cat_sitio`), no el nombre. Sin departamento, los atienden los administradores, como cualquier categoría sin departamento.

**Cómo se ve.**
- En la lista, en vez de un usuario de Toolz aparece de dónde vino: "Laura Gómez · Agencia Norte" o "Sofía Paz · Paz & Asociados (web)".
- En el detalle hay una tarjeta **Del sitio base** con estos datos:
  - origen, tipo, persona y email;
  - empresa y teléfono (los de la web);
  - página, versión y navegador (los de las agencias);
  - número del ticket en el base;
  - capturas, que se piden al base con `tk_file` solo cuando alguien las abre.
- Arriba de la lista está **Sincronizar ahora**, con la hora de la última sincronización y los envíos pendientes.
- Los mensajes de la agencia se ven con su nombre. Lo que todavía no llegó al base se marca "Pendiente de enviar al base".
- En los tickets de la web, el contacto no ve las respuestas, así que la pantalla ofrece escribirle por email.

**Qué vuelve al base (y a la página Soporte de la agencia).**
- Las respuestas públicas, con el nombre visible de quien respondió.
- Los cambios de estado, incluido el cierre. Estados: `abierto` ↔ `nuevo`; `en_curso`, `esperando`, `resuelto` y `cerrado` son iguales en los dos lados.
- **Las notas internas de Trabajo nunca salen.** La asignación, la prioridad, el escalado y las tareas tampoco.
- Lo que se haga en el panel propio del base o en Admin › Red Cronos › Tickets también entra a Trabajo:
  - una respuesta entra con el nombre de quien respondió, seguido de "(desde el base)";
  - un cambio de estado entra como "Panel del sitio base lo pasó a…".

**Cómo se sincroniza.**
- Trae cambios cada vez que alguien abre Trabajo (como mucho una vez por minuto), con `trabajo/cron.php` y con "Sincronizar ahora".
- Una respuesta o un cambio de estado se manda enseguida, en el mismo pedido.
- Todo va por la API firmada de siempre (`bc_call`). Hay dos acciones nuevas, que el base solo acepta firmadas y que el proxy del navegador (`base_api.php`) rechaza (`BC_SERVER_ONLY`):
  - `tk_sync {since, limit}`: devuelve los tickets que cambiaron después de esa revisión, en orden, sin las notas internas del base;
  - `tk_sync_push {id, key, text?, status?, link?}`: guarda una respuesta, un estado o el vínculo con Trabajo.
- Ninguna de las dos se anota en el historial del base (serían cientos de líneas).

**Por qué no se pierde ni se repite nada.**
- **En el base:**
  - `tkb_tx` le da a cada ticket que cambia una revisión nueva (`rev`, creciente en todo el archivo) y a cada respuesta un id estable (`rid`).
  - Los tickets de antes se numeran la primera vez.
  - `tkb_push` no aplica dos veces la misma clave: las respuestas se reconocen por `rid` y los cambios de estado por `wk_done`, donde quedan las últimas 100 claves.
  - Así, un reenvío no duplica una respuesta ni pisa un estado más nuevo.
- **En Trabajo:**
  - `g7_wk_tickets.base_id` es único: cada ticket del base se crea una sola vez.
  - `g7_wk_ticket_msgs.base_rid` es único por ticket: cada mensaje entra una sola vez. Las respuestas propias guardan su clave al salir, así que no se duplican cuando vuelven en la siguiente sincronización.
  - Lo que sale va por la cola `g7_wk_base_out`, en orden. Si el base no responde, la cola se detiene y se reintenta en la próxima sincronización. Si el base rechaza un envío (por ejemplo, porque borraron el ticket allá), ese envío queda marcado con una nota interna en el ticket y la cola sigue.
  - La revisión hasta la que se trajo queda en `g7_config.work_base_rev` y avanza ticket por ticket.
- **Si el estado cambia en los dos lados:** gana el base, salvo que Trabajo tenga un cambio de estado todavía sin entregar.
- **Si el base cambia de lista:**
  - Si restauran un respaldo con una revisión más vieja, Trabajo vuelve a pasar por todo. Nada se duplica.
  - Si el base empieza una lista nueva (cambia `_private/tickets_epoch.txt` y los números se repiten), los tickets que ya estaban en Trabajo quedan con su historia pero sin vínculo, y lo del base entra aparte.

**Tickets que ya existían.**
- La primera sincronización trae todos los tickets que hay en el base, incluidos los cerrados, con su conversación, sin avisos y con el SLA contado desde que se crearon en el base.
- Si alguno llega con el plazo ya vencido, queda marcado como vencido pero **no se escala ni avisa**. Lleva una nota interna que lo explica, así no hay una tanda de alertas.
- Los tickets que lleguen después sí avisan al departamento de la categoría (o a los administradores).
- En el panel del base y en Admin › Red Cronos › Tickets, los tickets vinculados dicen "Se gestiona en Trabajo (T-…)". Se pueden seguir respondiendo desde ahí: todo entra a Trabajo.

**Archivos.**
- **Base:**
  - `cronos-base/base/tickets_lib.php`: `rev`, `rid`, `tkb_since`, `tkb_push` y `tkb_epoch`;
  - `cronos-base/base/index.php`: `tk_sync` y `tk_sync_push`, y la marca "En Trabajo" en el panel.
- **Toolz:**
  - `site/trabajo/base_sync.php` (nuevo);
  - `site/trabajo/api.php`: enganches en `boot`, `tickets`, `ticket_get`, `ticket_msg` y `ticket_update`, más las acciones `ticket_base_sync` y `ticket_base_file`;
  - `site/trabajo/trabajo.js`;
  - `site/trabajo/cron.php`;
  - `site/base_client_lib.php`;
  - `site/base_api.php`: rechaza las acciones de sincronización y manda el nombre visible, no el usuario;
  - `site/admin_base.js`.
- **Esquema:** columnas `base_id` y `base_info` en `g7_wk_tickets`, `ext_name` y `base_rid` en `g7_wk_ticket_msgs`, y la tabla `g7_wk_base_out`. Se crean solas (`wb_install`, bandera `work_base_schema`) y solo en el Toolz conectado al base: en las agencias no cambia nada.
- **Prueba:** `dev/test_base_tickets.php`. Ver "Probar en local".

## Cobro de planes (Finanzas y pagos, 2026-11-13, sin publicar)
- Dos acciones firmadas nuevas en el base, **solo desde el servidor de Toolz**: Toolz las tiene en `BC_SERVER_ONLY` y el proxy del navegador no las acepta.
  - `lic_list`: agencias con plan y vencimiento, y los planes.
  - `lic_extend`: `{site, plan_id, months, ref}`. Suma meses al vencimiento y entrega la licencia, una vez por `ref` (la operación de pago). Las referencias quedan en `paid_refs`.
- La usa `site/pagos/plans.php`. Detalle en `PAGOS.md` › Planes de las agencias.
- Pruebas: la sección "Cobro de planes" de `dev/test_base_api.php`.

## Seguridad
- El navegador habla solo con `site/base_api.php`. Ese archivo exige:
  - sesión de Toolz;
  - superadmin real (`g7_is_super`);
  - POST;
  - CSRF propio de la sección (`X-CSRF`, guardado en `$_SESSION['g7_base_csrf']`);
  - una lista cerrada de acciones (`BC_ACTS` y `BC_OPS` en `site/base_client_lib.php`).
- Las acciones que cambian algo quedan en la Auditoría de Toolz como `base.<acción>`.
- El servidor de Toolz firma el pedido y lo manda a `base_url` + `?api=1`. Encabezados:
  - `X-Toolz-Ts`
  - `X-Toolz-Nonce` (32 hex)
  - `X-Toolz-User` (rawurlencode del nombre visible)
  - `X-Toolz-Sig`
- La firma es `HMAC-SHA256(toolz_sso_secret, "toolz-base-api-v1\nPOST\n<ts>\n<nonce>\n<user>\n" + sha256(cuerpo))`. Las dos copias de la fórmula están en `b_api_sig` (base) y `bc_sign` (Toolz).
- El base (`b_api_signed` en `cronos-base/base/index.php`) rechaza con 403:
  - las firmas que no coinciden (cuerpo o usuario alterados, clave equivocada);
  - las que tienen más de 120 s de diferencia (en cualquier sentido);
  - los nonces ya usados (`_private/api_nonces.json`, con candado);
  - lo que no es POST.
- Un pedido firmado no usa la sesión del panel. El login y la UI propia del base siguen iguales. Las acciones firmadas que cambian algo se anotan en el historial del base como `Toolz: <acción>`, con el usuario.
- Acción nueva en el base: `tk_file`, que devuelve una captura de un ticket en base64 para verla desde Toolz. Solo acepta nombres del patrón de capturas.
- La clave nunca llega al navegador. La prueba lo verifica en el HTML del Admin.

## Archivos
- **Base:** `L:\Kenia\cronos-base\base\index.php` (API firmada, `tk_file`, `$who` del usuario de Toolz en las respuestas).
- **Toolz** (`toolz/site`):
  - `base_client_lib.php` (nuevo)
  - `base_api.php` (nuevo)
  - `admin_base.php` (reescrito: ya no hay iframe)
  - `admin_base.js` (nuevo)
  - `admin.php` (menú Red Cronos en lugar de "Sitio base")
  - `site_lib.php` (gancho `G7_SITE_EXTRA`, que solo se usa en pruebas locales con `auto_prepend_file`)
- **Pruebas:** `dev/test_base_api.php` y `dev/base_local/` (`setup.php`, `sim_remote.php`, `sims_router.php`).

## Probar en local
1. `php -d extension=gd dev/base_local/setup.php --reset`
   - Copia el base y siembra datos.
   - Agencias simuladas: Agencia Norte 1.0.12 con licencia Pro por vencer, Inmobiliaria del Sur 1.0.11 y una caída.
   - Además: 3 tickets (uno con captura), un banner y 4 meses de historial.
   - Deja en `dev/base_local/codigo_salud_centro.txt` un código para probar "Agregar sitio".
2. Servidores (en `L:\Kenia\.claude\launch.json`):
   - `toolz-mysql` (MariaDB de XAMPP, :3306)
   - `base-local` :8151
   - `base-sims` :8152
   - `toolz-base-test` :8153 (Toolz con `base_url` al base local)
   - **No usar 8141/8142**: los usa `dev/test_update.php`.
3. Entrar en http://localhost:8153/toolz/login.php como `admin`, con la contraseña de `dev/LOCAL.md`. Ir a Admin › Red Cronos.
4. El panel propio del base local está en http://127.0.0.1:8151/base/. Contraseña de prueba: `base-local-prueba`.
5. Correr `php dev/test_base_api.php`. Resultado: 35 pruebas. Cubren firma válida, vencida, del futuro, repetida, cuerpo o usuario alterado, clave equivocada, GET, sesión vieja del base, proxy sin sesión, sin superadmin, sin CSRF y acción desconocida. La versión que revisa por el proxy es la más nueva del catálogo, porque el catálogo guarda solo las últimas.
6. Tickets en Trabajo: `G7_TEST_DB="127.0.0.1;port=3407" php -d extension=gd dev/test_base_tickets.php` (49 pruebas).
   - Necesita el base local en :8151.
   - Arma su propia base `wb_test` en MariaDB :3407 y levanta su propio Toolz en :8157 (prepend en `dev/tmp/`, que se borra al terminar).
   - Cubre:
     - la primera sincronización y los tickets viejos vencidos;
     - la idempotencia (sincronizar dos veces, perder el cursor, reenviar todo);
     - la respuesta pública y la nota interna que no sale;
     - los estados, el cierre y la respuesta de la agencia;
     - el base caído y su vuelta;
     - el envío rechazado;
     - los permisos;
     - las capturas;
     - el respaldo restaurado y el base que empieza de cero.
   - A mano: entrar en http://localhost:8153/toolz/trabajo/#tickets. Para hacer de agencia, usar `base/tickets_api.php` firmado con la clave de `agencia_norte` de `base_local/www/base/_private/sites.json`, como hace la prueba.

## Publicar los tickets en Trabajo (PUBLICADO 2026-11-10 21:00 con OK del usuario; falta instalar la 1.0.23 en cronostoolz.com/toolz)
Respaldos: `backups/20261110_210036_base_tickets_trabajo` (base) y `backups/20261110_210039_base_rel_1023` (catálogo). El instalador 1.0.23 está armado pero no se subió.
1. **Base primero:** subir `cronos-base/base/tickets_lib.php` e `index.php` a cronostoolz.com/base/, con respaldo de los actuales.
   - Es compatible: un Toolz sin la versión nueva no usa `tk_sync` y el panel sigue igual.
   - La primera vez que se toque un ticket, los de antes reciben `rev` y `rid`.
2. **Toolz 1.0.23**, con estos archivos de `site/`:
   - `trabajo/base_sync.php` (nuevo)
   - `trabajo/api.php`
   - `trabajo/trabajo.js`
   - `trabajo/cron.php`
   - `base_client_lib.php`
   - `base_api.php`
   - `admin_base.js`

   Antes, comparar `site/` contra el manifest de la 1.0.22. Instalarla en cronostoolz.com/toolz desde el panel.
3. Abrir Trabajo › Tickets en cronostoolz.com/toolz: la primera sincronización trae los tickets que ya hay, sin avisos. Revisar que aparezcan las categorías Comercial › Demo y Soporte, y asignarlas a un departamento si hace falta.

## Publicar (requiere el OK del usuario; NO hecho)
Orden:
1. **Base primero.** Subir `cronos-base/base/index.php` a cronostoolz.com/base/index.php, con respaldo del actual.
   - Es compatible: el panel propio y el SSO siguen andando, y la API firmada queda lista aunque Toolz todavía no la use.
   - `toolz_sso_secret` ya está en su `_private/site.php`: no hay que tocar claves.
2. **Toolz después.** Armar una versión (`dev/release.php`) con estos archivos de `site/`: `base_client_lib.php`, `base_api.php`, `admin_base.php`, `admin_base.js`, `admin.php` y `site_lib.php`.
   - Antes, comparar contra el manifest de la última versión, por cambios de otras sesiones.
   - Instalarla en cronostoolz.com/toolz desde el panel.
   - En las demás agencias no cambia nada visible: sin `base_url`, el menú Red Cronos no aparece.
3. Comprobar en cronostoolz.com/toolz › Admin › Red Cronos › Sitios que carga el estado.

## Actualizar toda la red (2026-11-12, sin publicar)

- **Red Cronos › Sitios y versiones › Actualizar toda la red:**
  - **Actualizar todos a X:** instala la última versión en cada sitio atrasado, uno por uno y en el orden de la lista, con el mismo `apply` de a uno (el base sigue comprobando que el anterior esté al día). Muestra el avance por sitio y se frena en el primer problema: sitio sin respuesta, instalación a medias o instalación que falla y vuelve atrás sola. Los siguientes quedan como estaban.
  - **Actualización automática:** interruptor (acción `auto_save` del base). Con ella prendida, el cron del base instala cada versión nueva sin intervención, con las mismas reglas.
- **Base:**
  - `sites_lib.php`: `b_sites`, `b_save`, `b_call` y `B_SITES`, separados de `index.php`;
  - `auto_update_lib.php`: `b_auto_run`, con candado para que nunca corran dos a la vez y con la última corrida guardada en `_private/auto_update.json`, que el panel muestra;
  - `cron_update.php`: solo por línea de comandos.
- **Cron en hPanel** (por ejemplo, cada hora): `php /home/<usuario>/public_html/base/cron_update.php`. Con `--ya` corre aunque la automática esté apagada (prueba a mano).
- **Al subir el base** van `index.php`, `sites_lib.php`, `auto_update_lib.php` y `cron_update.php`. `dev/base_local/setup.php` ya los copia.
- **Probado contra el base local:**
  - las dos agencias simuladas pasaron de 1.0.12 y 1.0.11 a 1.0.46 en orden y se frenó en la caída;
  - `auto_save` prende y apaga;
  - `test_base_api` 42/42 y `test_base_tickets` 49/49.


## Afiliados: puente de "Recomienda Cronos" (2026-11-16, sin publicar)
- **Archivos nuevos:** `base/aff_lib.php` y `base/aff_api.php`. Detalle completo en `AFILIADOS.md`.
- **De la agencia** (`aff_api.php`, con la clave del sitio, como `tickets_api.php`):
  - `me`: su resumen, la clave pública y lo que tiene en cola;
  - `lead`: una agencia recomendada, con un tope de 20 por hora;
  - `prefs`: descontar o cobrar, con los datos de cobro sellados (`s1:…`, que el base no puede abrir).
- **De nuestro Toolz** (`index.php`, acción firmada `aff_sync`, solo de servidor en `BC_SERVER_ONLY`): confirma lo guardado (`ack`), deja la clave pública y los resúmenes de todas las agencias, y devuelve la cola (hasta 200).
- **Guardado:** `_private/aff.json`, con candado:
  - la cola tiene un tope de 5000;
  - quedan las últimas 1000 claves confirmadas, así un reintento no se encola dos veces.
- **La landing** (`contacto.php`) encola los pedidos de demo que llegan con la cookie `ct_ref`.

## Catálogo Cronos (herramientas para la Biblioteca, 2026-11-18, sin publicar)
- Admin › Red Cronos › Herramientas carga herramientas web (un HTML cada una) en `base/_private/herramientas/`; las agencias las ven en Biblioteca › Catálogo Cronos y las copian. Acciones firmadas `tool_list`, `tool_get`, `tool_save`, `tool_delete` y `tool_move`; las agencias usan `base/herramientas_api.php`. Detalle en `CATALOGO.md`.
