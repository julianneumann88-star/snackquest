<?php
/**
 * SnackQuest — bounded SMTP/log mailer with verified STARTTLS and multipart mail.
 * No credentials, message contents or recipient addresses are written to app logs.
 * Version: 1.0.0 (2026-07-21)
 */
declare(strict_types=1);

namespace SnackQuest\Auth;

use SnackQuest\Config;
use SnackQuest\Support\Logger;

final class Mailer
{
    private const MAX_TEXT_BYTES = 200_000;
    private const MAX_HTML_BYTES = 500_000;

    public function __construct(
        private readonly Config $config,
        private readonly Logger $log,
    ) {
    }

    /** Send text with an optional HTML alternative. Returns false and never throws. */
    public function send(string $to, string $subject, string $textBody, ?string $htmlBody = null): bool
    {
        $subject = trim((string)preg_replace('/[\r\n]+/', ' ', $subject));
        $subject = mb_substr($subject, 0, 160);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)
            || $subject === ''
            || trim($textBody) === ''
            || strlen($textBody) > self::MAX_TEXT_BYTES
            || ($htmlBody !== null && (trim($htmlBody) === '' || strlen($htmlBody) > self::MAX_HTML_BYTES))) {
            $this->log->warning('Mailer: invalid or oversized message rejected');
            return false;
        }

        $transport = (string)$this->config->get('mail.transport', 'smtp');
        if ($transport === 'log') {
            $dir = (string)$this->config->get('log.dir');
            if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
                $this->log->error('Mailer: log transport directory unavailable');
                return false;
            }
            $written = @file_put_contents(
                $dir . '/mails.log',
                "=== " . date('c') . " to={$to} subject={$subject}\n{$textBody}\n\n",
                FILE_APPEND | LOCK_EX
            );
            $this->log->info('Mail captured by log transport', ['subject' => $subject, 'bytes' => strlen($textBody)]);
            return $written !== false;
        }
        if ($transport !== 'smtp') {
            $this->log->error('Mailer: unsupported transport rejected');
            return false;
        }

        try {
            return $this->sendSmtp($to, $subject, $textBody, $htmlBody);
        } catch (\Throwable $e) {
            $this->log->error('Mailer: SMTP send failed', ['type' => get_class($e)]);
            return false;
        }
    }

    private function sendSmtp(string $to, string $subject, string $textBody, ?string $htmlBody): bool
    {
        $host = trim((string)$this->config->get('mail.host'));
        $port = (int)$this->config->get('mail.port', 587);
        $user = (string)$this->config->get('mail.user');
        $pass = (string)$this->config->get('mail.pass');
        $from = trim((string)$this->config->get('mail.from'));
        $fromName = trim((string)$this->config->get('mail.from_name', 'SnackQuest'));
        $fromName = mb_substr((string)preg_replace('/[\r\n]+/', ' ', $fromName), 0, 100);

        if (!preg_match('/^[A-Za-z0-9.-]+$/', $host) || $port < 1 || $port > 65535
            || $user === '' || $pass === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Invalid SMTP configuration');
        }

        $timeout = 12;
        $context = stream_context_create(['ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $host,
            'allow_self_signed' => false,
        ]]);
        $fp = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
        if ($fp === false) {
            throw new \RuntimeException("SMTP connect failed ({$errno})");
        }
        stream_set_timeout($fp, $timeout);

        $read = static function () use ($fp): string {
            $data = '';
            while (($line = fgets($fp, 2048)) !== false) {
                $data .= $line;
                if (strlen($data) > 16_384) {
                    throw new \RuntimeException('SMTP response too large');
                }
                if (isset($line[3]) && $line[3] === ' ') {
                    break;
                }
            }
            $meta = stream_get_meta_data($fp);
            if (($meta['timed_out'] ?? false) || $data === '') {
                throw new \RuntimeException('SMTP response timeout');
            }
            return $data;
        };
        $write = static function (string $data) use ($fp): void {
            $offset = 0;
            $length = strlen($data);
            while ($offset < $length) {
                $written = fwrite($fp, substr($data, $offset));
                if ($written === false || $written === 0) {
                    throw new \RuntimeException('SMTP write failed');
                }
                $offset += $written;
            }
        };
        $cmd = static function (string $command, array $expect) use ($write, $read): string {
            $write($command . "\r\n");
            $response = $read();
            $code = (int)substr($response, 0, 3);
            if (!in_array($code, $expect, true)) {
                throw new \RuntimeException('SMTP unexpected response ' . $code);
            }
            return $response;
        };

        try {
            if ((int)substr($read(), 0, 3) !== 220) {
                throw new \RuntimeException('SMTP banner rejected');
            }
            $cmd('EHLO snackquest.local', [250]);
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new \RuntimeException('SMTP TLS negotiation failed');
            }
            $cmd('EHLO snackquest.local', [250]);
            $cmd('AUTH LOGIN', [334]);
            $cmd(base64_encode($user), [334]);
            $cmd(base64_encode($pass), [235]);
            $cmd('MAIL FROM:<' . $from . '>', [250]);
            $cmd('RCPT TO:<' . $to . '>', [250, 251]);
            $cmd('DATA', [354]);

            [$headers, $body] = $this->composeMessage($to, $from, $fromName, $subject, $textBody, $htmlBody);
            $wire = implode("\r\n", $headers) . "\r\n\r\n" . $body;
            $wire = (string)preg_replace('/^\./m', '..', $wire);
            $write($wire . "\r\n.\r\n");
            if ((int)substr($read(), 0, 3) !== 250) {
                throw new \RuntimeException('SMTP DATA not accepted');
            }
            $cmd('QUIT', [221]);
        } finally {
            fclose($fp);
        }

        $this->log->info('Mail sent', ['subject' => $subject]);
        return true;
    }

    /** @return array{0:array<int,string>,1:string} */
    private function composeMessage(string $to, string $from, string $fromName, string $subject, string $textBody, ?string $htmlBody): array
    {
        $domain = substr(strrchr($from, '@') ?: '@julian-neumann.org', 1);
        $headers = [
            'From: ' . mb_encode_mimeheader($fromName, 'UTF-8') . ' <' . $from . '>',
            'To: <' . $to . '>',
            'Subject: ' . mb_encode_mimeheader($subject, 'UTF-8'),
            'MIME-Version: 1.0',
            'Date: ' . date('r'),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
            'Auto-Submitted: auto-generated',
            'X-Auto-Response-Suppress: All',
        ];
        $text = quoted_printable_encode($this->normalizeLines($textBody));
        if ($htmlBody === null) {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: quoted-printable';
            return [$headers, $text];
        }
        $boundary = 'sq_' . bin2hex(random_bytes(16));
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
        $html = quoted_printable_encode($this->normalizeLines($htmlBody));
        $body = '--' . $boundary . "\r\nContent-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: quoted-printable\r\n\r\n{$text}\r\n"
            . '--' . $boundary . "\r\nContent-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: quoted-printable\r\n\r\n{$html}\r\n"
            . '--' . $boundary . '--';
        return [$headers, $body];
    }

    private function normalizeLines(string $value): string
    {
        return str_replace("\n", "\r\n", str_replace(["\r\n", "\r"], "\n", $value));
    }
}

