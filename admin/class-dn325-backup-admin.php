<?php
defined('ABSPATH') || exit;

class DN325_Backup_Admin {

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('admin_post_dn325_backup_download', [__CLASS__, 'handle_download']);
        add_action('admin_init', [__CLASS__, 'handle_oauth_callback']);
    }
    
    /**
     * Maneja el callback de OAuth
     */
    public static function handle_oauth_callback() {
        // Verificar que estamos en la página correcta
        if (!isset($_GET['page']) || $_GET['page'] !== 'dn325-backup') {
            return;
        }
        
        if (!isset($_GET['action']) || $_GET['action'] !== 'oauth_callback') {
            return;
        }
        
        // Verificar permisos
        if (!current_user_can('manage_options')) {
            wp_die(__('No tienes permisos para realizar esta acción', 'dn325-backup'));
        }
        
        require_once DN325_BACKUP_PATH . 'includes/class-dn325-backup-license.php';
        
        $code = isset($_GET['code']) ? sanitize_text_field($_GET['code']) : '';
        $state = isset($_GET['state']) ? sanitize_text_field($_GET['state']) : '';
        $error = isset($_GET['error']) ? sanitize_text_field($_GET['error']) : '';
        
        if (!empty($error)) {
            $error_message = isset($_GET['error_description']) ? sanitize_text_field($_GET['error_description']) : __('Error en la autorización', 'dn325-backup');
            add_action('admin_notices', function() use ($error_message) {
                echo '<div class="notice notice-error"><p>' . esc_html($error_message) . '</p></div>';
            });
            return;
        }
        
        if (empty($code) || empty($state)) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>' . esc_html__('Código de autorización inválido', 'dn325-backup') . '</p></div>';
            });
            return;
        }
        
        // Procesar callback
        $result = DN325_Backup_License::process_oauth_callback($code, $state);
        
        if ($result['success']) {
            // Reprogramar backups automáticos si es Ultra
            if (isset($result['version']) && $result['version'] === DN325_Backup_License::VERSION_ULTRA) {
                require_once DN325_BACKUP_PATH . 'includes/class-dn325-backup-scheduler.php';
                DN325_Backup_Scheduler::schedule_backup();
            }
            
            add_action('admin_notices', function() {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Cuenta conectada exitosamente', 'dn325-backup') . '</p></div>';
            });
        } else {
            add_action('admin_notices', function() use ($result) {
                $message = $result['message'] ?? __('Error al conectar la cuenta', 'dn325-backup');
                echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
            });
        }
        
        // Redirigir a la página del plugin sin parámetros
        wp_safe_redirect(admin_url('admin.php?page=dn325-backup'));
        exit;
    }

    /**
     * Agrega el menú de administración
     */
    public static function add_admin_menu() {
        require_once DN325_BACKUP_PATH . 'includes/class-dn325-menu.php';
        
        DN325_Menu::add_submenu(
            __('DN325 Backup', 'dn325-backup'),
            __('Backup', 'dn325-backup'),
            'dn325-backup',
            [__CLASS__, 'render_admin_page']
        );
    }

    /**
     * Carga los assets del admin
     */
    public static function enqueue_assets($hook) {
        // Verificar si estamos en la página del plugin
        // El hook puede ser 'toplevel_page_dn325-backup' o similar
        if (strpos($hook, 'dn325-backup') === false && $hook !== 'toplevel_page_dn325-backup') {
            error_log('DN325 Backup: enqueue_assets - Hook no coincide: ' . $hook);
            return;
        }
        
        error_log('DN325 Backup: Cargando assets para hook: ' . $hook);

        wp_enqueue_script(
            'dn325-backup-admin',
            DN325_BACKUP_URL . 'assets/js/admin.js',
            ['jquery'],
            DN325_BACKUP_VERSION,
            true
        );

        wp_enqueue_style(
            'dn325-backup-admin',
            DN325_BACKUP_URL . 'assets/css/admin.css',
            [],
            DN325_BACKUP_VERSION
        );

        wp_localize_script('dn325-backup-admin', 'dn325Backup', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('dn325_backup_nonce'),
            'strings' => [
                'exporting' => __('Exportando backup...', 'dn325-backup'),
                'importing' => __('Importando backup...', 'dn325-backup'),
                'validating' => __('Validando archivo...', 'dn325-backup'),
                'success' => __('Operación completada exitosamente', 'dn325-backup'),
                'error' => __('Ocurrió un error', 'dn325-backup'),
                'select_file' => __('Selecciona un archivo ZIP de backup', 'dn325-backup'),
                'drag_drop' => __('Arrastra y suelta el archivo aquí', 'dn325-backup'),
                'connect_confirm' => __('¿Deseas conectar tu cuenta para activar las funciones Pro/Ultra?', 'dn325-backup'),
                'disconnect_confirm' => __('¿Estás seguro de que deseas desconectar tu cuenta?', 'dn325-backup'),
                'delete_confirm' => __('¿Estás seguro de que deseas eliminar este backup? Esta acción no se puede deshacer.', 'dn325-backup')
            ]
        ]);
    }

    /**
     * Renderiza la página de administración
     */
    public static function render_admin_page() {
        require_once DN325_BACKUP_PATH . 'includes/class-dn325-backup-license.php';
        
        $version = DN325_Backup_License::get_version();
        $is_connected = DN325_Backup_License::is_account_connected();
        $account_info = DN325_Backup_License::get_account_info();
        $max_backups = DN325_Backup_License::get_max_backups();
        $current_backups = DN325_Backup_License::count_valid_backups();
        
        ?>
        <div class="wrap dn325-backup-wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <!-- Sección de Versión/Cuenta -->
            <div class="dn325-backup-account-section" style="background: #fff; border: 1px solid #ccd0d4; border-left: 4px solid #2271b1; padding: 15px 20px; margin: 20px 0; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap;">
                    <div>
                        <h2 style="margin: 0 0 10px 0; font-size: 18px;">
                            <?php 
                            if ($version === DN325_Backup_License::VERSION_FREE) {
                                echo '<span style="color: #646970;">' . esc_html__('Versión Free', 'dn325-backup') . '</span>';
                            } elseif ($version === DN325_Backup_License::VERSION_PRO) {
                                echo '<span style="color: #2271b1;">' . esc_html__('Versión Pro', 'dn325-backup') . '</span>';
                            } else {
                                echo '<span style="color: #d63638;">' . esc_html__('Versión Ultra', 'dn325-backup') . '</span>';
                            }
                            ?>
                        </h2>
                        <p style="margin: 0; color: #646970;">
                            <?php 
                            if ($version === DN325_Backup_License::VERSION_FREE) {
                                printf(
                                    __('Límite: %d backup | Actuales: %d', 'dn325-backup'),
                                    DN325_Backup_License::MAX_BACKUPS_FREE,
                                    $current_backups
                                );
                            } elseif ($version === DN325_Backup_License::VERSION_PRO) {
                                printf(
                                    __('Límite: %d backups | Actuales: %d', 'dn325-backup'),
                                    DN325_Backup_License::MAX_BACKUPS_PRO,
                                    $current_backups
                                );
                            } else {
                                printf(
                                    __('Backups: Ilimitados | Actuales: %d', 'dn325-backup'),
                                    $current_backups
                                );
                            }
                            ?>
                        </p>
                        <?php if ($is_connected && $account_info): ?>
                            <p style="margin: 5px 0 0 0; color: #646970; font-size: 13px;">
                                <?php printf(
                                    __('Cuenta conectada: %s', 'dn325-backup'),
                                    esc_html($account_info['user_email'])
                                ); ?>
                            </p>
                        <?php endif; ?>
                    </div>
                    <div style="display: flex; gap: 10px; margin-top: 10px;">
                        <?php if (!$is_connected): ?>
                            <?php if ($version === DN325_Backup_License::VERSION_FREE): ?>
                                <a href="https://joelpallero.com.ar/productos/dn325-backup-pro" 
                                   target="_blank" 
                                   class="button button-secondary" 
                                   style="text-decoration: none;">
                                    <?php _e('Actualizar a Pro', 'dn325-backup'); ?>
                                </a>
                                <a href="https://joelpallero.com.ar/productos/dn325-backup-ultra" 
                                   target="_blank" 
                                   class="button button-secondary" 
                                   style="text-decoration: none;">
                                    <?php _e('Actualizar a Ultra', 'dn325-backup'); ?>
                                </a>
                            <?php else: ?>
                                <button type="button" 
                                        id="dn325-backup-connect-account" 
                                        class="button button-primary">
                                    <?php _e('Conectar Cuenta', 'dn325-backup'); ?>
                                </button>
                            <?php endif; ?>
                        <?php else: ?>
                            <button type="button" 
                                    id="dn325-backup-disconnect-account" 
                                    class="button button-secondary">
                                <?php _e('Desconectar Cuenta', 'dn325-backup'); ?>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="dn325-backup-tabs">
                <nav class="nav-tab-wrapper">
                    <a href="#export" class="nav-tab nav-tab-active"><?php _e('Exportar', 'dn325-backup'); ?></a>
                    <a href="#restore" class="nav-tab"><?php _e('Restaurar', 'dn325-backup'); ?></a>
                    <a href="#import" class="nav-tab"><?php _e('Importar', 'dn325-backup'); ?></a>
                    <a href="#settings" class="nav-tab"><?php _e('Configuración', 'dn325-backup'); ?></a>
                </nav>

                <div class="dn325-backup-tab-content">
                    <!-- Pestaña Exportar -->
                    <div id="export-tab" class="tab-pane active">
                        <div class="dn325-backup-card">
                            <h2><?php _e('Crear Backup Completo', 'dn325-backup'); ?></h2>
                            
                            <div class="dn325-backup-export-actions">
                                <button id="dn325-backup-export-btn" class="button button-primary button-large">
                                    <?php _e('Iniciar Exportación', 'dn325-backup'); ?>
                                </button>
                            </div>

                            <div id="dn325-backup-export-progress" class="dn325-backup-progress" style="display: none;">
                                <div class="dn325-backup-progress-bar">
                                    <div class="dn325-backup-progress-fill"></div>
                                </div>
                                <div id="dn325-backup-progress-details" class="dn325-backup-progress-details"></div>
                                <p class="dn325-backup-progress-text"></p>
                            </div>

                            <div id="dn325-backup-export-result" class="dn325-backup-result"></div>
                        </div>
                    </div>

                    <!-- Pestaña Restaurar -->
                    <div id="restore-tab" class="tab-pane">
                        <div class="dn325-backup-card">
                            <h2><?php _e('Backups Guardados', 'dn325-backup'); ?></h2>
                            <p><?php _e('Selecciona un backup guardado en el servidor para restaurarlo.', 'dn325-backup'); ?></p>
                            
                            <div id="dn325-backup-list-container">
                                <div class="dn325-backup-loading" style="text-align: center; padding: 20px;">
                                    <span class="spinner is-active" style="float: none; margin: 0 auto;"></span>
                                    <p><?php _e('Cargando backups...', 'dn325-backup'); ?></p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Pestaña Importar -->
                    <div id="import-tab" class="tab-pane">
                        <div class="dn325-backup-card">
                            <h2><?php _e('Restaurar Backup', 'dn325-backup'); ?></h2>
                            <p><?php _e('Restaura un backup completo de tu sitio WordPress. El proceso reemplazará el contenido actual.', 'dn325-backup'); ?></p>
                            
                            <div class="dn325-backup-import-area">
                                <div id="dn325-backup-dropzone" class="dn325-backup-dropzone">
                                    <div class="dn325-backup-dropzone-content">
                                        <span class="dashicons dashicons-cloud-upload"></span>
                                        <p class="dn325-backup-dropzone-text"><?php _e('Arrastra y suelta el archivo ZIP aquí', 'dn325-backup'); ?></p>
                                        <p class="dn325-backup-dropzone-or"><?php _e('o', 'dn325-backup'); ?></p>
                                        <button type="button" class="button" id="dn325-backup-select-file">
                                            <?php _e('Seleccionar Archivo', 'dn325-backup'); ?>
                                        </button>
                                        <input type="file" id="dn325-backup-file-input" accept=".zip" style="display: none;">
                                    </div>
                                </div>

                                <div id="dn325-backup-file-info" class="dn325-backup-file-info" style="display: none;">
                                    <div class="dn325-backup-file-details">
                                        <span class="dashicons dashicons-media-archive"></span>
                                        <div class="dn325-backup-file-name"></div>
                                        <button type="button" class="button-link dn325-backup-remove-file">
                                            <?php _e('Eliminar', 'dn325-backup'); ?>
                                        </button>
                                    </div>
                                    <div class="dn325-backup-file-meta"></div>
                                </div>

                                <div class="dn325-backup-import-actions" style="display: none;">
                                    <button id="dn325-backup-import-btn" class="button button-primary button-large">
                                        <?php _e('Iniciar Importación', 'dn325-backup'); ?>
                                    </button>
                                </div>
                            </div>

                            <div id="dn325-backup-import-progress" class="dn325-backup-progress" style="display: none;">
                                <div class="dn325-backup-progress-bar">
                                    <div class="dn325-backup-progress-fill"></div>
                                </div>
                                <p class="dn325-backup-progress-text"></p>
                            </div>

                            <div id="dn325-backup-import-result" class="dn325-backup-result"></div>
                        </div>
                    </div>

                    <!-- Pestaña Configuración -->
                    <div id="settings-tab" class="tab-pane">
                        <div class="dn325-backup-card">
                            <h2><?php _e('Configuración de Backup', 'dn325-backup'); ?></h2>
                            <p><?php _e('Selecciona qué elementos incluir en tus backups.', 'dn325-backup'); ?></p>
                            
                            <?php
                            require_once DN325_BACKUP_PATH . 'includes/class-dn325-backup-settings.php';
                            $settings = DN325_Backup_Settings::get_settings();
                            ?>
                            
                            <form id="dn325-backup-settings-form">
                                <table class="form-table">
                                    <tbody>
                                        <tr>
                                            <th scope="row"><?php _e('Incluir en Backup', 'dn325-backup'); ?></th>
                                            <td>
                                                <fieldset>
                                                    <label>
                                                        <input type="checkbox" name="include_database" value="1" <?php checked($settings['include_database'], true); ?>>
                                                        <?php _e('Base de datos', 'dn325-backup'); ?>
                                                    </label><br>
                                                    <label>
                                                        <input type="checkbox" name="include_media" value="1" <?php checked($settings['include_media'], true); ?>>
                                                        <?php _e('Archivos multimedia', 'dn325-backup'); ?>
                                                    </label><br>
                                                    <label>
                                                        <input type="checkbox" name="include_uploads" value="1" <?php checked($settings['include_uploads'], true); ?>>
                                                        <?php _e('Carpeta uploads', 'dn325-backup'); ?>
                                                    </label><br>
                                                    <label>
                                                        <input type="checkbox" name="include_plugins" value="1" <?php checked($settings['include_plugins'], true); ?>>
                                                        <?php _e('Plugins', 'dn325-backup'); ?>
                                                    </label><br>
                                                    <label>
                                                        <input type="checkbox" name="include_themes" value="1" <?php checked($settings['include_themes'], true); ?>>
                                                        <?php _e('Temas', 'dn325-backup'); ?>
                                                    </label><br>
                                                    <label>
                                                        <input type="checkbox" name="include_posts" value="1" <?php checked($settings['include_posts'], true); ?>>
                                                        <?php _e('Entradas', 'dn325-backup'); ?>
                                                    </label><br>
                                                    <label>
                                                        <input type="checkbox" name="include_pages" value="1" <?php checked($settings['include_pages'], true); ?>>
                                                        <?php _e('Páginas', 'dn325-backup'); ?>
                                                    </label><br>
                                                    <label>
                                                        <input type="checkbox" name="include_comments" value="1" <?php checked($settings['include_comments'], true); ?>>
                                                        <?php _e('Comentarios', 'dn325-backup'); ?>
                                                    </label><br>
                                                    <label>
                                                        <input type="checkbox" name="include_users" value="1" <?php checked($settings['include_users'], true); ?>>
                                                        <?php _e('Usuarios', 'dn325-backup'); ?>
                                                    </label>
                                                </fieldset>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                                
                                <p class="submit">
                                    <button type="submit" class="button button-primary">
                                        <?php _e('Guardar Configuración', 'dn325-backup'); ?>
                                    </button>
                                </p>
                                
                                <div id="dn325-backup-settings-result" class="dn325-backup-result"></div>
                            </form>
                            
                            <?php if ($is_connected): ?>
                            <hr style="margin: 30px 0;">
                            
                            <h3><?php _e('Conexión OAuth', 'dn325-backup'); ?></h3>
                            <p><?php _e('Prueba la conexión con el servidor OAuth para verificar que todo funciona correctamente.', 'dn325-backup'); ?></p>
                            
                            <p>
                                <button type="button" id="dn325-backup-test-connection" class="button button-secondary">
                                    <?php _e('Probar Conexión', 'dn325-backup'); ?>
                                </button>
                            </p>
                            
                            <div id="dn325-backup-test-result" class="dn325-backup-result" style="display: none;"></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Maneja la descarga del archivo de backup
     */
    public static function handle_download() {
        if (!current_user_can('manage_options')) {
            wp_die(__('No tienes permisos para realizar esta acción', 'dn325-backup'));
        }

        // Obtener el parámetro file de diferentes formas posibles
        $filename = '';
        
        // Primero intentar desde $_GET directamente
        if (isset($_GET['file']) && !empty($_GET['file'])) {
            $filename = $_GET['file'];
        } 
        // Si no está en $_GET, intentar desde $_REQUEST
        elseif (isset($_REQUEST['file']) && !empty($_REQUEST['file'])) {
            $filename = $_REQUEST['file'];
        }
        // Si aún no está, parsear QUERY_STRING manualmente
        elseif (isset($_SERVER['QUERY_STRING']) && !empty($_SERVER['QUERY_STRING'])) {
            // Reemplazar &amp; por & para manejar codificación HTML
            $query_string = str_replace('&amp;', '&', $_SERVER['QUERY_STRING']);
            parse_str($query_string, $params);
            if (isset($params['file']) && !empty($params['file'])) {
                $filename = $params['file'];
            }
        }
        
        // Si aún no tenemos el filename, intentar desde REQUEST_URI
        if (empty($filename) && isset($_SERVER['REQUEST_URI'])) {
            $uri = $_SERVER['REQUEST_URI'];
            // Reemplazar &amp; por & 
            $uri = str_replace('&amp;', '&', $uri);
            $parsed = parse_url($uri);
            if (isset($parsed['query'])) {
                parse_str($parsed['query'], $params);
                if (isset($params['file']) && !empty($params['file'])) {
                    $filename = $params['file'];
                }
            }
        }

        // Decodificar si está codificado
        if (!empty($filename)) {
            $filename = urldecode($filename);
            $filename = sanitize_file_name($filename);
        }

        if (empty($filename)) {
            // Log para debug
            error_log('DN325 Backup Download Error - No filename found. GET: ' . print_r($_GET, true) . ' REQUEST: ' . print_r($_REQUEST, true) . ' QUERY_STRING: ' . (isset($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : 'N/A'));
            wp_die(__('Archivo no especificado', 'dn325-backup'));
        }

        // Obtener nonce de diferentes formas
        $nonce = '';
        if (isset($_GET['_wpnonce']) && !empty($_GET['_wpnonce'])) {
            $nonce = $_GET['_wpnonce'];
        } elseif (isset($_REQUEST['_wpnonce']) && !empty($_REQUEST['_wpnonce'])) {
            $nonce = $_REQUEST['_wpnonce'];
        } elseif (isset($_SERVER['QUERY_STRING']) && !empty($_SERVER['QUERY_STRING'])) {
            $query_string = str_replace('&amp;', '&', $_SERVER['QUERY_STRING']);
            parse_str($query_string, $params);
            if (isset($params['_wpnonce']) && !empty($params['_wpnonce'])) {
                $nonce = $params['_wpnonce'];
            }
        }

        if (empty($nonce) || !wp_verify_nonce($nonce, 'dn325_backup_download_' . $filename)) {
            error_log('DN325 Backup Download Error - Invalid nonce. Filename: ' . $filename . ' Nonce: ' . ($nonce ?: 'empty'));
            wp_die(__('Enlace de descarga no válido', 'dn325-backup'));
        }

        $file_path = ABSPATH . 'wp-content/dn325bck/' . $filename;

        if (!file_exists($file_path)) {
            wp_die(__('El archivo no existe', 'dn325-backup') . ': ' . esc_html($file_path));
        }

        // Verificar que el archivo está dentro del directorio de backups (seguridad)
        $real_backup_dir = realpath(ABSPATH . 'wp-content/dn325bck');
        $real_file_path = realpath($file_path);
        
        if (!$real_backup_dir || !$real_file_path || strpos($real_file_path, $real_backup_dir) !== 0) {
            wp_die(__('Ruta de archivo no válida', 'dn325-backup'));
        }

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($file_path));
        header('Pragma: no-cache');
        header('Expires: 0');

        readfile($file_path);
        exit;
    }
}
