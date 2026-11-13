# Integraciones por agencia (Etapa 10, 2026-11-10)

Cada agencia (cada sitio Toolz) carga sus propias cuentas y claves desde **Admin › Integraciones**. Solo los admins entran ahí.

Integraciones disponibles:
- **Google Calendar:** cada persona conecta su calendario y sus eventos de Toolz se copian solos.
- **WhatsApp Business (Cloud API de Meta):** recordatorio de Turnos por WhatsApp, además del email.
- **ARCA (factura electrónica):** pedir el CAE de las facturas emitidas (WSAA + WSFEv1).
- **Portales inmobiliarios:** feed de propiedades (XML y JSON) y ficha pública para compartir.
- **YouTube:** vistas, me gusta y comentarios de los entregables de Talentos.
- **OpenCloud (autoalojado):** adjuntar archivos de la nube propia de la agencia en el chat, los tickets y las solicitudes, y una carpeta de OpenCloud por cliente (2026-11-13).

Quedan documentadas como siguiente paso: Mercado Libre Inmuebles, Instagram y TikTok (ver al final).

> Todavía no hay cuentas reales. Todo se armó con la documentación oficial y se probó con respuestas simuladas (`dev/test_integraciones.php`). La primera vez que se conecte cada una con una cuenta de verdad, conviene probarla con "Probar conexión" y mirar el Registro.

---

## Guía paso a paso para sacar las claves

### 1. Google Calendar

Lo hace una sola vez el admin de la agencia. Después, cada persona conecta su cuenta desde **Mi perfil › Google Calendar**.

1. Entra a [console.cloud.google.com](https://console.cloud.google.com) con la cuenta de Google de la agencia y crea un proyecto (por ejemplo, "Toolz de Mi Agencia").
2. En **APIs y servicios › Biblioteca**, busca **Google Calendar API** y toca **Habilitar**.
3. Configura la **pantalla de consentimiento de OAuth** (en la consola nueva se llama **Google Auth Platform**):
   - **Tipo de usuario:** "Externo". Si la agencia usa Google Workspace y solo se conecta gente del mismo dominio, "Interno".
   - **Datos de la app:** nombre (lo ve cada persona al conectar), email de asistencia, logo opcional y el dominio del sitio en "Dominios autorizados".
   - **Permisos (alcances):** agrega `.../auth/calendar.events`, `openid` y `email`.
   - **Usuarios de prueba:** mientras la app está "En prueba", solo pueden conectar los emails que agregues acá (hasta 100). En ese estado, Google corta el acceso a los 7 días y hay que volver a conectar.
4. En **Credenciales › Crear credenciales › ID de cliente de OAuth**:
   - **Tipo de aplicación:** "Aplicación web".
   - **Orígenes de JavaScript autorizados:** la dirección del sitio, por ejemplo `https://miagencia.com`.
   - **URI de redireccionamiento autorizados:** `https://miagencia.com/toolz/integraciones/google_oauth.php`.

   Los dos valores exactos se ven y se copian en la tarjeta de Google Calendar del Admin.
5. Google muestra el **ID de cliente** (termina en `.apps.googleusercontent.com`) y el **Secreto del cliente**. Pégalos en **Admin › Integraciones › Google Calendar**, activa la tarjeta, guarda y toca **Probar conexión**.
6. **Para que dure:** en la pantalla de consentimiento, toca **Publicar la app**. El permiso `calendar.events` es "sensible", y Google puede pedir una verificación:
   - el dominio verificado;
   - una página de política de privacidad;
   - un video que muestre para qué se usa.

   Sin esa verificación, las personas ven el aviso "Google no verificó esta app" y pueden seguir igual desde "Configuración avanzada".

Cómo funciona:
- Cada persona toca **Conectar Google Calendar** en su perfil y acepta en Google.
- Cada 10 minutos (con cualquier página abierta), Toolz copia sus eventos a su calendario principal:
  - **Crea** los que no están en Google.
  - **Actualiza** los que cambiaron.
  - **Borra** los que se borraron en Toolz.
- Se copian los eventos personales, las visitas de Propiedades y los turnos (todos están en `g7_cal_events`). Los que terminaron hace más de 30 días no se suben, salvo los que se repiten.
- Es en **una sola dirección**: lo que se cambie en Google no vuelve a Toolz. Si alguien borra en Google un evento que sigue en Toolz, se vuelve a crear la próxima vez que cambie.
- En el perfil hay **Sincronizar ahora** y **Desconectar**. Desconectar también le quita el permiso a Toolz en la cuenta de Google.

### 2. WhatsApp Business (WhatsApp Cloud API de Meta)

1. Necesitas una cuenta de [Meta Business](https://business.facebook.com) de la agencia, idealmente verificada.
2. En [developers.facebook.com](https://developers.facebook.com):
   - entra a **Mis apps › Crear app**;
   - elige el caso de uso **Conectar con clientes a través de WhatsApp** (tipo "Empresa");
   - asóciala a la cuenta de Meta Business.
3. En la app, entra a **WhatsApp › Configuración de la API**. Meta crea un **número de prueba**, y ahí ves:
   - el **Identificador del número de teléfono** (phone number id), que es lo que va en Toolz;
   - el identificador de la cuenta de WhatsApp Business;
   - un token temporal, que dura 24 horas (sirve solo para probar).
4. **Número propio:**
   - en **Agregar número de teléfono**, carga el número de la agencia;
   - no puede estar en uso en la app de WhatsApp: si lo está, hay que borrar esa cuenta primero;
   - se verifica por SMS o por llamada;
   - Meta tiene que aprobar el nombre visible.

   Ese número tiene su propio phone number id.
5. **Token permanente:**
   1. En [business.facebook.com](https://business.facebook.com), entra a **Configuración del negocio › Usuarios › Usuarios del sistema**.
   2. Toca **Agregar** y crea uno con rol Administrador.
   3. En **Asignar activos**, dale control total de la app y de la cuenta de WhatsApp.
   4. Toca **Generar token** y completa así:
      - elige la app;
      - vencimiento **Nunca**;
      - permisos `whatsapp_business_messaging` y `whatsapp_business_management`.
   5. Copia el token: se ve una sola vez.
6. **Plantilla del recordatorio:** en **WhatsApp Manager › Plantillas de mensajes › Crear plantilla**:
   - **Categoría:** Utilidad.
   - **Nombre:** `recordatorio_turno` (o el que pongas en Toolz).
   - **Idioma:** Español (ARG), código `es_AR` (o el que corresponda; tiene que coincidir con el de Toolz).
   - **Cuerpo, con tres variables:**

     > Hola {{1}}, te recordamos tu turno con {{2}} el {{3}}. Si no puedes asistir, avísanos o cancélalo desde tu panel.

     Toolz manda: {{1}} el nombre del paciente, {{2}} el profesional y {{3}} la fecha y la hora ("lunes 3 de noviembre a las 10:00 h").
   - Espera a que la aprueben. Suele tardar minutos.
7. **Pago:** en la cuenta de WhatsApp Business, agrega un método de pago. Meta cobra cada mensaje de plantilla (los de Utilidad tienen precio por país).
8. En **Admin › Integraciones › WhatsApp Business**:
   - pega el phone number id y el token;
   - revisa el nombre y el idioma de la plantilla;
   - activa la tarjeta y guarda;
   - toca **Probar conexión**: muestra el número y el nombre verificado.
9. **Primera prueba:** usa **Enviar hello_world**. Meta crea sola esa plantilla, en inglés. Con el número de prueba, el destinatario tiene que estar en la lista de números de prueba de la app (hasta 5).

Cómo funciona:
- El recordatorio de Turnos sale el día anterior, igual que el email:
  - a los pacientes con email, por email;
  - a los que tienen WhatsApp (o, si no, teléfono) en su ficha, por WhatsApp;
  - un paciente con los dos recibe los dos.
- Los números se pasan solos al formato internacional. En Argentina, `11 15 5555 0000` queda `5491155550000`.
- Se puede apagar solo el WhatsApp con la casilla "Mandar el recordatorio de Turnos por WhatsApp".
- **Versión de la API:** v24.0, en "Opciones avanzadas". Meta da de baja cada versión unos dos años después. Si un día falla con "versión no soportada", se cambia ahí.

### 3. ARCA · factura electrónica (WSAA + WSFEv1)

Necesitas la clave fiscal nivel 3 del titular del CUIT, o de alguien con la relación delegada.

**a) Clave privada y pedido de certificado (CSR).** En una computadora con OpenSSL (Git Bash lo trae), corre:

```bash
openssl genrsa -out toolz.key 2048
```

```bash
openssl req -new -key toolz.key -subj "/C=AR/O=Mi Agencia/CN=toolz/serialNumber=CUIT 20123456789" -out toolz.csr
```

Cambia el nombre y el CUIT (sin guiones) por los del emisor. Guarda `toolz.key` en un lugar seguro: es la clave privada.

**b) Certificado de homologación (pruebas):**
1. En el sitio de ARCA, entra a **Administrador de Relaciones de Clave Fiscal › Adherir servicio** y suma **WSASS - Autogestión Certificados Homologación**.
2. En **WSASS**:
   - **Nuevo certificado:** pon un nombre y pega el contenido de `toolz.csr`. Copia el certificado que devuelve a un archivo `toolz_homo.crt`.
   - **Crear autorización a servicio:** elige ese certificado y el servicio **wsfe** (Facturación Electrónica).

**c) Certificado de producción:**
1. Adhiere el servicio **Administración de Certificados Digitales**. Ahí:
   - agrega un alias (por ejemplo `toolz`);
   - sube `toolz.csr`;
   - descarga el `.crt`.
2. En **Administrador de Relaciones de Clave Fiscal › Nueva relación**:
   - **Servicio:** ARCA › WebServices › **Facturación Electrónica**;
   - **Representante:** el certificado (computador fiscal) del alias que creaste.

**d) Punto de venta:** en **Administración de puntos de venta y domicilios › Agregar**, crea uno **nuevo solo para Toolz**:
- con el sistema "Factura Electrónica - Monotributo - Web Services" (monotributo);
- o "RECE para aplicativo y web services" (responsable inscripto).

No uses el de "Comprobantes en línea": la numeración se mezclaría.

**e) En Admin › Integraciones › ARCA:**
1. Completa los datos:
   - CUIT;
   - punto de venta;
   - condición frente al IVA (Monotributo o Exento emiten C; Responsable inscripto emite A o B);
   - qué se factura (servicios, por defecto);
   - ambiente **Homologación**;
   - el contenido del `.crt` y del `.key` (y la contraseña de la clave, si le pusiste una).
2. Activa la tarjeta, guarda y toca **Probar conexión**. Muestra el estado de los servidores, el ticket de acceso y el último número del punto de venta.
3. Prueba pedir un CAE en homologación: no tiene validez fiscal.
4. Para pasar a **Producción**, cambia el ambiente y pega el certificado de producción. El ticket guardado se descarta solo.

Cómo funciona:
- **Dónde:** Facturación › Cobros › pestaña **Factura electrónica**. Lista las facturas emitidas, con un botón **Pedir CAE** en las que todavía no lo tienen.
- **Tipo de comprobante:**
  - Monotributo y Exento: siempre **Factura C**;
  - Responsable inscripto: **Factura A** a inscriptos y monotributistas (el cliente necesita CUIT), **Factura B** al resto. A y B necesitan IVA en las líneas.
- **Receptor:**
  - la condición sale de la ficha del cliente ("Responsable Inscripto", "Monotributista", "Exento"…; vacía = consumidor final);
  - el documento es el CUIT (11 números) o el DNI; sin documento, consumidor final.
- **Pedido:**
  - Toolz pide el último número autorizado (`FECompUltimoAutorizado`) y manda el siguiente con `FECAESolicitar`.
  - Las facturas en USD van con la cotización que informa ARCA.
- **Lo que se guarda en la factura:** CAE, vencimiento del CAE, punto de venta, número fiscal, tipo, y el detalle con el QR de ARCA (RG 4892).
- **PDF:** sale con "Factura C N.º 00002-00000042", el CAE y el QR, en lugar del aviso de comprobante interno.
- **Después del CAE**, la factura ya no se puede corregir ni cancelar desde Toolz: hace falta una nota de crédito (pendiente).
- **Ticket de acceso (TA):** dura 12 horas y se reutiliza mientras está vigente. Si ARCA responde que "ya posee un TA válido", hay que esperar a que venza, unos minutos.

### 4. Portales inmobiliarios

No hace falta ninguna clave.
1. En **Admin › Integraciones › Portales inmobiliarios**, activa la tarjeta.
2. Completa:
   - el teléfono y el email de contacto de los avisos;
   - si se muestra el precio;
   - si se muestra la dirección exacta (si no, solo el barrio y la ciudad).
3. Copia el **Feed XML**, en formato Trovit: lo aceptan Trovit, Mitula, Nestoria y la mayoría de los agregadores. Pásalo al portal o agregador que lo pida.

   Zonaprop, Argenprop y otros portales locales suelen trabajar con integradores propios: pregúntales si aceptan un feed XML. Si piden otro formato, el **Feed JSON** tiene todos los datos para armarlo.
4. **Generar otro link** cambia el token: el anterior deja de funcionar. Úsalo si el link se filtró.

Ficha pública:
- En Propiedades, cada propiedad tiene el botón **Compartir**. Copia un link firmado a una página pública con:
  - fotos, datos, descripción y comodidades;
  - un botón de WhatsApp y el email de la agencia;
  - sin notas privadas ni propietario.
- Desde ahí se puede mandar directo por WhatsApp.
- **Vigencia (desde la 1.0.27):** el link vence a los 90 días, y se puede elegir 7, 30, 90 o sin vencimiento. La fecha va firmada en la URL: cambiarla rompe la firma. Compartir pide permiso de editar Propiedades y queda en Auditoría (`prop.share`).
- **Revocar los links enviados:** en el mismo cuadro, un botón deja sin efecto todos los links de esa propiedad, los nuevos y los de antes, y después se puede generar uno nuevo. Por dentro sube un contador por propiedad (`portales.share_rev` en `_private/integrations.json`), que entra en la firma.
- **Links de antes de la 1.0.27** (sin `e=` en la URL): siguen andando hasta que se revoque esa propiedad.
- **Feed:** los links del feed no vencen, porque los portales lo releen seguido. Revocar la propiedad igual los corta.
- Si la propiedad se vende o alquila, el link muestra "ya no está disponible".
- El feed y la ficha no se indexan en buscadores (`noindex`).

### 5. YouTube (métricas de Talentos)

1. En [console.cloud.google.com](https://console.cloud.google.com) (puede ser el mismo proyecto de Google Calendar), entra a **APIs y servicios › Biblioteca**, busca **YouTube Data API v3** y toca **Habilitar**.
2. En **Credenciales › Crear credenciales › Clave de API**, copia la clave.
3. **Restringe la clave** (Editar clave de API):
   - **Restricciones de API:** solo "YouTube Data API v3".
   - **Restricción de aplicación:** "Direcciones IP", con la IP del servidor del sitio, o ninguna.

     No uses "Sitios web" (HTTP referrers): la consulta sale del servidor, no del navegador.
4. Pégala en **Admin › Integraciones › YouTube**, activa, guarda y toca **Probar conexión**.

Cómo funciona:
- **Qué entregables:** los de Talentos con un link de YouTube (watch, youtu.be, shorts o live).
- **Qué trae:** vistas, me gusta y comentarios. Lo demás (alcance, guardados…) se sigue cargando a mano y no se pisa.
- **Cuándo:**
  - una vez por día, si queda tildado "Actualizar las métricas solas";
  - con **Actualizar métricas ahora** en el Admin;
  - con **Traer de YouTube** en cada entregable.
- **Cuota:** 10.000 unidades por día. Cada consulta trae hasta 50 videos y cuesta 1 unidad, así que alcanza de sobra.
- **Datos que no llegan:** si el canal oculta los me gusta o cerró los comentarios, ese dato no llega. Los videos privados o borrados se informan aparte.

### 6. OpenCloud (la nube de archivos propia, autoalojada)

Pedido del usuario, 2026-11-13: "tenemos que sumar a integración opencloud", que va a ser **autoalojado**.

**Decisiones del usuario:**
- Lo que hace: **adjuntar desde OpenCloud** y **una carpeta por cliente**.
- La conexión es **una cuenta para toda la agencia**.

**Qué hay que preparar en el servidor de OpenCloud:**
1. **Una cuenta solo para Toolz**, por ejemplo `toolz`. Lo que se ve desde Toolz es lo de esa cuenta, así que conviene que no sea la de una persona.
2. **Un token de aplicación para esa cuenta.**
   - Se crea desde OpenCloud (Mi cuenta › Tokens de aplicación) o en el servidor con `opencloud auth-app create --user-name=toolz --expiration=8760h`.
   - El servidor tiene que tener prendido el servicio de tokens de aplicación: `PROXY_ENABLE_APP_AUTH=true`.
   - **No uses la contraseña de la cuenta.**
3. **Un certificado válido (https)**, por ejemplo de Let’s Encrypt.
   - El servidor tiene que poder verse desde el hosting de Toolz.
   - Si usás un certificado autofirmado, existe la opción avanzada "Aceptar un certificado propio", pero no se recomienda.
4. **Links públicos:** para que funcionen, la cuenta tiene que poder compartir con link.

**En Toolz:** Admin › Integraciones › OpenCloud:
- dirección del servidor, usuario y token;
- la **Carpeta de Toolz**, por defecto `/Toolz`. Desde Toolz no se ve nada fuera de ella;
- la carpeta de los clientes, por defecto `Clientes`;
- qué link se pega al adjuntar: interno o público;
- quién puede adjuntar: todas las personas o solo admins.

Después, activar, guardar y tocar **Probar conexión**, que crea las carpetas si faltan.

**Cómo funciona:**
- **Adjuntar:** en el chat, en la respuesta de un ticket de Trabajo y en la conversación de una solicitud del portal aparece el botón **OpenCloud** (una nube).
  - Abre un explorador de la Carpeta de Toolz: se entra a las carpetas y se tildan archivos.
  - Al tocar **Adjuntar**, se pega el link de cada archivo en el mensaje: en el chat como `[📎 nombre](link)` y en tickets y solicitudes como `📎 nombre: link`.
  - En cada adjunto se puede elegir **link público**, de solo lectura, para quien no tiene cuenta en OpenCloud.
  - Los links en tickets y solicitudes ahora son clicables.
- **Carpeta del cliente** (Clientes › ficha › Archivos, tarjeta "Carpeta en OpenCloud"):
  - **Crear la carpeta:** queda en `/Toolz/Clientes/<nombre del cliente>`.
  - **Elegir una carpeta** que ya exista.
  - Ver lo que tiene, con subcarpetas.
  - **Abrir en OpenCloud.**
  - **Crear o copiar el link para compartir:** es público y de solo lectura.
  - **Desvincular:** en OpenCloud no se borra nada.
  - Hace falta el permiso de Clientes.

**Cómo está hecho:**

| Archivo | Qué hace |
|---|---|
| `integraciones/opencloud.php` | Lo de OpenCloud: WebDAV (`PROPFIND` para listar, `MKCOL` para crear carpetas) y links públicos por la API Graph (`createLink`). Si el servidor no tiene Graph, usa la API OCS de compartidos |
| `integraciones/opencloud_api.php` | La API para la pantalla, con la cabecera `X-INT: 1` |
| `assets/opencloud.js` y `assets/opencloud.css` | El botón y el selector en los cuadros de mensaje, y la tarjeta de la ficha |
| `topbar.php` | Carga el script solo si la integración está conectada |
| `admin_clientes.js` | El lugar de la tarjeta en la ficha |

- Las carpetas vinculadas a cada cliente se guardan en la tabla `g7_oc_client_folders`.

**Seguridad:**
- El token nunca va al navegador ni al registro.
- Toda ruta se limpia (`oc_clean`): no puede usar `..` ni salir de la Carpeta de Toolz. Desde la ficha, tampoco puede salir de la carpeta del cliente.
- Solo se pegan links del mismo servidor.
- Solo se acepta `https`. `http` solo vale para `localhost` en las pruebas.

**Pruebas:**
- `dev/test_opencloud.php` da **47/47**. Corre contra el OpenCloud simulado `dev/mock_opencloud.php` (preview `toolz-mock-opencloud`, :8097: usuario `toolz`, token `oc-prueba`).
- También se probó en el navegador, en la demo: conectar y "Probar conexión", adjuntar en el chat, el botón en tickets y la carpeta del cliente.
- **Falta probarlo contra un OpenCloud real.** La primera vez conviene mirar el Registro de la integración.

---

## Siguiente paso (documentado, no hecho)

- **Mercado Libre Inmuebles: publicar directo desde Propiedades.**
  - **Qué hace falta:**
    - una app en [developers.mercadolibre.com.ar](https://developers.mercadolibre.com.ar) (Mis aplicaciones › Crear), con la URI de redirección del sitio y los permisos `read`, `write` y `offline_access`;
    - que la cuenta de la inmobiliaria tenga habilitada la publicación de inmuebles (clasificados, con su plan de publicaciones).
  - **Cómo se armaría:**
    - el admin conecta la cuenta por OAuth, como Google;
    - "Publicar en Mercado Libre" crea el aviso con `POST /items` (categoría de inmuebles, atributos de la propiedad, fotos por URL);
    - el link queda en Avisos (`g7_prop_listings`) y se sincronizan precio y estado.
- **Instagram (métricas de Talentos):**
  - **Qué hace falta:**
    - una cuenta profesional (Business o Creator) de cada talento;
    - una app de Meta con "Instagram API con inicio de sesión de Instagram" y el permiso `instagram_business_manage_insights`.
  - **Por qué no está:** Meta exige **revisar la app** (App Review, con video del uso) antes de usarla con cuentas que no son de prueba.
- **TikTok (métricas de Talentos):**
  - **Qué hace falta:** una app en [developers.tiktok.com](https://developers.tiktok.com) con **Login Kit** + **Display API** (`user.info.basic`, `video.list`).
  - **Por qué no está:** TikTok **revisa la app** antes de abrirla a otros usuarios.
- **Nota de crédito electrónica** para anular una factura con CAE (tipos 3, 8 y 13, con el comprobante asociado).

---

## Para quien mantenga el código

Partes (`site/integraciones/`):
- **`lib.php`:** la base común.
  - `int_defs()` lista los campos de cada integración.
  - `int_cfg()`, `int_ready()` e `int_save()` leen, validan y guardan. Un secreto vacío no se cambia, y `clear` lo borra.
  - `int_public()` arma lo que ve el Admin: los secretos solo como `{saved}`.
  - `int_log()` escribe el log y la última actividad o el último error; `int_scrub()` saca las claves de los textos.
  - `int_http()` hace los pedidos; en las pruebas se reemplaza con `$GLOBALS['INT_HTTP_MOCK']`.
  - `int_sign()` firma con HMAC, con la clave `_key` del archivo.
- **`api.php`:** la API del Admin. Solo admins, POST JSON con cabecera `X-INT: 1`.
  - Acciones: get, save, test, wa_hello, feed_regen, yt_refresh, arca_reset_ta y log.
- **`gcal.php`, `google_oauth.php`, `user_api.php` y `perfil_google.php`:** Google Calendar.
  - Tokens por persona en `integrations.json` (`google_calendar.users`).
  - Mapeo de ids en `g7_int_gcal_map`.
  - El `state` va firmado y se usa una sola vez: la cookie de sesión es `SameSite=Strict` y no vuelve en el regreso de Google.
- **`whatsapp.php`:** `wa_number()`, `wa_send_template()` y `wa_test()`. Lo llama `ap_remind()` en `turnos/lib.php`.
- **`arca.php`:** la factura electrónica.
  - `arca_tra()` y `arca_sign_tra()` arman y firman el TRA (`openssl_pkcs7_sign`); `arca_ta()` pide y guarda el TA.
  - `arca_request()` y `arca_request_xml()` arman el pedido, con los campos en el orden del WSDL.
  - `arca_request_cae()` pide el CAE: usa un `GET_LOCK` para que dos pedidos no tomen el mismo número.
  - `arca_install()` suma `cbte_tipo`, `pto_vta` y `cbte_nro` a `g7_client_invoices`.
- **`portales.php`, `feed.php` y `ficha.php`:** el feed y la ficha pública.
- **`youtube.php`:** las métricas de Talentos.
- **Admin:** `site/admin_integraciones.php` y `admin_integraciones.js`, que se cargan con `SECTION_LOADERS.integraciones`.

Otros cambios:
- **`topbar.php`:** el barrido de Google Calendar (cada 10 minutos) y el de YouTube (cada 24 horas), solo si existe `_private/integrations.json`.
- **`facturacion/cobros.*`:** la pestaña Factura electrónica.
- **`facturacion/api.php`:** ya no deja corregir ni cancelar una factura con CAE.
- **`facturacion/doc.js` y `app.js`:** el bloque fiscal con el QR en el PDF.
- **`propiedades/api.php` y `app.js`:** el botón Compartir.
- **`talentos/api.php` y `app.js`:** el botón Traer de YouTube.
- **`user_config.php`:** la sección Google Calendar.
- **`admin.php`:** el menú y la sección.

Seguridad:
- **Dónde viven las claves:** en `site/_private/integrations.json`, dentro de `_private/`. Esa carpeta ya está en `U_PROTECTED`: las actualizaciones no la pisan.
- **Acceso por web:** `_private/.htaccess` bloquea la carpeta. Si falta, la librería lo crea.

  El servidor de desarrollo de PHP (`php -S`) no lee `.htaccess`: en local el archivo se puede pedir por web, en el hosting no.
- **Log:** `_private/integrations.log`, una línea JSON por acción. Rota a los 2 MB. Las claves se reemplazan por `***`.
- **Lo que nunca sale del servidor:** ninguna clave ni certificado va al navegador. El Admin muestra "guardada" y un enlace para borrarla.

Pruebas:
- `php dev/test_integraciones.php`: 100/100, con la base `in_test` en MariaDB :3407 y respuestas simuladas.
  - Cubre la base común, OAuth, la renovación del token y la sincronización (crear, actualizar, borrar y volver a crear).
  - Cubre los números y la plantilla de WhatsApp y el recordatorio de Turnos.
  - En ARCA, cubre:
    - tipos A, B y C, y el XML en orden;
    - el TRA firmado con un certificado autofirmado;
    - el TA guardado, el CAE guardado, el rechazo y el error SOAP.
  - Cubre el feed XML y JSON, la ficha firmada, YouTube, y que ninguna clave llegue al log ni al Admin.
- **Las demás suites siguen pasando:** rubros, propiedades, vencimientos, turnos, talentos_builds, cobros, portal_cfg, license, update, base_api y tienda.
- **En el navegador**, con la demo (:8154):
  - Google Calendar activado: el secreto queda "guardada" y nunca vuelve en la respuesta;
  - Portales activado: Probar conexión, feed XML y JSON, y token malo → 404;
  - Compartir en una propiedad, y la ficha pública (firma mala → 404);
  - Mi perfil › Google Calendar con el botón Conectar;
  - Cobros › Factura electrónica sin ARCA (aviso) y con ARCA de prueba (lista con "Pedir CAE").

  No se llamó a ningún servicio real.

## Mercado de integraciones

Arriba de Admin › Integraciones hay un catálogo por categoría (Pagos, Productividad, Comunicación, Facturación fiscal, IA y contenido, Inmobiliarias, Video, Próximamente) con el estado real de cada una (`int_market()` en `integraciones/lib.php`, categorías en `INT_CATS`). Las de este registro llevan a su tarjeta; Jira, Monday e IA abren su sección del admin (no se reescribieron); los medios de pago abren Finanzas y pagos › Medios de pago (ver PAGOS.md). Solo informa si hay claves guardadas: nunca las lee para mostrarlas.
