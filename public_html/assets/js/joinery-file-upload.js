/*
 * FormWriter's bulk file uploader (FormWriterV2Base::file_upload_full()):
 * drag and drop, a queue, and one POST per file to
 * /admin/admin_file_upload_process. The drop zone carries the server's limits
 * as data-allowed-extensions, data-max-size (bytes) and data-max-size-display.
 *
 * @version 1.0
 */
(function() {
    'use strict';

    function initFileUploader() {
    let selectedFiles = [];

    // The drop zone first: it carries the server's limits.
    const dropZone = document.getElementById('file-drop-zone');
    if (!dropZone) return;
    const allowedExtensions = dropZone.dataset.allowedExtensions || '';
    const allowedTypes = new RegExp('\\.(' + allowedExtensions.replace(/,/g, '|') + ')$', 'i');
    const maxFileSize = parseInt(dropZone.dataset.maxSize, 10) || 0; // Maximum file size in bytes

    // DOM elements
    const fileInput = document.getElementById('file-input');
    const browseBtn = document.getElementById('browse-btn');
    const uploadAllBtn = document.getElementById('upload-all-btn');
    const clearAllBtn = document.getElementById('clear-all-btn');
    const filesList = document.getElementById('files-list');
    const noFilesMessage = document.getElementById('no-files-message');
    const overallProgress = document.getElementById('overall-progress');
    const progressBar = document.getElementById('overall-progress-bar');

    // File size formatter
    function formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

    // Generate unique ID for each file
    function generateFileId() {
        return 'file_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
    }

    // Get file icon based on extension
    function getFileIcon(filename) {
        const ext = filename.split('.').pop().toLowerCase();
        const iconMap = {
            'pdf': '📕',
            'doc': '📘',
            'docx': '📘',
            'xls': '📗',
            'xlsx': '📗',
            'jpg': '🖼️',
            'jpeg': '🖼️',
            'png': '🖼️',
            'gif': '🖼️',
            'mp3': '🎵',
            'mp4': '🎬',
            'm4a': '🎵'
        };
        return iconMap[ext] || '📄';
    }

    // Add files to the list
    function addFiles(files) {
        Array.from(files).forEach(file => {
            // Validate file type using server setting
            if (!allowedTypes.test(file.name)) {
                showToast('Invalid file type: ' + file.name + '. Allowed: ' + allowedExtensions, 'error');
                return;
            }

            // Validate file size using server limit
            if (file.size > maxFileSize) {
                showToast('File too large: ' + file.name + '. Maximum size: ' + (dropZone.dataset.maxSizeDisplay || ''), 'error');
                return;
            }

            const fileId = generateFileId();
            const fileObj = {
                id: fileId,
                file: file,
                status: 'pending'
            };

            selectedFiles.push(fileObj);
            renderFileRow(fileObj);
        });

        updateUI();
    }

    // Render a file row in the table
    function renderFileRow(fileObj) {
        noFilesMessage.style.display = 'none';

        const fileIcon = getFileIcon(fileObj.file.name);
        const rowHTML = `
            <tr data-file-id="${fileObj.id}" class="file-row" style="border-bottom: 1px solid #eee;">
                <td style="padding: 10px;">
                    <div style="display: flex; align-items: center;">
                        <span style="margin-right: 8px; font-size: 20px;">${fileIcon}</span>
                        <span class="file-name">${fileObj.file.name}</span>
                    </div>
                </td>
                <td class="file-size" style="padding: 10px;">${formatFileSize(fileObj.file.size)}</td>
                <td class="file-status" style="padding: 10px;">
                    <span style="padding: 2px 8px; background: #6c757d; color: white; border-radius: 3px; font-size: 12px;">Ready to upload</span>
                </td>
                <td class="file-actions" style="padding: 10px;">
                    <button type="button" class="button small upload-single-btn" title="Upload this file" style="padding: 4px 8px; font-size: 12px;">
                        ⬆️
                    </button>
                    <button type="button" class="button small danger remove-file-btn" title="Remove this file" style="padding: 4px 8px; font-size: 12px; margin-left: 5px;">
                        ❌
                    </button>
                </td>
            </tr>
        `;

        filesList.insertAdjacentHTML('beforeend', rowHTML);
    }

    // Update UI state
    function updateUI() {
        const hasFiles = selectedFiles.length > 0;
        const pendingFilesCount = selectedFiles.filter(f => f.status === 'pending').length;

        uploadAllBtn.disabled = pendingFilesCount === 0;
        clearAllBtn.disabled = !hasFiles;

        if (!hasFiles) {
            noFilesMessage.style.display = '';
        }

        // Update button text with count
        if (pendingFilesCount > 0) {
            uploadAllBtn.innerHTML = `⬆️ Upload All (${pendingFilesCount})`;
        } else {
            uploadAllBtn.innerHTML = '⬆️ Upload All';
        }
    }

    // Show toast notification
    function showToast(message, type = 'info') {
        console.log(type + ': ' + message);
        // Simple alert for now - can be enhanced with proper toast notifications
        if (type === 'error') {
            alert('Error: ' + message);
        }
    }

    // Upload a single file
    function uploadFile(fileObj) {
        return new Promise((resolve, reject) => {
            const formData = new FormData();
            formData.append('files[]', fileObj.file);

            // Add any additional form data
            const hiddenForm = document.getElementById('hidden-form-data');
            if (hiddenForm) {
                hiddenForm.querySelectorAll('input').forEach(function(input) {
                    formData.append(input.name, input.value);
                });
            }

            const row = document.querySelector(`.file-row[data-file-id="${fileObj.id}"]`);
            const statusCell = row.querySelector('.file-status');
            const actionsCell = row.querySelector('.file-actions');

            // Update UI to uploading state
            statusCell.innerHTML = '<span style="padding: 2px 8px; background: #007bff; color: white; border-radius: 3px; font-size: 12px;">Uploading...</span>';
            actionsCell.innerHTML = `
                <div style="display: flex; align-items: center;">
                    <progress value="0" max="100" style="width: 60px; height: 20px; margin-right: 8px;">0%</progress>
                    <span style="color: #666; font-size: 12px;">0%</span>
                </div>
            `;

            // Create XMLHttpRequest for progress tracking
            const xhr = new XMLHttpRequest();

            xhr.upload.addEventListener('progress', function(e) {
                if (e.lengthComputable) {
                    const progress = Math.round((e.loaded / e.total) * 100);
                    const progressEl = actionsCell.querySelector('progress');
                    const spanEl = actionsCell.querySelector('span');
                    if (progressEl) {
                        progressEl.value = progress;
                        progressEl.textContent = progress + '%';
                    }
                    if (spanEl) {
                        spanEl.textContent = progress + '%';
                    }
                    statusCell.innerHTML = `<span style="padding: 2px 8px; background: #007bff; color: white; border-radius: 3px; font-size: 12px;">Uploading ${progress}%</span>`;
                }
            });

            xhr.addEventListener('load', function() {
                if (xhr.status === 200) {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        if (response.files && response.files[0]) {
                            const file = response.files[0];
                            if (file.url) {
                                // Success. A warning is a file that was saved but could not have
                                // its sizes made (a photo over the decode ceiling): say so on the row.
                                statusCell.innerHTML = '<span style="padding: 2px 8px; background: #28a745; color: white; border-radius: 3px; font-size: 12px;">✓ Upload successful</span>';
                                if (file.warning) {
                                    const warn = document.createElement('div');
                                    warn.style.cssText = 'margin-top: 4px; font-size: 12px; color: #856404;';
                                    warn.textContent = file.warning;
                                    statusCell.appendChild(warn);
                                }
                                actionsCell.innerHTML = `
                                    <a href="${file.url}" target="_blank" class="button small success" title="Download file" style="padding: 4px 8px; font-size: 12px; text-decoration: none;">
                                        ⬇️
                                    </a>
                                    <button type="button" class="button small danger remove-file-btn" title="Remove from list" style="padding: 4px 8px; font-size: 12px; margin-left: 5px;">
                                        ❌
                                    </button>
                                `;

                                // Make filename clickable if we have a file ID
                                if (file.file_id) {
                                    const nameElement = row.querySelector('.file-name');
                                    const fileName = nameElement.textContent;
                                    const fileIcon = getFileIcon(fileName);

                                    nameElement.parentElement.innerHTML = `
                                        <div style="display: flex; align-items: center;">
                                            <span style="margin-right: 8px; font-size: 20px;">${fileIcon}</span>
                                            <a href="/admin/admin_file?fil_file_id=${file.file_id}" style="color: #0066cc; text-decoration: none;">${fileName}</a>
                                        </div>
                                    `;
                                }

                                fileObj.status = 'completed';
                                fileObj.url = file.url;
                                fileObj.file_id = file.file_id;
                                resolve(fileObj);
                            } else if (file.error) {
                                throw new Error(file.error);
                            }
                        } else {
                            throw new Error('Invalid response format');
                        }
                    } catch (e) {
                        reject(e);
                    }
                } else {
                    reject(new Error('Upload failed with status: ' + xhr.status));
                }
            });

            xhr.addEventListener('error', function() {
                reject(new Error('Network error during upload'));
            });

            xhr.open('POST', '/admin/admin_file_upload_process');
            xhr.send(formData);
        }).catch(error => {
            // Handle error
            const row = document.querySelector(`.file-row[data-file-id="${fileObj.id}"]`);
            const statusCell = row.querySelector('.file-status');
            const actionsCell = row.querySelector('.file-actions');

            statusCell.innerHTML = `<span style="padding: 2px 8px; background: #dc3545; color: white; border-radius: 3px; font-size: 12px;">⚠️ Error</span>`;
            actionsCell.innerHTML = `
                <button type="button" class="button small upload-single-btn" title="Retry upload" style="padding: 4px 8px; font-size: 12px;">
                    🔄
                </button>
                <button type="button" class="button small danger remove-file-btn" title="Remove this file" style="padding: 4px 8px; font-size: 12px; margin-left: 5px;">
                    ❌
                </button>
            `;
            fileObj.status = 'error';
            showToast(`Upload failed: ${fileObj.file.name} - ${error.message}`, 'error');
            throw error;
        });
    }

    // Event Handlers
    // Note: Browse button is now a <label for="file-input"> which natively triggers the file input
    // No JavaScript handler needed for the browse button

    fileInput.addEventListener('change', function() {
        if (this.files.length > 0) {
            addFiles(this.files);
            this.value = ''; // Reset input
        }
    });

    // Drag and drop styling
    ['dragover', 'dragenter'].forEach(function(eventName) {
        dropZone.addEventListener(eventName, function(e) {
            e.preventDefault();
            e.stopPropagation();
            this.style.borderColor = '#007bff';
            this.style.backgroundColor = '#e7f3ff';
        });
    });

    dropZone.addEventListener('dragleave', function(e) {
        e.preventDefault();
        e.stopPropagation();
        this.style.borderColor = '#ccc';
        this.style.backgroundColor = '#f9f9f9';
    });

    dropZone.addEventListener('drop', function(e) {
        e.preventDefault();
        e.stopPropagation();
        this.style.borderColor = '#ccc';
        this.style.backgroundColor = '#f9f9f9';

        const files = e.dataTransfer.files;
        if (files.length > 0) {
            addFiles(files);
        }
    });

    // Upload all files
    uploadAllBtn.addEventListener('click', async function() {
        const pendingFiles = selectedFiles.filter(f => f.status === 'pending');
        if (pendingFiles.length === 0) return;

        overallProgress.style.display = 'flex';
        uploadAllBtn.disabled = true;

        let completed = 0;
        const total = pendingFiles.length;

        for (const fileObj of pendingFiles) {
            try {
                await uploadFile(fileObj);
                completed++;
                const progress = Math.round((completed / total) * 100);
                progressBar.value = progress;
            } catch (error) {
                console.error('Upload failed:', error);
                completed++; // Count errors as completed for progress
                const progress = Math.round((completed / total) * 100);
                progressBar.value = progress;
            }
        }

        setTimeout(function() {
            overallProgress.style.display = 'none';
            progressBar.value = 0;
            updateUI();
        }, 1000);
    });

    // Clear all files
    clearAllBtn.addEventListener('click', function() {
        if (confirm('Are you sure you want to clear all files?')) {
            selectedFiles = [];
            filesList.querySelectorAll('.file-row').forEach(function(row) {
                row.remove();
            });
            updateUI();
        }
    });

    // Event delegation for dynamic buttons
    filesList.addEventListener('click', function(e) {
        const uploadBtn = e.target.closest('.upload-single-btn');
        const removeBtn = e.target.closest('.remove-file-btn');

        if (uploadBtn) {
            const row = uploadBtn.closest('.file-row');
            const fileId = row.dataset.fileId;
            const fileObj = selectedFiles.find(f => f.id === fileId);
            if (fileObj && (fileObj.status === 'pending' || fileObj.status === 'error')) {
                uploadFile(fileObj).then(function() {
                    updateUI();
                }).catch(function() {
                    updateUI();
                });
            }
        }

        if (removeBtn) {
            const row = removeBtn.closest('.file-row');
            const fileId = row.dataset.fileId;

            // Remove from array
            selectedFiles = selectedFiles.filter(f => f.id !== fileId);

            // Remove from DOM with fade animation
            row.style.transition = 'opacity 0.3s';
            row.style.opacity = '0';
            setTimeout(function() {
                row.remove();
                updateUI();
            }, 300);
        }
    });

    // Click to browse anywhere in drop zone (except on the file input label area)
    dropZone.addEventListener('click', function(e) {
        // Don't interfere with the file input or its label container
        if (e.target.closest('label') || e.target.closest('button') || e.target.id === 'file-input') {
            return; // Let native behavior handle it
        }
        // For clicks on the drop zone itself or text elements, trigger file input
        fileInput.click();
    });
    } // end initFileUploader

    // Run immediately if DOM is already ready, otherwise wait for DOMContentLoaded
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initFileUploader);
    } else {
        initFileUploader();
    }
})();
