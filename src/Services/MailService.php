<?php

declare(strict_types=1);

namespace App\Services;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PDO;

class MailService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Sends an email using SMTP settings from system_settings table.
     */
    public function send(string $to, string $subject, string $body): bool
    {
        // 1. Fetch SMTP settings
        $stmt = $this->pdo->query("SELECT smtp_host, smtp_port, smtp_user, smtp_pass, smtp_from, smtp_encryption FROM system_settings LIMIT 1");
        $settings = $stmt->fetch();

        if (!$settings || empty($settings['smtp_host'])) {
            // Fallback: Log to file if SMTP not configured
            $this->logMessage($to, $subject, $body, "SMTP NOT CONFIGURED");
            return false;
        }

        $mail = new PHPMailer(true);

        try {
            // Server settings
            $mail->isSMTP();
            $mail->Host       = $settings['smtp_host'];
            $mail->SMTPAuth   = !empty($settings['smtp_pass']);
            $mail->Username   = $settings['smtp_user'];
            $mail->Password   = $settings['smtp_pass'];
            $mail->SMTPSecure = $settings['smtp_encryption'] === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = (int)$settings['smtp_port'];

            // Recipients
            $mail->setFrom($settings['smtp_from'] ?: 'no-reply@debugscan.ia', 'AI DebugScan');
            $mail->addAddress($to);

            // Content
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $body;
            $mail->AltBody = strip_tags($body);

            $mail->send();
            
            $this->logMessage($to, $subject, $body, "SENT");
            return true;
        } catch (Exception $e) {
            $this->logMessage($to, $subject, $body, "FAILED: " . $mail->ErrorInfo);
            return false;
        }
    }

    private function logMessage(string $to, string $subject, string $body, string $status): void
    {
        $dir = __DIR__ . '/../../storage/logs';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $logFile = $dir . '/emails.log';
        $entry = sprintf(
            "[%s] STATUS: %s | TO: %s | SUBJECT: %s\nBODY: %s\n%s\n",
            date('Y-m-d H:i:s'),
            $status,
            $to,
            $subject,
            $body,
            str_repeat('-', 80)
        );

        file_put_contents($logFile, $entry, FILE_APPEND);
    }
}
