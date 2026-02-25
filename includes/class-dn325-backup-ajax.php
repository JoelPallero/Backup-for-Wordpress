<?php
defined('ABSPATH') || exit;

class DN325_Backup_Ajax {

    public static function init() {
        add_action('wp_ajax_dn325_backup_export', [__CLASS__, 'handle_export']);
        add_action('wp_ajax_dn325_backup_import', [__CLASS__, 'handle_import']);
        add_action('wp_ajax_dn325_backup_validate', [__CLASS__, 'handle_validate']);
        add_action('wp_ajax_dn325_backup_list', [__CLASS__, 'handle_list']);
        add_action('wp_ajax_dn325_backup_get_progress', [__CLASS__, 'handle_get_progress']);
        add_action('wp_ajax_dn325_backup_connect_account', [__CLASS__, 'handle_connect_account']);
        add_action('wp_ajax_dn325_backup_disconnect_account', [__CLASS__, 'handle_disconnect_account']);
        add_action('wp_ajax_dn325_backup_save_auto_config', [__CLASS__, 'handle_save_auto_config']);
        add_action('wp_ajax_dn325_backup_get_account_info', [__CLASS__, 'handle_get_account_info']);
        add_action('wp_ajax_dn325_backup_delete', [__CLASS__, 'handle_delete']);
        add_action('wp_ajax_dn325_backup_save_settings', [__CLASS__, 'handle_save_settings']);
        add_action('wp_ajax_dn325_backup_test_connection', [__CLASS__, 'handle_test_connection']);
    }
    
    /**
     * Obtiene la lista de backups guardados (solo compatibles con el plugin)
     */
    public static function handle_list() {
        check_ajax_referer('dn325_backup_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('No tienes permisos para realizar esta acción', 'dn325-backup')]);
        }

        require_once DN325_BACKUP_PATH . 'includes/class-dn325-backup-license.php';
        require_once DN325_BACKUP_PATH . 'includes/class-dn325-backup-import.php';
        
        $backup_dir = ABSPATH . 'wp-content/dn325bck';
        $backups = [];

        // Verificar que el directorio existe
        if (!is_dir($backup_dir)) {
            DN325_Backup_Logger::debug('Directorio de backups no existe: ' . $backup_dir);
            wp_send_json_success(['backups' => $backups]);
        }

        // Buscar todos los archivos de backup (tanto con token válido como sin validar)
        $all_files = glob($backup_dir . '/dn325-backup-*.zip');
        
        if (!$all_files) {
            DN325_Backup_Logger::debug('No se encontraron archivos de backup en: ' . $backup_dir);
            wp_send_json_success(['backups' => $backups]);
        }

        DN325_Backup_Logger::debug('Encontrados ' . count($all_files) . ' archivos de backup para validar');

        foreach ($all_files as $file) {
            try {
                // Validar que el archivo sea compatible con el plugin
                $import = new DN325_Backup_Import();
                $validation = $import->validate_backup_file($file);
                
                // Solo incluir backups válidos y compatibles
                if ($validation['valid'] && isset($validation['info'])) {
                    $filename = basename($file);
                    $filetime = filemtime($file);
                    $filesize = filesize($file);
                    $info = $validation['info'];
                    
                    // Generar URL de descarga sin usar wp_nonce_url para evitar problemas de codificación
                    $nonce = wp_create_nonce('dn325_backup_download_' . $filename);
                    $download_url = admin_url('admin-post.php');
                    $download_url = add_query_arg([
                        'action' => 'dn325_backup_download',
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
                    
                    DN325_Backup_Logger::debug('Backup agregado a la lista: ' . $filename);
                } else {
                    // Log para debugging si la validación falla
                    $error_msg = isset($validation['error']) ? $validation['error'] : 'Desconocido';
                    DN325_Backup_Logger::debug('Backup no válido excluido de la lista: ' . basename($file) . ' - Error: ' . $error_msg);
                }
            } catch (Exception $e) {
                // Log error pero continuar con otros backups
                DN325_Backup_Logger::error('Error al validar backup ' . basename($file) . ': ' . $e->getMessage());
            } catch (Error $e) {
                // Log error fatal pero continuar
                DN325_Backup_Logger::error('Error fatal al validar backup ' . basename($file) . ': ' . $e->getMessage());
            }
        }

        // Ordenar por fecha (más reciente primero)
        usort($backups, function($a, $b) {
            return strtotime($b['date']) - strtotime($a['date']);
        });

        DN325_Backup_Logger::debug('Total de backups válidos encontrados: ' . count($backups));

        wp_send_json_success(['backups' => $backups]);
    }
    
    /**
     * Obtiene el progreso del backup (lee el log)
     */
    public static function handle_get_progress() {
        check_ajax_referer('dn325_backup_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('No tienes permisos para realizar esta acción', 'dn325-backup')]);
        }

        $log_file = DN325_Backup_Logger::get_log_file();
        $progress = [];
        $database_percentage = null;
        $files_percentage = null;
        $current_phase = null; // 'database' o 'files'
        
        if (file_exists($log_file)) {
            $lines = DN325_Backup_Logger::get_recent_logs(200);
            foreach ($lines as $line) {
                // Extraer porcentaje de base de datos
                if (preg_match('/\[INFO\].*?Base de datos:\s*(\d+)%/', $line, $matches)) {
                    $database_percentage = (int)$matches[1];
                    $current_phase = 'database';
                    $progress[] = trim($line);
                }
                // Extraer porcentaje de archivos
                elseif (preg_match('/\[INFO\].*?Archivos:\s*(\d+)%/', $line, $matches)) {
                    $files_percentage = (int)$matches[1];
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
    public static function handle_export() {
        // Log inicial para debugging
        error_log('DN325 Backup: handle_export() llamado');
        DN325_Backup_Logger::info('Solicitud AJAX de exportación recibida');
        
        // Verificar que la petición sea AJAX
        if (!wp_doing_ajax()) {
            error_log('DN325 Backup: No es una petición AJAX');
            wp_send_json_error(['message' => __('Esta acción solo puede ejecutarse vía AJAX', 'dn325-backup')]);
            return;
        }
        
        // Aumentar límites para procesos largos
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');
        @ini_set('max_execution_time', 0);
        
        DN325_Backup_Logger::debug('Límites configurados - Memory: ' . ini_get('memory_limit') . ', Max Execution Time: ' . ini_get('max_execution_time'));
        
        // Verificar nonce
        if (!isset($_POST['nonce'])) {
            error_log('DN325 Backup: Nonce no recibido en POST');
            wp_send_json_error(['message' => __('Nonce no recibido', 'dn325-backup')]);
            return;
        }
        
        check_ajax_referer('dn325_backup_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            $error = __('No tienes permisos para realizar esta acción', 'dn325-backup');
            DN325_Backup_Logger::error($error);
            wp_send_json_error(['message' => $error]);
        }

        try {
            $export = new DN325_Backup_Export();
            $result = $export->create_backup();

            if ($result['success']) {
                DN325_Backup_Logger::info('Exportación completada exitosamente: ' . $result['filename']);
                
                // Generar URL de descarga sin usar wp_nonce_url para evitar problemas de codificación
                $nonce = wp_create_nonce('dn325_backup_download_' . $result['filename']);
                $download_url = admin_url('admin-post.php');
                $download_url = add_query_arg([
                    'action' => 'dn325_backup_download',
                    'file' => urlencode($result['filename']),
                    '_wpnonce' => $nonce
                ], $download_url);
                
                wp_send_json_success([
                    'message' => __('Backup creado exitosamente', 'dn325-backup'),
                    'file' => $result['filename'],
                    'file_path' => $result['file'],
                    'download_url' => $download_url
                ]);
            } else {
                $error = $result['error'] ?: __('Error desconocido al crear el backup', 'dn325-backup');
                DN325_Backup_Logger::error('Error en exportación: ' . $error);
                wp_send_json_error(['message' => $error, 'error' => $error]);
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
            DN325_Backup_Logger::error('Excepción en exportación: ' . $error);
            DN325_Backup_Logger::error('Stack trace: ' . $e->getTraceAsString());
            wp_send_json_error(['message' => $error, 'error' => $error]);
        } catch (Error $e) {
            $error = $e->getMessage();
            DN325_Backup_Logger::error('Error fatal en exportación: ' . $error);
            DN325_Backup_Logger::error('Stack trace: ' . $e->getTraceAsString());
            wp_send_json_error(['message' => $error, 'error' => $error]);
        }
    }

    /**
     * Maneja la validación de archivo de backup
     */
    public static function handle_validate() {
        check_ajax_referer('dn325_backup_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('No tienes permisos para realizar esta acción', 'dn325-backup')]);
        }

        if (!isset($_FILES['backup_file'])) {
            wp_send_json_error(['message' => __('No se recibió ningún archivo', 'dn325-backup')]);
        }

        $file = $_FILES['backup_file'];
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error(['message' => __('Error al subir el archivo', 'dn325-backup')]);
        }

        // Validar tipo de archivo
        $file_type = wp_check_filetype($file['name']);
        if ($file_type['ext'] !== 'zip') {
            wp_send_json_error(['message' => __('El archivo debe ser un ZIP', 'dn325-backup')]);
        }

        // Mover archivo a directorio temporal
        $backup_dir = ABSPATH . 'wp-content/dn325bck';
        if (!file_exists($backup_dir)) {
            wp_mkdir_p($backup_dir);
        }

        $temp_file = $backup_dir . '/import-' . time() . '.zip';
        if (!move_uploaded_file($file['tmp_name'], $temp_file)) {
            wp_send_json_error(['message' => __('No se pudo mover el archivo', 'dn325-backup')]);
        }

        // Validar backup
                $import = new DN325_Backup_Import();
        $validation = $import->validate_backup_file($temp_file);

        if ($validation['valid']) {
            wp_send_json_success([
                'message' => __('Archivo de backup válido', 'dn325-backup'),
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
    public static function handle_import() {
        DN325_Backup_Logger::info('Solicitud AJAX de importación recibida');
        
        // Aumentar límites para procesos largos
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');
        @ini_set('max_execution_time', 0);
        
        check_ajax_referer('dn325_backup_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            $error = __('No tienes permisos para realizar esta acción', 'dn325-backup');
            DN325_Backup_Logger::error($error);
            wp_send_json_error(['message' => $error]);
        }

        if (!isset($_POST['temp_file'])) {
            $error = __('No se especificó el archivo a importar', 'dn325-backup');
            DN325_Backup_Logger::error($error);
            wp_send_json_error(['message' => $error]);
        }

        $backup_dir = ABSPATH . 'wp-content/dn325bck';
        $filename = sanitize_file_name($_POST['temp_file']);
        $file_path = $backup_dir . '/' . $filename;

        DN325_Backup_Logger::info('Intentando importar archivo: ' . $file_path);

        if (!file_exists($file_path)) {
            $error = __('El archivo no existe', 'dn325-backup') . ': ' . $file_path;
            DN325_Backup_Logger::error($error);
            wp_send_json_error(['message' => $error]);
        }

        try {
            $import = new DN325_Backup_Import();
            $result = $import->import_backup($file_path);

            // Solo eliminar archivo temporal si NO viene del servidor (archivos subidos)
            if (!isset($_POST['from_server']) || !$_POST['from_server']) {
                @unlink($file_path);
            }

            if ($result['success']) {
                DN325_Backup_Logger::info('Importación completada exitosamente');
                wp_send_json_success(['message' => $result['message']]);
            } else {
                $error = $result['error'] ?: __('Error desconocido al importar el backup', 'dn325-backup');
                DN325_Backup_Logger::error('Error en importación: ' . $error);
                wp_send_json_error(['message' => $error, 'error' => $error]);
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
            DN325_Backup_Logger::error('Excepción en importación: ' . $error);
            DN325_Backup_Logger::error('Stack trace: ' . $e->getTraceAsString());
            wp_send_json_error(['message' => $error, 'error' => $error]);
        } catch (Error $e) {
            $error = $e->getMessage();
            DN325_Backup_Logger::error('Error fatal en importación: ' . $error);
            DN325_Backup_Logger::error('Stack trace: ' . $e->getTraceAsString());
            wp_send_json_error(['message' => $error, 'error' => $error]);
        }
    }
    
    /**
     * Obtiene URL de autorización OAuth para conectar cuenta
     */
    public static function handle_connect_account() {
        check_ajax_referer('dn325_backup_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('No tienes permisos para realizar esta acción', 'dn325-backup')]);
        }

        require_once DN325_BACKUP_PATH . 'includes/class-dn325-backup-license.php';

        $auth_url = DN325_Backup_License::get_oauth_authorize_url();

        wp_send_json_success([
            'auth_url' => $auth_url,
            'message' => __('Redirigiendo a la página de autorización...', 'dn325-backup')
        ]);
    }
    
    /**
     * Desconecta la cuenta
     */
    public static function handle_disconnect_account() {
        check_ajax_referer('dn325_backup_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('No tienes permisos para realizar esta acción', 'dn325-backup')]);
        }

        require_once DN325_BACKUP_PATH . 'includes/class-dn325-backup-license.php';
        require_once DN325_BACKUP_PATH . 'includes/class-dn325-backup-scheduler.php';

        // Desprogramar backups automáticos
        DN325_Backup_Scheduler::unschedule_backup();

        DN325_Backup_License::disconnect_account();

        wp_send_json_success(['message' => __('Cuenta desconectada correctamente', 'dn325-backup')]);
    }
    
    /**
     * Guarda la configuración de copias automáticas
     */
    public static function handle_save_auto_config() {
        check_ajax_referer('dn325_backup_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('No tienes permisos para realizar esta acción', 'dn325-backup')]);
        }

        require_once DN325_BACKUP_PATH . 'includes/class-dn325-backup-license.php';
        require_once DN325_BACKUP_PATH . 'includes/class-dn325-backup-scheduler.php';

        // Solo para versión Ultra
        if (!DN325_Backup_License::has_auto_backups()) {
            wp_send_json_error(['message' => __('Copias automáticas solo disponibles en versión Ultra', 'dn325-backup')]);
        }

        $enabled = isset($_POST['enabled']) && $_POST['enabled'] === 'true';
        $frequency = isset($_POST['frequency']) ? sanitize_text_field($_POST['frequency']) : 'daily';
        $time = isset($_POST['time']) ? sanitize_text_field($_POST['time']) : '02:00';

        // Validar frecuencia
        if (!in_array($frequency, ['daily', 'weekly', 'monthly'])) {
            wp_send_json_error(['message' => __('Frecuencia inválida', 'dn325-backup')]);
        }

        // Validar formato de hora
        if (!preg_match('/^([0-1][0-9]|2[0-3]):[0-5][0-9]$/', $time)) {
            wp_send_json_error(['message' => __('Formato de hora inválido (debe ser HH:mm)', 'dn325-backup')]);
        }

        $config = [
            'enabled' => $enabled,
            'frequency' => $frequency,
            'time' => $time
        ];

        DN325_Backup_License::save_auto_backup_config($config);

        // Reprogramar backups
        if ($enabled) {
            DN325_Backup_Scheduler::schedule_backup();
        } else {
            DN325_Backup_Scheduler::unschedule_backup();
        }

        wp_send_json_success([
            'message' => __('Configuración guardada correctamente', 'dn325-backup'),
            'config' => $config
        ]);
    }
    
    /**
     * Obtiene información de la cuenta conectada
     */
    public static function handle_get_account_info() {
        check_ajax_referer('dn325_backup_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('No tienes permisos para realizar esta acción', 'dn325-backup')]);
        }

        require_once DN325_BACKUP_PATH . 'includes/class-dn325-backup-license.php';
        require_once DN325_BACKUP_PATH . 'includes/class-dn325-backup-scheduler.php';

        $version = DN325_Backup_License::get_version();
        $is_connected = DN325_Backup_License::is_account_connected();
        $account_info = DN325_Backup_License::get_account_info();
        $max_backups = DN325_Backup_License::get_max_backups();
        $current_backups = DN325_Backup_License::count_valid_backups();
        $can_create = DN325_Backup_License::can_create_backup();
        $has_auto_backups = DN325_Backup_License::has_auto_backups();
        
        $auto_config = null;
        $scheduler_status = null;
        
        if ($has_auto_backups) {
            $auto_config = DN325_Backup_License::get_auto_backup_config();
            $scheduler_status = DN325_Backup_Scheduler::get_status();
        }

        wp_send_json_success([
            'version' => $version,
            'is_connected' => $is_connected,
            'account_info' => $account_info,
            'max_backups' => $max_backups === -1 ? __('Ilimitado', 'dn325-backup') : $max_backups,
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
    public static function handle_delete() {
        check_ajax_referer('dn325_backup_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            $error = __('No tienes permisos para realizar esta acción', 'dn325-backup');
            DN325_Backup_Logger::error($error);
            wp_send_json_error(['message' => $error]);
        }

        if (!isset($_POST['filename'])) {
            $error = __('No se especificó el archivo a eliminar', 'dn325-backup');
            DN325_Backup_Logger::error($error);
            wp_send_json_error(['message' => $error]);
        }

        $filename = sanitize_file_name($_POST['filename']);
        $backup_dir = ABSPATH . 'wp-content/dn325bck';
        $file_path = $backup_dir . '/' . $filename;

        DN325_Backup_Logger::info('Intentando eliminar backup: ' . $file_path);

        // Verificar que el archivo existe
        if (!file_exists($file_path)) {
            $error = __('El archivo no existe', 'dn325-backup');
            DN325_Backup_Logger::error($error);
            wp_send_json_error(['message' => $error]);
        }

        // Verificar que está dentro del directorio de backups (seguridad)
        $real_backup_dir = realpath($backup_dir);
        $real_file_path = realpath($file_path);
        
        if (!$real_backup_dir || !$real_file_path || strpos($real_file_path, $real_backup_dir) !== 0) {
            $error = __('Ruta de archivo no válida', 'dn325-backup');
            DN325_Backup_Logger::error($error);
            wp_send_json_error(['message' => $error]);
        }

        // Eliminar el archivo
        if (@unlink($file_path)) {
            DN325_Backup_Logger::info('Backup eliminado exitosamente: ' . $filename);
            wp_send_json_success([
                'message' => __('Backup eliminado correctamente', 'dn325-backup')
            ]);
        } else {
            $error = __('No se pudo eliminar el archivo', 'dn325-backup');
            DN325_Backup_Logger::error($error);
            wp_send_json_error(['message' => $error]);
        }
    }

    /**
     * Guarda las configuraciones del backup
     */
    public static function handle_save_settings() {
        check_ajax_referer('dn325_backup_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('No tienes permisos para realizar esta acción', 'dn325-backup')]);
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
        ];

        DN325_Backup_Settings::save_settings($settings);

        wp_send_json_success([
            'message' => __('Configuración guardada exitosamente', 'dn325-backup')
        ]);
    }

    /**
     * Prueba la conexión OAuth con el servidor
     */
    public static function handle_test_connection() {
        DN325_Backup_Logger::debug('DN325 Backup: Iniciando prueba de conexión OAuth');
        
        check_ajax_referer('dn325_backup_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            DN325_Backup_Logger::debug('DN325 Backup: Usuario sin permisos para probar conexión');
            wp_send_json_error(['message' => __('No tienes permisos para realizar esta acción', 'dn325-backup')]);
        }

        require_once DN325_BACKUP_PATH . 'includes/class-dn325-backup-license.php';

        // Verificar que haya cuenta conectada
        if (!DN325_Backup_License::is_account_connected()) {
            DN325_Backup_Logger::debug('DN325 Backup: No hay cuenta conectada');
            wp_send_json_error(['message' => __('No hay cuenta conectada. Por favor, conecta tu cuenta primero.', 'dn325-backup')]);
        }

        // Verificar que el token no haya expirado
        if (DN325_Backup_License::is_token_expired()) {
            DN325_Backup_Logger::debug('DN325 Backup: Token expirado, intentando refrescar');
            if (!DN325_Backup_License::refresh_account_token()) {
                DN325_Backup_Logger::debug('DN325 Backup: No se pudo refrescar el token');
                wp_send_json_error(['message' => __('El token ha expirado y no se pudo refrescar. Por favor, desconecta y vuelve a conectar tu cuenta.', 'dn325-backup')]);
            }
        }

        // Obtener información de la cuenta para probar la conexión
        $account_info = DN325_Backup_License::get_account_info();
        
        if (!$account_info) {
            DN325_Backup_Logger::debug('DN325 Backup: No se pudo obtener información de la cuenta');
            wp_send_json_error(['message' => __('No se pudo obtener información de la cuenta. Verifica tu conexión.', 'dn325-backup')]);
        }

        // Intentar hacer una petición al servidor OAuth para verificar
        $token = DN325_Backup_License::get_account_token();
        $api_url = DN325_Backup_License::API_BASE_URL . '/user/info';
        
        DN325_Backup_Logger::debug('DN325 Backup: Probando conexión con API', ['url' => $api_url]);
        
        $response = wp_remote_get($api_url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'User-Agent' => 'DN325-Backup-Plugin/' . DN325_BACKUP_VERSION,
                'Content-Type' => 'application/json',
            ],
            'timeout' => 15,
            'sslverify' => true
        ]);

        if (is_wp_error($response)) {
            DN325_Backup_Logger::debug('DN325 Backup: Error en petición a API', ['error' => $response->get_error_message()]);
            wp_send_json_error([
                'message' => __('Error al conectar con el servidor: ', 'dn325-backup') . $response->get_error_message()
            ]);
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        DN325_Backup_Logger::debug('DN325 Backup: Respuesta del servidor', [
            'status_code' => $status_code,
            'body' => $body
        ]);

        if ($status_code === 200) {
            $data = json_decode($body, true);
            if ($data && isset($data['success']) && $data['success']) {
                wp_send_json_success([
                    'message' => __('Conexión exitosa. La cuenta está conectada correctamente.', 'dn325-backup'),
                    'account_info' => $account_info,
                    'api_response' => $data
                ]);
            } else {
                wp_send_json_error([
                    'message' => __('El servidor respondió pero la conexión no es válida.', 'dn325-backup'),
                    'details' => $data
                ]);
            }
        } else {
            wp_send_json_error([
                'message' => sprintf(__('Error en la conexión. Código de respuesta: %d', 'dn325-backup'), $status_code),
                'details' => $body
            ]);
        }
    }
}
