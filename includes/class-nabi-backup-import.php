<?php
defined('ABSPATH') || exit;

class NABI_BACKUP_Import
{

    private $backup_dir;
    private $temp_dir;
    private $zip_file;
    private $backup_info;
    private $wp_content_backup_path; // Ruta del backup de wp-content actual

    public function __construct()
    {
        // Usar ABSPATH para asegurar que siempre esté en la instalación correcta
        $this->backup_dir = ABSPATH . 'wp-content/Nabibck';
        $this->temp_dir = $this->backup_dir . '/import-' . time();

        if (!file_exists($this->backup_dir)) {
            wp_mkdir_p($this->backup_dir);
        }
    }

    /**
     * Valida y procesa el archivo de backup
     */
    public function validate_backup_file($file_path)
    {
        if (!file_exists($file_path)) {
            return [
                'valid' => false,
                'error' => __('El archivo no existe', 'Nabi-backup')
            ];
        }

        // Verificar extensión (permitir .zip por compatibilidad y .Nabi)
        $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        if ($ext !== 'zip' && $ext !== 'Nabi') {
            return [
                'valid' => false,
                'error' => __('Formato de archivo no soportado', 'Nabi-backup')
            ];
        }

        // Verificar que es un ZIP/Nabi
        $zip = new ZipArchive();
        if ($zip->open($file_path) !== TRUE) {
            return [
                'valid' => false,
                'error' => __('El archivo está corrupto o no es un backup válido', 'Nabi-backup')
            ];
        }
// ... (resto del código de validación igual)

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
                'error' => __('El archivo no es un backup válido de Nabi Backup', 'Nabi-backup')
            ];
        }

        // Leer información del backup
        $info_content = $zip->getFromName($info_file);
        $this->backup_info = json_decode($info_content, true);

        if (!$this->backup_info || !isset($this->backup_info['signature'])) {
            $zip->close();
            return [
                'valid' => false,
                'error' => __('El archivo de información del backup está corrupto', 'Nabi-backup')
            ];
        }

        // Validar firma
        require_once NABI_BACKUP_PATH . 'includes/class-Nabi-backup-license.php';

        if ($this->backup_info['signature'] !== NABI_BACKUP_SIGNATURE) {
            $zip->close();
            return [
                'valid' => false,
                'error' => __('El archivo no es un backup válido de Nabi Backup', 'Nabi-backup')
            ];
        }

        // Verificar token del backup (solo para referencia, no bloqueamos importación)
        // La importación puede ser de otro sitio, así que no validamos el token aquí
        // Pero lo guardamos para referencia
        if (isset($this->backup_info['token'])) {
            $this->backup_info['token_valid'] = NABI_BACKUP_License::validate_backup_token($file_path, $this->backup_info['token']);
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
    public function import_backup($file_path)
    {
        NABI_BACKUP_Logger::info('Iniciando proceso de importación: ' . $file_path);

        // Aumentar límites para procesos largos
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');
        @ini_set('max_execution_time', 0);
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }

        try {
            $this->zip_file = $file_path;

            // Validar archivo
            NABI_BACKUP_Logger::info('Validando archivo de backup');
            $validation = $this->validate_backup_file($file_path);
            if (!$validation['valid']) {
                $error = $validation['error'];
                NABI_BACKUP_Logger::error('Validación fallida: ' . $error);
                throw new Exception($error);
            }
            NABI_BACKUP_Logger::info('Archivo validado exitosamente');

            // Extraer ZIP
            NABI_BACKUP_Logger::info('Extrayendo archivo ZIP');
            $this->extract_zip();
            NABI_BACKUP_Logger::info('ZIP extraído exitosamente');

            // Restaurar base de datos
            NABI_BACKUP_Logger::info('Restaurando base de datos');
            $this->restore_database();
            NABI_BACKUP_Logger::info('Base de datos restaurada exitosamente');

            // Restaurar wp-content
            NABI_BACKUP_Logger::info('Restaurando wp-content');
            $this->restore_wp_content();
            NABI_BACKUP_Logger::info('wp-content restaurado exitosamente');

            NABI_BACKUP_Logger::info('Importación completada exitosamente');

            // Limpieza final exitosa
            $this->final_cleanup(true);

            return [
                'success' => true,
                'message' => __('Backup restaurado exitosamente', 'Nabi-backup')
            ];
        } catch (Exception $e) {
            $error_message = $e->getMessage();
            NABI_BACKUP_Logger::error('Error en importación: ' . $error_message);
            
            // Intentar revertir si hubo error
            $this->revert_wp_content();
            
            // Limpieza tras error
            $this->final_cleanup(false);

            return [
                'success' => false,
                'error' => $error_message
            ];
        } catch (Error $e) {
            $error_message = $e->getMessage();
            NABI_BACKUP_Logger::error('Error fatal en importación: ' . $error_message);
            
            $this->revert_wp_content();
            $this->final_cleanup(false);

            return [
                'success' => false,
                'error' => $error_message
            ];
        }
    }

    /**
     * Revierte los cambios de wp-content en caso de fallo
     */
    private function revert_wp_content()
    {
        if (empty($this->wp_content_backup_path) || !is_dir($this->wp_content_backup_path)) {
            NABI_BACKUP_Logger::warning('No se puede revertir wp-content: No existe el backup de seguridad.');
            return;
        }

        $wp_content_dir = ABSPATH . 'wp-content';
        NABI_BACKUP_Logger::info('REVERTIENDO WP-CONTENT A ESTADO ANTERIOR...');

        try {
            if (!is_dir($wp_content_dir)) {
                wp_mkdir_p($wp_content_dir);
            }

            // Mover archivos desde el backup de seguridad de vuelta a wp-content
            $files = array_diff(scandir($this->wp_content_backup_path), ['.', '..']);
            foreach ($files as $file) {
                $src = $this->wp_content_backup_path . '/' . $file;
                $dst = $wp_content_dir . '/' . $file;
                
                if (file_exists($dst)) {
                    if (is_dir($dst)) $this->delete_directory($dst);
                    else @unlink($dst);
                }

                if (!@rename($src, $dst)) {
                    $this->copy_directory($src, $dst);
                }
            }
            NABI_BACKUP_Logger::info('Reversión de wp-content completada');
        } catch (Exception $e) {
            NABI_BACKUP_Logger::error('Fallo crítico durante la reversión: ' . $e->getMessage());
        }
    }

    /**
     * Limpieza final de todos los temporales de importación
     */
    private function final_cleanup($success)
    {
        NABI_BACKUP_Logger::info('Iniciando limpieza final de temporales de importación');
        
        // Limpiar directorio de extracción
        $this->cleanup();

        // Limpiar el backup de seguridad de wp-content siempre al final
        if (!empty($this->wp_content_backup_path) && is_dir($this->wp_content_backup_path)) {
            $this->delete_directory($this->wp_content_backup_path);
            NABI_BACKUP_Logger::info('Backup de seguridad eliminado');
        }
    }

    /**
     * Extrae el archivo ZIP
     */
    private function extract_zip()
    {
        NABI_BACKUP_Logger::info('Creando directorio temporal: ' . $this->temp_dir);
        if (!wp_mkdir_p($this->temp_dir)) {
            $error = __('No se pudo crear el directorio temporal', 'Nabi-backup') . ': ' . $this->temp_dir;
            NABI_BACKUP_Logger::error($error);
            throw new Exception($error);
        }

        NABI_BACKUP_Logger::info('Abriendo archivo ZIP: ' . $this->zip_file);
        $zip = new ZipArchive();
        $result = $zip->open($this->zip_file);

        if ($result !== TRUE) {
            $error = __('No se pudo abrir el archivo ZIP', 'Nabi-backup') . ' (Código: ' . $result . ')';
            NABI_BACKUP_Logger::error($error);
            throw new Exception($error);
        }

        NABI_BACKUP_Logger::info('Extrayendo ' . $zip->numFiles . ' archivos del ZIP');
        $extract_result = $zip->extractTo($this->temp_dir);
        $zip->close();

        if (!$extract_result) {
            $error = __('Error al extraer el archivo ZIP', 'Nabi-backup');
            NABI_BACKUP_Logger::error($error);
            throw new Exception($error);
        }

        NABI_BACKUP_Logger::info('ZIP extraído exitosamente');
    }

    /**
     * Restaura la base de datos (versión optimizada para memoria con lectura secuencial)
     */
    private function restore_database()
    {
        global $wpdb;

        NABI_BACKUP_Logger::info('Buscando archivo de base de datos en el backup');
        $sql_file = $this->find_file_in_backup('database.sql');
        if (!$sql_file || !file_exists($sql_file)) {
            $error = __('No se encontró el archivo de base de datos en el backup', 'Nabi-backup');
            NABI_BACKUP_Logger::error($error);
            throw new Exception($error);
        }

        NABI_BACKUP_Logger::info('Archivo SQL encontrado: ' . $sql_file);
        $file_size = filesize($sql_file);
        NABI_BACKUP_Logger::info('Tamaño del archivo: ' . size_format($file_size));

        // Abrir archivo para lectura secuencial
        $handle = @fopen($sql_file, 'r');
        if (!$handle) {
            $error = __('No se pudo abrir el archivo de base de datos para lectura', 'Nabi-backup');
            NABI_BACKUP_Logger::error($error);
            throw new Exception($error);
        }

        // Desactivar verificación de claves foráneas
        $wpdb->query('SET FOREIGN_KEY_CHECKS=0;');

        $current_query = '';
        $executed = 0;
        $errors = [];
        $bytes_read = 0;
        $last_log_percentage = -1;

        while (($line = fgets($handle)) !== false) {
            $bytes_read += strlen($line);
            $line = trim($line);

            // Ignorar líneas vacías o comentarios simples
            if (empty($line) || strpos($line, '--') === 0 || strpos($line, '#') === 0 || strpos($line, '/*') === 0) {
                continue;
            }

            $current_query .= $line . "\n";

            // Si la línea termina con punto y coma, tenemos una consulta completa
            if (substr(rtrim($line), -1) === ';') {
                $query = trim($current_query);
                
                // Reemplazar prefijo si es necesario
                if (isset($this->backup_info['db_prefix']) && $this->backup_info['db_prefix'] !== $wpdb->prefix) {
                    $query = str_replace($this->backup_info['db_prefix'], $wpdb->prefix, $query);
                }

                // Manejar DROP/CREATE TABLE
                if (preg_match('/^\s*CREATE\s+TABLE/i', $query)) {
                    if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?/i', $query, $matches)) {
                        $table_name = $matches[1];
                        $wpdb->query("DROP TABLE IF EXISTS `{$table_name}`");
                    }
                }

                // Ejecutar consulta
                $result = $wpdb->query($query);

                if ($result === false && $wpdb->last_error) {
                    $errors[] = $wpdb->last_error;
                    if (count($errors) > 20) {
                        fclose($handle);
                        throw new Exception(__('Demasiados errores de base de datos', 'Nabi-backup') . ': ' . $wpdb->last_error);
                    }
                } else {
                    $executed++;
                }

                $current_query = '';

                // Log de progreso cada 10%
                $percentage = round(($bytes_read / $file_size) * 100);
                if ($percentage % 10 === 0 && $percentage != $last_log_percentage) {
                    NABI_BACKUP_Logger::info("Base de datos: {$percentage}% restaurado ({$executed} consultas)");
                    $last_log_percentage = $percentage;
                }
            }
        }

        fclose($handle);
        $wpdb->query('SET FOREIGN_KEY_CHECKS=1;');

        NABI_BACKUP_Logger::info("Restauración de BD finalizada. Consultas: {$executed}, Errores: " . count($errors));
    }



    /**
     * Restaura wp-content (versión optimizada por desplazamiento de directorios)
     */
    private function restore_wp_content()
    {
        $backup_wp_content = $this->find_file_in_backup('wp-content');

        if (!$backup_wp_content || !is_dir($backup_wp_content)) {
            throw new Exception(__('No se encontró la carpeta wp-content en el backup', 'Nabi-backup'));
        }

        $wp_content_dir = ABSPATH . 'wp-content';

        // Hacer backup de wp-content actual antes de restaurar (en Nabibck)
        $backup_timestamp = time();
        $this->wp_content_backup_path = $this->backup_dir . '/wp-content-backup-' . $backup_timestamp;
        
        if (is_dir($wp_content_dir)) {
            NABI_BACKUP_Logger::info('Creando backup de seguridad (moviendo archivos) de wp-content actual...');
            if (!is_dir($this->wp_content_backup_path)) {
                @wp_mkdir_p($this->wp_content_backup_path);
            }
            
            $files = array_diff(scandir($wp_content_dir), ['.', '..']);
            foreach ($files as $file) {
                // No mover nuestra propia carpeta de backups ni el de seguridad actual
                if ($file === 'Nabibck' || $file === basename($this->wp_content_backup_path)) {
                    continue;
                }
                
                $src = $wp_content_dir . '/' . $file;
                $dst = $this->wp_content_backup_path . '/' . $file;
                
                if (!@rename($src, $dst)) {
                    // Si rename falla (ej: permisos o particiones), usar copia y luego borrar
                    $this->copy_directory($src, $dst);
                    if (is_dir($src)) $this->delete_directory($src);
                    else @unlink($src);
                }
            }
            NABI_BACKUP_Logger::info('Backup de seguridad completado');
        }

        // Transferir archivos del backup a wp-content
        NABI_BACKUP_Logger::info('Transfiriendo archivos del backup a wp-content...');
        $files = array_diff(scandir($backup_wp_content), ['.', '..']);
        foreach ($files as $file) {
            $src = $backup_wp_content . '/' . $file;
            $dst = $wp_content_dir . '/' . $file;
            
            if (file_exists($dst)) {
                if (is_dir($dst)) $this->delete_directory($dst);
                else @unlink($dst);
            }

            if (!@rename($src, $dst)) {
                $this->copy_directory($src, $dst);
            }
        }
        NABI_BACKUP_Logger::info('Transferencia completada');
    }

    /**
     * Elimina wp-content de forma selectiva (preserva algunos directorios)
     */
    private function delete_wp_content_selective($dir)
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;

            // Preservar backups de Nabi-backup
            if ($file === 'Nabibck') {
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
    private function find_file_in_backup($filename)
    {
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
     * Busca recursivamente en un directorio (versión iterativa)
     */
    private function search_in_directory($dir, $filename)
    {
        $stack = [$dir];

        while (!empty($stack)) {
            $current_dir = array_pop($stack);
            
            if (basename($current_dir) === $filename) {
                return $current_dir;
            }

            $files = @scandir($current_dir);
            if ($files === false) continue;

            foreach ($files as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }

                $path = $current_dir . DIRECTORY_SEPARATOR . $file;
                if (basename($path) === $filename) {
                    return $path;
                }

                if (is_dir($path)) {
                    $stack[] = $path;
                }
            }
        }

        return false;
    }

    /**
     * Copia un directorio recursivamente (versión iterativa para mayor estabilidad)
     */
    private function copy_directory($source, $destination)
    {
        if (!is_dir($source)) {
            return false;
        }

        if (!is_dir($destination)) {
            wp_mkdir_p($destination);
        }

        $stack = [[$source, $destination]];

        while (!empty($stack)) {
            list($src, $dst) = array_pop($stack);
            
            if (!is_dir($dst)) {
                @wp_mkdir_p($dst);
            }

            $files = @scandir($src);
            if ($files === false) continue;

            foreach ($files as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }

                $src_path = $src . DIRECTORY_SEPARATOR . $file;
                $dst_path = $dst . DIRECTORY_SEPARATOR . $file;

                if (is_dir($src_path)) {
                    $stack[] = [$src_path, $dst_path];
                } else {
                    @copy($src_path, $dst_path);
                }
            }
        }

        return true;
    }

    /**
     * Elimina un directorio recursivamente (versión iterativa para evitar desbordamiento de pila)
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

        // Eliminar directorios en orden inverso (hijos primero)
        while (!empty($dirs_to_delete)) {
            $current_dir = array_pop($dirs_to_delete);
            @rmdir($current_dir);
        }
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
}


