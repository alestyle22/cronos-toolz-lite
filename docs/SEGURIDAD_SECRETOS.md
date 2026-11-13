# Secretos que no se leen por web (SEC-22, 2026-11-10)

**Problema:** las claves de integraciones y de pasarelas vivían en `.json` y `.key` dentro de `_private/`. Solo las protegía el `.htaccess` (`Require all denied`). En un servidor que lo ignora (nginx, o Apache con `AllowOverride None`) se podían descargar.

Comprobado en la demo local, cuyo servidor de PHP ignora `.htaccess` igual que nginx: `GET /toolz/_private/integrations.json` devolvía las claves completas (4122 bytes).

**Solución:** esos archivos ahora son `.php` y empiezan con la línea `<?php exit; ?>`. Si alguien los pide por web, PHP los ejecuta, corta en esa línea y no muestra nada. El código los lee quitando esa primera línea. Funciona en cualquier servidor que ejecute PHP, sin depender del `.htaccess`.

**Implementación:**
- `site/priv_lib.php`:
  - `g7_priv_write()` escribe de una vez (temporal y renombrar) con permisos 0600;
  - `g7_priv_read()` lee y, si encuentra el archivo de antes, lo **migra solo**: escribe el nuevo y borra el viejo. Si no puede escribir, sigue usando el viejo.

| Antes | Ahora | Qué guarda |
|---|---|---|
| `_private/integrations.json` | `_private/integrations.php` | Claves de Google, WhatsApp, YouTube y ARCA (incluida la clave privada), tokens de Google por persona y la firma de los links. |
| `_private/tienda/gateways.json` | `_private/tienda/gateways.php` | Claves y secretos de webhook de Mercado Pago y Stripe. |
| `_private/tienda/secret.key` | `_private/tienda/secret.php` | Firma de la tienda: tokens de contenido y webhook de prueba. |
| Base: `_private/license_sign.pem` | Base: `_private/license_sign.php` | Clave privada de firma de licencias. El base acepta las dos; conviene subir la `.php`, que queda armada en `toolz/dev/_keys/license_sign.php`. |

**Lo que ya estaba protegido:** `_private/secrets.php`, `site.php`, `ia.php`, `jira.php` y `monday.php` ya eran PHP.

**Lo que sigue dependiendo del `.htaccess`:** son datos, no claves.
- `license.json`, `banners.json`, `integrations.log` (los secretos se borran antes de escribirlo) y `monday_cache.json`.
- Los archivos subidos en `_private/` (chat, materiales, tienda/media). Tienen nombres al azar de 128 bits y se sirven por PHP con permisos.
- En nginx conviene igual negar `/_private/` en la configuración del servidor.

**Pruebas:** `php dev/test_secretos.php`, 13 de 13. Cubre:
- escritura, lectura y migración, sin pisar el archivo nuevo;
- que ejecutado no muestra nada;
- integraciones guardadas con el nombre de antes que se migran, sin cambiar la firma de los links;
- con la demo andando, que por web devuelve 0 bytes y que no quedan los archivos viejos.

**Al instalar la versión:** no hay que hacer nada. Cada sitio migra sus archivos la primera vez que los usa: al abrir Admin › Integraciones, cualquier página que use la tienda o el feed de propiedades.
