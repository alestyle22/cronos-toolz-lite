# Verificación en dos pasos (TOTP) (2026-11-10)

Pedido en la auditoría de seguridad (plan E, mejoras estructurales). Además de la contraseña, al entrar se pide un código de 6 números de una app del teléfono: Google Authenticator, Microsoft Authenticator, Authy o la del gestor de contraseñas.

## Para quien la usa
- **Activar:** Mi perfil › **Seguridad** › Activar. Se escanea el QR, o se escribe la clave a mano, y se confirma con el código que muestra la app.
- **Códigos de respaldo:** al activar aparecen **8 códigos de respaldo**. Cada uno sirve una sola vez para entrar sin el teléfono y no se vuelven a mostrar. Desde la misma pantalla se pueden generar nuevos (los anteriores dejan de servir).
- **Entrar:** usuario y contraseña, y después el código. Si pasan más de 5 minutos entre los dos pasos, hay que empezar de nuevo.
- **Desactivar:** pide la contraseña y un código. No se puede desactivar si el sitio la exige para superadmins.

## Para el superadmin
- **Exigirla a todos los superadmins** (Mi perfil › Seguridad › Superadmin).
  - Quien no la tenga solo puede entrar a Mi perfil para activarla: el resto de las páginas lo lleva ahí y las APIs responden 403.
  - Para cambiar este ajuste hay que tener la verificación activa. Para dejar de exigirla, además hay que escribir un código. Así, un superadmin sin el segundo paso no puede apagarla para saltearla.
- **Alguien perdió el teléfono y los códigos:** otro superadmin se la quita en esa misma pantalla ("Quitar"). La persona entra con la contraseña y la vuelve a activar.
- **Si es el único superadmin**, hay que usar la base de datos: `UPDATE g7_users SET totp_on = 0, totp_secret = '', totp_recovery = NULL WHERE id = …;`

## Cómo funciona
- **`site/totp_lib.php`:**
  - RFC 6238: HMAC-SHA1, pasos de 30 s y 6 dígitos;
  - acepta el código anterior y el siguiente (±30 s de reloj);
  - nunca acepta dos veces el mismo paso (`totp_last`, con un UPDATE condicional por si llegan dos pedidos a la vez);
  - los códigos de respaldo se guardan con `password_hash`.
  - Columnas en `g7_users`, que se agregan solas: `totp_secret`, `totp_on`, `totp_last` y `totp_recovery`.
- **`auth.php`:**
  - `login()`: si la persona tiene la verificación activa, no abre la sesión. Deja `$_SESSION['g7_2fa']` (5 minutos) y devuelve `need_2fa`.
  - `g7_login_2fa()`: comprueba el código. Se bloquea a los 8 incorrectos en 15 minutos (clave `2fa:<id>`) y después abre la sesión con `g7_login_finish()`.
  - `g7_totp_enforce()`: aplica la obligación para superadmins.
- **`login.php`:** el segundo paso. El login de Promos manda al principal para el código.
- **`totp_api.php`** (cabecera `X-2FA: 1`) y **`perfil_seguridad.php`:** la pantalla de Mi perfil. El QR se dibuja en el navegador con qrcodejs 1.0.0, desde cdnjs con SRI.
- **Auditoría:** `auth.2fa_on`, `auth.2fa_off`, `auth.2fa_recovery` (entró con un código de respaldo), `auth.2fa_recovery_new`, `auth.2fa_required` y `auth.2fa_reset`.

## Pruebas
- `php dev/test_2fa.php`: 29 pruebas contra la demo de rubros (`:8154`, antes `php dev/rubro_demo/setup.php`). Crea dos superadmins de prueba y los borra al terminar. Cubre:
  - vectores del RFC;
  - activar con código bueno y malo;
  - entrar con la contraseña sola (no abre sesión), código malo, código bueno, código repetido y código de respaldo (también en minúscula y con espacio);
  - el freno a los 8 códigos incorrectos;
  - la obligación: redirección, 403 en las APIs y que un superadmin sin el segundo paso no la pueda apagar;
  - desactivar y quitársela a otro.
- `php dev/test_permisos.php`: las acciones de superadmin de `totp_api.php` también se prueban con un usuario común.

## Arreglo que salió en la misma versión: renovación de la sesión
En la 1.0.22 se agregó `session.use_strict_mode`. Con eso, la renovación del id de sesión cada 5 minutos (`session_regenerate_id(true)`) podía dejar afuera a la persona: un pedido simultáneo con el id viejo (por ejemplo, el chat, que consulta seguido) recibía una sesión nueva vacía y su cookie pisaba la buena.

Ahora la sesión vieja queda marcada con `g7_replaced` y sigue valiendo 2 minutos, y después se descarta (`session_regenerate_id(false)`). Se reprodujo en local antes y después del arreglo.

## A quién se le exige (2026-11-12, sin publicar)

- **Ajuste del sitio** (Mi perfil › Seguridad › Superadmin › "Verificación en dos pasos obligatoria para"): todas las personas, admins y superadmins, solo los superadmins o a nadie.
- **Por defecto: todas las personas.** Si el sitio no tiene el ajuste guardado, se exige a todos. Quien no la tenga entra con su contraseña, pero solo puede ir a Mi perfil › Seguridad (las APIs responden 403) hasta activarla.
- **Lo cambia** solo un superadmin que ya la usa. Para exigirla a menos gente hace falta un código de su app.
- **Código:**
  - `g7_totp_scope()` y `g7_totp_required_for()` en `totp_lib.php` (`G7_TOTP_SCOPES`); la clave de configuración es `totp_required` (`totp_required_super` queda solo por compatibilidad).
  - `g7_totp_enforce()` recuerda en la sesión lo ya comprobado, atado a la persona y al ajuste: si se sube la exigencia, rige en el pedido siguiente.
- **Arreglo:** los enlaces a una sección de Mi perfil (`user_config.php#seguridad`) abrían siempre "Mi perfil" desde la 1.0.31 (buscaban el `onclick` que sacó la CSP). Ya abren la sección.

## Contraseñas: renovación cada X días (2026-11-12, sin publicar)

- **Ajuste** (mismo lugar): "Renovar las contraseñas": nunca, 30, 60, 90, 180 o 365 días (`pass_max_days`). Al prenderlo, el plazo cuenta desde ese momento para quien no tiene fecha de cambio (`pass_policy_since`): nadie vence de golpe.
- **Al vencer**, toda página lleva a `password.php?vencida=1` y las APIs responden 403 hasta cambiarla (`g7_pass_enforce()` en `auth.php`).
- **Una semana antes** llega un aviso a la campana (tipo `pass`, uno por día) que abre `password.php`.
- **Cambiar la propia contraseña:** `password.php` o Mi perfil › Contraseña, siempre con `password_api.php` (`g7_pass_change()` en `pass_lib.php`):
  - pide la actual (con freno por intentos `pw:<id>`);
  - al menos 8 caracteres (o el mínimo del sitio, si es mayor);
  - distinta de la actual y no de las obvias (12345678, password…);
  - guarda `g7_users.pass_changed_at` y lo anota en la Auditoría (`auth.pass_change`).
- **Arreglo:** el cambio de contraseña de Mi perfil mandaba el pedido a `users.php`, que no atiende ese tipo de pedido (visto en el código). Ahora usa `password_api.php`.
- **Pruebas:** `php dev/test_pass.php` (15: vencida, APIs, reglas, aviso, prender sin vencer a nadie) y `php dev/test_2fa.php` (31, con las opciones nuevas y el valor por defecto). `test_permisos` corre con la obligación apagada, para medir permisos y no el 403 del 2FA.

## Secreto cifrado y onboarding (2026-11-13, sin publicar)
- El secreto TOTP ya no se guarda en texto plano: AES-256-GCM con una clave fuera de la base (`_private/totp_key.php`, se crea sola; en pruebas `G7_TOTP_KEY`). `g7_totp_row()` lo descifra y cifra en el momento los que estaban en texto plano. Columna `totp_secret` a VARCHAR(255).
- **Respaldar la clave junto con la base**: sin ella nadie puede entrar con su app.
- La bienvenida (`bienvenida.php`, ver `ONBOARDING.md`) guía la activación con el mismo `totp_api.php`.
