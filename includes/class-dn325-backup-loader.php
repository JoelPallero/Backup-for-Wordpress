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
        }
        if (class_exists('DN325_Backup_Ajax')) {
            DN325_Backup_Ajax::init();
        }
        // Cargar settings
        require_once DN325_BACKUP_PATH . 'includes/class-dn325-backup-settings.php';
    }
}
