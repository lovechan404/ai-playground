<?php

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

require_once 'includes/settings.php'; 

if (!isset($_SESSION['conversation'])) {
    $_SESSION['conversation'] = [];
}
if (!isset($_SESSION['cancelled_requests'])) {
    $_SESSION['cancelled_requests'] = [];
}

if (!isset($_SESSION['api_settings'])) {
    $_SESSION['api_settings'] = [
        'model' => '', 
        'url'   => '', 
        'key'   => '',    
        'prompt'=> "You are a helpful assistant.", 
        'max_tokens' => 1000, 
        'temperature' => 0.7   
    ];
} else { 
    if (!isset($_SESSION['api_settings']['max_tokens'])) {
        $_SESSION['api_settings']['max_tokens'] = 1000;
    }
    if (!isset($_SESSION['api_settings']['temperature'])) {
        $_SESSION['api_settings']['temperature'] = 0.7;
    }
}

if (!isset($_SESSION['advanced_settings'])) {
    $_SESSION['advanced_settings'] = [
        'enable_raw_reply_view' => false,
        'enable_ai_response_edit' => false,
    ];
}

if (isset($_GET['action']) && $_GET['action'] === 'export_session') {
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="ai-playground-session-' . date('Ymd-His') . '.json"');
    
    $api_settings_export = $_SESSION['api_settings'] ?? [];
    unset($api_settings_export['key']); 

    $session_data = [
        'conversation' => $_SESSION['conversation'] ?? [],
        'api_settings' => $api_settings_export,
        'advanced_settings' => $_SESSION['advanced_settings'] ?? [],
        'text_streaming_enabled' => get_text_streaming_setting() 
    ];
    
    echo json_encode($session_data, JSON_PRETTY_PRINT);
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'import_session') {
    header('Content-Type: application/json');

    if (!isset($_POST['csrf_token']) || !validate_csrf_token($_POST['csrf_token'])) {
        echo json_encode(['success' => false, 'error' => 'CSRF token validation failed.']);
        exit;
    }

    $response = ['success' => false, 'error' => 'Unknown error.'];

    if (isset($_FILES['session_file']) && $_FILES['session_file']['error'] == UPLOAD_ERR_OK) {
        $json_data = file_get_contents($_FILES['session_file']['tmp_name']);
        $imported_data = json_decode($json_data, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            $required_keys = ['conversation', 'api_settings', 'advanced_settings', 'text_streaming_enabled'];
            $valid_structure = true;
            foreach ($required_keys as $key) {
                if (!array_key_exists($key, $imported_data)) {
                    $valid_structure = false;
                    $response['error'] = "Invalid session file: Missing key '$key'.";
                    break;
                }
            }
            
            if ($valid_structure) {
                if (!is_array($imported_data['conversation'])) $valid_structure = false;
                if (!is_array($imported_data['api_settings'])) $valid_structure = false;
                if (!is_array($imported_data['advanced_settings'])) $valid_structure = false;
                if (!is_bool($imported_data['text_streaming_enabled'])) $valid_structure = false;
                
                if (isset($imported_data['api_settings']['model']) && !is_string($imported_data['api_settings']['model'])) $valid_structure = false;
                if (isset($imported_data['api_settings']['url']) && !is_string($imported_data['api_settings']['url'])) $valid_structure = false;
                if (isset($imported_data['api_settings']['prompt']) && !is_string($imported_data['api_settings']['prompt'])) $valid_structure = false;
                if (isset($imported_data['api_settings']['max_tokens']) && !is_int($imported_data['api_settings']['max_tokens'])) $valid_structure = false;
                if (isset($imported_data['api_settings']['temperature']) && !is_numeric($imported_data['api_settings']['temperature'])) $valid_structure = false;
                if (isset($imported_data['advanced_settings']['enable_raw_reply_view']) && !is_bool($imported_data['advanced_settings']['enable_raw_reply_view'])) $valid_structure = false;
                if (isset($imported_data['advanced_settings']['enable_ai_response_edit']) && !is_bool($imported_data['advanced_settings']['enable_ai_response_edit'])) $valid_structure = false;

                if (!$valid_structure) {
                     $response['error'] = 'Invalid session file: Data structure or type mismatch.';
                }
            }

            if ($valid_structure) {
                $current_api_key = $_SESSION['api_settings']['key'] ?? '';

                $_SESSION['conversation'] = $imported_data['conversation'];
                $_SESSION['api_settings'] = $imported_data['api_settings']; 
                $_SESSION['advanced_settings'] = $imported_data['advanced_settings'];
                $_SESSION['text_streaming_enabled'] = (bool)$imported_data['text_streaming_enabled'];
                $_SESSION['api_settings']['key'] = $current_api_key; 
                
                if (!isset($_SESSION['api_settings']['max_tokens'])) $_SESSION['api_settings']['max_tokens'] = 1000;
                if (!isset($_SESSION['api_settings']['temperature'])) $_SESSION['api_settings']['temperature'] = 0.7;
                if (!isset($_SESSION['advanced_settings']['enable_raw_reply_view'])) $_SESSION['advanced_settings']['enable_raw_reply_view'] = false;
                if (!isset($_SESSION['advanced_settings']['enable_ai_response_edit'])) $_SESSION['advanced_settings']['enable_ai_response_edit'] = false;

                $response = ['success' => true, 'message' => 'Session imported successfully. Page will reload.'];
            } else {
                 if (!isset($response['error'])) $response['error'] = 'Invalid session file structure or data types.';
            }
        } else {
            $response['error'] = 'Invalid JSON file: ' . json_last_error_msg();
        }
    } else {
        $response['error'] = 'No file uploaded or an error occurred during upload.';
        if (isset($_FILES['session_file']['error']) && $_FILES['session_file']['error'] != UPLOAD_ERR_OK) {
            $response['error'] .= ' Error code: ' . $_FILES['session_file']['error'];
        }
    }
    echo json_encode($response);
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'saveApiSettings') {
    header('Content-Type: application/json');
    if (!isset($_POST['csrf_token']) || !validate_csrf_token($_POST['csrf_token'])) {
        echo json_encode(['success' => false, 'error' => 'CSRF token validation failed.']);
        exit;
    }

    $newSettings = [
        'model'  => trim($_POST['api_model'] ?? $_SESSION['api_settings']['model']),
        'url'    => trim($_POST['api_url'] ?? $_SESSION['api_settings']['url']),
        'key'    => isset($_POST['api_key']) ? trim($_POST['api_key']) : ($_SESSION['api_settings']['key'] ?? ''),
        'prompt' => trim($_POST['api_prompt'] ?? $_SESSION['api_settings']['prompt']),
        'max_tokens' => filter_var(trim($_POST['api_max_tokens'] ?? $_SESSION['api_settings']['max_tokens']), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]),
        'temperature' => filter_var(trim($_POST['api_temperature'] ?? $_SESSION['api_settings']['temperature']), FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => 0.0, 'max_range' => 2.0]])
    ];

    if ($newSettings['max_tokens'] === false || $newSettings['max_tokens'] === null) { 
        $newSettings['max_tokens'] = $_SESSION['api_settings']['max_tokens'] ?? 1000;
    }
    if ($newSettings['temperature'] === false || $newSettings['temperature'] === null) { 
        $newSettings['temperature'] = $_SESSION['api_settings']['temperature'] ?? 0.7;
    }

    if (!empty($newSettings['url']) && !filter_var($newSettings['url'], FILTER_VALIDATE_URL)) { 
        echo json_encode(['success' => false, 'error' => 'Invalid API URL format.']);
        exit;
    }
    
    $_SESSION['api_settings'] = $newSettings;
    echo json_encode(['success' => true, 'message' => 'API settings saved successfully.']);
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'saveAdvancedSettings') {
    header('Content-Type: application/json');
    if (!isset($_POST['csrf_token']) || !validate_csrf_token($_POST['csrf_token'])) {
        echo json_encode(['success' => false, 'error' => 'CSRF token validation failed.']);
        exit;
    }
    $_SESSION['advanced_settings']['enable_raw_reply_view'] = isset($_POST['enable_raw_reply_view']);
    $_SESSION['advanced_settings']['enable_ai_response_edit'] = isset($_POST['enable_ai_response_edit']);
    
    echo json_encode(['success' => true, 'message' => 'Advanced settings saved.', 'settings' => $_SESSION['advanced_settings']]);
    exit;
}

function processInnerMarkdown($text) { /* ... unchanged ... */ 
    $text = preg_replace_callback('/```(\w*)\n(.*?)```/s', function($matches) {
        $language = !empty($matches[1]) ? 'language-' . htmlspecialchars($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
        $codeContent = htmlspecialchars($matches[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return '<pre><button class="copy-code-button">Copy</button><code class="' . $language . '">' . $codeContent . '</code></pre>';
    }, $text);
    $text = preg_replace_callback('/`([^`]+)`/', function($matches) {
        return '<code>' . htmlspecialchars($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</code>';
    }, $text);
    $text = preg_replace('/^---\s*$/m', '<hr>', $text);
    $text = preg_replace_callback('/^###\s*(.*?)$/m', function($matches) { return '<h3>' . htmlspecialchars($matches[1], ENT_NOQUOTES, 'UTF-8') . '</h3>'; }, $text); 
    $text = preg_replace_callback('/^\d+\.\s*(.*?)$/m', function($matches) { return '<li_ol>' . htmlspecialchars($matches[1], ENT_NOQUOTES, 'UTF-8') . '</li_ol>'; }, $text); 
    $text = preg_replace_callback('/^-\s*(.*?)$/m', function($matches) { return '<li_ul>' . htmlspecialchars($matches[1], ENT_NOQUOTES, 'UTF-8') . '</li_ul>'; }, $text);     
    $text = preg_replace_callback('/(<li_ol>.*?<\/li_ol>\s*)+/s', function($matches) { return '<ol>' . str_replace(['<li_ol>', '</li_ol>'], ['<li>', '</li>'], trim($matches[0])) . '</ol>'; }, $text);
    $text = preg_replace_callback('/(<li_ul>.*?<\/li_ul>\s*)+/s', function($matches) { return '<ul>' . str_replace(['<li_ul>', '</li_ul>'], ['<li>', '</li>'], trim($matches[0])) . '</ul>'; }, $text);
    $text = preg_replace_callback('/^>\s*(.*?)$/m', function($matches) { return '<blockquote><p>' . htmlspecialchars($matches[1], ENT_NOQUOTES, 'UTF-8') . '</p></blockquote>'; }, $text); 
    $text = preg_replace_callback('/(<blockquote>.*?<\/blockquote>\s*)+/s', function($matches) {
        $blockquotes = $matches[0];
        preg_match_all('/<p>(.*?)<\/p>/s', $blockquotes, $paragraphs);
        $combinedContent = '';
        foreach ($paragraphs[1] as $p_content) { $combinedContent .= '<p>' . $p_content . '</p>'; } 
        return '<blockquote>' . $combinedContent . '</blockquote>';
    }, $text);
    $text = preg_replace_callback('/\*{3}([\s\S]+?)\*{3}/s', function($matches) { return '<strong><em>' . htmlspecialchars($matches[1], ENT_NOQUOTES, 'UTF-8') . '</em></strong>'; }, $text);
    $text = preg_replace_callback('/\*\*\_([\s\S]+?)\_\*\*/s', function($matches) { return '<strong><em>' . htmlspecialchars($matches[1], ENT_NOQUOTES, 'UTF-8') . '</em></strong>'; }, $text); 
    $text = preg_replace_callback('/\*__([\s\S]+?)__\*/s', function($matches) { return '<strong><em>' . htmlspecialchars($matches[1], ENT_NOQUOTES, 'UTF-8') . '</em></strong>'; }, $text); 
    $text = preg_replace_callback('/\*\*(.+?)\*\*/s', function($matches) { return '<strong>' . htmlspecialchars($matches[1], ENT_NOQUOTES, 'UTF-8') . '</strong>'; }, $text); 
    $text = preg_replace_callback('/__(.+?)__/s', function($matches) { return '<strong>' . htmlspecialchars($matches[1], ENT_NOQUOTES, 'UTF-8') . '</strong>'; }, $text); 
    $text = preg_replace_callback('/\*([^\*]+?)\*/s', function($matches) { return '<em>' . htmlspecialchars($matches[1], ENT_NOQUOTES, 'UTF-8') . '</em>'; }, $text);   
    $text = preg_replace_callback('/\_([^\_]+?)\_/s', function($matches) { return '<em>' . htmlspecialchars($matches[1], ENT_NOQUOTES, 'UTF-8') . '</em>'; }, $text);   
    return $text;
}

function markdownToHtml($text) { /* ... unchanged ... */ 
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'); 
    $text = preg_replace_callback('/<think>(.*?)<\/think>/s', function($matches) {
        $thoughtContent = processInnerMarkdown($matches[1]); 
        $thoughtContent = preg_replace_callback('/(<pre>.*?<\/pre>|<h3>.*?<\/h3>|<ul>.*?<\/ul>|<ol>.*?<\/ol>|<li[^>]*>.*?<\/li>|<hr>|<blockquote>.*?<\/blockquote>)|\n/s', function($m) {
            return isset($m[1]) && !empty($m[1]) ? $m[1] : '<br>';
        }, $thoughtContent);
        return '<details class="ai-thought" open><summary>AI\'s thought process</summary><div class="thought-content">' . $thoughtContent . '</div></details>';
    }, $text);
    $text = processInnerMarkdown($text);
    $paragraphs = preg_split('/(?:\r?\n){2,}/', $text);
    $finalHtml = [];
    $blockLevelTags = ['<pre', '<h3', '<ul', '<ol', '<blockquote', '<hr', '<details', '<li'];
    foreach ($paragraphs as $paragraph) {
        $trimmedParagraph = trim($paragraph);
        if (empty($trimmedParagraph)) continue;
        $isBlockContent = false;
        foreach ($blockLevelTags as $tag) { 
            if (strpos($trimmedParagraph, $tag) === 0) { 
                $isBlockContent = true; 
                break; 
            } 
        }
        if ($isBlockContent) {
            $finalHtml[] = $trimmedParagraph; 
        } else {
            $finalHtml[] = '<p>' . preg_replace('/\r?\n/', '<br>', $trimmedParagraph) . '</p>';
        }
    }
    $resultHtml = implode("\n", $finalHtml);
    $resultHtml = preg_replace('/(<br>\s*){2,}/i', '<br>', $resultHtml);
    $resultHtml = preg_replace('/<br>\s*(<(?:h[1-6r]|ul|ol|pre|details|blockquote|div|p)\b)/i', '$1', $resultHtml); 
    $resultHtml = preg_replace('/(<\/(?:h[1-6r]|ul|ol|pre|details|blockquote|div|p|li)>)\s*<br>/i', '$1', $resultHtml);
    return $resultHtml;
}

function generateUniqueId() { /* ... unchanged ... */ return str_replace('.', '-', uniqid('msg_', true)); }

function callApi($conversationHistory, $settings, $requestId = null) { /* ... unchanged ... */ 
    $apiKey = $settings['key'];
    $apiUrl = $settings['url'];
    $model = $settings['model'];
    $customSystemPrompt = $settings['prompt'];
    $maxTokens = $settings['max_tokens'] ?? null; 
    $temperature = $settings['temperature'] ?? null; 

    if (empty($apiKey) || empty($apiUrl) || empty($model) || $apiKey === 'YOUR_API_KEY_HERE' || $apiUrl === 'YOUR_API_ENDPOINT_HERE') {
        return ['success' => false, 'error' => 'API settings are missing or incomplete. Please configure them in Settings.'];
    }

    $apiMessages = [];
    if (!empty($customSystemPrompt)) {
        $apiMessages[] = ['role' => 'system', 'content' => $customSystemPrompt];
    }
    foreach ($conversationHistory as $msg) {
        $messageContent = '';
        if ($msg['role'] === 'user' || $msg['role'] === 'assistant') {
            $messageContent = isset($msg['all_contents'], $msg['current_version_index']) ? $msg['all_contents'][$msg['current_version_index']] : ($msg['content'] ?? '');
        } else { 
             $messageContent = $msg['content'] ?? '';
        }
        if (!empty($messageContent)) { 
             $apiMessages[] = ['role' => $msg['role'], 'content' => $messageContent];
        }
    }

    $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey];
    $data = ['model' => $model, 'messages' => $apiMessages];

    if ($temperature !== null && is_numeric($temperature) && $temperature >= 0.0 && $temperature <= 2.0) {
        $data['temperature'] = (float)$temperature;
    } else if ($temperature !== null && !empty(trim((string)$temperature))) { 
        error_log("[PHP API Call] Invalid temperature value provided: " . $temperature . ". Using API default.");
    }
    if ($maxTokens !== null && is_numeric($maxTokens) && $maxTokens >= 1) {
        $data['max_tokens'] = (int)$maxTokens;
    } else if ($maxTokens !== null && !empty(trim((string)$maxTokens))) { 
         error_log("[PHP API Call] Invalid max_tokens value provided: " . $maxTokens . ". Using API default.");
    }

    $mh = curl_multi_init();
    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [ CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($data), CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 10 ]);
    curl_multi_add_handle($mh, $ch);

    $active = null; $startTime = time(); $maxExecutionTime = 55; 
    do {
        $status = curl_multi_exec($mh, $active);
        if ($active) { curl_multi_select($mh, 0.1); }
        session_write_close(); usleep(50000); session_start(); 

        if ($requestId && isset($_SESSION['cancelled_requests'][$requestId]) && $_SESSION['cancelled_requests'][$requestId]) {
            error_log("[PHP API Call] Request ID $requestId was cancelled by user.");
            curl_multi_remove_handle($mh, $ch); curl_close($ch); curl_multi_close($mh);
            unset($_SESSION['cancelled_requests'][$requestId]); 
            return ['success' => false, 'status' => 'cancelled', 'error' => 'Request cancelled by user.'];
        }
        if (time() - $startTime > $maxExecutionTime) {
            error_log("[PHP API Call] Request ID $requestId timed out server-side after $maxExecutionTime seconds.");
            curl_multi_remove_handle($mh, $ch); curl_close($ch); curl_multi_close($mh);
            if($requestId) unset($_SESSION['cancelled_requests'][$requestId]);
            return ['success' => false, 'error' => 'API request timed out on server.'];
        }
    } while ($active && $status == CURLM_OK);

    $response = null; $http_code = 0; $curl_error = '';
    if ($status != CURLM_OK) {
        $curl_error = curl_multi_strerror($status); 
        error_log("[PHP API Call Error] curl_multi_exec error: " . $curl_error);
    } else {
        $info = curl_multi_info_read($mh, $msg_in_queue);
        if ($info && $info['result'] !== CURLE_OK) {
            $curl_error = curl_error($info['handle']);
            $http_code = curl_getinfo($info['handle'], CURLINFO_HTTP_CODE);
            error_log("[PHP API Call Error] cURL error on handle: " . $curl_error . " HTTP Code: " . $http_code);
        } else if ($info) {
            $response = curl_multi_getcontent($info['handle']);
            $http_code = curl_getinfo($info['handle'], CURLINFO_HTTP_CODE);
        }
    }

    curl_multi_remove_handle($mh, $ch); curl_close($ch); curl_multi_close($mh);
    if($requestId) unset($_SESSION['cancelled_requests'][$requestId]); 

    if ($response === null && !empty($curl_error)) { return ['success' => false, 'error' => "API call failed: " . $curl_error]; }
    if ($response === null && $http_code === 0 && empty($curl_error)) { return ['success' => false, 'error' => "API call failed: No response and no specific cURL error."]; }

    $response_data = json_decode($response, true);

    if ($http_code == 200 && json_last_error() === JSON_ERROR_NONE) {
        if (isset($response_data['choices'][0]['message']['content'])) {
            return ['success' => true, 'content' => $response_data['choices'][0]['message']['content']];
        } else {
            error_log("[PHP API Error] Unexpected response structure. HTTP $http_code. Response: " . print_r($response_data, true) . ". Sent: " . print_r($data, true));
            return ['success' => false, 'error' => "Unexpected API response. Check logs. Partial: " . substr(print_r($response_data, true), 0, 100)];
        }
    } else {
        error_log("[PHP API Error] Request failed. HTTP $http_code. cURL Error: $curl_error. Response: " . $response . ". Sent: " . print_r($data, true));
        $errorMsg = "API request failed (HTTP $http_code).";
        if (!empty($curl_error)) $errorMsg .= " cURL: $curl_error.";
        if ($response_data && isset($response_data['error']['message'])) $errorMsg .= " API: " . $response_data['error']['message'];
        else if (!empty($response)) $errorMsg .= " Raw: " . substr($response, 0, 100) . "...";
        return ['success' => false, 'error' => $errorMsg];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!in_array($_POST['action'], ['saveApiSettings', 'saveAdvancedSettings', 'import_session'])) {
        header('Content-Type: application/json');
        
        if (!isset($_POST['csrf_token']) || !validate_csrf_token($_POST['csrf_token'])) {
            echo json_encode(['success' => false, 'error' => 'CSRF token validation failed for action.']);
            exit;
        }

        $action = $_POST['action'];
        $response_data = ['success' => false, 'error' => 'Invalid action.'];
        $requestId = $_POST['requestId'] ?? null; 

        switch ($action) {
            case 'cancelApiRequest':
                $reqIdToCancel = $_POST['requestId'] ?? null;
                if ($reqIdToCancel) {
                    $_SESSION['cancelled_requests'][$reqIdToCancel] = true;
                    $response_data = ['success' => true, 'message' => "Request $reqIdToCancel cancellation initiated."];
                } else {
                    $response_data['error'] = 'No request ID provided for cancellation.';
                }
                break;

            case 'editAndRegenerate':
                $messageId = isset($_POST['messageId']) && is_string($_POST['messageId']) ? trim($_POST['messageId']) : '';
                $newContent = trim($_POST['newContent'] ?? '');
                
                if (empty($messageId)) { $response_data['error'] = 'Invalid Message ID.'; break; }

                $userMessageIndex = -1;
                foreach ($_SESSION['conversation'] as $key => $message) {
                    if (isset($message['id']) && $message['id'] === $messageId && $message['role'] === 'user') {
                        $userMessageIndex = $key;
                        break;
                    }
                }
                if ($userMessageIndex !== -1) {
                    $_SESSION['conversation'][$userMessageIndex]['all_contents'] = [$newContent]; 
                    $_SESSION['conversation'][$userMessageIndex]['current_version_index'] = 0;
                    array_splice($_SESSION['conversation'], $userMessageIndex + 1);
                    $api_response = callApi($_SESSION['conversation'], $_SESSION['api_settings'], $requestId);
                    if ($api_response['success']) {
                        $new_ai_message_id = generateUniqueId();
                        if (!isset($api_response['status']) || $api_response['status'] !== 'cancelled') {
                             $_SESSION['conversation'][] = ['id' => $new_ai_message_id, 'role' => 'assistant', 'all_contents' => [$api_response['content']], 'current_version_index' => 0];
                        }
                        $response_data = ['success' => true, 'id' => $messageId, 'newContent' => $newContent, 'newAIMessageContent' => $api_response['content'] ?? '', 'newAIMessageId' => $new_ai_message_id, 'status' => $api_response['status'] ?? 'completed'];
                    } else { 
                        $response_data['error'] = $api_response['error']; 
                        $response_data['status'] = $api_response['status'] ?? 'error';
                    }
                } else { $response_data['error'] = 'Message not found or not editable.'; }
                break;

            case 'deleteMessagesFromId':
                $messageIdToDeleteFrom = isset($_POST['messageId']) && is_string($_POST['messageId']) ? trim($_POST['messageId']) : '';
                if (empty($messageIdToDeleteFrom)) { $response_data['error'] = 'Invalid Message ID.'; break;}

                $foundIndex = -1;
                foreach ($_SESSION['conversation'] as $key => $message) {
                    if (isset($message['id']) && $message['id'] === $messageIdToDeleteFrom) { $foundIndex = $key; break; }
                }
                if ($foundIndex !== -1) {
                    array_splice($_SESSION['conversation'], $foundIndex);
                    $response_data = ['success' => true, 'deletedFromIndex' => $foundIndex];
                } else { $response_data['error'] = 'Message not found for deletion.'; }
                break;

            case 'regenerateReply':
                $messageIdToRegenerate = isset($_POST['messageId']) && is_string($_POST['messageId']) ? trim($_POST['messageId']) : '';
                if (empty($messageIdToRegenerate)) { $response_data['error'] = 'Invalid Message ID.'; break;}
                
                $messageKeyToRegenerate = null;
                foreach($_SESSION['conversation'] as $key => $msg) {
                    if (isset($msg['id']) && $msg['id'] === $messageIdToRegenerate && $msg['role'] === 'assistant') {
                        $messageKeyToRegenerate = $key;
                        break;
                    }
                }
                if ($messageKeyToRegenerate !== null) {
                    $tempConversation = array_slice($_SESSION['conversation'], 0, $messageKeyToRegenerate);
                    $api_response = callApi($tempConversation, $_SESSION['api_settings'], $requestId);
                    if ($api_response['success']) {
                        if (!isset($api_response['status']) || $api_response['status'] !== 'cancelled') {
                            if (!isset($_SESSION['conversation'][$messageKeyToRegenerate]['all_contents'])) {
                                $_SESSION['conversation'][$messageKeyToRegenerate]['all_contents'] = []; 
                            }
                            $_SESSION['conversation'][$messageKeyToRegenerate]['all_contents'][] = $api_response['content'];
                            $_SESSION['conversation'][$messageKeyToRegenerate]['current_version_index'] = count($_SESSION['conversation'][$messageKeyToRegenerate]['all_contents']) - 1;
                        }
                        $response_data = [
                            'success' => true, 'newContent' => $api_response['content'] ?? '', 'messageId' => $messageIdToRegenerate, 
                            'currentVersionIndex' => $_SESSION['conversation'][$messageKeyToRegenerate]['current_version_index'] ?? 0, 
                            'totalVersions' => count($_SESSION['conversation'][$messageKeyToRegenerate]['all_contents'] ?? []), 
                            'status' => $api_response['status'] ?? 'completed'
                        ];
                    } else { 
                        $response_data['error'] = $api_response['error'];
                        $response_data['status'] = $api_response['status'] ?? 'error';
                        $response_data['messageId'] = $messageIdToRegenerate;
                        $response_data['currentVersionIndex'] = $_SESSION['conversation'][$messageKeyToRegenerate]['current_version_index'] ?? 0;
                        $response_data['totalVersions'] = count($_SESSION['conversation'][$messageKeyToRegenerate]['all_contents'] ?? []);
                    }
                } else { $response_data['error'] = 'Cannot regenerate. AI message not found.'; }
                break;

            case 'updateVersionIndex':
                $messageId = isset($_POST['messageId']) && is_string($_POST['messageId']) ? trim($_POST['messageId']) : '';
                $newVersionIndex = filter_var($_POST['newVersionIndex'] ?? 0, FILTER_VALIDATE_INT);

                if (empty($messageId) || $newVersionIndex === false || $newVersionIndex < 0) {
                     $response_data['error'] = 'Invalid Message ID or version index.'; break;
                }
                $found = false;
                foreach ($_SESSION['conversation'] as &$message) { 
                    if (isset($message['id'], $message['all_contents']) && $message['id'] === $messageId && $message['role'] === 'assistant' && $newVersionIndex < count($message['all_contents'])) {
                        $message['current_version_index'] = $newVersionIndex;
                        $response_data = ['success' => true, 'currentVersionIndex' => $newVersionIndex, 'totalVersions' => count($message['all_contents']), 'contentForVersion' => $message['all_contents'][$newVersionIndex]];
                        $found = true; break;
                    }
                }
                if (!$found) $response_data['error'] = 'Message or version not found.';
                break;
            
            case 'saveEditedAIReply':
                $messageId = isset($_POST['messageId']) && is_string($_POST['messageId']) ? trim($_POST['messageId']) : '';
                $newRawContent = $_POST['newRawContent'] ?? ''; 
                if (empty($messageId)) { $response_data['error'] = 'Invalid Message ID.'; break;}
                $edited = false;
                foreach ($_SESSION['conversation'] as &$message) { 
                    if (isset($message['id']) && $message['id'] === $messageId && $message['role'] === 'assistant') {
                        $message['all_contents'] = [$newRawContent];
                        $message['current_version_index'] = 0;
                        $edited = true;
                        $response_data = [
                            'success' => true, 'message' => 'AI reply updated successfully.', 'messageId' => $messageId,
                            'newRawContent' => $newRawContent, 'currentVersionIndex' => 0, 'totalVersions' => 1
                        ];
                        break;
                    }
                }
                if (!$edited) $response_data['error'] = 'AI message not found or could not be edited.';
                break;
        }
        echo json_encode($response_data);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
    header('Content-Type: application/json');
    if (!isset($_POST['csrf_token']) || !validate_csrf_token($_POST['csrf_token'])) {
        echo json_encode(['success' => false, 'error' => 'CSRF token validation failed.']);
        exit;
    }

    $user_message_content = trim($_POST['message'] ?? '');
    $requestId = $_POST['requestId'] ?? null; 
    $response_data = ['success' => false, 'error' => 'An unknown error occurred.'];

    if (!empty($user_message_content)) {
        $user_message_id = generateUniqueId();
        $_SESSION['conversation'][] = [
            'id' => $user_message_id, 
            'role' => 'user', 
            'all_contents' => [$user_message_content],
            'current_version_index' => 0
        ];
        
        $api_response = callApi($_SESSION['conversation'], $_SESSION['api_settings'], $requestId);
        
        if ($api_response['success']) {
            $ai_message_id = generateUniqueId();
            if (!isset($api_response['status']) || $api_response['status'] !== 'cancelled') {
                $_SESSION['conversation'][] = ['id' => $ai_message_id, 'role' => 'assistant', 'all_contents' => [$api_response['content']], 'current_version_index' => 0];
            }
            $response_data = ['success' => true, 'userMessageId' => $user_message_id, 'userMessageContent' => $user_message_content, 'newAIMessageId' => $ai_message_id, 'newAIMessageContent' => $api_response['content'] ?? '', 'status' => $api_response['status'] ?? 'completed'];
        } else {
            $response_data = ['success' => false, 'error' => $api_response['error'], 'userMessageId' => $user_message_id, 'userMessageContent' => $user_message_content, 'status' => $api_response['status'] ?? 'error'];
        }
    } else {
         $response_data['error'] = 'Message cannot be empty.';
    }
    echo json_encode($response_data);
    exit;
}

if (isset($_GET['clear'])) {
    $_SESSION['conversation'] = [];
    $_SESSION['cancelled_requests'] = []; 
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); 
    header('Location: ' . strtok($_SERVER["REQUEST_URI"], '?')); 
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
    <link rel="stylesheet" href="css/styles.css?v=<?= filemtime('css/styles.css') ?>">
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
                        This project is free to use, but if you appreciate it, you can support me here:  
                        <a href="https://donate.lovechan.cc/" target="_blank"><strong>Donate Here</strong></a>
                    </p>

                    <p>Start by typing your first message below!</p>

                </div>
            <?php endif; ?>

            <?php 
            $last_user_message_index = -1;
            if (!empty($_SESSION['conversation'])) {
                foreach (array_reverse($_SESSION['conversation'], true) as $idx => $msg) {
                    if ($msg['role'] === 'user') { $last_user_message_index = $idx; break; }
                }
            }

            foreach ($_SESSION['conversation'] as $index => $message): 
                if (!isset($message['id'])) { $_SESSION['conversation'][$index]['id'] = generateUniqueId(); $message['id'] = $_SESSION['conversation'][$index]['id']; }
                
                $displayContent = '';
                if (!isset($message['all_contents'])) { $message['all_contents'] = [$message['content'] ?? 'Error: Content missing']; }
                if (!isset($message['current_version_index']) || !isset($message['all_contents'][$message['current_version_index']])) { 
                    $message['current_version_index'] = max(0, count($message['all_contents']) - 1);
                    $displayContent = (isset($message['all_contents'][$message['current_version_index']])) ? $message['all_contents'][$message['current_version_index']] : 'Error: Content missing';
                } else {
                    $displayContent = $message['all_contents'][$message['current_version_index']];
                }
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
                        <?php /* Action buttons are now fully managed by JS (ai_playground_ui.js) */ ?>
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
                <div class="form-group">
                    <label for="api_model">Model:</label>
                    <input type="text" id="api_model" name="api_model" value="<?= htmlspecialchars($_SESSION['api_settings']['model']) ?>" placeholder="e.g., gpt-4o">
                </div>
                <div class="form-group">
                    <label for="api_url">API/Proxy URL (Chat Completions):</label>
                    <input type="url" id="api_url" name="api_url" value="<?= htmlspecialchars($_SESSION['api_settings']['url']) ?>" placeholder="e.g., https://api.openai.com/v1/chat/completions">
                </div>
                <div class="form-group">
                    <label for="api_key">API Key:</label>
                    <input type="password" id="api_key" name="api_key" value="<?= htmlspecialchars($_SESSION['api_settings']['key']) ?>" placeholder="Enter your API key">
                </div>
                <div class="form-group">
                    <label for="api_prompt">Custom System Prompt:</label>
                    <textarea id="api_prompt" name="api_prompt" placeholder="e.g., You are a helpful assistant."><?= htmlspecialchars($_SESSION['api_settings']['prompt']) ?></textarea>
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
        <div class="modal-content api-settings-modal-content"> 
            <span class="close-button" id="closeSessionManagerModal">&times;</span>
            <h2>Import/Export Session</h2>
            
            <div class="form-group">
                <button type="button" id="exportSessionButton" class="action-btn primary-btn" style="width:100%;">Export Current Session</button>
                <small>Download a JSON file of your current chat history and settings (API key will not be included).</small>
            </div>
            
            <hr>

            <div class="form-group">
                <label for="importSessionFile" class="action-btn" style="width:100%; text-align:center; box-sizing: border-box;">Import Session from File</label>
                <input type="file" id="importSessionFile" name="session_file" accept=".json" style="display:none;">
                 <small>Upload a previously exported JSON session file. This will replace your current session. You may need to re-enter your API key in API Settings after import.</small>
            </div>
            <div id="importSessionStatus" class="status-message" style="display: none;"></div>
        </div>
    </div>

    <script src="js/ai_playground_utils.js?v=<?= filemtime('js/ai_playground_utils.js') ?>"></script>
    <script src="js/ai_playground_ui.js?v=<?= filemtime('js/ai_playground_ui.js') ?>"></script>
    <script src="js/ai_playground_chat.js?v=<?= filemtime('js/ai_playground_chat.js') ?>"></script>
    <script src="js/ai_playground_session_manager.js?v=<?= @filemtime('js/ai_playground_session_manager.js') ?>"></script>
</body>
</html>
