# Cronos Toolz Lite

Edición reducida de Cronos Toolz, la plataforma de gestión para agencias. Cada agencia tiene su propia instalación, conectada a la **Red Cronos**: el sitio base la activa, le manda las versiones firmadas y maneja su licencia y su soporte.

Este repositorio tiene la Lite **tal como se instala**, en la versión que dice `VERSION`.

## Qué trae

| Área | Módulos |
|---|---|
| Base | Inicio, Soporte, Mi perfil, bienvenida y configuración inicial (con verificación en dos pasos) |
| Trabajo | Tareas y tableros, Mis tareas, tickets, Portal de Solicitudes por departamento (también como módulo propio «Solicitudes») |
| Clientes | Fichas, servicios, notas y archivos, con carpeta de OpenCloud opcional. Sin facturas ni portal de clientes |
| Comunicación | Chat, Comunidad, Mi calendario y To-Do |
| Contenido | Contenidos (Promos) y Materiales, Landing Gen, Mailing Maker, Biblioteca y convertidores WebP, JPG y SVG |
| Datos | Estadísticas, con Jira (fechas a elección, resumen de pedidos, gráficos y exportación) |
| Integraciones | Las de Admin › Integraciones, entre ellas OpenCloud autoalojado |

Lo que la Lite deja afuera (Facturación, Pagos, Portal de clientes, Juegos, Tienda, Turnos y otros) está en `site/_update/editions.php` y explicado en `docs/LITE.md`.

## Stack

- PHP 8 sin framework ni Composer, MySQL o MariaDB con PDO.
- JavaScript sin build: cada módulo tiene su archivo.
- CSP estricta: nada de código en línea. Los eventos van por `data-on-*` (`assets/g7-on.js`) y los datos, en bloques JSON.
- Pensado para hosting compartido.

## Carpetas

| Carpeta | Qué hay |
|---|---|
| `site/` | La carpeta `toolz/` de una instalación. `install/release.php` dice la versión y el hash de cada archivo |
| `root/` | Lo que va en la raíz del dominio: `index.php` (asistente, panel de clientes o la plataforma), el manual de instalación y el LEEME |
| `docs/` | Documentación de los módulos de la Lite: decisiones, flujos, seguridad y cómo probar cada uno |

## Instalar

1. Subí `site/` como carpeta `toolz/` y el contenido de `root/` a la raíz del dominio.
2. Creá una base de datos MySQL vacía, con un usuario que tenga todos los permisos.
3. Abrí `https://tu-dominio/toolz/instalar.php` y seguí los pasos.
4. El último paso conecta el sitio con el sitio base. La instalación queda bloqueada hasta que el base la activa.

El detalle está en `root/manual-instalacion.html`.

## Actualizar

Las versiones salen firmadas desde el sitio base y se instalan desde su panel. Cada sitio verifica la firma con `site/_update/pubkey.pem`. Un sitio Lite no instala los archivos de los módulos que no trae.

**No se edita este repositorio para cambiar un sitio.** Los cambios se hacen en el proyecto principal, salen como versión firmada y este repo se regenera con:

```bash
php dev/lite_repo.php X.Y.Z
```

## Lo que no está en el repo

- **Lo de cada sitio vive en `_private/`:** su configuración, la clave de la verificación en dos pasos, las credenciales de las integraciones y la licencia.
- **Los datos de cada agencia.**
- **La clave privada que firma las versiones.**

Nada de eso se versiona, y `.gitignore` lo deja afuera por las dudas. El script que arma este repo revisa que no entre ninguna marca de cliente ni ningún secreto.

## Uso

El código está a la vista para que se pueda revisar. Usarlo en producción, copiarlo o redistribuirlo requiere autorización de Cronos Toolz: las instalaciones se activan y se licencian desde el sitio base.
