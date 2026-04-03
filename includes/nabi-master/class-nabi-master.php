<?php
defined('ABSPATH') || exit;

/**
 * Nabi Master Feature
 * Feature central compartido que reemplaza a "Nabi Master" y "Nabi Plugins Pack".
 * Muestra el ecosistema de plugins e invita a descargar los faltantes.
 */
if (!class_exists('NABI_Master')) {
    class NABI_Master {

        private static $menu_created = false;
        private static $menu_slug = 'nabi-master';
        private static $menu_hook = null;

        /**
         * Inicializa la clase y crea el menú
         */
        public static function init() {
            add_action('admin_menu', [__CLASS__, 'create_main_menu'], 9);
        }

        /**
         * Crea el menú principal si no existe
         */
        public static function create_main_menu() {
            if (self::$menu_created) {
                return self::$menu_hook;
            }

            global $menu;
            if (isset($menu)) {
                foreach ($menu as $key => $item) {
                    if (isset($item[2]) && $item[2] === self::$menu_slug) {
                        unset($menu[$key]);
                    }
                }
            }
            
            self::$menu_hook = add_menu_page(
                __('Nabi Master', 'nabi-master'),
                __('Nabi', 'nabi-master'),
                'manage_options',
                self::$menu_slug,
                [__CLASS__, 'render_main_page'],
                'dashicons-admin-plugins', // Changed to dashicons
                30
            );

            // Cambiar logo para asemejarse al Nabi Master anterior
            add_action('admin_head', function() {
                echo '<style>
                    #toplevel_page_nabi-master .wp-menu-image::before {
                        content: "\f106" !important;
                        font-family: dashicons !important;
                        font-size: 20px !important;
                    }
                    .nabi-master-card {
                        background: #fff;
                        border: 1px solid #ccd0d4;
                        padding: 20px;
                        border-radius: 4px;
                        box-shadow: 0 1px 3px rgba(0,0,0,0.04);
                        margin-bottom: 20px;
                    }
                    .nabi-master-grid {
                        display: grid;
                        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
                        gap: 20px;
                        margin-top: 20px;
                    }
                    .nabi-plugin-card {
                        border: 1px solid #ddd;
                        border-radius: 4px;
                        background: #fff;
                        display: flex;
                        flex-direction: column;
                        transition: all 0.3s ease;
                    }
                    .nabi-plugin-card:hover {
                        box-shadow: 0 5px 15px rgba(0,0,0,0.1);
                        transform: translateY(-2px);
                    }
                    .nabi-plugin-card.active {
                        border-top: 4px solid #00a32a;
                    }
                    .nabi-plugin-card.inactive {
                        border-top: 4px solid #2271b1;
                    }
                    .nabi-plugin-header {
                        padding: 20px;
                        border-bottom: 1px solid #eee;
                    }
                    .nabi-plugin-body {
                        padding: 20px;
                        flex-grow: 1;
                    }
                    .nabi-plugin-footer {
                        padding: 15px 20px;
                        background: #f8f9fa;
                        border-top: 1px solid #eee;
                        text-align: right;
                    }
                </style>';
            }, 999);

            self::$menu_created = true;
            return self::$menu_hook;
        }

        /**
         * Agrega un submenú al menú principal
         */
        public static function add_submenu($page_title, $menu_title, $menu_slug, $callback) {
            self::create_main_menu();

            return add_submenu_page(
                self::$menu_slug,
                $page_title,
                $menu_title,
                'manage_options',
                $menu_slug,
                $callback
            );
        }

        /**
         * Renderiza la página principal del Master
         */
        public static function render_main_page() {
            $ecosystem = self::get_nabi_ecosystem();
            ?>
            <div class="wrap">
                <h1 class="wp-heading-inline">
                    <span class="dashicons dashicons-admin-settings" style="font-size: 32px; width: 32px; height: 32px; margin-right: 10px; vertical-align: middle;"></span>
                    <?php _e('Nabi Master Dashboard', 'nabi-master'); ?>
                </h1>
                <hr class="wp-header-end">
                
                <div class="nabi-master-card" style="margin-top: 20px;">
                    <h2><?php _e('Bienvenido al Ecosistema Nabi', 'nabi-master'); ?></h2>
                    <p style="font-size: 14px; color: #50575e;">
                        <?php _e('Desde este panel puedes acceder a todos tus plugins instalados de la familia Nabi, así como descubrir e instalar el resto de la suite para aprovechar todo el potencial.', 'nabi-master'); ?>
                    </p>
                </div>

                <div class="nabi-master-grid">
                    <?php foreach ($ecosystem as $slug => $plugin) : ?>
                        <div class="nabi-plugin-card <?php echo $plugin['installed'] ? 'active' : 'inactive'; ?>">
                            <div class="nabi-plugin-header">
                                <h3 style="margin: 0; display: flex; align-items: center; gap: 10px;">
                                    <?php if ($plugin['installed']): ?>
                                        <span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span>
                                    <?php else: ?>
                                        <span class="dashicons dashicons-cloud-download" style="color: #2271b1;"></span>
                                    <?php endif; ?>
                                    <?php echo esc_html($plugin['name']); ?>
                                </h3>
                            </div>
                            <div class="nabi-plugin-body">
                                <p style="color: #646970; margin: 0; line-height: 1.5;">
                                    <?php echo esc_html($plugin['description']); ?>
                                </p>
                            </div>
                            <div class="nabi-plugin-footer">
                                <?php if ($plugin['installed']) : ?>
                                    <a href="<?php echo esc_url($plugin['admin_url']); ?>" class="button button-primary">
                                        <?php _e('Gestionar Plugin', 'nabi-master'); ?>
                                    </a>
                                <?php else : ?>
                                    <a href="<?php echo esc_url($plugin['store_url']); ?>" target="_blank" class="button button-secondary">
                                        <span class="dashicons dashicons-cart" style="margin-top: 3px; margin-right: 5px;"></span>
                                        <?php _e('Ver en la Tienda', 'nabi-master'); ?>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php
        }

        /**
         * Obtiene el estado del ecosistema Nabi completo
         */
        private static function get_nabi_ecosystem() {
            if (!function_exists('is_plugin_active')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }

            // Datos base del ecosistema
            $ecosystem = [
                'Nabi-backup' => [
                    'name' => __('Nabi Backup', 'nabi-master'),
                    'description' => __('Sistema completo de backup e importación para WordPress con compresión inteligente.', 'nabi-master'),
                    'store_url' => 'https://joelpallero.com.ar/store/nabi-backup',
                    'admin_url' => admin_url('admin.php?page=Nabi-backup'),
                    'check_class' => 'NABI_Backup_Loader'
                ],
                'Nabi-duplicator' => [
                    'name' => __('Nabi Duplicator', 'nabi-master'),
                    'description' => __('Duplica y clona instantáneamente posts, páginas, productos y menús con un solo clic.', 'nabi-master'),
                    'store_url' => 'https://joelpallero.com.ar/store/nabi-duplicator',
                    'admin_url' => admin_url('admin.php?page=Nabi-duplicator'),
                    'check_class' => 'NABI_Duplicator_Loader'
                ],
                'Nabi-filter' => [
                    'name' => __('Nabi Filter for WooCommerce', 'nabi-master'),
                    'description' => __('Sistema avanzado de filtros AJAX ultra rápidos para catálogos y productos de WooCommerce.', 'nabi-master'),
                    'store_url' => 'https://joelpallero.com.ar/store/nabi-filter',
                    'admin_url' => admin_url('admin.php?page=Nabi-filter'),
                    'check_class' => 'NABI_Filter_Loader'
                ],
                'Nabi-webp' => [
                    'name' => __('Nabi WebP Converter', 'nabi-master'),
                    'description' => __('Convierte y optimiza automáticamente las imágenes de tu biblioteca a formato WebP.', 'nabi-master'),
                    'store_url' => 'https://joelpallero.com.ar/store/nabi-webp',
                    'admin_url' => admin_url('admin.php?page=Nabi-webp'),
                    'check_class' => 'NABI_WebP_Loader'
                ]
            ];

            // Comprobar la instalación por su clase o si está activo
            foreach ($ecosystem as $slug => &$data) {
                // Si la clase base del plugin existe significa que está activo
                $data['installed'] = class_exists($data['check_class']);
            }

            return $ecosystem;
        }

        public static function get_menu_slug() {
            return self::$menu_slug;
        }
    }
}
