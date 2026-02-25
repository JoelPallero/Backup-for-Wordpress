<?php
defined('ABSPATH') || exit;

class DN325_Backup_Loader {

    public static function init() {
        if (is_admin()) {
            self::load_admin();
        }
    }

    private static function load_admin() {
        if (class_exists('DN325_Backup_Admin')) {
            DN325_Backup_Admin::init();
        } else {
            error_log('DN325 Backup: Clase DN325_Backup_Admin no encontrada');
        }
        
        if (class_exists('DN325_Backup_Ajax')) {
            DN325_Backup_Ajax::init();
            error_log('DN325 Backup: AJAX handlers registrados');
        } else {
            error_log('DN325 Backup: Clase DN325_Backup_Ajax no encontrada');
        }
    }
}
