<?php
declare(strict_types=1);

namespace Eren5\PhpIyzico\Services\Payments;

use Eren5\PhpIyzico\Config;
use Eren5\PhpIyzico\OptionsFactory;
use Eren5\PhpIyzico\Support\Helpers;
use Eren5\PhpIyzico\Security\Signature;

use Iyzipay\Model\Currency;
use Iyzipay\Model\PaymentCard;
use Iyzipay\Model\PaymentChannel;
use Iyzipay\Model\PaymentGroup;
use Iyzipay\Model\ThreedsInitialize;
use Iyzipay\Model\ThreedsPayment;

use Iyzipay\Request\CreatePaymentRequest;         // 3DS INIT
use Iyzipay\Request\CreateThreedsPaymentRequest; // 3DS AUTH

final class ThreeDS
{
    public function __construct(private Config $cfg)
    {
    }

    /**
     * 3DS Başlatma (ThreedsInitialize)
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
     * @param array<string,mixed> $buyer
     * @param array<string,mixed> $shipAddress
     * @param array<string,mixed> $billAddress
     * @param array<int,array<string,mixed>> $basketItems
     */
    public function init(
        array $order,
        PaymentCard $card,
        array $buyer,
        array $shipAddress,
        array $billAddress,
        array $basketItems,
        string $callbackUrl
    ): ThreedsInitialize {
        $r = new CreatePaymentRequest(); // SDK: 3DS init bu istekle

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

        // Kart, alıcı, adresler, sepet
        $r->setPaymentCard($card);
        $r->setBuyer(Helpers::buyer($buyer));
        $r->setShippingAddress(Helpers::address($shipAddress));
        $r->setBillingAddress(Helpers::address($billAddress));

        $items = [];
        foreach ($basketItems as $it) {
            $items[] = Helpers::basketItem($it);
        }
        $r->setBasketItems($items);

        // 3DS init için zorunlu
        $r->setCallbackUrl($callbackUrl);

        return ThreedsInitialize::create($r, OptionsFactory::create($this->cfg));
    }

    /**
     * 3DS Başlatma + İmza Doğrulama
     * Doküman sırası: [paymentId, conversationId]
     * @return array{init: ThreedsInitialize, verified: bool, calculated: string}
     */
    public function initAndVerify(
        array $order,
        PaymentCard $card,
        array $buyer,
        array $shipAddress,
        array $billAddress,
        array $basketItems,
        string $callbackUrl
    ): array {
        $opt = OptionsFactory::create($this->cfg);
        $init = $this->init($order, $card, $buyer, $shipAddress, $billAddress, $basketItems, $callbackUrl);

        $params = [
            (string) $init->getPaymentId(),
            (string) $init->getConversationId(),
        ];
        $calc = Signature::calculate($params, (string) $opt->getSecretKey());
        $verified = ((string) $init->getSignature() === $calc);

        return ['init' => $init, 'verified' => $verified, 'calculated' => $calc];
    }

    /**
     * 3DS Tamamlama (ThreedsPayment)
     * callback'ten gelen paymentId + conversationData ile
     */
    public function auth(string $paymentId, string $conversationData): ThreedsPayment
    {
        $r = new CreateThreedsPaymentRequest();
        $r->setLocale($this->cfg->locale);
        $r->setConversationId($this->cfg->conversationId);
        $r->setPaymentId($paymentId);
        $r->setConversationData($conversationData);

        return ThreedsPayment::create($r, OptionsFactory::create($this->cfg));
    }

    /**
     * 3DS Tamamlama + İmza Doğrulama
     * Doküman sırası: [paymentId, currency, basketId, conversationId, paidPrice, price]
     * @return array{payment: ThreedsPayment, verified: bool, calculated: string}
     */
    public function authAndVerify(string $paymentId, string $conversationData): array
    {
        $opt = OptionsFactory::create($this->cfg);
        $payment = $this->auth($paymentId, $conversationData);

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
