# Cronos Toolz Lite

Es una edición reducida de Toolz. Tiene su propio instalador, está conectada a la Red Cronos y se activa, actualiza y licencia desde el sitio base igual que la completa.

## Qué trae
- Inicio, Soporte y Mi perfil
- Trabajo / Tareas
- Clientes: fichas, servicios, notas y archivos. **Sin** facturas ni portal de clientes.
- Chat
- Contenidos (Promos) y Materiales
- Landing Gen y Mailing Maker
- Estadísticas
- Jira
- Convertidores WebP, JPG y SVG
- Comunidad, Mi calendario y To-Do
- Biblioteca (desde la 1.0.54), con sus guías
- Portal de Solicitudes por departamento (desde la 1.0.55), también como módulo propio «Solicitudes» (desde la 1.0.56)
- Bienvenida y configuración inicial, con la verificación en dos pasos (desde la 1.0.55)
- Integraciones de Admin › Integraciones, entre ellas OpenCloud (desde la 1.0.56)

Todo lo demás queda afuera: Facturación, Pagos, Portal, Juegos, Builds, Tienda, Propiedades, Turnos, Vencimientos, Talentos, Placas, Popup Gen, Links y reportes de links, Encuesta, Monday, Image Host, Recolorear, Renombrador, Claude, Pages, Planify y Status.

## Dónde se define
`site/_update/editions.php`, edición `lite`:
- `modules_off`: el instalador los copia a `modulos_off` de `_private/site.php`. Quedan apagados por el sitio y ni los admins los prenden.
- `exclude`: archivos y carpetas que no vienen en el instalador y que el actualizador no instala.
- `keep`: excepciones dentro de lo excluido. Hoy es `juegos/reportes_lib.php`, porque lo usa Trabajo › Tickets.

El archivo viaja en cada versión firmada. El sitio guarda su edición en `_private/site.php` (`'edition' => 'lite'`).

## Armar el instalador
```
php dev/release.php build X.Y.Z "notas"
php dev/release.php bundle X.Y.Z --edition lite   → releases/instalador/instalador-lite-X.Y.Z.zip
```
`bundle --edition` no arma el paquete si un archivo que viaja carga con `require`/`include` uno excluido sin preguntar antes `is_file`. Para revisarlo sin armar nada: `php dev/edition_deps.php lite` (con `--refs` muestra también menciones sueltas, como links o fetch).

## Actualizaciones
Las versiones son las mismas que las de la edición completa (un solo paquete). En un sitio lite, `u_plan` saltea los archivos excluidos que el sitio no tiene (`edition_skip`). El base lo muestra en la vista previa («X son de módulos que la edición Lite no trae»), y en la lista de sitios aparece la etiqueta **Lite**. `u_status` informa `edition`.

Ojo: el plan usa el `editions.php` que el sitio **ya tiene instalado**. Si una versión agrega una carpeta nueva a `exclude`, recién se respeta en la versión siguiente. Un módulo nuevo que no esté en `exclude` llega a la Lite.

## Licencia
El base tiene el plan **Lite**: 3 usuarios, 5 clientes, 5 GB y solo los módulos de la Lite. Se agrega una sola vez a los bases que ya tenían planes; si lo borran, no vuelve (marca `plans.json.lite`).

## Sin facturas en Clientes
En una instalación sin `facturacion/` o sin `portal/`:
- La ficha esconde las pestañas Facturas y Dashboard (`has` en `clientes_api.php` → get).
- Desaparece el botón «Datos de facturación».
- Admin no muestra «Portal de clientes».
- La raíz del dominio va directo a `/toolz/`.

## Prueba
`php -d extension=gd dev/test_lite.php` hace todo esto (74 comprobaciones):
- arma la versión 9.9.0 en una carpeta temporal y su instalador lite;
- lo instala en la base `lite_test` con un servidor en el puerto 8161;
- lo activa como el base;
- abre cada módulo y comprueba que lo excluido no esté;
- comprueba que la 9.9.1 no le instale nada de afuera.

Con `--keep` deja la instalación y la base para mirarlas con el preview `lite-test` (usuario `admin`, contraseña de prueba en el script).

## Portal de Solicitudes (2026-11-13)
Entra en la Lite, por decisión del usuario. Vive en `trabajo/`, que ya viaja en la Lite, así que `editions.php` no cambió. Ver `SOLICITUDES.md`.

## Repositorio de la Lite (2026-11-13)
Pedido del usuario: "armemos el repo de cronos toolz lite".
- **Dónde está:** en `L:\Kenia\cronos-toolz-lite`, con la rama `main`, publicado en GitHub como repo **público** `alestyle22/cronos-toolz-lite` (desde la 1.0.57, con historia limpia).
- **Qué tiene:** la Lite tal como se instala. `site/` es la carpeta `toolz/`, `root/` es lo que va en la raíz del dominio, y `docs/` tiene la documentación de sus módulos, solo la que no menciona a ningún cliente.
- **Cómo se pone al día:** con `php dev/lite_repo.php X.Y.Z` después de armar cada versión. Toma el instalador Lite firmado, reemplaza `site/` y `root/`, copia la documentación y frena si encuentra una marca de cliente, un secreto o algo de `_private/`. No hace commit: eso se hace a mano después de mirar `git status`.
- **Finales de línea:** `.gitattributes` con `* -text` deja los archivos byte a byte como en la versión firmada, así coinciden con los hashes de `site/install/release.php`.
- **Qué queda afuera del repo público:** CARGA.md (la cuenta y los límites del hosting), SEGURIDAD_WAF.md y SEGURIDAD_SAMM.md (qué defensas hay y cuáles faltan). Además, `lite_repo.php` y `release.php` frenan si aparece cualquiera de las marcas de clientes de su lista.

## Afiliados (1.0.80)

La Lite deja afuera `afiliados/`: el panel de Afiliados y el portal de particulares solo existen en el Cronos principal (edición completa, el que cobra los planes con `pagos/`). Las agencias Lite usan "Recomienda Cronos" (`recomendar/`), que habla con el base y no necesita Pagos. Hasta la 1.0.79 el instalador Lite no se podía armar porque `afiliados/api.php` carga `pagos/lib.php`.
