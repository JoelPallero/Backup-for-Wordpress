<?php
defined('ABSPATH') || exit;

class DN325_Backup_Import {

    private $backup_dir;
    private $temp_dir;
    private $zip_file;
    private $backup_info;
    private $wp_content_backup_path; // Ruta del backup de wp-content actual

    public function __construct() {
        // Usar ABSPATH para asegurar que siempre esté en la instalación correcta
        $this->backup_dir = ABSPATH . 'wp-content/dn325bck';
        $this->temp_dir = $this->backup_dir . '/import-' . time();
        
        if (!file_exists($this->backup_dir)) {
            wp_mkdir_p($this->backup_dir);
        }
    }

    /**
     * Valida y procesa el archivo de backup
     */
    public function validate_backup_file($file_path) {
        if (!file_exists($file_path)) {
            return [
                'valid' => false,
                'error' => __('El archivo no existe', 'dn325-backup')
            ];
        }

        // Verificar que es un ZIP
        $zip = new ZipArchive();
        if ($zip->open($file_path) !== TRUE) {
            return [
                'valid' => false,
                'error' => __('El archivo no es un ZIP válido', 'dn325-backup')
            ];
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
            return [
                'valid' => false,
                'error' => __('El archivo no es un backup válido de DN325 Backup', 'dn325-backup')
            ];
        }

        // Leer información del backup
        $info_content = $zip->getFromName($info_file);
        $this->backup_info = json_decode($info_content, true);

        if (!$this->backup_info || !isset($this->backup_info['signature'])) {
            $zip->close();
            return [
                'valid' => false,
                'error' => __('El archivo de información del backup está corrupto', 'dn325-backup')
            ];
        }

        // Validar firma
        require_once DN325_BACKUP_PATH . 'includes/class-dn325-backup-license.php';
        
        if ($this->backup_info['signature'] !== DN325_BACKUP_SIGNATURE) {
            $zip->close();
            return [
                'valid' => false,
                'error' => __('El archivo no es un backup válido de DN325 Backup', 'dn325-backup')
            ];
        }
        
        // Verificar token del backup (solo para referencia, no bloqueamos importación)
        // La importación puede ser de otro sitio, así que no validamos el token aquí
        // Pero lo guardamos para referencia
        if (isset($this->backup_info['token'])) {
            $this->backup_info['token_valid'] = DN325_Backup_License::validate_backup_token($file_path, $this->backup_info['token']);
        }

        $zip->close();

        return [
            'valid' => true,
            'info' => $this->backup_info
        ];
    }

    /**
     * Importa el backup
     */
    public function import_backup($file_path) {
        DN325_Backup_Logger::info('Iniciando proceso de importación: ' . $file_path);
        
        try {
            $this->zip_file = $file_path;

            // Validar archivo
            DN325_Backup_Logger::info('Validando archivo de backup');
            $validation = $this->validate_backup_file($file_path);
            if (!$validation['valid']) {
                $error = $validation['error'];
                DN325_Backup_Logger::error('Validación fallida: ' . $error);
                throw new Exception($error);
            }
            DN325_Backup_Logger::info('Archivo validado exitosamente');

            // Extraer ZIP
            DN325_Backup_Logger::info('Extrayendo archivo ZIP');
            $this->extract_zip();
            DN325_Backup_Logger::info('ZIP extraído exitosamente');

            // Restaurar base de datos
            DN325_Backup_Logger::info('Restaurando base de datos');
            $this->restore_database();
            DN325_Backup_Logger::info('Base de datos restaurada exitosamente');

            // Restaurar wp-content
            DN325_Backup_Logger::info('Restaurando wp-content');
            $this->restore_wp_content();
            DN325_Backup_Logger::info('wp-content restaurado exitosamente');

            // Limpiar
            $this->cleanup();
            
            // Eliminar backup de seguridad de wp-content después de restauración exitosa
            if (!empty($this->wp_content_backup_path) && is_dir($this->wp_content_backup_path)) {
                DN325_Backup_Logger::info('Eliminando backup de seguridad de wp-content: ' . $this->wp_content_backup_path);
                $this->delete_directory($this->wp_content_backup_path);
                DN325_Backup_Logger::info('Backup de seguridad eliminado exitosamente');
            }

            DN325_Backup_Logger::info('Importación completada exitosamente');
            
            return [
                'success' => true,
                'message' => __('Backup restaurado exitosamente', 'dn325-backup')
            ];
        } catch (Exception $e) {
            $error_message = $e->getMessage();
            DN325_Backup_Logger::error('Error en importación: ' . $error_message);
            DN325_Backup_Logger::error('Stack trace: ' . $e->getTraceAsString());
            $this->cleanup();
            return [
                'success' => false,
                'error' => $error_message
            ];
        } catch (Error $e) {
            $error_message = $e->getMessage();
            DN325_Backup_Logger::error('Error fatal en importación: ' . $error_message);
            DN325_Backup_Logger::error('Stack trace: ' . $e->getTraceAsString());
            $this->cleanup();
            return [
                'success' => false,
                'error' => $error_message
            ];
        }
    }

    /**
     * Extrae el archivo ZIP
     */
    private function extract_zip() {
        DN325_Backup_Logger::info('Creando directorio temporal: ' . $this->temp_dir);
        if (!wp_mkdir_p($this->temp_dir)) {
            $error = __('No se pudo crear el directorio temporal', 'dn325-backup') . ': ' . $this->temp_dir;
            DN325_Backup_Logger::error($error);
            throw new Exception($error);
        }

        DN325_Backup_Logger::info('Abriendo archivo ZIP: ' . $this->zip_file);
        $zip = new ZipArchive();
        $result = $zip->open($this->zip_file);
        
        if ($result !== TRUE) {
            $error = __('No se pudo abrir el archivo ZIP', 'dn325-backup') . ' (Código: ' . $result . ')';
            DN325_Backup_Logger::error($error);
            throw new Exception($error);
        }

        DN325_Backup_Logger::info('Extrayendo ' . $zip->numFiles . ' archivos del ZIP');
        $extract_result = $zip->extractTo($this->temp_dir);
        $zip->close();
        
        if (!$extract_result) {
            $error = __('Error al extraer el archivo ZIP', 'dn325-backup');
            DN325_Backup_Logger::error($error);
            throw new Exception($error);
        }
        
        DN325_Backup_Logger::info('ZIP extraído exitosamente');
    }

    /**
     * Restaura la base de datos
     */
    private function restore_database() {
        global $wpdb;

        DN325_Backup_Logger::info('Buscando archivo de base de datos en el backup');
        $sql_file = $this->find_file_in_backup('database.sql');
        if (!$sql_file || !file_exists($sql_file)) {
            $error = __('No se encontró el archivo de base de datos en el backup', 'dn325-backup');
            DN325_Backup_Logger::error($error);
            throw new Exception($error);
        }

        DN325_Backup_Logger::info('Archivo SQL encontrado: ' . $sql_file);
        DN325_Backup_Logger::info('Tamaño del archivo: ' . size_format(filesize($sql_file)));

        // Leer y ejecutar SQL
        DN325_Backup_Logger::info('Leyendo archivo SQL');
        $sql = @file_get_contents($sql_file);
        if ($sql === false || empty($sql)) {
            $error = __('No se pudo leer el archivo de base de datos', 'dn325-backup') . ': ' . $sql_file;
            DN325_Backup_Logger::error($error);
            throw new Exception($error);
        }

        DN325_Backup_Logger::info('Archivo SQL leído exitosamente (' . strlen($sql) . ' bytes)');

        // Dividir en consultas individuales de forma inteligente
        DN325_Backup_Logger::info('Dividiendo SQL en consultas individuales');
        $queries = $this->split_sql_queries($sql);
        $total_queries = count($queries);
        DN325_Backup_Logger::info("Total de consultas a ejecutar: {$total_queries}");

        // Desactivar verificación de claves foráneas temporalmente
        DN325_Backup_Logger::debug('Desactivando verificación de claves foráneas');
        $wpdb->query('SET FOREIGN_KEY_CHECKS=0;');
        
        if ($wpdb->last_error) {
            $error = 'Error al desactivar claves foráneas: ' . $wpdb->last_error;
            DN325_Backup_Logger::error($error);
            throw new Exception($error);
        }

        $executed = 0;
        $errors = [];
        
        foreach ($queries as $index => $query) {
            if (empty($query) || strpos($query, '--') === 0) {
                continue;
            }
            
            // Reemplazar prefijo si es necesario
            if (isset($this->backup_info['db_prefix']) && $this->backup_info['db_prefix'] !== $wpdb->prefix) {
                $query = str_replace($this->backup_info['db_prefix'], $wpdb->prefix, $query);
            }

            // Manejar CREATE TABLE: eliminar tabla si existe primero
            if (preg_match('/^\s*CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?/i', $query, $matches)) {
                $table_name = $matches[1];
                // Asegurar que el prefijo sea correcto
                if (isset($this->backup_info['db_prefix']) && $this->backup_info['db_prefix'] !== $wpdb->prefix) {
                    $table_name = str_replace($this->backup_info['db_prefix'], $wpdb->prefix, $table_name);
                }
                
                // Eliminar tabla si existe
                $wpdb->query("DROP TABLE IF EXISTS `{$table_name}`");
                
                // Modificar CREATE TABLE para incluir IF NOT EXISTS si no lo tiene
                if (stripos($query, 'IF NOT EXISTS') === false) {
                    $query = preg_replace('/CREATE\s+TABLE\s+/i', 'CREATE TABLE IF NOT EXISTS ', $query, 1);
                }
            }

            // Ejecutar consulta
            $result = $wpdb->query($query);
            
            if ($result === false && $wpdb->last_error) {
                $error_msg = "Error en consulta #{$index}: " . $wpdb->last_error;
                DN325_Backup_Logger::error($error_msg);
                DN325_Backup_Logger::debug("Consulta problemática: " . substr($query, 0, 200));
                $errors[] = $error_msg;
                
                // Continuar con las siguientes consultas, pero registrar el error
                // Solo lanzar excepción si hay muchos errores
                if (count($errors) > 10) {
                    $error = __('Demasiados errores al restaurar la base de datos', 'dn325-backup') . '. ' . implode('; ', array_slice($errors, 0, 3));
                    DN325_Backup_Logger::error($error);
                    throw new Exception($error);
                }
            } else {
                $executed++;
                if ($executed % 100 === 0) {
                    DN325_Backup_Logger::debug("Consultas ejecutadas: {$executed}/{$total_queries}");
                }
            }
        }

        DN325_Backup_Logger::info("Consultas ejecutadas exitosamente: {$executed}/{$total_queries}");
        
        if (!empty($errors)) {
            DN325_Backup_Logger::warning("Se encontraron " . count($errors) . " errores durante la restauración");
        }

        // Reactivar verificación de claves foráneas
        DN325_Backup_Logger::debug('Reactivando verificación de claves foráneas');
        $wpdb->query('SET FOREIGN_KEY_CHECKS=1;');
        
        if ($wpdb->last_error) {
            DN325_Backup_Logger::warning('Error al reactivar claves foráneas: ' . $wpdb->last_error);
        }
    }

    /**
     * Divide el SQL en consultas individuales respetando comillas y delimitadores
     */
    private function split_sql_queries($sql) {
        $queries = [];
        $current_query = '';
        $in_string = false;
        $string_char = null;
        $in_comment = false;
        $comment_type = null;
        $len = strlen($sql);
        
        for ($i = 0; $i < $len; $i++) {
            $char = $sql[$i];
            $next_char = ($i + 1 < $len) ? $sql[$i + 1] : null;
            
            // Manejar comentarios
            if (!$in_string && !$in_comment) {
                if ($char === '-' && $next_char === '-') {
                    $in_comment = true;
                    $comment_type = 'line';
                    $current_query .= $char;
                    continue;
                } elseif ($char === '/' && $next_char === '*') {
                    $in_comment = true;
                    $comment_type = 'block';
                    $current_query .= $char;
                    continue;
                }
            }
            
            if ($in_comment) {
                $current_query .= $char;
                if ($comment_type === 'line' && $char === "\n") {
                    $in_comment = false;
                    $comment_type = null;
                } elseif ($comment_type === 'block' && $char === '*' && $next_char === '/') {
                    $current_query .= $next_char;
                    $i++; // Saltar el siguiente carácter
                    $in_comment = false;
                    $comment_type = null;
                }
                continue;
            }
            
            // Manejar strings
            if ($char === '"' || $char === "'" || $char === '`') {
                // Contar backslashes consecutivos antes de la comilla
                $backslash_count = 0;
                $j = $i - 1;
                while ($j >= 0 && $sql[$j] === '\\') {
                    $backslash_count++;
                    $j--;
                }
                
                // Si el número de backslashes es par, la comilla no está escapada
                if ($backslash_count % 2 === 0) {
                    if (!$in_string) {
                        $in_string = true;
                        $string_char = $char;
                    } elseif ($char === $string_char) {
                        $in_string = false;
                        $string_char = null;
                    }
                }
            }
            
            // Si encontramos un punto y coma fuera de un string, es el final de una consulta
            if ($char === ';' && !$in_string) {
                $query = trim($current_query);
                if (!empty($query)) {
                    $queries[] = $query;
                }
                $current_query = '';
            } else {
                $current_query .= $char;
            }
        }
        
        // Agregar la última consulta si no termina con punto y coma
        $query = trim($current_query);
        if (!empty($query)) {
            $queries[] = $query;
        }
        
        return array_filter($queries, function($q) {
            return !empty(trim($q)) && strpos(trim($q), '--') !== 0;
        });
    }

    /**
     * Restaura wp-content
     */
    private function restore_wp_content() {
        $backup_wp_content = $this->find_file_in_backup('wp-content');
        
        if (!$backup_wp_content || !is_dir($backup_wp_content)) {
            throw new Exception(__('No se encontró la carpeta wp-content en el backup', 'dn325-backup'));
        }

        $wp_content_dir = ABSPATH . 'wp-content';
        
        // Hacer backup de wp-content actual antes de restaurar (en dn325bck)
        $backup_timestamp = time();
        $this->wp_content_backup_path = $this->backup_dir . '/wp-content-backup-' . $backup_timestamp;
        if (is_dir($wp_content_dir)) {
            DN325_Backup_Logger::info('Creando backup de seguridad de wp-content actual en: ' . $this->wp_content_backup_path);
            $this->copy_directory($wp_content_dir, $this->wp_content_backup_path);
            DN325_Backup_Logger::info('Backup de seguridad creado exitosamente');
        }

        // Eliminar wp-content actual (excepto algunos directorios importantes)
        $this->delete_wp_content_selective($wp_content_dir);

        // Copiar nuevo wp-content
        $this->copy_directory($backup_wp_content, $wp_content_dir);
    }

    /**
     * Elimina wp-content de forma selectiva (preserva algunos directorios)
     */
    private function delete_wp_content_selective($dir) {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            
            // Preservar backups de dn325-backup
            if ($file === 'dn325bck') {
                continue;
            }

            if (is_dir($path)) {
                $this->delete_directory($path);
            } else {
                unlink($path);
            }
        }
    }

    /**
     * Busca un archivo o directorio en el backup extraído
     */
    private function find_file_in_backup($filename) {
        $dirs = scandir($this->temp_dir);
        foreach ($dirs as $dir) {
            if ($dir === '.' || $dir === '..') {
                continue;
            }
            
            $full_path = $this->temp_dir . '/' . $dir;
            
            if (is_dir($full_path)) {
                // Buscar recursivamente
                $found = $this->search_in_directory($full_path, $filename);
                if ($found) {
                    return $found;
                }
            }
        }

        // Buscar directamente en el primer nivel
        $direct_path = $this->temp_dir . '/' . $filename;
        if (file_exists($direct_path)) {
            return $direct_path;
        }

        return false;
    }

    /**
     * Busca recursivamente en un directorio
     */
    private function search_in_directory($dir, $filename) {
        if (basename($dir) === $filename) {
            return $dir;
        }

        if (!is_dir($dir)) {
            return false;
        }

        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $path = $dir . '/' . $file;
            if (basename($path) === $filename) {
                return $path;
            }

            if (is_dir($path)) {
                $found = $this->search_in_directory($path, $filename);
                if ($found) {
                    return $found;
                }
            }
        }

        return false;
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

    /**
     * Limpia archivos temporales
     */
    private function cleanup() {
        if (is_dir($this->temp_dir)) {
            $this->delete_directory($this->temp_dir);
        }
    }
}
