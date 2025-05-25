// ai_playground_ui.js

let abortController = null;
let currentRequestId = null;
const aiMessageVersions = {};

let messageInput, chatForm, chatBox, sendButton, textStreamingToggle, projectInfoDiv;
let apiSettingsModal, apiSettingsButton, closeApiSettingsModalButton, apiSettingsForm, apiSettingsStatus, apiTemperatureSlider, temperatureValueDisplay, apiPromptTextarea;
let advancedSettingsModal, advancedSettingsButton, closeAdvancedSettingsModalButton, advancedSettingsForm, advancedSettingsStatus;
let viewRawReplyModal, closeViewRawReplyModalButton, rawReplyTextarea;
let settingsButton;
let sessionManagerModal;

/**
 * Forces chat box to scroll to bottom.
 */
function forceScrollToBottom() {
    if (chatBox && chatBox.querySelectorAll('.message').length > 0) {
        chatBox.scrollTop = chatBox.scrollHeight;
    }
}

/**
 * Scrolls chat box to bottom if user is near the bottom.
 */
function scrollToBottom() {
    if (!chatBox || chatBox.querySelectorAll('.message').length === 0) return;
    const threshold = 150;
    const isNearBottom = chatBox.scrollHeight - chatBox.scrollTop - chatBox.clientHeight < threshold;
    if (isNearBottom) chatBox.scrollTop = chatBox.scrollHeight;
}

/**
 * Shows a temporary modal message.
 * @param {string} message
 * @param {number} [duration=2000]
 * @param {boolean} [isError=false]
 */
function showTempModal(message, duration = 2000, isError = false) {
    const modalId = 'temp-feedback-modal';
    let existingModal = document.getElementById(modalId);
    if (existingModal) existingModal.remove();
    const modal = document.createElement('div');
    modal.id = modalId;
    modal.classList.add('modal', 'temp-feedback-modal-instance');
    modal.style.display = 'flex';
    modal.innerHTML = `<div class="modal-content" style="text-align: center; max-width: 280px!important"><p class="${isError ? 'error-text' : 'success-text'}">${escapeHtml(message)}</p></div>`;
    document.body.appendChild(modal);
    setTimeout(() => { if (document.body.contains(modal)) modal.remove(); }, duration);
}

/**
 * Updates chat area appearance based on whether it's empty.
 */
function updateChatAreaAppearance() {
    if (chatBox && projectInfoDiv) {
        if (chatBox.querySelectorAll('.message').length === 0) {
            projectInfoDiv.style.display = 'block';
            chatBox.classList.add('chat-box-empty-state');
        } else {
            projectInfoDiv.style.display = 'none';
            chatBox.classList.remove('chat-box-empty-state');
        }
    }
}

/**
 * Adjusts textarea height to fit its content, adding a 10px buffer.
 * @param {HTMLTextAreaElement} textarea
 */
function autoResizeTextarea(textarea) {
    if (!textarea) return;
    textarea.style.height = 'auto';
    textarea.style.height = (textarea.scrollHeight + 10) + 'px';
}

/**
 * Clears actions from previous last user/AI messages before a new request.
 */
function clearPreviousLastMessageActions() {
    if (!chatBox) return;
    const allCommittedMessages = Array.from(chatBox.querySelectorAll('.message:not(.message-streaming-placeholder)'));
    const lastCommittedAIMessage = allCommittedMessages.filter(m => m.classList.contains('assistant')).pop();
    const lastCommittedUserMessage = allCommittedMessages.filter(m => m.classList.contains('user')).pop();

    if(lastCommittedAIMessage) {
        const actionsDiv = lastCommittedAIMessage.querySelector('.message-actions');
        const messageId = lastCommittedAIMessage.dataset.messageId;
        if(actionsDiv && messageId) {
             actionsDiv.innerHTML = `<button class="copy-message-button action-btn" data-message-id="${messageId}">Copy</button>`;
        }
    }
    if(lastCommittedUserMessage) {
        const actionsDiv = lastCommittedUserMessage.querySelector('.message-actions');
        if(actionsDiv) actionsDiv.innerHTML = '';
    }
}

/**
 * Updates all action buttons for all messages based on current state.
 */
function updateAllActionButtons() {
    if (!chatBox) return;
    const allMessages = Array.from(chatBox.querySelectorAll('.message'));
    const allAIMessages = Array.from(chatBox.querySelectorAll('.message.assistant'));
    const allUserMessages = Array.from(chatBox.querySelectorAll('.message.user'));
    const lastAIMessageElement = allAIMessages.length > 0 ? allAIMessages[allAIMessages.length - 1] : null;
    const lastUserMessageElement = allUserMessages.length > 0 ? allUserMessages[allUserMessages.length - 1] : null;

    allMessages.forEach(messageElement => {
        const actionsDiv = messageElement.querySelector('.message-actions');
        if (!actionsDiv) return;
        actionsDiv.innerHTML = '';
        const messageId = messageElement.dataset.messageId;
        if (!messageId) return;
        const contentDiv = messageElement.querySelector('.message-content');
        const isCurrentlyTypingInThisMessage = contentDiv && contentDiv.querySelector('.typing-indicator');

        if (messageElement.classList.contains('assistant')) {
            if (!isCurrentlyTypingInThisMessage) {
                actionsDiv.innerHTML += `<button class="copy-message-button action-btn" data-message-id="${messageId}">Copy</button>`;
                if (messageElement === lastAIMessageElement) {
                    const totalVersions = parseInt(messageElement.dataset.totalVersions) || 1;
                    const versionIndex = parseInt(messageElement.dataset.currentVersionIndex) || 0;
                    if (totalVersions > 1) {
                       actionsDiv.innerHTML += `<span class="version-nav"><button class="version-prev action-btn-sm" data-message-id="${messageId}" ${versionIndex === 0 ? 'disabled' : ''}>&#9664;</button><span class="version-info">${versionIndex + 1} / ${totalVersions}</span><button class="version-next action-btn-sm" data-message-id="${messageId}" ${versionIndex === totalVersions - 1 ? 'disabled' : ''}>&#9654;</button></span>`;
                    }
                    actionsDiv.innerHTML += `<button class="regenerate-button action-btn" data-message-id="${messageId}">Regenerate</button>`;
                    if (window.advancedSettings && window.advancedSettings.enable_raw_reply_view) {
                        actionsDiv.innerHTML += `<button class="view-raw-button action-btn" data-message-id="${messageId}">Raw</button>`;
                    }
                    if (window.advancedSettings && window.advancedSettings.enable_ai_response_edit) {
                         actionsDiv.innerHTML += `<button class="edit-ai-button action-btn" data-message-id="${messageId}">Edit AI</button>`;
                    }
                }
            }
        } else if (messageElement.classList.contains('user')) {
            actionsDiv.innerHTML += `<button class="delete-button action-btn" data-message-id="${messageId}">Delete</button>`;
            if (messageElement === lastUserMessageElement) {
                 actionsDiv.innerHTML = `<button class="edit-button action-btn" data-message-id="${messageId}">Edit</button>` + actionsDiv.innerHTML;
            }
        }
    });
     attachAdvancedButtonListeners();
     updateChatAreaAppearance();
}

/**
 * Simulates typing effect for AI responses.
 * @param {HTMLElement} element
 * @param {string} text
 * @param {number} [delay=5]
 * @returns {Promise<void>}
 */
function typeEffect(element, text, delay = 5) {
    let i = 0;
    let currentText = '';
    if (!element) return Promise.resolve();
    element.innerHTML = '';
    if (!element.querySelector('.typing-indicator')) {
         element.innerHTML = `<span class="typing-indicator"><span class="dot"></span><span class="dot"></span><span class="dot"></span></span>`;
    }
    updateAllActionButtons();

    return new Promise(resolve => {
        const interval = setInterval(() => {
            if (abortController && abortController.signal.aborted && element.closest('.message.assistant')) {
                clearInterval(interval);
                const messageElement = element.closest('.message.assistant');
                const messageId = messageElement ? messageElement.dataset.messageId : null;
                const versionsExist = messageId && aiMessageVersions && aiMessageVersions[messageId];
                let displayedContent = markdownToHtmlJs(currentText);
                if (messageElement && messageId && versionsExist && aiMessageVersions[messageId][aiMessageVersions[messageId].length -1] !== text) {
                    displayedContent += ' <em class="info-text">(Stopped)</em>';
                }
                element.innerHTML = displayedContent;
                attachCopyButtonListeners(element);
                scrollToBottom();
                updateAllActionButtons();
                resolve();
                return;
            }
            if (i < text.length) {
                currentText += text.charAt(i);
                i++;
                element.innerHTML = markdownToHtmlJs(currentText);
                element.querySelectorAll('details.ai-thought').forEach(detail => { detail.open = true; });
                attachCopyButtonListeners(element);
                scrollToBottom();
            } else {
                clearInterval(interval);
                element.innerHTML = markdownToHtmlJs(text);
                element.querySelectorAll('details.ai-thought').forEach(detail => { detail.open = true; });
                attachCopyButtonListeners(element);
                updateAllActionButtons();
                scrollToBottom();
                resolve();
            }
        }, delay);
    });
}

/**
 * Attaches click listeners to copy code buttons.
 * @param {HTMLElement|Document} [parentElement=document]
 */
function attachCopyButtonListeners(parentElement = document) {
    parentElement.querySelectorAll('.copy-code-button').forEach(button => {
        button.removeEventListener('click', handleCopyButtonClick);
        button.addEventListener('click', handleCopyButtonClick);
    });
}

function handleCopyButtonClick(event) {
    const button = event.target;
    const preElement = button.closest('pre');
    if (preElement) {
        const codeElement = preElement.querySelector('code');
        if (codeElement) {
            const codeToCopy = codeElement.innerText;
            try {
                const tempTextArea = document.createElement('textarea');
                tempTextArea.value = codeToCopy;
                document.body.appendChild(tempTextArea);
                tempTextArea.select();
                document.execCommand('copy');
                document.body.removeChild(tempTextArea);
                showTempModal('Copied to clipboard!');
                button.textContent = 'Copied!';
                setTimeout(() => { button.textContent = 'Copy'; }, 2000);
            } catch (err) {
                console.error('Fallback copy method failed:', err);
                showTempModal('Failed to copy!', 2000, true);
            }
        }
    }
}

/**
 * Updates an AI message's display content and refreshes action buttons.
 * @param {HTMLElement} messageElement
 * @param {number} versionIndex
 * @param {number} totalVersions
 * @param {string} rawContent
 */
function updateAIMessageDisplay(messageElement, versionIndex, totalVersions, rawContent) {
    if (!messageElement) return;
    const messageContentDiv = messageElement.querySelector('.message-content');
    if (!messageContentDiv) return;
    const htmlContent = markdownToHtmlJs(rawContent);
    if (messageContentDiv.innerHTML !== htmlContent && !messageElement.querySelector('.edit-ai-container')) {
        messageContentDiv.innerHTML = htmlContent;
    }
    messageContentDiv.querySelectorAll('details.ai-thought').forEach(detail => { detail.open = true; });
    messageElement.dataset.currentVersionIndex = versionIndex;
    messageElement.dataset.totalVersions = totalVersions;
    if (aiMessageVersions[messageElement.dataset.messageId]) {
         messageElement.dataset.allContents = JSON.stringify(aiMessageVersions[messageElement.dataset.messageId]);
    }
    updateAllActionButtons();
    attachCopyButtonListeners(messageContentDiv);
}

function handleViewRawClick(event) {
    const messageId = event.target.dataset.messageId;
    const messageElement = document.querySelector(`.message.assistant[data-message-id="${messageId}"]`);
    if (!messageElement || !aiMessageVersions[messageId] || !viewRawReplyModal || !rawReplyTextarea) return;
    const currentVersionIndex = parseInt(messageElement.dataset.currentVersionIndex) || 0;
    const rawContent = aiMessageVersions[messageId][currentVersionIndex];
    rawReplyTextarea.value = rawContent || "";
    viewRawReplyModal.style.display = 'flex';
    setTimeout(() => autoResizeTextarea(rawReplyTextarea), 10);
}

function handleEditAIClick(event) {
    const messageId = event.target.dataset.messageId;
    const messageElement = document.querySelector(`.message.assistant[data-message-id="${messageId}"]`);
    if (!messageElement || !aiMessageVersions[messageId]) return;
    const messageContentDiv = messageElement.querySelector('.message-content');
    const actionsDiv = messageElement.querySelector('.message-actions');
    if (!messageContentDiv || !actionsDiv || messageElement.querySelector('.edit-ai-container')) return;
    const currentVersionIndex = parseInt(messageElement.dataset.currentVersionIndex) || 0;
    const rawContent = aiMessageVersions[messageId][currentVersionIndex] || "";
    messageContentDiv.style.display = 'none';
    actionsDiv.style.display = 'none';
    const editContainer = document.createElement('div');
    editContainer.classList.add('edit-ai-container');
    editContainer.innerHTML = `
        <textarea class="edit-ai-textarea" rows="3">${escapeHtml(rawContent)}</textarea>
        <div class="edit-ai-action-buttons" style="margin-top: 5px; text-align: right;">
            <button class="save-ai-button action-btn primary-btn">Save AI</button>
            <button class="cancel-ai-button action-btn">Cancel</button>
        </div>
    `;
    messageElement.appendChild(editContainer);
    const editTextArea = editContainer.querySelector('.edit-ai-textarea');
    setTimeout(() => { autoResizeTextarea(editTextArea); editTextArea.focus(); }, 10);
    editTextArea.addEventListener('input', () => autoResizeTextarea(editTextArea));

     editContainer.querySelector('.save-ai-button').onclick = () => {
        const newRawContent = editTextArea.value;
        aiMessageVersions[messageId] = [newRawContent];
        messageElement.dataset.currentVersionIndex = 0;
        messageElement.dataset.totalVersions = 1;
        messageElement.dataset.allContents = JSON.stringify(aiMessageVersions[messageId]);
        messageContentDiv.innerHTML = markdownToHtmlJs(newRawContent);
        editContainer.remove();
        messageContentDiv.style.display = 'block';
        actionsDiv.style.display = 'flex';
        updateAllActionButtons();
        
        const bodyParams = new URLSearchParams();
        bodyParams.append('action', 'saveEditedAIReply');
        bodyParams.append('messageId', messageId);
        bodyParams.append('newRawContent', newRawContent);
        if (window.csrfToken) bodyParams.append('csrf_token', window.csrfToken);

        fetch(window.location.pathname, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: bodyParams })
        .then(response => response.json())
        .then(data => { showTempModal(data.success ? 'AI response updated.' : 'Error saving AI edit.', 2000, !data.success); })
        .catch(error => { showTempModal('Network error saving AI edit.', 3000, true); });
    };
    editContainer.querySelector('.cancel-ai-button').onclick = () => {
        editContainer.remove();
        messageContentDiv.style.display = 'block';
        actionsDiv.style.display = 'flex';
        updateAllActionButtons();
    };
}

/**
 * Attaches listeners to advanced buttons ('Raw', 'Edit AI').
 */
function attachAdvancedButtonListeners() {
    document.querySelectorAll('.view-raw-button').forEach(btn => {
        btn.removeEventListener('click', handleViewRawClick);
        btn.addEventListener('click', handleViewRawClick);
    });
     document.querySelectorAll('.edit-ai-button').forEach(btn => {
        btn.removeEventListener('click', handleEditAIClick);
        btn.addEventListener('click', handleEditAIClick);
    });
}

function toggleSettingsDropdown(event) {
    event.stopPropagation();
    document.getElementById("settingsDropdown")?.classList.toggle("show");
}

/**
 * Sets UI loading state (disables input, changes send button).
 * @param {boolean} isLoading
 * @param {string|null} [reqId=null]
 */
function setUiLoadingState(isLoading, reqId = null) {
    if (messageInput) messageInput.disabled = isLoading;
    if (sendButton) {
        if (isLoading) {
            currentRequestId = reqId;
            sendButton.textContent = 'Stop';
            sendButton.classList.add('stop-button');
            sendButton.onclick = () => {
                if (abortController) abortController.abort();
                if (currentRequestId) {
                    const bodyParams = new URLSearchParams({ action: 'cancelApiRequest', requestId: currentRequestId });
                    if (window.csrfToken) bodyParams.append('csrf_token', window.csrfToken);
                    fetch(window.location.pathname, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: bodyParams })
                    .catch(e => console.error('Cancel err:', e));
                }
                setUiLoadingState(false);
                const streamingPlaceholder = chatBox.querySelector('.message-streaming-placeholder .message-content');
                if (streamingPlaceholder?.querySelector('.typing-indicator')) {
                    streamingPlaceholder.innerHTML = '<em class="info-text">Response stopped by user.</em>';
                }
                updateAllActionButtons();
            };
        } else {
            sendButton.textContent = 'Send';
            sendButton.classList.remove('stop-button');
            sendButton.onclick = null;
            currentRequestId = null;
        }
    }
}

document.addEventListener('DOMContentLoaded', () => {
    messageInput = document.getElementById('messageInput');
    chatForm = document.getElementById('chatForm');
    chatBox = document.getElementById('chatBox');
    sendButton = document.getElementById('sendButton');
    textStreamingToggle = document.getElementById('textStreamingToggle');
    projectInfoDiv = document.querySelector('.project-info');
    apiSettingsModal = document.getElementById('apiSettingsModal');
    apiSettingsButton = document.getElementById('apiSettingsButton');
    closeApiSettingsModalButton = document.getElementById('closeApiSettingsModal');
    apiSettingsForm = document.getElementById('apiSettingsForm');
    apiSettingsStatus = document.getElementById('apiSettingsStatus');
    apiTemperatureSlider = document.getElementById('api_temperature');
    temperatureValueDisplay = document.getElementById('temperatureValueDisplay');
    apiPromptTextarea = document.getElementById('api_prompt');
    advancedSettingsModal = document.getElementById('advancedSettingsModal');
    advancedSettingsButton = document.getElementById('advancedSettingsButton');
    closeAdvancedSettingsModalButton = document.getElementById('closeAdvancedSettingsModal');
    advancedSettingsForm = document.getElementById('advancedSettingsForm');
    advancedSettingsStatus = document.getElementById('advancedSettingsStatus');
    viewRawReplyModal = document.getElementById('viewRawReplyModal');
    closeViewRawReplyModalButton = document.getElementById('closeViewRawReplyModal');
    rawReplyTextarea = document.getElementById('rawReplyTextarea');
    settingsButton = document.querySelector('.settings-button');
    sessionManagerModal = document.getElementById('sessionManagerModal');

    if (typeof window.advancedSettings === 'undefined') {
        window.advancedSettings = { enable_raw_reply_view: false, enable_ai_response_edit: false };
    }
    // Ensure csrfToken is available, even if empty (PHP will output an empty string if session token isn't set yet)
    if (typeof window.csrfToken === 'undefined') {
        console.warn('CSRF token not found on window. Ensure index.php sets window.csrfToken.');
        window.csrfToken = ''; // Fallback to empty string
    }


    if (chatBox) {
        document.querySelectorAll('.message').forEach(el => {
            const messageId = el.dataset.messageId;
            if (!messageId) return;
            if (el.classList.contains('assistant') && el.dataset.allContents) {
                try { aiMessageVersions[messageId] = JSON.parse(el.dataset.allContents); } catch (e) { console.error('Parse error', e); aiMessageVersions[messageId] = ['Error']; }
            } else if (el.classList.contains('assistant')) {
                 aiMessageVersions[messageId] = [el.querySelector('.message-content')?.textContent.trim() || 'Error'];
            }
        });
        updateAllActionButtons();
        updateChatAreaAppearance();
        if (chatBox.querySelectorAll('.message').length > 0) forceScrollToBottom();
        attachCopyButtonListeners();
        const observer = new MutationObserver(() => { updateAllActionButtons(); updateChatAreaAppearance(); });
        observer.observe(chatBox, { childList: true });
    }

    if(messageInput) {
        messageInput.focus();
        messageInput.addEventListener('input', () => autoResizeTextarea(messageInput));
        autoResizeTextarea(messageInput);
        messageInput.addEventListener('keydown', function(event) {
            if (event.key === 'Enter' && !isTouchDevice() && !event.shiftKey) {
                event.preventDefault();
                if(chatForm && !messageInput.disabled) chatForm.dispatchEvent(new Event('submit', {cancelable:true, bubbles:true}));
            }
        });
    }

    if (apiPromptTextarea) {
        apiPromptTextarea.addEventListener('input', () => autoResizeTextarea(apiPromptTextarea));
    }

    if (settingsButton) settingsButton.addEventListener('click', toggleSettingsDropdown);
    
    window.addEventListener('click', function(event) {
        const activeSD = document.getElementById("settingsDropdown");
        if (activeSD?.classList.contains('show') && settingsButton && !settingsButton.contains(event.target) && !activeSD.contains(event.target)) {
            activeSD.classList.remove('show');
        }
        if (apiSettingsModal && event.target === apiSettingsModal) apiSettingsModal.style.display = "none";
        if (advancedSettingsModal && event.target === advancedSettingsModal) advancedSettingsModal.style.display = "none";
        if (viewRawReplyModal && event.target === viewRawReplyModal) viewRawReplyModal.style.display = "none";
        if (sessionManagerModal && event.target === sessionManagerModal) sessionManagerModal.style.display = "none";
        const dynModal = document.querySelector('.js-dynamic-confirm-modal');
        if (dynModal && event.target === dynModal) dynModal.remove();
    });

    window.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            let modalClosed = false;
            if (viewRawReplyModal?.style.display === 'flex') { viewRawReplyModal.style.display = 'none'; modalClosed = true; }
            else if (advancedSettingsModal?.style.display === 'flex') { advancedSettingsModal.style.display = 'none'; modalClosed = true; }
            else if (apiSettingsModal?.style.display === 'flex') { apiSettingsModal.style.display = 'none'; modalClosed = true; }
            else if (sessionManagerModal?.style.display === 'flex') { sessionManagerModal.style.display = 'none'; modalClosed = true; }
            const tempM = document.querySelector('.temp-feedback-modal-instance');
            if (tempM) { tempM.remove(); modalClosed = true; }
            const dynM = document.querySelector('.js-dynamic-confirm-modal');
            if (dynM?.style.display === 'flex') { dynM.remove(); modalClosed = true; }
            if (modalClosed) event.preventDefault();
        }
    });

    if (textStreamingToggle) {
        textStreamingToggle.addEventListener('change', function() {
            const bodyParams = new URLSearchParams({action: 'updateTextStreamingSetting', isEnabled: this.checked ? 'true' : 'false'});
            if (window.csrfToken) bodyParams.append('csrf_token', window.csrfToken);
            fetch(window.location.pathname, { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: bodyParams })
            .then(r=>r.json()).then(d => { if(!d.success) { showTempModal('Failed to update.',2000,true); this.checked=!this.checked;}}).catch(e => {showTempModal('Network error.',2000,true); this.checked=!this.checked;});
        });
    }

    if (apiSettingsButton) {
        apiSettingsButton.onclick = (e) => {
            e.preventDefault();
            if (apiSettingsModal) {
                apiSettingsModal.style.display = "flex";
                setTimeout(() => {
                    if (apiTemperatureSlider && temperatureValueDisplay) {
                        temperatureValueDisplay.textContent = parseFloat(apiTemperatureSlider.value).toFixed(2);
                    }
                    if (apiPromptTextarea) autoResizeTextarea(apiPromptTextarea);
                }, 10);
            }
            if (apiSettingsStatus) { apiSettingsStatus.textContent = ''; apiSettingsStatus.className = 'status-message'; apiSettingsStatus.style.display = 'none'; }
            document.getElementById("settingsDropdown")?.classList.remove("show");
        };
    }
    if (closeApiSettingsModalButton) closeApiSettingsModalButton.onclick = () => { if (apiSettingsModal) apiSettingsModal.style.display = "none"; };
    if (closeViewRawReplyModalButton) closeViewRawReplyModalButton.onclick = () => { if (viewRawReplyModal) viewRawReplyModal.style.display = "none"; };

    if (advancedSettingsButton) {
         advancedSettingsButton.onclick = (e) => {
            e.preventDefault();
            if (advancedSettingsModal) advancedSettingsModal.style.display = "flex";
            if (advancedSettingsStatus) { advancedSettingsStatus.textContent = ''; advancedSettingsStatus.className = 'status-message'; advancedSettingsStatus.style.display = 'none'; }
            document.getElementById("settingsDropdown")?.classList.remove("show");
        };
    }
    if (closeAdvancedSettingsModalButton) closeAdvancedSettingsModalButton.onclick = () => { if (advancedSettingsModal) advancedSettingsModal.style.display = "none";};
    if (apiTemperatureSlider && temperatureValueDisplay) apiTemperatureSlider.addEventListener('input', function() { temperatureValueDisplay.textContent = parseFloat(this.value).toFixed(2); });

    if (apiSettingsForm) {
        apiSettingsForm.addEventListener('submit', function(event) {
            event.preventDefault();
            const formData = new FormData(this); // FormData is fine for this form
            formData.append('action', 'saveApiSettings');
            if (window.csrfToken) formData.append('csrf_token', window.csrfToken);
            // api_temperature is already part of FormData from the range input
            // If it wasn't, you'd do: if (apiTemperatureSlider) formData.set('api_temperature', apiTemperatureSlider.value);
            
            const saveBtn = this.querySelector('button[type="submit"]');
            if(saveBtn){ saveBtn.disabled = true; saveBtn.textContent = 'Saving...';}
            if(apiSettingsStatus){ apiSettingsStatus.textContent=''; apiSettingsStatus.className='status-message'; apiSettingsStatus.style.display='none';}
            
            // For FormData, fetch doesn't need Content-Type header to be set manually
            fetch(window.location.pathname,{method:'POST', body:formData})
            .then(r=>r.json()).then(d=>{
                if(d.success){
                    if(apiSettingsStatus){apiSettingsStatus.textContent=d.message||'Settings saved!';apiSettingsStatus.className='status-message success';apiSettingsStatus.style.display='block';setTimeout(()=>apiSettingsStatus.style.display='none',2500);}
                    setTimeout(()=> {if(apiSettingsModal)apiSettingsModal.style.display="none";},1000);
                }else{
                    if(apiSettingsStatus){apiSettingsStatus.textContent='Error: '+(d.error||'Could not save.');apiSettingsStatus.className='status-message error';apiSettingsStatus.style.display='block';setTimeout(()=>apiSettingsStatus.style.display='none',3000);}
                }
            }).catch(e=>{console.error('Err saving API settings:',e);if(apiSettingsStatus){apiSettingsStatus.textContent='Network error.';apiSettingsStatus.className='status-message error';apiSettingsStatus.style.display='block';setTimeout(()=>apiSettingsStatus.style.display='none',3000);}})
            .finally(()=>{if(saveBtn){saveBtn.disabled=false;saveBtn.textContent='Save Settings';}});
        });
    }
    if (advancedSettingsForm) {
        advancedSettingsForm.addEventListener('submit', function(event) {
            event.preventDefault();
            const formData = new FormData(this); // FormData is fine
            formData.append('action', 'saveAdvancedSettings');
            if (window.csrfToken) formData.append('csrf_token', window.csrfToken);
            
            const saveBtn = this.querySelector('button[type="submit"]');
            if(saveBtn){saveBtn.disabled=true;saveBtn.textContent='Saving...';}
            if(advancedSettingsStatus){advancedSettingsStatus.textContent='';advancedSettingsStatus.className='status-message';advancedSettingsStatus.style.display='none';}

            fetch(window.location.pathname,{method:'POST', body:formData})
            .then(r=>r.json()).then(d=>{
                if(d.success){
                    if(advancedSettingsStatus){advancedSettingsStatus.textContent=d.message||'Settings saved!';advancedSettingsStatus.className='status-message success';advancedSettingsStatus.style.display='block';setTimeout(()=>advancedSettingsStatus.style.display='none',2500);}
                    if (d.settings) window.advancedSettings = d.settings;
                    updateAllActionButtons();
                    setTimeout(()=> {if(advancedSettingsModal)advancedSettingsModal.style.display="none";},1000);
                }else{
                    if(advancedSettingsStatus){advancedSettingsStatus.textContent='Error: '+(d.error||'Could not save.');advancedSettingsStatus.className='status-message error';advancedSettingsStatus.style.display='block';setTimeout(()=>advancedSettingsStatus.style.display='none',3000);}
                }
            }).catch(e=>{console.error('Err saving advanced settings:',e);if(advancedSettingsStatus){advancedSettingsStatus.textContent='Network error.';advancedSettingsStatus.className='status-message error';advancedSettingsStatus.style.display='block';setTimeout(()=>advancedSettingsStatus.style.display='none',3000);}})
            .finally(()=>{if(saveBtn){saveBtn.disabled=false;saveBtn.textContent='Save Advanced Settings';}});
        });
    }

    const clearHistoryLink = document.getElementById('clearHistoryLink');
    if (clearHistoryLink) {
        clearHistoryLink.addEventListener('click', function(event) {
            event.preventDefault();
            const confirmModal = document.createElement('div');
            confirmModal.classList.add('modal', 'js-dynamic-confirm-modal');
            confirmModal.style.display = 'flex';
            confirmModal.innerHTML = `
                <div class="modal-content">
                    <span class="close-button">&times;</span>
                    <p>Are you sure you want to clear the entire chat history?</p>
                    <div style="text-align:center;margin-top:15px;">
                        <button class="action-btn primary-btn" id="confirmClearHistoryYes">Yes, Clear</button>
                        <button class="action-btn" id="confirmClearHistoryNo">Cancel</button>
                    </div>
                </div>`;
            document.body.appendChild(confirmModal);
            const closeFn = () => { if (document.body.contains(confirmModal)) confirmModal.remove(); };
            const modalContent = confirmModal.querySelector('.modal-content');
            const closeButton = confirmModal.querySelector('.close-button');
            const noButton = confirmModal.querySelector('#confirmClearHistoryNo');
            const yesButton = confirmModal.querySelector('#confirmClearHistoryYes');
            if (closeButton) closeButton.onclick = closeFn;
            if (noButton) noButton.onclick = closeFn;
            if (yesButton) yesButton.onclick = () => {
                closeFn();
                // For GET clear, CSRF is less critical but could be added to URL if needed for hardened version
                // Example: window.location.href = '?clear=1&csrf_token=' + encodeURIComponent(window.csrfToken);
                window.location.href = '?clear=1'; 
            };
            if (modalContent) modalContent.addEventListener('click', e => e.stopPropagation());
            document.getElementById("settingsDropdown")?.classList.remove("show");
        });
    }
});
