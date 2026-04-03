<?php
defined('ABSPATH') || exit;

class NABI_BACKUP_Export
{

    private $backup_dir;
    private $temp_dir;
    private $zip_file;

    public function __construct()
    {
        // Usar ABSPATH para asegurar que siempre esté en la instalación correcta
        $this->backup_dir = ABSPATH . 'wp-content/Nabibck';
        $this->temp_dir = $this->backup_dir . '/temp-' . time();

        if (!file_exists($this->backup_dir)) {
            wp_mkdir_p($this->backup_dir);
        }
    }

    /**
     * Crea un backup completo
     */
    public function create_backup()
    {
        NABI_BACKUP_Logger::info('Iniciando proceso de backup optimizado');

        // Aumentar límites para procesos largos
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');
        @ini_set('max_execution_time', 0);
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }

        // Registrar información del sistema
        NABI_BACKUP_Logger::debug('PHP Version: ' . PHP_VERSION);
        NABI_BACKUP_Logger::debug('Memory Limit: ' . ini_get('memory_limit'));
        NABI_BACKUP_Logger::debug('Memory Usage inicial: ' . round(memory_get_usage(true) / 1024 / 1024, 2) . ' MB');

        try {
            // 1. Crear directorio temporal solo para metadatos (SQL e Info JSON)
            if (!file_exists($this->temp_dir)) {
                if (!wp_mkdir_p($this->temp_dir)) {
                    throw new Exception(__('No se pudo crear el directorio temporal', 'Nabi-backup'));
                }
            }

            // 2. Preparar el archivo ZIP
            if (!class_exists('ZipArchive')) {
                throw new Exception(__('La extensión ZipArchive no está disponible en este servidor', 'Nabi-backup'));
            }

            $filename = 'Nabi-backup-' . date('Y-m-d-H-i-s') . '.Nabi';
            $this->zip_file = $this->backup_dir . '/' . $filename;
            
            $zip = new ZipArchive();
            $result = $zip->open($this->zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            if ($result !== TRUE) {
                throw new Exception(__('No se pudo crear el archivo ZIP', 'Nabi-backup') . ' (Err: ' . $result . ')');
            }

            // 3. Exportar base de datos directamente al ZIP (vía archivo temporal)
            if (NABI_BACKUP_Settings::should_include('include_database')) {
                NABI_BACKUP_Logger::info('Fase 1/3: Exportando base de datos...');
                $this->export_database(); // Esto crea database.sql en temp_dir
                $sql_file = $this->temp_dir . '/database.sql';
                if (file_exists($sql_file)) {
                    $zip->addFile($sql_file, 'database.sql');
                }
            }

            // 4. Crear archivo de información
            $this->create_info_file(); // Esto crea backup-info.json en temp_dir
            $info_file = $this->temp_dir . '/backup-info.json';
            if (file_exists($info_file)) {
                $zip->addFile($info_file, 'backup-info.json');
            }

            // 5. Añadir archivos de wp-content directamente al ZIP (Fase más pesada)
            $include_content = NABI_BACKUP_Settings::should_include('include_media') ||
                NABI_BACKUP_Settings::should_include('include_plugins') ||
                NABI_BACKUP_Settings::should_include('include_themes') ||
                NABI_BACKUP_Settings::should_include('include_uploads');

            if ($include_content) {
                NABI_BACKUP_Logger::info('Fase 2/3: Añadiendo archivos al ZIP directamente...');
                $this->add_wp_content_to_zip($zip);
            }

            // 6. Cerrar el ZIP (Fase crítica donde se realiza la compresión)
            $zip_count = $zip->numFiles;
            NABI_BACKUP_Logger::info("Fase 3/3: Comprimiendo y cerrando archivo ZIP con {$zip_count} archivos (esto puede tardar según el tamaño del sitio)...");
            NABI_BACKUP_Logger::debug('Memoria antes de cerrar ZIP: ' . round(memory_get_usage(true) / 1024 / 1024, 2) . ' MB');
            
            $start_close = microtime(true);
            $closed = $zip->close();
            $end_close = microtime(true);
            
            if (!$closed) {
                $error_msg = __('Error al cerrar y procesar el archivo ZIP final. Verifique espacio en disco o límites de tiempo.', 'Nabi-backup');
                $last_error = error_get_last();
                if ($last_error) {
                    $error_msg .= ' Detalles: ' . $last_error['message'];
                }
                throw new Exception($error_msg);
            }

            $close_duration = round($end_close - $start_close, 2);
            NABI_BACKUP_Logger::info("Archivo ZIP cerrado y guardado correctamente en {$close_duration} segundos.");

            // Limpiar archivos temporales
            $this->cleanup();

            NABI_BACKUP_Logger::info('Backup completado con éxito: ' . $filename);

            return [
                'success' => true,
                'file' => $this->zip_file,
                'filename' => $filename
            ];


        } catch (Exception $e) {
            NABI_BACKUP_Logger::error('Error en el proceso de backup: ' . $e->getMessage());
            $this->cleanup();
            if (isset($zip) && $zip instanceof ZipArchive) {
                @$zip->close();
            }
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        } catch (Error $e) {
            NABI_BACKUP_Logger::error('Error fatal detectado: ' . $e->getMessage());
            $this->cleanup();
            return [
                'success' => false,
                'error' => 'Error fatal del servidor: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Escribe de forma segura en un archivo y verifica errores
     */
    private function safe_fwrite($handle, $string)
    {
        // Verificar espacio en disco antes de escribir
        $meta = stream_get_meta_data($handle);
        if (isset($meta['uri'])) {
            $file_path = $meta['uri'];
            $dir = dirname($file_path);
            $free_space = @disk_free_space($dir);
            $string_size = strlen($string);

            if ($free_space !== false && $free_space < ($string_size * 2)) {
                $free_space_mb = round($free_space / 1024 / 1024, 2);
                $string_size_mb = round($string_size / 1024 / 1024, 2);
                $error = sprintf(
                    __('Espacio insuficiente en disco para escribir. Espacio disponible: %s MB, Tamaño a escribir: %s MB', 'Nabi-backup'),
                    $free_space_mb,
                    $string_size_mb
                );
                NABI_BACKUP_Logger::error($error);
                throw new Exception($error);
            }
        }

        $result = @fwrite($handle, $string);
        if ($result === false) {
            $error = error_get_last();
            $error_msg = $error ? $error['message'] : __('Error desconocido al escribir en el archivo', 'Nabi-backup');

            // Verificar si es error de espacio en disco
            if (
                strpos(strtolower($error_msg), 'no space') !== false ||
                strpos(strtolower($error_msg), 'disk full') !== false ||
                strpos(strtolower($error_msg), 'espacio') !== false
            ) {
                if (isset($file_path)) {
                    $this->log_disk_space_error($file_path, strlen($string));
                }
            }

            NABI_BACKUP_Logger::error('Error al escribir en archivo: ' . $error_msg);
            throw new Exception(__('Error al escribir en el archivo SQL', 'Nabi-backup') . ': ' . $error_msg);
        }
        return $result;
    }

    /**
     * Exporta la base de datos a un archivo SQL
     */
    private function export_database()
    {
        global $wpdb;

        $sql_file = $this->temp_dir . '/database.sql';
        NABI_BACKUP_Logger::debug('Intentando crear archivo SQL: ' . $sql_file);
        $handle = fopen($sql_file, 'w');

        if (!$handle) {
            $error = __('No se pudo crear el archivo SQL', 'Nabi-backup') . ' (' . $sql_file . ')';
            NABI_BACKUP_Logger::error($error);
            throw new Exception($error);
        }

        // Encabezado SQL
        $this->safe_fwrite($handle, "-- Nabi Backup Database Export\n");
        $this->safe_fwrite($handle, "-- Date: " . current_time('mysql') . "\n");
        $this->safe_fwrite($handle, "-- WordPress Version: " . get_bloginfo('version') . "\n\n");
        $this->safe_fwrite($handle, "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n");
        $this->safe_fwrite($handle, "SET time_zone = \"+00:00\";\n\n");

        // Obtener todas las tablas
        NABI_BACKUP_Logger::debug('Obteniendo lista de tablas de la base de datos');
        $tables = $wpdb->get_results("SHOW TABLES", ARRAY_N);

        if ($wpdb->last_error) {
            $error = 'Error SQL al obtener lista de tablas: ' . $wpdb->last_error;
            NABI_BACKUP_Logger::error($error);
            throw new Exception($error);
        }

        if (empty($tables)) {
            NABI_BACKUP_Logger::warning('No se encontraron tablas en la base de datos');
        } else {
            $total_tables = count($tables);
            NABI_BACKUP_Logger::info('Encontradas ' . $total_tables . ' tablas');

            // Contar tablas con el prefijo correcto
            $prefixed_count = 0;
            foreach ($tables as $table) {
                if (strpos($table[0], $wpdb->prefix) === 0) {
                    $prefixed_count++;
                }
            }
            NABI_BACKUP_Logger::info("Tablas con prefijo '{$wpdb->prefix}': {$prefixed_count}");
        }

        // Contar tablas con prefijo para calcular porcentaje
        $prefixed_tables = [];
        foreach ($tables as $table) {
            if (strpos($table[0], $wpdb->prefix) === 0) {
                $prefixed_tables[] = $table[0];
            }
        }
        $total_prefixed_tables = count($prefixed_tables);

        $table_count = 0;
        foreach ($tables as $table) {
            $table_name = $table[0];

            // Omitir tablas que no pertenecen a este WordPress
            if (strpos($table_name, $wpdb->prefix) !== 0) {
                continue;
            }

            $table_count++;
            $percentage = $total_prefixed_tables > 0 ? round(($table_count / $total_prefixed_tables) * 100) : 0;
            NABI_BACKUP_Logger::info("Procesando tabla {$table_count}/{$total_prefixed_tables}: {$table_name} - Base de datos: {$percentage}%");

            // Registrar uso de memoria antes de procesar cada tabla
            $memory_usage = memory_get_usage(true);
            $memory_peak = memory_get_peak_usage(true);
            NABI_BACKUP_Logger::debug("Memoria antes de tabla {$table_name}: " . round($memory_usage / 1024 / 1024, 2) . " MB (Peak: " . round($memory_peak / 1024 / 1024, 2) . " MB)");

            try {
                NABI_BACKUP_Logger::info("Iniciando procesamiento de tabla {$table_name}");

                $this->safe_fwrite($handle, "\n-- Table: {$table_name}\n");
                NABI_BACKUP_Logger::debug("Escribiendo DROP TABLE para {$table_name}");
                $this->safe_fwrite($handle, "DROP TABLE IF EXISTS `{$table_name}`;\n");

                // Obtener estructura de la tabla
                NABI_BACKUP_Logger::debug("Obteniendo estructura de la tabla: {$table_name}");
                $create_table = $wpdb->get_row("SHOW CREATE TABLE `{$table_name}`", ARRAY_N);

                if ($wpdb->last_error) {
                    $error = "Error SQL al obtener estructura de {$table_name}: " . $wpdb->last_error;
                    NABI_BACKUP_Logger::error($error);
                    throw new Exception($error);
                }

                if ($create_table) {
                    NABI_BACKUP_Logger::debug("Escribiendo estructura de la tabla: {$table_name}");
                    $this->safe_fwrite($handle, $create_table[1] . ";\n\n");
                } else {
                    NABI_BACKUP_Logger::warning("No se pudo obtener la estructura de la tabla: {$table_name}");
                }

                // Obtener datos en lotes para evitar problemas de memoria
                NABI_BACKUP_Logger::debug("Obteniendo datos de la tabla: {$table_name}");
                
                $batch_size = 500; // Tamaño reducido para mayor seguridad
                $offset = 0;
                $has_data = false;

                while (true) {
                    $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM `{$table_name}` LIMIT %d OFFSET %d", $batch_size, $offset), ARRAY_A);
                    
                    if ($wpdb->last_error) {
                        $error = "Error SQL al obtener datos de {$table_name}: " . $wpdb->last_error;
                        NABI_BACKUP_Logger::error($error);
                        throw new Exception($error);
                    }

                    $row_count = count($rows);
                    if ($row_count === 0) {
                        break;
                    }

                    $has_data = true;
                    if ($offset === 0) {
                        $this->safe_fwrite($handle, "INSERT INTO `{$table_name}` VALUES\n");
                    } else {
                        $this->safe_fwrite($handle, ",\n");
                    }

                    $values = [];
                    foreach ($rows as $row) {
                        $row_values = [];
                        foreach ($row as $value) {
                            if ($value === null) {
                                $row_values[] = 'NULL';
                            } else {
                                $escaped = str_replace(['\\', "'", "\n", "\r"], ['\\\\', "\\'", "\\n", "\\r"], $value);
                                $row_values[] = "'" . $escaped . "'";
                            }
                        }
                        $values[] = "(" . implode(",", $row_values) . ")";
                    }

                    $this->safe_fwrite($handle, implode(",\n", $values));
                    
                    $offset += $row_count;
                    NABI_BACKUP_Logger::info("Procesadas {$offset} filas de la tabla {$table_name}");

                    // Liberar memoria
                    unset($rows, $values);
                    if (function_exists('gc_collect_cycles')) {
                        gc_collect_cycles();
                    }

                    if ($row_count < $batch_size) {
                        break;
                    }
                }

                if ($has_data) {
                    $this->safe_fwrite($handle, ";\n\n");
                    NABI_BACKUP_Logger::info("Tabla {$table_name} exportada exitosamente ({$offset} filas)");
                } else {
                    NABI_BACKUP_Logger::info("Tabla {$table_name} está vacía, omitiendo datos");
                }

                NABI_BACKUP_Logger::info("Tabla {$table_name} completada");
            } catch (Exception $e) {
                NABI_BACKUP_Logger::error("Error al procesar tabla {$table_name}: " . $e->getMessage());
                throw $e;
            } catch (Error $e) {
                NABI_BACKUP_Logger::error("Error fatal al procesar tabla {$table_name}: " . $e->getMessage());
                throw $e;
            }
        }

        NABI_BACKUP_Logger::info("Exportación de base de datos completada. Total de tablas procesadas: {$table_count}");
        NABI_BACKUP_Logger::info("Base de datos: 100%");

        if (!fclose($handle)) {
            $error = __('Error al cerrar el archivo SQL', 'Nabi-backup');
            NABI_BACKUP_Logger::error($error);
            throw new Exception($error);
        }

        NABI_BACKUP_Logger::info('Archivo SQL creado exitosamente: ' . $sql_file);
    }

    /**
     * Añade selectivamente el contenido de wp-content al ZIP sin copiar archivos a temporal
     * Utiliza un enfoque iterativo para evitar desbordamiento de pila en estructuras profundas
     */
    private function add_wp_content_to_zip($zip) {
        $wp_content_dir = ABSPATH . 'wp-content';
        
        // Mapeo de carpetas a configuraciones
        $folder_map = [
            'plugins' => 'include_plugins',
            'themes' => 'include_themes',
            'uploads' => 'include_uploads'
        ];

        if (!is_dir($wp_content_dir)) {
            NABI_BACKUP_Logger::warning('Directorio wp-content no encontrado: ' . $wp_content_dir);
            return;
        }

        $items = array_diff(scandir($wp_content_dir), ['.', '..']);
        $total_items = count($items);
        $count = 0;

        foreach ($items as $item) {
            $count++;
            $item_path = $wp_content_dir . '/' . $item;
            
            // Omitir nuestra propia carpeta de backups SIEMPRE
            if ($item === 'Nabibck') continue;
            
            // Si es un directorio conocido, verificar configuración
            if (is_dir($item_path) && isset($folder_map[$item])) {
                if (!NABI_BACKUP_Settings::should_include($folder_map[$item])) {
                    NABI_BACKUP_Logger::debug("Omitiendo carpeta por configuración: $item");
                    continue;
                }
            }

            NABI_BACKUP_Logger::info("Procesando en cola ({$count}/{$total_items}): wp-content/{$item}");
            $this->iterative_add_to_zip($item_path, $zip, 'wp-content/' . $item);
            
            // Limpiar memoria cada vez que se procesa un ítem de primer nivel
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }

            // Actualizar porcentaje aproximado
            $percentage = round(($count / $total_items) * 100);
            NABI_BACKUP_Logger::info("Archivos: {$percentage}%");
        }
    }

    /**
     * Añade archivos al objeto ZipArchive de forma iterativa (usando una cola)
     */
    private function iterative_add_to_zip($start_path, $zip, $start_zip_path) {
        $exclude_other = NABI_BACKUP_Settings::should_include('exclude_other_backups');
        $folders_to_exclude = [
            'ai1wm-backups',
            'updraft',
            'backwpup',
            'managewp',
            'backup-db',
            'wp-all-import',
            'wpmudev',
            'envato-backups'
        ];

        $stack = [['path' => $start_path, 'zip_path' => $start_zip_path]];
        $processed_count = 0;

        while (!empty($stack)) {
            $current = array_pop($stack);
            $path = $current['path'];
            $zip_path = $current['zip_path'];

            if (is_dir($path)) {
                $folder_name = strtolower(basename($path));
                
                // Excluir si es una carpeta de backup externo conocida
                if ($exclude_other) {
                    $should_exclude = false;
                    foreach ($folders_to_exclude as $exclude_pattern) {
                        if (strpos($folder_name, $exclude_pattern) !== false) {
                            $should_exclude = true;
                            break;
                        }
                    }
                    if ($should_exclude) {
                        NABI_BACKUP_Logger::debug("Omitiendo carpeta de backup externo: $zip_path");
                        continue;
                    }
                }

                $zip->addEmptyDir($zip_path);
                $files = @scandir($path);
                if ($files) {
                    foreach ($files as $file) {
                        if ($file === '.' || $file === '..') continue;
                        $stack[] = [
                            'path' => $path . '/' . $file,
                            'zip_path' => $zip_path . '/' . $file
                        ];
                    }
                }
            } else {
                if (is_readable($path)) {
                    $zip->addFile($path, $zip_path);
                    $processed_count++;
                    
                    // Cada 500 archivos, liberar memoria y log de progreso
                    if ($processed_count % 500 == 0) {
                        if (function_exists('gc_collect_cycles')) gc_collect_cycles();
                        NABI_BACKUP_Logger::debug("Añadidos {$processed_count} archivos al índice ZIP desde {$start_zip_path}");
                    }
                }
            }
        }
    }



    /**
     * Verifica si hay espacio suficiente en disco
     */
    private function check_disk_space($file_path, $file_size)
    {
        $dir = dirname($file_path);
        $free_space = @disk_free_space($dir);

        if ($free_space !== false && $free_space < ($file_size * 2)) {
            // Espacio insuficiente (dejamos un margen de 2x el tamaño del archivo)
            $free_space_mb = round($free_space / 1024 / 1024, 2);
            $file_size_mb = round($file_size / 1024 / 1024, 2);
            $error = sprintf(
                __('Espacio insuficiente en disco. Espacio disponible: %s MB, Tamaño del archivo: %s MB', 'Nabi-backup'),
                $free_space_mb,
                $file_size_mb
            );
            NABI_BACKUP_Logger::error($error);
            throw new Exception($error);
        }
    }

    /**
     * Verifica errores de espacio en disco al crear directorios
     */
    private function check_disk_space_error($path)
    {
        $dir = dirname($path);
        $free_space = @disk_free_space($dir);

        if ($free_space !== false && $free_space < 10485760) { // Menos de 10MB
            $free_space_mb = round($free_space / 1024 / 1024, 2);
            $error = sprintf(
                __('Espacio insuficiente en disco para crear directorio. Espacio disponible: %s MB', 'Nabi-backup'),
                $free_space_mb
            );
            NABI_BACKUP_Logger::error($error);
        }
    }

    /**
     * Registra error de espacio en disco en el log
     */
    private function log_disk_space_error($file_path, $file_size)
    {
        $dir = dirname($file_path);
        $free_space = @disk_free_space($dir);
        $free_space_mb = $free_space !== false ? round($free_space / 1024 / 1024, 2) : __('Desconocido', 'Nabi-backup');
        $file_size_mb = round($file_size / 1024 / 1024, 2);

        $error = sprintf(
            __('ERROR: No hay espacio suficiente en disco para guardar el archivo. Archivo: %s, Tamaño requerido: %s MB, Espacio disponible: %s MB', 'Nabi-backup'),
            $file_path,
            $file_size_mb,
            $free_space_mb
        );
        NABI_BACKUP_Logger::error($error);
    }

    /**
     * Crea archivo de información del backup
     */
    private function create_info_file()
    {
        require_once NABI_BACKUP_PATH . 'includes/class-Nabi-backup-license.php';

        $info = [
            'signature' => NABI_BACKUP_SIGNATURE,
            'version' => NABI_BACKUP_VERSION,
            'wp_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'date' => current_time('mysql'),
            'site_url' => get_site_url(),
            'home_url' => get_home_url(),
            'db_prefix' => $GLOBALS['wpdb']->prefix,
            'token' => NABI_BACKUP_License::get_token() // Token único de la instalación
        ];

        $info_file = $this->temp_dir . '/backup-info.json';
        file_put_contents($info_file, json_encode($info, JSON_PRETTY_PRINT));
    }

    /**
     * Limpia archivos temporales
     */
    private function cleanup()
    {
        if (is_dir($this->temp_dir)) {
            $this->delete_directory($this->temp_dir);
        }
    }

    /**
     * Estima el tamaño total que ocupará el backup
     */
    private function estimate_backup_size()
    {
        $total_size = 0;

        // Estimar base de datos
        if (NABI_BACKUP_Settings::should_include('include_database')) {
            global $wpdb;
            $res = $wpdb->get_results("SELECT SUM(data_length + index_length) AS size FROM information_schema.TABLES WHERE table_schema = '" . DB_NAME . "'", ARRAY_A);
            $db_size = isset($res[0]['size']) ? (int) $res[0]['size'] : 0;
            $total_size += $db_size;
            NABI_BACKUP_Logger::debug('Tamaño estimado DB: ' . size_format($db_size));
        }

        // Estimar wp-content
        $include_content = NABI_BACKUP_Settings::should_include('include_media') ||
            NABI_BACKUP_Settings::should_include('include_plugins') ||
            NABI_BACKUP_Settings::should_include('include_themes');

        if ($include_content) {
            $wp_content_size = $this->get_directory_size(ABSPATH . 'wp-content');
            // Restar el tamaño del propio directorio de backups para no contarlo doble
            $backup_dir_size = $this->get_directory_size($this->backup_dir);
            $actual_content_size = max(0, $wp_content_size - $backup_dir_size);
            $total_size += $actual_content_size;
            NABI_BACKUP_Logger::debug('Tamaño estimado wp-content: ' . size_format($actual_content_size));
        }

        return $total_size;
    }

    /**
     * Obtiene el tamaño total de un directorio de forma iterativa
     */
    private function get_directory_size($dir)
    {
        $size = 0;
        if (!is_dir($dir)) return $size;

        $stack = [$dir];
        while (!empty($stack)) {
            $current_dir = array_pop($stack);
            $files = @scandir($current_dir);
            if (!$files) continue;

            foreach ($files as $file) {
                if ($file == '.' || $file == '..') continue;
                
                $path = $current_dir . '/' . $file;
                if (is_dir($path)) {
                    $stack[] = $path;
                } else {
                    $size += (int) @filesize($path);
                }
            }
        }

        return $size;
    }

    /**
     * Elimina un directorio recursivamente (versión iterativa segura)
     */
    private function delete_directory($dir)
    {
        if (!is_dir($dir)) {
            return;
        }

        $stack = [$dir];
        $dirs_to_delete = [];

        while (!empty($stack)) {
            $current = array_pop($stack);
            $dirs_to_delete[] = $current;
            
            $files = @scandir($current);
            if ($files === false) continue;

            foreach ($files as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }

                $path = $current . DIRECTORY_SEPARATOR . $file;
                if (is_dir($path)) {
                    $stack[] = $path;
                } else {
                    @unlink($path);
                }
            }
        }

        // Eliminar directorios en orden inverso
        while (!empty($dirs_to_delete)) {
            $current_dir = array_pop($dirs_to_delete);
            @rmdir($current_dir);
        }
    }
}


