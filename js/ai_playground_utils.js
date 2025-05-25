// ai_playground_utils.js

/**
 * Replaces newline characters with <br> tags.
 * @param {string} str
 * @returns {string}
 */
function nl2br(str) {
    if (typeof str !== 'string') return '';
    return str.replace(/\n/g, '<br>');
}

/**
 * Escapes HTML special characters in a string.
 * @param {string} text
 * @returns {string}
 */
function escapeHtml(text) {
    if (typeof text !== 'string') return '';
    if (/&[#\w]+;/.test(text) || text.startsWith("%%PLACEHOLDER")) {
        return text;
    }
    const div = document.createElement('div');
    div.appendChild(document.createTextNode(text));
    return div.innerHTML;
}

/**
 * Checks if the current device is a touch device.
 * @returns {boolean}
 */
function isTouchDevice() {
    return (('ontouchstart' in window) || (navigator.maxTouchPoints > 0) || (navigator.msMaxTouchPoints > 0));
}

/**
 * Generates a UUID v4.
 * @returns {string}
 */
function generateUUID() {
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
        var r = Math.random()*16|0, v = c == 'x' ? r : (r&0x3|0x8);
        return v.toString(16);
    });
}

/**
 * Recursively processes inline Markdown elements.
 * @param {string} text
 * @returns {string}
 */
function recursivelyProcessInlineMarkdown(text) {
    if (typeof text !== 'string' || !text.trim()) return text;

    let processedText = text;
    let changedInThisPass = false;

    processedText = processedText.replace(/\*{3}([\s\S]+?)\*{3}/gs, (match, content) => {
        changedInThisPass = true;
        return `<strong><em>${recursivelyProcessInlineMarkdown(content)}</em></strong>`;
    });
    processedText = processedText.replace(/\*\*_([\s\S]+?)_\*\*/gs, (match, content) => {
        changedInThisPass = true;
        return `<strong><em>${recursivelyProcessInlineMarkdown(content)}</em></strong>`;
    });
    processedText = processedText.replace(/\*__([\s\S]+?)__\*/gs, (match, content) => {
        changedInThisPass = true;
        return `<strong><em>${recursivelyProcessInlineMarkdown(content)}</em></strong>`;
    });

    processedText = processedText.replace(/\*\*(.+?)\*\*/gs, (match, content) => {
        changedInThisPass = true;
        return `<strong>${recursivelyProcessInlineMarkdown(content)}</strong>`;
    });
    processedText = processedText.replace(/__(.+?)__/gs, (match, content) => {
        changedInThisPass = true;
        return `<strong>${recursivelyProcessInlineMarkdown(content)}</strong>`;
    });

    processedText = processedText.replace(/\*(.+?)\*/gs, (match, content) => {
        changedInThisPass = true;
        return `<em>${recursivelyProcessInlineMarkdown(content)}</em>`;
    });
    processedText = processedText.replace(/_(.+?)_/gs, (match, content) => {
        changedInThisPass = true;
        return `<em>${recursivelyProcessInlineMarkdown(content)}</em>`;
    });

    if (!changedInThisPass) {
        if (!(/&[#\w]+;/.test(processedText) || processedText.startsWith("%%PLACEHOLDER") || /<[^>]+>/.test(processedText))) {
            return escapeHtml(processedText);
        }
    }
    return processedText;
}

/**
 * Processes block-level Markdown.
 * @param {string} text
 * @returns {string}
 */
function processInnerMarkdownJs(text) {
    if (typeof text !== 'string') text = String(text);

    const placeholders = {};
    let placeholderId = 0;
    const placeholderPrefix = "%%PLACEHOLDER"; 
    const placeholderSuffix = "ID";
    const placeholderEnd = "%%";

    text = text.replace(/```(\w*)\n([\s\S]*?)(```|$)/gs, (match, lang, codeContent) => {
        const placeholderKey = `${placeholderPrefix}CODEBLOCK${placeholderSuffix}${placeholderId++}${placeholderEnd}`;
        const languageClass = lang ? `language-${escapeHtml(lang)}` : '';
        placeholders[placeholderKey] = `<pre><button class="copy-code-button">Copy</button><code class="${languageClass}">${escapeHtml(codeContent.trim())}</code></pre>`;
        return placeholderKey;
    });

    text = text.replace(/`([^`]+?)`/g, (match, inlineCodeContent) => {
        const placeholderKey = `${placeholderPrefix}INLINECODE${placeholderSuffix}${placeholderId++}${placeholderEnd}`;
        placeholders[placeholderKey] = `<code>${escapeHtml(inlineCodeContent)}</code>`;
        return placeholderKey;
    });
    
    text = text.replace(/^---\s*$/gm, '<hr>');
    text = text.replace(/^###\s*(.*?)$/gm, (match, headingContent) => `<h3>${recursivelyProcessInlineMarkdown(headingContent.trim())}</h3>`);

    text = text.replace(/^\d+\.\s*(.*?)$/gm, (match, listItemContent) => `<li_ol_temp>${recursivelyProcessInlineMarkdown(listItemContent.trim())}</li_ol_temp>`);
    text = text.replace(/^-\s*(.*?)$/gm, (match, listItemContent) => `<li_ul_temp>${recursivelyProcessInlineMarkdown(listItemContent.trim())}</li_ul_temp>`);

    text = text.replace(/(<li_ol_temp>.*?<\/li_ol_temp>\s*)+/gs, (match) => `<ol>${match.replace(/<li_ol_temp>/g, '<li>').replace(/<\/li_ol_temp>/g, '</li>').replace(/\s*<\/li>\s*<li>\s*/g, '</li><li>')}</ol>`);
    text = text.replace(/(<li_ul_temp>.*?<\/li_ul_temp>\s*)+/gs, (match) => `<ul>${match.replace(/<li_ul_temp>/g, '<li>').replace(/<\/li_ul_temp>/g, '</li>').replace(/\s*<\/li>\s*<li>\s*/g, '</li><li>')}</ul>`);

    text = text.replace(/^>\s*(.*?)$/gm, (match, quoteContent) => `<p_bq_temp>${recursivelyProcessInlineMarkdown(quoteContent.trim())}</p_bq_temp>`);
    text = text.replace(/(<p_bq_temp>.*?<\/p_bq_temp>\s*)+/gs, (match) => {
        let content = match.replace(/<p_bq_temp>/g, '').replace(/<\/p_bq_temp>/g, '<br>').trim();
        if (content.endsWith('<br>')) content = content.slice(0, -4); 
        return `<blockquote><p>${content}</p></blockquote>`;
    });

    const sortedPlaceholders = Object.keys(placeholders).sort((a, b) => b.length - a.length);
    for (const placeholderKey of sortedPlaceholders) {
        const escapedKey = placeholderKey.replace(/([.*+?^=!:${}()|\[\]\/\\])/g, "\\$1");
        text = text.replace(new RegExp(escapedKey, 'g'), () => placeholders[placeholderKey]);
    }
    return text;
}

/**
 * Converts Markdown text to HTML.
 * @param {string} text
 * @returns {string}
 */
function markdownToHtmlJs(text) {
    if (typeof text !== 'string') text = String(text);

    const thinkPlaceholders = {};
    let thinkPlaceholderId = 0;
    const thinkPlaceholderPrefix = "%%THINKBLOCKID"; 
    const thinkPlaceholderSuffix = "%%";

    text = text.replace(/<think>([\s\S]*?)(?:<\/think>|$)/gs, (match, thoughtContent) => {
        const placeholderKey = `${thinkPlaceholderPrefix}${thinkPlaceholderId++}${thinkPlaceholderSuffix}`;
        const processedThoughtContent = processInnerMarkdownJs(thoughtContent.trim());
        const finalThoughtContent = processedThoughtContent.replace(/(<pre>.*?<\/pre>|<h3>.*?<\/h3>|<ul>.*?<\/ul>|<ol>.*?<\/ol>|<li[^>]*>.*?<\/li>|<hr>|<blockquote>.*?<\/blockquote>)|\n/gs, (m, htmlBlock) => {
            return htmlBlock ? htmlBlock : '<br>';
        });
        thinkPlaceholders[placeholderKey] = `<details class="ai-thought" open><summary>AI's thought process</summary><div class="thought-content">${finalThoughtContent}</div></details>`;
        return placeholderKey;
    });

    let mainContentProcessed = processInnerMarkdownJs(text);

    const sortedThinkPlaceholders = Object.keys(thinkPlaceholders).sort((a, b) => b.length - a.length);
    for (const placeholderKey of sortedThinkPlaceholders) {
        const escapedKey = placeholderKey.replace(/([.*+?^=!:${}()|\[\]\/\\])/g, "\\$1");
        mainContentProcessed = mainContentProcessed.replace(new RegExp(escapedKey, 'g'), () => thinkPlaceholders[placeholderKey]);
    }
    
    const lines = mainContentProcessed.split('\n');
    let finalHtml = '';
    let paragraphBuffer = '';
    const blockTagRegex = /^\s*<(pre|h[1-6]|ul|ol|li|blockquote|hr|details|p)(?:>|[\s>])/i;

    function flushParagraphBuffer() {
        if (paragraphBuffer.trim().length > 0) {
            finalHtml += `<p>${recursivelyProcessInlineMarkdown(paragraphBuffer.trim())}</p>\n`;
        }
        paragraphBuffer = '';
    }

    for (let i = 0; i < lines.length; i++) {
        const line = lines[i];
        if (blockTagRegex.test(line.trim())) {
            flushParagraphBuffer();
            finalHtml += line + '\n';
        } else if (line.trim().length === 0) {
            flushParagraphBuffer();
        } else {
            paragraphBuffer += (paragraphBuffer ? '\n' : '') + line;
        }
    }
    flushParagraphBuffer();
    
    finalHtml = finalHtml.trim(); 
    finalHtml = finalHtml.replace(/<p>\s*<\/p>/gi, '');
    finalHtml = finalHtml.replace(/(<br\s*\/?>\s*){2,}/gi, '<br>\n');

    return finalHtml;
}
