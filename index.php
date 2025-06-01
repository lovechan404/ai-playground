<?php

// --- SESSION AND CSRF SETUP ---
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_samesite', 'Lax');
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    ini_set('session.cookie_secure', 1);
}

session_start();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function validate_csrf_token($token_from_request) {
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token_from_request)) {
        return false;
    }
    return true;
}

// --- INCLUDES & INITIALIZATIONS ---
require_once 'includes/settings.php'; 

if (!isset($_SESSION['conversation'])) {
    $_SESSION['conversation'] = [];
}
if (!isset($_SESSION['cancelled_requests'])) {
    $_SESSION['cancelled_requests'] = [];
}
if (!isset($_SESSION['api_settings'])) {
    $_SESSION['api_settings'] = [
        'model' => '', 'url'   => '', 'key'   => '',
        'prompt'=> "You are a helpful assistant.",
        'max_tokens' => 1000, 'temperature' => 0.7
    ];
} else {
    if (!isset($_SESSION['api_settings']['max_tokens'])) $_SESSION['api_settings']['max_tokens'] = 1000;
    if (!isset($_SESSION['api_settings']['temperature'])) $_SESSION['api_settings']['temperature'] = 0.7;
}
if (!isset($_SESSION['advanced_settings'])) {
    $_SESSION['advanced_settings'] = [
        'enable_raw_reply_view' => false, 'enable_ai_response_edit' => false,
    ];
}

// --- START OF MARKDOWN PROCESSING FUNCTIONS ---
// These functions (escapeAttrPHP, escapeContentPHP, recursivelyProcessInlineMarkdownPHP, processInnerMarkdown, markdownToHtml)
// are extensive and handle the conversion of Markdown text to HTML for display.
// Their internal comments are preserved for clarity of their specific logic.

function escapeAttrPHP($string) {
    return htmlspecialchars((string)$string, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function escapeContentPHP($string) {
    return htmlspecialchars((string)$string, ENT_NOQUOTES | ENT_HTML5, 'UTF-8');
}

function recursivelyProcessInlineMarkdownPHP($text) {
    if (!is_string($text) || trim($text) === '') return $text;
    $codePlaceholderPrefix = "%%PHP_PLACEHOLDER_";
    $thinkBlockPrefix = "%%PHP_THINKBLOCKID_";
    $preBlockPlaceholderPrefix = "%%PHP_PREBLOCKPLACEHOLDER_";
    if (strpos($text, $codePlaceholderPrefix) === 0 || strpos($text, $thinkBlockPrefix) === 0 || strpos($text, $preBlockPlaceholderPrefix) === 0) return $text;
    $originalText = $text;
    $text = preg_replace_callback('/~~([\s\S]+?)~~/s', function($m) { return '<del>' . recursivelyProcessInlineMarkdownPHP($m[1]) . '</del>'; }, $text);
    $text = preg_replace_callback('/\*{3}([\s\S]+?)\*{3}/s', function($m) { return '<strong><em>' . recursivelyProcessInlineMarkdownPHP($m[1]) . '</em></strong>'; }, $text);
    $text = preg_replace_callback('/\*\*_([\s\S]+?)_\*\*/s', function($m) { return '<strong><em>' . recursivelyProcessInlineMarkdownPHP($m[1]) . '</em></strong>'; }, $text);
    $text = preg_replace_callback('/\*__([\s\S]+?)__\*/s', function($m) { return '<strong><em>' . recursivelyProcessInlineMarkdownPHP($m[1]) . '</em></strong>'; }, $text);
    $text = preg_replace_callback('/\*\*(.+?)\*\*/s', function($m) { return '<strong>' . recursivelyProcessInlineMarkdownPHP($m[1]) . '</strong>'; }, $text);
    $text = preg_replace_callback('/__(.+?)__/s', function($m) { return '<strong>' . recursivelyProcessInlineMarkdownPHP($m[1]) . '</strong>'; }, $text);
    $text = preg_replace_callback('/\*(.+?)\*/s', function($m) { return '<em>' . recursivelyProcessInlineMarkdownPHP($m[1]) . '</em>'; }, $text);
    $text = preg_replace_callback('/(?<![a-zA-Z0-9_])_(?!_)(.+?)(?<!_)_(?!_)(?![a-zA-Z0-9_])/s', function($m) { return '<em>' . recursivelyProcessInlineMarkdownPHP($m[1]) . '</em>'; }, $text);
    if ($text === $originalText && !preg_match('/<[^>]+>/', $text)) return escapeContentPHP($text);
    return $text;
}

function processInnerMarkdown($text) {
    if (!is_string($text)) $text = (string)$text;
    $codePlaceholders = []; $codePlaceholderId = 0; $codePlaceholderPrefix = "%%PHP_PLACEHOLDER_"; $codePlaceholderSuffix = "ID"; $codePlaceholderEnd = "%%";
    $text = preg_replace_callback('/^\s*```(\w*)\n([\s\S]*?)\n?\s*```\s*$/m', function($matches) use (&$codePlaceholders, &$codePlaceholderId, $codePlaceholderPrefix, $codePlaceholderSuffix, $codePlaceholderEnd) {
        $placeholderKey = $codePlaceholderPrefix . "CODEBLOCK" . $codePlaceholderSuffix . $codePlaceholderId++ . $codePlaceholderEnd;
        $language = !empty($matches[1]) ? 'language-' . escapeAttrPHP(strtolower($matches[1])) : '';
        $codePlaceholders[$placeholderKey] = '<pre><button class="copy-code-button">Copy</button><code class="' . $language . '">' . escapeContentPHP(trim($matches[2])) . '</code></pre>';
        return $placeholderKey;
    }, $text);
    $text = preg_replace_callback('/`([^`]+?)`/', function($matches) use (&$codePlaceholders, &$codePlaceholderId, $codePlaceholderPrefix, $codePlaceholderSuffix, $codePlaceholderEnd) {
        $placeholderKey = $codePlaceholderPrefix . "INLINECODE" . $codePlaceholderSuffix . $codePlaceholderId++ . $codePlaceholderEnd;
        $codePlaceholders[$placeholderKey] = '<code>' . escapeContentPHP($matches[1]) . '</code>';
        return $placeholderKey;
    }, $text);
    $lines = explode("\n", $text); $processedLines = []; $listStack = []; $currentBlockquoteLines = [];
    $getIndentPHP = function($line) { preg_match('/^(\s*)/', $line, $match); return $match ? strlen($match[1]) : 0; };
    $buildLevelPHP_ref_closure = null; // Placeholder for recursive closure
    $flushBlockquotePHP = function() use (&$currentBlockquoteLines, &$processedLines, &$buildLevelPHP_ref_closure) {
        if (empty($currentBlockquoteLines)) return;
        $buildLevelPHP_ref_closure = function($bqLines, $level) use (&$buildLevelPHP_ref_closure) {
            $html = ''; $currentLevelContent = []; $nestedLevelLines = []; $i = 0;
            while ($i < count($bqLines)) {
                $bqLine = $bqLines[$i]; preg_match('/^(\s*>+)/', $bqLine, $leadingCharsMatch);
                $currentLineLevel = $leadingCharsMatch ? strlen(str_replace(' ', '', $leadingCharsMatch[1])) : 0;
                $content = preg_replace('/^\s*>+\s?/', '', $bqLine);
                if ($currentLineLevel === $level) { $currentLevelContent[] = recursivelyProcessInlineMarkdownPHP($content); $i++; }
                elseif ($currentLineLevel > $level) {
                    if (!empty($currentLevelContent)) { $html .= '<p>' . implode('<br>', $currentLevelContent) . '</p>'; $currentLevelContent = []; }
                    while ($i < count($bqLines)) {
                        preg_match('/^(\s*>+)/', $bqLines[$i], $nestedLeadingMatch);
                        $nestedLevelTest = $nestedLeadingMatch ? strlen(str_replace(' ', '', $nestedLeadingMatch[1])) : 0;
                        if ($nestedLevelTest > $level) { $nestedLevelLines[] = substr($bqLines[$i], strpos($bqLines[$i], '>') + 1); $i++; } else { break; }
                    }
                    $html .= $buildLevelPHP_ref_closure($nestedLevelLines, $level + 1); $nestedLevelLines = [];
                } else { break; }
            }
            if (!empty($currentLevelContent)) { $html .= '<p>' . implode('<br>', $currentLevelContent) . '</p>'; }
            return '<blockquote>' . $html . '</blockquote>';
        };
        $processedLines[] = $buildLevelPHP_ref_closure($currentBlockquoteLines, 1); $currentBlockquoteLines = [];
    };
    $manageListStackPHP = function($lineIndent, $listType) use (&$listStack, &$processedLines) {
        while (!empty($listStack)) {
            $topList = end($listStack);
            if ($lineIndent < $topList['indent'] || ($lineIndent === $topList['indent'] && $listType !== $topList['type'])) {
                $processedLines[] = $topList['type'] === 'ul' ? '</ul>' : '</ol>'; array_pop($listStack);
            } else { break; }
        }
        if (empty($listStack) || $lineIndent > end($listStack)['indent'] || ($lineIndent === end($listStack)['indent'] && $listType !== end($listStack)['type'])) {
             $processedLines[] = $listType === 'ul' ? '<ul>' : '<ol>'; $listStack[] = ['type' => $listType, 'indent' => $lineIndent];
        } elseif (empty($listStack)) { $processedLines[] = $listType === 'ul' ? '<ul>' : '<ol>'; $listStack[] = ['type' => $listType, 'indent' => $lineIndent]; }
    };
    $closeAllOpenListsPHP = function() use (&$listStack, &$processedLines) { while (!empty($listStack)) { $topList = array_pop($listStack); $processedLines[] = $topList['type'] === 'ul' ? '</ul>' : '</ol>'; } };
    for ($idx = 0; $idx < count($lines); $idx++) {
        $line = $lines[$idx]; $lineIndent = $getIndentPHP($line);
        if (strpos($line, $codePlaceholderPrefix) === 0) { $flushBlockquotePHP(); $closeAllOpenListsPHP(); $processedLines[] = $line; continue; }
        if (preg_match('/^\s*(#{1,6})\s+(.*?)\s*$/', $line, $matches)) { $flushBlockquotePHP(); $closeAllOpenListsPHP(); $level = strlen($matches[1]); $processedLines[] = '<h' . $level . '>' . recursivelyProcessInlineMarkdownPHP(trim($matches[2])) . '</h' . $level . '>'; continue; }
        if (preg_match('/^\s*---\s*$/', $line)) { $flushBlockquotePHP(); $closeAllOpenListsPHP(); $processedLines[] = '<hr>'; continue; }
        if (preg_match('/^\s*>\s?(.*)$/', $line, $matches)) { $closeAllOpenListsPHP(); $currentBlockquoteLines[] = $line; if ($idx === count($lines) - 1 || !preg_match('/^\s*>\s?(.*)$/', $lines[$idx+1] ?? '')) { $flushBlockquotePHP(); } continue; }
        elseif (!empty($currentBlockquoteLines)) { $flushBlockquotePHP(); }
        if (preg_match('/^(\s*)(?:(-\s+)|(\d+\.\s+))(.*)$/', $line, $listItemMatch)) { $indent = strlen($listItemMatch[1]); $type = !empty($listItemMatch[2]) ? 'ul' : 'ol'; $itemContent = trim($listItemMatch[4]); $manageListStackPHP($indent, $type); $processedLines[] = '<li>' . recursivelyProcessInlineMarkdownPHP($itemContent) . '</li>'; continue; }
        if (!empty($listStack) && trim($line) !== '') { $closeAllOpenListsPHP(); }
        $processedLines[] = $line;
    }
    $flushBlockquotePHP(); $closeAllOpenListsPHP(); $text = implode("\n", $processedLines);
    $text = preg_replace_callback('/^\s*\|(.+?)\|\s*\n\s*\|(\s*[:\-]{3,}\s*\|)+\s*?\n((?:\|.*?\n)*)/m', function($tableMatch) {
        $headerLine = $tableMatch[1]; $bodyLinesText = $tableMatch[3]; $tableHtml = '<table><thead><tr>';
        $headerCells = array_filter(array_map('trim', explode('|', trim($headerLine))), function($cell, $idx) use ($headerLine) { return $cell !== '' || $idx < count(explode('|', trim($headerLine))) -1; }, ARRAY_FILTER_USE_BOTH);
        foreach ($headerCells as $cell) { $tableHtml .= '<th>' . recursivelyProcessInlineMarkdownPHP($cell) . '</th>'; }
        $tableHtml .= '</tr></thead><tbody>';
        if (trim($bodyLinesText)) {
            $bodyRows = array_filter(explode("\n", trim($bodyLinesText)));
            foreach ($bodyRows as $rowLine) {
                if (!trim($rowLine)) continue; $tableHtml .= '<tr>';
                $cellsArray = explode('|', trim($rowLine)); $actualBodyCells = [];
                if (count($cellsArray) > 1)  { $actualBodyCells = array_slice($cellsArray, 1, count($cellsArray) - 2); }
                $bodyCells = array_map('trim', $actualBodyCells);
                foreach ($bodyCells as $cell) { $tableHtml .= '<td>' . recursivelyProcessInlineMarkdownPHP($cell) . '</td>'; }
                $tableHtml .= '</tr>';
            }
        }
        $tableHtml .= '</tbody></table>'; return $tableHtml;
    }, $text);
    $text = preg_replace_callback('/!\[(.*?)\]\((.*?)(?:\s+"(.*?)")?\)/', function($matches) { $alt = escapeAttrPHP(trim($matches[1])); $src = escapeAttrPHP(trim($matches[2])); $title_attr = isset($matches[3]) ? ' title="' . escapeAttrPHP(trim($matches[3])) . '"' : ''; return '<img src="' . $src . '" alt="' . $alt . '"' . $title_attr . '>'; }, $text);
    $text = preg_replace_callback('/\[([^\]]+)\]\((.*?)(?:\s+"(.*?)")?\)/', function($matches) { $linkText = trim($matches[1]); $url = escapeAttrPHP(trim($matches[2])); $title_attr = isset($matches[3]) ? ' title="' . escapeAttrPHP(trim($matches[3])) . '"' : ''; $targetBlank = (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0 || strpos($url, '//') === 0) ? ' target="_blank" rel="noopener noreferrer"' : ''; return '<a href="' . $url . '"' . $title_attr . $targetBlank . '>' . recursivelyProcessInlineMarkdownPHP($linkText) . '</a>'; }, $text);
    if (!empty($codePlaceholders)) { krsort($codePlaceholders); foreach ($codePlaceholders as $key => $value) { $text = str_replace($key, $value, $text); } }
    return $text;
}

function markdownToHtml($text) {
    if (!is_string($text)) $text = (string)$text;
    $thinkPlaceholders = []; $thinkPlaceholderId = 0; $thinkBlockPrefix = "%%PHP_THINKBLOCKID_"; $thinkPlaceholderSuffix = "%%";
    $preBlockPlaceholders = []; $preBlockPlaceholderId = 0; $preBlockPlaceholderPrefix = "%%PHP_PREBLOCKPLACEHOLDER_";
    $text = preg_replace_callback('/<think>([\s\S]*?)(?:<\/think>|$)/s', function($matches) use (&$thinkPlaceholders, &$thinkPlaceholderId, $thinkBlockPrefix, $thinkPlaceholderSuffix) {
        $placeholderKey = $thinkBlockPrefix . $thinkPlaceholderId++ . $thinkPlaceholderSuffix;
        $processedThoughtContent = processInnerMarkdown(trim($matches[1]));
        $processedThoughtContent = preg_replace_callback('/(<pre>.*?<\/pre>|<h[1-6]>.*?<\/h[1-6]>|<ul>.*?<\/ul>|<ol>.*?<\/ol>|<li[^>]*>.*?<\/li>|<hr>|<blockquote>.*?<\/blockquote>|<table>.*?<\/table>)|\n/s', function($m) { return isset($m[1]) && !empty($m[1]) ? $m[1] : '<br>'; }, $processedThoughtContent);
        $thinkPlaceholders[$placeholderKey] = '<details class="ai-thought" open><summary>AI\'s thought process</summary><div class="thought-content">' . $processedThoughtContent . '</div></details>';
        return $placeholderKey;
    }, $text);
    $mainContentProcessed = processInnerMarkdown($text);
    if (!empty($thinkPlaceholders)) { krsort($thinkPlaceholders); foreach ($thinkPlaceholders as $key => $value) { $mainContentProcessed = str_replace($key, $value, $mainContentProcessed); } }
    $mainContentProcessed = preg_replace_callback('/<pre>[\s\S]*?<\/pre>/', function($match) use (&$preBlockPlaceholders, &$preBlockPlaceholderId, $preBlockPlaceholderPrefix, $thinkPlaceholderSuffix){ $placeholderKey = $preBlockPlaceholderPrefix . $preBlockPlaceholderId++ . $thinkPlaceholderSuffix; $preBlockPlaceholders[$placeholderKey] = $match[0]; return $placeholderKey; }, $mainContentProcessed);
    $lines = explode("\n", $mainContentProcessed); $finalHtml = ''; $paragraphBuffer = ''; $blockTagRegex = '/^\s*<(h[1-6]|ul|ol|li|blockquote|hr|details|p|img|a|table|thead|tbody|tr|th|td)(?:>|[\s>])/i';
    $flushParagraphBufferPHP = function() use (&$paragraphBuffer, &$finalHtml) { $trimmedBuffer = trim($paragraphBuffer); if ($trimmedBuffer !== '') { $finalHtml .= '<p>' . recursivelyProcessInlineMarkdownPHP($trimmedBuffer) . "</p>\n"; } $paragraphBuffer = ''; };
    foreach ($lines as $currentLine) {
        $trimmedLine = trim($currentLine);
        if (strpos($trimmedLine, $preBlockPlaceholderPrefix) === 0) { $flushParagraphBufferPHP(); $finalHtml .= $currentLine . "\n"; }
        elseif ($trimmedLine === '') { $flushParagraphBufferPHP(); }
        elseif (preg_match($blockTagRegex, $trimmedLine)) { $flushParagraphBufferPHP(); $finalHtml .= $currentLine . "\n"; }
        else { $paragraphBuffer .= ($paragraphBuffer !== '' ? "\n" : "") . $currentLine; }
    }
    $flushParagraphBufferPHP();
    if (!empty($preBlockPlaceholders)) { krsort($preBlockPlaceholders); foreach ($preBlockPlaceholders as $key => $value) { $finalHtml = str_replace($key, $value, $finalHtml); } }
    $finalHtml = trim($finalHtml); $finalHtml = preg_replace('/<p>\s*<\/p>/i', '', $finalHtml); $finalHtml = preg_replace('/(<br\s*\/?>\s*){2,}/i', "<br>\n", $finalHtml);
    return $finalHtml;
}
// --- END OF MARKDOWN PROCESSING FUNCTIONS ---

function generateUniqueId() { return str_replace('.', '-', uniqid('msg_', true)); }

// --- API CALL FUNCTION ---
function callApi($conversationHistory, $settings, $requestId = null) {
    $apiKey = $settings['key']; $apiUrl = $settings['url']; $model = $settings['model'];
    $customSystemPrompt = $settings['prompt']; $maxTokens = $settings['max_tokens'] ?? null; $temperature = $settings['temperature'] ?? null;

    if (empty($apiKey) || empty($apiUrl) || empty($model) || $apiKey === 'YOUR_API_KEY_HERE' || $apiUrl === 'YOUR_API_ENDPOINT_HERE') {
        return ['success' => false, 'error' => 'API settings are missing or incomplete. Please configure them in Settings.'];
    }
    $apiMessages = []; if (!empty($customSystemPrompt)) { $apiMessages[] = ['role' => 'system', 'content' => $customSystemPrompt]; }
    foreach ($conversationHistory as $msg) {
        $messageContent = '';
        if (isset($msg['all_contents'], $msg['current_version_index']) && isset($msg['all_contents'][$msg['current_version_index']])) { $messageContent = $msg['all_contents'][$msg['current_version_index']]; }
        elseif (isset($msg['content'])) { $messageContent = $msg['content']; }
        if (!empty($messageContent)) { $apiMessages[] = ['role' => $msg['role'], 'content' => $messageContent]; }
    }
    $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey];
    $data = ['model' => $model, 'messages' => $apiMessages];
    if ($temperature !== null && is_numeric($temperature) && $temperature >= 0.0 && $temperature <= 2.0) { $data['temperature'] = (float)$temperature; }
    if ($maxTokens !== null && is_numeric($maxTokens) && $maxTokens >= 1) { $data['max_tokens'] = (int)$maxTokens; }

    $mh = curl_multi_init(); $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [ CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($data), CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 10 ]);
    curl_multi_add_handle($mh, $ch);
    $active = null; $startTime = time(); $maxExecutionTime = 55;
    do {
        $status = curl_multi_exec($mh, $active); if ($active) { curl_multi_select($mh, 0.1); }
        session_write_close(); usleep(50000); session_start();
        if ($requestId && isset($_SESSION['cancelled_requests'][$requestId]) && $_SESSION['cancelled_requests'][$requestId]) {
            curl_multi_remove_handle($mh, $ch); curl_close($ch); curl_multi_close($mh); unset($_SESSION['cancelled_requests'][$requestId]);
            return ['success' => false, 'status' => 'cancelled', 'error' => 'Request cancelled by user.'];
        }
        if (time() - $startTime > $maxExecutionTime) {
            curl_multi_remove_handle($mh, $ch); curl_close($ch); curl_multi_close($mh); if($requestId) unset($_SESSION['cancelled_requests'][$requestId]);
            return ['success' => false, 'error' => 'API request timed out on server.'];
        }
    } while ($active && $status == CURLM_OK);
    $response = null; $http_code = 0; $curl_error = '';
    if ($status != CURLM_OK) { $curl_error = curl_multi_strerror($status); }
    else {
        $info = curl_multi_info_read($mh);
        if ($info && $info['result'] !== CURLE_OK) { $curl_error = curl_error($info['handle']); $http_code = curl_getinfo($info['handle'], CURLINFO_HTTP_CODE); }
        else if ($info) { $response = curl_multi_getcontent($info['handle']); $http_code = curl_getinfo($info['handle'], CURLINFO_HTTP_CODE); }
    }
    curl_multi_remove_handle($mh, $ch); curl_close($ch); curl_multi_close($mh); if($requestId) unset($_SESSION['cancelled_requests'][$requestId]);
    if ($response === null && !empty($curl_error)) { return ['success' => false, 'error' => "API call failed: " . $curl_error]; }
    if ($response === null && $http_code === 0 && empty($curl_error)) { return ['success' => false, 'error' => "API call failed: No response and no specific cURL error."]; }
    $response_data = json_decode($response, true);
    if ($http_code == 200 && json_last_error() === JSON_ERROR_NONE) {
        if (isset($response_data['choices'][0]['message']['content'])) { return ['success' => true, 'content' => $response_data['choices'][0]['message']['content']]; }
        elseif (isset($response_data['content'][0]['text'])) { return ['success' => true, 'content' => $response_data['content'][0]['text']]; }
        else { return ['success' => false, 'error' => "Unexpected API response structure."]; }
    } else {
        $errorMsg = "API request failed (HTTP $http_code).";
        if (!empty($curl_error)) $errorMsg .= " cURL: $curl_error.";
        if ($response_data && isset($response_data['error']['message'])) $errorMsg .= " API: " . $response_data['error']['message'];
        elseif ($response_data && isset($response_data['error']['type'])) $errorMsg .= " API Type: " . $response_data['error']['type'];
        else if (!empty($response)) $errorMsg .= " Raw: " . substr($response, 0, 100) . "...";
        return ['success' => false, 'error' => $errorMsg];
    }
}

// --- POST REQUEST HANDLING ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // CSRF check for most actions (excluding settings and import, which are checked specifically below)
    if (!in_array($_POST['action'], ['saveApiSettings', 'saveAdvancedSettings', 'import_session'])) {
        header('Content-Type: application/json');
         if (!isset($_POST['csrf_token']) || !validate_csrf_token($_POST['csrf_token'])) {
            echo json_encode(['success' => false, 'error' => 'CSRF token validation failed for action.']);
            exit;
        }
    }

    $action = $_POST['action'];
    $response_data = ['success' => false, 'error' => 'Invalid action or CSRF token missing for this action type.']; // Default response
    $requestId = $_POST['requestId'] ?? null;

    // Specific CSRF check for settings and import actions
    if (in_array($action, ['saveApiSettings', 'saveAdvancedSettings', 'import_session'])) {
        if (!isset($_POST['csrf_token']) || !validate_csrf_token($_POST['csrf_token'])) {
            if ($action !== 'import_session' || !isset($_FILES['session_file'])) { // Avoid double header for file uploads
                 header('Content-Type: application/json');
            }
            echo json_encode(['success' => false, 'error' => 'CSRF token validation failed for settings/import.']);
            exit;
        }
    }

    switch ($action) {
        case 'saveApiSettings': // ADDED
            $_SESSION['api_settings']['model'] = trim($_POST['api_model'] ?? '');
            $_SESSION['api_settings']['url'] = trim($_POST['api_url'] ?? '');
            $_SESSION['api_settings']['key'] = trim($_POST['api_key'] ?? '');
            $_SESSION['api_settings']['prompt'] = trim($_POST['api_prompt'] ?? 'You are a helpful assistant.');
            $max_tokens_input = trim($_POST['api_max_tokens'] ?? '');
            $_SESSION['api_settings']['max_tokens'] = ($max_tokens_input === '' || !is_numeric($max_tokens_input) || (int)$max_tokens_input < 1) ? null : (int)$max_tokens_input;
            $temperature_input = trim($_POST['api_temperature'] ?? '');
            $_SESSION['api_settings']['temperature'] = ($temperature_input === '' || !is_numeric($temperature_input) || (float)$temperature_input < 0.0 || (float)$temperature_input > 2.0) ? null : (float)$temperature_input;
            $response_data = ['success' => true, 'message' => 'API settings saved.'];
            break;

        case 'saveAdvancedSettings': // ADDED
            $_SESSION['advanced_settings']['enable_raw_reply_view'] = isset($_POST['enable_raw_reply_view']);
            $_SESSION['advanced_settings']['enable_ai_response_edit'] = isset($_POST['enable_ai_response_edit']);
            $response_data = ['success' => true, 'message' => 'Advanced settings saved.', 'settings' => $_SESSION['advanced_settings']];
            break;

        case 'import_session': // ADDED
            if (isset($_FILES['session_file']) && $_FILES['session_file']['error'] === UPLOAD_ERR_OK) {
                $fileContent = file_get_contents($_FILES['session_file']['tmp_name']);
                $decodedSession = json_decode($fileContent, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decodedSession)) {
                    if (isset($decodedSession['conversation'])) $_SESSION['conversation'] = $decodedSession['conversation'];
                    if (isset($decodedSession['api_settings'])) {
                        $imported_api = $decodedSession['api_settings'];
                        $_SESSION['api_settings']['model'] = $imported_api['model'] ?? $_SESSION['api_settings']['model'];
                        $_SESSION['api_settings']['url'] = $imported_api['url'] ?? $_SESSION['api_settings']['url'];
                        $_SESSION['api_settings']['prompt'] = $imported_api['prompt'] ?? $_SESSION['api_settings']['prompt'];
                        $_SESSION['api_settings']['max_tokens'] = $imported_api['max_tokens'] ?? $_SESSION['api_settings']['max_tokens'];
                        $_SESSION['api_settings']['temperature'] = $imported_api['temperature'] ?? $_SESSION['api_settings']['temperature'];
                        // API Key is NOT imported for security.
                    }
                    if (isset($decodedSession['advanced_settings'])) $_SESSION['advanced_settings'] = $decodedSession['advanced_settings'];
                    if (isset($decodedSession['text_streaming_enabled'])) $_SESSION['text_streaming_enabled'] = $decodedSession['text_streaming_enabled'];
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); // Regenerate CSRF token
                    $response_data = ['success' => true, 'message' => 'Session imported successfully. Page will reload.'];
                } else { $response_data = ['success' => false, 'error' => 'Invalid session file format: ' . json_last_error_msg()]; }
            } else { $response_data = ['success' => false, 'error' => 'File upload error: ' . ($_FILES['session_file']['error'] ?? 'N/A')]; }
            break;

        case 'cancelApiRequest':
            $reqIdToCancel = $_POST['requestId'] ?? null;
            if ($reqIdToCancel) { $_SESSION['cancelled_requests'][$reqIdToCancel] = true; $response_data = ['success' => true, 'message' => "Request $reqIdToCancel cancellation initiated."]; }
            else { $response_data['error'] = 'No request ID provided for cancellation.'; }
            break;

        case 'editAndRegenerate':
            $messageId = trim($_POST['messageId'] ?? ''); $newContent = trim($_POST['newContent'] ?? '');
            if (empty($messageId) || $newContent === '') { $response_data['error'] = 'Invalid Message ID or empty content.'; break; }
            $userMessageIndex = -1;
            foreach ($_SESSION['conversation'] as $key => $message) { if (isset($message['id']) && $message['id'] === $messageId && $message['role'] === 'user') { $userMessageIndex = $key; break; } }
            if ($userMessageIndex !== -1) {
                $_SESSION['conversation'][$userMessageIndex]['all_contents'] = [$newContent]; $_SESSION['conversation'][$userMessageIndex]['current_version_index'] = 0;
                array_splice($_SESSION['conversation'], $userMessageIndex + 1);
                $api_response = callApi($_SESSION['conversation'], $_SESSION['api_settings'], $requestId);
                if ($api_response['success']) {
                    $new_ai_message_id = generateUniqueId();
                    if (!isset($api_response['status']) || $api_response['status'] !== 'cancelled') { $_SESSION['conversation'][] = ['id' => $new_ai_message_id, 'role' => 'assistant', 'all_contents' => [$api_response['content']], 'current_version_index' => 0]; }
                    $response_data = ['success' => true, 'id' => $messageId, 'newContent' => $newContent, 'newAIMessageContent' => $api_response['content'] ?? '', 'newAIMessageId' => $new_ai_message_id, 'status' => $api_response['status'] ?? 'completed'];
                } else { $response_data['error'] = $api_response['error']; $response_data['status'] = $api_response['status'] ?? 'error'; }
            } else { $response_data['error'] = 'User message not found or not editable.'; }
            break;

        case 'deleteMessagesFromId':
            $messageIdToDeleteFrom = trim($_POST['messageId'] ?? '');
            if (empty($messageIdToDeleteFrom)) { $response_data['error'] = 'Invalid Message ID for deletion.'; break;}
            $foundIndex = -1;
            foreach ($_SESSION['conversation'] as $key => $message) { if (isset($message['id']) && $message['id'] === $messageIdToDeleteFrom) { $foundIndex = $key; break; } }
            if ($foundIndex !== -1) { array_splice($_SESSION['conversation'], $foundIndex); $response_data = ['success' => true, 'deletedFromIndex' => $foundIndex]; }
            else { $response_data['error'] = 'Message not found for deletion.'; }
            break;

        case 'regenerateReply':
            $messageIdToRegenerate = trim($_POST['messageId'] ?? '');
            if (empty($messageIdToRegenerate)) { $response_data['error'] = 'Invalid Message ID for regeneration.'; break;}
            $messageKeyToRegenerate = null;
            foreach($_SESSION['conversation'] as $key => $msg) { if (isset($msg['id']) && $msg['id'] === $messageIdToRegenerate && $msg['role'] === 'assistant') { $messageKeyToRegenerate = $key; break; } }
            if ($messageKeyToRegenerate !== null) {
                $tempConversation = array_slice($_SESSION['conversation'], 0, $messageKeyToRegenerate);
                $api_response = callApi($tempConversation, $_SESSION['api_settings'], $requestId);
                if ($api_response['success']) {
                    if (!isset($api_response['status']) || $api_response['status'] !== 'cancelled') {
                        if (!isset($_SESSION['conversation'][$messageKeyToRegenerate]['all_contents']) || !is_array($_SESSION['conversation'][$messageKeyToRegenerate]['all_contents'])) { $_SESSION['conversation'][$messageKeyToRegenerate]['all_contents'] = []; }
                        $_SESSION['conversation'][$messageKeyToRegenerate]['all_contents'][] = $api_response['content'];
                        $_SESSION['conversation'][$messageKeyToRegenerate]['current_version_index'] = count($_SESSION['conversation'][$messageKeyToRegenerate]['all_contents']) - 1;
                    }
                    $response_data = ['success' => true, 'newContent' => $api_response['content'] ?? '', 'messageId' => $messageIdToRegenerate, 'currentVersionIndex' => $_SESSION['conversation'][$messageKeyToRegenerate]['current_version_index'] ?? 0, 'totalVersions' => count($_SESSION['conversation'][$messageKeyToRegenerate]['all_contents'] ?? []), 'status' => $api_response['status'] ?? 'completed'];
                } else { $response_data = ['error' => $api_response['error'], 'status' => $api_response['status'] ?? 'error', 'messageId' => $messageIdToRegenerate, 'currentVersionIndex' => $_SESSION['conversation'][$messageKeyToRegenerate]['current_version_index'] ?? 0, 'totalVersions' => count($_SESSION['conversation'][$messageKeyToRegenerate]['all_contents'] ?? [])]; }
            } else { $response_data['error'] = 'Cannot regenerate. AI message not found.'; }
            break;

        case 'updateVersionIndex':
            $messageId = trim($_POST['messageId'] ?? ''); $newVersionIndex = filter_var($_POST['newVersionIndex'] ?? null, FILTER_VALIDATE_INT);
            if (empty($messageId) || $newVersionIndex === false || $newVersionIndex === null || $newVersionIndex < 0) { $response_data['error'] = 'Invalid Message ID or version index.'; break; }
            $found = false;
            foreach ($_SESSION['conversation'] as $key => &$message) {
                if (isset($message['id'], $message['all_contents']) && is_array($message['all_contents']) && $message['id'] === $messageId && $message['role'] === 'assistant' && $newVersionIndex < count($message['all_contents'])) {
                    $message['current_version_index'] = $newVersionIndex;
                    $response_data = ['success' => true, 'currentVersionIndex' => $newVersionIndex, 'totalVersions' => count($message['all_contents']), 'contentForVersion' => $message['all_contents'][$newVersionIndex]];
                    $found = true; break;
                }
            } unset($message);
            if (!$found) $response_data['error'] = 'Message or version not found for update.';
            break;

        case 'saveEditedAIReply':
            $messageId = trim($_POST['messageId'] ?? ''); $newRawContent = $_POST['newRawContent'] ?? '';
            if (empty($messageId)) { $response_data['error'] = 'Invalid Message ID for saving AI reply.'; break;}
            $edited = false;
            foreach ($_SESSION['conversation'] as $key => &$message) {
                if (isset($message['id']) && $message['id'] === $messageId && $message['role'] === 'assistant') {
                    $message['all_contents'] = [$newRawContent]; $message['current_version_index'] = 0; $edited = true;
                    $response_data = ['success' => true, 'message' => 'AI reply updated.', 'messageId' => $messageId, 'newRawContent' => $newRawContent, 'currentVersionIndex' => 0, 'totalVersions' => 1];
                    break;
                }
            } unset($message);
            if (!$edited) $response_data['error'] = 'AI message not found or could not be edited.';
            break;

        default:
             $response_data['error'] = 'Unknown action: ' . htmlspecialchars($action);
             break;
    }
    if (!headers_sent()) { header('Content-Type: application/json'); }
    echo json_encode($response_data);
    exit;
}

// --- POST NEW MESSAGE HANDLING (NO 'action' PARAM) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
    header('Content-Type: application/json');
    if (!isset($_POST['csrf_token']) || !validate_csrf_token($_POST['csrf_token'])) {
        echo json_encode(['success' => false, 'error' => 'CSRF token validation failed for new message.']);
        exit;
    }
    $user_message_content = trim($_POST['message'] ?? ''); $requestId = $_POST['requestId'] ?? null;
    $response_data = ['success' => false, 'error' => 'An unknown error occurred with new message.'];
    if (!empty($user_message_content)) {
        $user_message_id = generateUniqueId();
        $_SESSION['conversation'][] = ['id' => $user_message_id, 'role' => 'user', 'all_contents' => [$user_message_content], 'current_version_index' => 0];
        $api_response = callApi($_SESSION['conversation'], $_SESSION['api_settings'], $requestId);
        if ($api_response['success']) {
            $ai_message_id = generateUniqueId();
            if (!isset($api_response['status']) || $api_response['status'] !== 'cancelled') { $_SESSION['conversation'][] = ['id' => $ai_message_id, 'role' => 'assistant', 'all_contents' => [$api_response['content']], 'current_version_index' => 0]; }
            $response_data = ['success' => true, 'userMessageId' => $user_message_id, 'userMessageContent' => $user_message_content, 'newAIMessageId' => $ai_message_id, 'newAIMessageContent' => $api_response['content'] ?? '', 'status' => $api_response['status'] ?? 'completed'];
        } else { $response_data = ['success' => false, 'error' => $api_response['error'], 'userMessageId' => $user_message_id, 'userMessageContent' => $user_message_content, 'status' => $api_response['status'] ?? 'error']; }
    } else { $response_data['error'] = 'Message cannot be empty.'; }
    echo json_encode($response_data);
    exit;
}

// --- GET REQUEST HANDLING ---
if (isset($_GET['clear'])) { // Clear session
    $_SESSION['conversation'] = []; $_SESSION['cancelled_requests'] = [];
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); // Regenerate CSRF token
    header('Location: ' . strtok($_SERVER["REQUEST_URI"], '?'));
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'export_session') { // ADDED Export session
    if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); } // Ensure session is active
    $sessionDataToExport = [
        'conversation' => $_SESSION['conversation'] ?? [],
        'api_settings' => [
            'model'       => $_SESSION['api_settings']['model'] ?? '',
            'url'         => $_SESSION['api_settings']['url'] ?? '',
            'prompt'      => $_SESSION['api_settings']['prompt'] ?? '',
            'max_tokens'  => $_SESSION['api_settings']['max_tokens'] ?? null,
            'temperature' => $_SESSION['api_settings']['temperature'] ?? null,
        ], // API key is intentionally excluded
        'advanced_settings' => $_SESSION['advanced_settings'] ?? [],
        'text_streaming_enabled' => get_text_streaming_setting(),
    ];
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="ai_playground_session_'.date('Y-m-d_H-i-s').'.json"');
    echo json_encode($sessionDataToExport, JSON_PRETTY_PRINT);
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Playground</title>
    <link rel="icon" type="image/x-icon" href="ai-playground.ico">
    <link rel="stylesheet" href="css/styles.css?v=<?= @filemtime('css/styles.css') ?>">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
    <script>
        window.advancedSettings = <?= json_encode($_SESSION['advanced_settings'] ?? ['enable_raw_reply_view' => false, 'enable_ai_response_edit' => false]) ?>;
        window.textStreamingEnabled = <?= json_encode(get_text_streaming_setting()) ?>;
        window.csrfToken = "<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>";
    </script>
</head>
<body>
    <div class="container">
        <h1 class="title">AI Playground</h1>
        <small class="subtitle">Play with any AI API</small>
        <div class="chat-box" id="chatBox">
            <div class="chatbox-control">
                <div class="settings-dropdown">
                    <a href="#" id="clearHistoryLink" class="control-btn" style="color: #ba7a7a;">Clear History</a>
                    <button class="settings-button control-btn">Settings</button>
                    <div class="settings-content" id="settingsDropdown">
                        <label>
                            <input type="checkbox" id="textStreamingToggle" <?= get_text_streaming_setting() ? 'checked' : '' ?>>
                            Enable Text Streaming
                        </label>
                        <a href="#" id="apiSettingsButton" class="settings-link">API Settings</a>
                        <a href="#" id="advancedSettingsButton" class="settings-link">Advanced Settings</a>
                        <a href="#" id="sessionManagerButton" class="settings-link">Import/Export Session</a>
                        <hr style="margin: 5px 0;">
                        <a href="https://github.com/lovechan404/ai-playground" class="settings-link" target="_blank" > View on Github </a>
                    </div>
                </div>
            </div>
            <?php if (empty($_SESSION['conversation'])): ?>
                <div class="project-info">
                    <h2>Welcome to AI Playground!</h2>
                    <p>AI Playground is lightweight, self-hostable AI chat interface designed for quick experimentation with various AI APIs. No logins, no persistence—just a clean, temporary sandbox where you can test prompts, tweak AI settings, and interact with models in real time.</p>
                    <p>This is an open-source project by <strong>love-chan</strong>. <a href="https://github.com/lovechan404/ai-playground" target="_blank" rel="noopener noreferrer" class="github-link">View on GitHub</a>
                    </p>
                    <hr>
                    <h4>How to Use with Different APIs:</h4>
                    <ol>
                        <li>Go to <strong>Settings > API Settings</strong>.</li>
                        <li>
                            <strong>Model:</strong> Enter the specific model name for your chosen API.
                            <ul>
                                <li><em>Example (OpenAI):</em> <code>gpt-4o</code>, <code>gpt-3.5-turbo</code></li>
                                <li><em>Example (Anthropic Claude):</em> <code>claude-3-opus-20240229</code></li>
                                <li><em>Example (DeepSeek):</em> <code>deepseek-chat</code> or <code>deepseek-coder</code></li>
                                <li><em>Example (Groq):</em> <code>llama3-8b-8192</code>, <code>mixtral-8x7b-32768</code></li>
                            </ul>
                        </li>
                        <li>
                            <strong>API/Proxy URL:</strong> This is the chat completions endpoint.
                            <ul>
                                <li><em>OpenAI:</em> <code>https://api.openai.com/v1/chat/completions</code></li>
                                <li><em>Anthropic Claude:</em> <code>https://api.anthropic.com/v1/messages</code> <br>(Note: Full Claude API compatibility may require adjustments or a proxy.)</li>
                                <li><em>DeepSeek:</em> <code>https://api.deepseek.com/chat/completions</code></li>
                                <li><em>Groq:</em> <code>https://api.groq.com/openai/v1/chat/completions</code></li>
                                <li><em>Other Proxies/Self-Hosted:</em> Your specific endpoint URL.</li>
                            </ul>
                        </li>
                        <li><strong>API Key:</strong> This is a unique, private key that authenticates your access to the chosen AI service. It acts as a password that allows your requests to be processed securely. Be sure to keep it confidential, as sharing it may lead to unauthorized usage or additional costs.</li>
                        <li><strong>Custom System Prompt (Optional):</strong> This defines how the AI should behave and what it should remember. You can set instructions to guide its tone, response style, and even specific details you'd like it to retain throughout the interaction.</li>
                        <li><strong>Max Tokens (Optional):</strong> Controls response length. Each provider may have different limits. Always check the maximum token allowance of your chosen API before use.</li>
                        <li><strong>Temperature:</strong> Adjusts randomness. A low value (e.g., 0.0) makes responses more predictable, while a higher value (e.g., 2.0) leads to more diverse and creative answers.</li>
                    </ol>
                    <h4>Disclaimer</h4>
                    <p>
                        <strong>AI Playground</strong> interacts with third-party APIs, which are entirely managed and controlled by their respective providers. The usage, functionality, and availability of these APIs are subject to their own terms and conditions. The developer of this project does not control, endorse, or take responsibility for any issues, limitations, or consequences arising from API usage.
                    </p>
                    <p>
                        <strong>Users are solely responsible for configuring and using API keys properly.</strong> Make sure to
                        review the terms of any API provider you integrate with!
                    </p>
                    <p><strong>For those new to AI APIs:</strong> Some models charge per token, so please review the pricing details of your chosen provider beforehand. </p>
                    <h4>Where to Get APIs</h4>
                    <ul>
                        <li><strong>OpenAI:</strong> <a href="https://platform.openai.com/" target="_blank" >Get API Key</a></li>
                        <li><strong>Anthropic Claude:</strong> <a href="https://console.anthropic.com/settings/keys" target="_blank" >Get API Key</a></li>
                        <li><strong>DeepSeek:</strong> <a href="https://platform.deepseek.com/api_keys" target="_blank" >Get API Key</a></li>
                        <li><strong>Groq:</strong> <a href="https://console.groq.com/keys" target="_blank" >Get API Key</a></li>
                    </ul>
                    <h4>Donate</h4>
                    <p>
                        ✨ This project is totally free, but if you’d like to sprinkle some magic ✨ and help me keep it running, I’d be super grateful!
                        <a href="https://donate.lovechan.cc/" target="_blank">Click here to support.</a>
                    </p>
                    <p>Start by typing your first message below! 🐸</p>
                </div>
            <?php endif; ?>
            <?php
            // --- MESSAGE RENDERING LOOP ---
            foreach ($_SESSION['conversation'] as $index => $message):
                if (!isset($message['id'])) { $_SESSION['conversation'][$index]['id'] = generateUniqueId(); $message['id'] = $_SESSION['conversation'][$index]['id']; }
                $displayContent = '';
                if (!isset($message['all_contents']) || !is_array($message['all_contents'])) { $content_fallback = $message['content'] ?? ($message['role'] === 'user' ? 'Error: User content missing' : 'Error: AI content missing'); $_SESSION['conversation'][$index]['all_contents'] = [$content_fallback]; $message['all_contents'] = [$content_fallback]; }
                if (!isset($message['current_version_index']) || !isset($message['all_contents'][$message['current_version_index']])) { $_SESSION['conversation'][$index]['current_version_index'] = max(0, count($message['all_contents']) - 1); $message['current_version_index'] = $_SESSION['conversation'][$index]['current_version_index']; }
                $displayContent = $message['all_contents'][$message['current_version_index']] ?? 'Error: Content unavailable';
            ?>
                <div class="message <?= htmlspecialchars($message['role']) ?>"
                     data-message-id="<?= htmlspecialchars($message['id']) ?>"
                     data-current-version-index="<?= htmlspecialchars($message['current_version_index']) ?>"
                     data-total-versions="<?= htmlspecialchars(count($message['all_contents'])) ?>"
                     data-all-contents="<?= htmlspecialchars(json_encode($message['all_contents']), ENT_QUOTES, 'UTF-8') ?>"
                     >
                    <div class="message-content" id="message-content-<?= htmlspecialchars($message['id']) ?>">
                        <?= ($message['role'] === 'user') ? nl2br(htmlspecialchars($displayContent)) : markdownToHtml($displayContent) ?>
                    </div>
                    <div class="message-actions">
                        </div>
                </div>
            <?php endforeach; ?>
        </div>
        <form method="post" id="chatForm">
             <div class="input-area">
                <textarea name="message" id="messageInput" rows="1" placeholder="Type your message..."></textarea>
                <button type="submit" id="sendButton" class="send-btn">Send</button>
            </div>
        </form>
    </div>

    <div id="apiSettingsModal" class="modal">
        <div class="modal-content api-settings-modal-content">
            <span class="close-button" id="closeApiSettingsModal">&times;</span>
            <h2>API Settings</h2>
            <form id="apiSettingsForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                <div class="form-group">
                    <label for="api_model">Model:</label>
                    <input type="text" id="api_model" name="api_model" value="<?= htmlspecialchars($_SESSION['api_settings']['model'] ?? '') ?>" placeholder="e.g., gpt-4o">
                </div>
                <div class="form-group">
                    <label for="api_url">API/Proxy URL (Chat Completions):</label>
                    <input type="url" id="api_url" name="api_url" value="<?= htmlspecialchars($_SESSION['api_settings']['url'] ?? '') ?>" placeholder="e.g., https://api.openai.com/v1/chat/completions">
                </div>
                <div class="form-group">
                    <label for="api_key">API Key:</label>
                    <input type="password" id="api_key" name="api_key" value="<?= htmlspecialchars($_SESSION['api_settings']['key'] ?? '') ?>" placeholder="Enter your API key">
                </div>
                <div class="form-group">
                    <label for="api_prompt">Custom System Prompt:</label>
                    <textarea id="api_prompt" name="api_prompt" placeholder="e.g., You are a helpful assistant."><?= htmlspecialchars($_SESSION['api_settings']['prompt'] ?? '') ?></textarea>
                </div>
                <div class="form-group">
                    <label for="api_max_tokens">Max Tokens (Optional):</label>
                    <input type="number" id="api_max_tokens" name="api_max_tokens" value="<?= htmlspecialchars((string)($_SESSION['api_settings']['max_tokens'] ?? '')) ?>" placeholder="e.g., 1000 (API default if empty)" min="1">
                </div>
                <div class="form-group">
                    <label for="api_temperature">Temperature (0.0-2.0): <span id="temperatureValueDisplay"><?= htmlspecialchars(number_format((float)($_SESSION['api_settings']['temperature'] ?? 0.7), 2)) ?></span></label>
                    <input type="range" id="api_temperature" name="api_temperature" value="<?= htmlspecialchars((string)($_SESSION['api_settings']['temperature'] ?? 0.7)) ?>" min="0" max="2" step="0.01" class="temperature-slider">
                </div>
                <small class="api-note">Note: Max Tokens & Temperature behavior depends on the API provider. Leave empty to use provider defaults. Always check your API provider's documentation for supported ranges and defaults.</small>
                <div class="form-actions">
                    <button type="submit" id="saveApiSettingsButton" class="primary-btn action-btn">Save Settings</button>
                </div>
            </form>
            <div id="apiSettingsStatus" class="status-message" style="display: none;"></div>
        </div>
    </div>

    <div id="advancedSettingsModal" class="modal">
        <div class="modal-content advanced-settings-modal-content">
            <span class="close-button" id="closeAdvancedSettingsModal">&times;</span>
            <h2>Advanced Settings</h2>
            <form id="advancedSettingsForm">
                 <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                 <p class="settings-note">This is for debugging or experimental purposes. Features enabled here might affect performance or user experience.</p>
                <div class="form-group">
                    <label for="enable_raw_reply_view">
                        <input type="checkbox" id="enable_raw_reply_view" name="enable_raw_reply_view" <?= ($_SESSION['advanced_settings']['enable_raw_reply_view'] ?? false) ? 'checked' : '' ?>>
                        Enable View Raw AI Reply Button
                    </label>
                    <small>Adds a button to see the raw, unprocessed response from the AI.</small>
                </div>
                <div class="form-group">
                    <label for="enable_ai_response_edit">
                        <input type="checkbox" id="enable_ai_response_edit" name="enable_ai_response_edit" <?= ($_SESSION['advanced_settings']['enable_ai_response_edit'] ?? false) ? 'checked' : '' ?>>
                        Enable AI Response Edit Mode
                    </label>
                    <small>Adds an "Edit" button to AI messages, allowing direct modification of the AI's response.</small>
                </div>
                <div class="form-actions">
                    <button type="submit" id="saveAdvancedSettingsButton" class="primary-btn action-btn">Save Advanced Settings</button>
                </div>
            </form>
            <div id="advancedSettingsStatus" class="status-message" style="display: none;"></div>
        </div>
    </div>

    <div id="viewRawReplyModal" class="modal">
        <div class="modal-content view-raw-reply-modal-content">
            <span class="close-button" id="closeViewRawReplyModal">&times;</span>
            <h2>Raw AI Response</h2>
            <textarea id="rawReplyTextarea" readonly rows="10" style="width: 100%; box-sizing: border-box; min-height: 150px;"></textarea>
        </div>
    </div>

    <div id="sessionManagerModal" class="modal">
        <div class="modal-content api-settings-modal-content"> <span class="close-button" id="closeSessionManagerModal">&times;</span>
            <h2>Import/Export Session</h2>
            <div class="form-group">
                <button type="button" id="exportSessionButton" class="action-btn primary-btn" style="width:100%;">Export Current Session</button>
                <small>Download a JSON file of your current chat history and settings (API key will not be included).</small>
            </div>
            <hr>
            <form id="importSessionForm" style="display: contents;"> <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                 <input type="hidden" name="action" value="import_session"> <div class="form-group">
                    <label for="importSessionFile" class="action-btn" style="width:100%; text-align:center; box-sizing: border-box; display: inline-block;">Import Session from File</label>
                    <input type="file" id="importSessionFile" name="session_file" accept=".json" style="display:none;">
                     <small>Upload a previously exported JSON session file. This will replace your current session. You may need to re-enter your API key in API Settings after import.</small>
                </div>
            </form>
            <div id="importSessionStatus" class="status-message" style="display: none;"></div>
        </div>
    </div>

    <script src="js/ai_playground_utils.js?v=<?= @filemtime('js/ai_playground_utils.js') ?>"></script>
    <script src="js/ai_playground_ui.js?v=<?= @filemtime('js/ai_playground_ui.js') ?>"></script>
    <script src="js/ai_playground_chat.js?v=<?= @filemtime('js/ai_playground_chat.js') ?>"></script>
    <script src="js/ai_playground_session_manager.js?v=<?= @filemtime('js/ai_playground_session_manager.js') ?>"></script>
</body>
</html>
