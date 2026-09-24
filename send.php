<?php
// ================== НАСТРОЙКИ ==================
// 1. Создайте бота в Telegram через @BotFather и вставьте его токен:
$BOT_TOKEN = 'ВСТАВЬТЕ_ТОКЕН_БОТА';
// 2. Узнайте chat_id (свой или группы) через @userinfobot / @getmyid_bot и вставьте:
$CHAT_ID   = 'ВСТАВЬТЕ_CHAT_ID';
// 3. (необязательно) копия заявок на почту. Оставьте пустым, если не нужно:
$EMAIL     = '';
// ===============================================

date_default_timezone_set('Europe/Istanbul');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Метод не поддерживается']);
    exit;
}

// Защита от спам-ботов: скрытое поле должно быть пустым
if (!empty($_POST['website'])) { echo json_encode(['ok' => true]); exit; }

function clean($v, $max = 200) {
    $v = trim(strip_tags((string)$v));
    return mb_substr($v, 0, $max, 'UTF-8');
}

$name    = clean($_POST['name'] ?? '');
$contact = clean($_POST['contact'] ?? '');
$source  = clean($_POST['source'] ?? 'Заявка с сайта', 100);

if ($name === '' || $contact === '') {
    echo json_encode(['ok' => false, 'error' => 'Заполните имя и телефон или Telegram.']);
    exit;
}

// Простая защита от повторной отправки: не чаще раза в 20 секунд с одного IP
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$lock = sys_get_temp_dir() . '/lead_' . md5($ip);
if (file_exists($lock) && time() - filemtime($lock) < 20) {
    echo json_encode(['ok' => false, 'error' => 'Заявка уже отправлена. Подождите немного.']);
    exit;
}
@touch($lock);

$date = date('d.m.Y H:i');
$text = "🌅 Новая заявка с лендинга\n\n"
      . "📌 {$source}\n"
      . "👤 Имя: {$name}\n"
      . "📱 Контакт: {$contact}\n"
      . "🕒 {$date}";

$sent = false;
if ($BOT_TOKEN !== 'ВСТАВЬТЕ_ТОКЕН_БОТА' && $CHAT_ID !== 'ВСТАВЬТЕ_CHAT_ID') {
    $url = "https://api.telegram.org/bot{$BOT_TOKEN}/sendMessage";
    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => http_build_query(['chat_id' => $CHAT_ID, 'text' => $text]),
        'timeout' => 10,
    ]]);
    $res = @file_get_contents($url, false, $ctx);
    $sent = $res !== false && strpos($res, '"ok":true') !== false;
}

if ($EMAIL !== '') {
    $subject = '=?UTF-8?B?' . base64_encode('Заявка с лендинга: ' . $source) . '?=';
    $mailOk = @mail($EMAIL, $subject, $text, "Content-Type: text/plain; charset=utf-8\r\n");
    $sent = $sent || $mailOk;
}

// Резервная копия всех заявок в файл leads.csv (на случай сбоя Telegram)
$dir = __DIR__ . '/leads';
if (!is_dir($dir)) { @mkdir($dir, 0750); @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n"); }
@file_put_contents($dir . '/leads.csv', "\"{$date}\";\"{$source}\";\"{$name}\";\"{$contact}\"\n", FILE_APPEND | LOCK_EX);

echo json_encode($sent
    ? ['ok' => true]
    : ['ok' => true, 'note' => 'saved_to_file']);
