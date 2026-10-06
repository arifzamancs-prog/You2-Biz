<?php

require_once __DIR__ . '/system_settings_helper.php';

function smtp_send_mail($to_email, $to_name, $subject, $html_body, $attachments = [], $mail_options = [])
{
    global $conn;

    $settings = isset($conn) && $conn instanceof mysqli
        ? system_settings_all($conn)
        : system_settings_defaults();
    $config = [
        'host' => $settings['smtp_host'] ?? 'mail.you2techbd.com',
        'port' => (int)($settings['smtp_port'] ?? 465),
        'secure' => $settings['smtp_secure'] ?? 'ssl',
        'username' => $settings['smtp_username'] ?? 'noreply@you2techbd.com',
        'password' => $settings['smtp_password'] ?? '',
        'from_email' => $settings['smtp_from_email'] ?? 'noreply@you2techbd.com',
        'from_name' => $settings['smtp_from_name'] ?? 'You2 Biz',
    ];
    $requested_from_name = trim(str_replace(["\r", "\n"], '', (string)($mail_options['from_name'] ?? '')));
    if($requested_from_name !== ''){
        $config['from_name'] = $requested_from_name;
    }

    $remote = ($config['secure'] === 'ssl' ? 'ssl://' : '') .
        $config['host'] . ':' . $config['port'];

    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ]);

    $socket = @stream_socket_client(
        $remote,
        $errno,
        $errstr,
        20,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if (!$socket) {
        return [false, "SMTP connection failed"];
    }

    stream_set_timeout($socket, 20);

    $read = function() use ($socket) {
        $response = '';

        while($line = fgets($socket, 515)){
            $response .= $line;

            if(isset($line[3]) && $line[3] === ' '){
                break;
            }
        }

        return $response;
    };

    $write = function($command) use ($socket, $read) {
        fwrite($socket, $command . "\r\n");
        return $read();
    };

    $expect = function($response, $codes) {
        $code = (int)substr($response, 0, 3);
        return in_array($code, $codes, true);
    };

    $response = $read();

    if(!$expect($response, [220])){
        fclose($socket);
        return [false, "SMTP greeting failed"];
    }

    $server_name = $_SERVER['SERVER_NAME'] ?? 'localhost';

    $response = $write("EHLO " . $server_name);

    if(!$expect($response, [250])){
        fclose($socket);
        return [false, "SMTP EHLO failed"];
    }

    if($config['secure'] === 'tls'){
        $response = $write("STARTTLS");

        if(!$expect($response, [220])){
            fclose($socket);
            return [false, "SMTP STARTTLS failed"];
        }

        if(!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)){
            fclose($socket);
            return [false, "SMTP TLS negotiation failed"];
        }

        $response = $write("EHLO " . $server_name);

        if(!$expect($response, [250])){
            fclose($socket);
            return [false, "SMTP EHLO after TLS failed"];
        }
    }

    $response = $write("AUTH LOGIN");

    if(!$expect($response, [334])){
        fclose($socket);
        return [false, "SMTP auth start failed"];
    }

    $response = $write(base64_encode($config['username']));

    if(!$expect($response, [334])){
        fclose($socket);
        return [false, "SMTP username failed"];
    }

    $response = $write(base64_encode($config['password']));

    if(!$expect($response, [235])){
        fclose($socket);
        return [false, "SMTP password failed"];
    }

    $response = $write("MAIL FROM:<" . $config['from_email'] . ">");

    if(!$expect($response, [250])){
        fclose($socket);
        return [false, "SMTP sender failed"];
    }

    $response = $write("RCPT TO:<" . $to_email . ">");

    if(!$expect($response, [250, 251])){
        fclose($socket);
        return [false, "SMTP recipient failed"];
    }

    $response = $write("DATA");

    if(!$expect($response, [354])){
        fclose($socket);
        return [false, "SMTP data failed"];
    }

    $to_email = str_replace(["\r", "\n"], '', (string)$to_email);
    $to_name = str_replace(["\r", "\n"], '', (string)$to_name);
    $subject = str_replace(["\r", "\n"], '', (string)$subject);
    $attachment_rows = [];
    foreach((array)$attachments as $attachment){
        if(!is_array($attachment) || !isset($attachment['content'])) continue;
        $filename = trim(str_replace(["\r", "\n", '"'], '', (string)($attachment['filename'] ?? 'attachment.pdf')));
        $content = (string)$attachment['content'];
        if($filename === '' || $content === '') continue;
        $attachment_rows[] = [
            'filename' => $filename,
            'content' => $content,
            'content_type' => trim((string)($attachment['content_type'] ?? 'application/octet-stream')) ?: 'application/octet-stream',
        ];
    }

    $headers = [
        'From: ' . $config['from_name'] . ' <' . $config['from_email'] . '>',
        'To: ' . ($to_name ? $to_name . ' ' : '') . '<' . $to_email . '>',
        'Subject: ' . $subject,
        'MIME-Version: 1.0',
    ];

    if($attachment_rows){
        $boundary = '=_You2Biz_' . bin2hex(random_bytes(16));
        $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
        $body = '--' . $boundary . "\r\n" .
            "Content-Type: text/html; charset=UTF-8\r\n" .
            "Content-Transfer-Encoding: 8bit\r\n\r\n" .
            $html_body . "\r\n";
        foreach($attachment_rows as $attachment){
            $body .= '--' . $boundary . "\r\n" .
                'Content-Type: ' . $attachment['content_type'] . '; name="' . $attachment['filename'] . '"' . "\r\n" .
                'Content-Transfer-Encoding: base64' . "\r\n" .
                'Content-Disposition: attachment; filename="' . $attachment['filename'] . '"' . "\r\n\r\n" .
                chunk_split(base64_encode($attachment['content'])) . "\r\n";
        }
        $body .= '--' . $boundary . "--\r\n";
    }else{
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: 8bit';
        $body = $html_body;
    }

    $message = implode("\r\n", $headers) .
        "\r\n\r\n" .
        $body .
        "\r\n.";

    fwrite($socket, $message . "\r\n");
    $response = $read();

    if(!$expect($response, [250])){
        fclose($socket);
        return [false, "SMTP message failed"];
    }

    $write("QUIT");
    fclose($socket);

    return [true, ""];
}
