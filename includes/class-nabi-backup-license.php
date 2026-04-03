<?php
defined('ABSPATH') || exit;

/**
 * Clase para gestionar conexión de cuenta y versiones del plugin
 * Similar a Elementor Pro - requiere conexión de cuenta para usar Pro/Ultra
 */
class NABI_BACKUP_License
{

    const VERSION_FREE = 'free';
    const VERSION_PRO = 'pro';
    const VERSION_ULTRA = 'ultra';

    const OPTION_ACCOUNT_TOKEN = 'NABI_BACKUP_account_token';
    const OPTION_ACCOUNT_REFRESH_TOKEN = 'NABI_BACKUP_account_refresh_token';
    const OPTION_ACCOUNT_VERSION = 'NABI_BACKUP_account_version';
    const OPTION_ACCOUNT_CONNECTED = 'NABI_BACKUP_account_connected';
    const OPTION_ACCOUNT_USER_ID = 'NABI_BACKUP_account_user_id';
    const OPTION_ACCOUNT_USER_EMAIL = 'NABI_BACKUP_account_user_email';
    const OPTION_ACCOUNT_EXPIRES_AT = 'NABI_BACKUP_account_expires_at';
    const OPTION_INSTALLATION_TOKEN = 'NABI_BACKUP_installation_token';
    const OPTION_OAUTH_STATE = 'NABI_BACKUP_oauth_state';

    const MAX_BACKUPS_FREE = -1; // Sin límite
    const MAX_BACKUPS_PRO = -1;  // Sin límite
    const MAX_BACKUPS_ULTRA = -1; // Ilimitado

    const API_BASE_URL = 'https://joelpallero.com.ar/productos/api';
    const OAUTH_AUTHORIZE_URL = 'https://joelpallero.com.ar/productos/oauth/authorize';
    const OAUTH_TOKEN_URL = 'https://joelpallero.com.ar/productos/api/oauth/token';

    /**
     * Obtiene la versión actual del plugin basada en la cuenta conectada
     */
    public static function get_version()
    {
        // Verificar si hay cuenta conectada y válida
        if (!self::is_account_connected()) {
            return self::VERSION_FREE;
        }

        // Verificar que el token no haya expirado
        if (self::is_token_expired()) {
            // Intentar refrescar el token
            if (!self::refresh_account_token()) {
                // Si no se puede refrescar, desconectar
                self::disconnect_account();
                return self::VERSION_FREE;
            }
        }

        $version = get_option(self::OPTION_ACCOUNT_VERSION, self::VERSION_FREE);

        // Solo retornar Pro o Ultra si la cuenta está conectada
        if (in_array($version, [self::VERSION_PRO, self::VERSION_ULTRA])) {
            return $version;
        }

        return self::VERSION_FREE;
    }

    /**
     * Verifica si hay una cuenta conectada
     */
    public static function is_account_connected()
    {
        $connected = get_option(self::OPTION_ACCOUNT_CONNECTED, false);
        $token = get_option(self::OPTION_ACCOUNT_TOKEN);

        return $connected && !empty($token);
    }

    /**
     * Verifica si el token ha expirado
     */
    public static function is_token_expired()
    {
        $expires_at = get_option(self::OPTION_ACCOUNT_EXPIRES_AT);

        if (!$expires_at) {
            return true;
        }

        // Verificar con margen de 5 minutos antes de expirar
        return time() >= ($expires_at - 300);
    }

    /**
     * Obtiene el token único de la instalación (para backups)
     */
    public static function get_token()
    {
        $token = get_option(self::OPTION_INSTALLATION_TOKEN);

        if (!$token) {
            // Generar token único basado en información del sitio
            $site_url = get_site_url();
            $admin_email = get_option('admin_email');
            $site_name = get_option('blogname');

            // Crear hash único y seguro
            $data = $site_url . $admin_email . $site_name . ABSPATH;
            $token = hash('sha256', $data . wp_salt('auth'));

            // Guardar token
            update_option(self::OPTION_INSTALLATION_TOKEN, $token);
        }

        return $token;
    }

    /**
     * Obtiene el token de acceso de la cuenta
     */
    public static function get_account_token()
    {
        return get_option(self::OPTION_ACCOUNT_TOKEN);
    }

    /**
     * Obtiene información de la cuenta conectada
     */
    public static function get_account_info()
    {
        if (!self::is_account_connected()) {
            return null;
        }

        return [
            'user_id' => get_option(self::OPTION_ACCOUNT_USER_ID),
            'user_email' => get_option(self::OPTION_ACCOUNT_USER_EMAIL),
            'version' => get_option(self::OPTION_ACCOUNT_VERSION, self::VERSION_FREE),
            'expires_at' => get_option(self::OPTION_ACCOUNT_EXPIRES_AT)
        ];
    }

    /**
     * Genera URL de autorización OAuth
     */
    public static function get_oauth_authorize_url()
    {
        $state = wp_generate_password(32, false);
        update_option(self::OPTION_OAUTH_STATE, $state);

        $redirect_uri = admin_url('admin.php?page=Nabi-backup&action=oauth_callback');
        $site_url = get_site_url();
        $installation_token = self::get_token();

        $params = [
            'response_type' => 'code',
            'client_id' => 'Nabi-backup-plugin',
            'redirect_uri' => $redirect_uri,
            'state' => $state,
            'scope' => 'read write',
            'site_url' => $site_url,
            'installation_token' => $installation_token
        ];

        return self::OAUTH_AUTHORIZE_URL . '?' . http_build_query($params);
    }

    /**
     * Procesa el callback de OAuth
     */
    public static function process_oauth_callback($code, $state)
    {
        // Verificar state
        $saved_state = get_option(self::OPTION_OAUTH_STATE);
        if (!$saved_state || !hash_equals($saved_state, $state)) {
            return [
                'success' => false,
                'message' => __('Estado de seguridad inválido', 'Nabi-backup')
            ];
        }

        // Eliminar state usado
        delete_option(self::OPTION_OAUTH_STATE);

        // Intercambiar código por token
        $redirect_uri = admin_url('admin.php?page=Nabi-backup&action=oauth_callback');
        $site_url = get_site_url();
        $installation_token = self::get_token();

        $response = wp_remote_post(self::OAUTH_TOKEN_URL, [
            'body' => [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $redirect_uri,
                'client_id' => 'Nabi-backup-plugin',
                'site_url' => $site_url,
                'installation_token' => $installation_token
            ],
            'timeout' => 15,
            'sslverify' => true,
            'headers' => [
                'User-Agent' => 'Nabi-Backup-Plugin/' . NABI_BACKUP_VERSION,
                'X-Requested-With' => 'XMLHttpRequest'
            ]
        ]);

        if (is_wp_error($response)) {
            NABI_BACKUP_Logger::error('Error al obtener token OAuth: ' . $response->get_error_message());
            return [
                'success' => false,
                'message' => __('Error al conectar con el servidor', 'Nabi-backup')
            ];
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            NABI_BACKUP_Logger::error('Error HTTP al obtener token OAuth: ' . $response_code);
            return [
                'success' => false,
                'message' => __('Error del servidor de autenticación', 'Nabi-backup')
            ];
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!$data || !isset($data['access_token'])) {
            NABI_BACKUP_Logger::error('Respuesta inválida del servidor OAuth');
            return [
                'success' => false,
                'message' => __('Respuesta inválida del servidor', 'Nabi-backup')
            ];
        }

        // Guardar tokens
        update_option(self::OPTION_ACCOUNT_TOKEN, $data['access_token']);
        if (isset($data['refresh_token'])) {
            update_option(self::OPTION_ACCOUNT_REFRESH_TOKEN, $data['refresh_token']);
        }

        $expires_in = isset($data['expires_in']) ? (int) $data['expires_in'] : 3600;
        update_option(self::OPTION_ACCOUNT_EXPIRES_AT, time() + $expires_in);

        // Obtener información del usuario
        $user_info = self::get_user_info_from_api($data['access_token']);

        if ($user_info && isset($user_info['version'])) {
            update_option(self::OPTION_ACCOUNT_USER_ID, $user_info['user_id'] ?? '');
            update_option(self::OPTION_ACCOUNT_USER_EMAIL, $user_info['email'] ?? '');
            update_option(self::OPTION_ACCOUNT_VERSION, $user_info['version']);
            update_option(self::OPTION_ACCOUNT_CONNECTED, true);

            NABI_BACKUP_Logger::info('Cuenta conectada exitosamente. Versión: ' . $user_info['version']);

            return [
                'success' => true,
                'message' => __('Cuenta conectada exitosamente', 'Nabi-backup'),
                'version' => $user_info['version']
            ];
        }

        return [
            'success' => false,
            'message' => __('No se pudo obtener información de la cuenta', 'Nabi-backup')
        ];
    }

    /**
     * Obtiene información del usuario desde la API
     */
    private static function get_user_info_from_api($access_token)
    {
        $response = wp_remote_get(self::API_BASE_URL . '/user/info', [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'User-Agent' => 'Nabi-Backup-Plugin/' . NABI_BACKUP_VERSION
            ],
            'timeout' => 15,
            'sslverify' => true
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        return json_decode($body, true);
    }

    /**
     * Refresca el token de acceso
     */
    public static function refresh_account_token()
    {
        $refresh_token = get_option(self::OPTION_ACCOUNT_REFRESH_TOKEN);

        if (!$refresh_token) {
            return false;
        }

        $site_url = get_site_url();
        $installation_token = self::get_token();

        $response = wp_remote_post(self::OAUTH_TOKEN_URL, [
            'body' => [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refresh_token,
                'client_id' => 'Nabi-backup-plugin',
                'site_url' => $site_url,
                'installation_token' => $installation_token
            ],
            'timeout' => 15,
            'sslverify' => true,
            'headers' => [
                'User-Agent' => 'Nabi-Backup-Plugin/' . NABI_BACKUP_VERSION
            ]
        ]);

        if (is_wp_error($response)) {
            NABI_BACKUP_Logger::error('Error al refrescar token: ' . $response->get_error_message());
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            NABI_BACKUP_Logger::error('Error HTTP al refrescar token: ' . $response_code);
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!$data || !isset($data['access_token'])) {
            return false;
        }

        // Actualizar tokens
        update_option(self::OPTION_ACCOUNT_TOKEN, $data['access_token']);
        if (isset($data['refresh_token'])) {
            update_option(self::OPTION_ACCOUNT_REFRESH_TOKEN, $data['refresh_token']);
        }

        $expires_in = isset($data['expires_in']) ? (int) $data['expires_in'] : 3600;
        update_option(self::OPTION_ACCOUNT_EXPIRES_AT, time() + $expires_in);

        NABI_BACKUP_Logger::info('Token de cuenta refrescado exitosamente');
        return true;
    }

    /**
     * Desconecta la cuenta
     */
    public static function disconnect_account()
    {
        // Notificar al servidor (opcional)
        $token = get_option(self::OPTION_ACCOUNT_TOKEN);
        if ($token) {
            wp_remote_post(self::API_BASE_URL . '/user/disconnect', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'User-Agent' => 'Nabi-Backup-Plugin/' . NABI_BACKUP_VERSION
                ],
                'timeout' => 5,
                'sslverify' => true
            ]);
        }

        // Eliminar datos de la cuenta
        delete_option(self::OPTION_ACCOUNT_TOKEN);
        delete_option(self::OPTION_ACCOUNT_REFRESH_TOKEN);
        delete_option(self::OPTION_ACCOUNT_VERSION);
        delete_option(self::OPTION_ACCOUNT_CONNECTED);
        delete_option(self::OPTION_ACCOUNT_USER_ID);
        delete_option(self::OPTION_ACCOUNT_USER_EMAIL);
        delete_option(self::OPTION_ACCOUNT_EXPIRES_AT);

        NABI_BACKUP_Logger::info('Cuenta desconectada');
    }

    /**
     * Verifica la conexión de cuenta con el servidor
     */
    public static function verify_account_connection()
    {
        if (!self::is_account_connected()) {
            return false;
        }

        $token = self::get_account_token();
        $response = wp_remote_get(self::API_BASE_URL . '/user/verify', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'User-Agent' => 'Nabi-Backup-Plugin/' . NABI_BACKUP_VERSION
            ],
            'timeout' => 10,
            'sslverify' => true
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code === 401) {
            // Token inválido, intentar refrescar
            if (self::refresh_account_token()) {
                return true;
            }
            // Si no se puede refrescar, desconectar
            self::disconnect_account();
            return false;
        }

        return $response_code === 200;
    }

    /**
     * Obtiene el límite de backups según la versión
     */
    public static function get_max_backups()
    {
        $version = self::get_version();

        switch ($version) {
            case self::VERSION_PRO:
                return self::MAX_BACKUPS_PRO;
            case self::VERSION_ULTRA:
                return self::MAX_BACKUPS_ULTRA;
            default:
                return self::MAX_BACKUPS_FREE;
        }
    }

    /**
     * Verifica si se puede crear un nuevo backup
     */
    public static function can_create_backup()
    {
        // En esta versión no hay límites más que el espacio en disco (verificado en Export)
        return true;
    }

    /**
     * Cuenta los backups válidos (con token correcto)
     */
    public static function count_valid_backups()
    {
        $backup_dir = ABSPATH . 'wp-content/Nabibck';
        $token = self::get_token();
        $count = 0;

        if (!is_dir($backup_dir)) {
            return 0;
        }

        $files = glob($backup_dir . '/Nabi-backup-*.zip');

        if (!$files) {
            return 0;
        }

        foreach ($files as $file) {
            // Validar que el backup tenga el token correcto
            if (self::validate_backup_token($file, $token)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Valida que un backup tenga el token correcto
     */
    public static function validate_backup_token($backup_file, $expected_token = null)
    {
        if (!$expected_token) {
            $expected_token = self::get_token();
        }

        if (!file_exists($backup_file)) {
            return false;
        }

        $zip = new ZipArchive();
        if ($zip->open($backup_file) !== TRUE) {
            return false;
        }

        // Buscar archivo de información
        $info_file = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            if (basename($filename) === 'backup-info.json') {
                $info_file = $filename;
                break;
            }
        }

        if (!$info_file) {
            $zip->close();
            return false;
        }

        // Leer información del backup
        $info_content = $zip->getFromName($info_file);
        $backup_info = json_decode($info_content, true);

        $zip->close();

        if (!$backup_info || !isset($backup_info['token'])) {
            return false;
        }

        // Verificar que el token coincida
        return hash_equals($expected_token, $backup_info['token']);
    }

    /**
     * Obtiene la lista de backups válidos (con token correcto)
     */
    public static function get_valid_backups()
    {
        $backup_dir = ABSPATH . 'wp-content/Nabibck';
        $token = self::get_token();
        $valid_backups = [];

        if (!is_dir($backup_dir)) {
            return $valid_backups;
        }

        $files = glob($backup_dir . '/Nabi-backup-*.zip');

        if (!$files) {
            return $valid_backups;
        }

        foreach ($files as $file) {
            if (self::validate_backup_token($file, $token)) {
                $valid_backups[] = $file;
            }
        }

        // Ordenar por fecha (más reciente primero)
        usort($valid_backups, function ($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        return $valid_backups;
    }

    /**
     * Elimina backups antiguos si se excede el límite
     */
    public static function cleanup_old_backups()
    {
        // No se eliminan backups automáticamente para dar total control al espacio en hosting
        return;
    }

    /**
     * Verifica si tiene copias automáticas habilitadas
     */
    public static function has_auto_backups()
    {
        return self::get_version() === self::VERSION_ULTRA && self::is_account_connected();
    }

    /**
     * Obtiene la configuración de copias automáticas
     */
    public static function get_auto_backup_config()
    {
        if (!self::has_auto_backups()) {
            return null;
        }

        return get_option('NABI_BACKUP_auto_config', [
            'enabled' => false,
            'frequency' => 'daily', // daily, weekly, monthly
            'time' => '02:00' // Hora en formato HH:mm
        ]);
    }

    /**
     * Guarda la configuración de copias automáticas
     */
    public static function save_auto_backup_config($config)
    {
        if (!self::has_auto_backups()) {
            return false;
        }

        update_option('NABI_BACKUP_auto_config', $config);
        return true;
    }
}


