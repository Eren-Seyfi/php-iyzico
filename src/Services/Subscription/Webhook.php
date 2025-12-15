<?php
declare(strict_types=1);

namespace Eren5\PhpIyzico\Services\Subscription;

use Eren5\PhpIyzico\Config;
use Eren5\PhpIyzico\OptionsFactory;

/**
 * X-Iyz-Signature-V3 doğrulama yardımcısı
 *
 * Not: iyzico event tipine göre imzalanan alanların sırası değişebilir.
 * Bu helper en yaygın varyasyonları ele alır.
 */
final class Webhook
{
    public function __construct(private Config $config)
    {
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,string> $headers
     */
    public function verifySignatureV3(array $payload, array $headers): bool
    {
        $options = OptionsFactory::create($this->config);
        $secretKey = (string) $options->getSecretKey();

        // Header anahtarı büyük/küçük fark etmeyecek şekilde yakalanır
        $signatureHeader =
            $headers['X-Iyz-Signature-V3']
            ?? $headers['x-iyz-signature-v3']
            ?? '';

        if ($signatureHeader === '' || $secretKey === '') {
            return false;
        }

        // İmzaya dahil edilecek parçalar
        $signatureParts = [$secretKey];

        // Alanların varlığına göre ekleme yapılır (event tipine göre değişiklik gösterebilir)
        if (!empty($payload['iyziEventType'])) {
            $signatureParts[] = (string) $payload['iyziEventType'];
        }

        if (!empty($payload['iyziPaymentId'])) {
            $signatureParts[] = (string) $payload['iyziPaymentId'];
        }

        if (!empty($payload['paymentId'])) {
            $signatureParts[] = (string) $payload['paymentId'];
        }

        if (!empty($payload['token'])) {
            $signatureParts[] = (string) $payload['token'];
        }

        if (!empty($payload['paymentConversationId'])) {
            $signatureParts[] = (string) $payload['paymentConversationId'];
        }

        if (!empty($payload['status'])) {
            $signatureParts[] = (string) $payload['status'];
        }

        // Concatenate
        $rawData = implode('', $signatureParts);

        // İmza hesapla
        $calculatedSignature = bin2hex(
            hash_hmac('sha256', $rawData, $secretKey, true)
        );

        return hash_equals((string) $signatureHeader, $calculatedSignature);
    }
}
