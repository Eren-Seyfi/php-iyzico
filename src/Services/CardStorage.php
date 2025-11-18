<?php
declare(strict_types=1);

namespace Eren5\PhpIyzico\Services;

use Eren5\PhpIyzico\Config;
use Eren5\PhpIyzico\OptionsFactory;
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
    public function __construct(private Config $cfg)
    {
    }

    /**
     * SDK: create_user_and_add_card
     *
     * Verilen email + externalId ile kart kullanıcısı oluşturur ve ilk kartı ekler.
     *
     * @param string $email
     * @param string $externalId
     * @param array{
     *   cardAlias?:string,
     *   holderName?:string,
     *   number?:string,
     *   expireMonth?:string,
     *   expireYear?:string
     * } $cardInfo
     */
    public function createUserAndAddCard(string $email, string $externalId, array $cardInfo): Card
    {
        $email = $this->requireNonEmpty($email, 'email');
        $externalId = $this->requireNonEmpty($externalId, 'externalId');

        $r = new CreateCardRequest();
        $r->setLocale($this->cfg->locale);
        $r->setConversationId($this->cfg->conversationId);
        $r->setEmail($email);
        $r->setExternalId($externalId);
        $r->setCard($this->buildCardInformation($cardInfo));

        return Card::create($r, OptionsFactory::create($this->cfg));
    }

    /**
     * SDK: create_card
     *
     * Var olan bir kart kullanıcısına (cardUserKey) yeni kart ekler.
     *
     * @param string $cardUserKey
     * @param array{
     *   cardAlias?:string,
     *   holderName?:string,
     *   number?:string,
     *   expireMonth?:string,
     *   expireYear?:string
     * } $cardInfo
     */
    public function addCard(string $cardUserKey, array $cardInfo): Card
    {
        $cardUserKey = $this->requireNonEmpty($cardUserKey, 'cardUserKey');

        $r = new CreateCardRequest();
        $r->setLocale($this->cfg->locale);
        $r->setConversationId($this->cfg->conversationId);
        $r->setCardUserKey($cardUserKey);
        $r->setCard($this->buildCardInformation($cardInfo));

        return Card::create($r, OptionsFactory::create($this->cfg));
    }

    /**
     * SDK: RetrieveCardListRequest
     */
    public function list(string $cardUserKey): CardList
    {
        $cardUserKey = $this->requireNonEmpty($cardUserKey, 'cardUserKey');

        $r = new RetrieveCardListRequest();
        $r->setLocale($this->cfg->locale);
        $r->setConversationId($this->cfg->conversationId);
        $r->setCardUserKey($cardUserKey);

        return CardList::retrieve($r, OptionsFactory::create($this->cfg));
    }

    /**
     * SDK: DeleteCardRequest
     */
    public function delete(string $cardUserKey, string $cardToken): Card
    {
        $cardUserKey = $this->requireNonEmpty($cardUserKey, 'cardUserKey');
        $cardToken = $this->requireNonEmpty($cardToken, 'cardToken');

        $r = new DeleteCardRequest();
        $r->setLocale($this->cfg->locale);
        $r->setConversationId($this->cfg->conversationId);
        $r->setCardUserKey($cardUserKey);
        $r->setCardToken($cardToken);

        return Card::delete($r, OptionsFactory::create($this->cfg));
    }

    /**
     * SDK: "Saklı Kart ile Ödeme Alma (NON3D)" örneğinin servis hâli.
     *
     * @param array $payload
     *   price            string|int|float  -> Örn: "1"
     *   paidPrice        string|int|float  -> Örn: "1.2"
     *   currency         string            -> Currency::TL varsayılan
     *   installment      int               -> Örn: 1
     *   basketId         string            -> Örn: "B67832"
     *   paymentChannel   string            -> PaymentChannel::WEB (varsayılan)
     *   paymentGroup     string            -> PaymentGroup::PRODUCT (varsayılan)
     *   cardUserKey      string            -> ZORUNLU
     *   cardToken        string            -> ZORUNLU
     *   buyer            array{ id:string, name:string, surname:string, gsmNumber?:string, email:string,
     *                           identityNumber?:string, lastLoginDate?:string, registrationDate?:string,
     *                           registrationAddress:string, ip:string, city:string, country:string, zipCode?:string }
     *   shippingAddress  array{ contactName:string, city:string, country:string, address:string, zipCode?:string }
     *   billingAddress   array{ contactName:string, city:string, country:string, address:string, zipCode?:string }
     *   basketItems      array<array{ id:string, name:string, category1:string, category2?:string,
     *                                 itemType:string, price:string|int|float }>
     */
    public function payWithSavedCardNon3D(array $payload): Payment
    {
        // Zorunlular
        $cardUserKey = $this->requireNonEmpty((string) ($payload['cardUserKey'] ?? ''), 'cardUserKey');
        $cardToken = $this->requireNonEmpty((string) ($payload['cardToken'] ?? ''), 'cardToken');
        $basketId = $this->requireNonEmpty((string) ($payload['basketId'] ?? ''), 'basketId');

        $price = $this->normalizeAmount($payload['price'] ?? null, 'price');
        $paidPrice = $this->normalizeAmount($payload['paidPrice'] ?? null, 'paidPrice');

        $currency = strtoupper((string) ($payload['currency'] ?? Currency::TL));
        $installment = (int) ($payload['installment'] ?? 1);
        $paymentChannel = (string) ($payload['paymentChannel'] ?? PaymentChannel::WEB);
        $paymentGroup = (string) ($payload['paymentGroup'] ?? PaymentGroup::PRODUCT);

        // Buyer / Address / Basket
        $buyer = $this->buildBuyer($payload['buyer'] ?? []);
        $shippingAddress = $this->buildAddress($payload['shippingAddress'] ?? [], 'shippingAddress');
        $billingAddress = $this->buildAddress($payload['billingAddress'] ?? [], 'billingAddress');
        $basketItems = $this->buildBasketItems($payload['basketItems'] ?? []);

        $req = new CreatePaymentRequest();
        $req->setLocale($this->cfg->locale);
        $req->setConversationId($this->cfg->conversationId);
        $req->setPrice($price);
        $req->setPaidPrice($paidPrice);
        $req->setCurrency($currency);
        $req->setInstallment($installment);
        $req->setBasketId($basketId);
        $req->setPaymentChannel($paymentChannel);
        $req->setPaymentGroup($paymentGroup);

        // Saved card bilgisi
        $paymentCard = new \Iyzipay\Model\PaymentCard();
        $paymentCard->setCardUserKey($cardUserKey);
        $paymentCard->setCardToken($cardToken);
        $req->setPaymentCard($paymentCard);

        // Buyer / Addresses / Basket
        $req->setBuyer($buyer);
        $req->setShippingAddress($shippingAddress);
        $req->setBillingAddress($billingAddress);
        $req->setBasketItems($basketItems);

        return Payment::create($req, OptionsFactory::create($this->cfg));
    }

    // --------------------------------------------------------------------
    // Builders & Validators
    // --------------------------------------------------------------------

    /**
     * CardInformation builder (CVC dahil edilmez)
     *
     * @param array{
     *   cardAlias?:string,
     *   holderName?:string,
     *   number?:string,
     *   expireMonth?:string,
     *   expireYear?:string
     * } $cardInfo
     */
    private function buildCardInformation(array $cardInfo): CardInformation
    {
        $ci = new CardInformation();

        if (!empty($cardInfo['cardAlias'])) {
            $ci->setCardAlias((string) $cardInfo['cardAlias']);
        }
        if (!empty($cardInfo['holderName'])) {
            $ci->setCardHolderName((string) $cardInfo['holderName']);
        }
        if (!empty($cardInfo['number'])) {
            $this->assertOnlyDigits($cardInfo['number'], 'card number');
            $ci->setCardNumber((string) $cardInfo['number']);
        }
        if (!empty($cardInfo['expireMonth'])) {
            $ci->setExpireMonth((string) $cardInfo['expireMonth']);
        }
        if (!empty($cardInfo['expireYear'])) {
            $ci->setExpireYear((string) $cardInfo['expireYear']);
        }

        return $ci;
    }

    /**
     * Buyer builder
     *
     * @param array $data
     */
    private function buildBuyer(array $data): Buyer
    {
        $buyer = new Buyer();
        $buyer->setId($this->requireNonEmpty((string) ($data['id'] ?? ''), 'buyer.id'));
        $buyer->setName($this->requireNonEmpty((string) ($data['name'] ?? ''), 'buyer.name'));
        $buyer->setSurname($this->requireNonEmpty((string) ($data['surname'] ?? ''), 'buyer.surname'));
        $buyer->setEmail($this->requireNonEmpty((string) ($data['email'] ?? ''), 'buyer.email'));
        $buyer->setRegistrationAddress($this->requireNonEmpty((string) ($data['registrationAddress'] ?? ''), 'buyer.registrationAddress'));
        $buyer->setIp($this->requireNonEmpty((string) ($data['ip'] ?? ''), 'buyer.ip'));
        $buyer->setCity($this->requireNonEmpty((string) ($data['city'] ?? ''), 'buyer.city'));
        $buyer->setCountry($this->requireNonEmpty((string) ($data['country'] ?? ''), 'buyer.country'));

        if (!empty($data['gsmNumber']))
            $buyer->setGsmNumber((string) $data['gsmNumber']);
        if (!empty($data['identityNumber']))
            $buyer->setIdentityNumber((string) $data['identityNumber']);
        if (!empty($data['lastLoginDate']))
            $buyer->setLastLoginDate((string) $data['lastLoginDate']);
        if (!empty($data['registrationDate']))
            $buyer->setRegistrationDate((string) $data['registrationDate']);
        if (!empty($data['zipCode']))
            $buyer->setZipCode((string) $data['zipCode']);

        return $buyer;
    }

    /**
     * Address builder
     *
     * @param array $data
     * @param string $fieldPath
     */
    private function buildAddress(array $data, string $fieldPath): Address
    {
        $addr = new Address();
        $addr->setContactName($this->requireNonEmpty((string) ($data['contactName'] ?? ''), $fieldPath . '.contactName'));
        $addr->setCity($this->requireNonEmpty((string) ($data['city'] ?? ''), $fieldPath . '.city'));
        $addr->setCountry($this->requireNonEmpty((string) ($data['country'] ?? ''), $fieldPath . '.country'));
        $addr->setAddress($this->requireNonEmpty((string) ($data['address'] ?? ''), $fieldPath . '.address'));
        if (!empty($data['zipCode']))
            $addr->setZipCode((string) $data['zipCode']);
        return $addr;
    }

    /**
     * Basket items builder
     *
     * @param array<int, array{
     *   id:string, name:string, category1:string, category2?:string,
     *   itemType:string, price:string|int|float
     * }> $items
     * @return BasketItem[]
     */
    private function buildBasketItems(array $items): array
    {
        if (empty($items)) {
            throw new InvalidArgumentException('basketItems boş olamaz.');
        }
        $out = [];
        foreach ($items as $i => $row) {
            $bi = new BasketItem();
            $bi->setId($this->requireNonEmpty((string) ($row['id'] ?? ''), "basketItems[$i].id"));
            $bi->setName($this->requireNonEmpty((string) ($row['name'] ?? ''), "basketItems[$i].name"));
            $bi->setCategory1($this->requireNonEmpty((string) ($row['category1'] ?? ''), "basketItems[$i].category1"));
            if (!empty($row['category2']))
                $bi->setCategory2((string) $row['category2']);

            $itemType = strtoupper((string) ($row['itemType'] ?? ''));
            if ($itemType !== BasketItemType::PHYSICAL && $itemType !== BasketItemType::VIRTUAL) {
                throw new InvalidArgumentException("basketItems[$i].itemType PHYSICAL veya VIRTUAL olmalıdır.");
            }
            $bi->setItemType($itemType);

            $price = $this->normalizeAmount($row['price'] ?? null, "basketItems[$i].price");
            $bi->setPrice($price);
            $out[] = $bi;
        }
        return $out;
    }

    // ----------------- helpers -----------------

    private function requireNonEmpty(?string $val, string $field): string
    {
        $val = trim((string) $val);
        if ($val === '') {
            throw new InvalidArgumentException("$field boş olamaz.");
        }
        return $val;
    }

    /**
     * Iyzico string fiyat kabul eder — “100.00” formatına normalize eder.
     *
     * @param mixed $amount
     */
    private function normalizeAmount(mixed $amount, string $field): string
    {
        if ($amount === null || $amount === '') {
            throw new InvalidArgumentException("$field zorunludur.");
        }
        if (is_string($amount)) {
            $amount = str_replace(',', '.', trim($amount));
        }
        $num = (float) $amount;
        if ($num <= 0) {
            throw new InvalidArgumentException("$field 0’dan büyük olmalıdır.");
        }
        return number_format($num, 2, '.', '');
    }

    private function assertOnlyDigits(string $val, string $fieldLabel): void
    {
        if (!preg_match('/^\d+$/', $val)) {
            throw new InvalidArgumentException("$fieldLabel yalnızca rakam içermelidir.");
        }
    }
}
