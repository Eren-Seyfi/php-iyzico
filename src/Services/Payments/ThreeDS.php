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

use Iyzipay\Request\CreatePaymentRequest;          // 3DS INIT
use Iyzipay\Request\CreateThreedsPaymentRequest;   // 3DS AUTH

final class ThreeDS
{
    public function __construct(private Config $config)
    {
    }

    /**
     * 3D Secure Başlatma
     *
     * @param array{
     *   price:numeric-string|int|float,
     *   paidPrice?:numeric-string|int|float,
     *   currency?:string,
     *   installment?:int,
     *   basketId?:string,
     *   locale?:string,
     *   conversationId?:string,
     *   paymentChannel?:string,
     *   paymentGroup?:string
     * } $orderData
     * @param array<string,mixed> $buyerData
     * @param array<string,mixed> $shippingAddressData
     * @param array<string,mixed> $billingAddressData
     * @param array<int,array<string,mixed>> $basketItemsData
     */
    public function initialize3DS(
        array $orderData,
        PaymentCard $paymentCard,
        array $buyerData,
        array $shippingAddressData,
        array $billingAddressData,
        array $basketItemsData,
        string $callbackUrl
    ): ThreedsInitialize {
        $request = new CreatePaymentRequest();

        $request->setLocale($orderData['locale'] ?? $this->config->locale);
        $request->setConversationId($orderData['conversationId'] ?? $this->config->conversationId);

        // Zorunlu alanlar
        $request->setPrice((string) $orderData['price']);
        $request->setPaidPrice((string) ($orderData['paidPrice'] ?? $orderData['price']));
        $request->setCurrency($orderData['currency'] ?? Currency::TL);
        $request->setInstallment((int) ($orderData['installment'] ?? 1));

        // Opsiyoneller
        if (!empty($orderData['basketId'])) {
            $request->setBasketId((string) $orderData['basketId']);
        }

        $request->setPaymentChannel($orderData['paymentChannel'] ?? PaymentChannel::WEB);
        $request->setPaymentGroup($orderData['paymentGroup'] ?? PaymentGroup::PRODUCT);

        // Kart – Alıcı – Adresler – Sepet
        $request->setPaymentCard($paymentCard);
        $request->setBuyer(Helpers::buyer($buyerData));
        $request->setShippingAddress(Helpers::address($shippingAddressData));
        $request->setBillingAddress(Helpers::address($billingAddressData));

        $basketItems = [];
        foreach ($basketItemsData as $itemData) {
            $basketItems[] = Helpers::basketItem($itemData);
        }
        $request->setBasketItems($basketItems);

        // 3DS Init için zorunlu callback
        $request->setCallbackUrl($callbackUrl);

        return ThreedsInitialize::create($request, OptionsFactory::create($this->config));
    }

    /**
     * 3DS Başlatma + İmza Doğrulama
     *
     * Doküman sırası: [paymentId, conversationId]
     *
     * @return array{
     *   initializeResult: ThreedsInitialize,
     *   verified: bool,
     *   calculatedSignature: string
     * }
     */
    public function initialize3DSAndVerify(
        array $orderData,
        PaymentCard $paymentCard,
        array $buyerData,
        array $shippingAddressData,
        array $billingAddressData,
        array $basketItemsData,
        string $callbackUrl
    ): array {
        $options = OptionsFactory::create($this->config);

        $initializeResult = $this->initialize3DS(
            $orderData,
            $paymentCard,
            $buyerData,
            $shippingAddressData,
            $billingAddressData,
            $basketItemsData,
            $callbackUrl
        );

        $params = [
            (string) $initializeResult->getPaymentId(),
            (string) $initializeResult->getConversationId(),
        ];

        $calculatedSignature = Signature::calculate($params, (string) $options->getSecretKey());
        $verified = ((string) $initializeResult->getSignature() === $calculatedSignature);

        return [
            'initializeResult' => $initializeResult,
            'verified' => $verified,
            'calculatedSignature' => $calculatedSignature,
        ];
    }

    /**
     * 3DS Auth (Tamamlama)
     * callback'ten gelen paymentId + conversationData ile
     */
    public function complete3DS(string $paymentId, string $conversationData): ThreedsPayment
    {
        $request = new CreateThreedsPaymentRequest();
        $request->setLocale($this->config->locale);
        $request->setConversationId($this->config->conversationId);
        $request->setPaymentId($paymentId);
        $request->setConversationData($conversationData);

        return ThreedsPayment::create($request, OptionsFactory::create($this->config));
    }

    /**
     * 3DS Auth + Signature Verification
     *
     * Doküman imza sırası:
     * [paymentId, currency, basketId, conversationId, paidPrice, price]
     *
     * @return array{
     *   paymentResult: ThreedsPayment,
     *   verified: bool,
     *   calculatedSignature: string
     * }
     */
    public function complete3DSAndVerify(string $paymentId, string $conversationData): array
    {
        $options = OptionsFactory::create($this->config);
        $paymentResult = $this->complete3DS($paymentId, $conversationData);

        $params = [
            (string) $paymentResult->getPaymentId(),
            (string) $paymentResult->getCurrency(),
            (string) $paymentResult->getBasketId(),
            (string) $paymentResult->getConversationId(),
            (string) $paymentResult->getPaidPrice(),
            (string) $paymentResult->getPrice(),
        ];

        $calculatedSignature = Signature::calculate($params, (string) $options->getSecretKey());
        $verified = ((string) $paymentResult->getSignature() === $calculatedSignature);

        return [
            'paymentResult' => $paymentResult,
            'verified' => $verified,
            'calculatedSignature' => $calculatedSignature,
        ];
    }
}
