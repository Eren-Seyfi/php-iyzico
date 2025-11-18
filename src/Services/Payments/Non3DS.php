<?php
declare(strict_types=1);

namespace Eren5\PhpIyzico\Services\Payments;

use Eren5\PhpIyzico\Config;
use Eren5\PhpIyzico\OptionsFactory;
use Eren5\PhpIyzico\Support\Helpers;
use Eren5\PhpIyzico\Security\Signature;

use Iyzipay\Model\Currency;
use Iyzipay\Model\Payment;
use Iyzipay\Model\PaymentCard;
use Iyzipay\Model\PaymentChannel;
use Iyzipay\Model\PaymentGroup;

use Iyzipay\Request\CreatePaymentRequest;
use Iyzipay\Request\RetrievePaymentRequest;

final class Non3DS
{
    public function __construct(private Config $cfg)
    {
    }

    /**
     * Non3D ödeme oluştur.
     *
     * @param array{
     *   price:numeric-string|int|float,
     *   paidPrice?:numeric-string|int|float,
     *   currency?:string,                // Iyzipay\Model\Currency::TL|USD|EUR ...
     *   installment?:int,                // 1..12
     *   basketId?:string,
     *   locale?:string,
     *   conversationId?:string,
     *   paymentChannel?:string,          // Iyzipay\Model\PaymentChannel::WEB ...
     *   paymentGroup?:string             // Iyzipay\Model\PaymentGroup::PRODUCT ...
     * } $order
     * @param PaymentCard $card
     * @param array<string,mixed> $buyer
     * @param array<string,mixed> $shipAddress
     * @param array<string,mixed> $billAddress
     * @param array<int,array<string,mixed>> $basketItems
     */
    public function pay(
        array $order,
        PaymentCard $card,
        array $buyer,
        array $shipAddress,
        array $billAddress,
        array $basketItems
    ): Payment {
        $r = new CreatePaymentRequest();
        $r->setLocale($order['locale'] ?? $this->cfg->locale);
        $r->setConversationId($order['conversationId'] ?? $this->cfg->conversationId);

        // Zorunlular
        $r->setPrice((string) $order['price']);
        $r->setPaidPrice((string) ($order['paidPrice'] ?? $order['price']));
        $r->setCurrency($order['currency'] ?? Currency::TL);
        $r->setInstallment((int) ($order['installment'] ?? 1));

        // Opsiyoneller
        if (!empty($order['basketId'])) {
            $r->setBasketId((string) $order['basketId']);
        }
        $r->setPaymentChannel($order['paymentChannel'] ?? PaymentChannel::WEB);
        $r->setPaymentGroup($order['paymentGroup'] ?? PaymentGroup::PRODUCT);

        // Parçalar
        $r->setPaymentCard($card);
        $r->setBuyer(Helpers::buyer($buyer));
        $r->setShippingAddress(Helpers::address($shipAddress));
        $r->setBillingAddress(Helpers::address($billAddress));

        $items = [];
        foreach ($basketItems as $it) {
            $items[] = Helpers::basketItem($it);
        }
        $r->setBasketItems($items);

        return Payment::create($r, OptionsFactory::create($this->cfg));
    }

    /**
     * Ödeme + imza doğrulama (dokümandaki sırayla).
     * @return array{payment: Payment, verified: bool, calculated: string}
     */
    public function payAndVerify(
        array $order,
        PaymentCard $card,
        array $buyer,
        array $shipAddress,
        array $billAddress,
        array $basketItems
    ): array {
        $opt = OptionsFactory::create($this->cfg);
        $payment = $this->pay($order, $card, $buyer, $shipAddress, $billAddress, $basketItems);

        $params = [
            (string) $payment->getPaymentId(),
            (string) $payment->getCurrency(),
            (string) $payment->getBasketId(),
            (string) $payment->getConversationId(),
            (string) $payment->getPaidPrice(),
            (string) $payment->getPrice(),
        ];
        $calc = Signature::calculate($params, (string) $opt->getSecretKey());
        $verified = ((string) $payment->getSignature() === $calc);

        return ['payment' => $payment, 'verified' => $verified, 'calculated' => $calc];
    }

    /**
     * Ödeme sorgulama (Retrieve Payment Result).
     *
     * @param array{
     *   paymentId?:string,
     *   paymentConversationId?:string,
     *   locale?:string,
     *   conversationId?:string
     * } $query
     */
    public function retrieve(array $query): Payment
    {
        $r = new RetrievePaymentRequest();
        $r->setLocale($query['locale'] ?? $this->cfg->locale);
        $r->setConversationId($query['conversationId'] ?? $this->cfg->conversationId);

        if (!empty($query['paymentId'])) {
            $r->setPaymentId((string) $query['paymentId']);
        }
        if (!empty($query['paymentConversationId'])) {
            $r->setPaymentConversationId((string) $query['paymentConversationId']);
        }

        return Payment::retrieve($r, OptionsFactory::create($this->cfg));
    }

    /**
     * Ödeme sorgulama + imza doğrulama.
     * @return array{payment: Payment, verified: bool, calculated: string}
     */
    public function retrieveAndVerify(array $query): array
    {
        $opt = OptionsFactory::create($this->cfg);
        $payment = $this->retrieve($query);

        $params = [
            (string) $payment->getPaymentId(),
            (string) $payment->getCurrency(),
            (string) $payment->getBasketId(),
            (string) $payment->getConversationId(),
            (string) $payment->getPaidPrice(),
            (string) $payment->getPrice(),
        ];
        $calc = Signature::calculate($params, (string) $opt->getSecretKey());
        $verified = ((string) $payment->getSignature() === $calc);

        return ['payment' => $payment, 'verified' => $verified, 'calculated' => $calc];
    }
}
