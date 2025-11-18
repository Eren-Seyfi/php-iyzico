<?php
declare(strict_types=1);

namespace Eren5\PhpIyzico\Services;

use Eren5\PhpIyzico\Config;
use Eren5\PhpIyzico\OptionsFactory;
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
    public function __construct(private Config $cfg)
    {
    }

    /**
     * Tam iptal (Cancel). Partial desteklemez.
     *
     * @param  string      $paymentId  İptal edilecek ödeme ID’si
     * @param  string|null $ip         Örn: "85.34.78.112"
     */
    public function cancel(
        string $paymentId,
        ?string $ip = null
    ): Cancel {
        $paymentId = $this->requireNonEmpty($paymentId, 'paymentId');

        $r = new CreateCancelRequest();
        $r->setLocale($this->cfg->locale);
        $r->setConversationId($this->cfg->conversationId);
        $r->setPaymentId($paymentId);

        if ($ip !== null && $ip !== '') {
            $r->setIp($ip);
        }

        return Cancel::create($r, OptionsFactory::create($this->cfg));
    }

    /**
     * Kısmi/Tam iade (transaction bazlı).
     * CreateRefundRequest reason/description alanlarını destekler.
     *
     * @param string              $paymentTransactionId
     * @param string|int|float    $price
     * @param string              $currency
     * @param string|null         $ip
     * @param string|null         $reason        \Iyzipay\Model\RefundReason::*
     * @param string|null         $description
     */
    public function refund(
        string $paymentTransactionId,
        string|int|float $price,
        string $currency = Currency::TL,
        ?string $ip = null,
        ?string $reason = null,
        ?string $description = null
    ): Refund {
        $paymentTransactionId = $this->requireNonEmpty($paymentTransactionId, 'paymentTransactionId');
        $normalizedPrice = $this->normalizePrice($price);
        $currency = $this->normalizeCurrency($currency);

        $r = new CreateRefundRequest();
        $r->setLocale($this->cfg->locale);
        $r->setConversationId($this->cfg->conversationId);
        $r->setPaymentTransactionId($paymentTransactionId);
        $r->setPrice($normalizedPrice);
        $r->setCurrency($currency);

        if ($ip !== null && $ip !== '') {
            $r->setIp($ip);
        }
        if ($reason !== null && $reason !== '') {
            $r->setReason($reason);
        }
        if ($description !== null && $description !== '') {
            $r->setDescription($description);
        }

        return Refund::create($r, OptionsFactory::create($this->cfg));
    }

    /**
     * Refund V2 – PaymentId + Amount (AmountBaseRefundRequest).
     * NOT: reason/description bu request'te yok.
     *
     * @param string            $paymentId
     * @param string|int|float  $price
     * @param string|null       $ip
     */
    public function amountBaseRefund(
        string $paymentId,
        string|int|float $price,
        ?string $ip = null
    ): AmountBaseRefund {
        $paymentId = $this->requireNonEmpty($paymentId, 'paymentId');
        $normalizedPrice = $this->normalizePrice($price);

        $r = new AmountBaseRefundRequest();
        $r->setLocale($this->cfg->locale);
        $r->setConversationId($this->cfg->conversationId);
        $r->setPaymentId($paymentId);
        // SDK bu alanda numerik bekliyor; normalize edilmiş string'i float'a çeviriyoruz.
        $r->setPrice((float) $normalizedPrice);

        if ($ip !== null && $ip !== '') {
            $r->setIp($ip);
        }

        return AmountBaseRefund::create($r, OptionsFactory::create($this->cfg));
    }

    // -------------------
    // Helpers
    // -------------------

    private function requireNonEmpty(?string $val, string $field): string
    {
        $val = trim((string) $val);
        if ($val === '') {
            throw new InvalidArgumentException("$field boş olamaz.");
        }
        return $val;
    }

    private function normalizePrice(string|int|float $price): string
    {
        if (is_string($price)) {
            $price = str_replace(',', '.', trim($price));
        }
        $num = (float) $price;
        if ($num <= 0) {
            throw new InvalidArgumentException('Tutar 0’dan büyük olmalıdır.');
        }
        return number_format($num, 2, '.', '');
    }

    private function normalizeCurrency(string $currency): string
    {
        $currency = strtoupper(trim($currency));
        $allowed = [Currency::TL, Currency::EUR, Currency::USD, Currency::GBP, Currency::IRR];
        if (!in_array($currency, $allowed, true)) {
            throw new InvalidArgumentException('Geçersiz para birimi: ' . $currency);
        }
        return $currency;
    }
}
