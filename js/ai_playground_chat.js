// ai_playground_chat.js

document.addEventListener('DOMContentLoaded', () => {
    if (typeof chatForm !== 'undefined' && chatForm) {
        chatForm.addEventListener('submit', async function(event) {
            event.preventDefault();
            if (!messageInput || !sendButton || !chatBox) {
                console.error("Chat UI elements not found.");
                return;
            }

            const userMessageContent = messageInput.value.trim();
            if (!userMessageContent) return;

            clearPreviousLastMessageActions(); 

            const requestId = generateUUID();
            abortController = new AbortController();
            setUiLoadingState(true, requestId);

            if (projectInfoDiv && projectInfoDiv.style.display !== 'none') {
                projectInfoDiv.style.display = 'none';
            }

            const tempUserMessageId = 'temp_user_' + Date.now();
            const userMessageDiv = document.createElement('div');
            userMessageDiv.classList.add('message', 'user');
            userMessageDiv.dataset.messageId = tempUserMessageId;
            userMessageDiv.innerHTML = `
                <div class="message-content" id="message-content-${tempUserMessageId}">${nl2br(escapeHtml(userMessageContent))}</div>
                <div class="message-actions"></div>
            `;
            chatBox.appendChild(userMessageDiv);
            messageInput.value = '';
            autoResizeTextarea(messageInput);

            const tempAiMessageId = 'temp_ai_' + Date.now();
            const aiMessageDiv = document.createElement('div');
            aiMessageDiv.classList.add('message', 'assistant', 'message-streaming-placeholder');
            aiMessageDiv.dataset.messageId = tempAiMessageId;
            const aiContentDivId = `message-content-${tempAiMessageId}`;
            aiMessageDiv.innerHTML = `
                <div class="message-content" id="${aiContentDivId}">
                    <span class="typing-indicator"><span class="dot"></span><span class="dot"></span><span class="dot"></span></span>
                </div>
                <div class="message-actions"></div>
            `;
            chatBox.appendChild(aiMessageDiv);
            updateAllActionButtons();
            forceScrollToBottom();
            const aiContentDiv = document.getElementById(aiContentDivId);

            try {
                const bodyParams = new URLSearchParams({
                    message: userMessageContent,
                    requestId: requestId
                });
                if (window.csrfToken) bodyParams.append('csrf_token', window.csrfToken);

                const response = await fetch(window.location.pathname, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: bodyParams,
                    signal: abortController.signal
                });

                if (abortController.signal.aborted) throw new Error("AbortError");
                const data = await response.json();
                if (data.error && data.error.toLowerCase().includes('csrf')) { // Handle CSRF error specifically
                    showTempModal(`Security token error: ${data.error} Please refresh the page.`, 5000, true);
                    setUiLoadingState(false); // Unlock UI
                    return;
                }
                if (data.status === 'cancelled') throw new Error("CancelledByServer");

                const actualUserMessageId = data.userMessageId || tempUserMessageId;
                userMessageDiv.dataset.messageId = actualUserMessageId;
                const userContentDiv = userMessageDiv.querySelector('.message-content');
                if (userContentDiv) userContentDiv.id = `message-content-${actualUserMessageId}`;


                if (data.success && data.newAIMessageContent) {
                    const actualAiMessageId = data.newAIMessageId || tempAiMessageId;
                    aiMessageDiv.dataset.messageId = actualAiMessageId;
                    if (aiContentDiv) aiContentDiv.id = `message-content-${actualAiMessageId}`;

                    aiMessageVersions[actualAiMessageId] = [data.newAIMessageContent];
                    aiMessageDiv.dataset.currentVersionIndex = 0;
                    aiMessageDiv.dataset.totalVersions = 1;
                    aiMessageDiv.dataset.allContents = JSON.stringify(aiMessageVersions[actualAiMessageId]);

                    if (textStreamingToggle && textStreamingToggle.checked) {
                        if(aiContentDiv) await typeEffect(aiContentDiv, data.newAIMessageContent);
                    } else {
                        if(aiContentDiv) {
                            aiContentDiv.innerHTML = markdownToHtmlJs(data.newAIMessageContent);
                            aiContentDiv.querySelectorAll('details.ai-thought').forEach(detail => { detail.open = true; });
                            attachCopyButtonListeners(aiContentDiv);
                        }
                    }
                } else {
                    if(aiContentDiv) aiContentDiv.innerHTML = `<em class="error-text">Error: ${escapeHtml(data.error || 'Unknown error.')}</em>`;
                }
            } catch (error) {
                 const currentContent = aiMessageDiv.querySelector('.message-content');
                 if (currentContent) {
                    if (error.name === 'AbortError' || error.message === 'CancelledByServer') {
                        currentContent.innerHTML = `<em class="info-text">Response stopped/cancelled.</em>`;
                    } else {
                        currentContent.innerHTML = `<em class="error-text">Network Error: ${escapeHtml(error.message)}</em>`;
                        console.error('Network or other error:', error);
                    }
                 }
            } finally {
                aiMessageDiv.classList.remove('message-streaming-placeholder');
                setUiLoadingState(false);
                updateAllActionButtons();
                scrollToBottom();
            }
        });
    }

    if (typeof chatBox !== 'undefined' && chatBox) {
        chatBox.addEventListener('click', async function(event) {
            const target = event.target;
            const messageElement = target.closest('.message');
            if (!messageElement) return;
            const messageId = messageElement.dataset.messageId;
            if (!messageId) return;

            if (target.classList.contains('edit-button')) {
                const messageContentDiv = document.getElementById(`message-content-${messageId}`);
                if (!messageContentDiv || messageElement.querySelector('.edit-container')) return;
                const originalContent = messageContentDiv.innerText;
                const actionsDiv = messageElement.querySelector('.message-actions');
                if(actionsDiv) actionsDiv.style.display = 'none';
                messageContentDiv.style.display = 'none';
                const editContainer = document.createElement('div');
                editContainer.classList.add('edit-container');
                editContainer.innerHTML = `
                    <textarea class="edit-textarea" rows="3">${escapeHtml(originalContent)}</textarea>
                    <div class="edit-action-buttons" style="margin-top: 5px; text-align: right;">
                        <button class="save-button action-btn primary-btn">Save & Regenerate</button>
                        <button class="cancel-button action-btn">Cancel</button>
                    </div>
                `;
                messageElement.appendChild(editContainer);
                const editTextArea = editContainer.querySelector('.edit-textarea');
                autoResizeTextarea(editTextArea);
                editTextArea.focus();
                editTextArea.addEventListener('input', () => autoResizeTextarea(editTextArea));

                editContainer.querySelector('.save-button').onclick = async () => {
                    const newContent = editTextArea.value.trim();
                    if (!newContent) return;
                    clearPreviousLastMessageActions();
                    const saveBtn = editContainer.querySelector('.save-button');
                    if(saveBtn) { saveBtn.disabled = true; saveBtn.textContent = 'Saving...'; }
                    const requestId = generateUUID();
                    abortController = new AbortController();
                    setUiLoadingState(true, requestId);
                    let nextSibling = messageElement.nextElementSibling;
                    while(nextSibling) {
                        let temp = nextSibling.nextElementSibling;
                        if (nextSibling.classList.contains('assistant')) delete aiMessageVersions[nextSibling.dataset.messageId];
                        nextSibling.remove();
                        nextSibling = temp;
                    }
                    const tempNewAiMessageId = 'temp_edit_ai_' + Date.now();
                    let newAiMessageDiv = document.createElement('div');
                    newAiMessageDiv.classList.add('message', 'assistant', 'message-streaming-placeholder');
                    newAiMessageDiv.dataset.messageId = tempNewAiMessageId;
                    const newAiContentDivId = `message-content-${tempNewAiMessageId}`;
                    newAiMessageDiv.innerHTML = `
                        <div class="message-content" id="${newAiContentDivId}">
                            <span class="typing-indicator"><span class="dot"></span><span class="dot"></span><span class="dot"></span></span>
                        </div>
                        <div class="message-actions"></div>`;
                    chatBox.appendChild(newAiMessageDiv);
                    updateAllActionButtons();
                    let newAiContentElement = document.getElementById(newAiContentDivId);

                    try {
                        const bodyParams = new URLSearchParams({
                            action: 'editAndRegenerate', messageId: messageId,
                            newContent: newContent, requestId: requestId
                        });
                        if (window.csrfToken) bodyParams.append('csrf_token', window.csrfToken);

                        const response = await fetch(window.location.pathname, {
                            method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: bodyParams, signal: abortController.signal,
                        });
                        if (abortController.signal.aborted) throw new Error("AbortError");
                        const data = await response.json();
                        if (data.error && data.error.toLowerCase().includes('csrf')) {
                            showTempModal(`Security token error: ${data.error} Please refresh the page.`, 5000, true);
                            throw new Error("CSRFError"); // Throw to go to finally block
                        }
                        if (data.status === 'cancelled') throw new Error("CancelledByServer");

                        if (data.success) {
                            messageContentDiv.innerHTML = nl2br(escapeHtml(newContent));
                            newAiMessageDiv.dataset.messageId = data.newAIMessageId;
                            if(newAiContentElement) newAiContentElement.id = `message-content-${data.newAIMessageId}`;
                            aiMessageVersions[data.newAIMessageId] = [data.newAIMessageContent];
                            newAiMessageDiv.dataset.currentVersionIndex = 0;
                            newAiMessageDiv.dataset.totalVersions = 1;
                            newAiMessageDiv.dataset.allContents = JSON.stringify(aiMessageVersions[data.newAIMessageId]);

                            if (textStreamingToggle && textStreamingToggle.checked) {
                                if(newAiContentElement) await typeEffect(newAiContentElement, data.newAIMessageContent);
                            } else {
                                if(newAiContentElement) {
                                    newAiContentElement.innerHTML = markdownToHtmlJs(data.newAIMessageContent);
                                    newAiContentElement.querySelectorAll('details.ai-thought').forEach(detail => { detail.open = true; });
                                    attachCopyButtonListeners(newAiContentElement);
                                }
                            }
                        } else {
                            if(newAiContentElement) newAiContentElement.innerHTML = `<em class="error-text">Error: ${escapeHtml(data.error || 'Failed to save.')}</em>`;
                        }
                    } catch (err) {
                        const currentAiContent = newAiMessageDiv ? newAiMessageDiv.querySelector('.message-content') : null;
                        if (currentAiContent && err.message !== "CSRFError") { // Don't overwrite CSRF error message
                            if (err.message === "AbortError" || err.name === 'AbortError' || err.message === "CancelledByServer") {
                                currentAiContent.innerHTML = '<em class="info-text">Response stopped/cancelled.</em>';
                            } else {
                                currentAiContent.innerHTML = `<em class="error-text">Network Error: ${escapeHtml(err.message)}</em>`;
                            }
                        }
                    } finally {
                        newAiMessageDiv.classList.remove('message-streaming-placeholder');
                        editContainer.remove();
                        if(actionsDiv) actionsDiv.style.display = 'flex';
                        messageContentDiv.style.display = 'block';
                        setUiLoadingState(false);
                        updateAllActionButtons();
                    }
                };
                editContainer.querySelector('.cancel-button').onclick = () => {
                    editContainer.remove();
                    if(actionsDiv) actionsDiv.style.display = 'flex';
                    messageContentDiv.style.display = 'block';
                    updateAllActionButtons();
                };
            }

            else if (target.classList.contains('delete-button')) {
                const confirmModal = document.createElement('div');
                confirmModal.classList.add('modal', 'js-dynamic-confirm-modal');
                confirmModal.style.display = 'flex';
                confirmModal.innerHTML = `<div class="modal-content"><span class="close-button">&times;</span><p>Delete this message and all subsequent messages?</p><div style="text-align:center;margin-top:15px;"><button class="action-btn primary-btn" id="confirmDeleteYesButton">Yes, Delete</button> <button class="action-btn" id="confirmDeleteNoButton">Cancel</button></div></div>`;
                document.body.appendChild(confirmModal);
                const closeConfirm = () => { if (document.body.contains(confirmModal)) confirmModal.remove(); };
                confirmModal.querySelector('.close-button').onclick = closeConfirm;
                confirmModal.querySelector('#confirmDeleteNoButton').onclick = closeConfirm;
                confirmModal.querySelector('#confirmDeleteYesButton').onclick = async () => {
                    closeConfirm();
                    try {
                        const bodyParams = new URLSearchParams({ action: 'deleteMessagesFromId', messageId: messageId });
                        if (window.csrfToken) bodyParams.append('csrf_token', window.csrfToken);
                        const response = await fetch(window.location.pathname, {
                            method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: bodyParams
                        });
                        const data = await response.json();
                        if (data.error && data.error.toLowerCase().includes('csrf')) {
                             showTempModal(`Security token error: ${data.error} Please refresh the page.`, 5000, true);
                             return;
                        }
                        if (data.success) {
                            let currentMsgElement = messageElement;
                            while(currentMsgElement) {
                                let next = currentMsgElement.nextElementSibling;
                                if (currentMsgElement.classList.contains('assistant')) {
                                   delete aiMessageVersions[currentMsgElement.dataset.messageId];
                                }
                                currentMsgElement.remove();
                                currentMsgElement = next;
                            }
                            updateAllActionButtons();
                        } else {
                            showTempModal(`Error: ${data.error || 'Failed to delete.'}`, 2500, true);
                        }
                    } catch (err) {
                        showTempModal(`Network error: ${err.message}`, 2500, true);
                    }
                };
            }

            else if (target.classList.contains('regenerate-button')) {
                const aiContentDiv = messageElement.querySelector('.message-content');
                if (!aiContentDiv) return;
                clearPreviousLastMessageActions();
                messageElement.classList.add('message-streaming-placeholder');
                const requestId = generateUUID();
                abortController = new AbortController();
                setUiLoadingState(true, requestId);
                aiContentDiv.innerHTML = `<span class="typing-indicator"><span class="dot"></span><span class="dot"></span><span class="dot"></span></span>`;
                updateAllActionButtons();
                forceScrollToBottom();

                try {
                    const bodyParams = new URLSearchParams({
                        action: 'regenerateReply', messageId: messageId, requestId: requestId
                    });
                    if (window.csrfToken) bodyParams.append('csrf_token', window.csrfToken);
                    const response = await fetch(window.location.pathname, {
                        method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: bodyParams, signal: abortController.signal
                    });
                    if (abortController.signal.aborted) throw new Error("AbortError");
                    const data = await response.json();
                    if (data.error && data.error.toLowerCase().includes('csrf')) {
                        showTempModal(`Security token error: ${data.error} Please refresh the page.`, 5000, true);
                        throw new Error("CSRFError");
                    }
                    if (data.status === 'cancelled') throw new Error("CancelledByServer");

                    if (data.success && data.newContent) {
                        aiMessageVersions[messageId] = aiMessageVersions[messageId] || [];
                        aiMessageVersions[messageId].push(data.newContent);
                        const newVersionIndex = data.currentVersionIndex;
                        const totalVersions = data.totalVersions;
                        messageElement.dataset.currentVersionIndex = newVersionIndex;
                        messageElement.dataset.totalVersions = totalVersions;
                        messageElement.dataset.allContents = JSON.stringify(aiMessageVersions[messageId]);

                        if (textStreamingToggle && textStreamingToggle.checked) {
                            await typeEffect(aiContentDiv, data.newContent);
                        } else {
                            aiContentDiv.innerHTML = markdownToHtmlJs(data.newContent);
                            aiContentDiv.querySelectorAll('details.ai-thought').forEach(detail => { detail.open = true; });
                            attachCopyButtonListeners(aiContentDiv);
                        }
                    } else {
                        aiContentDiv.innerHTML = `<em class="error-text">Error: ${escapeHtml(data.error || "Failed to regenerate.")}</em>`;
                    }
                } catch (err) {
                    if (err.message !== "CSRFError") { // Don't overwrite CSRF error message
                        if (err.name === 'AbortError' || err.message === 'CancelledByServer') {
                            aiContentDiv.innerHTML = `<em class="info-text">Regeneration stopped/cancelled.</em>`;
                        } else {
                            aiContentDiv.innerHTML = `<em class="error-text">Network Error: ${escapeHtml(err.message)}</em>`;
                        }
                    }
                } finally {
                    messageElement.classList.remove('message-streaming-placeholder');
                    setUiLoadingState(false);
                    updateAllActionButtons();
                }
            }

            else if (target.classList.contains('version-prev') || target.classList.contains('version-next')) {
                let currentVersionIndex = parseInt(messageElement.dataset.currentVersionIndex);
                const totalVersions = parseInt(messageElement.dataset.totalVersions);
                let newVersionIndex = currentVersionIndex + (target.classList.contains('version-next') ? 1 : -1);

                if (aiMessageVersions[messageId] && newVersionIndex >= 0 && newVersionIndex < totalVersions) {
                    const contentForVersion = aiMessageVersions[messageId][newVersionIndex];
                    updateAIMessageDisplay(messageElement, newVersionIndex, totalVersions, contentForVersion);
                    
                    const bodyParams = new URLSearchParams({
                        action: 'updateVersionIndex', messageId: messageId, newVersionIndex: newVersionIndex
                    });
                    if (window.csrfToken) bodyParams.append('csrf_token', window.csrfToken);
                    fetch(window.location.pathname, {
                        method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: bodyParams
                    }).catch(error => console.error('Error syncing version:', error));
                }
            }

            else if (target.classList.contains('copy-message-button')) {
                 const messageContentDiv = messageElement.querySelector('.message-content');
                if (messageContentDiv) {
                    const tempDiv = messageContentDiv.cloneNode(true);
                    tempDiv.querySelectorAll('details.ai-thought summary, .copy-code-button, .version-nav, .typing-indicator').forEach(el => el.remove());
                    tempDiv.querySelectorAll('details.ai-thought[open] .thought-content').forEach(detailContent => {
                        const parent = detailContent.parentNode;
                        if (parent) parent.innerHTML = detailContent.innerHTML;
                    });
                    tempDiv.querySelectorAll('details.ai-thought:not([open])').forEach(el => el.remove());
                    const textToCopy = tempDiv.innerText.trim();
                    try {
                        const tempTextArea = document.createElement('textarea');
                        tempTextArea.value = textToCopy;
                        document.body.appendChild(tempTextArea);
                        tempTextArea.select();
                        document.execCommand('copy');
                        document.body.removeChild(tempTextArea);
                        showTempModal('Copied to clipboard!');
                        target.textContent = 'Copied!';
                        setTimeout(() => { target.textContent = 'Copy'; }, 2000);
                    } catch (err) {
                        showTempModal('Failed to copy!', 2000, true);
                    }
                }
            }
        });
    }
});
