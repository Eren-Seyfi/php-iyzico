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
    public function __construct(private Config $config)
    {
    }

    /**
     * Ödeme detayını getirir.
     *
     * En az bir parametre zorunludur:
     *  - $paymentId
     *  - $paymentConversationId
     */
    public function paymentDetail(
        ?string $paymentId = null,
        ?string $paymentConversationId = null
    ): Payment {
        if ($paymentId === null && $paymentConversationId === null) {
            throw new InvalidArgumentException(
                'paymentId veya paymentConversationId parametrelerinden en az biri verilmelidir.'
            );
        }

        $paymentRequest = new RetrievePaymentRequest();
        $paymentRequest->setLocale($this->config->locale);
        $paymentRequest->setConversationId($this->config->conversationId);

        if ($paymentId !== null) {
            $paymentRequest->setPaymentId($paymentId);
        }

        if ($paymentConversationId !== null) {
            $paymentRequest->setPaymentConversationId($paymentConversationId);
        }

        return Payment::retrieve(
            $paymentRequest,
            OptionsFactory::create($this->config)
        );
    }

    /**
     * İmza doğrulama için gerekli alanları döndürür.
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
     * Dışarıdan verilen imza hesaplayıcı (callable) ile imzayı doğrular.
     */
    public function verifySignatureWith(callable $signatureCalculator, Payment $payment): bool
    {
        $calculatedSignature = $signatureCalculator(
            $this->signatureFields($payment)
        );

        return $payment->getSignature() === $calculatedSignature;
    }
}
