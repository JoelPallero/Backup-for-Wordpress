<?php
/**
 * Plugin Name: Nabi Backup for WordPress
 * Description: Sistema completo de backup e importación para WordPress. Incluye backup de wp-content y base de datos con compresión inteligente.
<<<<<<< HEAD
 * Version: 1.0.0
=======
 * Version: 1.0.1
>>>>>>> 642dd96 (Standardization of Nabi ecosystem and slug refactoring v1.0.1)
 * Author: Joel Pallero
 * Author URI: https://joelpallero.com.ar
 * Plugin URI: https://joelpallero.com.ar/store/nabi-backup
 * Text Domain: Nabi-backup
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Plugin Icon: assets/icons/icon.svg
 */

defined('ABSPATH') || exit;

// Definiciones globales
<<<<<<< HEAD
define('NABI_BACKUP_VERSION', '1.0.0');
=======
define('NABI_BACKUP_VERSION', '1.0.1');
>>>>>>> 642dd96 (Standardization of Nabi ecosystem and slug refactoring v1.0.1)
define('NABI_BACKUP_PATH', plugin_dir_path(__FILE__));
define('NABI_BACKUP_URL', plugin_dir_url(__FILE__));
define('NABI_BACKUP_SIGNATURE', 'NABI_BACKUP_V1.0.0'); // Firma para validar archivos

<<<<<<< HEAD
// Autocarga de clases con verificación de existencia
$required_files = [
    'includes/nabi-master/class-nabi-master.php',
    'includes/class-Nabi-backup-logger.php',
    'includes/class-Nabi-backup-license.php',
    'includes/class-Nabi-backup-settings.php',
    'includes/class-Nabi-backup-loader.php',
    'includes/class-Nabi-backup-export.php',
    'includes/class-Nabi-backup-import.php',
    'includes/class-Nabi-backup-ajax.php',
    'includes/class-Nabi-backup-scheduler.php',
    'admin/class-Nabi-backup-admin.php'
];

foreach ($required_files as $file) {
    $file_path = NABI_BACKUP_PATH . $file;
    if (file_exists($file_path)) {
        require_once $file_path;
    } else {
        // Si estamos en modo debug, mostrar error
        if (defined('WP_DEBUG') && WP_DEBUG) {
            wp_die(sprintf(__('Error: No se pudo cargar el archivo requerido: %s', 'Nabi-backup'), $file));
        }
    }
}

=======
// Autocarga de clases
if (!class_exists('NABI_Master')) {
    require_once NABI_BACKUP_PATH . 'includes/nabi-master/class-nabi-master.php';
}

require_once NABI_BACKUP_PATH . 'includes/class-nabi-backup-logger.php';
require_once NABI_BACKUP_PATH . 'includes/class-nabi-backup-loader.php';
require_once NABI_BACKUP_PATH . 'includes/class-nabi-backup-ajax.php';
require_once NABI_BACKUP_PATH . 'includes/class-nabi-backup-export.php';
require_once NABI_BACKUP_PATH . 'includes/class-nabi-backup-import.php';
require_once NABI_BACKUP_PATH . 'includes/class-nabi-backup-scheduler.php';
require_once NABI_BACKUP_PATH . 'includes/class-nabi-backup-settings.php';
require_once NABI_BACKUP_PATH . 'includes/class-nabi-backup-license.php';
require_once NABI_BACKUP_PATH . 'admin/class-nabi-backup-admin.php';

>>>>>>> 642dd96 (Standardization of Nabi ecosystem and slug refactoring v1.0.1)
// Hooks de inicialización
add_action('plugins_loaded', function() {
    if (class_exists('NABI_Master')) {
        NABI_Master::init();
    }

<<<<<<< HEAD
    // Inicializar logger después de que WordPress esté cargado
=======
>>>>>>> 642dd96 (Standardization of Nabi ecosystem and slug refactoring v1.0.1)
    if (class_exists('NABI_BACKUP_Logger')) {
        NABI_BACKUP_Logger::init();
    }
    
<<<<<<< HEAD
    // Inicializar loader
    if (class_exists('NABI_BACKUP_Loader')) {
        NABI_BACKUP_Loader::init();
    }
    
    // Inicializar scheduler de copias automáticas
    if (class_exists('NABI_BACKUP_Scheduler')) {
        NABI_BACKUP_Scheduler::init();
    }
});

// Cargar textdomain
add_action('plugins_loaded', 'NABI_BACKUP_load_textdomain');
if (!function_exists('NABI_BACKUP_load_textdomain')) {
    function NABI_BACKUP_load_textdomain() {
        load_plugin_textdomain('Nabi-backup', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }
}


=======
    if (class_exists('NABI_BACKUP_Loader')) {
        NABI_BACKUP_Loader::init();
    }
});

// Cargar textdomain
add_action('plugins_loaded', 'NABI_backup_load_textdomain');
function NABI_backup_load_textdomain() {
    load_plugin_textdomain('Nabi-backup', false, dirname(plugin_basename(__FILE__)) . '/languages');
}
>>>>>>> 642dd96 (Standardization of Nabi ecosystem and slug refactoring v1.0.1)
