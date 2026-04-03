# Nabi Backup for WordPress

Sistema completo de backup e importación para WordPress. Diseñado para ser ligero, robusto y sin limitaciones arbitrarias.

## 🚀 Características Principales

*   **Backup Completo**: Copia de seguridad de la base de datos y de todo el contenido de `wp-content` (plugins, temas, uploads).
*   **Sin Límites**: Los backups son ilimitados, restringidos únicamente por el espacio disponible en tu hosting.
*   **Verificación de Espacio**: El plugin estima el tamaño del backup antes de iniciar y verifica si hay suficiente espacio en disco, evitando fallos a mitad del proceso.
*   **Importación Persistente**: Los archivos importados se validan, se guardan permanentemente en el servidor y aparecen automáticamente en el listado de restauración.
*   **Progreso en Tiempo Real**: Visualización precisa del avance del backup y la restauración mediante lectura directa de logs.
*   **Seguridad**: Los archivos de backup incluyen una firma digital (`NABI_BACKUP_SIGNATURE`) para verificar su integridad antes de cualquier restauración.
*   **Sin Conflictos**: Diseñado con prefijos únicos y namespaces propios para evitar interferencias con otros plugins del sitio.

## 📋 Requisitos del Sistema

*   **WordPress**: Versión 6.0 o superior.
*   **PHP**: Versión 7.0 o superior.
*   **Extensiones PHP**: `ZipArchive`, `mysqli`, `json`.
*   **Permisos de Escritura**: El plugin requiere permisos para crear la carpeta `wp-content/Nabibck`.

## 🛠️ Instalación

1.  Sube la carpeta `backup-for-wp` al directorio `/wp-content/plugins/`.
2.  Activa el plugin a través del menú 'Plugins' en WordPress.
3.  Accede a **Nabi > Backup** en el panel lateral.

## 📖 Uso General

### Exportar Backup
Ve a la pestaña **Exportar** y haz clic en "Iniciar Exportación". El plugin realizará un análisis previo del espacio disponible y, si es suficiente, empaquetará tu sitio en un archivo ZIP descargable.

### Importar desde Archivo
En la pestaña **Importar**, puedes arrastrar y soltar un archivo de backup previamente creado. Una vez validado, el archivo se guardará en el servidor y podrás elegir "Iniciar Importación" para restaurar el sitio.

### Restaurar Backup Guardado
La pestaña **Restaurar** lista todos los backups disponibles en tu servidor (tanto exportados localmente como importados). Desde aquí puedes descargar una copia a tu PC, eliminar archivos antiguos o iniciar una restauración completa.

### Configuración
En la pestaña **Configuración**, puedes personalizar qué elementos deseas incluir en tus copias de seguridad por defecto (Base de datos, Media, Plugins, Temas, etc.).

## 🔒 Seguridad y Almacenamiento

*   **Ubicación**: Todos los archivos se almacenan en `wp-content/Nabibck/`.
*   **Protección**: La carpeta está protegida por un archivo `index.php` para evitar el listado de directorios.
*   **Descargas**: El sistema de descarga utiliza nonces de seguridad de WordPress para asegurar que solo usuarios autorizados puedan descargar los archivos.

---
**Desarrollado por:** [Joel Pallero](https://joelpallero.com.ar)


