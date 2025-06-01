// ai_playground_utils.js

// Replaces newline characters with <br> tags.
function nl2br(str) {
    if (typeof str !== 'string') return '';
    return str.replace(/\n/g, '<br>');
}

// Escapes HTML special characters.
function escapeHtml(text) {
    if (typeof text !== 'string') return '';
    const placeholderPrefix = "%%PLACEHOLDER";
    const thinkBlockPrefix = "%%THINKBLOCKID";
    const preBlockPlaceholderPrefix = "%%PREBLOCKPLACEHOLDER";

    if (text.startsWith(placeholderPrefix) || text.startsWith(thinkBlockPrefix) || text.startsWith(preBlockPlaceholderPrefix)) {
        return text;
    }
    const div = document.createElement('div');
    div.appendChild(document.createTextNode(text));
    return div.innerHTML;
}

// Checks if the current device is a touch device.
function isTouchDevice() {
    return (('ontouchstart' in window) || (navigator.maxTouchPoints > 0) || (navigator.msMaxTouchPoints > 0));
}

// Generates a UUID v4.
function generateUUID() {
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
        var r = Math.random()*16|0, v = c == 'x' ? r : (r&0x3|0x8);
        return v.toString(16);
    });
}

// Recursively processes inline Markdown elements.
function recursivelyProcessInlineMarkdown(text) {
    if (typeof text !== 'string' || !text.trim()) return text;

    const placeholderPrefix = "%%PLACEHOLDER";
    const thinkBlockPrefix = "%%THINKBLOCKID";
    const preBlockPlaceholderPrefix = "%%PREBLOCKPLACEHOLDER";
    if (text.startsWith(placeholderPrefix) || text.startsWith(thinkBlockPrefix) || text.startsWith(preBlockPlaceholderPrefix)) {
        return text;
    }

    let processedText = text;
    const originalText = text;

    processedText = processedText.replace(/~~([\s\S]+?)~~/g, (match, content) => `<del>${recursivelyProcessInlineMarkdown(content)}</del>`);
    processedText = processedText.replace(/\*{3}([\s\S]+?)\*{3}/g, (match, content) => `<strong><em>${recursivelyProcessInlineMarkdown(content)}</em></strong>`);
    processedText = processedText.replace(/\*\*_([\s\S]+?)_\*\*/g, (match, content) => `<strong><em>${recursivelyProcessInlineMarkdown(content)}</em></strong>`);
    processedText = processedText.replace(/\*__([\s\S]+?)__\*/g, (match, content) => `<strong><em>${recursivelyProcessInlineMarkdown(content)}</em></strong>`);
    processedText = processedText.replace(/\*\*(.+?)\*\*/g, (match, content) => `<strong>${recursivelyProcessInlineMarkdown(content)}</strong>`);
    processedText = processedText.replace(/__(.+?)__/g, (match, content) => `<strong>${recursivelyProcessInlineMarkdown(content)}</strong>`);
    processedText = processedText.replace(/\*(.+?)\*/g, (match, content) => `<em>${recursivelyProcessInlineMarkdown(content)}</em>`);
    processedText = processedText.replace(/(?<![a-zA-Z0-9_])_(?!_)(.+?)(?<!_)_(?!_)(?![a-zA-Z0-9_])/g, (match, content) => `<em>${recursivelyProcessInlineMarkdown(content)}</em>`);

    if (processedText === originalText && !(/<[^>]+>/.test(processedText))) {
        return escapeHtml(processedText);
    }
    return processedText;
}

// Processes block-level and major inline Markdown elements.
function processInnerMarkdownJs(text) {
    if (typeof text !== 'string') text = String(text);

    const codePlaceholders = {};
    let codePlaceholderId = 0;
    const codePlaceholderPrefix = "%%PLACEHOLDER";
    const codePlaceholderSuffix = "ID";
    const codePlaceholderEnd = "%%";

    // Stage 1: Isolate Code Blocks and Inline Code
    text = text.replace(/^\s*```(\w*)\n([\s\S]*?)\n?\s*```\s*$/gm, (match, lang, codeContent) => {
        const placeholderKey = `${codePlaceholderPrefix}CODEBLOCK${codePlaceholderSuffix}${codePlaceholderId++}${codePlaceholderEnd}`;
        const languageClass = lang ? `language-${escapeHtml(lang.toLowerCase())}` : '';
        codePlaceholders[placeholderKey] = `<pre><button class="copy-code-button">Copy</button><code class="${languageClass}">${escapeHtml(codeContent.trim())}</code></pre>`;
        return placeholderKey;
    });

    text = text.replace(/`([^`]+?)`/g, (match, inlineCodeContent) => {
        const placeholderKey = `${codePlaceholderPrefix}INLINECODE${codePlaceholderSuffix}${codePlaceholderId++}${codePlaceholderEnd}`;
        codePlaceholders[placeholderKey] = `<code>${escapeHtml(inlineCodeContent)}</code>`;
        return placeholderKey;
    });

    // Stage 2: Process Block-Level Elements line by line
    let lines = text.split('\n');
    let processedLines = [];
    let listStack = []; // { type: 'ul'/'ol', indent: number }
    let currentBlockquoteLines = [];

    function getIndent(line) {
        const match = line.match(/^(\s*)/);
        return match ? match[1].length : 0;
    }
    
    function flushBlockquote() {
        if (currentBlockquoteLines.length > 0) {
            function buildLevel(bqLines, level) {
                let html = '';
                let currentLevelContent = [];
                let nestedLevelLines = [];
                let i = 0;

                while (i < bqLines.length) {
                    const bqLine = bqLines[i];
                    const leadingChars = bqLine.match(/^(\s*>+)/);
                    const currentLineLevel = leadingChars ? leadingChars[1].replace(/\s/g, '').length : 0;
                    const content = bqLine.replace(/^\s*>+\s?/, '');

                    if (currentLineLevel === level) {
                        currentLevelContent.push(recursivelyProcessInlineMarkdown(content));
                        i++;
                    } else if (currentLineLevel > level) {
                        if (currentLevelContent.length > 0) {
                            html += `<p>${currentLevelContent.join('<br>')}</p>`;
                            currentLevelContent = [];
                        }
                        let startNestedIdx = i;
                        while (i < bqLines.length && (bqLines[i].match(/^(\s*>+)/)?.[1].replace(/\s/g, '').length || 0) > level) {
                            nestedLevelLines.push(bqLines[i].substring(bqLines[i].indexOf('>') + 1)); // Pass stripped line for nesting
                            i++;
                        }
                        html += buildLevel(nestedLevelLines, level + 1);
                        nestedLevelLines = []; // Reset for next potential nested block
                    } else { // currentLineLevel < level, or not a bq line
                        break; 
                    }
                }
                if (currentLevelContent.length > 0) {
                    html += `<p>${currentLevelContent.join('<br>')}</p>`;
                }
                return `<blockquote>${html}</blockquote>`;
            }
            processedLines.push(buildLevel(currentBlockquoteLines, 1));
            currentBlockquoteLines = [];
        }
    }

    function manageListStack(lineIndent, listType) {
        // Close lists with greater or equal indent if type changes, or just greater indent
        while (listStack.length > 0) {
            const topList = listStack[listStack.length - 1];
            if (lineIndent < topList.indent || (lineIndent === topList.indent && listType !== topList.type)) {
                processedLines.push(topList.type === 'ul' ? '</ul>' : '</ol>');
                listStack.pop();
            } else {
                break;
            }
        }
        // Open new list if needed
        if (listStack.length === 0 || lineIndent > listStack[listStack.length - 1].indent || listType !== listStack[listStack.length - 1].type) {
            processedLines.push(listType === 'ul' ? '<ul>' : '<ol>');
            listStack.push({ type: listType, indent: lineIndent });
        }
    }
    
    function closeAllOpenLists() {
        while (listStack.length > 0) {
            const topList = listStack.pop();
            processedLines.push(topList.type === 'ul' ? '</ul>' : '</ol>');
        }
    }

    for (let i = 0; i < lines.length; i++) {
        let line = lines[i];
        const lineIndent = getIndent(line);

        if (line.startsWith(codePlaceholderPrefix)) {
            flushBlockquote(); closeAllOpenLists();
            processedLines.push(line);
            continue;
        }

        let match = line.match(/^\s*(#{1,6})\s+(.*?)\s*$/);
        if (match) {
            flushBlockquote(); closeAllOpenLists();
            const level = match[1].length;
            processedLines.push(`<h${level}>${recursivelyProcessInlineMarkdown(match[2].trim())}</h${level}>`);
            continue;
        }

        if (line.match(/^\s*---\s*$/)) {
            flushBlockquote(); closeAllOpenLists();
            processedLines.push('<hr>');
            continue;
        }
        
        match = line.match(/^\s*>\s?(.*)$/); // Blockquote
        if (match) {
            closeAllOpenLists(); // Blockquotes break lists
            currentBlockquoteLines.push(line); // Store raw line with '>'
             if (i === lines.length - 1 || !lines[i+1].match(/^\s*>\s?(.*)$/)) {
                flushBlockquote(); 
            }
            continue;
        } else if (currentBlockquoteLines.length > 0) {
            flushBlockquote(); 
        }
        
        let listItemMatch = line.match(/^(\s*)(?:(-\s+)|(\d+\.\s+))(.*)$/); // Matches both ul and ol list items
        if (listItemMatch) {
            const indent = listItemMatch[1].length;
            const type = listItemMatch[2] ? 'ul' : 'ol'; // Check if '-' or 'digit.' matched
            const itemContent = listItemMatch[4].trim();

            manageListStack(indent, type);
            processedLines.push(`<li>${recursivelyProcessInlineMarkdown(itemContent)}</li>`);
            continue;
        }
        
        // If it's not a list item or any other block, close all lists.
        if (listStack.length > 0 && line.trim() !== '') { // Only close if line has content
             closeAllOpenLists();
        }
        
        processedLines.push(line); 
    }
    flushBlockquote();
    closeAllOpenLists();

    text = processedLines.join('\n');

    // Stage 3: Process Tables
    text = text.replace(
        /^\s*\|(.+?)\|\s*\n\s*\|(\s*[:\-]{3,}\s*\|)+\s*?\n((?:\|.*?\n)*)/gm,
        (tableMatch, headerLine, separatorLineRepeated, bodyLines) => {
            let tableHtml = '<table>';
            tableHtml += '<thead><tr>';
            headerLine.trim().split('|').map(s => s.trim()).filter((s,idx,arr) => s || idx < arr.length -1 ).forEach(cell => {
                tableHtml += `<th>${recursivelyProcessInlineMarkdown(cell)}</th>`;
            });
            tableHtml += '</tr></thead>';

            tableHtml += '<tbody>';
            if (bodyLines.trim()) {
                bodyLines.trim().split('\n').filter(rl => rl.trim()).forEach(rowLine => {
                    tableHtml += '<tr>';
                    const cellsArray = rowLine.trim().split('|');
                    if (cellsArray.length > 1) {
                        cellsArray.slice(1, cellsArray.length - 1).map(s => s.trim()).forEach(cell => {
                            tableHtml += `<td>${recursivelyProcessInlineMarkdown(cell)}</td>`;
                        });
                    }
                    tableHtml += '</tr>';
                });
            }
            tableHtml += '</tbody></table>';
            return tableHtml;
        }
    );

    // Stage 4: Process Inline Images and Links
    text = text.replace(/!\[(.*?)\]\((.*?)(?:\s+"(.*?)")?\)/g, (match, alt, src, title) => {
        const escapedSrc = escapeHtml(src.trim());
        const escapedAlt = escapeHtml(alt.trim());
        let imgTag = `<img src="${escapedSrc}" alt="${escapedAlt}"`;
        if (title) imgTag += ` title="${escapeHtml(title.trim())}"`;
        imgTag += '>';
        return imgTag;
    });

    text = text.replace(/\[([^\]]+)\]\((.*?)(?:\s+"(.*?)")?\)/g, (match, linkText, url, title) => {
        const escapedUrl = escapeHtml(url.trim());
        const processedLinkText = recursivelyProcessInlineMarkdown(linkText.trim());
        let linkTag = `<a href="${escapedUrl}"`;
        if (title) linkTag += ` title="${escapeHtml(title.trim())}"`;
        if (escapedUrl.startsWith('http://') || escapedUrl.startsWith('https://') || escapedUrl.startsWith('//')) {
            linkTag += ' target="_blank" rel="noopener noreferrer"';
        }
        linkTag += `>${processedLinkText}</a>`;
        return linkTag;
    });

    // Stage 5: Restore Code Placeholders
    if (Object.keys(codePlaceholders).length > 0) {
        const sortedPlaceholders = Object.keys(codePlaceholders).sort((a, b) => b.length - a.length);
        for (const placeholderKey of sortedPlaceholders) {
            const escapedRegexKey = placeholderKey.replace(/([.*+?^=!:${}()|\[\]\/\\])/g, "\\$1");
            text = text.replace(new RegExp(escapedRegexKey, 'g'), codePlaceholders[placeholderKey]);
        }
    }
    return text;
}

// Converts Markdown text to HTML.
function markdownToHtmlJs(text) {
    if (typeof text !== 'string') text = String(text);

    const thinkPlaceholders = {};
    let thinkPlaceholderId = 0;
    const thinkBlockPrefix = "%%THINKBLOCKID";
    const thinkPlaceholderSuffix = "%%";

    const preBlockPlaceholders = {}; 
    let preBlockPlaceholderId = 0;
    const preBlockPlaceholderPrefix = "%%PREBLOCKPLACEHOLDER";

    // 1. Isolate <think> blocks
    text = text.replace(/<think>([\s\S]*?)(?:<\/think>|$)/gs, (match, thoughtContent) => {
        const placeholderKey = `${thinkBlockPrefix}${thinkPlaceholderId++}${thinkPlaceholderSuffix}`;
        let processedThoughtContent = processInnerMarkdownJs(thoughtContent.trim());
        processedThoughtContent = processedThoughtContent.replace(/(<pre>.*?<\/pre>|<h[1-6]>.*?<\/h[1-6]>|<ul>.*?<\/ul>|<ol>.*?<\/ol>|<li[^>]*>.*?<\/li>|<hr>|<blockquote>.*?<\/blockquote>|<table>.*?<\/table>)|\n/gs, (m, htmlBlock) => {
            return htmlBlock ? htmlBlock : '<br>';
        });
        thinkPlaceholders[placeholderKey] = `<details class="ai-thought" open><summary>AI's thought process</summary><div class="thought-content">${processedThoughtContent}</div></details>`;
        return placeholderKey;
    });

    // 2. Process the main content for Markdown elements
    let mainContentProcessed = processInnerMarkdownJs(text);

    // 3. Restore <think> block placeholders
    if (Object.keys(thinkPlaceholders).length > 0) {
        const sortedPlaceholders = Object.keys(thinkPlaceholders).sort((a, b) => b.length - a.length);
        for (const placeholderKey of sortedPlaceholders) {
            const escapedKey = placeholderKey.replace(/([.*+?^=!:${}()|\[\]\/\\])/g, "\\$1");
            mainContentProcessed = mainContentProcessed.replace(new RegExp(escapedKey, 'g'), thinkPlaceholders[placeholderKey]);
        }
    }
    
    // 4. Temporarily replace fully formed <pre> blocks
    mainContentProcessed = mainContentProcessed.replace(/<pre>[\s\S]*?<\/pre>/g, (match) => {
        const placeholderKey = `${preBlockPlaceholderPrefix}${preBlockPlaceholderId++}${thinkPlaceholderSuffix}`; 
        preBlockPlaceholders[placeholderKey] = match;
        return placeholderKey;
    });

    // 5. Paragraph wrapping logic
    const lines = mainContentProcessed.split('\n');
    let finalHtml = '';
    let paragraphBuffer = '';
    const blockTagRegex = /^\s*<(h[1-6]|ul|ol|li|blockquote|hr|details|p|img|a|table|thead|tbody|tr|th|td)(?:>|[\s>])/i;

    function flushParagraphBuffer() {
        const trimmedBuffer = paragraphBuffer.trim();
        if (trimmedBuffer.length > 0) {
            finalHtml += `<p>${recursivelyProcessInlineMarkdown(trimmedBuffer)}</p>\n`;
        }
        paragraphBuffer = '';
    }

    for (let i = 0; i < lines.length; i++) {
        const currentLine = lines[i];
        const trimmedLine = currentLine.trim();

        if (trimmedLine.startsWith(preBlockPlaceholderPrefix)) { 
            flushParagraphBuffer();
            finalHtml += currentLine + '\n'; 
        } else if (trimmedLine.length === 0) {
            flushParagraphBuffer();
        } else if (blockTagRegex.test(trimmedLine)) {
            flushParagraphBuffer();
            finalHtml += currentLine + '\n';
        } else {
            paragraphBuffer += (paragraphBuffer ? "\n" : "") + currentLine;
        }
    }
    flushParagraphBuffer();

    // 6. Restore <pre> block placeholders
    if (Object.keys(preBlockPlaceholders).length > 0) {
        const sortedPrePlaceholders = Object.keys(preBlockPlaceholders).sort((a, b) => b.length - a.length);
        for (const placeholderKey of sortedPrePlaceholders) {
            const escapedKey = placeholderKey.replace(/([.*+?^=!:${}()|\[\]\/\\])/g, "\\$1");
            finalHtml = finalHtml.replace(new RegExp(escapedKey, 'g'), preBlockPlaceholders[placeholderKey]);
        }
    }

    finalHtml = finalHtml.trim();
    finalHtml = finalHtml.replace(/<p>\s*<\/p>/gi, '');
    finalHtml = finalHtml.replace(/(<br\s*\/?>\s*){2,}/gi, '<br>\n');

    return finalHtml;
}
