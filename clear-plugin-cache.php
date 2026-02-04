<?php
/**
 * Script temporal para limpiar el cache del plugin después de cambiar el nombre de la carpeta
 * 
 * INSTRUCCIONES:
 * 1. Sube este archivo a la raíz de tu instalación de WordPress (mismo nivel que wp-config.php)
 * 2. Accede a: http://tudominio.com/clear-plugin-cache.php
 * 3. Elimina este archivo después de usarlo
 */

// Cargar WordPress
require_once('wp-load.php');

if (!current_user_can('manage_options')) {
    die('No tienes permisos para ejecutar este script');
}

echo '<h1>Limpieza de Cache del Plugin DN325 Backup</h1>';

// Limpiar opciones relacionadas
$options_to_delete = [
    'active_plugins',
    'dn325_backup_*',
];

// Limpiar transients
global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_dn325_%'");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_dn325_%'");

echo '<p>✅ Transients eliminados</p>';

// Limpiar cache de plugins activos
$active_plugins = get_option('active_plugins', []);
$plugin_file = 'backup_for_wp/dn325-backup-for-wordpress.php';

// Si el plugin está en la lista con otro nombre, eliminarlo
$updated = false;
foreach ($active_plugins as $key => $plugin) {
    if (strpos($plugin, 'dn325-backup') !== false && $plugin !== $plugin_file) {
        unset($active_plugins[$key]);
        $updated = true;
    }
}

if ($updated) {
    update_option('active_plugins', array_values($active_plugins));
    echo '<p>✅ Lista de plugins activos actualizada</p>';
}

// Limpiar rewrite rules
flush_rewrite_rules();
echo '<p>✅ Reglas de rewrite limpiadas</p>';

echo '<h2>✅ Limpieza completada</h2>';
echo '<p>Ahora intenta activar el plugin desde el panel de administración.</p>';
echo '<p><strong>IMPORTANTE:</strong> Elimina este archivo después de usarlo por seguridad.</p>';
