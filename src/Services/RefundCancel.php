<?php
declare(strict_types=1);

namespace Eren\PhpIyzico\Services;

use Eren\PhpIyzico\Config;
use Eren\PhpIyzico\OptionsFactory;
use Iyzipay\Model\Cancel;
use Iyzipay\Model\Currency;
use Iyzipay\Model\Refund;
use Iyzipay\Model\AmountBaseRefund;
use Iyzipay\Request\CreateCancelRequest;
use Iyzipay\Request\CreateRefundRequest;
use Iyzipay\Request\AmountBaseRefundRequest;
use InvalidArgumentException;

final class RefundCancel
{
    public function __construct(private Config $config)
    {
    }

    /**
     * Tam iptal (Cancel). Partial desteklemez.
     *
     * @param string      $paymentId  İptal edilecek ödeme ID’si
     * @param string|null $ipAddress  Müşterinin IP adresi ("85.34.78.112" gibi)
     */
    public function cancel(
        string $paymentId,
        ?string $ipAddress = null
    ): Cancel {
        $paymentId = $this->requireNonEmpty($paymentId, 'paymentId');

        $cancelRequest = new CreateCancelRequest();
        $cancelRequest->setLocale($this->config->locale);
        $cancelRequest->setConversationId($this->config->conversationId);
        $cancelRequest->setPaymentId($paymentId);

        if ($ipAddress !== null && $ipAddress !== '') {
            $cancelRequest->setIp($ipAddress);
        }

        return Cancel::create($cancelRequest, OptionsFactory::create($this->config));
    }

    /**
     * Kısmi/Tam iade (transaction bazlı).
     * CreateRefundRequest reason/description alanlarını destekler.
     */
    public function refund(
        string $paymentTransactionId,
        string|int|float $price,
        string $currency = Currency::TL,
        ?string $ipAddress = null,
        ?string $refundReason = null,
        ?string $refundDescription = null
    ): Refund {
        $paymentTransactionId = $this->requireNonEmpty($paymentTransactionId, 'paymentTransactionId');
        $normalizedPrice = $this->normalizePrice($price);
        $normalizedCurrency = $this->normalizeCurrency($currency);

        $refundRequest = new CreateRefundRequest();
        $refundRequest->setLocale($this->config->locale);
        $refundRequest->setConversationId($this->config->conversationId);
        $refundRequest->setPaymentTransactionId($paymentTransactionId);
        $refundRequest->setPrice($normalizedPrice);
        $refundRequest->setCurrency($normalizedCurrency);

        if ($ipAddress !== null && $ipAddress !== '') {
            $refundRequest->setIp($ipAddress);
        }
        if ($refundReason !== null && $refundReason !== '') {
            $refundRequest->setReason($refundReason);
        }
        if ($refundDescription !== null && $refundDescription !== '') {
            $refundRequest->setDescription($refundDescription);
        }

        return Refund::create($refundRequest, OptionsFactory::create($this->config));
    }

    /**
     * Refund V2 – PaymentId + Amount (AmountBaseRefundRequest).
     * NOT: reason/description bu request'te yok.
     */
    public function amountBaseRefund(
        string $paymentId,
        string|int|float $price,
        ?string $ipAddress = null
    ): AmountBaseRefund {
        $paymentId = $this->requireNonEmpty($paymentId, 'paymentId');
        $normalizedPrice = $this->normalizePrice($price);

        $amountBaseRefundRequest = new AmountBaseRefundRequest();
        $amountBaseRefundRequest->setLocale($this->config->locale);
        $amountBaseRefundRequest->setConversationId($this->config->conversationId);
        $amountBaseRefundRequest->setPaymentId($paymentId);
        $amountBaseRefundRequest->setPrice((float) $normalizedPrice);

        if ($ipAddress !== null && $ipAddress !== '') {
            $amountBaseRefundRequest->setIp($ipAddress);
        }

        return AmountBaseRefund::create($amountBaseRefundRequest, OptionsFactory::create($this->config));
    }

    // -------------------
    // Helpers
    // -------------------

    private function requireNonEmpty(?string $value, string $fieldName): string
    {
        $trimmedValue = trim((string) $value);

        if ($trimmedValue === '') {
            throw new InvalidArgumentException("$fieldName boş olamaz.");
        }

        return $trimmedValue;
    }

    private function normalizePrice(string|int|float $price): string
    {
        if (is_string($price)) {
            $price = str_replace(',', '.', trim($price));
        }

        $numericPrice = (float) $price;

        if ($numericPrice <= 0) {
            throw new InvalidArgumentException('Tutar 0’dan büyük olmalıdır.');
        }

        return number_format($numericPrice, 2, '.', '');
    }

    private function normalizeCurrency(string $currency): string
    {
        $normalizedCurrency = strtoupper(trim($currency));

        $allowedCurrencies = [
            Currency::TL,
            Currency::EUR,
            Currency::USD,
            Currency::GBP,
            Currency::IRR
        ];

        if (!in_array($normalizedCurrency, $allowedCurrencies, true)) {
            throw new InvalidArgumentException('Geçersiz para birimi: ' . $normalizedCurrency);
        }

        return $normalizedCurrency;
    }
}
