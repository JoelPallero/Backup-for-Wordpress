(function($) {
    'use strict';

    let selectedFile = null;
    let tempFileName = null;

    $(document).ready(function() {
        // Debug: Verificar que jQuery y las variables estén disponibles
        if (typeof jQuery === 'undefined') {
            console.error('Nabi Backup: jQuery no está disponible');
            return;
        }
        
        if (typeof NabiBackup === 'undefined') {
            console.error('Nabi Backup: Variables de localización no están disponibles');
            console.log('Verificar que wp_localize_script esté funcionando correctamente');
            return;
        }
        
        console.log('Nabi Backup: Inicializando...', NabiBackup);
        
        try {
            initTabs();
            initExport();
            initImport();
            initRestore();
            initAccountManagement();
            initSettings();
            console.log('Nabi Backup: Inicialización completada');
        } catch (error) {
            console.error('Nabi Backup: Error en inicialización:', error);
        }
    });

    /**
     * Inicializa el sistema de pestañas
     */
    function initTabs() {
        $('.Nabi-backup-tabs .nav-tab').on('click', function(e) {
            e.preventDefault();
            
            const target = $(this).attr('href');
            
            // Actualizar pestañas
            $('.nav-tab').removeClass('nav-tab-active');
            $(this).addClass('nav-tab-active');
            
            // Mostrar contenido
            $('.tab-pane').removeClass('active');
            $(target + '-tab').addClass('active');
            
            // Ya no es necesario manejar #restore aquí como pestaña
        });
    }
    
    /**
     * Inicializa la funcionalidad de restaurar
     */
    function initRestore() {
        // Cargar la lista automáticamente ya que ahora es una sección fija al principio
        loadBackupList();
    }
    
    /**
     * Carga la lista de backups guardados
     */
    function loadBackupList() {
        const $container = $('#Nabi-backup-list-container');
        
        // Mostrar indicador de carga
        $container.html('<div class="Nabi-backup-loading" style="text-align: center; padding: 20px;"><span class="spinner is-active" style="float: none; margin: 0 auto;"></span><p>Cargando backups...</p></div>');
        
        $.ajax({
            url: NabiBackup.ajax_url,
            type: 'POST',
            data: {
                action: 'NABI_BACKUP_list',
                nonce: NabiBackup.nonce
            },
            success: function(response) {
                console.log('Nabi Backup: Respuesta de lista de backups', response);
                
                if (response.success && response.data && response.data.backups && response.data.backups.length > 0) {
                    let html = '<div class="Nabi-backup-list">';
                    
                    response.data.backups.forEach(function(backup) {
                        html += '<div class="Nabi-backup-list-item">';
                        html += '<div class="Nabi-backup-list-item-info">';
                        html += '<div class="Nabi-backup-list-item-name">' + backup.filename + '</div>';
                        html += '<div class="Nabi-backup-list-item-meta">';
                        html += '<strong>Fecha:</strong> ' + backup.date;
                        if (backup.wp_version) {
                            html += ' | <strong>WP:</strong> ' + backup.wp_version;
                        }
                        if (backup.version) {
                            html += ' | <strong>Plugin:</strong> ' + backup.version;
                        }
                        html += ' | <strong>Tamaño:</strong> ' + backup.size_formatted;
                        html += '</div>';
                        html += '</div>';
                        html += '<div class="Nabi-backup-list-item-actions">';
                        html += '<a href="' + backup.download_url + '" class="button" download>Descargar</a>';
                        html += '<button type="button" class="button button-primary restore-backup-btn" data-filename="' + backup.filename + '">Restaurar</button>';
                        html += '<button type="button" class="button button-link-delete delete-backup-btn" data-filename="' + backup.filename + '" style="color: #b32d2e;">Borrar</button>';
                        html += '</div>';
                        html += '</div>';
                    });
                    
                    html += '</div>';
                    $container.html(html);
                    
                    // Agregar evento a los botones de restaurar
                    $('.restore-backup-btn').on('click', function() {
                        const filename = $(this).data('filename');
                        restoreBackupFromServer(filename);
                    });
                    
                    // Agregar evento a los botones de borrar
                    $('.delete-backup-btn').on('click', function() {
                        const filename = $(this).data('filename');
                        const $btn = $(this);
                        deleteBackup(filename, $btn);
                    });
                } else {
                    $container.html('<div class="Nabi-backup-list-empty"><span class="dashicons dashicons-database"></span><p>No hay backups guardados en el servidor.</p></div>');
                }
            },
            error: function(xhr, status, error) {
                console.error('Nabi Backup: Error al cargar lista de backups', {
                    xhr: xhr,
                    status: status,
                    error: error,
                    responseText: xhr.responseText
                });
                
                let errorMessage = 'Error al cargar la lista de backups.';
                try {
                    if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                        errorMessage = xhr.responseJSON.data.message;
                    } else if (xhr.responseText) {
                        const response = JSON.parse(xhr.responseText);
                        if (response.data && response.data.message) {
                            errorMessage = response.data.message;
                        }
                    }
                } catch (e) {
                    errorMessage = 'Error de conexión: ' + error;
                }
                
                $container.html('<div class="Nabi-backup-list-empty"><p>' + errorMessage + '</p></div>');
            }
        });
    }
    
    /**
     * Elimina un backup del servidor
     */
    function deleteBackup(filename, $btn) {
        const confirmMessage = NabiBackup.strings.delete_confirm || '¿Estás seguro de que deseas eliminar este backup? Esta acción no se puede deshacer.';
        if (!confirm(confirmMessage)) {
            return;
        }
        
        // Deshabilitar botón mientras se procesa
        $btn.prop('disabled', true);
        const originalText = $btn.text();
        $btn.text('Eliminando...');
        
        $.ajax({
            url: NabiBackup.ajax_url,
            type: 'POST',
            data: {
                action: 'NABI_BACKUP_delete',
                nonce: NabiBackup.nonce,
                filename: filename
            },
            success: function(response) {
                if (response.success) {
                    // Recargar la lista de backups
                    loadBackupList();
                } else {
                    $btn.prop('disabled', false);
                    $btn.text(originalText);
                    var errorMessage = response.data && response.data.message ? response.data.message : 
                                      (response.data && response.data.error ? response.data.error : 'Error desconocido al eliminar');
                    alert('Borrado fallido: ' + errorMessage);
                }
            },
            error: function(xhr, status, error) {
                $btn.prop('disabled', false);
                $btn.text(originalText);
                
                var errorMessage = 'Error del servidor';
                if (xhr.status === 500) {
                    errorMessage = 'Error crítico del servidor (500). Verifique los logs de error de PHP.';
                } else if (xhr.responseText && xhr.responseText.indexOf('wp-die-message') !== -1) {
                    // Extraer mensaje de error de WordPress si es posible
                    const $temp = $('<div>').html(xhr.responseText);
                    errorMessage = $temp.find('.wp-die-message').text() || 'Error crítico de WordPress';
                }
                
                alert('Borrado fallido: ' + errorMessage);
                console.error('Nabi Backup: Error en borrado', {xhr, status, error});
            }

        });
    }
    
    /**
     * Restaura un backup desde el servidor
     */
    function restoreBackupFromServer(filename) {
        if (!confirm('¿Estás seguro de restaurar este backup? Esto reemplazará todo el contenido actual del sitio.')) {
            return;
        }
        
        // Cambiar a pestaña de importar y simular carga del archivo
        $('.nav-tab[href="#import"]').click();
        
        // Simular que el archivo fue seleccionado
        const $progress = $('#Nabi-backup-import-progress');
        const $result = $('#Nabi-backup-import-result');
        const $progressText = $progress.find('.Nabi-backup-progress-text');
        const $progressFill = $progress.find('.Nabi-backup-progress-fill');
        const $importBtn = $('#Nabi-backup-import-btn');
        
        $progress.show();
        $result.hide();
        $progressText.text(NabiBackup.strings.importing);
        $progressFill.css('width', '10%');
        
        // Polling para obtener progreso real
        const progressInterval = setInterval(function() {
            updateProgressBar($progressText, $progressFill);
        }, 2000);
        
        $.ajax({
            url: NabiBackup.ajax_url,
            type: 'POST',
            timeout: 0, // Sin límite de tiempo para restauraciones grandes
            data: {
                action: 'NABI_BACKUP_import',
                nonce: NabiBackup.nonce,
                temp_file: filename,
                from_server: true
            },
            success: function(response) {
                clearInterval(progressInterval);
                $progressFill.css('width', '100%');
                
                if (response.success) {
                    $progressText.text(NabiBackup.strings.success);
                    setTimeout(function() {
                        $progress.hide();
                        showResult($result, 'success', response.data.message + '<br><br><strong>Recomendación:</strong> El sitio ha sido restaurado. Refresca la página para ver los cambios.');
                        // Opcional: auto-recarga después de 3 segundos
                        // setTimeout(function() { location.reload(); }, 3000);
                    }, 1000);
                } else {
                    $progress.hide();
                    var errorMessage = response.data && response.data.message ? response.data.message : 
                                      (response.data && response.data.error ? response.data.error : NabiBackup.strings.error);
                    showResult($result, 'error', '<strong>Fallo en la restauración:</strong> ' + errorMessage);
                }
            },
            error: function(xhr, status, error) {
                console.error('Nabi Backup: Error en restauración', {xhr, status, error});
                clearInterval(progressInterval);
                $progress.hide();
                
                var errorMessage = 'Error de comunicación con el servidor';
                if (xhr.status === 500) {
                    errorMessage = 'Error crítico del servidor (500). Es probable que el backup sea demasiado grande o haya un problema de permisos.';
                } else if (status === 'timeout') {
                    errorMessage = 'El servidor tardó demasiado en responder (Timeout). Sin embargo, el proceso podría seguir ejecutándose en segundo plano.';
                } else {
                    try {
                        if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                            errorMessage = xhr.responseJSON.data.message;
                        } else if (xhr.responseText && xhr.responseText.indexOf('wp-die-message') !== -1) {
                            const $temp = $('<div>').html(xhr.responseText);
                            errorMessage = $temp.find('.wp-die-message').text() || 'Error interno de WordPress';
                        }
                    } catch (e) {
                        errorMessage = 'Error de conexión: ' + error;
                    }
                }
                
                showResult($result, 'error', '<strong>Error:</strong> ' + errorMessage);
                if (typeof $btn !== 'undefined') $btn.prop('disabled', false);
            }
        });
    }

    /**
     * Inicializa la funcionalidad de exportación
     */
    function initExport() {
        const $exportBtn = $('#Nabi-backup-export-btn');
        
        if ($exportBtn.length === 0) {
            console.warn('Nabi Backup: Botón de exportación no encontrado');
            return;
        }
        
        console.log('Nabi Backup: Registrando evento click en botón de exportación');
        
        $exportBtn.on('click', function(e) {
            e.preventDefault();
            console.log('Nabi Backup: Click en botón de exportación');
            if (!confirm((NabiBackup.strings.exporting || 'Exportando backup...') + '\n\n' + '¿Estás seguro de continuar?')) {
                return;
            }

            const $btn = $(this);
            const $progress = $('#Nabi-backup-export-progress');
            const $result = $('#Nabi-backup-export-result');
            const $progressText = $progress.find('.Nabi-backup-progress-text');
            const $progressFill = $progress.find('.Nabi-backup-progress-fill');
            const $progressDetails = $('#Nabi-backup-progress-details');

            $btn.prop('disabled', true);
            $progress.show();
            $result.hide();
            $progressDetails.html('');
            $progressText.text('Iniciando backup...');
            $progressFill.css('width', '5%');

            // Polling para obtener progreso
            const progressCheckInterval = setInterval(function() {
                updateProgressBar($progressText, $progressFill, $progressDetails);
            }, 2000); // Verificar cada 2 segundos

            console.log('Nabi Backup: Enviando petición AJAX de exportación', {
                url: NabiBackup.ajax_url,
                action: 'NABI_BACKUP_export'
            });
            
            $.ajax({
                url: NabiBackup.ajax_url,
                type: 'POST',
                data: {
                    action: 'NABI_BACKUP_export',
                    nonce: NabiBackup.nonce
                },
                success: function(response) {
                    console.log('Nabi Backup: Respuesta AJAX recibida', response);
                    clearInterval(progressCheckInterval);
                    $progressFill.css('width', '100%');

                    if (response.success) {
                        $progressText.text(NabiBackup.strings.success);
                        
                        // Descargar automáticamente
                        if (response.data && response.data.download_url) {
                            window.location.href = response.data.download_url;
                        }
                        
                        setTimeout(function() {
                            $progress.hide();
                            showResult($result, 'success', (response.data && response.data.message ? response.data.message : 'Operación completada') + '<br><br>El backup se ha guardado en el servidor y se está descargando automáticamente.');
                            $btn.prop('disabled', false);
                            // Recargar lista global de backups
                            loadBackupList();
                        }, 1000);
                    } else {
                        $progress.hide();
                        var errorMessage = response.data && response.data.message ? response.data.message : 
                                          (response.data && response.data.error ? response.data.error : NabiBackup.strings.error);
                        showResult($result, 'error', '<strong>Error:</strong> ' + errorMessage);
                        $btn.prop('disabled', false);
                    }
                },
                error: function(xhr, status, error) {
                    clearInterval(progressCheckInterval);
                    $progress.hide();
                    
                    console.error('Nabi Backup: Error en exportación', xhr, status, error);
                    
                    var errorMessage = 'Error de conexión con el servidor.';
                    if (status === 'timeout') {
                        errorMessage = 'Tiempo de espera agotado. El servidor tardó demasiado en responder (posiblemente por el tamaño del sitio).';
                    } else if (xhr.status === 500) {
                        errorMessage = 'Error interno del servidor (500). El servidor no pudo procesar la solicitud, posiblemente por falta de memoria o límites de tiempo del hosting.';
                    } else if (xhr.status === 0) {
                        errorMessage = 'No se ha podido contactar con el sitio. Verifique su conexión a internet o si hay un firewall bloqueando la petición.';
                    }
                    
                    showResult($result, 'error', '<strong>Error crítico:</strong> ' + errorMessage + ' (Status: ' + xhr.status + ')');
                    $btn.prop('disabled', false);
                }
            });
        });
    }

    /**
     * Actualiza la barra de progreso consultando el log
     */
    function updateProgressBar($progressText, $progressFill, $progressDetails = null) {
        $.ajax({
            url: NabiBackup.ajax_url,
            type: 'POST',
            data: {
                action: 'NABI_BACKUP_get_progress',
                nonce: NabiBackup.nonce
            },
            success: function(response) {
                if (response.success) {
                    let detailsHtml = '';
                    let currentPercentage = 0;
                    let phaseText = '';
                    
                    if ($progressDetails && response.data.progress && response.data.progress.length > 0) {
                        response.data.progress.forEach(function(line) {
                            const match = line.match(/\[INFO\](.*)/);
                            const errorMatch = line.match(/\[ERROR\](.*)/);
                            if (match) {
                                const message = match[1].trim();
                                if (message) detailsHtml += '<div class="progress-item">' + message + '</div>';
                            } else if (errorMatch) {
                                const message = errorMatch[1].trim();
                                if (message) detailsHtml += '<div class="progress-item" style="color: #d63638;">[ERROR] ' + message + '</div>';
                            }
                        });
                        $progressDetails.html(detailsHtml);
                        $progressDetails.scrollTop($progressDetails[0].scrollHeight);
                    }
                    
                    if (response.data.current_phase === 'database' && response.data.database_percentage !== null) {
                        currentPercentage = response.data.database_percentage;
                        phaseText = 'Base de datos: ' + currentPercentage + '%';
                    } else if (response.data.current_phase === 'files' && response.data.files_percentage !== null) {
                        currentPercentage = response.data.files_percentage;
                        phaseText = 'Archivos: ' + currentPercentage + '%';
                    } else if (response.data.database_percentage !== null && response.data.database_percentage === 100) {
                        if (response.data.files_percentage !== null) {
                            currentPercentage = response.data.files_percentage;
                            phaseText = 'Archivos: ' + currentPercentage + '%';
                        } else {
                            phaseText = 'Base de datos completada...';
                        }
                    } else if (response.data.database_percentage !== null) {
                        currentPercentage = response.data.database_percentage;
                        phaseText = 'Base de datos: ' + currentPercentage + '%';
                    }
                    
                    if (currentPercentage > 0) {
                        $progressFill.css('width', currentPercentage + '%');
                        $progressText.text(phaseText);
                    }
                }
            }
        });
    }

    /**
     * Inicializa la funcionalidad de importación
     */
    function initImport() {
        const $dropzone = $('#Nabi-backup-dropzone');
        const $fileInput = $('#Nabi-backup-file-input');
        const $selectBtn = $('#Nabi-backup-select-file');
        const $fileInfo = $('#Nabi-backup-file-info');
        const $importBtn = $('#Nabi-backup-import-btn');
        const $importActions = $('.Nabi-backup-import-actions');

        // Click en el botón de seleccionar
        $selectBtn.on('click', function() {
            $fileInput.click();
        });

        // Click en el dropzone
        $dropzone.on('click', function() {
            $fileInput.click();
        });

        // Cambio de archivo
        $fileInput.on('change', function() {
            handleFileSelect(this.files[0]);
        });

        // Drag and drop
        $dropzone.on('dragover', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).addClass('dragover');
        });

        $dropzone.on('dragleave', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).removeClass('dragover');
        });

        $dropzone.on('drop', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).removeClass('dragover');

            const files = e.originalEvent.dataTransfer.files;
            if (files.length > 0) {
                handleFileSelect(files[0]);
            }
        });

        // Eliminar archivo
        $('.Nabi-backup-remove-file').on('click', function() {
            selectedFile = null;
            tempFileName = null;
            $fileInput.val('');
            $fileInfo.hide();
            $importActions.hide();
        });

        // Importar
        $importBtn.on('click', function() {
            if (!selectedFile || !tempFileName) {
                return;
            }

            if (!confirm('¿Estás seguro de restaurar este backup? Esto reemplazará todo el contenido actual del sitio.')) {
                return;
            }

            const $btn = $(this);
            const $progress = $('#Nabi-backup-import-progress');
            const $result = $('#Nabi-backup-import-result');
            const $progressText = $progress.find('.Nabi-backup-progress-text');
            const $progressFill = $progress.find('.Nabi-backup-progress-fill');

            $btn.prop('disabled', true);
            $progress.show();
            $result.hide();
            $progressText.text(NabiBackup.strings.importing);
            $progressFill.css('width', '10%');

            // Polling para obtener progreso real
            const progressInterval = setInterval(function() {
                updateProgressBar($progressText, $progressFill);
            }, 2000);
            
            $.ajax({
                url: NabiBackup.ajax_url,
                type: 'POST',
                data: {
                    action: 'NABI_BACKUP_import',
                    nonce: NabiBackup.nonce,
                    temp_file: tempFileName
                },
                success: function(response) {
                    clearInterval(progressInterval);
                    $progressFill.css('width', '100%');

                    if (response.success) {
                        $progressText.text(NabiBackup.strings.success);
                        setTimeout(function() {
                            $progress.hide();
                            showResult($result, 'success', response.data.message + '<br><br><strong>Nota:</strong> Es recomendable refrescar la página después de la importación.');
                            $btn.prop('disabled', false);
                            // Resetear formulario
                            selectedFile = null;
                            tempFileName = null;
                            $fileInput.val('');
                            $fileInfo.hide();
                            $importActions.hide();
                        }, 1000);
                    } else {
                        $progress.hide();
                        // Mostrar el error específico
                        var errorMessage = response.data && response.data.message ? response.data.message : 
                                          (response.data && response.data.error ? response.data.error : NabiBackup.strings.error);
                        showResult($result, 'error', '<strong>Error:</strong> ' + errorMessage);
                        $btn.prop('disabled', false);
                    }
                },
                error: function(xhr, status, error) {
                    clearInterval(progressInterval);
                    $progress.hide();
                    
                    // Intentar obtener el mensaje de error de la respuesta
                    var errorMessage = NabiBackup.strings.error;
                    try {
                        if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                            errorMessage = xhr.responseJSON.data.message;
                        } else if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.error) {
                            errorMessage = xhr.responseJSON.data.error;
                        } else if (xhr.responseText) {
                            var response = JSON.parse(xhr.responseText);
                            if (response.data && response.data.message) {
                                errorMessage = response.data.message;
                            } else if (response.data && response.data.error) {
                                errorMessage = response.data.error;
                            }
                        }
                    } catch (e) {
                        // Si no se puede parsear, usar el mensaje genérico
                        errorMessage = 'Error de conexión: ' + error;
                    }
                    
                    showResult($result, 'error', '<strong>Error:</strong> ' + errorMessage);
                    $btn.prop('disabled', false);
                }
            });
        });
    }

    /**
     * Maneja la selección de archivo
     */
    function handleFileSelect(file) {
        if (!file) {
            return;
        }

        // Validar que sea ZIP o .Nabi
        const fileName = file.name.toLowerCase();
        if (!fileName.endsWith('.zip') && !fileName.endsWith('.Nabi')) {
            alert('El archivo debe ser un backup válido (.zip o .Nabi)');
            return;
        }

        selectedFile = file;
        const $fileInfo = $('#Nabi-backup-file-info');
        const $fileName = $fileInfo.find('.Nabi-backup-file-name');
        const $fileMeta = $fileInfo.find('.Nabi-backup-file-meta');
        const $importActions = $('.Nabi-backup-import-actions');
        const $result = $('#Nabi-backup-import-result');

        $fileName.text(file.name);
        $fileMeta.html('<strong>Tamaño:</strong> ' + formatFileSize(file.size));
        $fileInfo.show();
        $importActions.hide();
        $result.hide();

        // Validar archivo
        validateBackupFile(file);
    }

    /**
     * Valida el archivo de backup
     */
    function validateBackupFile(file) {
        const $progress = $('#Nabi-backup-import-progress');
        const $result = $('#Nabi-backup-import-result');
        const $progressText = $progress.find('.Nabi-backup-progress-text');
        const $progressFill = $progress.find('.Nabi-backup-progress-fill');
        const $importActions = $('.Nabi-backup-import-actions');
        const $fileMeta = $('#Nabi-backup-file-info .Nabi-backup-file-meta');

        $progress.show();
        $progressText.text(NabiBackup.strings.validating);
        $progressFill.css('width', '50%');

        const formData = new FormData();
        formData.append('action', 'NABI_BACKUP_validate');
        formData.append('nonce', NabiBackup.nonce);
        formData.append('backup_file', file);

        $.ajax({
            url: NabiBackup.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                $progress.hide();

                if (response.success) {
                    tempFileName = response.data.temp_file;
                    const info = response.data.info;
                    
                    let metaHtml = '<strong>Tamaño:</strong> ' + formatFileSize(selectedFile.size) + '<br>';
                    if (info.wp_version) {
                        metaHtml += '<strong>WordPress:</strong> ' + info.wp_version + '<br>';
                    }
                    if (info.date) {
                        metaHtml += '<strong>Fecha:</strong> ' + info.date;
                    }
                    $fileMeta.html(metaHtml);
                    
                    $importActions.show();
                    showResult($result, 'success', 'Archivo de backup válido. Puedes proceder con la importación.');
                } else {
                    // Mostrar el error específico
                    var errorMessage = response.data && response.data.message ? response.data.message : 
                                      (response.data && response.data.error ? response.data.error : 'El archivo no es válido');
                    showResult($result, 'error', '<strong>Error:</strong> ' + errorMessage);
                    selectedFile = null;
                    $('#Nabi-backup-file-input').val('');
                    $('#Nabi-backup-file-info').hide();
                }
            },
            error: function(xhr, status, error) {
                $progress.hide();
                
                // Intentar obtener el mensaje de error de la respuesta
                var errorMessage = NabiBackup.strings.error;
                try {
                    if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                        errorMessage = xhr.responseJSON.data.message;
                    } else if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.error) {
                        errorMessage = xhr.responseJSON.data.error;
                    } else if (xhr.responseText) {
                        var response = JSON.parse(xhr.responseText);
                        if (response.data && response.data.message) {
                            errorMessage = response.data.message;
                        } else if (response.data && response.data.error) {
                            errorMessage = response.data.error;
                        }
                    }
                } catch (e) {
                    // Si no se puede parsear, usar el mensaje genérico
                    errorMessage = 'Error de conexión: ' + error;
                }
                
                showResult($result, 'error', '<strong>Error:</strong> ' + errorMessage);
                selectedFile = null;
                $('#Nabi-backup-file-input').val('');
                $('#Nabi-backup-file-info').hide();
            }
        });
    }

    /**
     * Muestra un mensaje de resultado
     */
    function showResult($element, type, message) {
        $element
            .removeClass('success error')
            .addClass(type)
            .html('<span class="dashicons dashicons-' + (type === 'success' ? 'yes-alt' : 'warning') + '"></span>' + message)
            .show();
    }

    /**
     * Formatea el tamaño del archivo
     */
    function formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
    }
    
    /**
     * Inicializa la gestión de cuenta
     */
    function initAccountManagement() {
        // Conectar cuenta
        $('#Nabi-backup-connect-account').on('click', function() {
            if (!confirm(NabiBackup.strings.connect_confirm || '¿Deseas conectar tu cuenta para activar las funciones Pro/Ultra?')) {
                return;
            }
            
            $.ajax({
                url: NabiBackup.ajax_url,
                type: 'POST',
                data: {
                    action: 'NABI_BACKUP_connect_account',
                    nonce: NabiBackup.nonce
                },
                success: function(response) {
                    if (response.success && response.data.auth_url) {
                        // Redirigir a la página de autorización
                        window.location.href = response.data.auth_url;
                    } else {
                        alert(response.data.message || 'Error al obtener URL de autorización');
                    }
                },
                error: function() {
                    alert('Error de conexión');
                }
            });
        });
        
        // Desconectar cuenta
        $('#Nabi-backup-disconnect-account').on('click', function() {
            if (!confirm(NabiBackup.strings.disconnect_confirm || '¿Estás seguro de que deseas desconectar tu cuenta?')) {
                return;
            }
            
            $.ajax({
                url: NabiBackup.ajax_url,
                type: 'POST',
                data: {
                    action: 'NABI_BACKUP_disconnect_account',
                    nonce: NabiBackup.nonce
                },
                success: function(response) {
                    if (response.success) {
                        alert(response.data.message || 'Cuenta desconectada correctamente');
                        location.reload();
                    } else {
                        alert(response.data.message || 'Error al desconectar la cuenta');
                    }
                },
                error: function() {
                    alert('Error de conexión');
                }
            });
        });
    }

    /**
     * Inicializa la configuración
     */
    function initSettings() {
        console.log('Nabi Backup: Inicializando configuración');
        
        // Formulario de configuración
        $('#Nabi-backup-settings-form').on('submit', function(e) {
            e.preventDefault();
            console.log('Nabi Backup: Enviando formulario de configuración');
            
            const $form = $(this);
            const $result = $('#Nabi-backup-settings-result');
            
            const formData = {};
            $form.find('input[type="checkbox"]').each(function() {
                formData[$(this).attr('name')] = $(this).is(':checked') ? 1 : 0;
            });
            
            $.ajax({
                url: NabiBackup.ajax_url,
                type: 'POST',
                data: {
                    action: 'NABI_BACKUP_save_settings',
                    nonce: NabiBackup.nonce,
                    ...formData
                },
                success: function(response) {
                    console.log('Nabi Backup: Respuesta de guardar configuración', response);
                    if (response.success) {
                        showResult($result, 'success', response.data.message);
                    } else {
                        showResult($result, 'error', response.data.message || 'Error al guardar');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Nabi Backup: Error al guardar configuración', xhr, status, error);
                    showResult($result, 'error', 'Error de conexión');
                }
            });
        });
        
        // Botón de prueba de conexión
        const $testBtn = $('#Nabi-backup-test-connection');
        if ($testBtn.length > 0) {
            console.log('Nabi Backup: Registrando evento click en botón de prueba de conexión');
            
            $testBtn.on('click', function(e) {
                e.preventDefault();
                console.log('Nabi Backup: Click en botón de prueba de conexión');
                
                const $btn = $(this);
                const $result = $('#Nabi-backup-test-result');
                
                $btn.prop('disabled', true).text('Probando...');
                $result.hide().removeClass('success error');
                
                $.ajax({
                    url: NabiBackup.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'NABI_BACKUP_test_connection',
                        nonce: NabiBackup.nonce
                    },
                    beforeSend: function() {
                        console.log('Nabi Backup: Enviando petición AJAX para probar conexión');
                    },
                    success: function(response) {
                        console.log('Nabi Backup: Respuesta de prueba de conexión', response);
                        if (response.success) {
                            let message = response.data.message || 'Conexión exitosa';
                            if (response.data.account_info) {
                                message += '<br><br><strong>Información de la cuenta:</strong><br>';
                                message += 'Email: ' + (response.data.account_info.user_email || 'N/A') + '<br>';
                                message += 'Versión: ' + (response.data.account_info.version || 'N/A');
                            }
                            showResult($result, 'success', message);
                        } else {
                            showResult($result, 'error', response.data.message || 'Error al probar la conexión');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Nabi Backup: Error en petición AJAX de prueba de conexión', xhr, status, error);
                        let errorMessage = 'Error de conexión';
                        try {
                            if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                                errorMessage = xhr.responseJSON.data.message;
                            }
                        } catch (e) {
                            errorMessage = 'Error de conexión: ' + error;
                        }
                        showResult($result, 'error', errorMessage);
                    },
                    complete: function() {
                        $btn.prop('disabled', false).text('Probar Conexión');
                        $result.show();
                    }
                });
            });
        }
    }

})(jQuery);


