<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package NABI_BACKUP
 */

// Si no se llama desde WordPress, salir
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Verificar permisos
if (!current_user_can('activate_plugins')) {
    return;
}

/**
 * Elimina todas las copias de seguridad y archivos relacionados
 */
function NABI_BACKUP_delete_backup_files() {
    $backup_dir = ABSPATH . 'wp-content/Nabibck';
    
    if (is_dir($backup_dir)) {
        // Eliminar todo el contenido del directorio
        NABI_BACKUP_delete_directory($backup_dir);
    }
}

/**
 * Elimina un directorio y todo su contenido recursivamente
 */
function NABI_BACKUP_delete_directory($dir) {
    if (!is_dir($dir)) {
        return;
    }
    
    $files = array_diff(scandir($dir), ['.', '..']);
    
    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        
        if (is_dir($path)) {
            NABI_BACKUP_delete_directory($path);
        } else {
            @unlink($path);
        }
    }
    
    @rmdir($dir);
}

/**
 * Elimina todas las opciones/configuraciones de la base de datos
 */
function NABI_BACKUP_delete_database_options() {
    global $wpdb;
    
    // Eliminar opciones que puedan estar relacionadas con el plugin
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'NABI_BACKUP_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_NABI_BACKUP_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_NABI_BACKUP_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_site_transient_NABI_BACKUP_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_site_transient_timeout_NABI_BACKUP_%'");
    
    // Limpiar meta de usuarios si hay alguna
    $wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'NABI_BACKUP_%'");
    
    // Limpiar meta de posts si hay alguna
    $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE 'NABI_BACKUP_%'");
    
    // Limpiar comentarios meta si hay alguna
    $wpdb->query("DELETE FROM {$wpdb->commentmeta} WHERE meta_key LIKE 'NABI_BACKUP_%'");
}

// Ejecutar limpieza
NABI_BACKUP_delete_backup_files();
NABI_BACKUP_delete_database_options();


