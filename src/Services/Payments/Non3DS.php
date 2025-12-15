<?php
declare(strict_types=1);

namespace Eren\PhpIyzico\Services\Payments;

use Eren\PhpIyzico\Config;
use Eren\PhpIyzico\OptionsFactory;
use Eren\PhpIyzico\Support\Helpers;
use Eren\PhpIyzico\Security\Signature;

use Iyzipay\Model\Currency;
use Iyzipay\Model\Payment;
use Iyzipay\Model\PaymentCard;
use Iyzipay\Model\PaymentChannel;
use Iyzipay\Model\PaymentGroup;

use Iyzipay\Request\CreatePaymentRequest;
use Iyzipay\Request\RetrievePaymentRequest;

final class Non3DS
{
    public function __construct(private Config $config)
    {
    }

    /**
     * Non-3D ödeme oluşturma.
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
     *
     * @param PaymentCard $paymentCard
     * @param array<string,mixed> $buyerData
     * @param array<string,mixed> $shippingAddressData
     * @param array<string,mixed> $billingAddressData
     * @param array<int,array<string,mixed>> $basketItemsData
     */
    public function createPayment(
        array $orderData,
        PaymentCard $paymentCard,
        array $buyerData,
        array $shippingAddressData,
        array $billingAddressData,
        array $basketItemsData
    ): Payment {
        $request = new CreatePaymentRequest();

        $request->setLocale($orderData['locale'] ?? $this->config->locale);
        $request->setConversationId($orderData['conversationId'] ?? $this->config->conversationId);

        // Zorunlular
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

        // Buyer – Card – Address – Items
        $request->setPaymentCard($paymentCard);
        $request->setBuyer(Helpers::buyer($buyerData));
        $request->setShippingAddress(Helpers::address($shippingAddressData));
        $request->setBillingAddress(Helpers::address($billingAddressData));

        $basketItems = [];
        foreach ($basketItemsData as $itemData) {
            $basketItems[] = Helpers::basketItem($itemData);
        }
        $request->setBasketItems($basketItems);

        return Payment::create($request, OptionsFactory::create($this->config));
    }

    /**
     * Non3DS Payment + Signature Verification (dokümandaki sırayla)
     *
     * @return array{
     *   payment: Payment,
     *   verified: bool,
     *   calculatedSignature: string
     * }
     */
    public function createPaymentAndVerify(
        array $orderData,
        PaymentCard $paymentCard,
        array $buyerData,
        array $shippingAddressData,
        array $billingAddressData,
        array $basketItemsData
    ): array {
        $options = OptionsFactory::create($this->config);
        $paymentResult = $this->createPayment(
            $orderData,
            $paymentCard,
            $buyerData,
            $shippingAddressData,
            $billingAddressData,
            $basketItemsData
        );

        // Doküman imza sırası
        $partsToSign = [
            (string) $paymentResult->getPaymentId(),
            (string) $paymentResult->getCurrency(),
            (string) $paymentResult->getBasketId(),
            (string) $paymentResult->getConversationId(),
            (string) $paymentResult->getPaidPrice(),
            (string) $paymentResult->getPrice(),
        ];

        $calculatedSignature = Signature::calculate($partsToSign, (string) $options->getSecretKey());
        $verified = ((string) $paymentResult->getSignature() === $calculatedSignature);

        return [
            'payment' => $paymentResult,
            'verified' => $verified,
            'calculatedSignature' => $calculatedSignature
        ];
    }

    /**
     * Ödeme sorgulama (RetrievePayment)
     *
     * @param array{
     *   paymentId?:string,
     *   paymentConversationId?:string,
     *   locale?:string,
     *   conversationId?:string
     * } $queryData
     */
    public function retrievePayment(array $queryData): Payment
    {
        $request = new RetrievePaymentRequest();

        $request->setLocale($queryData['locale'] ?? $this->config->locale);
        $request->setConversationId($queryData['conversationId'] ?? $this->config->conversationId);

        if (!empty($queryData['paymentId'])) {
            $request->setPaymentId((string) $queryData['paymentId']);
        }
        if (!empty($queryData['paymentConversationId'])) {
            $request->setPaymentConversationId((string) $queryData['paymentConversationId']);
        }

        return Payment::retrieve($request, OptionsFactory::create($this->config));
    }

    /**
     * Retrieve + Signature Verification
     *
     * @return array{
     *   payment: Payment,
     *   verified: bool,
     *   calculatedSignature: string
     * }
     */
    public function retrievePaymentAndVerify(array $queryData): array
    {
        $options = OptionsFactory::create($this->config);
        $paymentResult = $this->retrievePayment($queryData);

        $partsToSign = [
            (string) $paymentResult->getPaymentId(),
            (string) $paymentResult->getCurrency(),
            (string) $paymentResult->getBasketId(),
            (string) $paymentResult->getConversationId(),
            (string) $paymentResult->getPaidPrice(),
            (string) $paymentResult->getPrice(),
        ];

        $calculatedSignature = Signature::calculate($partsToSign, (string) $options->getSecretKey());
        $verified = ((string) $paymentResult->getSignature() === $calculatedSignature);

        return [
            'payment' => $paymentResult,
            'verified' => $verified,
            'calculatedSignature' => $calculatedSignature
        ];
    }
}
