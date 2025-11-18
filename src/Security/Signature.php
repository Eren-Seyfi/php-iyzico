<?php
declare(strict_types=1);

namespace Eren5\PhpIyzico\Security;

use Iyzipay\Model\Payment;
use Iyzipay\Model\ThreedsInitialize;
use Iyzipay\Options;
use InvalidArgumentException;

final class Signature
{

    /** @param list<scalar|null> $params */
    public static function calculate(array $params, string $secretKey): string
    {
        // Iyzipay örneklerindeki signature_verification.php ile AYNI sırayı ve ayracı kullanmalısın.
        // Çoğu örnekte HMAC-SHA256 + base64 ve ":" ayraç kullanılıyor.
        $normalized = array_map(static fn($v) => (string) ($v ?? ''), $params);
        $payload = implode(':', $normalized);         // ← gerekirse ayracı signature_verification.php'deki ile eşleştir
        $raw = hash_hmac('sha256', $payload, $secretKey, true);
        return base64_encode($raw);
    }

    /**
     * Kolon (:) ile birleştirilmiş veriler için HMAC-SHA256 (hex)
     * Örn: payment imzası, callback örneği (::1:22484292:success)
     * @param array<int,string> $params
     */
    public static function calculateColonSeparated(array $params, string $secretKey): string
    {
        $dataToSign = implode(':', $params);
        return bin2hex(hash_hmac('sha256', $dataToSign, $secretKey, true));
    }

    /**
     * Delimitersiz bitişik veriler için HMAC-SHA256 (hex)
     * Örn: Webhook X-Iyz-Signature-V3
     * @param array<int,string> $params
     */
    public static function calculateConcatenated(array $params, string $secretKey): string
    {
        $dataToSign = implode('', $params);
        return bin2hex(hash_hmac('sha256', $dataToSign, $secretKey, true));
    }

    /**
     * Non-3DS (ve 3DS auth sonrası) Payment imzasını doğrular.
     * Pattern: [paymentId, currency, basketId, conversationId, paidPrice, price]
     */
    public static function verifyPayment(Payment $p, Options $opt): bool
    {
        $secret = (string) $opt->getSecretKey();
        $params = [
            (string) $p->getPaymentId(),
            (string) $p->getCurrency(),
            (string) $p->getBasketId(),
            (string) $p->getConversationId(),
            (string) $p->getPaidPrice(),
            (string) $p->getPrice(),
        ];
        $calc = self::calculateColonSeparated($params, $secret);
        return (string) $p->getSignature() === $calc;
    }

    /**
     * (İsteğe bağlı) 3DS Initialize doğrulaması — akışına uygun ise kullan.
     * Sıklıkla: [paymentId, conversationId]
     */
    public static function verifyThreedsInit(ThreedsInitialize $init, Options $opt): bool
    {
        $secret = (string) $opt->getSecretKey();
        $params = [
            (string) $init->getPaymentId(),
            (string) $init->getConversationId(),
        ];
        $calc = self::calculateColonSeparated($params, $secret);
        return (string) $init->getSignature() === $calc;
    }

    /**
     * 3DS/Non-3DS callback (returnURL) için imza doğrulama.
     * Pattern: conversationData:conversationId:mdStatus:paymentId:status
     * Not: conversationData boş olabilir → "::1:22484292:success" gibi.
     */
    public static function verifyCallbackReturn(
        string $signature,
        string $conversationData,
        string $conversationId,
        string $mdStatus,
        string $paymentId,
        string $status,
        Options $opt
    ): bool {
        $secret = (string) $opt->getSecretKey();
        $params = [
            $conversationData,
            $conversationId,
            $mdStatus,
            $paymentId,
            $status,
        ];
        $calc = self::calculateColonSeparated($params, $secret);
        return hash_equals($signature, $calc);
    }

    /**
     * Webhook X-Iyz-Signature-V3 doğrulaması (CO Form / Pay with iyzico).
     * Pattern: secretKey + iyziEventType + iyziPaymentId + token + paymentConversationId + status
     * DİKKAT: Dokümanda secretKey veri stringinin başında da yer alıyor.
     */
    public static function verifyWebhookV3CheckoutForm(
        string $signatureV3,
        string $iyziEventType,
        string $iyziPaymentId,
        string $token,
        string $paymentConversationId,
        string $status,
        Options $opt
    ): bool {
        $secret = (string) $opt->getSecretKey();
        $params = [
            $secret,
            $iyziEventType,
            $iyziPaymentId,
            $token,
            $paymentConversationId,
            $status,
        ];
        $calc = self::calculateConcatenated($params, $secret);
        return hash_equals($signatureV3, $calc);
    }

    /**
     * Webhook X-Iyz-Signature-V3 doğrulaması (Direct Payments via API).
     * Pattern: secretKey + iyziEventType + paymentId + paymentConversationId + status
     */
    public static function verifyWebhookV3Api(
        string $signatureV3,
        string $iyziEventType,
        string $paymentId,
        string $paymentConversationId,
        string $status,
        Options $opt
    ): bool {
        $secret = (string) $opt->getSecretKey();
        $params = [
            $secret,
            $iyziEventType,
            $paymentId,
            $paymentConversationId,
            $status,
        ];
        $calc = self::calculateConcatenated($params, $secret);
        return hash_equals($signatureV3, $calc);
    }

    /**
     * Payload’a göre uygun Webhook V3 doğrulamasını seçmek istersen:
     * $payload içinde 'token' varsa CO Form; yoksa Direct API kabul eder.
     *
     * @param array<string,mixed> $payload  JSON body (assoc)
     * @param array<string,string> $headers HTTP headers (signature 'X-Iyz-Signature-V3')
     */
    public static function verifyWebhookV3Auto(array $payload, array $headers, Options $opt): bool
    {
        $sig = $headers['X-Iyz-Signature-V3'] ?? $headers['x-iyz-signature-v3'] ?? null;
        if (!$sig) {
            throw new InvalidArgumentException('X-Iyz-Signature-V3 header eksik.');
        }

        $type = (string) ($payload['iyziEventType'] ?? '');
        $pcid = (string) ($payload['paymentConversationId'] ?? '');
        $stat = (string) ($payload['status'] ?? '');
        $pid = (string) ($payload['paymentId'] ?? $payload['iyziPaymentId'] ?? '');
        $token = $payload['token'] ?? null;

        if ($token !== null) {
            // CO Form / Pay with iyzico
            return self::verifyWebhookV3CheckoutForm(
                $sig,
                $type,
                (string) $pid,          // iyziPaymentId
                (string) $token,
                $pcid,
                $stat,
                $opt
            );
        }

        // Direct API
        return self::verifyWebhookV3Api(
            $sig,
            $type,
            (string) $pid,             // paymentId
            $pcid,
            $stat,
            $opt
        );
    }
}
