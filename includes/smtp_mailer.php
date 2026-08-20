<?php

require_once __DIR__ . '/system_settings_helper.php';

function smtp_send_mail($to_email, $to_name, $subject, $html_body)
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

    $headers = [
        'From: ' . $config['from_name'] . ' <' . $config['from_email'] . '>',
        'To: ' . ($to_name ? $to_name . ' ' : '') . '<' . $to_email . '>',
        'Subject: ' . $subject,
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];

    $message = implode("\r\n", $headers) .
        "\r\n\r\n" .
        $html_body .
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
