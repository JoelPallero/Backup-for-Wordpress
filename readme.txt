=== Nabi Backup for WordPress ===
Contributors: Joel Pallero
Tags: backup, migration, database, import, export, security
Requires at least: 6.0
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sistema completo de backup e importación para WordPress. Incluye backup de wp-content y base de datos con compresión inteligente.

== Description ==

Nabi Backup for WordPress es una herramienta potente diseñada para proteger tus datos. Permite realizar copias de seguridad completas de tu instalación, incluyendo archivos y base de datos, y restaurarlas fácilmente o migrar tu sitio a otro servidor.

**Características principales:**
* Backup completo de archivos (wp-content).
* Backup de base de datos MySQL.
* Programación de copias automáticas.
* Sistema de firma digital para validar archivos de backup.
* Interfaz de administración simplificada.
* Registro detallado (logs) de todas las operaciones.

== Installation ==

1. Sube la carpeta del plugin al directorio `/wp-content/plugins/`.
2. Activa el plugin a través del menú 'Plugins' en WordPress.
3. Ve a 'Backup for WP' en el menú de administración y configura tu primer backup.

== FAQ ==

= ¿Dónde se guardan los backups? =
Los archivos se almacenan en una carpeta segura dentro de `/wp-content/uploads/Nabi-backups/` con acceso restringido.

= ¿Puedo migrar un sitio a otro dominio? =
Sí, el sistema de exportación/importación procesa las rutas de forma relativa para facilitar la migración.

== Screenshots ==

1. Panel de control principal con historial de backups.
2. Configuración de programación automática.

== Changelog ==

= 1.0.0 =
* Versión inicial estable con soporte para archivos, base de datos y programación.


