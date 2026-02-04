<?php
defined('ABSPATH') || exit;

class DN325_Backup_Export {

    private $backup_dir;
    private $temp_dir;
    private $zip_file;

    public function __construct() {
        // Usar ABSPATH para asegurar que siempre esté en la instalación correcta
        $this->backup_dir = ABSPATH . 'wp-content/dn325bck';
        $this->temp_dir = $this->backup_dir . '/temp-' . time();
        
        if (!file_exists($this->backup_dir)) {
            wp_mkdir_p($this->backup_dir);
        }
    }

    /**
     * Crea un backup completo
     */
    public function create_backup() {
        DN325_Backup_Logger::info('Iniciando proceso de backup');
        
        // Verificar si se puede crear un nuevo backup
        require_once DN325_BACKUP_PATH . 'includes/class-dn325-backup-license.php';
        
        $version = DN325_Backup_License::get_version();
        
        // Verificar conexión de cuenta si es Pro o Ultra
        if (in_array($version, [DN325_Backup_License::VERSION_PRO, DN325_Backup_License::VERSION_ULTRA])) {
            if (!DN325_Backup_License::is_account_connected()) {
                $error = __('Debes conectar tu cuenta para usar las funciones Pro/Ultra. Ve a la configuración del plugin para conectar tu cuenta.', 'dn325-backup');
                DN325_Backup_Logger::error($error);
                throw new Exception($error);
            }
            
            // Verificar conexión con el servidor
            if (!DN325_Backup_License::verify_account_connection()) {
                $error = __('La conexión con tu cuenta ha expirado. Por favor, reconecta tu cuenta.', 'dn325-backup');
                DN325_Backup_Logger::error($error);
                throw new Exception($error);
            }
        }
        
        if (!DN325_Backup_License::can_create_backup()) {
            $max_backups = DN325_Backup_License::get_max_backups();
            
            $error = sprintf(
                __('Has alcanzado el límite de backups permitidos (%s) para la versión %s. Elimina un backup existente o actualiza a una versión superior.', 'dn325-backup'),
                $max_backups === -1 ? __('ilimitado', 'dn325-backup') : $max_backups,
                strtoupper($version)
            );
            
            DN325_Backup_Logger::error($error);
            throw new Exception($error);
        }
        
        // Aumentar límites para procesos largos
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');
        @ini_set('max_execution_time', 0);
        
        // Registrar información del sistema
        DN325_Backup_Logger::debug('PHP Version: ' . PHP_VERSION);
        DN325_Backup_Logger::debug('Memory Limit: ' . ini_get('memory_limit'));
        DN325_Backup_Logger::debug('Max Execution Time: ' . ini_get('max_execution_time'));
        DN325_Backup_Logger::debug('Memory Usage: ' . round(memory_get_usage(true) / 1024 / 1024, 2) . ' MB');
        
        try {
            // Crear directorio temporal
            DN325_Backup_Logger::info('Creando directorio temporal: ' . $this->temp_dir);
            if (!wp_mkdir_p($this->temp_dir)) {
                $error = __('No se pudo crear el directorio temporal', 'dn325-backup');
                DN325_Backup_Logger::error($error);
                throw new Exception($error);
            }

            // Exportar base de datos (si está habilitado)
            require_once DN325_BACKUP_PATH . 'includes/class-dn325-backup-settings.php';
            
            if (DN325_Backup_Settings::should_include('include_database')) {
                DN325_Backup_Logger::info('Exportando base de datos');
                try {
                    $this->export_database();
                    DN325_Backup_Logger::info('Base de datos exportada exitosamente');
                } catch (Exception $e) {
                $error = 'Error al exportar base de datos: ' . $e->getMessage() . ' (Archivo: ' . $e->getFile() . ', Línea: ' . $e->getLine() . ')';
                DN325_Backup_Logger::error($error);
                throw new Exception($error);
            } catch (Error $e) {
                $error = 'Error fatal al exportar base de datos: ' . $e->getMessage() . ' (Archivo: ' . $e->getFile() . ', Línea: ' . $e->getLine() . ')';
                DN325_Backup_Logger::error($error);
                throw new Exception($error);
            }

            // Exportar wp-content (si está habilitado)
            $include_content = DN325_Backup_Settings::should_include('include_media') || 
                              DN325_Backup_Settings::should_include('include_plugins') || 
                              DN325_Backup_Settings::should_include('include_themes');
            
            if ($include_content) {
                DN325_Backup_Logger::info('Exportando wp-content');
                $this->export_wp_content();
                DN325_Backup_Logger::info('wp-content exportado exitosamente');
            }

            // Crear archivo de información
            DN325_Backup_Logger::info('Creando archivo de información');
            $this->create_info_file();

            // Crear ZIP
            DN325_Backup_Logger::info('Creando archivo ZIP');
            $this->create_zip();
            DN325_Backup_Logger::info('Archivo ZIP creado: ' . basename($this->zip_file));

            // Limpiar archivos temporales
            $this->cleanup();

            DN325_Backup_Logger::info('Backup completado exitosamente');
            
            return [
                'success' => true,
                'file' => $this->zip_file,
                'filename' => basename($this->zip_file)
            ];
        } catch (Exception $e) {
            $error_message = $e->getMessage();
            $file = $e->getFile();
            $line = $e->getLine();
            DN325_Backup_Logger::error('Error en backup: ' . $error_message);
            DN325_Backup_Logger::error('Archivo: ' . $file . ', Línea: ' . $line);
            DN325_Backup_Logger::error('Stack trace: ' . $e->getTraceAsString());
            
            // Agregar información adicional del error
            $last_error = error_get_last();
            if ($last_error) {
                DN325_Backup_Logger::error('Último error PHP: ' . $last_error['message'] . ' en ' . $last_error['file'] . ':' . $last_error['line']);
            }
            
            $this->cleanup();
            return [
                'success' => false,
                'error' => $error_message . ' (Ver logs para más detalles)'
            ];
        } catch (Error $e) {
            $error_message = $e->getMessage();
            $file = $e->getFile();
            $line = $e->getLine();
            DN325_Backup_Logger::error('Error fatal en backup: ' . $error_message);
            DN325_Backup_Logger::error('Archivo: ' . $file . ', Línea: ' . $line);
            DN325_Backup_Logger::error('Stack trace: ' . $e->getTraceAsString());
            
            // Agregar información adicional del error
            $last_error = error_get_last();
            if ($last_error) {
                DN325_Backup_Logger::error('Último error PHP: ' . $last_error['message'] . ' en ' . $last_error['file'] . ':' . $last_error['line']);
            }
            
            $this->cleanup();
            return [
                'success' => false,
                'error' => $error_message . ' (Ver logs para más detalles)'
            ];
        }
    }

    /**
     * Escribe de forma segura en un archivo y verifica errores
     */
    private function safe_fwrite($handle, $string) {
        $result = @fwrite($handle, $string);
        if ($result === false) {
            $error = error_get_last();
            $error_msg = $error ? $error['message'] : __('Error desconocido al escribir en el archivo', 'dn325-backup');
            DN325_Backup_Logger::error('Error al escribir en archivo: ' . $error_msg);
            throw new Exception(__('Error al escribir en el archivo SQL', 'dn325-backup') . ': ' . $error_msg);
        }
        return $result;
    }

    /**
     * Exporta la base de datos a un archivo SQL
     */
    private function export_database() {
        global $wpdb;

        $sql_file = $this->temp_dir . '/database.sql';
        DN325_Backup_Logger::debug('Intentando crear archivo SQL: ' . $sql_file);
        $handle = fopen($sql_file, 'w');

        if (!$handle) {
            $error = __('No se pudo crear el archivo SQL', 'dn325-backup') . ' (' . $sql_file . ')';
            DN325_Backup_Logger::error($error);
            throw new Exception($error);
        }

        // Encabezado SQL
        $this->safe_fwrite($handle, "-- DN325 Backup Database Export\n");
        $this->safe_fwrite($handle, "-- Date: " . current_time('mysql') . "\n");
        $this->safe_fwrite($handle, "-- WordPress Version: " . get_bloginfo('version') . "\n\n");
        $this->safe_fwrite($handle, "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n");
        $this->safe_fwrite($handle, "SET time_zone = \"+00:00\";\n\n");

        // Obtener todas las tablas
        DN325_Backup_Logger::debug('Obteniendo lista de tablas de la base de datos');
        $tables = $wpdb->get_results("SHOW TABLES", ARRAY_N);
        
        if ($wpdb->last_error) {
            $error = 'Error SQL al obtener lista de tablas: ' . $wpdb->last_error;
            DN325_Backup_Logger::error($error);
            throw new Exception($error);
        }
        
        if (empty($tables)) {
            DN325_Backup_Logger::warning('No se encontraron tablas en la base de datos');
        } else {
            $total_tables = count($tables);
            DN325_Backup_Logger::info('Encontradas ' . $total_tables . ' tablas');
            
            // Contar tablas con el prefijo correcto
            $prefixed_count = 0;
            foreach ($tables as $table) {
                if (strpos($table[0], $wpdb->prefix) === 0) {
                    $prefixed_count++;
                }
            }
            DN325_Backup_Logger::info("Tablas con prefijo '{$wpdb->prefix}': {$prefixed_count}");
        }
        
        $table_count = 0;
        foreach ($tables as $table) {
            $table_name = $table[0];
            
            // Omitir tablas que no pertenecen a este WordPress
            if (strpos($table_name, $wpdb->prefix) !== 0) {
                continue;
            }

            $table_count++;
            DN325_Backup_Logger::info("Procesando tabla {$table_count}: {$table_name}");
            
            // Registrar uso de memoria antes de procesar cada tabla
            $memory_usage = memory_get_usage(true);
            $memory_peak = memory_get_peak_usage(true);
            DN325_Backup_Logger::debug("Memoria antes de tabla {$table_name}: " . round($memory_usage / 1024 / 1024, 2) . " MB (Peak: " . round($memory_peak / 1024 / 1024, 2) . " MB)");

            try {
                DN325_Backup_Logger::info("Iniciando procesamiento de tabla {$table_name}");
                
                $this->safe_fwrite($handle, "\n-- Table: {$table_name}\n");
                DN325_Backup_Logger::debug("Escribiendo DROP TABLE para {$table_name}");
                $this->safe_fwrite($handle, "DROP TABLE IF EXISTS `{$table_name}`;\n");

                // Obtener estructura de la tabla
                DN325_Backup_Logger::debug("Obteniendo estructura de la tabla: {$table_name}");
                $create_table = $wpdb->get_row("SHOW CREATE TABLE `{$table_name}`", ARRAY_N);
                
                if ($wpdb->last_error) {
                    $error = "Error SQL al obtener estructura de {$table_name}: " . $wpdb->last_error;
                    DN325_Backup_Logger::error($error);
                    throw new Exception($error);
                }
                
                if ($create_table) {
                    DN325_Backup_Logger::debug("Escribiendo estructura de la tabla: {$table_name}");
                    $this->safe_fwrite($handle, $create_table[1] . ";\n\n");
                } else {
                    DN325_Backup_Logger::warning("No se pudo obtener la estructura de la tabla: {$table_name}");
                }

                // Obtener datos
                DN325_Backup_Logger::debug("Obteniendo datos de la tabla: {$table_name}");
                $rows = $wpdb->get_results("SELECT * FROM `{$table_name}`", ARRAY_A);
                
                if ($wpdb->last_error) {
                    $error = "Error SQL al obtener datos de {$table_name}: " . $wpdb->last_error;
                    DN325_Backup_Logger::error($error);
                    throw new Exception($error);
                }
                
                $row_count = count($rows);
                DN325_Backup_Logger::info("Tabla {$table_name}: {$row_count} filas encontradas");
                
                if ($row_count > 0) {
                    // Procesar en lotes para evitar problemas de memoria
                    $batch_size = 1000;
                    $batches = array_chunk($rows, $batch_size);
                    $total_batches = count($batches);
                    
                    DN325_Backup_Logger::info("Procesando {$total_batches} lotes de {$batch_size} filas cada uno para tabla {$table_name}");
                    
                    // Liberar memoria de $rows antes de procesar lotes
                    unset($rows);
                    
                    for ($batch_index = 0; $batch_index < $total_batches; $batch_index++) {
                        DN325_Backup_Logger::debug("Procesando lote " . ($batch_index + 1) . "/{$total_batches} de tabla {$table_name}");
                        
                        $batch = $batches[$batch_index];
                        $is_first_batch = ($batch_index === 0);
                        
                        if ($is_first_batch) {
                            DN325_Backup_Logger::debug("Escribiendo INSERT INTO para tabla {$table_name}");
                            $this->safe_fwrite($handle, "INSERT INTO `{$table_name}` VALUES\n");
                        }
                        
                        DN325_Backup_Logger::debug("Procesando " . count($batch) . " filas del lote " . ($batch_index + 1));
                        $values = [];
                        foreach ($batch as $row_index => $row) {
                            $row_values = [];
                            foreach ($row as $value) {
                                if ($value === null) {
                                    $row_values[] = 'NULL';
                                } else {
                                    // Escapar correctamente para SQL
                                    // Reemplazar comillas simples y barras invertidas
                                    $escaped = str_replace(['\\', "'", "\n", "\r"], ['\\\\', "\\'", "\\n", "\\r"], $value);
                                    $row_values[] = "'" . $escaped . "'";
                                }
                            }
                            $values[] = "(" . implode(",", $row_values) . ")";
                            
                            // Liberar memoria periódicamente
                            if ($row_index % 100 === 0 && $row_index > 0) {
                                unset($row_values);
                            }
                        }
                        
                        DN325_Backup_Logger::debug("Escribiendo " . count($values) . " valores al archivo");
                        $this->safe_fwrite($handle, implode(",\n", $values));
                        
                        if ($batch_index < $total_batches - 1) {
                            $this->safe_fwrite($handle, ",\n");
                        } else {
                            $this->safe_fwrite($handle, ";\n\n");
                        }
                        
                        DN325_Backup_Logger::info("Lote " . ($batch_index + 1) . "/{$total_batches} de la tabla {$table_name} procesado exitosamente");
                        
                        // Liberar memoria
                        unset($batch, $values);
                        
                        // Forzar liberación de memoria
                        if (function_exists('gc_collect_cycles')) {
                            gc_collect_cycles();
                        }
                    }
                    
                    DN325_Backup_Logger::info("Tabla {$table_name} exportada exitosamente ({$row_count} filas)");
                } else {
                    DN325_Backup_Logger::info("Tabla {$table_name} está vacía, omitiendo datos");
                }
                
                DN325_Backup_Logger::info("Tabla {$table_name} completada");
            } catch (Exception $e) {
                DN325_Backup_Logger::error("Error al procesar tabla {$table_name}: " . $e->getMessage());
                throw $e;
            } catch (Error $e) {
                DN325_Backup_Logger::error("Error fatal al procesar tabla {$table_name}: " . $e->getMessage());
                throw $e;
            }
        }
        
        DN325_Backup_Logger::info("Exportación de base de datos completada. Total de tablas procesadas: {$table_count}");

        if (!fclose($handle)) {
            $error = __('Error al cerrar el archivo SQL', 'dn325-backup');
            DN325_Backup_Logger::error($error);
            throw new Exception($error);
        }
        
        DN325_Backup_Logger::info('Archivo SQL creado exitosamente: ' . $sql_file);
    }

    /**
     * Exporta la carpeta wp-content
     */
    private function export_wp_content() {
        $wp_content_dir = ABSPATH . 'wp-content';
        $target_dir = $this->temp_dir . '/wp-content';

        DN325_Backup_Logger::debug('Copiando wp-content desde: ' . $wp_content_dir . ' hacia: ' . $target_dir);

        if (!is_dir($wp_content_dir)) {
            $error = __('El directorio wp-content no existe', 'dn325-backup') . ' (' . $wp_content_dir . ')';
            DN325_Backup_Logger::error($error);
            throw new Exception($error);
        }

        if (!wp_mkdir_p($target_dir)) {
            $error = __('No se pudo crear el directorio wp-content en el backup', 'dn325-backup') . ' (' . $target_dir . ')';
            DN325_Backup_Logger::error($error);
            throw new Exception($error);
        }

        $this->copy_directory($wp_content_dir, $target_dir);
        DN325_Backup_Logger::info('wp-content copiado exitosamente');
    }

    /**
     * Copia un directorio recursivamente
     */
    private function copy_directory($source, $destination) {
        if (!is_dir($source)) {
            return false;
        }

        if (!is_dir($destination)) {
            wp_mkdir_p($destination);
        }

        $dir = opendir($source);
        if (!$dir) {
            return false;
        }

        while (($file = readdir($dir)) !== false) {
            if ($file == '.' || $file == '..') {
                continue;
            }

            $source_path = $source . '/' . $file;
            $dest_path = $destination . '/' . $file;

            // Omitir backups anteriores y archivos temporales
            if (strpos($source_path, '/dn325bck') !== false) {
                continue;
            }

            if (is_dir($source_path)) {
                $this->copy_directory($source_path, $dest_path);
            } else {
                copy($source_path, $dest_path);
            }
        }

        closedir($dir);
        return true;
    }

    /**
     * Crea archivo de información del backup
     */
    private function create_info_file() {
        require_once DN325_BACKUP_PATH . 'includes/class-dn325-backup-license.php';
        
        $info = [
            'signature' => DN325_BACKUP_SIGNATURE,
            'version' => DN325_BACKUP_VERSION,
            'wp_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'date' => current_time('mysql'),
            'site_url' => get_site_url(),
            'home_url' => get_home_url(),
            'db_prefix' => $GLOBALS['wpdb']->prefix,
            'token' => DN325_Backup_License::get_token() // Token único de la instalación
        ];

        $info_file = $this->temp_dir . '/backup-info.json';
        file_put_contents($info_file, json_encode($info, JSON_PRETTY_PRINT));
    }

    /**
     * Crea el archivo ZIP final
     */
    private function create_zip() {
        if (!class_exists('ZipArchive')) {
            $error = __('La extensión ZipArchive no está disponible en este servidor', 'dn325-backup');
            DN325_Backup_Logger::error($error);
            throw new Exception($error);
        }

        $filename = 'dn325-backup-' . date('Y-m-d-H-i-s') . '.zip';
        $this->zip_file = $this->backup_dir . '/' . $filename;

        DN325_Backup_Logger::debug('Creando archivo ZIP: ' . $this->zip_file);
        $zip = new ZipArchive();
        $result = $zip->open($this->zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        
        if ($result !== TRUE) {
            $error = __('No se pudo crear el archivo ZIP', 'dn325-backup') . ' (Código: ' . $result . ')';
            DN325_Backup_Logger::error($error);
            throw new Exception($error);
        }

        // Agregar todos los archivos del directorio temporal
        $this->add_directory_to_zip($this->temp_dir, $zip, basename($this->temp_dir));

        $zip->close();
    }

    /**
     * Agrega un directorio completo al ZIP
     */
    private function add_directory_to_zip($dir, $zip, $zip_path = '') {
        $files = scandir($dir);
        
        foreach ($files as $file) {
            if ($file == '.' || $file == '..') {
                continue;
            }

            $file_path = $dir . '/' . $file;
            $zip_file_path = $zip_path ? $zip_path . '/' . $file : $file;

            if (is_dir($file_path)) {
                $zip->addEmptyDir($zip_file_path);
                $this->add_directory_to_zip($file_path, $zip, $zip_file_path);
            } else {
                $zip->addFile($file_path, $zip_file_path);
            }
        }
    }

    /**
     * Limpia archivos temporales
     */
    private function cleanup() {
        if (is_dir($this->temp_dir)) {
            $this->delete_directory($this->temp_dir);
        }
    }

    /**
     * Elimina un directorio recursivamente
     */
    private function delete_directory($dir) {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->delete_directory($path) : unlink($path);
        }

        rmdir($dir);
    }
}
