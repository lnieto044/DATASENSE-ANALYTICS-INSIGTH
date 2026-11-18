# DataSense Analytics Insights

Plataforma web de una consultora de analítica de datos: sitio público con chatbot, tienda en línea y un panel
de gestión con mesa de servicios (ITIL), canal de PQRSD, facturación y contabilidad.

## Demo

Hay una demostración publicada en Render con **datos ficticios** (ningún nombre, correo ni documento es real).
Todas las cuentas usan la clave `Demo2026*`:

| Cuenta | Rol | Qué puede ver |
|---|---|---|
| `admin@example.com` | Administrador | Todo, incluida la configuración de roles y del ANS |
| `supervisor@example.com` | Supervisor | Supervisión de la mesa de servicios y del canal PQRSD, reportes |
| `soporte@example.com` | Soporte | Consola de casos (Gestión IT) y bandeja PQRSD |
| `finanzas@example.com` | Finanzas | Facturación, comprobantes, conciliación, impuestos y estados financieros |
| `analista@example.com` | Analista | Dashboard, clientes, encuestas e historial del chatbot |
| `cliente@example.com` | Cliente | Su resumen, Centro de Soporte, facturas y pedidos |

Verificación en dos pasos con Google Authenticator o Microsoft Authenticator (código QR y 8 códigos de recuperación de
un solo uso). Por defecto (`MFA_MODO=opcional`) se ofrece una sola vez, al registrarse, con "Hacerlo después y entrar";
al iniciar sesión no se pide a quien no la activó, y cada quien la activa o desactiva en Mi perfil. Quien la activó
siempre escribe el código al entrar. `MFA_EQUIPO_OBLIGATORIO=1` la exige al equipo interno y `MFA_MODO=obligatorio`, a
todos. En una base permanente, quien entra con la clave publicada `Demo2026*` debe cambiarla antes de usar el panel.

Al arrancar, la demo mueve las fechas de los datos de prueba (cron/fechas_demo.php) para que el mes en curso tenga
casos, PQRSD, mensajes, pagos y reuniones, sin cambiar el orden entre ellos.

En la demo los pagos van a un simulador y el correo está desactivado. Cada vez que el servidor se reinicia los
datos vuelven a su estado inicial; en el plan gratuito de Render el servidor se apaga tras 15 minutos sin visitas
y la primera visita después tarda alrededor de un minuto.

## Módulos

- **Sitio público:** servicios, equipo, formulario de contacto, chatbot SenseBot y canal ciudadano de PQRSD.
- **Tienda en línea:** catálogo con búsqueda y filtros, carrito, cupones, reseñas y compra con factura electrónica de venta.
- **Mesa de servicios (ITIL):** incidentes y requerimientos con prioridad por impacto × urgencia, ANS de primera respuesta
  y de solución en horario hábil (con festivos de Colombia), pausas, reaperturas, cierre automático, alertas de
  escalamiento, asignación automática por especialidad, nivel y carga, y encuesta de satisfacción.
- **PQRSD (Ley 1755 de 2015):** radicación con términos en días hábiles, prórroga, requerimiento al ciudadano,
  traslado por competencia, atención prioritaria, reparto automático y reporte del canal.
- **Facturación y contabilidad:** facturas, notas crédito y débito, comprobantes de ingreso y egreso, cuentas por pagar,
  conciliación bancaria, impuestos y retenciones, libro auxiliar, libro mayor, estado de resultados, balance y cierre.
- **Pagos:** MercadoPago, PayPal, Zelle, Western Union y USDT, con validación por Finanzas.
- **Seguridad de acceso:** verificación en dos pasos al iniciar sesión y al registrarse con una app autenticadora
  (TOTP, el mismo estándar del MFA de AWS Cognito), códigos de recuperación de un solo uso, secretos cifrados,
  límite de intentos y restablecimiento por el administrador desde Seguridad y Roles.
- **Redes sociales:** publicaciones listas con su texto y un plan por días de la semana (sin festivos); publicación
  automática en Facebook, Instagram y LinkedIn en la fecha programada, o descarga del plan para programarlo a mano; diseñador rápido de afiches y editor libre (textos, fuentes,
  formas, iconos, fotos, capas, deshacer, cambio de tamaño y diseños guardados); mensajes para las respuestas
  automáticas, los comentarios con palabra clave y las preguntas frecuentes de Facebook, Instagram y WhatsApp Business.
- **Administración:** permisos por rol y módulo, equipo, plantillas de correo, encuestas y salas de reunión.

## Tecnología

PHP 8 sin framework, MySQL/MariaDB con PDO (consultas preparadas), Bootstrap 5, PHPMailer, phpdotenv y FPDF.
Protección CSRF en todos los formularios, contraseñas con bcrypt, límite de intentos de inicio de sesión y control
de acceso por rol en cada página.

## Ejecutar en local con Docker (recomendado)

Con Docker (también dentro de un Codespace) se usa la misma imagen que Render, con la base de datos incluida:

```bash
docker compose up -d --build
```

Abre `http://localhost:8080` (en un Codespace, pestaña **Puertos**, puerto 8080) e ingresa con una de las cuentas de
la tabla. La base de datos y los archivos subidos quedan en volúmenes: lo que crees se conserva al reiniciar o al
actualizar con el mismo comando. `docker compose down -v` borra todo y vuelve a los datos de demostración.

## Ejecutar en local (XAMPP)

1. Copiar el proyecto en `htdocs` y ejecutar `composer install`.
2. Crear la base `datasense_demo` e importar `deploy/demo/datasense_demo.sql`.
3. Copiar `.env.example` como `.env` y completar `DB_*` (por ejemplo `DB_NAME=datasense_demo`).
4. Abrir `http://localhost/<carpeta-del-proyecto>/` e ingresar con una de las cuentas de la tabla.

## Publicación automática en redes sociales

En **Redes Sociales › Publicación automática** se conectan las cuentas con un token de acceso (se guarda cifrado):
uno de la página de Facebook (con permisos `pages_manage_posts`, `pages_read_engagement`, `instagram_basic` e
`instagram_content_publish`) conecta Facebook y su Instagram profesional; LinkedIn necesita un token con
`w_organization_social` y el número de la página de empresa. "Programar el plan" deja cada afiche en la cola y
`cron/publicar_redes.php` publica cada minuto lo que llegó a su fecha, con hasta 3 intentos por red.

- En el contenedor (Render y la copia local) el publicador arranca solo con el servidor.
- En un hosting con cron: `* * * * * php /ruta/del/proyecto/cron/publicar_redes.php`.
- El servidor debe estar encendido a la hora de cada publicación: el plan gratuito de Render se apaga sin visitas
  y su base vuelve al estado inicial al reiniciarse, así que para publicar de verdad se necesita un servidor que
  no se duerma, con una base que conserve los datos.

## Publicar la demo en Render

El repositorio incluye `render.yaml` y un `Dockerfile` que arman un solo contenedor con Apache, PHP 8.2 y MariaDB
con la base de demostración. En Render: **New → Blueprint**, elegir este repositorio y **Apply**.

## Publicar la demo en Vercel

Vercel ejecuta el mismo `Dockerfile` con su tiempo de ejecución de contenedores (`vercel.json` lo indica con
`"framework": "container"`). En vercel.com/new se importa este repositorio y se despliega, sin variables: la imagen
trae los valores de la demostración, escucha en el puerto 80 y genera la clave interna de la base al arrancar.

- La base de demostración va dentro de la imagen; las fechas se ponen al día en cada arranque.
- Tras unos minutos sin visitas Vercel apaga el contenedor: sin base permanente, lo creado en la demo (casos,
  usuarios, verificación en dos pasos) se pierde y la siguiente visita arranca con los datos iniciales. Mientras está
  apagado no corre la publicación automática.
- Los tokens de redes (`META_PAGE_TOKEN`, `LINKEDIN_TOKEN`, `LINKEDIN_ORG_ID`) son opcionales y se agregan en las
  variables de entorno del proyecto en Vercel, nunca en el código.

### Base de datos permanente

Con la variable `DATABASE_URL` el sitio usa una base externa en lugar de la que trae la imagen, y nada se borra al
reiniciar. Probado con TiDB Cloud (plan gratuito, compatible con MySQL; elegir la región de AWS en N. Virginia, la misma
de Vercel). En su consola: abrir la base → botón para conectar → generar la contraseña → copiar la cadena de conexión
`mysql://usuario:clave@servidor:4000/test` y pegarla en Vercel como `DATABASE_URL`; luego volver a desplegar.

- La primera vez la base se crea con el nombre `datasense` y recibe los datos de demostración con las fechas del mes
  en curso. Desde ahí las fechas no se mueven y todo lo que se guarde queda.
- La conexión es cifrada (TLS) y verifica el certificado del servidor.
- Las sesiones y los intentos fallidos también se guardan en la base: un ingreso sigue abierto aunque Vercel reinicie
  el servidor, y los bloqueos por intentos fallidos no se reinician.
- Las cuentas de ejemplo reciben una clave al azar; solo `admin@example.com` conserva `Demo2026*` para el primer
  ingreso. Conviene entrar de una vez, configurar la verificación en dos pasos y cambiar esa clave en Mi perfil.
- Los archivos subidos (adjuntos, comprobantes, imágenes de afiches) siguen guardándose en el servidor y se pierden
  cuando Vercel lo reinicia.

### Un año de actividad para los tableros

`cron/datos_muestra.php` completa la demostración con los últimos doce meses: casos de la mesa con sus tiempos, ANS y
encuestas, facturas con pagos, retenciones y comprobantes aprobados, gastos mensuales (nómina, arriendo, servicios,
honorarios, 4x1000), PQRSD con sus términos, mensajes del sitio, chats y reuniones. Usa las reglas de la plataforma
(partida doble, consecutivos, días hábiles y festivos) y al final numera todo en orden de fecha.

- Corre una sola vez por base: al construir la imagen y, en una base permanente creada con los datos de demostración,
  en segundo plano al primer arranque. Nunca en una base real. `DATOS_MUESTRA=0` lo apaga.
- `cron/datos_muestra_extra.php` completa cuentas por pagar con sus pagos, notas contables (depreciación), notas
  crédito y débito, y pedidos de la tienda con su factura y pago; en una base permanente, además, la conciliación
  bancaria de cada mes y el cierre contable de los meses terminados (con el cierre del año anterior).
- `cron/actividad_diaria.php` crea cada día la actividad de hoy (casos nuevos en distintos estados, mensajes, chats y a
  veces una PQRSD) y atiende los casos de ejemplo que llevan días abiertos.
- En la base de demostración, el primer ingreso de un administrador con correo propio prepara su cuenta: queda como
  único administrador (se borran los de ejemplo) y recibe el historial de un cliente de ejemplo.
- Todo es ficticio (correos `@example.com`, teléfonos 307 555). Los pedidos de muestra no cambian los "vendidos" ni el
  inventario que ve el público, y no se crean reseñas: la tienda es pública.

## Pagos con Mercado Pago

Con `PAGOS_ENTORNO=PRODUCCION` y `MP_ACCESS_TOKEN` el botón de pago de "Mis facturas" lleva a Mercado Pago (Checkout
Pro) y el aviso de pago (`admin/webhook_pagos.php`) registra el recaudo y su comprobante de ingreso aprobado.

- Con las credenciales de prueba de la aplicación (en el panel nuevo de Mercado Pago también empiezan por `APP_USR-`;
  en el anterior, por `TEST-`) se paga con las tarjetas de prueba (titular `APRO` para aprobar), mejor en una ventana
  de incógnito; con las credenciales de producción los pagos son reales.
- El aviso se confirma siempre consultando el pago en Mercado Pago y cada pago se contabiliza una sola vez.
- Si Mercado Pago rechaza la solicitud, el motivo queda en el registro del servidor y el cliente ve un mensaje amable.

## Datos y licencias

- La base de datos real no está en el repositorio. `deploy/demo/datasense_demo.sql` tiene solo datos ficticios.
- Las fotos de la tienda son de dominio público (CC0); la fuente de cada una está en `img/productos/CREDITOS_FOTOS.txt`.
