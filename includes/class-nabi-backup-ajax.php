<?php
defined('ABSPATH') || exit;

class NABI_BACKUP_Ajax
{

    public static function init()
    {
        add_action('wp_ajax_NABI_BACKUP_export', [__CLASS__, 'handle_export']);
        add_action('wp_ajax_NABI_BACKUP_import', [__CLASS__, 'handle_import']);
        add_action('wp_ajax_NABI_BACKUP_validate', [__CLASS__, 'handle_validate']);
        add_action('wp_ajax_NABI_BACKUP_list', [__CLASS__, 'handle_list']);
        add_action('wp_ajax_NABI_BACKUP_get_progress', [__CLASS__, 'handle_get_progress']);
        add_action('wp_ajax_NABI_BACKUP_connect_account', [__CLASS__, 'handle_connect_account']);
        add_action('wp_ajax_NABI_BACKUP_disconnect_account', [__CLASS__, 'handle_disconnect_account']);
        add_action('wp_ajax_NABI_BACKUP_save_auto_config', [__CLASS__, 'handle_save_auto_config']);
        add_action('wp_ajax_NABI_BACKUP_get_account_info', [__CLASS__, 'handle_get_account_info']);
        add_action('wp_ajax_NABI_BACKUP_delete', [__CLASS__, 'handle_delete']);
        add_action('wp_ajax_NABI_BACKUP_save_settings', [__CLASS__, 'handle_save_settings']);
        add_action('wp_ajax_NABI_BACKUP_test_connection', [__CLASS__, 'handle_test_connection']);
    }

    /**
     * Obtiene la lista de backups guardados (solo compatibles con el plugin)
     */
    public static function handle_list()
    {
        self::auto_cleanup();
        check_ajax_referer('NABI_BACKUP_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('No tienes permisos para realizar esta acción', 'Nabi-backup')]);
        }

        require_once NABI_BACKUP_PATH . 'includes/class-Nabi-backup-license.php';
        require_once NABI_BACKUP_PATH . 'includes/class-Nabi-backup-import.php';

        $backup_dir = ABSPATH . 'wp-content/Nabibck';
        $backups = [];

        // Verificar que el directorio existe
        if (!is_dir($backup_dir)) {
            NABI_BACKUP_Logger::debug('Directorio de backups no existe: ' . $backup_dir);
            wp_send_json_success(['backups' => $backups]);
        }

        // Buscar todos los archivos de backup (tanto con token válido como sin validar)
        $all_files = glob($backup_dir . '/Nabi-backup-*.{Nabi,zip}', GLOB_BRACE);

        if (!$all_files) {
            NABI_BACKUP_Logger::debug('No se encontraron archivos de backup en: ' . $backup_dir);
            wp_send_json_success(['backups' => $backups]);
        }

        NABI_BACKUP_Logger::debug('Encontrados ' . count($all_files) . ' archivos de backup para validar');

        foreach ($all_files as $file) {
            try {
                // Validar que el archivo sea compatible con el plugin
                $import = new NABI_BACKUP_Import();
                $validation = $import->validate_backup_file($file);

                // Solo incluir backups válidos y compatibles
                if ($validation['valid'] && isset($validation['info'])) {
                    $filename = basename($file);
                    $filetime = filemtime($file);
                    $filesize = filesize($file);
                    $info = $validation['info'];

                    // Generar URL de descarga sin usar wp_nonce_url para evitar problemas de codificación
                    $nonce = wp_create_nonce('NABI_BACKUP_download_' . $filename);
                    $download_url = admin_url('admin-post.php');
                    $download_url = add_query_arg([
                        'action' => 'NABI_BACKUP_download',
                        'file' => urlencode($filename),
                        '_wpnonce' => $nonce
                    ], $download_url);

                    $backups[] = [
                        'filename' => $filename,
                        'date' => isset($info['date']) ? $info['date'] : date('Y-m-d H:i:s', $filetime),
                        'size' => $filesize,
                        'size_formatted' => size_format($filesize),
                        'wp_version' => isset($info['wp_version']) ? $info['wp_version'] : '',
                        'version' => isset($info['version']) ? $info['version'] : '',
                        'download_url' => $download_url
                    ];

                    NABI_BACKUP_Logger::debug('Backup agregado a la lista: ' . $filename);
                } else {
                    // Log para debugging si la validación falla
                    $error_msg = isset($validation['error']) ? $validation['error'] : 'Desconocido';
                    NABI_BACKUP_Logger::debug('Backup no válido excluido de la lista: ' . basename($file) . ' - Error: ' . $error_msg);
                }
            } catch (Exception $e) {
                // Log error pero continuar con otros backups
                NABI_BACKUP_Logger::error('Error al validar backup ' . basename($file) . ': ' . $e->getMessage());
            } catch (Error $e) {
                // Log error fatal pero continuar
                NABI_BACKUP_Logger::error('Error fatal al validar backup ' . basename($file) . ': ' . $e->getMessage());
            }
        }

        // Ordenar por fecha (más reciente primero)
        usort($backups, function ($a, $b) {
            return strtotime($b['date']) - strtotime($a['date']);
        });

        NABI_BACKUP_Logger::debug('Total de backups válidos encontrados: ' . count($backups));

        wp_send_json_success(['backups' => $backups]);
    }

    /**
     * Obtiene el progreso del backup (lee el log)
     */
    public static function handle_get_progress()
    {
        check_ajax_referer('NABI_BACKUP_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('No tienes permisos para realizar esta acción', 'Nabi-backup')]);
        }

        $log_file = NABI_BACKUP_Logger::get_log_file();
        $progress = [];
        $database_percentage = null;
        $files_percentage = null;
        $current_phase = null; // 'database' o 'files'

        if (file_exists($log_file)) {
            $lines = NABI_BACKUP_Logger::get_recent_logs(200);
            foreach ($lines as $line) {
                // Extraer porcentaje de base de datos
                if (preg_match('/\[INFO\].*?Base de datos:\s*(\d+)%/', $line, $matches)) {
                    $database_percentage = (int) $matches[1];
                    $current_phase = 'database';
                    $progress[] = trim($line);
                }
                // Extraer porcentaje de archivos
                elseif (preg_match('/\[INFO\].*?Archivos:\s*(\d+)%/', $line, $matches)) {
                    $files_percentage = (int) $matches[1];
                    $current_phase = 'files';
                    $progress[] = trim($line);
                }
                // Extraer otras líneas INFO relevantes
                elseif (preg_match('/\[INFO\].*?(Procesando tabla|Exportando|Creando|wp-content|completado|Tabla \d+\/|Lote \d+\/)/', $line)) {
                    $progress[] = trim($line);
                }
                // Extraer errores de espacio en disco
                elseif (preg_match('/\[ERROR\].*?(espacio|space|disk|disco)/i', $line)) {
                    $progress[] = trim($line);
                }
            }
        }

        wp_send_json_success([
            'progress' => array_slice($progress, -20), // Últimas 20 líneas
            'database_percentage' => $database_percentage,
            'files_percentage' => $files_percentage,
            'current_phase' => $current_phase
        ]);
    }

    /**
     * Maneja la exportación de backup
     */
    public static function handle_export()
    {
        // Log inicial para debugging
        error_log('Nabi Backup: handle_export() llamado');
        NABI_BACKUP_Logger::info('Solicitud AJAX de exportación recibida');

        // Verificar que la petición sea AJAX
        if (!wp_doing_ajax()) {
            error_log('Nabi Backup: No es una petición AJAX');
            wp_send_json_error(['message' => __('Esta acción solo puede ejecutarse vía AJAX', 'Nabi-backup')]);
            return;
        }

        // Aumentar límites para procesos largos
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');
        @ini_set('max_execution_time', 0);

        NABI_BACKUP_Logger::debug('Límites configurados - Memory: ' . ini_get('memory_limit') . ', Max Execution Time: ' . ini_get('max_execution_time'));

        // Verificar nonce
        if (!isset($_POST['nonce'])) {
            error_log('Nabi Backup: Nonce no recibido en POST');
            wp_send_json_error(['message' => __('Nonce no recibido', 'Nabi-backup')]);
            return;
        }

        check_ajax_referer('NABI_BACKUP_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            $error = __('No tienes permisos para realizar esta acción', 'Nabi-backup');
            NABI_BACKUP_Logger::error($error);
            wp_send_json_error(['message' => $error]);
        }

        try {
            $export = new NABI_BACKUP_Export();
            $result = $export->create_backup();

            if ($result['success']) {
                NABI_BACKUP_Logger::info('Exportación completada exitosamente: ' . $result['filename']);

                // Generar URL de descarga sin usar wp_nonce_url para evitar problemas de codificación
                $nonce = wp_create_nonce('NABI_BACKUP_download_' . $result['filename']);
                $download_url = admin_url('admin-post.php');
                $download_url = add_query_arg([
                    'action' => 'NABI_BACKUP_download',
                    'file' => urlencode($result['filename']),
                    '_wpnonce' => $nonce
                ], $download_url);

                wp_send_json_success([
                    'message' => __('Backup creado exitosamente', 'Nabi-backup'),
                    'file' => $result['filename'],
                    'file_path' => $result['file'],
                    'download_url' => $download_url
                ]);
            } else {
                $error = $result['error'] ?: __('Error desconocido al crear el backup', 'Nabi-backup');
                NABI_BACKUP_Logger::error('Error en exportación: ' . $error);
                wp_send_json_error(['message' => $error, 'error' => $error]);
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
            NABI_BACKUP_Logger::error('Excepción en exportación: ' . $error);
            NABI_BACKUP_Logger::error('Stack trace: ' . $e->getTraceAsString());
            wp_send_json_error(['message' => $error, 'error' => $error]);
        } catch (Error $e) {
            $error = $e->getMessage();
            NABI_BACKUP_Logger::error('Error fatal en exportación: ' . $error);
            NABI_BACKUP_Logger::error('Stack trace: ' . $e->getTraceAsString());
            wp_send_json_error(['message' => $error, 'error' => $error]);
        }
    }

    /**
     * Maneja la validación de archivo de backup
     */
    public static function handle_validate()
    {
        check_ajax_referer('NABI_BACKUP_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('No tienes permisos para realizar esta acción', 'Nabi-backup')]);
        }

        if (!isset($_FILES['backup_file'])) {
            wp_send_json_error(['message' => __('No se recibió ningún archivo', 'Nabi-backup')]);
        }

        $file = $_FILES['backup_file'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error(['message' => __('Error al subir el archivo', 'Nabi-backup')]);
        }

        // Validar tipo de archivo
        $file_type = wp_check_filetype($file['name']);
        if ($file_type['ext'] !== 'zip' && $file_type['ext'] !== 'Nabi') {
            wp_send_json_error(['message' => __('El archivo debe ser un ZIP o .Nabi', 'Nabi-backup')]);
        }

        // Mover archivo a directorio temporal
        $backup_dir = ABSPATH . 'wp-content/Nabibck';
        if (!file_exists($backup_dir)) {
            wp_mkdir_p($backup_dir);
        }

        $temp_file = $backup_dir . '/import-' . time() . '.Nabi';
        if (!move_uploaded_file($file['tmp_name'], $temp_file)) {
            wp_send_json_error(['message' => __('No se pudo mover el archivo', 'Nabi-backup')]);
        }

        // Validar backup
        $import = new NABI_BACKUP_Import();
        $validation = $import->validate_backup_file($temp_file);

        if ($validation['valid']) {
            // Renombrar a un formato permanente para que aparezca en el listado de backups
            $date_str = isset($validation['info']['date']) ? sanitize_file_name($validation['info']['date']) : time();
            $permanent_filename = 'Nabi-backup-imported-' . $date_str . '-' . uniqid() . '.Nabi';
            $permanent_file = $backup_dir . '/' . $permanent_filename;

            if (@rename($temp_file, $permanent_file)) {
                NABI_BACKUP_Logger::info('Backup importado guardado permanentemente: ' . $permanent_filename);
                $temp_file = $permanent_file;
            }

            wp_send_json_success([
                'message' => __('Archivo de backup válido y guardado en el listado', 'Nabi-backup'),
                'info' => $validation['info'],
                'temp_file' => basename($temp_file)
            ]);
        } else {
            @unlink($temp_file);
            wp_send_json_error(['message' => $validation['error']]);
        }
    }

    /**
     * Maneja la importación de backup
     */
    public static function handle_import()
    {
        NABI_BACKUP_Logger::info('Solicitud AJAX de importación recibida');

        // Aumentar límites para procesos largos
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');
        @ini_set('max_execution_time', 0);

        check_ajax_referer('NABI_BACKUP_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            $error = __('No tienes permisos para realizar esta acción', 'Nabi-backup');
            NABI_BACKUP_Logger::error($error);
            wp_send_json_error(['message' => $error]);
        }

        if (!isset($_POST['temp_file'])) {
            $error = __('No se especificó el archivo a importar', 'Nabi-backup');
            NABI_BACKUP_Logger::error($error);
            wp_send_json_error(['message' => $error]);
        }

        $backup_dir = ABSPATH . 'wp-content/Nabibck';
        $filename = sanitize_file_name($_POST['temp_file']);
        $file_path = $backup_dir . '/' . $filename;

        NABI_BACKUP_Logger::info('Intentando importar archivo: ' . $file_path);

        if (!file_exists($file_path)) {
            $error = __('El archivo no existe', 'Nabi-backup') . ': ' . $file_path;
            NABI_BACKUP_Logger::error($error);
            wp_send_json_error(['message' => $error]);
        }

        try {
            $import = new NABI_BACKUP_Import();
            $result = $import->import_backup($file_path);

            // Mantenemos el archivo en el servidor para que aparezca en el listado, como solicitó el usuario
            NABI_BACKUP_Logger::info('Backup conservado en el servidor para el listado');

            if ($result['success']) {
                NABI_BACKUP_Logger::info('Importación completada exitosamente');
                wp_send_json_success(['message' => $result['message']]);
            } else {
                $error = $result['error'] ?: __('Error desconocido al importar el backup', 'Nabi-backup');
                NABI_BACKUP_Logger::error('Error en importación: ' . $error);
                wp_send_json_error(['message' => $error, 'error' => $error]);
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
            NABI_BACKUP_Logger::error('Excepción en importación: ' . $error);
            NABI_BACKUP_Logger::error('Stack trace: ' . $e->getTraceAsString());
            wp_send_json_error(['message' => $error, 'error' => $error]);
        } catch (Error $e) {
            $error = $e->getMessage();
            NABI_BACKUP_Logger::error('Error fatal en importación: ' . $error);
            NABI_BACKUP_Logger::error('Stack trace: ' . $e->getTraceAsString());
            wp_send_json_error(['message' => $error, 'error' => $error]);
        }
    }

    /**
     * Obtiene URL de autorización OAuth para conectar cuenta
     */
    public static function handle_connect_account()
    {
        check_ajax_referer('NABI_BACKUP_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('No tienes permisos para realizar esta acción', 'Nabi-backup')]);
        }

        require_once NABI_BACKUP_PATH . 'includes/class-Nabi-backup-license.php';

        $auth_url = NABI_BACKUP_License::get_oauth_authorize_url();

        wp_send_json_success([
            'auth_url' => $auth_url,
            'message' => __('Redirigiendo a la página de autorización...', 'Nabi-backup')
        ]);
    }

    /**
     * Desconecta la cuenta
     */
    public static function handle_disconnect_account()
    {
        check_ajax_referer('NABI_BACKUP_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('No tienes permisos para realizar esta acción', 'Nabi-backup')]);
        }

        require_once NABI_BACKUP_PATH . 'includes/class-Nabi-backup-license.php';
        require_once NABI_BACKUP_PATH . 'includes/class-Nabi-backup-scheduler.php';

        // Desprogramar backups automáticos
        NABI_BACKUP_Scheduler::unschedule_backup();

        NABI_BACKUP_License::disconnect_account();

        wp_send_json_success(['message' => __('Cuenta desconectada correctamente', 'Nabi-backup')]);
    }

    /**
     * Guarda la configuración de copias automáticas
     */
    public static function handle_save_auto_config()
    {
        check_ajax_referer('NABI_BACKUP_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('No tienes permisos para realizar esta acción', 'Nabi-backup')]);
        }

        require_once NABI_BACKUP_PATH . 'includes/class-Nabi-backup-license.php';
        require_once NABI_BACKUP_PATH . 'includes/class-Nabi-backup-scheduler.php';

        // Solo para versión Ultra
        if (!NABI_BACKUP_License::has_auto_backups()) {
            wp_send_json_error(['message' => __('Copias automáticas solo disponibles en versión Ultra', 'Nabi-backup')]);
        }

        $enabled = isset($_POST['enabled']) && $_POST['enabled'] === 'true';
        $frequency = isset($_POST['frequency']) ? sanitize_text_field($_POST['frequency']) : 'daily';
        $time = isset($_POST['time']) ? sanitize_text_field($_POST['time']) : '02:00';

        // Validar frecuencia
        if (!in_array($frequency, ['daily', 'weekly', 'monthly'])) {
            wp_send_json_error(['message' => __('Frecuencia inválida', 'Nabi-backup')]);
        }

        // Validar formato de hora
        if (!preg_match('/^([0-1][0-9]|2[0-3]):[0-5][0-9]$/', $time)) {
            wp_send_json_error(['message' => __('Formato de hora inválido (debe ser HH:mm)', 'Nabi-backup')]);
        }

        $config = [
            'enabled' => $enabled,
            'frequency' => $frequency,
            'time' => $time
        ];

        NABI_BACKUP_License::save_auto_backup_config($config);

        // Reprogramar backups
        if ($enabled) {
            NABI_BACKUP_Scheduler::schedule_backup();
        } else {
            NABI_BACKUP_Scheduler::unschedule_backup();
        }

        wp_send_json_success([
            'message' => __('Configuración guardada correctamente', 'Nabi-backup'),
            'config' => $config
        ]);
    }

    /**
     * Obtiene información de la cuenta conectada
     */
    public static function handle_get_account_info()
    {
        check_ajax_referer('NABI_BACKUP_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('No tienes permisos para realizar esta acción', 'Nabi-backup')]);
        }

        require_once NABI_BACKUP_PATH . 'includes/class-Nabi-backup-license.php';
        require_once NABI_BACKUP_PATH . 'includes/class-Nabi-backup-scheduler.php';

        $version = NABI_BACKUP_License::get_version();
        $is_connected = NABI_BACKUP_License::is_account_connected();
        $account_info = NABI_BACKUP_License::get_account_info();
        $max_backups = NABI_BACKUP_License::get_max_backups();
        $current_backups = NABI_BACKUP_License::count_valid_backups();
        $can_create = NABI_BACKUP_License::can_create_backup();
        $has_auto_backups = NABI_BACKUP_License::has_auto_backups();

        $auto_config = null;
        $scheduler_status = null;

        if ($has_auto_backups) {
            $auto_config = NABI_BACKUP_License::get_auto_backup_config();
            $scheduler_status = NABI_BACKUP_Scheduler::get_status();
        }

        wp_send_json_success([
            'version' => $version,
            'is_connected' => $is_connected,
            'account_info' => $account_info,
            'max_backups' => $max_backups === -1 ? __('Ilimitado', 'Nabi-backup') : $max_backups,
            'current_backups' => $current_backups,
            'can_create' => $can_create,
            'has_auto_backups' => $has_auto_backups,
            'auto_config' => $auto_config,
            'scheduler_status' => $scheduler_status
        ]);
    }

    /**
     * Maneja la eliminación de un backup
     */
    public static function handle_delete()
    {
        try {
            check_ajax_referer('NABI_BACKUP_nonce', 'nonce');

            if (!current_user_can('manage_options')) {
                throw new Exception(__('No tienes permisos para realizar esta acción', 'Nabi-backup'));
            }

            if (!isset($_POST['filename']) || empty($_POST['filename'])) {
                throw new Exception(__('No se especificó el archivo a eliminar', 'Nabi-backup'));
            }

            $filename = sanitize_file_name($_POST['filename']);
            $backup_dir = defined('ABSPATH') ? ABSPATH . 'wp-content/Nabibck' : null;
            
            if (!$backup_dir) {
                throw new Exception('Error de entorno: ABSPATH no definido');
            }

            $file_path = $backup_dir . '/' . $filename;
            NABI_BACKUP_Logger::info('Solicitud de borrado para: ' . $filename);

            // Verificar que el archivo existe
            if (!file_exists($file_path)) {
                throw new Exception(__('El archivo no existe o ya ha sido eliminado', 'Nabi-backup'));
            }

            // Verificar seguridad de ruta
            $real_backup_dir = realpath($backup_dir);
            $real_file_path = realpath($file_path);

            if (!$real_backup_dir || !$real_file_path || strpos($real_file_path, $real_backup_dir) !== 0) {
                NABI_BACKUP_Logger::error("Intento de borrado fuera de rango: $file_path");
                throw new Exception(__('Ruta de archivo no válida', 'Nabi-backup'));
            }

            // Eliminar el archivo
            if (@unlink($file_path)) {
                NABI_BACKUP_Logger::info('Backup eliminado permanentemente: ' . $filename);
                wp_send_json_success([
                    'message' => __('Backup eliminado correctamente', 'Nabi-backup')
                ]);
            } else {
                throw new Exception(__('El sistema de archivos no permitió eliminar el archivo. Verifique permisos.', 'Nabi-backup'));
            }

        } catch (Exception $e) {
            NABI_BACKUP_Logger::error('Fallo al borrar backup: ' . $e->getMessage());
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        } catch (Error $e) {
            NABI_BACKUP_Logger::error('Error crítico al borrar backup: ' . $e->getMessage());
            wp_send_json_error([
                'message' => __('Error crítico del sistema al procesar el borrado', 'Nabi-backup')
            ]);
        }
    }


    /**
     * Guarda las configuraciones del backup
     */
    public static function handle_save_settings()
    {
        check_ajax_referer('NABI_BACKUP_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('No tienes permisos para realizar esta acción', 'Nabi-backup')]);
        }

        $settings = [
            'include_media' => isset($_POST['include_media']),
            'include_uploads' => isset($_POST['include_uploads']),
            'include_plugins' => isset($_POST['include_plugins']),
            'include_themes' => isset($_POST['include_themes']),
            'include_posts' => isset($_POST['include_posts']),
            'include_pages' => isset($_POST['include_pages']),
            'include_comments' => isset($_POST['include_comments']),
            'include_users' => isset($_POST['include_users']),
            'include_database' => isset($_POST['include_database']),
            'exclude_other_backups' => isset($_POST['exclude_other_backups']),
        ];

        NABI_BACKUP_Settings::save_settings($settings);

        wp_send_json_success([
            'message' => __('Configuración guardada exitosamente', 'Nabi-backup')
        ]);
    }

    /**
     * Prueba la conexión OAuth con el servidor
     */
    public static function handle_test_connection()
    {
        NABI_BACKUP_Logger::debug('Nabi Backup: Iniciando prueba de conexión OAuth');

        check_ajax_referer('NABI_BACKUP_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            NABI_BACKUP_Logger::debug('Nabi Backup: Usuario sin permisos para probar conexión');
            wp_send_json_error(['message' => __('No tienes permisos para realizar esta acción', 'Nabi-backup')]);
        }

        require_once NABI_BACKUP_PATH . 'includes/class-Nabi-backup-license.php';

        // Verificar que haya cuenta conectada
        if (!NABI_BACKUP_License::is_account_connected()) {
            NABI_BACKUP_Logger::debug('Nabi Backup: No hay cuenta conectada');
            wp_send_json_error(['message' => __('No hay cuenta conectada. Por favor, conecta tu cuenta primero.', 'Nabi-backup')]);
        }

        // Verificar que el token no haya expirado
        if (NABI_BACKUP_License::is_token_expired()) {
            NABI_BACKUP_Logger::debug('Nabi Backup: Token expirado, intentando refrescar');
            if (!NABI_BACKUP_License::refresh_account_token()) {
                NABI_BACKUP_Logger::debug('Nabi Backup: No se pudo refrescar el token');
                wp_send_json_error(['message' => __('El token ha expirado y no se pudo refrescar. Por favor, desconecta y vuelve a conectar tu cuenta.', 'Nabi-backup')]);
            }
        }

        // Obtener información de la cuenta para probar la conexión
        $account_info = NABI_BACKUP_License::get_account_info();

        if (!$account_info) {
            NABI_BACKUP_Logger::debug('Nabi Backup: No se pudo obtener información de la cuenta');
            wp_send_json_error(['message' => __('No se pudo obtener información de la cuenta. Verifica tu conexión.', 'Nabi-backup')]);
        }

        // Intentar hacer una petición al servidor OAuth para verificar
        $token = NABI_BACKUP_License::get_account_token();
        $api_url = NABI_BACKUP_License::API_BASE_URL . '/user/info';

        NABI_BACKUP_Logger::debug('Nabi Backup: Probando conexión con API', ['url' => $api_url]);

        $response = wp_remote_get($api_url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'User-Agent' => 'Nabi-Backup-Plugin/' . NABI_BACKUP_VERSION,
                'Content-Type' => 'application/json',
            ],
            'timeout' => 15,
            'sslverify' => true
        ]);

        if (is_wp_error($response)) {
            NABI_BACKUP_Logger::debug('Nabi Backup: Error en petición a API', ['error' => $response->get_error_message()]);
            wp_send_json_error([
                'message' => __('Error al conectar con el servidor: ', 'Nabi-backup') . $response->get_error_message()
            ]);
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        NABI_BACKUP_Logger::debug('Nabi Backup: Respuesta del servidor', [
            'status_code' => $status_code,
            'body' => $body
        ]);

        if ($status_code === 200) {
            $data = json_decode($body, true);
            if ($data && isset($data['success']) && $data['success']) {
                wp_send_json_success([
                    'message' => __('Conexión exitosa. La cuenta está conectada correctamente.', 'Nabi-backup'),
                    'account_info' => $account_info,
                    'api_response' => $data
                ]);
            } else {
                wp_send_json_error([
                    'message' => __('El servidor respondió pero la conexión no es válida.', 'Nabi-backup'),
                    'details' => $data
                ]);
            }
        } else {
            wp_send_json_error([
                'message' => sprintf(__('Error en la conexión. Código de respuesta: %d', 'Nabi-backup'), $status_code),
                'details' => $body
            ]);
        }
    }
    /**
     * Limpia automáticamente archivos y carpetas temporales antiguos (más de 24 horas)
     */
    public static function auto_cleanup()
    {
        $backup_dir = ABSPATH . 'wp-content/Nabibck';
        if (!is_dir($backup_dir)) return;

        $now = time();
        $expiry = 24 * 3600; // 24 horas
        $cleaned_count = 0;

        $items = @scandir($backup_dir);
        if (!$items) return;

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;

            // REGLA DE ORO: Si empieza por Nabi-backup-, NO TOCAR NUNCA.
            if (strpos($item, 'Nabi-backup-') === 0) continue;

            $path = $backup_dir . '/' . $item;
            $is_temp_dir = is_dir($path) && (
                strpos($item, 'temp-') === 0 || 
                strpos($item, 'import-') === 0 || 
                strpos($item, 'wp-content-backup-') === 0
            );
            
            $is_temp_file = !is_dir($path) && (
                strpos($item, 'zip.') === 0 || 
                (strpos($item, 'import-') === 0 && strpos($item, '.zip') !== false)
            );

            if ($is_temp_dir || $is_temp_file) {
                if ($now - filemtime($path) > $expiry) {
                    if (is_dir($path)) {
                        self::delete_directory_iterative($path);
                    } else {
                        @unlink($path);
                    }
                    $cleaned_count++;
                }
            }
        }

        if ($cleaned_count > 0) {
            NABI_BACKUP_Logger::info("Limpieza automática finalizada. Se eliminaron {$cleaned_count} elementos temporales antiguos.");
        }
    }

    /**
     * Elimina un directorio de forma iterativa y segura
     */
    private static function delete_directory_iterative($dir)
    {
        if (!is_dir($dir)) return;

        $stack = [$dir];
        $dirs_to_delete = [];

        while (!empty($stack)) {
            $current = array_pop($stack);
            $dirs_to_delete[] = $current;
            $files = @scandir($current);
            if (!$files) continue;

            foreach ($files as $file) {
                if ($file === '.' || $file === '..') continue;
                $path = $current . DIRECTORY_SEPARATOR . $file;
                if (is_dir($path)) {
                    $stack[] = $path;
                } else {
                    @unlink($path);
                }
            }
        }

        while (!empty($dirs_to_delete)) {
            @rmdir(array_pop($dirs_to_delete));
        }
    }
}


