(function($) {
    'use strict';

    let selectedFile = null;
    let tempFileName = null;

    $(document).ready(function() {
        initTabs();
        initExport();
        initImport();
        initRestore();
        initAccountManagement();
        initSettings();
    });

    /**
     * Inicializa el sistema de pestañas
     */
    function initTabs() {
        $('.dn325-backup-tabs .nav-tab').on('click', function(e) {
            e.preventDefault();
            
            const target = $(this).attr('href');
            
            // Actualizar pestañas
            $('.nav-tab').removeClass('nav-tab-active');
            $(this).addClass('nav-tab-active');
            
            // Mostrar contenido
            $('.tab-pane').removeClass('active');
            $(target + '-tab').addClass('active');
            
            // Si es la pestaña de restaurar, cargar la lista
            if (target === '#restore') {
                loadBackupList();
            }
        });
    }
    
    /**
     * Inicializa la funcionalidad de restaurar
     */
    function initRestore() {
        // La lista se carga cuando se hace clic en la pestaña
    }
    
    /**
     * Carga la lista de backups guardados
     */
    function loadBackupList() {
        const $container = $('#dn325-backup-list-container');
        
        $.ajax({
            url: dn325Backup.ajax_url,
            type: 'POST',
            data: {
                action: 'dn325_backup_list',
                nonce: dn325Backup.nonce
            },
            success: function(response) {
                if (response.success && response.data.backups.length > 0) {
                    let html = '<div class="dn325-backup-list">';
                    
                    response.data.backups.forEach(function(backup) {
                        html += '<div class="dn325-backup-list-item">';
                        html += '<div class="dn325-backup-list-item-info">';
                        html += '<div class="dn325-backup-list-item-name">' + backup.filename + '</div>';
                        html += '<div class="dn325-backup-list-item-meta">';
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
                        html += '<div class="dn325-backup-list-item-actions">';
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
                    $container.html('<div class="dn325-backup-list-empty"><span class="dashicons dashicons-database"></span><p>No hay backups guardados en el servidor.</p></div>');
                }
            },
            error: function() {
                $container.html('<div class="dn325-backup-list-empty"><p>Error al cargar la lista de backups.</p></div>');
            }
        });
    }
    
    /**
     * Elimina un backup del servidor
     */
    function deleteBackup(filename, $btn) {
        const confirmMessage = dn325Backup.strings.delete_confirm || '¿Estás seguro de que deseas eliminar este backup? Esta acción no se puede deshacer.';
        if (!confirm(confirmMessage)) {
            return;
        }
        
        // Deshabilitar botón mientras se procesa
        $btn.prop('disabled', true);
        const originalText = $btn.text();
        $btn.text('Eliminando...');
        
        $.ajax({
            url: dn325Backup.ajax_url,
            type: 'POST',
            data: {
                action: 'dn325_backup_delete',
                nonce: dn325Backup.nonce,
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
                                      (response.data && response.data.error ? response.data.error : 'Error al eliminar el backup');
                    alert('Error: ' + errorMessage);
                }
            },
            error: function(xhr, status, error) {
                $btn.prop('disabled', false);
                $btn.text(originalText);
                
                var errorMessage = 'Error de conexión';
                try {
                    if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                        errorMessage = xhr.responseJSON.data.message;
                    } else if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.error) {
                        errorMessage = xhr.responseJSON.data.error;
                    }
                } catch (e) {
                    errorMessage = 'Error de conexión: ' + error;
                }
                
                alert('Error: ' + errorMessage);
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
        const $progress = $('#dn325-backup-import-progress');
        const $result = $('#dn325-backup-import-result');
        const $progressText = $progress.find('.dn325-backup-progress-text');
        const $progressFill = $progress.find('.dn325-backup-progress-fill');
        const $importBtn = $('#dn325-backup-import-btn');
        
        $progress.show();
        $result.hide();
        $progressText.text(dn325Backup.strings.importing);
        $progressFill.css('width', '10%');
        
        // Simular progreso
        let progress = 10;
        const progressInterval = setInterval(function() {
            progress += 3;
            if (progress < 90) {
                $progressFill.css('width', progress + '%');
            }
        }, 500);
        
        $.ajax({
            url: dn325Backup.ajax_url,
            type: 'POST',
            data: {
                action: 'dn325_backup_import',
                nonce: dn325Backup.nonce,
                temp_file: filename,
                from_server: true
            },
            success: function(response) {
                clearInterval(progressInterval);
                $progressFill.css('width', '100%');
                
                if (response.success) {
                    $progressText.text(dn325Backup.strings.success);
                    setTimeout(function() {
                        $progress.hide();
                        showResult($result, 'success', response.data.message + '<br><br><strong>Nota:</strong> Es recomendable refrescar la página después de la importación.');
                    }, 1000);
                } else {
                    $progress.hide();
                    var errorMessage = response.data && response.data.message ? response.data.message : 
                                      (response.data && response.data.error ? response.data.error : dn325Backup.strings.error);
                    showResult($result, 'error', '<strong>Error:</strong> ' + errorMessage);
                }
            },
            error: function(xhr, status, error) {
                clearInterval(progressInterval);
                $progress.hide();
                
                var errorMessage = dn325Backup.strings.error;
                try {
                    if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                        errorMessage = xhr.responseJSON.data.message;
                    } else if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.error) {
                        errorMessage = xhr.responseJSON.data.error;
                    }
                } catch (e) {
                    errorMessage = 'Error de conexión: ' + error;
                }
                
                showResult($result, 'error', '<strong>Error:</strong> ' + errorMessage);
            }
        });
    }

    /**
     * Inicializa la funcionalidad de exportación
     */
    function initExport() {
        $('#dn325-backup-export-btn').on('click', function() {
            if (!confirm(dn325Backup.strings.exporting + '\n\n' + '¿Estás seguro de continuar?')) {
                return;
            }

            const $btn = $(this);
            const $progress = $('#dn325-backup-export-progress');
            const $result = $('#dn325-backup-export-result');
            const $progressText = $progress.find('.dn325-backup-progress-text');
            const $progressFill = $progress.find('.dn325-backup-progress-fill');

            $btn.prop('disabled', true);
            $progress.show();
            $result.hide();
            const $progressDetails = $('#dn325-backup-progress-details');
            $progressDetails.html('');
            $progressText.text('Iniciando backup...');
            $progressFill.css('width', '5%');

            // Polling para obtener progreso
            let progressCheckInterval = setInterval(function() {
                $.ajax({
                    url: dn325Backup.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'dn325_backup_get_progress',
                        nonce: dn325Backup.nonce
                    },
                    success: function(response) {
                        if (response.success && response.data.progress.length > 0) {
                            let detailsHtml = '';
                            response.data.progress.forEach(function(line) {
                                // Extraer solo el mensaje relevante
                                const match = line.match(/\[INFO\](.*)/);
                                if (match) {
                                    const message = match[1].trim();
                                    if (message) {
                                        detailsHtml += '<div class="progress-item">' + message + '</div>';
                                    }
                                }
                            });
                            $progressDetails.html(detailsHtml);
                            // Scroll al final
                            $progressDetails.scrollTop($progressDetails[0].scrollHeight);
                            
                            // Actualizar barra de progreso basado en el contenido
                            const progressLines = response.data.progress.length;
                            const estimatedProgress = Math.min(90, (progressLines / 200) * 100);
                            $progressFill.css('width', estimatedProgress + '%');
                        }
                    }
                });
            }, 2000); // Verificar cada 2 segundos

            $.ajax({
                url: dn325Backup.ajax_url,
                type: 'POST',
                data: {
                    action: 'dn325_backup_export',
                    nonce: dn325Backup.nonce
                },
                success: function(response) {
                    clearInterval(progressCheckInterval);
                    $progressFill.css('width', '100%');

                    if (response.success) {
                        $progressText.text(dn325Backup.strings.success);
                        
                        // Descargar automáticamente
                        if (response.data.download_url) {
                            window.location.href = response.data.download_url;
                        }
                        
                        setTimeout(function() {
                            $progress.hide();
                            showResult($result, 'success', response.data.message + '<br><br>El backup se ha guardado en el servidor y se está descargando automáticamente.');
                            $btn.prop('disabled', false);
                            // Recargar lista si estamos en la pestaña de restaurar
                            if ($('#restore-tab').hasClass('active')) {
                                loadBackupList();
                            }
                        }, 1000);
                    } else {
                        $progress.hide();
                        // Mostrar el error específico
                        var errorMessage = response.data && response.data.message ? response.data.message : 
                                          (response.data && response.data.error ? response.data.error : dn325Backup.strings.error);
                        showResult($result, 'error', '<strong>Error:</strong> ' + errorMessage);
                        $btn.prop('disabled', false);
                    }
                },
                error: function(xhr, status, error) {
                    clearInterval(progressInterval);
                    $progress.hide();
                    
                    // Intentar obtener el mensaje de error de la respuesta
                    var errorMessage = dn325Backup.strings.error;
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
     * Inicializa la funcionalidad de importación
     */
    function initImport() {
        const $dropzone = $('#dn325-backup-dropzone');
        const $fileInput = $('#dn325-backup-file-input');
        const $selectBtn = $('#dn325-backup-select-file');
        const $fileInfo = $('#dn325-backup-file-info');
        const $importBtn = $('#dn325-backup-import-btn');
        const $importActions = $('.dn325-backup-import-actions');

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
        $('.dn325-backup-remove-file').on('click', function() {
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
            const $progress = $('#dn325-backup-import-progress');
            const $result = $('#dn325-backup-import-result');
            const $progressText = $progress.find('.dn325-backup-progress-text');
            const $progressFill = $progress.find('.dn325-backup-progress-fill');

            $btn.prop('disabled', true);
            $progress.show();
            $result.hide();
            $progressText.text(dn325Backup.strings.importing);
            $progressFill.css('width', '10%');

            // Simular progreso
            let progress = 10;
            const progressInterval = setInterval(function() {
                progress += 3;
                if (progress < 90) {
                    $progressFill.css('width', progress + '%');
                }
            }, 500);

            $.ajax({
                url: dn325Backup.ajax_url,
                type: 'POST',
                data: {
                    action: 'dn325_backup_import',
                    nonce: dn325Backup.nonce,
                    temp_file: tempFileName
                },
                success: function(response) {
                    clearInterval(progressInterval);
                    $progressFill.css('width', '100%');

                    if (response.success) {
                        $progressText.text(dn325Backup.strings.success);
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
                                          (response.data && response.data.error ? response.data.error : dn325Backup.strings.error);
                        showResult($result, 'error', '<strong>Error:</strong> ' + errorMessage);
                        $btn.prop('disabled', false);
                    }
                },
                error: function(xhr, status, error) {
                    clearInterval(progressInterval);
                    $progress.hide();
                    
                    // Intentar obtener el mensaje de error de la respuesta
                    var errorMessage = dn325Backup.strings.error;
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

        // Validar que sea ZIP
        if (!file.name.toLowerCase().endsWith('.zip')) {
            alert('El archivo debe ser un ZIP');
            return;
        }

        selectedFile = file;
        const $fileInfo = $('#dn325-backup-file-info');
        const $fileName = $fileInfo.find('.dn325-backup-file-name');
        const $fileMeta = $fileInfo.find('.dn325-backup-file-meta');
        const $importActions = $('.dn325-backup-import-actions');
        const $result = $('#dn325-backup-import-result');

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
        const $progress = $('#dn325-backup-import-progress');
        const $result = $('#dn325-backup-import-result');
        const $progressText = $progress.find('.dn325-backup-progress-text');
        const $progressFill = $progress.find('.dn325-backup-progress-fill');
        const $importActions = $('.dn325-backup-import-actions');
        const $fileMeta = $('#dn325-backup-file-info .dn325-backup-file-meta');

        $progress.show();
        $progressText.text(dn325Backup.strings.validating);
        $progressFill.css('width', '50%');

        const formData = new FormData();
        formData.append('action', 'dn325_backup_validate');
        formData.append('nonce', dn325Backup.nonce);
        formData.append('backup_file', file);

        $.ajax({
            url: dn325Backup.ajax_url,
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
                    $('#dn325-backup-file-input').val('');
                    $('#dn325-backup-file-info').hide();
                }
            },
            error: function(xhr, status, error) {
                $progress.hide();
                
                // Intentar obtener el mensaje de error de la respuesta
                var errorMessage = dn325Backup.strings.error;
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
                $('#dn325-backup-file-input').val('');
                $('#dn325-backup-file-info').hide();
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
        $('#dn325-backup-connect-account').on('click', function() {
            if (!confirm(dn325Backup.strings.connect_confirm || '¿Deseas conectar tu cuenta para activar las funciones Pro/Ultra?')) {
                return;
            }
            
            $.ajax({
                url: dn325Backup.ajax_url,
                type: 'POST',
                data: {
                    action: 'dn325_backup_connect_account',
                    nonce: dn325Backup.nonce
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
        $('#dn325-backup-disconnect-account').on('click', function() {
            if (!confirm(dn325Backup.strings.disconnect_confirm || '¿Estás seguro de que deseas desconectar tu cuenta?')) {
                return;
            }
            
            $.ajax({
                url: dn325Backup.ajax_url,
                type: 'POST',
                data: {
                    action: 'dn325_backup_disconnect_account',
                    nonce: dn325Backup.nonce
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
        $('#dn325-backup-settings-form').on('submit', function(e) {
            e.preventDefault();
            
            const $form = $(this);
            const $result = $('#dn325-backup-settings-result');
            
            const formData = {};
            $form.find('input[type="checkbox"]').each(function() {
                formData[$(this).attr('name')] = $(this).is(':checked') ? 1 : 0;
            });
            
            $.ajax({
                url: dn325Backup.ajax_url,
                type: 'POST',
                data: {
                    action: 'dn325_backup_save_settings',
                    nonce: dn325Backup.nonce,
                    ...formData
                },
                success: function(response) {
                    if (response.success) {
                        showResult($result, 'success', response.data.message);
                    } else {
                        showResult($result, 'error', response.data.message || 'Error al guardar');
                    }
                },
                error: function() {
                    showResult($result, 'error', 'Error de conexión');
                }
            });
        });
    }

})(jQuery);
