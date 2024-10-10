<?php
require 'vendor/autoload.php';

use Telegram\Bot\Api;

$telegram = new Api('YOUR_BOT_API_TOKEN');

// In-memory storage for user activity (data will reset when script restarts)
$users = []; // Array to store userId and last message time

// Function to log user activity in the in-memory array
function logUserActivity($userId, $timestamp) {
    global $users;
    $users[$userId] = $timestamp;  // Store user activity in the array
}

// Function to remove inactive users after 3 days (259200 seconds)
function removeInactiveUsers($chatId, $timeThreshold) {
    global $users, $telegram;
    $inactiveUsers = [];

    foreach ($users as $userId => $lastMessageTime) {
        if ((time() - $lastMessageTime) > $timeThreshold) {
            $inactiveUsers[] = $userId;  // Collect inactive user ids
        }
    }

    // Remove inactive users from the group
    foreach ($inactiveUsers as $inactiveUser) {
        $telegram->kickChatMember([
            'chat_id' => $chatId,
            'user_id' => $inactiveUser,
        ]);

        // Optionally, you can also remove them from the tracking list
        unset($users[$inactiveUser]);
    }
}

// Fetch updates from Telegram (polling for messages)
$response = $telegram->getUpdates();
$messages = $response['result'];  // Array of new messages

// Log user activity when they send a message
foreach ($messages as $message) {
    if (isset($message['message']['chat']['id']) && $message['message']['chat']['type'] == 'group') {
        $chatId = $message['message']['chat']['id'];
        $userId = $message['message']['from']['id'];
        $messageTime = $message['message']['date'];  // Unix timestamp of message

        // Log user's last message time in the array
        logUserActivity($userId, $messageTime);
    }
}

// Remove users inactive for 3 days (259200 seconds)
removeInactiveUsers($chatId, 259200);


// PART 2: INLINE BOT FOR READING A BOOK

// Fetch Webhook updates for inline queries or callbacks
$response = $telegram->getWebhookUpdates();

// Handle inline queries (when a user requests the next page to read)
if (isset($response['inline_query'])) {
    $inlineQueryId = $response['inline_query']['id'];
    $nextPage = $response['inline_query']['query']; // Assume the query is the next page number

    // Respond with a message that has a "I've read the page" button
    $telegram->answerInlineQuery([
        'inline_query_id' => $inlineQueryId,
        'results' => json_encode([[
            'type' => 'article',
            'id' => uniqid(),
            'title' => "Next page: $nextPage",
            'input_message_content' => [
                'message_text' => "Page $nextPage. [Click to read the page]",
            ],
            'reply_markup' => [
                'inline_keyboard' => [[
                    ['text' => "I've read page $nextPage", 'callback_data' => "read_$nextPage"]
                ]]
            ]
        ]]),
        'cache_time' => 0
    ]);
}

// Handle callback queries (when a user clicks "I've read the page")
if (isset($response['callback_query'])) {
    $callbackId = $response['callback_query']['id'];
    $callbackData = $response['callback_query']['data'];
    $chatId = $response['callback_query']['message']['chat']['id'];
    $messageId = $response['callback_query']['message']['message_id'];

    if (strpos($callbackData, 'read_') === 0) {
        $pageRead = str_replace('read_', '', $callbackData);

        // Update the message to indicate the page has been read and remove the button
        $telegram->editMessageText([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => "Page $pageRead ✅", // Add checkmark emoji to show it's been read
        ]);
    }
}
?>
