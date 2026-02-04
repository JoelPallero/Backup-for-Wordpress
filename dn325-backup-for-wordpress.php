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

// Autocarga de clases
require_once DN325_BACKUP_PATH . 'includes/class-dn325-menu.php';
require_once DN325_BACKUP_PATH . 'includes/class-dn325-backup-logger.php';
require_once DN325_BACKUP_PATH . 'includes/class-dn325-backup-license.php';
require_once DN325_BACKUP_PATH . 'includes/class-dn325-backup-loader.php';
require_once DN325_BACKUP_PATH . 'includes/class-dn325-backup-export.php';
require_once DN325_BACKUP_PATH . 'includes/class-dn325-backup-import.php';
require_once DN325_BACKUP_PATH . 'includes/class-dn325-backup-ajax.php';
require_once DN325_BACKUP_PATH . 'includes/class-dn325-backup-scheduler.php';
require_once DN325_BACKUP_PATH . 'admin/class-dn325-backup-admin.php';

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
function dn325_backup_load_textdomain() {
    load_plugin_textdomain('dn325-backup', false, dirname(plugin_basename(__FILE__)) . '/languages');
}
