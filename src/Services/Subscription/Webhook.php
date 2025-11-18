<?php
declare(strict_types=1);

namespace Eren5\PhpIyzico\Services\Subscription;

use Eren5\PhpIyzico\Config;
use Eren5\PhpIyzico\OptionsFactory;

/**
 * X-Iyz-Signature-V3 doğrulama yardımcısı
 *
 * Not: iyzico event tipine göre imzalanan alanların sırası değişebilir.
 * Bu helper, en yaygın bileşimleri kapsayan basit bir heuristik uygular.
 * Canlıda mutlaka event tipine göre (iyziEventType) net formülü teyit edin.
 */
final class Webhook
{
    public function __construct(private Config $cfg)
    {
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,string> $headers
     */
    public function verifySignatureV3(array $payload, array $headers): bool
    {
        $options = OptionsFactory::create($this->cfg);
        $secret = (string) $options->getSecretKey();
        $header = $headers['X-Iyz-Signature-V3'] ?? $headers['x-iyz-signature-v3'] ?? '';
        if ($header === '' || $secret === '') {
            return false;
        }

        // Basit kural: CF/PWI (token mevcut) ya da API (token yok)
        // Abonelik event'lerinde alanlar değişebilir; payload'a göre genişlet.
        $parts = [$secret];

        if (!empty($payload['iyziEventType'])) {
            $parts[] = (string) $payload['iyziEventType'];
        }
        if (!empty($payload['iyziPaymentId'])) {
            $parts[] = (string) $payload['iyziPaymentId'];
        }
        if (!empty($payload['paymentId'])) { // bazı eventlerde paymentId ismi
            $parts[] = (string) $payload['paymentId'];
        }
        if (!empty($payload['token'])) {
            $parts[] = (string) $payload['token'];
        }
        if (!empty($payload['paymentConversationId'])) {
            $parts[] = (string) $payload['paymentConversationId'];
        }
        if (!empty($payload['status'])) {
            $parts[] = (string) $payload['status'];
        }

        $dataToSign = implode('', $parts);
        $calc = bin2hex(hash_hmac('sha256', $dataToSign, $secret, true));

        return hash_equals((string) $header, $calc);
    }
}
