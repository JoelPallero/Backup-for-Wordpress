<?php
defined('ABSPATH') || exit;

class NABI_BACKUP_Admin
{

    public static function init()
    {
        add_action('admin_menu', [__CLASS__, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('admin_post_NABI_BACKUP_download', [__CLASS__, 'handle_download']);
        add_action('admin_init', [__CLASS__, 'handle_oauth_callback']);
    }

    /**
     * Maneja el callback de OAuth
     */
    public static function handle_oauth_callback()
    {
        // Verificar que estamos en la página correcta
<<<<<<< HEAD
        if (!isset($_GET['page']) || $_GET['page'] !== 'Nabi-backup') {
=======
        if (!isset($_GET['page']) || $_GET['page'] !== 'nabi-backup') {
>>>>>>> 642dd96 (Standardization of Nabi ecosystem and slug refactoring v1.0.1)
            return;
        }

        if (!isset($_GET['action']) || $_GET['action'] !== 'oauth_callback') {
            return;
        }

        // Verificar permisos
        if (!current_user_can('manage_options')) {
            wp_die(__('No tienes permisos para realizar esta acción', 'Nabi-backup'));
        }

        require_once NABI_BACKUP_PATH . 'includes/class-Nabi-backup-license.php';

        $code = isset($_GET['code']) ? sanitize_text_field($_GET['code']) : '';
        $state = isset($_GET['state']) ? sanitize_text_field($_GET['state']) : '';
        $error = isset($_GET['error']) ? sanitize_text_field($_GET['error']) : '';

        if (!empty($error)) {
            $error_message = isset($_GET['error_description']) ? sanitize_text_field($_GET['error_description']) : __('Error en la autorización', 'Nabi-backup');
            add_action('admin_notices', function () use ($error_message) {
                echo '<div class="notice notice-error"><p>' . esc_html($error_message) . '</p></div>';
            });
            return;
        }

        if (empty($code) || empty($state)) {
            add_action('admin_notices', function () {
                echo '<div class="notice notice-error"><p>' . esc_html__('Código de autorización inválido', 'Nabi-backup') . '</p></div>';
            });
            return;
        }

        // Procesar callback
        $result = NABI_BACKUP_License::process_oauth_callback($code, $state);

        if ($result['success']) {
            // Reprogramar backups automáticos si es Ultra
            if (isset($result['version']) && $result['version'] === NABI_BACKUP_License::VERSION_ULTRA) {
                require_once NABI_BACKUP_PATH . 'includes/class-Nabi-backup-scheduler.php';
                NABI_BACKUP_Scheduler::schedule_backup();
            }

            add_action('admin_notices', function () {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Cuenta conectada exitosamente', 'Nabi-backup') . '</p></div>';
            });
        } else {
            add_action('admin_notices', function () use ($result) {
                $message = $result['message'] ?? __('Error al conectar la cuenta', 'Nabi-backup');
                echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
            });
        }

        // Redirigir a la página del plugin sin parámetros
        wp_safe_redirect(admin_url('admin.php?page=Nabi-backup'));
        exit;
    }

    /**
     * Agrega el menú de administración
     */
    public static function add_admin_menu()
    {
        if (!class_exists('NABI_Master')) {
            require_once NABI_BACKUP_PATH . 'includes/nabi-master/class-nabi-master.php';
        }

        NABI_Master::add_submenu(
<<<<<<< HEAD
            __('Nabi Backup', 'Nabi-backup'),
            __('Backup', 'Nabi-backup'),
            'Nabi-backup',
=======
            __('Nabi Backup', 'nabi-backup'),
            __('Backup', 'nabi-backup'),
            'nabi-backup',
>>>>>>> 642dd96 (Standardization of Nabi ecosystem and slug refactoring v1.0.1)
            [__CLASS__, 'render_admin_page']
        );
    }

    /**
     * Carga los assets del admin
     */
    public static function enqueue_assets($hook)
    {
        // Verificar si estamos en la página del plugin
        // El hook puede ser 'toplevel_page_Nabi-backup' o similar
<<<<<<< HEAD
        if (strpos($hook, 'Nabi-backup') === false && $hook !== 'toplevel_page_Nabi-backup') {
            error_log('Nabi Backup: enqueue_assets - Hook no coincide: ' . $hook);
=======
        if (strpos($hook, 'nabi-backup') === false) {
>>>>>>> 642dd96 (Standardization of Nabi ecosystem and slug refactoring v1.0.1)
            return;
        }

        error_log('Nabi Backup: Cargando assets para hook: ' . $hook);

        wp_enqueue_script(
            'Nabi-backup-admin',
            NABI_BACKUP_URL . 'assets/js/admin.js',
            ['jquery'],
            NABI_BACKUP_VERSION,
            true
        );

        wp_enqueue_style(
            'Nabi-backup-admin',
            NABI_BACKUP_URL . 'assets/css/admin.css',
            [],
            NABI_BACKUP_VERSION
        );

        wp_localize_script('Nabi-backup-admin', 'NabiBackup', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('NABI_BACKUP_nonce'),
            'strings' => [
                'exporting' => __('Exportando backup...', 'Nabi-backup'),
                'importing' => __('Importando backup...', 'Nabi-backup'),
                'validating' => __('Validando archivo...', 'Nabi-backup'),
                'success' => __('Operación completada exitosamente', 'Nabi-backup'),
                'error' => __('Ocurrió un error', 'Nabi-backup'),
                'select_file' => __('Selecciona un archivo ZIP de backup', 'Nabi-backup'),
                'drag_drop' => __('Arrastra y suelta el archivo aquí', 'Nabi-backup'),
                'connect_confirm' => __('¿Deseas conectar tu cuenta para activar las funciones Pro/Ultra?', 'Nabi-backup'),
                'disconnect_confirm' => __('¿Estás seguro de que deseas desconectar tu cuenta?', 'Nabi-backup'),
                'delete_confirm' => __('¿Estás seguro de que deseas eliminar este backup? Esta acción no se puede deshacer.', 'Nabi-backup')
            ]
        ]);
    }

    /**
     * Renderiza la página de administración
     */
    public static function render_admin_page()
    {
        require_once NABI_BACKUP_PATH . 'includes/class-Nabi-backup-license.php';

        $version = NABI_BACKUP_License::get_version();
        $is_connected = NABI_BACKUP_License::is_account_connected();
        $account_info = NABI_BACKUP_License::get_account_info();
        $max_backups = NABI_BACKUP_License::get_max_backups();
        $current_backups = NABI_BACKUP_License::count_valid_backups();

        ?>
        <div class="wrap Nabi-backup-wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <?php if (false): // Sección de Versión/Cuenta ocultada por solicitud del usuario ?>
            <div class="Nabi-backup-account-section"
                style="background: #fff; border: 1px solid #ccd0d4; border-left: 4px solid #2271b1; padding: 15px 20px; margin: 20px 0; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap;">
                    <div>
                        <h2 style="margin: 0 0 10px 0; font-size: 18px;">
                            <?php
                            if ($version === NABI_BACKUP_License::VERSION_FREE) {
                                echo '<span style="color: #646970;">' . esc_html__('Versión Free', 'Nabi-backup') . '</span>';
                            } elseif ($version === NABI_BACKUP_License::VERSION_PRO) {
                                echo '<span style="color: #2271b1;">' . esc_html__('Versión Pro', 'Nabi-backup') . '</span>';
                            } else {
                                echo '<span style="color: #d63638;">' . esc_html__('Versión Ultra', 'Nabi-backup') . '</span>';
                            }
                            ?>
                        </h2>
                        <p style="margin: 0; color: #646970;">
                            <?php
                            if ($max_backups === -1) {
                                printf(
                                    __('Backups: Ilimitados | Actuales: %d', 'Nabi-backup'),
                                    $current_backups
                                );
                            } else {
                                printf(
                                    __('Límite: %d backups | Actuales: %d', 'Nabi-backup'),
                                    $max_backups,
                                    $current_backups
                                );
                            }
                            ?>
                        </p>
                        <?php if ($is_connected && $account_info): ?>
                            <p style="margin: 5px 0 0 0; color: #646970; font-size: 13px;">
                                <?php printf(
                                    __('Cuenta conectada: %s', 'Nabi-backup'),
                                    esc_html($account_info['user_email'])
                                ); ?>
                            </p>
                        <?php endif; ?>
                    </div>
                    <div style="display: flex; gap: 10px; margin-top: 10px;">
                        <?php if (!$is_connected): ?>
                            <?php if ($version === NABI_BACKUP_License::VERSION_FREE): ?>
                                <a href="https://joelpallero.com.ar/productos/Nabi-backup-pro" target="_blank"
                                    class="button button-secondary" style="text-decoration: none;">
                                    <?php _e('Actualizar a Pro', 'Nabi-backup'); ?>
                                </a>
                                <a href="https://joelpallero.com.ar/productos/Nabi-backup-ultra" target="_blank"
                                    class="button button-secondary" style="text-decoration: none;">
                                    <?php _e('Actualizar a Ultra', 'Nabi-backup'); ?>
                                </a>
                            <?php else: ?>
                                <button type="button" id="Nabi-backup-connect-account" class="button button-primary">
                                    <?php _e('Conectar Cuenta', 'Nabi-backup'); ?>
                                </button>
                            <?php endif; ?>
                        <?php else: ?>
                            <button type="button" id="Nabi-backup-disconnect-account" class="button button-secondary">
                                <?php _e('Desconectar Cuenta', 'Nabi-backup'); ?>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="Nabi-backup-section" style="margin-bottom: 30px;">
                <div class="Nabi-backup-card">
                    <h2><?php _e('Backups', 'Nabi-backup'); ?></h2>
                    <div id="Nabi-backup-list-container">
                        <div class="Nabi-backup-loading" style="text-align: center; padding: 20px;">
                            <span class="spinner is-active" style="float: none; margin: 0 auto;"></span>
                            <p><?php _e('Cargando backups...', 'Nabi-backup'); ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="Nabi-backup-tabs">
                <nav class="nav-tab-wrapper">
                    <a href="#export" class="nav-tab nav-tab-active"><?php _e('Nuevo Backup', 'Nabi-backup'); ?></a>
                    <a href="#import" class="nav-tab"><?php _e('Importar', 'Nabi-backup'); ?></a>
                    <a href="#settings" class="nav-tab"><?php _e('Configuración', 'Nabi-backup'); ?></a>
                </nav>

                <div class="Nabi-backup-tab-content">
                    <!-- Pestaña Exportar -->
                    <!-- Pestaña Exportar -->
                    <div id="export-tab" class="tab-pane active">
                        <div class="Nabi-backup-card">
                            <h2><?php _e('Crear Nuevo Backup', 'Nabi-backup'); ?></h2>

                            <div class="Nabi-backup-export-actions">
                                <button id="Nabi-backup-export-btn" class="button button-primary button-large">
                                    <?php _e('iniciar backup', 'Nabi-backup'); ?>
                                </button>
                            </div>
 drum

                            <div id="Nabi-backup-export-progress" class="Nabi-backup-progress" style="display: none;">
                                <div class="Nabi-backup-progress-bar">
                                    <div class="Nabi-backup-progress-fill"></div>
                                </div>
                                <div id="Nabi-backup-progress-details" class="Nabi-backup-progress-details"></div>
                                <p class="Nabi-backup-progress-text"></p>
                            </div>

                            <div id="Nabi-backup-export-result" class="Nabi-backup-result"></div>
                        </div>
                    </div>


                    <!-- Pestaña Importar -->
                    <div id="import-tab" class="tab-pane">
                        <div class="Nabi-backup-card">
                            <h2><?php _e('Restaurar Backup', 'Nabi-backup'); ?></h2>
                            <p><?php _e('Restaura un backup completo de tu sitio WordPress. El proceso reemplazará el contenido actual.', 'Nabi-backup'); ?>
                            </p>

                            <div class="Nabi-backup-import-area">
                                <div id="Nabi-backup-dropzone" class="Nabi-backup-dropzone">
                                    <div class="Nabi-backup-dropzone-content">
                                        <span class="dashicons dashicons-cloud-upload"></span>
                                        <p class="Nabi-backup-dropzone-text">
                                            <?php _e('Arrastra y suelta el archivo de backup (.Nabi o .zip) aquí', 'Nabi-backup'); ?></p>
                                        <p class="Nabi-backup-dropzone-or"><?php _e('o', 'Nabi-backup'); ?></p>
                                        <button type="button" class="button" id="Nabi-backup-select-file">
                                            <?php _e('Seleccionar Archivo', 'Nabi-backup'); ?>
                                        </button>
                                        <input type="file" id="Nabi-backup-file-input" accept=".zip,.Nabi" style="display: none;">
                                    </div>
                                </div>

                                <div id="Nabi-backup-file-info" class="Nabi-backup-file-info" style="display: none;">
                                    <div class="Nabi-backup-file-details">
                                        <span class="dashicons dashicons-media-archive"></span>
                                        <div class="Nabi-backup-file-name"></div>
                                        <button type="button" class="button-link Nabi-backup-remove-file">
                                            <?php _e('Eliminar', 'Nabi-backup'); ?>
                                        </button>
                                    </div>
                                    <div class="Nabi-backup-file-meta"></div>
                                </div>

                                <div class="Nabi-backup-import-actions" style="display: none;">
                                    <button id="Nabi-backup-import-btn" class="button button-primary button-large">
                                        <?php _e('Iniciar Importación', 'Nabi-backup'); ?>
                                    </button>
                                </div>
                            </div>

                            <div id="Nabi-backup-import-progress" class="Nabi-backup-progress" style="display: none;">
                                <div class="Nabi-backup-progress-bar">
                                    <div class="Nabi-backup-progress-fill"></div>
                                </div>
                                <p class="Nabi-backup-progress-text"></p>
                            </div>

                            <div id="Nabi-backup-import-result" class="Nabi-backup-result"></div>
                        </div>
                    </div>

                    <!-- Pestaña Configuración -->
                    <div id="settings-tab" class="tab-pane">
                        <div class="Nabi-backup-card">
                            <h2><?php _e('Configuración de Backup', 'Nabi-backup'); ?></h2>
                            <p><?php _e('Selecciona qué elementos incluir en tus backups.', 'Nabi-backup'); ?></p>

                            <?php
                            require_once NABI_BACKUP_PATH . 'includes/class-Nabi-backup-settings.php';
                            $settings = NABI_BACKUP_Settings::get_settings();
                            ?>

                            <form id="Nabi-backup-settings-form">
                                <table class="form-table">
                                    <tbody>
                                        <tr>
                                            <th scope="row"><?php _e('Incluir en Backup', 'Nabi-backup'); ?></th>
                                            <td>
                                                <fieldset>
                                                    <label>
                                                        <input type="checkbox" name="include_database" value="1" <?php checked($settings['include_database'], true); ?>>
                                                        <?php _e('Base de datos', 'Nabi-backup'); ?>
                                                    </label><br>
                                                    <label>
                                                        <input type="checkbox" name="include_media" value="1" <?php checked($settings['include_media'], true); ?>>
                                                        <?php _e('Archivos multimedia', 'Nabi-backup'); ?>
                                                    </label><br>
                                                    <label>
                                                        <input type="checkbox" name="include_uploads" value="1" <?php checked($settings['include_uploads'], true); ?>>
                                                        <?php _e('Carpeta uploads', 'Nabi-backup'); ?>
                                                    </label><br>
                                                    <label>
                                                        <input type="checkbox" name="include_plugins" value="1" <?php checked($settings['include_plugins'], true); ?>>
                                                        <?php _e('Plugins', 'Nabi-backup'); ?>
                                                    </label><br>
                                                    <label>
                                                        <input type="checkbox" name="include_themes" value="1" <?php checked($settings['include_themes'], true); ?>>
                                                        <?php _e('Temas', 'Nabi-backup'); ?>
                                                    </label><br>
                                                    <label>
                                                        <input type="checkbox" name="include_posts" value="1" <?php checked($settings['include_posts'], true); ?>>
                                                        <?php _e('Entradas', 'Nabi-backup'); ?>
                                                    </label><br>
                                                    <label>
                                                        <input type="checkbox" name="include_pages" value="1" <?php checked($settings['include_pages'], true); ?>>
                                                        <?php _e('Páginas', 'Nabi-backup'); ?>
                                                    </label><br>
                                                    <label>
                                                        <input type="checkbox" name="include_comments" value="1" <?php checked($settings['include_comments'], true); ?>>
                                                        <?php _e('Comentarios', 'Nabi-backup'); ?>
                                                    </label><br>
                                                    <label>
                                                        <input type="checkbox" name="include_users" value="1" <?php checked($settings['include_users'], true); ?>>
                                                        <?php _e('Usuarios', 'Nabi-backup'); ?>
                                                    </label><br>
                                                    <label>
                                                        <input type="checkbox" name="exclude_other_backups" value="1" <?php checked($settings['exclude_other_backups'], true); ?>>
                                                        <strong><?php _e('Excluir backups de otros plugins (AIO, Updraft, etc.)', 'Nabi-backup'); ?></strong>
                                                    </label>
                                                </fieldset>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>

                                <p class="submit">
                                    <button type="submit" class="button button-primary">
                                        <?php _e('Guardar Configuración', 'Nabi-backup'); ?>
                                    </button>
                                </p>

                                <div id="Nabi-backup-settings-result" class="Nabi-backup-result"></div>
                            </form>

                            <?php if ($is_connected): ?>
                                <hr style="margin: 30px 0;">

                                <h3><?php _e('Conexión OAuth', 'Nabi-backup'); ?></h3>
                                <p><?php _e('Prueba la conexión con el servidor OAuth para verificar que todo funciona correctamente.', 'Nabi-backup'); ?>
                                </p>

                                <p>
                                    <button type="button" id="Nabi-backup-test-connection" class="button button-secondary">
                                        <?php _e('Probar Conexión', 'Nabi-backup'); ?>
                                    </button>
                                </p>

                                <div id="Nabi-backup-test-result" class="Nabi-backup-result" style="display: none;"></div>
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
    public static function handle_download()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('No tienes permisos para realizar esta acción', 'Nabi-backup'));
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
            error_log('Nabi Backup Download Error - No filename found. GET: ' . print_r($_GET, true) . ' REQUEST: ' . print_r($_REQUEST, true) . ' QUERY_STRING: ' . (isset($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : 'N/A'));
            wp_die(__('Archivo no especificado', 'Nabi-backup'));
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

        if (empty($nonce) || !wp_verify_nonce($nonce, 'NABI_BACKUP_download_' . $filename)) {
            error_log('Nabi Backup Download Error - Invalid nonce. Filename: ' . $filename . ' Nonce: ' . ($nonce ?: 'empty'));
            wp_die(__('Enlace de descarga no válido', 'Nabi-backup'));
        }

        $file_path = ABSPATH . 'wp-content/Nabibck/' . $filename;

        if (!file_exists($file_path)) {
            wp_die(__('El archivo no existe', 'Nabi-backup') . ': ' . esc_html($file_path));
        }

        // Verificar que el archivo está dentro del directorio de backups (seguridad)
        $real_backup_dir = realpath(ABSPATH . 'wp-content/Nabibck');
        $real_file_path = realpath($file_path);

        if (!$real_backup_dir || !$real_file_path || strpos($real_file_path, $real_backup_dir) !== 0) {
            wp_die(__('Ruta de archivo no válida', 'Nabi-backup'));
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


