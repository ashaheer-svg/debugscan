<?php

declare(strict_types=1);

namespace App\Services;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PDO;

/**
 * MailService: Send transactional emails via SMTP
 *
 * PURPOSE:
 * Send notifications and transactional emails (job completion, alerts, etc.)
 * Fetch SMTP configuration from database (admin-configurable)
 * Log all messages for audit trail and debugging
 *
 * SMTP CONFIGURATION:
 * Stored in system_settings table:
 * - smtp_host: Server address
 * - smtp_port: Port number
 * - smtp_user: Authentication username
 * - smtp_pass: Authentication password
 * - smtp_encryption: 'ssl' or 'starttls' (or other)
 * - smtp_from: Sender email address
 *
 * FALLBACK:
 * If SMTP not configured (smtp_host empty):
 * - Log message to file instead
 * - Return false (email not sent)
 * - Allows graceful degradation (system works without email)
 *
 * LOGGING:
 * All messages logged to storage/logs/emails.log:
 * - Timestamp, status (SENT/FAILED/UNCONFIGURED), recipient, subject
 * - Body text (for debugging)
 * - Separator line for readability
 * Persists even if email sending fails (audit trail)
 *
 * FAILURE TRACKING:
 * Failed sends also logged to audit_log table:
 * - Action: 'email_failed'
 * - Details: JSON with to, subject, error message, SMTP host
 * - Admin can see email failures in dashboard
 * - Failures don't crash system (errors caught, logged, returned)
 *
 * PHPMAILER INTEGRATION:
 * Uses industry-standard PHPMailer library
 * Supports SMTP, STARTTLS, and SSL encryption
 * Auto-generates plain-text alternative from HTML body
 * Throws exceptions on misconfiguration (caught and handled)
 *
 * @package App\Services
 */
class MailService
{
    private PDO $pdo;

    /**
     * Constructor: Dependency injection
     *
     * @param PDO $pdo Database connection for SMTP settings and audit_log
     */
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Send transactional email via SMTP
     *
     * WORKFLOW:
     * 1. Fetch SMTP settings from system_settings table
     * 2. If not configured: log and return false (graceful fallback)
     * 3. Create PHPMailer instance
     * 4. Configure SMTP server (host, port, auth, encryption)
     * 5. Set sender and recipient
     * 6. Set HTML body + auto-generate plain-text alternative
     * 7. Send via PHPMailer
     * 8. Log success or failure
     *
     * CONFIGURATION DETECTION:
     * SMTP enabled only if smtp_host is set and non-empty
     * If missing, email NOT sent but logged (doesn't break system)
     * Admin can configure SMTP in system settings UI
     *
     * AUTHENTICATION:
     * SMTPAuth enabled only if smtp_pass provided
     * Handles both authenticated and open relays
     *
     * ENCRYPTION:
     * Supports ssl (SMTPS) and starttls (TLS)
     * Default: STARTTLS if smtp_encryption != 'ssl'
     *
     * CONTENT HANDLING:
     * isHTML(true): Parse body as HTML
     * AltBody: Auto-generated plain-text version (strip HTML tags)
     * Ensures email clients without HTML support can read
     *
     * ERROR HANDLING:
     * PHPMailer exceptions caught
     * PHPMailer::ErrorInfo: Detailed error message
     * Logged to both file and database
     * Audit entry created (email_failed) for dashboard visibility
     * Audit failures don't crash (nested try-catch)
     *
     * LOGGING:
     * logMessage() called for all outcomes
     * File: storage/logs/emails.log
     * Always appended (persistent audit trail)
     *
     * @param string $to Recipient email address
     * @param string $subject Email subject line
     * @param string $body HTML email body
     *
     * @return bool True if sent, false if failed or SMTP not configured
     */
    public function send(string $to, string $subject, string $body): bool
    {
        // Fetch SMTP settings from database
        $stmt = $this->pdo->query("SELECT smtp_host, smtp_port, smtp_user, smtp_pass, smtp_from, smtp_encryption FROM system_settings LIMIT 1");
        $settings = $stmt->fetch();

        if (!$settings || empty($settings['smtp_host'])) {
            // SMTP not configured: log and return false
            $this->logMessage($to, $subject, $body, "SMTP NOT CONFIGURED");
            return false;
        }

        $mail = new PHPMailer(true);

        try {
            // Configure SMTP server
            $mail->isSMTP();
            $mail->Host       = $settings['smtp_host'];
            $mail->SMTPAuth   = !empty($settings['smtp_pass']);
            $mail->Username   = $settings['smtp_user'];
            $mail->Password   = $settings['smtp_pass'];
            $mail->SMTPSecure = $settings['smtp_encryption'] === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = (int)$settings['smtp_port'];

            // Set sender and recipient
            $mail->setFrom($settings['smtp_from'] ?: 'no-reply@debugscan.ia', 'AI DebugScan');
            $mail->addAddress($to);

            // Set content (HTML + plain-text alternative)
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $body;
            $mail->AltBody = strip_tags($body);

            // Send
            $mail->send();

            $this->logMessage($to, $subject, $body, "SENT");
            return true;
        } catch (Exception $e) {
            $errorMsg = $mail->ErrorInfo ?: $e->getMessage();
            $this->logMessage($to, $subject, $body, "FAILED: " . $errorMsg);

            // Log failure to audit_log for admin visibility
            try {
                $stmt = $this->pdo->prepare("
                    INSERT INTO audit_log (action, details, created_at)
                    VALUES ('email_failed', :details, NOW())
                ");
                $stmt->execute([
                    'details' => json_encode([
                        'to' => $to,
                        'subject' => $subject,
                        'error' => $errorMsg,
                        'smtp_host' => $settings['smtp_host'] ?? 'N/A'
                    ])
                ]);
            } catch (\Exception $auditEx) {
                // Ignore audit logging failures (prevent cascading errors)
                error_log("Failed to log email error to audit_log: " . $auditEx->getMessage());
            }

            return false;
        }
    }

    /**
     * Log email message to file
     *
     * LOCATION:
     * storage/logs/emails.log
     * Created recursively if doesn't exist (mkdir -p)
     *
     * LOG FORMAT:
     * Timestamp | Status | To | Subject | Body | Separator
     * Each entry separated by 80-character line
     * Appended to file (not overwritten)
     *
     * CALLED BY:
     * send() method for all outcomes (SENT, FAILED, UNCONFIGURED)
     * Ensures all mail activity auditable
     *
     * DIRECTORY CREATION:
     * If storage/logs doesn't exist: created with 0755 permissions
     * Allows graceful handling of missing directories
     *
     * @param string $to Recipient email address
     * @param string $subject Email subject
     * @param string $body Email body (may be HTML)
     * @param string $status Message status (SENT, FAILED, UNCONFIGURED)
     *
     * @return void
     */
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
