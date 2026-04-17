<?php
require __DIR__ . '/../vendor/autoload.php';

use App\Database;
use App\Services\MailService;

$pdo = Database::getConnection();
$mail = new MailService($pdo);

echo "Testing MailService...\n";
$success = $mail->send('shaheer@activelk.com', 'Test Subject', '<h1>Test Body</h1>');

if ($success) {
    echo "Mail reported as sent (or logged).\n";
} else {
    echo "Mail failed (likely SMTP not configured, check storage/logs/emails.log).\n";
}

$logFile = __DIR__ . '/storage/logs/emails.log';
if (file_exists($logFile)) {
    echo "\nLast Log Entry:\n";
    echo tailCustom($logFile, 5);
}

function tailCustom($filepath, $lines = 1) {
    $f = fopen($filepath, "rb");
    if (!$f) return false;
    fseek($f, -1, SEEK_END);
    if (fread($f, 1) != "\n") $lines -= 1;
    
    $output = '';
    $chunk = 4096;
    while (ftell($f) > 0 && $lines >= 0) {
        $seek = min(ftell($f), $chunk);
        fseek($f, -$seek, SEEK_CUR);
        $buffer = fread($f, $seek);
        $lines -= substr_count($buffer, "\n");
        $output = $buffer . $output;
        fseek($f, -$seek, SEEK_CUR);
    }
    while ($lines < 0) {
        $output = substr($output, strpos($output, "\n") + 1);
        $lines++;
    }
    fclose($f);
    return $output;
}
