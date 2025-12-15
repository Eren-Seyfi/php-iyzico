<?php
declare(strict_types=1);

namespace Eren\PhpIyzico\Services;

use Eren\PhpIyzico\Config;
use Eren\PhpIyzico\OptionsFactory;
use Iyzipay\Model\Address;
use Iyzipay\Model\BasketItem;
use Iyzipay\Model\BasketItemType;
use Iyzipay\Model\Buyer;
use Iyzipay\Model\Card;
use Iyzipay\Model\CardInformation;
use Iyzipay\Model\CardList;
use Iyzipay\Model\Currency;
use Iyzipay\Model\Payment;
use Iyzipay\Model\PaymentChannel;
use Iyzipay\Model\PaymentGroup;
use Iyzipay\Request\CreateCardRequest;
use Iyzipay\Request\CreatePaymentRequest;
use Iyzipay\Request\DeleteCardRequest;
use Iyzipay\Request\RetrieveCardListRequest;
use InvalidArgumentException;

final class CardStorage
{
    public function __construct(private Config $config)
    {
    }

    /**
     * Kullanıcı oluşturup ilk kartı ekler (create_user_and_add_card)
     */
    public function createUserAndAddCard(string $email, string $externalId, array $cardInformation): Card
    {
        $email = $this->requireNonEmpty($email, 'email');
        $externalId = $this->requireNonEmpty($externalId, 'externalId');

        $createCardRequest = new CreateCardRequest();
        $createCardRequest->setLocale($this->config->locale);
        $createCardRequest->setConversationId($this->config->conversationId);
        $createCardRequest->setEmail($email);
        $createCardRequest->setExternalId($externalId);
        $createCardRequest->setCard($this->buildCardInformation($cardInformation));

        return Card::create($createCardRequest, OptionsFactory::create($this->config));
    }

    /**
     * Var olan kullanıcıya yeni kart ekler.
     */
    public function addCard(string $cardUserKey, array $cardInformation): Card
    {
        $cardUserKey = $this->requireNonEmpty($cardUserKey, 'cardUserKey');

        $createCardRequest = new CreateCardRequest();
        $createCardRequest->setLocale($this->config->locale);
        $createCardRequest->setConversationId($this->config->conversationId);
        $createCardRequest->setCardUserKey($cardUserKey);
        $createCardRequest->setCard($this->buildCardInformation($cardInformation));

        return Card::create($createCardRequest, OptionsFactory::create($this->config));
    }

    /**
     * Kart listesini döndürür.
     */
    public function list(string $cardUserKey): CardList
    {
        $cardUserKey = $this->requireNonEmpty($cardUserKey, 'cardUserKey');

        $retrieveCardListRequest = new RetrieveCardListRequest();
        $retrieveCardListRequest->setLocale($this->config->locale);
        $retrieveCardListRequest->setConversationId($this->config->conversationId);
        $retrieveCardListRequest->setCardUserKey($cardUserKey);

        return CardList::retrieve($retrieveCardListRequest, OptionsFactory::create($this->config));
    }

    /**
     * Kartı siler.
     */
    public function delete(string $cardUserKey, string $cardToken): Card
    {
        $cardUserKey = $this->requireNonEmpty($cardUserKey, 'cardUserKey');
        $cardToken = $this->requireNonEmpty($cardToken, 'cardToken');

        $deleteCardRequest = new DeleteCardRequest();
        $deleteCardRequest->setLocale($this->config->locale);
        $deleteCardRequest->setConversationId($this->config->conversationId);
        $deleteCardRequest->setCardUserKey($cardUserKey);
        $deleteCardRequest->setCardToken($cardToken);

        return Card::delete($deleteCardRequest, OptionsFactory::create($this->config));
    }

    /**
     * Saklı kart ile NON-3D ödeme alma
     */
    public function payWithSavedCardNon3D(array $payload): Payment
    {
        $cardUserKey = $this->requireNonEmpty((string) ($payload['cardUserKey'] ?? ''), 'cardUserKey');
        $cardToken = $this->requireNonEmpty((string) ($payload['cardToken'] ?? ''), 'cardToken');
        $basketId = $this->requireNonEmpty((string) ($payload['basketId'] ?? ''), 'basketId');

        $price = $this->normalizeAmount($payload['price'] ?? null, 'price');
        $paidPrice = $this->normalizeAmount($payload['paidPrice'] ?? null, 'paidPrice');

        $currency = strtoupper((string) ($payload['currency'] ?? Currency::TL));
        $installment = (int) ($payload['installment'] ?? 1);
        $paymentChannel = (string) ($payload['paymentChannel'] ?? PaymentChannel::WEB);
        $paymentGroup = (string) ($payload['paymentGroup'] ?? PaymentGroup::PRODUCT);

        $buyer = $this->buildBuyer($payload['buyer'] ?? []);
        $shippingAddress = $this->buildAddress($payload['shippingAddress'] ?? [], 'shippingAddress');
        $billingAddress = $this->buildAddress($payload['billingAddress'] ?? [], 'billingAddress');
        $basketItems = $this->buildBasketItems($payload['basketItems'] ?? []);

        $createPaymentRequest = new CreatePaymentRequest();
        $createPaymentRequest->setLocale($this->config->locale);
        $createPaymentRequest->setConversationId($this->config->conversationId);
        $createPaymentRequest->setPrice($price);
        $createPaymentRequest->setPaidPrice($paidPrice);
        $createPaymentRequest->setCurrency($currency);
        $createPaymentRequest->setInstallment($installment);
        $createPaymentRequest->setBasketId($basketId);
        $createPaymentRequest->setPaymentChannel($paymentChannel);
        $createPaymentRequest->setPaymentGroup($paymentGroup);

        $paymentCard = new \Iyzipay\Model\PaymentCard();
        $paymentCard->setCardUserKey($cardUserKey);
        $paymentCard->setCardToken($cardToken);
        $createPaymentRequest->setPaymentCard($paymentCard);

        $createPaymentRequest->setBuyer($buyer);
        $createPaymentRequest->setShippingAddress($shippingAddress);
        $createPaymentRequest->setBillingAddress($billingAddress);
        $createPaymentRequest->setBasketItems($basketItems);

        return Payment::create($createPaymentRequest, OptionsFactory::create($this->config));
    }

    // --------------------------------------------------------------------
    // Builders
    // --------------------------------------------------------------------

    private function buildCardInformation(array $cardInformation): CardInformation
    {
        $cardInfoModel = new CardInformation();

        if (!empty($cardInformation['cardAlias'])) {
            $cardInfoModel->setCardAlias((string) $cardInformation['cardAlias']);
        }
        if (!empty($cardInformation['holderName'])) {
            $cardInfoModel->setCardHolderName((string) $cardInformation['holderName']);
        }
        if (!empty($cardInformation['number'])) {
            $this->assertOnlyDigits($cardInformation['number'], 'card number');
            $cardInfoModel->setCardNumber((string) $cardInformation['number']);
        }
        if (!empty($cardInformation['expireMonth'])) {
            $cardInfoModel->setExpireMonth((string) $cardInformation['expireMonth']);
        }
        if (!empty($cardInformation['expireYear'])) {
            $cardInfoModel->setExpireYear((string) $cardInformation['expireYear']);
        }

        return $cardInfoModel;
    }

    private function buildBuyer(array $buyerData): Buyer
    {
        $buyerModel = new Buyer();

        $buyerModel->setId($this->requireNonEmpty((string) ($buyerData['id'] ?? ''), 'buyer.id'));
        $buyerModel->setName($this->requireNonEmpty((string) ($buyerData['name'] ?? ''), 'buyer.name'));
        $buyerModel->setSurname($this->requireNonEmpty((string) ($buyerData['surname'] ?? ''), 'buyer.surname'));
        $buyerModel->setEmail($this->requireNonEmpty((string) ($buyerData['email'] ?? ''), 'buyer.email'));
        $buyerModel->setRegistrationAddress($this->requireNonEmpty((string) ($buyerData['registrationAddress'] ?? ''), 'buyer.registrationAddress'));
        $buyerModel->setIp($this->requireNonEmpty((string) ($buyerData['ip'] ?? ''), 'buyer.ip'));
        $buyerModel->setCity($this->requireNonEmpty((string) ($buyerData['city'] ?? ''), 'buyer.city'));
        $buyerModel->setCountry($this->requireNonEmpty((string) ($buyerData['country'] ?? ''), 'buyer.country'));

        if (!empty($buyerData['gsmNumber'])) {
            $buyerModel->setGsmNumber((string) $buyerData['gsmNumber']);
        }
        if (!empty($buyerData['identityNumber'])) {
            $buyerModel->setIdentityNumber((string) $buyerData['identityNumber']);
        }
        if (!empty($buyerData['lastLoginDate'])) {
            $buyerModel->setLastLoginDate((string) $buyerData['lastLoginDate']);
        }
        if (!empty($buyerData['registrationDate'])) {
            $buyerModel->setRegistrationDate((string) $buyerData['registrationDate']);
        }
        if (!empty($buyerData['zipCode'])) {
            $buyerModel->setZipCode((string) $buyerData['zipCode']);
        }

        return $buyerModel;
    }

    private function buildAddress(array $addressData, string $fieldPath): Address
    {
        $addressModel = new Address();

        $addressModel->setContactName($this->requireNonEmpty((string) ($addressData['contactName'] ?? ''), "$fieldPath.contactName"));
        $addressModel->setCity($this->requireNonEmpty((string) ($addressData['city'] ?? ''), "$fieldPath.city"));
        $addressModel->setCountry($this->requireNonEmpty((string) ($addressData['country'] ?? ''), "$fieldPath.country"));
        $addressModel->setAddress($this->requireNonEmpty((string) ($addressData['address'] ?? ''), "$fieldPath.address"));

        if (!empty($addressData['zipCode'])) {
            $addressModel->setZipCode((string) $addressData['zipCode']);
        }

        return $addressModel;
    }

    private function buildBasketItems(array $basketItems): array
    {
        if (empty($basketItems)) {
            throw new InvalidArgumentException('basketItems boş olamaz.');
        }

        $basketItemModels = [];

        foreach ($basketItems as $index => $basketItemData) {
            $basketItemModel = new BasketItem();

            $basketItemModel->setId($this->requireNonEmpty((string) ($basketItemData['id'] ?? ''), "basketItems[$index].id"));
            $basketItemModel->setName($this->requireNonEmpty((string) ($basketItemData['name'] ?? ''), "basketItems[$index].name"));
            $basketItemModel->setCategory1($this->requireNonEmpty((string) ($basketItemData['category1'] ?? ''), "basketItems[$index].category1"));

            if (!empty($basketItemData['category2'])) {
                $basketItemModel->setCategory2((string) $basketItemData['category2']);
            }

            $itemType = strtoupper((string) ($basketItemData['itemType'] ?? ''));

            if (!in_array($itemType, [BasketItemType::PHYSICAL, BasketItemType::VIRTUAL], true)) {
                throw new InvalidArgumentException("basketItems[$index].itemType PHYSICAL veya VIRTUAL olmalıdır.");
            }

            $basketItemModel->setItemType($itemType);

            $normalizedPrice = $this->normalizeAmount($basketItemData['price'] ?? null, "basketItems[$index].price");
            $basketItemModel->setPrice($normalizedPrice);

            $basketItemModels[] = $basketItemModel;
        }

        return $basketItemModels;
    }

    // ----------------- Helpers -----------------

    private function requireNonEmpty(?string $value, string $fieldName): string
    {
        $trimmed = trim((string) $value);

        if ($trimmed === '') {
            throw new InvalidArgumentException("$fieldName boş olamaz.");
        }

        return $trimmed;
    }

    private function normalizeAmount(mixed $amount, string $fieldName): string
    {
        if ($amount === null || $amount === '') {
            throw new InvalidArgumentException("$fieldName zorunludur.");
        }

        if (is_string($amount)) {
            $amount = str_replace(',', '.', trim($amount));
        }

        $numericAmount = (float) $amount;

        if ($numericAmount <= 0) {
            throw new InvalidArgumentException("$fieldName 0’dan büyük olmalıdır.");
        }

        return number_format($numericAmount, 2, '.', '');
    }

    private function assertOnlyDigits(string $value, string $label): void
    {
        if (!preg_match('/^\d+$/', $value)) {
            throw new InvalidArgumentException("$label yalnızca rakam içermelidir.");
        }
    }
}
