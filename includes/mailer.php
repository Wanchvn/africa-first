<?php

/**
 * Send an email.
 *
 * If config['enabled'] is false, the email is written to storage/mail.log
 * instead of being sent. This lets you test the flow before the domain
 * is live and Brevo is verified.
 */
function send_email($to_email, $to_name, $subject, $html_body) {
    $config = require __DIR__ . '/../config/mail.php';

    // Log-only mode
    if (empty($config['enabled'])) {
        $log_dir = dirname($config['log_file']);
        if (!is_dir($log_dir)) {
            @mkdir($log_dir, 0755, true);
        }

        $entry  = str_repeat('=', 60) . "\n";
        $entry .= 'Date: ' . date('Y-m-d H:i:s') . "\n";
        $entry .= 'To: ' . $to_email . ' <' . $to_name . ">\n";
        $entry .= 'Subject: ' . $subject . "\n";
        $entry .= str_repeat('-', 60) . "\n";
        $entry .= $html_body . "\n\n";

        @file_put_contents($config['log_file'], $entry, FILE_APPEND);
        return true;
    }

    // Real send via PHPMailer
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoload)) {
        error_log('Mailer: PHPMailer not installed. Run: composer require phpmailer/phpmailer');
        return false;
    }
    require_once $autoload;

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = $config['smtp']['host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $config['smtp']['username'];
        $mail->Password   = $config['smtp']['password'];
        $mail->SMTPSecure = $config['smtp']['secure'];
        $mail->Port       = $config['smtp']['port'];

        $mail->setFrom($config['from']['email'], $config['from']['name']);
        $mail->addAddress($to_email, $to_name);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html_body;
        $mail->AltBody = strip_tags($html_body);

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('Mailer: ' . $mail->ErrorInfo);
        return false;
    }
}

/**
 * Build the password reset email body.
 */
function password_reset_email($username, $reset_url) {
    return '<!DOCTYPE html>
<html>
<body style="font-family:-apple-system,sans-serif;background:#F5EFE6;padding:40px 20px;margin:0;">
  <div style="max-width:520px;margin:0 auto;background:#fff;border-radius:16px;padding:32px;">
    <h1 style="color:#3E2723;font-size:22px;margin:0 0 16px;">Reset your Qarota password</h1>
    <p style="color:#2B2B2B;line-height:1.6;">Hi ' . htmlspecialchars($username) . ',</p>
    <p style="color:#2B2B2B;line-height:1.6;">
      Someone (hopefully you) requested a password reset for your Qarota account.
      Click the button below to set a new password.
    </p>
    <p style="text-align:center;margin:32px 0;">
      <a href="' . htmlspecialchars($reset_url) . '"
         style="background:#C65D3B;color:#fff;padding:14px 28px;border-radius:10px;
                text-decoration:none;font-weight:600;display:inline-block;">
        Set a new password
      </a>
    </p>
    <p style="color:#7A7A7A;font-size:14px;line-height:1.6;">
      This link expires in 1 hour. If you didn\'t request this, ignore this email.
    </p>
    <hr style="border:none;border-top:1px solid #E5DDD3;margin:24px 0;">
    <p style="color:#7A7A7A;font-size:13px;">
      Or copy this URL:<br>
      <span style="word-break:break-all;color:#C65D3B;">' . htmlspecialchars($reset_url) . '</span>
    </p>
    <p style="color:#7A7A7A;font-size:12px;margin-top:24px;">Qarota · Your data stays in Ghana</p>
  </div>
</body>
</html>';
}