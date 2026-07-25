<?php

namespace App\Services;

use App\Models\SmtpSetting;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

class SmtpService
{
    /**
     * Monta um mailer sob demanda usando as credenciais SMTP da loja.
     */
    public function mailer(SmtpSetting $setting): Mailer
    {
        $encryption = $this->normalizeEncryption($setting->encryption);

        return Mail::build([
            'transport' => 'smtp',
            'scheme' => $encryption === 'ssl' ? 'smtps' : 'smtp',
            'host' => $setting->host,
            'port' => (int) $setting->port,
            'username' => $setting->username,
            'password' => $setting->password,
            // STARTTLS automático (porta 587). Desativado quando sem criptografia.
            'auto_tls' => $encryption !== null,
            'require_tls' => $encryption === 'tls',
            'timeout' => 15,
        ]);
    }

    /**
     * Envia um e-mail HTML usando as configurações SMTP da loja.
     */
    public function sendHtml(SmtpSetting $setting, string $to, string $subject, string $html): void
    {
        $fromEmail = $setting->from_email ?: $setting->username;
        $fromName = $setting->from_name ?: ($setting->name ?? '');

        $this->mailer($setting)->html($html, function (Message $message) use ($to, $subject, $fromEmail, $fromName) {
            $message->to($to)
                ->subject($subject)
                ->from($fromEmail, $fromName);
        });
    }

    /**
     * Testa conexão + autenticação com o servidor SMTP (sem enviar e-mail).
     * O transporte conecta, negocia TLS e autentica no primeiro comando.
     */
    public function testConnection(SmtpSetting $setting): void
    {
        $encryption = $this->normalizeEncryption($setting->encryption);

        $transport = new EsmtpTransport(
            $setting->host,
            (int) $setting->port,
            $encryption === 'ssl'
        );
        $transport->setUsername((string) $setting->username);
        $transport->setPassword((string) $setting->password);
        $transport->setAutoTls($encryption !== null);
        $transport->setRequireTls($encryption === 'tls');

        // NOOP força conexão + EHLO + STARTTLS + AUTH.
        $transport->executeCommand("NOOP\r\n", [250]);
    }

    /**
     * Normaliza o valor de criptografia salvo para o formato do mailer.
     * tls (STARTTLS) | ssl (implícito) | null (sem criptografia)
     */
    private function normalizeEncryption(?string $encryption): ?string
    {
        $value = strtolower((string) $encryption);

        return match ($value) {
            'tls', 'starttls' => 'tls',
            'ssl' => 'ssl',
            default => null,
        };
    }
}
