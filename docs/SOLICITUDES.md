# Portal de Solicitudes y Tickets por departamento

Pedido del usuario: 2026-11-13. Plan aprobado en `SOLICITUDES_PLAN.md`.

**Estado:** hecho y probado en local, contra la base de prueba y contra `toolz_local`. **No se publicó ni se instaló en ningún sitio.**

## Decisiones del usuario
| | Decisión |
|---|---|
| A | El portal **entra en la Lite**. Vive en `trabajo/`, que ya viaja en la Lite, así que no hubo que tocar `editions.php`. `test_lite` pasa 74/74. |
| B | Hay 9 etapas nuevas, pero **los 5 estados de siempre siguen siendo los principales**. Cada etapa se apoya en uno de esos estados (ver "Etapas"). |
| C | La aprobación es **opcional** por departamento o por formulario, y viene apagada. También la pide un elemento "Aprobación" del formulario. |
| D | El portal **reemplaza** a Trabajo › Solicitudes. Los formularios que ya existían pasan solos al formato nuevo y quedan publicados como versión 1. |
| E | La **asignación automática** es una opción que cada departamento prende si quiere. |

## Dónde está cada cosa
Todo está en `site/trabajo/`.

**En el servidor:**
- `form_lib.php`: el esquema de los formularios (formato 2), las condiciones Y/O, la validación y la conversión de los formularios viejos.
- `estimate_lib.php`: el motor de estimación.
- `portal_lib.php`: el esquema v9, los departamentos y sus roles, las etapas, la disponibilidad, la capacidad, el reparto de la carga, las fechas alternativas, la asignación automática, los avisos, las automatizaciones y el barrido de vencimientos.
- `portal_api.php`: toda la API del portal.
- `portal_metrics.php`: los números del tablero de métricas.
- `portal_file.php`: entrega los adjuntos, banners e imágenes, siempre después de mirar el permiso.

**En la pantalla:**
- `form_render.js`: dibuja el formulario y valida mientras se llena. Lo usan el portal y la vista previa del constructor.
- `form_editors.js`: los editores de condiciones y de reglas de tiempo.
- `portal.js`: el portal, el llenado del formulario, el calendario y la ficha de cada solicitud.
- `portal_admin.js`: Recibidas, la configuración de los departamentos, las etapas y las métricas.
- `portal.css`.
- `builder.php`, `builder.js` y `builder.css`: el constructor de formularios.

**Cambios en archivos que ya existían:**
- `lib.php`: carga `portal_lib.php` y corre la instalación v9.
- `link_lib.php`: `wk_tk_set` llama a `pt_on_ticket_set`, que pone al día la etapa y recalcula la carga.
- `api.php`: Tickets muestra las respuestas de los pedidos del portal con su versión.
- `trabajo.js`: `api()` devuelve el detalle del error en `e.data`.
- `index.php`: suma los ítems del menú y los scripts.
- `tareas_lib.php`: avisos nuevos `pt_*`.

## Portal aparte (módulo Solicitudes)
Pedido del usuario, 2026-11-13: el portal también tiene que poder usarse **sin entrar a Gestión de trabajo**.

- **Dónde:** `/toolz/solicitudes/`, con su propio ítem "Solicitudes" en el menú (aparece una vez al lado de Trabajo; el admin lo puede sacar o mover).
- **Permiso propio:** es el módulo `solicitudes` de Accesos, abierto a todos por defecto. Quien no tiene Trabajo igual pide, sigue sus pedidos y, si es del departamento, atiende Recibidas. Si a alguien le sacan Solicitudes, sigue pidiendo desde Trabajo como antes.
- **Es el mismo portal, no una copia:** la página carga `trabajo/portal.js`, `portal_admin.js`, `form_render.js` y `form_editors.js`. Lo único propio es `solicitudes/app.js`, que trae las piezas chicas de `trabajo.js` que el portal usa (`api`, `toast`, `av`…) y el menú.
- **Las llamadas** van a `solicitudes/portal_api.php`, `portal_file.php` y `builder.php`, que solo cargan los de `trabajo/`. Así rige el permiso de Solicitudes y no el de Trabajo.
- **Secciones:** Nuevo pedido, Mis solicitudes, Recibidas, Métricas y Departamentos (las tres últimas según el rol), más "Ir a Trabajo" para quien lo tiene.
- **Avisos:** los `pt_*` llegan con el tipo `pt_tk` y abren `/toolz/solicitudes/#solicitudes/t/ID` (también el link del email). A quien no tiene el módulo Solicitudes le siguen abriendo Trabajo (`wk_tk`).
- **Sigue estando en Trabajo › Solicitudes**, igual que antes. El historial de solicitudes de antes del portal se ve solo desde Trabajo.
- **Otros cambios:** `access.php` (módulo nuevo), `topbar.php` (link en el menú), `rubro_lib.php` (ningún rubro lo apaga), `assets/topbar.js` (aviso `pt_tk`), `portal_api.php` (`boot` devuelve los equipos) y `portal.js` (un solo pedido de arranque aunque lo pidan dos a la vez). Entra en la Lite.

## Cómo funciona

### Departamentos
- Un departamento es un **equipo**: el de Admin › Equipos. "Nuevo departamento" (solo administración) crea el equipo.
- `g7_wk_dept` guarda la ficha del portal:
  - descripción, ícono, color y banner;
  - visibilidad: todos, algunos equipos o algunas personas;
  - horario semanal, tope diario, margen de seguridad y reserva para urgencias;
  - anticipación mínima (en horas) y máxima (en días);
  - umbrales de color;
  - política de sobrecarga;
  - si muestra la estimación y en qué unidad;
  - si los pedidos necesitan aprobación y si la asignación es automática.
- Cada integrante tiene su fila en `g7_wk_member_cap`: si produce, su dedicación en %, su horario propio, si es responsable y sus especialidades (categorías).
- Las ausencias van en `g7_wk_dayoff`, con cuatro alcances:
  - **feriado:** para todos; lo carga solo administración;
  - **bloqueo:** para el departamento;
  - **vacaciones o ausencia:** para una persona; puede ser el día entero o algunos minutos.
- Las **excepciones por fecha** (`g7_wk_day_cap`) fijan la capacidad total de un día. Sirven, por ejemplo, para un sábado de entrega o para cerrar un día.

### Roles
| Rol | Quién es | Qué puede hacer |
|---|---|---|
| Administrador general | Admin o director de Trabajo | Todo |
| Administrador de departamento | Jefe del equipo | Configura su departamento, sus formularios, sus categorías y sus reglas |
| Responsable | Marca por departamento | Aprueba, asigna, cambia fechas y estimaciones, y ve la configuración |
| Miembro | Integrante del equipo | Atiende lo suyo: mueve las etapas de lo que tiene asignado, carga horas y escribe notas |
| Solicitante | Cualquiera que la visibilidad deje pedir | Pide, ve sus pedidos sin lo interno, responde y cancela antes de que empiecen |

### Formularios (constructor)
- Se abre en `builder.php?id=N`, desde Departamentos › Formularios.
- **Pantalla:** a la izquierda los elementos (se arrastran o se suman con un clic), en el centro el lienzo, que muestra el formulario tal como se ve, y a la derecha las propiedades.
- **Pestañas de propiedades:** Elemento, Sección o paso, Formulario, Diseño y Versiones.
- **Arrastre** con eventos de puntero, así anda con mouse, lápiz y dedo. Se puede mover dentro de una sección, entre secciones y adentro de un grupo, con una línea que marca dónde cae.
- Deshacer y Rehacer (Ctrl+Z y Ctrl+Y), y Suprimir para quitar.
- **Guardado:** el borrador se guarda solo a los 1,5 s. Si otra persona guardó en el medio, avisa y deja elegir.
- **Elementos (25):** texto corto y largo, número, email, teléfono, desplegable, selección múltiple, opción única, casilla, fecha, hora, color, archivo, imagen, link, prioridad, categorías visuales, separador, encabezado, descripción, bloque informativo, aprobación, oculto interno y grupo. "Persona" sigue existiendo solo para los formularios viejos.
- **Propiedades de cada elemento:**
  - etiqueta, ayuda y texto de ejemplo;
  - obligatorio y valor por defecto;
  - opciones, cada una con ícono, color y tiempo;
  - validaciones: largo, formato, rango, cantidad elegida, tipos y peso de los archivos, fechas mínima y máxima;
  - id interno: si se cambia, se cambia también en las condiciones y en las reglas;
  - ancho y orden.
- **Condiciones:** grupos de "todas" (Y) o "alguna" (O) sobre texto, números, fechas, casillas, listas y categorías. Sirven para mostrar un campo, una sección o un paso.
- **Modalidades:**
  - **Por pasos:** barra de progreso, valida cada paso, resumen al final y "Guardar y seguir después" (el borrador queda en el servidor). Los pasos Fecha y Resumen los agrega el portal solo.
  - **Completo:** secciones, columnas y el calendario al final.
- **Diseño:** colores (principal, fondo, campos y texto), tipografía, redondeo, espacios, estilo de los botones, fondo y portada. La vista previa se ve en escritorio y en celular, con el tiempo calculado en vivo.
- **Versiones:**
  - "Publicar" crea una versión que ya no cambia (`g7_wk_form_versions`).
  - Cada ticket guarda en `form_version_id` la versión con la que nació, y su ficha muestra las respuestas con esa estructura.
  - Una versión se puede volver a usar como borrador.
- **Plantillas y copias:** se puede duplicar un formulario, guardarlo como plantilla del departamento y crear uno nuevo desde una plantilla.

### Categorías y estimación
- Hay tres niveles: categoría, subcategoría y variante (`g7_wk_ticket_cats.parent_id`).
- Cada una tiene ícono, imagen, color, descripción, tiempo base, mínimo y máximo, componentes, reglas y formulario asociado.
- Si una categoría tiene formulario asociado, su tarjeta en la página del departamento lleva a ese formulario.
- El motor (`pt_estimate`) solo corre en el servidor. Aplica las reglas en este orden:
  1. base de la hoja (o del padre más cercano que tenga);
  2. reemplazos;
  3. alternativas excluyentes;
  4. sumas y "por unidad";
  5. rangos de cantidad;
  6. multiplicadores;
  7. tope entre el mínimo y el máximo.
- Las reglas salen de las opciones y casillas, de los campos numéricos, de las reglas del formulario y de las de la categoría y sus padres. Cada regla puede tener una condición.
- **Lo que manda el navegador como tiempo se ignora siempre.** La prueba manda `est_min: 5` y el ticket queda con 360.
- Se distinguen tres cosas:
  - **tiempo de trabajo** (minutos);
  - **tiempo calendario** (días corridos hasta la entrega posible);
  - **fecha de entrega** (la pedida y la comprometida).

### Capacidad y calendario
- **Capacidad de un día:**
  - es la suma, por integrante que produce, de sus horas por su dedicación;
  - se descuentan ausencias y feriados;
  - se aplica el tope del departamento, o la excepción de ese día si hay una;
  - al resultado se le resta el margen de seguridad.
- **Reserva para urgencias:** solo las prioridades urgente y crítica pueden usarla.
- **Carga:** `g7_wk_load` guarda cuántos minutos ocupa cada ticket cada día.
  - El trabajo se reparte **hacia atrás** desde la fecha de entrega, en días laborables y sin pasar lo libre.
  - Lo que no entra queda en el día de entrega marcado `is_over`, y el ticket queda con `overload = 1`.
  - Con responsable, también se respeta lo libre de esa persona.
- **Recálculo** (`pt_recalc`): borra y vuelve a escribir el reparto del ticket en una transacción, con el candado del departamento. Así **nunca se cuenta dos veces**.
  - Corre solo, por el gancho de `wk_tk_set`, cada vez que cambia el estado, la etapa, la estimación, la fecha, el responsable, las horas reales, la cancelación, la aprobación o la prioridad.
  - Si cambian los horarios, los integrantes, las ausencias o las excepciones, se recalcula todo el departamento (`pt_recalc_team`). Los tickets que vencen antes eligen primero.
  - Completado y cancelado liberan la capacidad.
  - Lo que falta se calcula como estimado menos horas reales.
- **Concurrencia:** cada departamento tiene un candado con nombre (`GET_LOCK`). El envío revisa los duplicados, mira la capacidad, crea el ticket y guarda la carga sin soltarlo.
- **Colores del calendario:** gris para lo que no es laborable, ya pasó o queda fuera de la anticipación permitida. Para el resto, según cómo quedaría el reparto si se elige ese día:
  - rojo si queda sobrecarga;
  - verde hasta el umbral verde;
  - amarillo hasta el umbral amarillo.
- **Detalle del día:** capacidad, comprometido, disponible, horas del pedido, % proyectado con la fórmula pedida ((comprometidas + nueva) / capacidad × 100), cantidad de solicitudes, cómo se reparte en varios días, quién trabaja ese día (solo para quien atiende) y hasta 3 fechas alternativas.
- **Fecha llena:** nunca se bloquea del todo. Según la política del departamento:
  - **advertir:** se confirma y se envía;
  - **pedir motivo:** hay que contar por qué;
  - **solo responsables:** el resto tiene que elegir otra fecha.

  En todos los casos se sugieren alternativas, y la sobrecarga queda en el ticket, en su historial y en la auditoría.

### Tickets
- Son los de siempre: `g7_wk_tickets`, con su tarea, su SLA y su conversación única.
- Se sumaron estos campos: `stage`, `est_min`, `est_detail`, `real_min`, `requested_date`, `committed_date`, `form_version_id`, `approval`, `approver_id`, `overload`, `overload_note`, `dedupe_key` (único), `cancelled`, `started_at` y `completed_at`.

**Etapas.** Se configuran en Departamentos › Etapas: nombre, color y transiciones. Las 9 de fábrica no se pueden borrar.

| Etapa | Estado principal |
|---|---|
| Nuevo, Pendiente de revisión, Aprobado | Abierto |
| En progreso, En revisión | En curso |
| Esperando información, Pausado | Esperando (frena el SLA) |
| Completado | Resuelto |
| Cancelado | Cerrado, con marca de cancelado |

Si el estado cambia desde Tickets, desde la tarea o desde el sitio base, la etapa lo sigue.

**Vistas (Recibidas).**
- Tabla con orden por columna, kanban por etapa (arrastrar respeta las transiciones), calendario por fecha comprometida, por departamento y por responsable. Los pedidos propios están en Portal › Mis solicitudes.
- Filtros: búsqueda, departamento, etapa, prioridad, responsable, abiertas o cerradas, atrasadas y con sobrecarga.
- Los filtros guardados quedan en `g7_user_prefs` (`wk_pt_filters`).
- La ficha se abre en un panel lateral.

**Asignación.**
- A mano o en automático: a quien le alcanza la capacidad, después especialidad, después más horas libres hasta la entrega, después menos pendientes y menos horas comprometidas.
- **Nunca** asigna a alguien ausente todo el período, sin horas o que no toma trabajo. "Auto" muestra el ranking y por qué saltea a cada uno.

**Comunicación.**
- Respuestas públicas y notas internas, con adjuntos, menciones (`@usuario`) y eventos.
- **Pedido de información:** pasa a "Esperando información". Cuando quien pidió responde, vuelve a la etapa anterior.
- Aprobar o rechazar (el rechazo pide motivo). Al aprobar se puede asignar en el mismo paso.
- Quien pide ve el recorrido de su pedido. No ve las notas internas, los campos ocultos internos, la carga por día ni las horas.

**Horas reales.** Las carga quien atiende (`g7_wk_tk_time`). Lo que falta se vuelve a repartir.

### Avisos y automatizaciones
- **Avisos nuevos** en `TK_EVENTS`. Cada persona los elige como siempre (campana, email y navegador):
  - `pt_received`: recibido;
  - `pt_approval`: espera aprobación;
  - `pt_approved` y `pt_rejected`: aprobado y rechazado;
  - `pt_assignee`: cambio de responsable;
  - `pt_date`: cambio de fecha;
  - `pt_info`: pedido de información;
  - `pt_started`: iniciado;
  - `pt_done`: completado;
  - `pt_due_soon`: se entrega mañana;
  - `pt_late`: atrasado;
  - `pt_overload`: entró con sobrecarga.
- Los comentarios usan el `tk_reply` de siempre.
- "Por vencer" y "atrasado" salen una sola vez por ticket y fecha, con el barrido de cada 10 minutos.
- **Automatizaciones** por departamento (`g7_wk_pt_autos`):
  - **cuándo:** entra, se aprueba, se rechaza, cambia de etapa, cambia la fecha, hay un comentario, se entrega mañana o está atrasada;
  - **condiciones Y/O:** prioridad, categoría, etapa, estimación, sobrecarga y formulario;
  - **acciones:** asignar (a una persona o en automático), cambiar la etapa, cambiar la prioridad, avisar o agregar una nota interna.

  Las acciones no disparan otras reglas.

### Métricas
Se filtran por período (7 días, 30 días, 90 días o 12 meses) y por departamento, y se comparan con el período anterior:
- **Tickets:** nuevos, completados, pendientes, en curso, esperando, atrasados, sin responsable y por aprobar.
- **Tiempos:** resolución promedio y estimado contra real.
- **Por departamento:** ocupación de los próximos 14 días (carga sobre capacidad) y del período (horas reales sobre capacidad).
- **Gráficos:** nuevas y completadas por día o por semana, abiertas por etapa y por categoría.
- **Por integrante:** productividad (completadas, estimado, horas cargadas, % a tiempo, abiertas y lo que queda por hacer).

### Seguridad
- Los permisos y la validación se deciden en el servidor en cada pedido. El servidor vuelve a evaluar las condiciones: un campo oculto ni se valida ni se guarda, y el "oculto interno" siempre toma su valor por defecto.
- **Adjuntos:**
  - se suben en partes de 1 MB con lista blanca de extensiones (sin php, exe, etc.);
  - las imágenes se verifican con `getimagesize`;
  - se guardan en `_private/solicitudes/<ticket>/` (con `Require all denied`);
  - los entrega `portal_file.php` después de mirar el permiso: a alguien ajeno le responde 404 y sin sesión, 401.
- **Anti-duplicados:** cada envío lleva una clave. El mismo envío devuelve el mismo ticket, y eso se revisa también dentro del candado.
- **Auditoría:** `g7_wk_pt_audit` registra la configuración, los envíos, la sobrecarga, las etapas, las aprobaciones, las asignaciones y los cambios de fecha. Se ve en Departamentos › Historial. Las publicaciones de formularios quedan además en `g7_audit`.
- **Sin datos ficticios:** el portal arranca vacío y sin categorías ni formularios de ejemplo.

## Subdepartamentos y subequipos (1.0.84, completa y Lite)

Un departamento puede tener subdepartamentos. Como un departamento es un equipo de Admin › Equipos, un subdepartamento es también un subequipo.

- **Dónde se guarda:** en `g7_teams.parent_id`. La columna la crea sola `teams_lib.php` la primera vez (`g7_config` `teams_schema` = 1). En instalaciones nuevas ya viene en `install/schema.php`.
- **Niveles:** hasta 4 (`G7_TEAM_MAX_DEPTH`), es decir, departamento › sub › sub › sub. No se permiten ciclos.
- **Configuración:** un subdepartamento es un departamento completo, con su gente, horarios, capacidad, categorías, automatizaciones y sus propios formularios.
  - Al crearlo arranca con la configuración de su departamento (horarios, capacidad, quién puede pedir, aprobación, anticipación) y con sus permisos de módulos.
  - Después se cambia aparte.
- **Jefe:**
  - El jefe del subdepartamento (admin de su equipo) lo configura y arma sus formularios.
  - La jefatura de arriba también lo administra: `wk_ctx` suma a `lead` todos los departamentos de abajo, e `isTeamAdmin` mira los equipos de arriba.
  - El jefe de un subdepartamento lo nombra o lo saca administración o la jefatura de arriba (`pt_can_appoint`). El propio jefe del subdepartamento no puede.
- **Quién crea:**
  - Un subdepartamento lo crea quien configura el de arriba (`dept_create` con `parent_id` y `lead_id` opcional), si no se pasa de niveles (`pt_can_sub`).
  - Los departamentos de primer nivel los sigue creando solo administración.
- **Mover:** solo administración, desde General › Depende de (`dept_save` con `parent_id`) o desde Admin › Equipos › Dentro de. Al moverlo, su rama va con él.
- **Borrar un equipo:** sus subequipos suben un nivel (`g7_team_before_delete`).
- **Avisos y escalado:** un subdepartamento sin jefe le avisa a la jefatura de arriba (`wk_team_people` con `$leads`), y si nadie, a administración.
- **Portal (quien pide):**
  - El inicio muestra los departamentos de primer nivel; los subdepartamentos aparecen al buscar.
  - En la página de un departamento se ven sus subdepartamentos, y las migas llevan hasta los de arriba.
- **Configurar:** la pestaña **Subdepartamentos** lista cada uno con su jefe, su gente y sus formularios, y tiene el botón **Nuevo subdepartamento**.
- **Trabajo:** los selectores de departamento muestran el camino ("Marketing › Redes").
- **Admin › Equipos y la página Equipos:**
  - árbol, **Dentro de** y **Nuevo subequipo**;
  - el admin de un equipo ve y administra sus subequipos aunque no sea miembro;
  - el respaldo de configuración guarda `parent_id`.
- **Prueba:** `dev/test_subdepartamentos.php` (64 pruebas).

## Pruebas
```bash
G7_TEST_DB="127.0.0.1;port=3407" php -d extension=gd dev/test_portal_solicitudes.php
```
- Da **102/102** (los últimos 9 son del portal aparte). Crea la base `pt_test` y levanta 4 servidores propios en :8171–8174, porque el servidor de PHP en Windows atiende un pedido a la vez y la prueba de concurrencia necesita pedidos simultáneos de verdad.
- Los adjuntos de la prueba van a `dev/tmp/pt_files`.

**Las otras suites siguen pasando:**

| Suite | Resultado | Nota |
|---|---|---|
| `test_trabajo_tickets` | 87/87 | Ahora espera el esquema 9 |
| `test_base_tickets` | 49/49 | |
| `test_tk_canned` | 18/18 | |
| `test_tk_colab` | 25/25 | |
| `test_lite` | 74/74 | |
| `test_entregas` | 34/34 | |
| `test_chat_acciones` | 26/26 | |
| `test_vencimientos` | 52/52 | |
| `test_game_reports` | 83/83 | |

`brand_scan` no encuentra ninguna marca y `edition_deps lite` no encuentra cargas rotas.

### Los 20 criterios
| # | Criterio | Dónde se comprobó |
|---|---|---|
| 1 | Crear un departamento | Prueba §1 y navegador |
| 2 | Horarios, capacidad, ausencias, feriados y excepciones | Prueba §1 y navegador (Horarios y capacidad) |
| 3 | Categorías con ícono y tiempos | Prueba §2 y navegador (Placas con 5 variantes) |
| 4 | Variantes y reglas con la estimación correcta | Prueba §4: 180, 360 con tope, campo oculto que no suma, 30 |
| 5 | Formulario desde cero arrastrando | Navegador: arrastre desde la paleta y para reordenar; prueba §3 |
| 6 | Modalidad por pasos | Navegador: validación por paso, resumen y borrador |
| 7 | Modalidad completa | Prueba: versión 2 en modo completo |
| 8 | Campos condicionales también en el servidor | Prueba §4 y navegador ("Lleva sonido" solo con animada) |
| 9 | Publicar con versión y vista previa desktop y mobile | Prueba (versiones 1 y 2) y navegador |
| 10 | Crear la solicitud y ver el cálculo | Navegador: 6 h = 4 h + 1 h + 30 min + 30 min |
| 11 | Capacidad con colores y detalle del día | Prueba §4 y navegador |
| 12 | Fechas alternativas | Prueba §4 y navegador |
| 13 | Enviar con sobrecarga según la política, y que quede registrada | Prueba §4 (advertencia, motivo, registro y aviso) y §8 (política "solo responsables") |
| 14 | Aprobar y asignar, a mano y en automático, sin ausentes | Prueba §5 y navegador (Auto con ranking) |
| 15 | Cambiar fecha y estimación con recálculo sin doble conteo | Prueba §5 |
| 16 | Horas reales | Prueba §5 y navegador |
| 17 | Completar y cancelar liberan la capacidad | Prueba §5, incluido el cambio desde Tickets |
| 18 | Permisos y privacidad | Prueba §1, §5 y §6 |
| 19 | Avisos | Prueba §4 y §5: recibido, aprobación, aprobado, rechazado, fecha, iniciado, completado y sobrecarga |
| 20 | Responsive y concurrencia | Navegador a 375 px sin desplazamiento lateral; prueba §8 |

En la prueba §8 de concurrencia:
- 8 envíos a la vez con lugar para 4: entran 4 y los otros 4 reciben alternativas;
- 6 envíos a la vez con sobrecarga aceptada: cada uno queda con 60 minutos exactos;
- cambiar la capacidad recalcula sin duplicar.

### En `toolz_local`
- La base quedó en el **esquema 9**. Hay un respaldo de antes en `toolz/backups/20261113_toolz_local_antes_portal.sql`.
- Para revisarlo quedó cargado:
  - Diseño con la categoría Placas y sus 5 variantes (1 h, 2 h, 3 h, 4 h y 30 min);
  - el formulario "Pedido de placas", por pasos y publicado;
  - la solicitud T-0032 (Placa animada con sonido y 3 formatos: 6 h, asignada en automático, en progreso, con 1 h 30 min cargada).
- Sirve para verlo desde Trabajo › Solicitudes.

## Limitaciones y pendientes
- **Arrastre en el navegador de prueba:** el arrastre simulado del panel del navegador no manda `pointerup`, así que el arrastre se probó con eventos de puntero por JavaScript. Es la misma limitación que ya tenía el kanban de Tareas, que anda bien con mouse y con dedo.
- **Emails en local:** con el servidor de PHP, el envío espera a que salgan los emails (unos 15 s en local). En el hosting no pasa, porque `tk_mail_flush` cierra la respuesta antes con `litespeed_finish_request`.
- **Anticipación mínima:** se cuenta en horas corridas desde ahora, no en horas laborables.
- **Reparto:** usa días enteros y no horas del día. El "% proyectado" del detalle sigue la fórmula pedida y suma el pedido entero a ese día; el color se calcula con el reparto real en varios días.
- **Capacidad por persona:** se usa al asignar y en el detalle del día. La carga sin responsable cuenta solo para el departamento.
- **Visibilidad de los departamentos:** se elige por equipos o por personas, no por rol.
- **Panel viejo:**
  - las solicitudes con aprobación de antes del portal (`g7_wk_requests`) siguen en Portal › Historial;
  - el editor viejo de formularios ya no se usa;
  - la acción `form_submit` de `api.php` queda solo por compatibilidad.
- **Automatizaciones:** los disparadores "por vencer" y "atrasado" corren con el barrido de cada 10 minutos, que se activa con el uso de Trabajo o con `trabajo/cron.php`.
- **Al publicar** (cuando el usuario lo apruebe):
  - va en la próxima versión: la carpeta `_private/solicitudes/` la crea el código solo;
  - la migración v9 corre la primera vez que alguien abre Trabajo;
  - en sitios con muchos formularios viejos, la conversión es inmediata.
