<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package DN325_Backup
 */

// Si no se llama desde WordPress, salir
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Verificar permisos
if (!current_user_can('activate_plugins')) {
    return;
}

// Verificar que el usuario realmente quiere desinstalar
check_admin_referer('bulk-plugins');

/**
 * Elimina todas las copias de seguridad y archivos relacionados
 */
function dn325_backup_delete_backup_files() {
    $backup_dir = ABSPATH . 'wp-content/dn325bck';
    
    if (is_dir($backup_dir)) {
        // Eliminar todo el contenido del directorio
        dn325_backup_delete_directory($backup_dir);
    }
}

/**
 * Elimina un directorio y todo su contenido recursivamente
 */
function dn325_backup_delete_directory($dir) {
    if (!is_dir($dir)) {
        return;
    }
    
    $files = array_diff(scandir($dir), ['.', '..']);
    
    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        
        if (is_dir($path)) {
            dn325_backup_delete_directory($path);
        } else {
            @unlink($path);
        }
    }
    
    @rmdir($dir);
}

/**
 * Elimina todas las opciones/configuraciones de la base de datos
 */
function dn325_backup_delete_database_options() {
    global $wpdb;
    
    // Eliminar opciones que puedan estar relacionadas con el plugin
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'dn325_backup_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_dn325_backup_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_dn325_backup_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_site_transient_dn325_backup_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_site_transient_timeout_dn325_backup_%'");
    
    // Limpiar meta de usuarios si hay alguna
    $wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'dn325_backup_%'");
    
    // Limpiar meta de posts si hay alguna
    $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE 'dn325_backup_%'");
    
    // Limpiar comentarios meta si hay alguna
    $wpdb->query("DELETE FROM {$wpdb->commentmeta} WHERE meta_key LIKE 'dn325_backup_%'");
}

// Ejecutar limpieza
dn325_backup_delete_backup_files();
dn325_backup_delete_database_options();
