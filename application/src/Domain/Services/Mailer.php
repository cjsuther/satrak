<?php

declare(strict_types=1);

namespace Satrak\Domain\Services;

use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Envío de email vía PHPMailer/SMTP.
 *
 * Si no hay SMTP configurado (host vacío) — típico en desarrollo — el mensaje
 * NO se envía por red: se vuelca a storage/logs/mail/ para poder leerlo. Así el
 * flujo de recupero de contraseña es probable sin un servidor SMTP real.
 */
final class Mailer
{
    /**
     * @param array{host:string,port:int,user:string,pass:string,secure:string,from:string,from_name:string} $smtp
     */
    public function __construct(private array $smtp, private string $logDir)
    {
    }

    public function send(string $toEmail, string $toName, string $subject, string $htmlBody, string $textBody = ''): bool
    {
        if (trim($this->smtp['host'] ?? '') === '') {
            return $this->dumpToDisk($toEmail, $subject, $htmlBody);
        }

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = $this->smtp['host'];
            $mail->Port       = (int) $this->smtp['port'];
            $mail->SMTPAuth   = $this->smtp['user'] !== '';
            $mail->Username   = $this->smtp['user'];
            $mail->Password   = $this->smtp['pass'];
            if (!empty($this->smtp['secure'])) {
                $mail->SMTPSecure = $this->smtp['secure'];   // 'tls' | 'ssl'
            }
            $mail->CharSet = 'UTF-8';

            $mail->setFrom($this->smtp['from'], $this->smtp['from_name']);
            $mail->addAddress($toEmail, $toName);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;
            $mail->AltBody = $textBody !== '' ? $textBody : strip_tags($htmlBody);

            return $mail->send();
        } catch (MailException $e) {
            error_log('Mailer error: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Modo desarrollo: guarda el email en disco en vez de enviarlo.
     */
    private function dumpToDisk(string $to, string $subject, string $htmlBody): bool
    {
        $dir = $this->logDir . '/mail';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $file = $dir . '/' . date('Ymd-His') . '-' . substr(md5($to . $subject . microtime()), 0, 8) . '.html';
        $header = "<!-- To: {$to} | Subject: {$subject} | " . date('c') . " -->\n";

        return (bool) @file_put_contents($file, $header . $htmlBody);
    }
}
