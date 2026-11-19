# Bienvenida y configuración inicial (onboarding)

Pedido del usuario: 2026-11-13.

**Estado:** hecho y probado en local. **No se publicó ni se instaló en ningún sitio.**

## Qué hace
Cuando alguien entra por primera vez ve una pantalla de bienvenida y un asistente de 4 pasos:

| Paso | Qué hace |
|---|---|
| Bienvenida | Saluda por el nombre, presenta la plataforma, muestra el recorrido y el botón "Comenzar configuración". |
| 1. Perfil | Nombre, apellido, nombre visible, foto, usuario (solo se ve), sobre vos, cargo, departamento, país, zona horaria e idioma. Lo que ya estaba cargado aparece completo y con un tilde. |
| 2. Preferencias | Tema (oscuro, claro o automático), avisos (campana, email, navegador y sonido) y comunicación (en línea, confirmación de lectura y Enter para enviar). Tiene "Usar los valores predeterminados". |
| 3. Seguridad | Explicación, QR, clave para escribir a mano, código de 6 dígitos, confirmación y los 8 códigos de recuperación para copiar o descargar, con "Los guardé" obligatorio para seguir. |
| 4. Confirmación | Resumen con tildes y la pantalla "¡Tu espacio está listo!", con el botón "Ir a mi escritorio" que lleva a la página que la persona quería abrir. |

**Detalles de los pasos:**
- **Perfil:** la zona horaria se propone sola desde el dispositivo, pero se puede cambiar. Se aceptan también los nombres viejos que dan algunos navegadores, como America/Buenos_Aires.
- **Seguridad:** "Más tarde" aparece solo si a esa persona no le exigen el 2FA.
- Se puede volver atrás sin perder lo cargado.
- **Retomar:** si la persona sale a mitad de camino, al volver retoma desde el paso donde quedó. Cerrar la sesión no lo reinicia.

## Cómo está hecho (sin duplicar nada)
**Archivos nuevos:**
- `site/onboarding_lib.php`: el estado de cada persona, la configuración, el control de acceso (`ob_enforce`) y los números.
- `site/onboarding_api.php`: la API, con la cabecera `X-OB: 1`.
- `site/bienvenida.php`, `assets/onboarding.js` y `assets/onboarding.css`: la bienvenida y el asistente.
- `site/onboarding_admin.php`, `assets/onboarding-admin.js` y `assets/onboarding-admin.css`: el panel de administración.

**Lo que se reutiliza:**
- El **perfil** se guarda en `g7_users`: `display_name`, `bio`, `position` y `avatar`, más dos columnas nuevas, `first_name` y `last_name`.
- La **foto** se sube con la acción de siempre de `user_config.php`, achicada en el navegador.
- El **departamento** se elige entre los equipos de la persona y queda como principal (`g7_team_members.is_primary`).
- **País y zona horaria** se guardan con `g7_tz_save()` de las preferencias regionales.
- **Preferencias:** las de siempre en `g7_user_prefs`: `theme`, `notif_desktop`, `notif_sound`, `privacy_*`, `chat_enter_send`, `language` y `tasks_notif`.
- **2FA:** es el mismo de siempre (`totp_lib.php` + `totp_api.php`). No hay otro mecanismo de login ni otro 2FA.
- **Avisos** a la persona: campana con el tipo `onboarding`, que abre la bienvenida.

**Archivos que ya existían y se tocaron:**
- `auth.php`: `getCurrentUser()` llama a `ob_enforce()` después de la contraseña vencida y antes del 2FA obligatorio. `totp_enforce` deja pasar la bienvenida.
- `totp_lib.php`: secreto cifrado (ver "Seguridad").
- `totp_api.php`: guarda el secreto cifrado.
- `assets/topbar.js`: el aviso `onboarding`.
- `admin.php`: un enlace "Bienvenida (onboarding)" en el menú.

## Estado de cada persona (`g7_onboarding`)
| Estado | Significado |
|---|---|
| `pending` | No iniciado |
| `progress` | En progreso |
| `security` | Pendiente de seguridad: solo falta el 2FA obligatorio |
| `done` | Completado |

Se guarda la fecha de inicio, el último paso, los pasos hechos, la fecha de activación del 2FA, la fecha en que confirmó los códigos, si dejó el 2FA para después y la fecha de fin. También se guarda si un admin lo pidió (`forced`, `force_mode`, `force_reason`), si se le exige el 2FA (`require_2fa`) y cuándo se le recordó.

**Quién la ve:**
- La configuración guarda `onboarding_since`: el momento en que se activó la bienvenida en el sitio.
- **Personas nuevas** (usuario creado después de esa fecha): ven la bienvenida.
- **Personas que ya estaban:** según la configuración.
  - Por defecto **no se les muestra nada** y quedan completadas solas (`auto = 1`).
  - Con la opción "solo si les falta algo", la ven únicamente si les falta un dato obligatorio o el 2FA obligatorio.
  - El admin se la puede pedir a quien quiera.
- **Quien ya tenía el 2FA activo** no lo vuelve a configurar: el paso de seguridad queda hecho.

**Control de acceso (`ob_enforce`):**
- Con el onboarding pendiente, toda **página** lleva a `/toolz/bienvenida.php?next=…`.
- Las **APIs** siguen andando, para no romper el chat ni los avisos, **salvo** que lo pendiente sea un 2FA obligatorio: entonces responden 403, como la política del sitio.
- Se recuerda en la sesión, atado a una revisión (`onboarding_rev`) que sube con cada cambio del admin. Así, "Exigir 2FA" o "Reiniciar" rigen en el pedido siguiente.

## Seguridad
- **El secreto TOTP ahora se guarda cifrado:**
  - con AES-256-GCM (`g7_totp_seal` / `g7_totp_unseal`);
  - la clave vive **fuera de la base**, en `_private/totp_key.php`. Se crea sola, no viaja en las versiones porque `_private` está protegido, y en las pruebas se usa `G7_TOTP_KEY`;
  - los secretos viejos en texto plano se cifran solos la primera vez que se leen;
  - la columna pasó de 64 a 255 caracteres.
  - **Importante:** esa clave hay que respaldarla junto con la base. Sin ella, nadie puede entrar con su app: el sitio **bloquea** (nunca deja entrar sin el segundo paso) y hay que quitarle el 2FA a cada persona desde Seguridad para que lo vuelva a activar.
- **Los códigos de recuperación** siguen con `password_hash`, un solo uso y 8 por persona. Se muestran una sola vez.
- **El código se valida solo en el servidor:**
  - acepta ±30 s y nunca el mismo paso dos veces (`totp_last`);
  - freno de 8 intentos en 15 minutos al activar y al entrar.
- **No hay confirmación falsa:** "Los guardé" (`security_ack`) se rechaza si el 2FA no se activó con un código válido, y no se puede terminar si el 2FA es obligatorio y falta.
- **Desactivar el 2FA** pide la contraseña y un código (reautenticación). Si el sitio lo exige, no se puede desactivar.
- Ni el secreto ni los códigos van a los registros. La prueba lo revisa.
- Los datos del perfil se validan en el servidor: obligatorios, letras en nombre y apellido, sin `<` ni `>`, zona horaria real del país y departamento propio.
- **Auditoría:** `onboarding.profile`, `onboarding.security`, `onboarding.done`, `onboarding.cfg` y `onboarding.admin_*`.

## Panel de administración (Admin › Bienvenida)
Está en `onboarding_admin.php`, solo para administración.

**Personas:**
- Números por estado, % de completados, % con 2FA y una barra de avance.
- Tabla con estado, paso, datos que faltan, 2FA (activa, exigida o dejada para después), inicio y fin.
- Búsqueda y filtros: sin completar, con datos faltantes, sin 2FA y por estado.
- **Acciones en lote**, con un mensaje opcional que le llega a la persona en la campana:

| Acción | Qué hace |
|---|---|
| Pedir onboarding completo | La persona vuelve a ver la bienvenida y todos los pasos |
| Pedir lo que falta | Solo le pide los datos obligatorios que le faltan |
| Exigir 2FA | Le bloquea el resto hasta activarlo |
| Reiniciar | Vuelve a empezar de cero; conserva el 2FA que ya tenga |
| Recordar | Le manda un aviso a la campana |
| Dar por completo | Lo marca como terminado sin pedirle nada |

Un admin común no le cambia nada a un superadmin.

**Configuración:**
- Activar o desactivar la bienvenida.
- Qué pasa con las personas que ya estaban.
- Qué pasos son obligatorios.
- **2FA:** según la política del sitio, obligatorio para terminar, o recomendado. La política de todo el sitio sigue en Mi perfil › Seguridad (superadmin).
- Permitir "Más tarde".
- Qué datos del perfil se piden: obligatorio, opcional o no se pide.
- Preferencias predeterminadas.
- Textos de bienvenida y final, donde `{plataforma}` se reemplaza por el nombre del sitio.
- Colores de la bienvenida.

## Pruebas
```bash
G7_TEST_DB="127.0.0.1;port=3407" php -d extension=gd dev/test_onboarding.php
```
Da **60/60**, con la base `ob_test` y un servidor propio en :8181. Cubre:
- **primer ingreso:** la redirección recuerda a dónde iba;
- **retomar** con otra sesión, sin reiniciar;
- **validación del perfil**: obligatorios, letras, zona y departamento ajeno;
- **preferencias** guardadas en las tablas de siempre;
- **TOTP:**
  - un código malo no activa y el correcto sí;
  - el secreto queda cifrado y los secretos viejos se cifran al leerlos;
  - los códigos de recuperación quedan como hash;
- **sin confirmación falsa** y sin terminar antes de confirmar los códigos;
- **al entrar:** con un código de recuperación (un solo uso) y con el de la app (sin repetir el mismo);
- **personas existentes**, con y sin datos faltantes;
- **persona nueva que ya tenía 2FA**;
- **política obligatoria:** redirección, 403 en las APIs y sin "Más tarde";
- **panel:** permisos, Exigir 2FA (redirección, 403 y aviso), Reiniciar y Dar por completo;
- **desactivar** con reautenticación y bloqueado si es obligatorio;
- que ni el secreto ni los códigos queden en el registro;
- **clave perdida:** si el secreto no se puede descifrar, igual se pide el código y ninguno sirve (no se entra sin el segundo paso).

**Además siguen pasando** `test_2fa` (33), `test_pass` (15), `test_permisos` (240), `test_activacion` (16), `test_rubros` (80), `test_csp` (47), `test_zonas` (60), `test_secretos` (13), `test_lite` (74), `test_trabajo_tickets` (87) y `test_portal_solicitudes` (93).

`test_2fa`, `test_pass` y `test_activacion` crean personas nuevas para probar otra cosa: ahora las dejan con el onboarding completado.

**En el navegador** (local, con el usuario de prueba Ian): bienvenida, perfil (validación, zona del dispositivo), preferencias, 2FA con QR, código, códigos de recuperación, resumen y final. También el panel. Después se le quitó el 2FA a Ian en la base local para que siga entrando con la contraseña de prueba. La clave de cifrado local quedó en `site/_private/totp_key.php`.

## Limitaciones
- **Idioma:** la preferencia se guarda, pero la plataforma está solo en español. La pantalla lo aclara.
- **Nombre de usuario:** se muestra pero no se edita, porque se usa para entrar. Lo cambia administración.
- **Departamento:** se elige entre los equipos de la persona; la bienvenida no permite sumarse a otro.
- **Al publicar:**
  - la primera vez se crea la clave `_private/totp_key.php` en cada sitio, y los secretos existentes se cifran a medida que cada persona entra;
  - hay que **respaldar esa clave**;
  - la bienvenida rige desde el primer pedido después de instalar; las personas que ya estaban no la ven, salvo que el admin la pida.
- **Servidor de PHP local:** al pedir una carpeta (`/toolz/trabajo/`) se pierde el `auto_prepend_file` de las pruebas. La prueba usa `trabajo/index.php`. En el hosting no pasa.

## Perfil en pantallas cortas y datos nuevos (2026-11-14)

- El paso **Perfil** se ve como **4 pantallas cortas**, cada una con su lugar en la barra (7 pasos en total): **Vos** (foto, nombre, cargo, departamento), **Sobre vos** (bio, email, teléfono, ubicación, país, zona horaria, idioma), **Fechas** (cumpleaños, con aviso si falta, y en el equipo desde) y **Lo que sabés** (herramientas y aptitudes, elegidas en un modal).
- Para el servidor sigue siendo un solo paso (`last_step` y `steps_done` no cambian). Continuar valida solo la pantalla actual; en la última se guarda todo junto. Si el servidor marca un error, se vuelve a la pantalla de ese dato. Las pantallas sin nada para completar se saltean (por ejemplo, con "solo lo que falta").
- Campos nuevos en `OB_FIELDS` (Admin › Bienvenida › Datos del perfil, **opcionales** por defecto): Email, Teléfono, Ubicación, Cumpleaños, En el equipo desde, Herramientas y Aptitudes. Las redes sociales no se piden acá. Se guardan en `save_profile` con las mismas reglas que Comunidad (`profile_fields_lib.php`).
