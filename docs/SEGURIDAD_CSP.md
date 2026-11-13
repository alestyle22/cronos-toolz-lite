# CSP de scripts (SEC-24) (2026-11-10)

**Objetivo:** una Content-Security-Policy que no deje correr scripts en línea. Es la defensa de fondo contra el XSS: aunque se cuele un `<img onerror=…>` o un `<script>`, el navegador no lo ejecuta.

**Por qué se hace en fases:** la plataforma tiene mucho código en línea (`php dev/csp_inventario.php`, detalle en `QA_Reports/CSP_inventario.md`):
- **1850** atributos `on…="…"` (onclick, onchange…);
- **100** bloques `<script>` en línea;
- 1 enlace `javascript:`;
- 8 `eval` o `new Function`;
- en **96** archivos.

Por área, lo más grande está en la raíz (`admin.php`, `topbar.php`, `user_config.php`, `index.php`…), mailingmaker, placas, promos, landing-gen y tienda. Bloquearlo hoy rompería casi todo.

## Fase 1: medir (hecha, versión 1.0.29)
- **Encabezado:** `csp_lib.php` manda `Content-Security-Policy-Report-Only` con la política objetivo (`G7_CSP_POLICY`):
  - `script-src` solo `'self'` y los CDN que se usan: cdnjs, unpkg, jsdelivr y tailwind;
  - `object-src 'none'`, `base-uri 'self'`, `report-uri`.
  - **El navegador no bloquea nada:** solo avisa.
- **Interruptor:** apagado por defecto. Un superadmin lo prende en **Mi perfil › Seguridad › Superadmin › Medir 24 h** y se apaga solo. El estado vive en `_private/csp_state.php`, sin base de datos.
- **Receptor:** `csp_report.php` es público, porque los avisos los manda el navegador.
  - Solo guarda mientras la medición está prendida.
  - Acepta el formato clásico (`report-uri`) y la Reporting API.
  - Corta los pedidos de más de 16 KB.
  - Agrupa por directiva, origen, página, archivo, línea y muestra, con un tope de 2000 grupos.
  - Guarda el origen de lo bloqueado, nunca la URL completa con su consulta.
  - El informe va en `_private/csp_reports.php`, protegido como los secretos.
- **Informe:** en la misma tarjeta, "Ver informe" muestra los totales por causa y por página y los 80 grupos más frecuentes.
- **Prueba en la demo:** inicio, Admin, Propiedades, Facturación y Mi perfil dieron 34 avisos, todos `script-src-elem` en línea, y ningún origen externo fuera de la lista.
- **Pruebas:** `php dev/test_csp.php` (12/12) y `dev/test_permisos.php`: las acciones `csp_*` son solo del superadmin.

**Cómo usarla en producción:** en una agencia con uso real, prender la medición un día de trabajo, mirar el informe y borrarlo. Los avisos `script-src-attr` (los `onclick`) aparecen recién cuando alguien hace clic: hace falta uso real, no solo abrir páginas.

## Fase 2: avance (2026-11-10, versión 1.0.30)
**Con la CSP estricta en modo bloqueo** (`g7_csp_enforce()` o encabezado propio):
- `login.php`: sus 3 eventos pasaron a `assets/login.js`. Comprobado: un `onerror` inyectado en la página no se ejecuta.
- Turnos, Vencimientos, Talentos y Builds: sus páginas y su JS ya no tenían código en línea.
- Probados en el navegador (carga, diálogos y formularios) sin ninguna violación.
- El Asistente IA y Links Generator también están limpios, pero quedaron sin bloqueo porque en la demo están apagados y no se pudieron probar.

**Barra superior (`topbar.php`, se carga en todas las páginas):**
- 0 eventos en línea (antes 27) y 0 scripts que se ejecuten en línea (antes 6). El código pasó a `assets/topbar.js`, `assets/topbar-pal.js` y `assets/theme-boot.js`.
- Los datos de PHP van en bloques `<script type="application/json">`, que no se ejecutan.
- Los eventos de los elementos fijos se enganchan directo, con el mismo orden de antes. Los de los elementos que arma el JS se manejan con un único detector en fase de captura, porque el modal del zumbido corta la propagación.

**Otros cambios:**
- `vocab_lib.php`: el vocabulario del rubro va en JSON más `assets/vocab.js`.
- `chat_widget.php` se retiró: no se usaba (el chat es `chat/widget.php`, que ya estaba limpio).

**Dos XSS anteriores encontrados al hacerlo** (SEC-42):
- en el zumbido, el nombre de la persona y **el mensaje libre** se mostraban sin escapar: cualquier usuario podía ejecutar código en el navegador de otro mandándole un zumbido;
- la lista de personas del zumbido mostraba el nombre sin escapar.
- Corregidos en `assets/topbar.js` y comprobados en el navegador.

**Inventario después de este avance:** 96 `<script>` en línea y 1763 `on…=`, en 95 archivos (antes 100 y 1850, en 96).

## Fase 2: segunda área (Inicio y Mi perfil, versión 1.0.31)
- **`assets/g7-on.js`** (lo carga la barra superior en todas las páginas): eventos declarativos sin código en línea. `onclick="fn('a', this.value)"` pasa a ser `data-on-click="fn" data-click-1="s:a" data-click-2="value"`.
  - **Argumentos:** `s:` texto, `n:` número, `j:` JSON, `this`, `event`, `value`, `nvalue`, `tvalue`, `checked`, `true`, `false` y `null`.
  - **Funciones propias:** `@remove`, `@removeParent`, `@stop` y `@set` (asigna a una ruta global).
  - **Métodos:** se aceptan, como `BZ.editPlan`.
  - **Teclas:** filtro con `data-keydown-key`.
  - Escucha en captura en `document`, así que funciona aunque un contenedor corte la propagación.
- **`dev/csp_convertir.py`:** convierte solo los casos simples (una llamada con argumentos literales, asignaciones a un borrador, tecla Enter y `this.remove()`). Lo demás lo lista para hacerlo a mano. Uso: `python dev/csp_convertir.py archivo… [--dry]`.
- **En modo bloqueo:** `index.php` (Inicio) y `user_config.php` (Mi perfil, con sus secciones Seguridad y Google Calendar).
  - Sus scripts pasaron a `assets/home-1..3.js`, `assets/user-config.js`, `assets/perfil-seguridad.js` e `integraciones/perfil-google.js`.
  - Los banners de la Red pasaron a `assets/red-banners.js`.
  - Probado en el navegador: filtro, personalizar, pestañas, accesos con sus argumentos, secciones, ajustes que llaman a guardar con el valor correcto, sincronización del color, QR del doble factor y un `onerror` inyectado bloqueado.
- **Inventario:** 88 `<script>` en línea y 1723 `on…=`, en 91 archivos.

**Próxima área: el Admin.** El conversor en seco resuelve unos 240 eventos de `admin.php`, `admin_ui.php` y sus `.js`, y deja unos 70 para hacer a mano:
- bordes al pasar el mouse (pasan a CSS);
- `return false`;
- varias instrucciones (`BZ.plan=null;bzDraw()`);
- `navItems[i]…`.

Los objetos `DR` y `RB` tienen que quedar en `window` (`window.DR = DR`) para que `g7-on` los encuentre.

## Fase 2: tercera área (Admin, versión 1.0.32)
- **`admin.php` con la CSP en modo bloqueo.** Ni la página ni lo que carga tiene código en línea: los fragmentos `admin_*.php` y `admin_*.js`, y el script principal, que pasó a `assets/admin-page.js`.
- **Conversión:**
  - unos 240 eventos con `dev/csp_convertir.py`;
  - 71 a mano, con funciones con nombre que hacen lo mismo (`accGenSet`, `bzReset`, `bzTkFilter`, `rbFieldNew`, `admNavSet`…);
  - los bordes al pasar el mouse pasaron a CSS (`[data-hov]`).
  - `seg()` y `toggle()` ahora reciben el nombre de la función y sus argumentos en lugar de un texto con código.
- **Datos de PHP para el JS** (`BZ_CSRF` y `CLI_META`): bloques `<script type="application/json" data-global>` más `assets/json-globals.js`.
- **Pruebas:**
  - en el navegador, las 24 secciones del Admin de la demo abren y todos los `data-on-…` que aparecen (75 funciones) existen;
  - Usuarios: ficha, ver contraseña, interruptores; Módulos: botones de modo con sus argumentos; menú de la barra, zumbidos y rubros: campo nuevo, tipo y cancelar;
  - Red Cronos en el Admin real de `:8153` (base local): 6 secciones, 879 eventos que apuntan a funciones existentes, sin errores;
  - `test_csp` 47/47 y `test_base_api` 42/42.
- **Problema encontrado y resuelto antes de publicar:** los botones de modo de Módulos y permisos pasaban como "función" un texto con código (`((v)=>accSetMode('x',v))`); con la conversión automática no iban a hacer nada.

## Fase 2: lo que falta (por áreas)
Orden propuesto: primero lo que ve todo el mundo y lo más sensible, al final las herramientas de diseño.
1. **`login.php`, `topbar.php`, `chat_widget.php`, `index.php`, `user_config.php`, `admin.php` y `admin_*.php`** (raíz, unos 950 elementos):
   - cada `<script>` en línea pasa a un `.js` o recibe un `nonce` por pedido (`g7_csp_nonce()`);
   - los `onclick="f(1)"` pasan a `data-action` con un solo `addEventListener` delegado por página.
2. **Módulos de gestión:** Facturación, Clientes, Propiedades, Turnos, Vencimientos, Talentos, Builds, Trabajo, Tareas y Tienda.
3. **Herramientas:** promos, mailingmaker, landing-gen, placas y links. Tienen editores con `eval` o `new Function` (8 lugares), que hay que revisar uno por uno.
4. Con cada área limpia, se prende la política en **modo bloqueo** solo en esas rutas, y se mide de nuevo con la fase 1.
5. Al final, la política estricta en todo el sitio. El `.htaccess` mantiene la CSP mínima de hoy (`object-src 'none'; base-uri 'self'; frame-ancestors 'self'`).

**Esfuerzo estimado:** de varios días a semanas, según el área. Se puede hacer de a una versión por área, siempre con la medición de la fase 1 como control.

## Fase 2: cierre en todas las áreas (2026-11-12, sin publicar)

Cinco pasadas en paralelo, una por área. Todas las páginas de Toolz quedan con `g7_csp_enforce()` (CSP estricta, sin `unsafe-inline` ni `unsafe-eval`).

- **Gestión:**
  - Clientes, Facturación y Cobros, Propiedades, Trabajo, Tareas, Calendario, Chat y su admin, Soporte y Tasking;
  - la ficha pública y `google_oauth`;
  - el portal de clientes y las páginas de impresión (`cli_print_page`).
- **Tienda:** las 8 páginas públicas y su admin. Los 2 `eval` del admin pasaron a `accReopen(ctx)`.
- **Raíz:** usuarios, equipos, anotador, Monday, estado, imghost, conversor SVG, páginas, Planify, stock y materiales, estadísticas y su reporte, comunidad, y el generador de links.
- **Promos.**
- **Herramientas:** Mailing Maker, Landing Gen, Placas Paid, Links y Encuesta, más las chicas: renombrador, jpg y webp, recolorear SVG y placas deportivas.
  - `g7_wrap_tool()` tiene el parámetro `$csp`; las herramientas que todavía tienen código en línea siguen sin él.
  - **Patrón de marco aislado:** lo que necesita correr código ajeno (la vista previa de Landing Gen y "Quitar fondo" de Placas, que usa `eval` y WebAssembly) corre en `landing-gen/frame.php` y `placaspaid/bg-frame.php`. Esas páginas se sirven con `Content-Security-Policy: sandbox`, así que tienen otro origen y no ven la sesión.
  - Para las vistas previas, las fuentes de `landing-gen/assets/` necesitan CORS (`.htaccess`).
- **Datos de PHP para el JS:** bloques `<script type="application/json">`. El inventario ya no los cuenta como código en línea.
  - `topbar.php` no carga `assets/json-globals.js`: cada página que lo usa lo incluye.
- **Arreglos que salieron en el camino:**
  - "Me gusta", comentarios y colaboradores en Promos;
  - "Exportar CSV" de Estadísticas;
  - "Restaurar" del historial de Mailing;
  - "Descargar" de Landing Gen;
  - nombres que se metían sin escapar en el JS (XSS) en Calendario, Estadísticas, Stock, Comunidad y Monday;
  - los enlaces a una sección de Mi perfil.
- **Quedan sin bloqueo, a propósito:**
  - `instalar.php`: corre una sola vez, al crear un sitio, y mete valores de PHP en sus scripts.
  - Popup Gen: su vista previa corre el JS del popup con `new Function`. Hace falta pasarla a un marco aislado como el de Landing Gen.
  - `placasdeportivas/` y `linkgen/`: trabajo de otras sesiones sin publicar.
  - `g7_brand_js()` de `access.php` (lo usa solo linkgen).
- **Inventario:** `php dev/csp_inventario.php --md QA_Reports/CSP_inventario.md`.
