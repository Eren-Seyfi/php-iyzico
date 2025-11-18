<?php
declare(strict_types=1);

namespace Eren5\PhpIyzico\Services;

use Eren5\PhpIyzico\Config;
use Eren5\PhpIyzico\OptionsFactory;
use Iyzipay\Model\Payment;
use Iyzipay\Request\RetrievePaymentRequest;
use InvalidArgumentException;

final class Status
{
    public function __construct(private Config $cfg)
    {
    }

    /**
     * Ödeme detayını getirir.
     * SDK örneği: RetrievePaymentRequest (paymentId ve/veya paymentConversationId ile)
     *
     * En az birini vermek zorunlu:
     *  - $paymentId
     *  - $paymentConversationId
     */
    public function paymentDetail(?string $paymentId = null, ?string $paymentConversationId = null): Payment
    {
        if ($paymentId === null && $paymentConversationId === null) {
            throw new InvalidArgumentException('paymentId veya paymentConversationId parametrelerinden en az biri verilmelidir.');
        }

        $r = new RetrievePaymentRequest();
        $r->setLocale($this->cfg->locale);
        $r->setConversationId($this->cfg->conversationId);

        if ($paymentId !== null) {
            $r->setPaymentId($paymentId);
        }
        if ($paymentConversationId !== null) {
            $r->setPaymentConversationId($paymentConversationId);
        }

        return Payment::retrieve($r, OptionsFactory::create($this->cfg));
    }

    /**
     * İmza doğrulama için gereken alanları örnek sırada döndürür:
     * [$paymentId, $currency, $basketId, $conversationId, $paidPrice, $price]
     */
    public function signatureFields(Payment $payment): array
    {
        return [
            $payment->getPaymentId(),
            $payment->getCurrency(),
            $payment->getBasketId(),
            $payment->getConversationId(),
            $payment->getPaidPrice(),
            $payment->getPrice(),
        ];
    }

    /**
     * Dışarıdan verilen hesaplayıcı ile (ör. calculateHmacSHA256Signature) imzayı doğrular.
     * $calculator, signatureFields(array) alıp string döndürmelidir.
     */
    public function verifySignatureWith(callable $calculator, Payment $payment): bool
    {
        $calc = $calculator($this->signatureFields($payment));
        return $payment->getSignature() === $calc;
    }
}
