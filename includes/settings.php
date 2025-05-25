<?php
// includes/settings.php

// Function to get user setting (default to true for streaming)
function get_text_streaming_setting() {
    return isset($_SESSION['text_streaming_enabled']) ? $_SESSION['text_streaming_enabled'] : true;
}

// Handle AJAX request to update text streaming setting
if (isset($_POST['action']) && $_POST['action'] === 'updateTextStreamingSetting') {
    $isEnabled = filter_var($_POST['isEnabled'], FILTER_VALIDATE_BOOLEAN);
    $_SESSION['text_streaming_enabled'] = $isEnabled;
    echo json_encode(['success' => true, 'isEnabled' => $isEnabled]);
    exit;
}
?>

