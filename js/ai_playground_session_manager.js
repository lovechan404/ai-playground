// js/ai_playground_session_manager.js

document.addEventListener('DOMContentLoaded', () => {
    const sessionManagerModal = document.getElementById('sessionManagerModal');
    const sessionManagerButton = document.getElementById('sessionManagerButton');
    const closeSessionManagerModalButton = document.getElementById('closeSessionManagerModal');
    const exportSessionButton = document.getElementById('exportSessionButton');
    const importSessionFileInput = document.getElementById('importSessionFile');
    const importSessionLabel = document.querySelector('label[for="importSessionFile"]');
    const importSessionStatus = document.getElementById('importSessionStatus');

    if (sessionManagerButton && sessionManagerModal) {
        sessionManagerButton.addEventListener('click', (e) => {
            e.preventDefault();
            sessionManagerModal.style.display = 'flex';
            if (importSessionStatus) {
                importSessionStatus.textContent = '';
                importSessionStatus.className = 'status-message';
                importSessionStatus.style.display = 'none';
            }
            const settingsDropdown = document.getElementById("settingsDropdown");
            if (settingsDropdown) settingsDropdown.classList.remove("show");
        });
    }

    if (closeSessionManagerModalButton && sessionManagerModal) {
        closeSessionManagerModalButton.addEventListener('click', () => {
            sessionManagerModal.style.display = 'none';
        });
    }

    if (exportSessionButton && importSessionStatus) {
        exportSessionButton.addEventListener('click', () => {
            importSessionStatus.textContent = 'Exporting session... Please wait for download.';
            importSessionStatus.className = 'status-message';
            importSessionStatus.style.display = 'block';
            
            // For export via GET, CSRF token isn't typically sent in URL for this kind of action,
            // but if it were a POST, it would be needed.
            window.location.href = 'index.php?action=export_session';
            
            // Clear message after a bit, or let user close modal.
            setTimeout(() => {
                if (sessionManagerModal && sessionManagerModal.style.display === 'flex') {
                     // importSessionStatus.textContent = 'Export initiated.'; 
                     // importSessionStatus.className = 'status-message success';
                }
            }, 2500);
        });
    }

    if (importSessionFileInput && importSessionStatus) {
        if (importSessionLabel) {
            importSessionLabel.addEventListener('click', (e) => {
                e.preventDefault(); 
                importSessionFileInput.click();
            });
        }

        importSessionFileInput.addEventListener('change', async (event) => {
            const file = event.target.files[0];
            if (!file) {
                importSessionStatus.textContent = 'No file selected.';
                importSessionStatus.className = 'status-message error';
                importSessionStatus.style.display = 'block';
                return;
            }

            if (file.type !== 'application/json') {
                importSessionStatus.textContent = 'Invalid file type. Please select a .json file.';
                importSessionStatus.className = 'status-message error';
                importSessionStatus.style.display = 'block';
                event.target.value = ''; 
                return;
            }

            const formData = new FormData();
            formData.append('action', 'import_session');
            formData.append('session_file', file);
            if (window.csrfToken) { // Add CSRF token to FormData
                formData.append('csrf_token', window.csrfToken);
            } else {
                console.warn('CSRF token not found for import session.');
                // Optionally, you could prevent the request or inform the user more directly
            }

            importSessionStatus.textContent = 'Importing...';
            importSessionStatus.className = 'status-message'; 
            importSessionStatus.style.display = 'block';

            try {
                const response = await fetch('index.php', {
                    method: 'POST',
                    body: formData 
                });

                const result = await response.json();

                if (result.error && result.error.toLowerCase().includes('csrf')) {
                    importSessionStatus.textContent = `Security token error: ${result.error} Please refresh.`;
                    importSessionStatus.className = 'status-message error';
                } else if (result.success) {
                    importSessionStatus.textContent = result.message || 'Session imported! Reloading...';
                    importSessionStatus.className = 'status-message success';
                    setTimeout(() => {
                        window.location.reload();
                    }, 2500); 
                } else {
                    importSessionStatus.textContent = 'Error: ' + (result.error || 'Failed to import session.');
                    importSessionStatus.className = 'status-message error';
                }
            } catch (error) {
                console.error('Import error:', error);
                importSessionStatus.textContent = 'Error: Could not connect or invalid server response.';
                importSessionStatus.className = 'status-message error';
            } finally {
                event.target.value = ''; 
            }
        });
    }
    // Redundant Escape key and click-outside listeners removed as they are handled by ai_playground_ui.js
});
