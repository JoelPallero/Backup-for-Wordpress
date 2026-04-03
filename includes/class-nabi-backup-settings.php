<?php
defined('ABSPATH') || exit;

/**
 * Clase para gestionar las configuraciones del plugin Backup
 */
class NABI_BACKUP_Settings {

    /**
     * Obtiene todas las configuraciones
     */
    public static function get_settings() {
        return [
            'include_media' => get_option('NABI_BACKUP_include_media', true),
            'include_uploads' => get_option('NABI_BACKUP_include_uploads', true),
            'include_plugins' => get_option('NABI_BACKUP_include_plugins', true),
            'include_themes' => get_option('NABI_BACKUP_include_themes', true),
            'include_posts' => get_option('NABI_BACKUP_include_posts', true),
            'include_pages' => get_option('NABI_BACKUP_include_pages', true),
            'include_comments' => get_option('NABI_BACKUP_include_comments', true),
            'include_users' => get_option('NABI_BACKUP_include_users', true),
            'include_database' => get_option('NABI_BACKUP_include_database', true),
            'exclude_other_backups' => get_option('NABI_BACKUP_exclude_other_backups', true),
        ];
    }

    /**
     * Guarda las configuraciones
     */
    public static function save_settings($settings) {
        update_option('NABI_BACKUP_include_media', isset($settings['include_media']) ? (bool)$settings['include_media'] : true);
        update_option('NABI_BACKUP_include_uploads', isset($settings['include_uploads']) ? (bool)$settings['include_uploads'] : true);
        update_option('NABI_BACKUP_include_plugins', isset($settings['include_plugins']) ? (bool)$settings['include_plugins'] : true);
        update_option('NABI_BACKUP_include_themes', isset($settings['include_themes']) ? (bool)$settings['include_themes'] : true);
        update_option('NABI_BACKUP_include_posts', isset($settings['include_posts']) ? (bool)$settings['include_posts'] : true);
        update_option('NABI_BACKUP_include_pages', isset($settings['include_pages']) ? (bool)$settings['include_pages'] : true);
        update_option('NABI_BACKUP_include_comments', isset($settings['include_comments']) ? (bool)$settings['include_comments'] : true);
        update_option('NABI_BACKUP_include_users', isset($settings['include_users']) ? (bool)$settings['include_users'] : true);
        update_option('NABI_BACKUP_include_database', isset($settings['include_database']) ? (bool)$settings['include_database'] : true);
        update_option('NABI_BACKUP_exclude_other_backups', isset($settings['exclude_other_backups']) ? (bool)$settings['exclude_other_backups'] : true);
        
        return true;
    }

    /**
     * Verifica si se debe incluir un elemento en el backup
     */
    public static function should_include($item) {
        $settings = self::get_settings();
        return isset($settings[$item]) ? $settings[$item] : true;
    }
}


