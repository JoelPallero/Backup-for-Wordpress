<?php
/**
 * Plugin Name: DN325 Backup for WordPress
 * Description: Sistema completo de backup e importación para WordPress. Incluye backup de wp-content y base de datos.
 * Version: 1.0.0
 * Author: Joel Pallero
 * Author URI: https://joelpallero.com.ar
 * Plugin URI: https://joelpallero.com.ar/productos
 * Text Domain: dn325-backup
 * Requires at least: 6.9
 * Requires PHP: 7.6
 * Plugin Icon: assets/icons/icon.svg
 */

defined('ABSPATH') || exit;

// Definiciones globales
define('DN325_BACKUP_VERSION', '1.0.0');
define('DN325_BACKUP_PATH', plugin_dir_path(__FILE__));
define('DN325_BACKUP_URL', plugin_dir_url(__FILE__));
define('DN325_BACKUP_SIGNATURE', 'DN325_BACKUP_V1.0.0'); // Firma para validar archivos

// Autocarga de clases con verificación de existencia
$required_files = [
    'includes/class-dn325-menu.php',
    'includes/class-dn325-backup-logger.php',
    'includes/class-dn325-backup-license.php',
    'includes/class-dn325-backup-settings.php',
    'includes/class-dn325-backup-loader.php',
    'includes/class-dn325-backup-export.php',
    'includes/class-dn325-backup-import.php',
    'includes/class-dn325-backup-ajax.php',
    'includes/class-dn325-backup-scheduler.php',
    'admin/class-dn325-backup-admin.php'
];

foreach ($required_files as $file) {
    $file_path = DN325_BACKUP_PATH . $file;
    if (file_exists($file_path)) {
        require_once $file_path;
    } else {
        // Si estamos en modo debug, mostrar error
        if (defined('WP_DEBUG') && WP_DEBUG) {
            wp_die(sprintf(__('Error: No se pudo cargar el archivo requerido: %s', 'dn325-backup'), $file));
        }
    }
}

// Hooks de inicialización
add_action('plugins_loaded', function() {
    // Inicializar logger después de que WordPress esté cargado
    if (class_exists('DN325_Backup_Logger')) {
        DN325_Backup_Logger::init();
    }
    
    // Inicializar loader
    if (class_exists('DN325_Backup_Loader')) {
        DN325_Backup_Loader::init();
    }
    
    // Inicializar scheduler de copias automáticas
    if (class_exists('DN325_Backup_Scheduler')) {
        DN325_Backup_Scheduler::init();
    }
});

// Cargar textdomain
add_action('plugins_loaded', 'dn325_backup_load_textdomain');
if (!function_exists('dn325_backup_load_textdomain')) {
    function dn325_backup_load_textdomain() {
        load_plugin_textdomain('dn325-backup', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }
}
