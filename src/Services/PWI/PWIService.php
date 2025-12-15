<?php
declare(strict_types=1);

namespace Eren5\PhpIyzico\Services\PWI;

use Eren5\PhpIyzico\Config;
use Eren5\PhpIyzico\OptionsFactory;
use Eren5\PhpIyzico\Security\Signature;

// Iyzipay Core
use Iyzipay\Options;
use Iyzipay\Model\Locale;
use Iyzipay\Model\Currency;
use Iyzipay\Model\PaymentGroup;
use Iyzipay\Model\BasketItemType;

// Iyzipay Domain Models
use Iyzipay\Model\Buyer;
use Iyzipay\Model\Address;
use Iyzipay\Model\BasketItem;

// PWI Requests & Models
use Iyzipay\Request\CreatePayWithIyzicoInitializeRequest;
use Iyzipay\Request\RetrievePayWithIyzicoRequest;
use Iyzipay\Model\PayWithIyzicoInitialize;
use Iyzipay\Model\PayWithIyzico;

/**
 * Pay with iyzico (PWI) yüksek seviyeli servis sarmalayıcı.
 */
final class PWIService
{
    private Options $options;

    public function __construct(
        private Config $config,
        private Signature $signature
    ) {
        $this->options = OptionsFactory::create($this->config);
    }

    /**
     * PWI Başlatma
     */
    public function create(array $requestData): array
    {
        // Zorunlu alan kontrolü
        $requiredFields = [
            'price',
            'paidPrice',
            'currency',
            'basketId',
            'paymentGroup',
            'callbackUrl',
            'buyer',
            'shippingAddress',
            'billingAddress',
            'basketItems'
        ];

        foreach ($requiredFields as $fieldName) {
            if (!array_key_exists($fieldName, $requestData)) {
                throw new \InvalidArgumentException("Missing required field: {$fieldName}");
            }
        }

        $initializeRequest = new CreatePayWithIyzicoInitializeRequest();
        $initializeRequest->setLocale($requestData['locale'] ?? Locale::TR);
        $initializeRequest->setConversationId($requestData['conversationId'] ?? (string) microtime(true));
        $initializeRequest->setPrice((string) $requestData['price']);
        $initializeRequest->setPaidPrice((string) $requestData['paidPrice']);

        // Eğer currency string gelmişse TRY varsayımı
        $initializeRequest->setCurrency(
            is_string($requestData['currency']) ? Currency::TL : $requestData['currency']
        );

        $initializeRequest->setBasketId((string) $requestData['basketId']);

        // PaymentGroup string gelmişse PRODUCT varsayımı
        $initializeRequest->setPaymentGroup(
            is_string($requestData['paymentGroup']) ? PaymentGroup::PRODUCT : $requestData['paymentGroup']
        );

        $initializeRequest->setCallbackUrl((string) $requestData['callbackUrl']);

        if (!empty($requestData['enabledInstallments'])) {
            $initializeRequest->setEnabledInstallments(
                array_map('intval', (array) $requestData['enabledInstallments'])
            );
        }

        $initializeRequest->setBuyer($this->mapBuyer($requestData['buyer']));
        $initializeRequest->setShippingAddress($this->mapAddress($requestData['shippingAddress']));
        $initializeRequest->setBillingAddress($this->mapAddress($requestData['billingAddress']));
        $initializeRequest->setBasketItems($this->mapBasketItems($requestData['basketItems']));

        $response = PayWithIyzicoInitialize::create($initializeRequest, $this->options);

        // İmza doğrulama
        $conversationId = (string) $response->getConversationId();
        $token = (string) $response->getToken();
        $serverSignature = (string) $response->getSignature();

        $calculatedSignature = $this->signature::calculateColonSeparated(
            [$conversationId, $token],
            (string) $this->options->getSecretKey()
        );

        $verified = hash_equals($serverSignature, $calculatedSignature);

        return $this->normalizeResponse($response) + [
            'verified' => $verified
        ];
    }

    /**
     * @deprecated Eski ad. Yeni adı: create()
     */
    public function initialize(array $data): array
    {
        return $this->create($data);
    }

    /**
     * PWI Sonuç Getirme
     */
    public function retrieve(string $token, ?string $conversationId = null, ?string $locale = null): array
    {
        $retrieveRequest = new RetrievePayWithIyzicoRequest();
        $retrieveRequest->setLocale($locale ?? Locale::TR);
        $retrieveRequest->setConversationId($conversationId ?? (string) microtime(true));
        $retrieveRequest->setToken($token);

        $response = PayWithIyzico::retrieve($retrieveRequest, $this->options);

        // İmza doğrulama
        $serverSignature = (string) $response->getSignature();

        $signatureParts = [
            (string) $response->getPaymentStatus(),
            (string) $response->getPaymentId(),
            (string) $response->getCurrency(),
            (string) $response->getBasketId(),
            (string) $response->getConversationId(),
            (string) $response->getPaidPrice(),
            (string) $response->getPrice(),
            (string) $response->getToken(),
        ];

        $calculatedSignature = $this->signature::calculateColonSeparated(
            $signatureParts,
            (string) $this->options->getSecretKey()
        );

        $verified = hash_equals($serverSignature, $calculatedSignature);

        return $this->normalizeResponse($response) + [
            'verified' => $verified
        ];
    }

    // ---------------------------------------
    // Model Mapping Helpers
    // ---------------------------------------

    private function mapBuyer(array $buyerData): Buyer
    {
        $buyerModel = new Buyer();

        isset($buyerData['id']) && $buyerModel->setId((string) $buyerData['id']);
        isset($buyerData['name']) && $buyerModel->setName((string) $buyerData['name']);
        isset($buyerData['surname']) && $buyerModel->setSurname((string) $buyerData['surname']);
        isset($buyerData['gsmNumber']) && $buyerModel->setGsmNumber((string) $buyerData['gsmNumber']);
        isset($buyerData['email']) && $buyerModel->setEmail((string) $buyerData['email']);
        isset($buyerData['identityNumber']) && $buyerModel->setIdentityNumber((string) $buyerData['identityNumber']);
        isset($buyerData['lastLoginDate']) && $buyerModel->setLastLoginDate((string) $buyerData['lastLoginDate']);
        isset($buyerData['registrationDate']) && $buyerModel->setRegistrationDate((string) $buyerData['registrationDate']);
        isset($buyerData['registrationAddress']) && $buyerModel->setRegistrationAddress((string) $buyerData['registrationAddress']);
        isset($buyerData['ip']) && $buyerModel->setIp((string) $buyerData['ip']);
        isset($buyerData['city']) && $buyerModel->setCity((string) $buyerData['city']);
        isset($buyerData['country']) && $buyerModel->setCountry((string) $buyerData['country']);
        isset($buyerData['zipCode']) && $buyerModel->setZipCode((string) $buyerData['zipCode']);

        return $buyerModel;
    }

    private function mapAddress(array $addressData): Address
    {
        $addressModel = new Address();

        isset($addressData['contactName']) && $addressModel->setContactName((string) $addressData['contactName']);
        isset($addressData['city']) && $addressModel->setCity((string) $addressData['city']);
        isset($addressData['country']) && $addressModel->setCountry((string) $addressData['country']);
        isset($addressData['address']) && $addressModel->setAddress((string) $addressData['address']);
        isset($addressData['zipCode']) && $addressModel->setZipCode((string) $addressData['zipCode']);

        return $addressModel;
    }

    private function mapBasketItems(array $basketItemsData): array
    {
        $basketItemModels = [];

        foreach ($basketItemsData as $index => $itemData) {
            $basketItem = new BasketItem();

            isset($itemData['id']) && $basketItem->setId((string) $itemData['id']);
            isset($itemData['name']) && $basketItem->setName((string) $itemData['name']);
            isset($itemData['category1']) && $basketItem->setCategory1((string) $itemData['category1']);
            isset($itemData['category2']) && $basketItem->setCategory2((string) $itemData['category2']);

            if (isset($itemData['itemType'])) {
                $basketItem->setItemType(
                    is_string($itemData['itemType'])
                    ? BasketItemType::PHYSICAL
                    : $itemData['itemType']
                );
            }

            isset($itemData['price']) && $basketItem->setPrice((string) $itemData['price']);

            $basketItemModels[$index] = $basketItem;
        }

        return array_values($basketItemModels);
    }

    // ---------------------------------------
    // Normalize Helpers
    // ---------------------------------------

    private function normalizeResponse(object $response): array
    {
        if (method_exists($response, 'getRawResult')) {
            $rawJson = $response->getRawResult();
            if (is_string($rawJson) && $rawJson !== '') {
                $decoded = json_decode($rawJson, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    return $decoded;
                }
                return ['rawResult' => $rawJson];
            }
        }

        $json = json_encode($response, JSON_UNESCAPED_UNICODE);

        if ($json !== false) {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return ['result' => print_r($response, true)];
    }
}
