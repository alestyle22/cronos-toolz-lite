# Zonas horarias

Cada persona ve las fechas y horas en su zona, y el resto del equipo ve qué hora es donde está (chat, perfil).
Implementado el 2026-11-12. Código: `site/tz_lib.php` (servidor) y `site/assets/g7-time.js` (navegador). Prueba: `dev/test_zonas.php`.

## Cómo se guardan las fechas

- **Zona de almacenamiento** (`g7_config.tz_storage`): una sola por instalación y **fija**. Se toma la primera vez que el sitio
  se conecta a la base con esta versión, de la zona del sitio (`timezone`). No cambia aunque el admin cambie la zona del sitio.
- `getDB()` (auth.php) llama a `g7_tz_boot()`: deja PHP (`date_default_timezone_set`) y MySQL (`SET time_zone = '±hh:mm'`) en esa
  zona. Así `date('Y-m-d H:i:s')`, `NOW()` y `DEFAULT CURRENT_TIMESTAMP` escriben lo mismo, y cada DATETIME es un instante exacto.
- `access.php` también usa `tz_storage` para PHP (antes usaba la zona del sitio).
- Se eligió una zona fija en vez de migrar todo a UTC (decisión del 2026-11-12): migrar 211 columnas en cada agencia y revisar toda
  la lógica de "hoy" (turnos, vencimientos, estadísticas por día) era riesgoso. Como todo se muestra con los helpers, pasar a UTC más
  adelante es solo una migración de datos más cambiar `tz_storage`.
- Límite conocido: si la zona de almacenamiento tiene horario de verano, la hora repetida al atrasar el reloj es ambigua (1 hora al
  año). Argentina no tiene horario de verano.

### El problema que había (y cómo se corrige lo viejo)

Hasta esta versión, PHP escribía en la zona del sitio y MySQL en la del servidor de base. En producción MySQL está en UTC
(`@@time_zone = SYSTEM`, `NOW() = UTC_TIMESTAMP()`) y el sitio en Argentina: lo que escribió MySQL quedó **3 horas adelantado**.

Al fijar la zona se anotan `tz_fixed_at` (desde cuándo) y `tz_db_offset_before` (desfase de MySQL hasta entonces, en minutos).
`dev/tz_corregir_historico.php` corrige lo viejo:

```
php dev/tz_corregir_historico.php --db=NOMBRE                 # simulación: lista columnas y filas
php dev/tz_corregir_historico.php --db=NOMBRE --aplicar       # aplica (después de un respaldo)
```

- Clasifica las columnas que llena MySQL (DEFAULT CURRENT_TIMESTAMP, ON UPDATE, `NOW()` en el código):
  **mysql** (solo MySQL: se corrigen) y **mixta** (el código también les pone fechas de PHP: no se tocan, revisar a mano).
  La clasificación es una heurística (mira 4 líneas alrededor de cada `NOW()` y cada `date('Y-m-d H:i…')`): revisar la lista.
- Solo cambia filas anteriores a `tz_fixed_at`. Deja `tz_hist_fixed` para no aplicarse dos veces.
- Conviene correrlo enseguida después de instalar la versión: las filas que MySQL escribió en las últimas horas antes del cambio
  quedan "en el futuro" respecto de `tz_fixed_at` y no se tocan.
- En producción: respaldo, simulación, y aplicar **solo con el visto bueno del dueño**.

## Zona de cada persona

- Mi perfil › **Ubicación y zona horaria** (`user_config.php`, `assets/user-tz.js`): país con buscador y banderas; si el país tiene una
  sola zona se toma sola, si tiene varias (Argentina, Estados Unidos, Brasil, México, España/Canarias…) se elige, con la del
  dispositivo propuesta si es de ese país. Vista previa de la hora en vivo. Ciudad opcional.
- Columnas nuevas en `g7_users` (las agrega `g7_tz_install()`): `country` (ISO de 2 letras), `timezone` (id IANA, p. ej.
  `America/Argentina/Buenos_Aires`), `city`, `city_public`.
- Se guarda el **id IANA**, no el desfase: los cambios de horario de verano se aplican solos.
- Sin zona propia: se usa la del sitio (`g7_tz_site()`).
- Cambiar la zona **nunca** toca lo guardado: solo cambia cómo se muestra.
- Las zonas de cada país salen de PHP (`DateTimeZone::PER_COUNTRY`); los nombres de los países, de `Intl.DisplayNames` del navegador
  (el PHP de los sitios puede no tener intl). Nombres en español de las zonas más vistas: `G7_TZ_NAMES`.
- Banderas: librería **flag-icons 7.2.3** desde jsdelivr (CSP: `style-src` ya permite jsdelivr e `img-src https:`), con SRI.
  `G7Time.flag(cc)` la carga la primera vez que se usa.

### Privacidad

- País, zona y hora local: se muestran siempre (decisión del 2026-11-12: sin opción de ocultar la hora).
- Ciudad: solo si la persona activa "Mostrar mi ciudad en mi perfil". `g7_tz_public()` es el único lugar que arma lo que ven otros.

## Mostrar fechas

### Servidor (`tz_lib.php`)

| Función | Qué hace |
|---|---|
| `g7_dt($guardada, 'd/m/Y H:i', $tz = null)` | Fecha guardada → texto en la zona de quien mira (o la pedida) |
| `g7_dt_obj()` / `g7_dt_ts()` / `g7_dt_iso()` | DateTimeImmutable / timestamp / ISO en UTC |
| `g7_tz_viewer()`, `g7_tz_user($uid)`, `g7_tz_site()`, `g7_tz_storage()` | Zonas |
| `g7_tz_diff_label($mia, $otra)` | "Tu misma zona horaria", "2 horas menos", "3 h 30 min más" |
| `g7_tz_country_zones($cc)`, `g7_tz_countries()`, `g7_tz_name($tz)` | Países y zonas |
| `g7_tz_public($fila)` | Lo que se muestra de la ubicación de alguien a otros |
| `g7_tz_save($uid, $cc, $tz, $ciudad, $publica)` | Guarda (valida que la zona sea de ese país) |

### Navegador (`assets/g7-time.js`, `window.G7Time`)

Lo carga `topbar.php` en todas las páginas con `data-cfg='{"store","tz","site"}'`. El servidor manda las fechas como
`AAAA-MM-DD HH:MM:SS` en la zona de almacenamiento; el helper calcula el instante real **sin depender del reloj ni de la zona del
navegador** y lo muestra en la zona de quien mira.

- `parse(s)` (Date), `time`, `date`, `dateTime`, `fmt(s, opcionesIntl)`, `dayLabel` (Hoy/Ayer/…), `short`, `ago` ("hace 5 min"),
  `sameDay`, `dayKey`.
- Campos `datetime-local`: `inputValue(fecha)` para mostrarlos y `toStore(fromInput(valor))` para mandarlos al servidor.
- Otras zonas: `clock(tz)`, `offsetLabel(tz)`, `diffLabel(otra)`, `isNight(tz)`, `countryName(cc)`, `flag(cc)`, `locHtml(loc)`.
- `<span data-g7-clock="Europe/Madrid">` y `<time data-g7-ago="…">` se actualizan solos (cada 15 s, alineado al minuto).
  `data-g7-loc='{"cc","tz","zone","city"}'` se rellena con bandera, país, hora local y zona.
- En la hora repetida al atrasar el reloj elige la segunda ocurrencia, igual que PHP (lo verifica la prueba).

**Regla para código nuevo:** no armar fechas con `new Date('AAAA-MM-DD HH:MM:SS'.replace(' ', 'T'))` ni `toLocaleString()` sin zona:
usar `G7Time` en el navegador y `g7_dt()` en PHP.

## Dónde se aplicó

- **Chat** (`chat/chat.js`, `widget.js`, `refs.js`, `admin.js`, `lib.php`, `api.php`, `chat2.css`): horas de los mensajes, separadores
  de día, "visto", recordatorios, mensajes programados y anuncios (los campos de fecha usan la zona de quien escribe).
  En un mensaje directo, la cabecera muestra bandera, hora local de la otra persona (en vivo), país/ciudad y la diferencia; al pasar el
  mouse por su nombre o avatar aparece una ventanita con la hora local; la ficha también la muestra. El widget flotante muestra
  la bandera y la hora en el subtítulo. El horario de silencio usa la zona de la persona.
- **Comunidad** (`comunidad.php`, `assets/comunidad-page.js`): posts, respuestas, actividad, comentarios del perfil; el perfil
  (propio y de otros) muestra país, ciudad si se comparte, zona y hora local.
- **Campana** de la topbar (`assets/topbar.js`).
- **Calendario** (`calendario/`): cada evento guarda su zona (`g7_cal_events.tz`, la de quien lo crea) y la hora de pared, como
  iCalendar: "todos los lunes a las 10" sigue a las 10 aunque cambie el horario de verano. Se muestra en la zona de quien mira; los de
  todo el día no se corren de fecha. Los avisos se escriben en la zona de la persona. La sincronización con Google Calendar manda la zona
  del evento (`integraciones/gcal.php`).
- **Mi perfil › Seguridad**, **Trabajo**, **Tareas**, **Clientes**, **Admin** (`admin_ui.js`): los "hace X" y el registro de cambios.

### Pendiente (segunda tanda)

Siguen mostrando la hora de almacenamiento tal cual (correcto para quien está en la zona del sitio): el resto de Trabajo (fechas que se
cortan del texto), estadísticas, facturación, tienda, juegos, imghost, status, planify, mailing y landings. Para pasarlos: buscar
`replace(' ', 'T')`, `toLocale` y `date(` en cada módulo y usar `G7Time` / `g7_dt()`.

## Verificación en dos pasos: guía paso a paso

Relacionado (mismo pedido, 2026-11-12): si el sitio exige la verificación en dos pasos y la persona no la tiene, `g7_totp_enforce()`
la manda a `activar_2fa.php?next=…` (antes iba a Mi perfil › Seguridad). Cinco pasos: qué es · instalar la app (links a Google
Authenticator, Microsoft Authenticator y Authy) · escanear el QR (o escribir la clave, o abrirla en la app del mismo teléfono) · escribir
el código (6 casillas, se puede pegar) · guardar los códigos de respaldo (copiar, descargar .txt y confirmar). Al terminar vuelve a la
página que quería abrir. También se llega desde Mi perfil › Seguridad ("Guía paso a paso"). Cuando el sitio no la exige, no se muestra sola.

## Pruebas

- `G7_TEST_DB="127.0.0.1;port=3407" php dev/test_zonas.php` (60 comprobaciones): almacenamiento, conversiones con y sin horario de
  verano, cambio de hora (hora inexistente y repetida), países con varias zonas, validación, privacidad, inmutabilidad de lo guardado,
  helper del navegador contra el servidor (con node) y repeticiones del calendario.
- Navegador (demo `rubros_demo`): Mi perfil (España › Canarias), chat entre Canarias y Nueva York (cabecera, ventanita, ficha), guía de
  dos pasos completa y la redirección cuando se exige.
