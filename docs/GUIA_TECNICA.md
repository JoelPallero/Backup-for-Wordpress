# Guía Técnica - Nabi Backup for WordPress

## 🛡️ Estructura del Plugin

El plugin sigue una arquitectura modular y funcional, dividiendo las tareas en clases especializadas para cada parte del proceso de backup y restauración.

### Clases Core (`includes/`):

1.  **`NABI_BACKUP_Export`**:
    *   Gestiona la creación de backups completos.
    *   **Logic**: DB Dump -> Zip wp-content -> Final Zip en `Nabibck`.
    *   **Space Checking**: Antes de iniciar, calcula el peso de los componentes y verifica si hay espacio libre suficiente (Margen x2.5).

2.  **`NABI_BACKUP_Import`**:
    *   Gestiona la extracción y movido definitivo de backups subidos desde archivo.
    *   Se encarga de renombrar los archivos importados a un formato reconocible (`Nabi-backup-imported-*.zip`).

3.  **`NABI_BACKUP_AJAX`**:
    *   Centraliza los endpoints para todas las interacciones del frontend (exportar, importar, listar, borrar).
    *   Permite obtener actualizaciones de progreso durante procesos largos.

4.  **`NABI_BACKUP_License`**:
    *   Configura los límites de backups. Se configuró para permitir backups ILIMITADOS (`MAX_BACKUPS = -1`).
    *   Maneja la conexión a cuentas Pro/Ultra si el entorno lo permite.

5.  **`NABI_BACKUP_Logger`**:
    *   Sistema de logs que permite seguir el estado del plugin y los mensajes de progreso.

### 🔌 Interfaz de Admin (`admin/`):

*   **`NABI_BACKUP_Admin`**: Renderiza la página de configuración principal con sus pestañas de gestión.
*   **Assets**: Estilado premium en `css/admin.css` y lógica de polling de progreso en `js/admin.js`.

---

## 💾 Polling de Progreso Real

Para evitar problemas de timeouts y para que el usuario siempre vea lo que está pasando, se ha implementado un sistema de **polling**:

1.  La acción AJAX principal devuelve un `success: true` inmediatamente después de empezar el proceso de backup o restauración (si es asíncrono) o el frontend lanza la petición y empieza a consultar logs periódicamente.
2.  El JavaScript consulta cada 2 segundos a un endpoint que devuelve el contenido del último log relevante.
3.  Se analiza el contenido del log para extraer porcentajes de completado (Database y Files).

---

## 🔒 Almacenamiento y Seguridad

*   Los backups se guardan en: `wp-content/Nabibck/`
*   Cada backup tiene una firma digital interna `NABI_BACKUP_SIGNATURE` para asegurar que el archivo no ha sido manipulado antes de restaurarlo.
*   Los archivos importados se guardan permanentemente en el servidor para que el usuario pueda restaurarlos varias veces si es necesario.

## 🛠️ Modificaciones de Código para Mantenimiento

Si necesitas modificar la arquitectura o agregar nuevas exclusiones de archivos, revisa el método `estimate_backup_size` en `class-Nabi-backup-export.php`. El filtro por defecto ignora la propia carpeta de backups para evitar que los archivos crezcan exponencialmente.


