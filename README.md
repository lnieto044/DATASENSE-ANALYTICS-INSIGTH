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

Después de la clave se pide la verificación en dos pasos. Como estas cuentas son compartidas, en la demo la
pantalla muestra el código del momento; una cuenta creada con "Crear cuenta" la configura con su propia app
autenticadora.

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

## Datos y licencias

- La base de datos real no está en el repositorio. `deploy/demo/datasense_demo.sql` tiene solo datos ficticios.
- Las fotos de la tienda son de dominio público (CC0); la fuente de cada una está en `img/productos/CREDITOS_FOTOS.txt`.
