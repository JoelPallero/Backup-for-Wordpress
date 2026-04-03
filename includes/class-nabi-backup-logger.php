<?php
defined('ABSPATH') || exit;

class NABI_BACKUP_Logger {

    private static $log_file = null;
    private static $log_dir = null;

    /**
     * Inicializa el sistema de logging
     */
    public static function init() {
        self::$log_dir = ABSPATH . 'wp-content/Nabibck/logs';
        
        // Crear directorio si no existe
        if (!file_exists(self::$log_dir)) {
            $created = @wp_mkdir_p(self::$log_dir);
            if (!$created) {
                // Si no se puede crear, intentar usar el directorio de uploads como fallback
                $upload_dir = wp_upload_dir();
                self::$log_dir = $upload_dir['basedir'] . '/Nabi-backup-logs';
                @wp_mkdir_p(self::$log_dir);
            }
        }

        // Verificar permisos
        if (!is_writable(self::$log_dir)) {
            // Intentar cambiar permisos
            @chmod(self::$log_dir, 0755);
        }

        // Crear archivo de log con fecha
        $date = date('Y-m-d');
        self::$log_file = self::$log_dir . '/Nabi-backup-' . $date . '.log';
        
        // Crear archivo si no existe y verificar que sea escribible
        if (!file_exists(self::$log_file)) {
            @touch(self::$log_file);
            @chmod(self::$log_file, 0644);
        }
    }

    /**
     * Escribe un mensaje en el log
     */
    public static function log($message, $type = 'INFO') {
        if (!self::$log_file) {
            self::init();
        }

        // Verificar que el directorio existe y es escribible
        if (!is_dir(self::$log_dir) || !is_writable(self::$log_dir)) {
            // Intentar crear el directorio si no existe
            if (!is_dir(self::$log_dir)) {
                @wp_mkdir_p(self::$log_dir);
            }
            
            // Si aún no se puede escribir, usar el log de WordPress como fallback
            if (!is_writable(self::$log_dir)) {
                error_log('Nabi Backup [' . strtoupper($type) . ']: ' . $message);
                return;
            }
        }

        $timestamp = date('Y-m-d H:i:s');
        $log_entry = sprintf(
            "[%s] [%s] %s\n",
            $timestamp,
            strtoupper($type),
            $message
        );

        // Escribir en el archivo con manejo de errores
        $result = @file_put_contents(self::$log_file, $log_entry, FILE_APPEND | LOCK_EX);
        
        // Si falla la escritura, intentar escribir en el log de WordPress
        if ($result === false) {
            $error = error_get_last();
            error_log('Nabi Backup: Error al escribir en log - ' . ($error ? $error['message'] : 'Error desconocido'));
            error_log('Nabi Backup [' . strtoupper($type) . ']: ' . $message);
        }

        // También escribir en el log de WordPress si WP_DEBUG está activo
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Nabi Backup [' . strtoupper($type) . ']: ' . $message);
        }
    }

    /**
     * Log de información
     */
    public static function info($message) {
        self::log($message, 'INFO');
    }

    /**
     * Log de error
     */
    public static function error($message) {
        self::log($message, 'ERROR');
    }

    /**
     * Log de advertencia
     */
    public static function warning($message) {
        self::log($message, 'WARNING');
    }

    /**
     * Log de debug
     */
    public static function debug($message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            self::log($message, 'DEBUG');
        }
    }

    /**
     * Obtiene las últimas líneas del log
     */
    public static function get_recent_logs($lines = 50) {
        if (!self::$log_file || !file_exists(self::$log_file)) {
            return [];
        }

        $file = file(self::$log_file);
        return array_slice($file, -$lines);
    }

    /**
     * Limpia logs antiguos (más de 30 días)
     */
    public static function clean_old_logs($days = 30) {
        if (!self::$log_dir || !is_dir(self::$log_dir)) {
            return;
        }

        $files = glob(self::$log_dir . '/Nabi-backup-*.log');
        $cutoff_time = time() - ($days * DAY_IN_SECONDS);

        foreach ($files as $file) {
            if (filemtime($file) < $cutoff_time) {
                @unlink($file);
            }
        }
    }

    /**
     * Obtiene la ruta del archivo de log actual
     */
    public static function get_log_file() {
        if (!self::$log_file) {
            self::init();
        }
        return self::$log_file;
    }
}


