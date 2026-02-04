<?php
defined('ABSPATH') || exit;

/**
 * Clase compartida para crear el menú principal de DN325
 * Esta clase asegura que solo se cree un menú principal compartido
 */
if (!class_exists('DN325_Menu')) {
class DN325_Menu {

    private static $menu_created = false;
    private static $menu_slug = 'dn325-plugins';
    private static $menu_hook = null;

    /**
     * Crea el menú principal si no existe
     */
    public static function create_main_menu() {
        if (self::$menu_created) {
            return self::$menu_hook;
        }

        // Usar dashicon nativo de WordPress (forzar actualización)
        $menu_icon = 'dashicons-admin-plugins';
        
        // Eliminar cualquier menú existente con el mismo slug para forzar recreación
        global $menu;
        if (isset($menu)) {
            foreach ($menu as $key => $item) {
                if (isset($item[2]) && $item[2] === self::$menu_slug) {
                    unset($menu[$key]);
                }
            }
        }
        
        self::$menu_hook = add_menu_page(
            __('DN325 Plugins', 'dn325'),
            __('DN325', 'dn325'),
            'manage_options',
            self::$menu_slug,
            [__CLASS__, 'render_main_page'],
            $menu_icon,
            30
        );

        // Forzar actualización del ícono con CSS si es necesario
        add_action('admin_head', function() {
            echo '<style>
                #toplevel_page_dn325-plugins .wp-menu-image::before {
                    content: "\f106" !important;
                    font-family: dashicons !important;
                    font-size: 20px !important;
                }
                #toplevel_page_dn325-plugins .wp-menu-image img {
                    display: none !important;
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
        // Asegurar que el menú principal existe
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
     * Renderiza la página principal del menú
     */
    public static function render_main_page() {
        // Obtener todos los plugins instalados de DN325
        $dn325_plugins = self::get_installed_dn325_plugins();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <p><?php _e('Bienvenido a los plugins de DN325. Selecciona una opción del menú lateral para acceder a cada plugin.', 'dn325'); ?></p>
            
            <?php if (!empty($dn325_plugins)) : ?>
                <div class="dn325-plugins-list" style="margin-top: 30px;">
                    <h2><?php _e('Plugins de DN325 Instalados', 'dn325'); ?></h2>
                    <div class="dn325-plugins-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px; margin-top: 20px;">
                        <?php foreach ($dn325_plugins as $plugin) : ?>
                            <div class="dn325-plugin-card" style="border: 1px solid #ddd; padding: 20px; border-radius: 4px; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                                <h3 style="margin-top: 0;">
                                    <a href="<?php echo esc_url($plugin['url']); ?>" style="text-decoration: none; color: #2271b1;">
                                        <?php echo esc_html($plugin['name']); ?>
                                    </a>
                                </h3>
                                <?php if (!empty($plugin['description'])) : ?>
                                    <p style="color: #646970; margin-bottom: 15px;"><?php echo esc_html($plugin['description']); ?></p>
                                <?php endif; ?>
                                <a href="<?php echo esc_url($plugin['url']); ?>" class="button button-primary">
                                    <?php _e('Ingresar', 'dn325'); ?>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php else : ?>
                <div class="notice notice-info" style="margin-top: 20px;">
                    <p><?php _e('No se encontraron plugins de DN325 instalados.', 'dn325'); ?></p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Obtiene la lista de plugins de DN325 instalados
     */
    private static function get_installed_dn325_plugins() {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins = get_plugins();
        $dn325_plugins = [];
        
        // Mapeo de slugs conocidos de plugins de DN325
        $dn325_slugs = [
            'dn325-backup' => [
                'name' => __('DN325 Backup', 'dn325'),
                'description' => __('Sistema completo de backup e importación para WordPress.', 'dn325')
            ],
            'dn325-filter' => [
                'name' => __('DN325 Filter for WooCommerce', 'dn325'),
                'description' => __('Sistema avanzado de filtros AJAX para productos de WooCommerce.', 'dn325')
            ],
            'dn325-webp' => [
                'name' => __('DN325 WebP Converter', 'dn325'),
                'description' => __('Convierte automáticamente todas las imágenes subidas a formato WebP.', 'dn325')
            ]
        ];

        // Buscar plugins de DN325 por nombre
        foreach ($all_plugins as $plugin_file => $plugin_data) {
            $plugin_name = $plugin_data['Name'];
            
            // Verificar si el plugin es de DN325 (empieza con "DN325")
            if (strpos($plugin_name, 'DN325') === 0) {
                // Determinar el slug del plugin basado en el nombre o archivo
                $slug = null;
                $plugin_dir = dirname($plugin_file);
                
                if (strpos($plugin_file, 'dn325-backup') !== false || strpos($plugin_dir, 'dn325-backup') !== false) {
                    $slug = 'dn325-backup';
                } elseif (strpos($plugin_file, 'dn325-filter') !== false || strpos($plugin_dir, 'dn325-filter') !== false) {
                    $slug = 'dn325-filter';
                } elseif (strpos($plugin_file, 'dn325-webp') !== false || strpos($plugin_dir, 'dn325-webp') !== false) {
                    $slug = 'dn325-webp';
                }
                
                // Si encontramos un slug conocido, agregar el plugin
                if ($slug && isset($dn325_slugs[$slug])) {
                    // Verificar si el plugin está activo
                    $is_active = is_plugin_active($plugin_file);
                    
                    if ($is_active) {
                        $dn325_plugins[$slug] = [
                            'name' => $dn325_slugs[$slug]['name'],
                            'description' => !empty($plugin_data['Description']) ? $plugin_data['Description'] : $dn325_slugs[$slug]['description'],
                            'url' => admin_url('admin.php?page=' . $slug),
                            'version' => !empty($plugin_data['Version']) ? $plugin_data['Version'] : '',
                            'active' => true
                        ];
                    }
                }
            }
        }

        return $dn325_plugins;
    }

    /**
     * Obtiene el slug del menú principal
     */
    public static function get_menu_slug() {
        return self::$menu_slug;
    }
}
} // Fin de if (!class_exists('DN325_Menu'))
