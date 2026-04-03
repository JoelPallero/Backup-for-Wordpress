<?php
defined('ABSPATH') || exit;

class NABI_BACKUP_Loader {

    public static function init() {
        if (is_admin()) {
            self::load_admin();
        }
    }

    private static function load_admin() {
        if (class_exists('NABI_BACKUP_Admin')) {
            NABI_BACKUP_Admin::init();
        } else {
            error_log('Nabi Backup: Clase NABI_BACKUP_Admin no encontrada');
        }
        
        if (class_exists('NABI_BACKUP_Ajax')) {
            NABI_BACKUP_Ajax::init();
            error_log('Nabi Backup: AJAX handlers registrados');
        } else {
            error_log('Nabi Backup: Clase NABI_BACKUP_Ajax no encontrada');
        }
    }
}


