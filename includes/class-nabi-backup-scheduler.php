<?php
defined('ABSPATH') || exit;

/**
 * Clase para gestionar copias automáticas programadas (solo Ultra)
 */
class NABI_BACKUP_Scheduler {

    const CRON_HOOK = 'NABI_BACKUP_auto_backup';
    
    /**
     * Inicializa el sistema de copias automáticas
     */
    public static function init() {
        // Registrar hook de cron
        add_action(self::CRON_HOOK, [__CLASS__, 'execute_auto_backup']);
        
        // Programar evento si está habilitado
        self::schedule_backup();
    }
    
    /**
     * Programa el próximo backup automático
     */
    public static function schedule_backup() {
        require_once NABI_BACKUP_PATH . 'includes/class-Nabi-backup-license.php';
        
        // Solo para versión Ultra
        if (!NABI_BACKUP_License::has_auto_backups()) {
            self::unschedule_backup();
            return;
        }
        
        $config = NABI_BACKUP_License::get_auto_backup_config();
        
        if (!$config || !$config['enabled']) {
            self::unschedule_backup();
            return;
        }
        
        // Eliminar evento existente si hay
        self::unschedule_backup();
        
        // Calcular próxima ejecución
        $next_run = self::calculate_next_run($config['frequency'], $config['time']);
        
        // Programar evento
        wp_schedule_event($next_run, self::get_cron_schedule($config['frequency']), self::CRON_HOOK);
        
        NABI_BACKUP_Logger::info('Backup automático programado para: ' . date('Y-m-d H:i:s', $next_run));
    }
    
    /**
     * Desprograma el backup automático
     */
    public static function unschedule_backup() {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
    }
    
    /**
     * Ejecuta el backup automático
     */
    public static function execute_auto_backup() {
        require_once NABI_BACKUP_PATH . 'includes/class-Nabi-backup-license.php';
        
        // Verificar que sigue siendo Ultra
        if (!NABI_BACKUP_License::has_auto_backups()) {
            self::unschedule_backup();
            return;
        }
        
        NABI_BACKUP_Logger::info('Iniciando backup automático programado');
        
        try {
            require_once NABI_BACKUP_PATH . 'includes/class-Nabi-backup-export.php';
            
            $export = new NABI_BACKUP_Export();
            $result = $export->create_backup();
            
            if ($result['success']) {
                NABI_BACKUP_Logger::info('Backup automático completado exitosamente: ' . $result['filename']);
                
                // Enviar email de notificación
                self::send_backup_notification_email($result);
            } else {
                NABI_BACKUP_Logger::error('Error en backup automático: ' . ($result['error'] ?? 'Error desconocido'));
            }
        } catch (Exception $e) {
            NABI_BACKUP_Logger::error('Excepción en backup automático: ' . $e->getMessage());
        } catch (Error $e) {
            NABI_BACKUP_Logger::error('Error fatal en backup automático: ' . $e->getMessage());
        }
    }
    
    /**
     * Envía email de notificación cuando se completa un backup automático
     */
    private static function send_backup_notification_email($backup_result) {
        $admin_email = get_option('admin_email');
        $site_name = get_option('blogname');
        $site_url = get_site_url();
        
        $subject = sprintf(
            __('[%s] Backup automático completado', 'Nabi-backup'),
            $site_name
        );
        
        $message = sprintf(
            __("Hola,\n\nSe ha completado exitosamente el backup automático de tu sitio WordPress.\n\nDetalles del backup:\n- Archivo: %s\n- Fecha: %s\n- Tamaño: %s\n\nPuedes descargar el backup desde el panel de administración de WordPress.\n\nSaludos,\nSistema de Backups Nabi", 'Nabi-backup'),
            $backup_result['filename'],
            date('Y-m-d H:i:s'),
            size_format(filesize($backup_result['file']))
        );
        
        $headers = [
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . $site_name . ' <' . $admin_email . '>'
        ];
        
        $sent = wp_mail($admin_email, $subject, $message, $headers);
        
        if ($sent) {
            NABI_BACKUP_Logger::info('Email de notificación de backup enviado a: ' . $admin_email);
        } else {
            NABI_BACKUP_Logger::warning('No se pudo enviar el email de notificación de backup');
        }
    }
    
    /**
     * Calcula el próximo tiempo de ejecución
     */
    private static function calculate_next_run($frequency, $time) {
        $current_time = current_time('timestamp');
        $time_parts = explode(':', $time);
        $hour = (int) $time_parts[0];
        $minute = isset($time_parts[1]) ? (int) $time_parts[1] : 0;
        
        switch ($frequency) {
            case 'daily':
                // Próxima ejecución hoy o mañana a la hora especificada
                $next_run = mktime($hour, $minute, 0, date('n', $current_time), date('j', $current_time), date('Y', $current_time));
                if ($next_run <= $current_time) {
                    $next_run = strtotime('+1 day', $next_run);
                }
                break;
                
            case 'weekly':
                // Próxima ejecución el próximo día de la semana a la hora especificada
                $next_run = strtotime('next monday', $current_time);
                $next_run = mktime($hour, $minute, 0, date('n', $next_run), date('j', $next_run), date('Y', $next_run));
                break;
                
            case 'monthly':
                // Próxima ejecución el primer día del próximo mes a la hora especificada
                $next_run = mktime($hour, $minute, 0, date('n', $current_time) + 1, 1, date('Y', $current_time));
                break;
                
            default:
                $next_run = strtotime('+1 day', $current_time);
        }
        
        return $next_run;
    }
    
    /**
     * Obtiene el schedule de cron según la frecuencia
     */
    private static function get_cron_schedule($frequency) {
        switch ($frequency) {
            case 'daily':
                return 'daily';
            case 'weekly':
                return 'weekly';
            case 'monthly':
                // WordPress no tiene 'monthly' por defecto, usamos 'daily' y lo manejamos manualmente
                return 'daily';
            default:
                return 'daily';
        }
    }
    
    /**
     * Obtiene el estado del backup automático
     */
    public static function get_status() {
        require_once NABI_BACKUP_PATH . 'includes/class-Nabi-backup-license.php';
        
        if (!NABI_BACKUP_License::has_auto_backups()) {
            return [
                'enabled' => false,
                'message' => __('Copias automáticas solo disponibles en versión Ultra', 'Nabi-backup')
            ];
        }
        
        $config = NABI_BACKUP_License::get_auto_backup_config();
        $next_run = wp_next_scheduled(self::CRON_HOOK);
        
        return [
            'enabled' => $config && $config['enabled'],
            'frequency' => $config ? $config['frequency'] : 'daily',
            'time' => $config ? $config['time'] : '02:00',
            'next_run' => $next_run ? date('Y-m-d H:i:s', $next_run) : null,
            'next_run_timestamp' => $next_run
        ];
    }
}


