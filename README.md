# Musiteca

Sistema web para la gestión del inventario de instrumentos musicales, control de préstamos, incidencias y administración operativa.

## Descripción

Musiteca es una aplicación desarrollada para facilitar la administración del inventario de instrumentos musicales mediante una plataforma web que permite registrar instrumentos, controlar préstamos y devoluciones, gestionar incidencias, generar reportes y brindar una aplicación operativa orientada al personal encargado de las actividades diarias.

El proyecto fue desarrollado utilizando únicamente PHP, MySQL, JavaScript Vanilla y Bootstrap 5, manteniendo una arquitectura sencilla, modular y fácil de mantener.

---

# Tecnologías utilizadas

* PHP 8+
* MySQL
* Bootstrap 5
* Bootstrap Icons
* JavaScript Vanilla
* HTML5
* CSS3
* PDO para acceso a base de datos

---

# Características principales

* Inicio de sesión con autenticación.
* Dashboard con indicadores principales.
* Gestión completa de instrumentos.
* Registro y edición de información.
* Control de préstamos y devoluciones.
* Gestión de incidencias.
* Historial de movimientos por instrumento.
* Búsqueda y filtrado de información.
* Exportación de reportes.
* Aplicación operativa optimizada para dispositivos móviles.
* Módulo de soporte técnico con carga de evidencias.

---

# Estructura del proyecto

```text
config/
includes/
assets/
api/
app/
database/
scripts/          <- utilidades de línea de comandos (crear_admin.php,
                     pruebas/ para las pruebas de integración)
uploads/
login.php
logout.php
index.php
soporte.php
```

---

# Pruebas

La lógica de negocio más sensible (préstamos y devoluciones, con sus
transacciones y bloqueos de fila) vive en `includes/servicios/` en vez de
directamente en los controladores de `api/`, siguiendo el mismo patrón que
`usuarios_servicio.php`. Esto permite probarla de forma aislada, sin pasar
por una petición HTTP real.

Para correr las pruebas de préstamos/devoluciones:

```
php scripts/pruebas/test_prestamos_servicio.php
```

El script crea (o recrea) una base de datos de pruebas separada —
`musiteca_test` por defecto— y nunca toca la base `musiteca` real: se niega
a ejecutarse si detecta que el nombre resuelto es `musiteca`. Usa las
variables de entorno `MUSITECA_TEST_DB_HOST` / `MUSITECA_TEST_DB_NAME` /
`MUSITECA_TEST_DB_USER` / `MUSITECA_TEST_DB_PASS` para apuntar a otra base
si lo necesitas.

---

# Instalación

1. Crear la base de datos ejecutando `database/schema.sql` (ya incluye
   índices, baja lógica y control de préstamos vencidos; no es necesario
   ejecutar ninguna migración adicional en una instalación nueva).
2. Configurar la conexión mediante variables de entorno del servidor
   (recomendado): `MUSITECA_DB_HOST`, `MUSITECA_DB_NAME`,
   `MUSITECA_DB_USER`, `MUSITECA_DB_PASS`. Si no se definen, `config/database.php`
   usa valores de desarrollo local (`localhost` / `root` / sin contraseña).
3. Crear el primer usuario administrador (el esquema ya NO trae usuarios
   ni contraseñas de prueba):
   ```
   php scripts/crear_admin.php
   ```
4. Publicar el proyecto en un servidor Apache con `mod_rewrite` y
   `mod_headers` habilitados, y verificar que se respeten los archivos
   `.htaccess` incluidos (bloquean el acceso directo a `config/`,
   `includes/`, `database/` y `scripts/`).
5. Servir el sitio siempre por HTTPS en producción; al hacerlo, habilita
   la línea `Strict-Transport-Security` comentada en el `.htaccess` raíz.

---

# Arquitectura

El proyecto está organizado en módulos independientes para facilitar su mantenimiento:

* **config/** Configuración general y conexión a la base de datos.
* **includes/** Componentes reutilizables y autenticación.
* **api/** Endpoints para comunicación mediante AJAX.
* **assets/** Recursos CSS, JavaScript e imágenes.
* **app/** Aplicación operativa para dispositivos móviles.
* **database/** Scripts de creación y actualización de la base de datos.

---

# Objetivos del proyecto

* Facilitar la administración del inventario.
* Centralizar la información de préstamos e incidencias.
* Mejorar la trazabilidad de los instrumentos.
* Optimizar los procesos administrativos.
* Proporcionar una interfaz clara, intuitiva y fácil de utilizar.
* Mantener una arquitectura modular que facilite futuras mejoras.

---

# Seguridad de producción

* Sin credenciales de prueba embebidas: el primer administrador se crea con
  `php scripts/crear_admin.php`.
* `.htaccess` en la raíz y en `config/`, `includes/`, `database/` y
  `scripts/` bloquean el acceso directo por HTTP a esos recursos y agregan
  cabeceras de seguridad (`X-Content-Type-Options`, `X-Frame-Options`,
  `Referrer-Policy`, `Permissions-Policy`).
* Baja lógica de instrumentos: "Eliminar" ya no borra el registro físico,
  lo marca como `activo = 0` / `estado = 'baja'`, preservando su historial
  de préstamos e incidencias para auditoría.
* Control de préstamos vencidos: cada solicitud registra una fecha de
  devolución esperada; el módulo de Control la usa para marcar y filtrar
  préstamos "Vencidos" automáticamente.

# Próximas mejoras

El proyecto está diseñado para seguir evolucionando. Entre las mejoras previstas se encuentran:

* Avisos automáticos por correo cuando un préstamo se vence: el código base
  ya existe (`includes/correo_smtp.php`, `includes/servicios/notificaciones_servicio.php`,
  `scripts/enviar_avisos_vencidos.php`), pero se deja sin activar en esta
  entrega porque requiere que alguien administre credenciales SMTP y una
  tarea programada (cron) fuera del alcance del proyecto. Queda listo para
  que, si en el futuro cuentan con correo institucional y una persona que
  dé mantenimiento al servidor, se active sin tocar el resto del sistema.
  Mientras tanto, los préstamos vencidos ya son visibles de inmediato desde
  el Dashboard y el filtro "Vencidos" del módulo de Control.
* Optimización adicional de la experiencia de usuario y accesibilidad.
* Nuevas funcionalidades administrativas.
* Documentación formal de la API interna.
* Recuperación de contraseña autoservicio.

---

# Licencia

Proyecto desarrollado con fines académicos.
