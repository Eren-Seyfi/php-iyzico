<?php
declare(strict_types=1);

namespace Eren5\PhpIyzico\Services\Marketplace;

use InvalidArgumentException;
use Eren5\PhpIyzico\Config;
use Eren5\PhpIyzico\OptionsFactory;

// Iyzipay core/models
use Iyzipay\Options;
use Iyzipay\Model\Payment;
use Iyzipay\Model\Locale;          // "tr" | "en"
use Iyzipay\Model\Currency;        // "TRY","USD","EUR","GBP","IRR","NOK","RUB","CHF"
use Iyzipay\Model\PaymentGroup;    // PRODUCT | LISTING | SUBSCRIPTION
use Iyzipay\Model\PaymentChannel;  // WEB | MOBILE | ...
use Iyzipay\Model\BasketItem;
use Iyzipay\Model\BasketItemType;  // PHYSICAL | VIRTUAL
use Iyzipay\Model\Buyer;
use Iyzipay\Model\Address;
use Iyzipay\Model\PaymentCard;

// Iyzipay requests - Non3DS & 3DS INIT
use Iyzipay\Request\CreatePaymentRequest;

// Iyzipay requests/models - 3DS COMPLETE
use Iyzipay\Request\CreateThreedsPaymentRequest;
use Iyzipay\Model\ThreedsInitialize;
use Iyzipay\Model\ThreedsPayment;

// Iyzipay requests/models - Checkout Form
use Iyzipay\Request\CreateCheckoutFormInitializeRequest;
use Iyzipay\Request\RetrieveCheckoutFormRequest;
use Iyzipay\Model\CheckoutFormInitialize;
use Iyzipay\Model\CheckoutForm;

// Iyzipay requests/models - Pay With iyzico (PWI)
use Iyzipay\Request\CreatePayWithIyzicoInitializeRequest;
use Iyzipay\Request\RetrievePayWithIyzicoRequest;
use Iyzipay\Model\PayWithIyzicoInitialize;
use Iyzipay\Model\PayWithIyzico;

/**
 * # MarketPaymentService
 *
 * Pazaryeri (split) ödemeleri için hepsi bir arada servis:
 *
 * - **Non-3DS** (kart bilgileri ile anında ödeme)
 * - **3DS (Two-step)**: init (HTML 3DS formu) + complete (auth)
 * - **Checkout Form** (Iyzi’nin hazır hosted formu)
 * - **Pay With iyzico (PWI)** (müşteriyi iyzico sayfasına yönlendirir)
 *
 * Sepet kalemleri (BasketItem) düzeyinde **subMerchantKey** ve **subMerchantPrice**
 * zorunludur (pazaryeri komisyon/bölüşüm için).
 */
final class MarketPaymentService
{
    /** @var Options Iyzipay istekleri için yapılandırılmış Options nesnesi (API anahtarları, baseUrl vs.) */
    private Options $options;

    /**
     * @param Config $config Uygulama yapılandırması (apiKey, secretKey, baseUrl, varsayılan locale/conversationId vs.)
     */
    public function __construct(private Config $config)
    {
        // Iyzipay Options oluştur (apiKey, secretKey, baseUrl)
        $this->options = OptionsFactory::create($this->config);
    }

    /* =============================================================================
     * NON-3DS
     * ============================================================================= */

    /**
     * Non-3DS ödeme oluşturur (anında provizyon).
     *
     * @param array{
     *   price: numeric-string|int|float,         // Zorunlu: Sepet anapara tutarı (ör: "1.00")
     *   paidPrice: numeric-string|int|float,     // Zorunlu: Karttan çekilecek toplam (komisyon, kargo dahil)
     *   currency: string,                        // Zorunlu: "TL"/"TRY"/"USD"/"EUR"...
     *   installment?: int,                       // Opsiyonel: Taksit sayısı (varsayılan 1)
     *   basketId?: string,                       // Opsiyonel: Sepet ID (raporlama)
     *   locale?: string,                         // Opsiyonel: Locale::TR | Locale::EN ("tr" | "en")
     *   conversationId?: string,                 // Opsiyonel: İstenen konuşma ID’si
     *   paymentChannel?: string,                 // Opsiyonel: PaymentChannel::* (varsayılan WEB)
     *   paymentGroup?: string,                   // Opsiyonel: PaymentGroup::* (varsayılan PRODUCT)
     *   paymentCard: array<string,mixed>,        // Zorunlu: Kart bilgileri (buildPaymentCard kullanır)
     *   buyer: array<string,mixed>,              // Zorunlu: Alıcı bilgileri (buildBuyer kullanır)
     *   shippingAddress: array<string,mixed>,    // Zorunlu: Kargo adresi (buildAddress)
     *   billingAddress: array<string,mixed>,     // Zorunlu: Fatura adresi (buildAddress)
     *   basketItems: array<int,array<string,mixed>> // Zorunlu: Sepet (buildBasketItems)
     * } $payment
     * @return array{
     *   ok: bool,
     *   status: string|null,
     *   errorCode: string|null,
     *   errorMessage: string|null,
     *   paymentId: string|null,
     *   authCode: string|null,
     *   fraudStatus: mixed,
     *   raw: array<string,mixed>|string|null
     * }
     */
    public function createNon3DS(array $payment): array
    {
        // Minimal zorunlu alan kontrolü (üst düzey)
        $this->require($payment, [
            'price',
            'paidPrice',
            'currency',
            'paymentCard',
            'buyer',
            'shippingAddress',
            'billingAddress',
            'basketItems'
        ]);

        // Sepet kalemlerinde pazaryeri alanları var mı kontrol et
        $this->assertBasketItems($payment['basketItems']);

        // CreatePaymentRequest kur ve gönder
        $request = $this->buildCreatePaymentRequest($payment);
        $response = Payment::create($request, $this->options);

        // Basit normalize edilmiş dizi döndür
        return $this->responseToArray($response);
    }

    /* =============================================================================
     * 3DS (INIT + COMPLETE)
     * ============================================================================= */

    /**
     * 3DS Init (ThreedsInitialize): kart sahibi bankanın 3DS sayfasına gönderilecek HTML içerik üretir.
     *
     * @param array{
     *   price: numeric-string|int|float,
     *   paidPrice: numeric-string|int|float,
     *   currency: string,
     *   callbackUrl: string,                     // Zorunlu: 3DS tamamlandıktan sonra iyzico’nun post yapacağı URL
     *   installment?: int,
     *   basketId?: string,
     *   locale?: string,
     *   conversationId?: string,
     *   paymentChannel?: string,
     *   paymentGroup?: string,
     *   paymentCard: array<string,mixed>,
     *   buyer: array<string,mixed>,
     *   shippingAddress: array<string,mixed>,
     *   billingAddress: array<string,mixed>,
     *   basketItems: array<int,array<string,mixed>>
     * } $payment
     * @return array{
     *   ok: bool,
     *   status: string|null,
     *   errorCode: string|null,
     *   errorMessage: string|null,
     *   paymentId: string|null,
     *   authCode: string|null,
     *   fraudStatus: mixed,
     *   threeDSHtmlContent?: string|null,        // HTML (auto submit form) — tarayıcıya basılır
     *   raw: array<string,mixed>|string|null
     * }
     */
    public function init3DS(array $payment): array
    {
        $this->require($payment, [
            'price',
            'paidPrice',
            'currency',
            'callbackUrl',
            'paymentCard',
            'buyer',
            'shippingAddress',
            'billingAddress',
            'basketItems'
        ]);
        $this->assertBasketItems($payment['basketItems']);

        // 3DS init için de CreatePaymentRequest kullanılır.
        $request = $this->buildCreatePaymentRequest($payment);

        // 3DS callback adresi: bankadan dönüş bu adrese gönderilir
        $request->setCallbackUrl((string) $payment['callbackUrl']);

        /** @var ThreedsInitialize $resp */
        $response = ThreedsInitialize::create($request, $this->options);

        $responseArray = $this->responseToArray($response);

        // HTML içerik genelde 'threeDSHtmlContent' ya da 'htmlContent' alanı ile gelir
        $rawResult = $responseArray['raw'];
        if (is_array($rawResult)) {
            $responseArray['threeDSHtmlContent'] = $rawResult['threeDSHtmlContent'] ?? $rawResult['htmlContent'] ?? null;
        }
        return $responseArray;
    }

    /**
     * 3DS Complete (ThreedsPayment): bankadan dönen verilerle işlemi tamamlar.
     *
     * @param array{
     *   paymentId: string,                       // Zorunlu: init sırasında üretilen paymentId
     *   conversationData: string,                // Zorunlu: bankadan callback ile dönen conversationData
     *   locale?: string,
     *   conversationId?: string
     * } $data
     * @return array{
     *   ok: bool,
     *   status: string|null,
     *   errorCode: string|null,
     *   errorMessage: string|null,
     *   paymentId: string|null,
     *   authCode: string|null,
     *   fraudStatus: mixed,
     *   raw: array<string,mixed>|string|null
     * }
     */
    public function complete3DS(array $data): array
    {
        // 3DS tamamlamak için 'paymentId' ve 'conversationData' şart
        $this->require($data, ['paymentId', 'conversationData']);

        $request = new CreateThreedsPaymentRequest();
        $request->setLocale($data['locale'] ?? Locale::TR);
        $request->setConversationId($data['conversationId'] ?? (string) microtime(true));
        $request->setPaymentId((string) $data['paymentId']);
        $request->setConversationData((string) $data['conversationData']);

        /** @var ThreedsPayment $resp */
        $response = ThreedsPayment::create($request, $this->options);
        return $this->responseToArray($response);
    }

    /* =============================================================================
     * CHECKOUT FORM (INIT + RETRIEVE)
     * ============================================================================= */

    /**
     * Checkout Form (Hosted) init: iyzico’nun hazır ödeme formunu başlatır.
     *
     * Not: Bazı SDK sürümlerinde CreateCheckoutFormInitializeRequest üzerinde
     * `setPaymentChannel()` metodu **yoktur**. Bu yüzden çağrılmıyor.
     *
     * @param array{
     *   price: numeric-string|int|float,
     *   paidPrice: numeric-string|int|float,
     *   currency: string,
     *   callbackUrl: string,                     // Zorunlu: ödeme sonucu dönüş adresi
     *   basketId?: string,
     *   paymentGroup?: string,                   // PaymentGroup::*
     *   enabledInstallments?: int[],             // Örn: [2,3,6,9]
     *   locale?: string,
     *   conversationId?: string,
     *   buyer: array<string,mixed>,
     *   shippingAddress: array<string,mixed>,
     *   billingAddress: array<string,mixed>,
     *   basketItems: array<int,array<string,mixed>>
     * } $payment
     * @return array{
     *   ok: bool,
     *   status: string|null,
     *   errorCode: string|null,
     *   errorMessage: string|null,
     *   token?: string|null,                     // Hosted form tokenı
     *   tokenExpireTime?: int|null,              // ms cinsinden son kullanma zamanı
     *   checkoutFormContent?: string|null,       // Gömülebilir HTML
     *   raw: array<string,mixed>|string|null
     * }
     */
    public function initCheckoutForm(array $payment): array
    {
        $this->require($payment, [
            'price',
            'paidPrice',
            'currency',
            'callbackUrl',
            'buyer',
            'shippingAddress',
            'billingAddress',
            'basketItems'
        ]);
        $this->assertBasketItems($payment['basketItems']);

        $request = new CreateCheckoutFormInitializeRequest();
        $request->setLocale($payment['locale'] ?? Locale::TR);
        $request->setConversationId($payment['conversationId'] ?? (string) microtime(true));
        $request->setPrice((string) $payment['price']);
        $request->setPaidPrice((string) $payment['paidPrice']);
        $request->setCurrency($this->normalizeCurrency($payment['currency']));
        $request->setCallbackUrl((string) $payment['callbackUrl']);
        if (!empty($payment['basketId'])) {
            $request->setBasketId((string) $payment['basketId']);
        }
        // setPaymentChannel yok — çağrılmıyor
        $request->setPaymentGroup($this->normalizePaymentGroup($payment['paymentGroup'] ?? PaymentGroup::PRODUCT));

        // Taksit kısıtlaması (banka bazlı)
        if (!empty($payment['enabledInstallments']) && is_array($payment['enabledInstallments'])) {
            $request->setEnabledInstallments($payment['enabledInstallments']);
        }

        // Zorunlu parçalar
        $request->setBuyer($this->buildBuyer($payment['buyer']));
        $request->setShippingAddress($this->buildAddress($payment['shippingAddress']));
        $request->setBillingAddress($this->buildAddress($payment['billingAddress']));
        $request->setBasketItems($this->buildBasketItems($payment['basketItems']));

        $response = CheckoutFormInitialize::create($request, $this->options);

        // Yanıtı normalize et
        $responseArray = $this->responseToArray($response);
        $rawResult = $responseArray['raw'];
        if (is_array($rawResult)) {
            $responseArray['checkoutFormContent'] = $rawResult['checkoutFormContent'] ?? null;
            $responseArray['token'] = $rawResult['token'] ?? null;
            $responseArray['tokenExpireTime'] = $rawResult['tokenExpireTime'] ?? null;
        }
        return $responseArray;
    }

    /**
     * Checkout Form sonucunu token ile getirir (ödeme sonucu).
     *
     * @param array{
     *   token: string,                           // Zorunlu: init’te dönen token
     *   locale?: string,
     *   conversationId?: string
     * } $data
     * @return array<string,mixed> Normalized yanıt (status, error… + raw)
     */
    public function retrieveCheckoutForm(array $data): array
    {
        $this->require($data, ['token']);

        $request = new RetrieveCheckoutFormRequest();
        $request->setLocale($data['locale'] ?? Locale::TR);
        $request->setConversationId($data['conversationId'] ?? (string) microtime(true));
        $request->setToken((string) $data['token']);

        $response = CheckoutForm::retrieve($request, $this->options);
        return $this->responseToArray($response);
    }

    /* =============================================================================
     * PWI (INIT + RETRIEVE)
     * ============================================================================= */

    /**
     * Pay With iyzico (PWI) init: kullanıcıyı iyzico ödeme sayfasına yönlendiren akış.
     *
     * Not: Bazı SDK sürümlerinde CreatePayWithIyzicoInitializeRequest üzerinde
     * `setPaymentChannel()` metodu **yoktur**. Bu yüzden çağrılmıyor.
     *
     * @param array{
     *   price: numeric-string|int|float,
     *   paidPrice: numeric-string|int|float,
     *   currency: string,
     *   callbackUrl: string,
     *   basketId?: string,
     *   paymentGroup?: string,
     *   enabledInstallments?: int[],
     *   locale?: string,
     *   conversationId?: string,
     *   buyer: array<string,mixed>,
     *   shippingAddress: array<string,mixed>,
     *   billingAddress: array<string,mixed>,
     *   basketItems: array<int,array<string,mixed>>
     * } $payment
     * @return array{
     *   ok: bool,
     *   status: string|null,
     *   errorCode: string|null,
     *   errorMessage: string|null,
     *   payWithIyzicoPageUrl?: string|null,      // Kullanıcıyı yönlendireceğin URL
     *   token?: string|null,
     *   tokenExpireTime?: int|null,
     *   raw: array<string,mixed>|string|null
     * }
     */
    public function initPWI(array $payment): array
    {
        $this->require($payment, [
            'price',
            'paidPrice',
            'currency',
            'callbackUrl',
            'buyer',
            'shippingAddress',
            'billingAddress',
            'basketItems'
        ]);
        $this->assertBasketItems($payment['basketItems']);

        $request = new CreatePayWithIyzicoInitializeRequest();
        $request->setLocale($payment['locale'] ?? Locale::TR);
        $request->setConversationId($payment['conversationId'] ?? (string) microtime(true));
        $request->setPrice((string) $payment['price']);
        $request->setPaidPrice((string) $payment['paidPrice']);
        $request->setCurrency($this->normalizeCurrency($payment['currency']));
        $request->setCallbackUrl((string) $payment['callbackUrl']);
        if (!empty($payment['basketId'])) {
            $request->setBasketId((string) $payment['basketId']);
        }

        // setPaymentChannel yok — çağrılmıyor
        $request->setPaymentGroup($this->normalizePaymentGroup($payment['paymentGroup'] ?? PaymentGroup::PRODUCT));

        if (!empty($payment['enabledInstallments']) && is_array($payment['enabledInstallments'])) {
            $request->setEnabledInstallments($payment['enabledInstallments']);
        }
        $request->setBuyer($this->buildBuyer($payment['buyer']));
        $request->setShippingAddress($this->buildAddress($payment['shippingAddress']));
        $request->setBillingAddress($this->buildAddress($payment['billingAddress']));
        $request->setBasketItems($this->buildBasketItems($payment['basketItems']));

        $response = PayWithIyzicoInitialize::create($request, $this->options);

        $responseArray = $this->responseToArray($response);
        $rawResult = $responseArray['raw'];
        if (is_array($rawResult)) {
            $responseArray['payWithIyzicoPageUrl'] = $rawResult['payWithIyzicoPageUrl'] ?? null;
            $responseArray['token'] = $rawResult['token'] ?? null;
            $responseArray['tokenExpireTime'] = $rawResult['tokenExpireTime'] ?? null;
        }
        return $responseArray;
    }

    /**
     * PWI sonucunu token ile getirir.
     *
     * @param array{
     *   token: string,                           // Zorunlu: init’te dönen token
     *   locale?: string,
     *   conversationId?: string
     * } $data
     * @return array<string,mixed> Normalized yanıt (status, error… + raw)
     */
    public function retrievePWI(array $data): array
    {
        $this->require($data, ['token']);

        $request = new RetrievePayWithIyzicoRequest();
        $request->setLocale($data['locale'] ?? Locale::TR);
        $request->setConversationId($data['conversationId'] ?? (string) microtime(true));
        $request->setToken((string) $data['token']);

        $response = PayWithIyzico::retrieve($request, $this->options);
        return $this->responseToArray($response);
    }

    /* =============================================================================
     * Builders & Helpers
     * ============================================================================= */

    /**
     * CreatePaymentRequest kurucu (Non-3DS ve 3DS-init için ortak).
     *
     * @param array<string,mixed> $p Üstteki createNon3DS/init3DS parametreleriyle aynı mantık
     * @return CreatePaymentRequest
     */
    private function buildCreatePaymentRequest(array $paymentData): CreatePaymentRequest
    {
        $request = new CreatePaymentRequest();

        // Locale/conversationId yoksa varsayılanları kullan (mikro saniyeli fallback)
        $request->setLocale($paymentData['locale'] ?? Locale::TR);
        $request->setConversationId($paymentData['conversationId'] ?? (string) microtime(true));

        // Zorunlu parasal alanlar (string olarak set edilir)
        $request->setPrice((string) $paymentData['price']);
        $request->setPaidPrice((string) $paymentData['paidPrice']);

        // Para birimi normalize edilir (TL/TRY farkı gözetilir)
        $request->setCurrency($this->normalizeCurrency($paymentData['currency']));

        // Taksit sayısı (yoksa 1)
        $request->setInstallment((int) ($paymentData['installment'] ?? 1));

        // Sepet ID (opsiyonel)
        if (!empty($paymentData['basketId'])) {
            $request->setBasketId((string) $paymentData['basketId']);
        }

        // Ödeme kanalı/grubu normalize edilir
        $request->setPaymentChannel($this->normalizePaymentChannel($paymentData['paymentChannel'] ?? PaymentChannel::WEB));
        $request->setPaymentGroup($this->normalizePaymentGroup($paymentData['paymentGroup'] ?? PaymentGroup::PRODUCT));

        // Kart/alıcı/adres/sepet builder’ları
        $request->setPaymentCard($this->buildPaymentCard($paymentData['paymentCard']));
        $request->setBuyer($this->buildBuyer($paymentData['buyer']));
        $request->setShippingAddress($this->buildAddress($paymentData['shippingAddress']));
        $request->setBillingAddress($this->buildAddress($paymentData['billingAddress']));
        $request->setBasketItems($this->buildBasketItems($paymentData['basketItems']));

        return $request;
    }

    /**
     * PaymentCard builder
     *
     * Beklenen alanlar:
     * - Zorunlu: cardHolderName, cardNumber, expireMonth, expireYear, cvc
     * - Opsiyonel: registerCard (0/1), cardAlias, cardToken, cardUserKey,
     *              registerConsumerCard (0/1), ucsToken, consumerToken
     *
     * @param array<string,mixed> $card
     */
    private function buildPaymentCard(array $card): PaymentCard
    {
        // Minimum kart alanları
        $this->require($card, ['cardHolderName', 'cardNumber', 'expireMonth', 'expireYear', 'cvc']);

        $paymentCard = new PaymentCard();
        $paymentCard->setCardHolderName((string) $card['cardHolderName']);
        $paymentCard->setCardNumber((string) $card['cardNumber']);
        $paymentCard->setExpireMonth((string) $card['expireMonth']);
        $paymentCard->setExpireYear((string) $card['expireYear']);
        $paymentCard->setCvc((string) $card['cvc']);
        $paymentCard->setRegisterCard((int) ($card['registerCard'] ?? 0));

        // Opsiyoneller (SDK modelinde mevcut)
        if (isset($card['cardAlias']))
            $paymentCard->setCardAlias((string) $card['cardAlias']);
        if (isset($card['cardToken']))
            $paymentCard->setCardToken((string) $card['cardToken']);
        if (isset($card['cardUserKey']))
            $paymentCard->setCardUserKey((string) $card['cardUserKey']);
        if (isset($card['registerConsumerCard']))
            $paymentCard->setRegisterConsumerCard((int) $card['registerConsumerCard']);
        if (isset($card['ucsToken']))
            $paymentCard->setUcsToken((string) $card['ucsToken']);
        if (isset($card['consumerToken']))
            $paymentCard->setConsumerToken((string) $card['consumerToken']);

        return $paymentCard;
    }

    /**
     * Buyer builder
     *
     * Zorunlu alanlar:
     *  id, name, surname, gsmNumber, email, identityNumber,
     *  registrationAddress, ip, city, country, zipCode
     *
     * Opsiyonel:
     *  lastLoginDate, registrationDate
     *
     * @param array<string,mixed> $b
     */
    private function buildBuyer(array $buyerData): Buyer
    {
        $this->require($buyerData, [
            'id',
            'name',
            'surname',
            'gsmNumber',
            'email',
            'identityNumber',
            'registrationAddress',
            'ip',
            'city',
            'country',
            'zipCode'
        ]);

        $buyer = new Buyer();
        $buyer->setId((string) $buyerData['id']);
        $buyer->setName((string) $buyerData['name']);
        $buyer->setSurname((string) $buyerData['surname']);
        $buyer->setGsmNumber((string) $buyerData['gsmNumber']);
        $buyer->setEmail((string) $buyerData['email']);
        $buyer->setIdentityNumber((string) $buyerData['identityNumber']);
        if (!empty($buyerData['lastLoginDate']))
            $buyer->setLastLoginDate((string) $buyerData['lastLoginDate']);
        if (!empty($buyerData['registrationDate']))
            $buyer->setRegistrationDate((string) $buyerData['registrationDate']);
        $buyer->setRegistrationAddress((string) $buyerData['registrationAddress']);
        $buyer->setIp((string) $buyerData['ip']);
        $buyer->setCity((string) $buyerData['city']);
        $buyer->setCountry((string) $buyerData['country']);
        $buyer->setZipCode((string) $buyerData['zipCode']);

        return $buyer;
    }

    /**
     * Address builder
     *
     * Zorunlu alanlar: contactName, city, country, address, zipCode
     *
     * @param array<string,mixed> $a
     */
    private function buildAddress(array $addressData): Address
    {
        $this->require($addressData, ['contactName', 'city', 'country', 'address', 'zipCode']);

        $address = new Address();
        $address->setContactName((string) $addressData['contactName']);
        $address->setCity((string) $addressData['city']);
        $address->setCountry((string) $addressData['country']);
        $address->setAddress((string) $addressData['address']);
        $address->setZipCode((string) $addressData['zipCode']);

        return $address;
    }

    /**
     * BasketItem list builder (pazaryeri alanlarıyla birlikte).
     *
     * Her öğe için zorunlu alanlar:
     *  id, name, price, subMerchantKey, subMerchantPrice
     *
     * Opsiyonel:
     *  category1, category2, itemType (PHYSICAL/VIRTUAL, varsayılan PHYSICAL), withholdingTax
     *
     * @param array<int, array<string,mixed>> $items
     * @return array<int, BasketItem>
     */
    private function buildBasketItems(array $items): array
    {
        $basketItems = [];

        foreach ($items as $item) {
            // Pazaryeri bölüşüm için subMerchantKey/subMerchantPrice zorunlu
            $this->require($item, ['id', 'name', 'price', 'subMerchantKey', 'subMerchantPrice']);

            $basketItem = new BasketItem();
            $basketItem->setId((string) $item['id']);
            $basketItem->setName((string) $item['name']);

            // Kategori alanları opsiyonel
            $basketItem->setCategory1((string) ($item['category1'] ?? ''));
            if (!empty($item['category2'])) {
                $basketItem->setCategory2((string) $item['category2']);
            }

            // Ürün tipi: PHYSICAL/VIRTUAL (varsayılan PHYSICAL)
            $basketItem->setItemType($this->normalizeItemType($item['itemType'] ?? BasketItemType::PHYSICAL));

            // Fiyat alanları string olarak gönderilmeli
            $basketItem->setPrice((string) $item['price']);

            // Pazaryeri zorunlu alanları
            $basketItem->setSubMerchantKey((string) $item['subMerchantKey']);
            $basketItem->setSubMerchantPrice((string) $item['subMerchantPrice']);

            // Tevkifat (opsiyonel) — SDK modelinde alan mevcut
            if (isset($item['withholdingTax'])) {
                // Tipini SDK'ya uygun gönder (genelde sayı/oran)
                $basketItem->setWithholdingTax($item['withholdingTax']);
            }

            $basketItems[] = $basketItem;
        }

        return $basketItems;
    }

    /**
     * Sepet elemanlarının asgari zorunluluklarını topluca doğrular.
     *
     * @param mixed $items
     */
    private function assertBasketItems(mixed $items): void
    {
        if (!is_array($items) || count($items) < 1) {
            throw new InvalidArgumentException('basketItems en az 1 öğe içermelidir.');
        }
        foreach ($items as $item) {
            $this->require($item, ['id', 'name', 'price', 'subMerchantKey', 'subMerchantPrice']);
        }
    }

    /**
     * Para birimini Iyzipay Currency sabitlerine normalize eder.
     *
     * - "TL" veya "TRY" -> Currency::TL (değeri "TRY")
     * - Bilinmeyenler -> Currency::TL
     */
    private function normalizeCurrency(string $currency): string
    {
        $currency = strtoupper($currency);
        if ($currency === 'TRY' || $currency === 'TL') {
            return Currency::TL; // SDK’da "TRY"
        }

        return match ($currency) {
            'USD' => Currency::USD,
            'EUR' => Currency::EUR,
            'GBP' => Currency::GBP,
            'IRR' => Currency::IRR,
            'NOK' => Currency::NOK,
            'RUB' => Currency::RUB,
            'CHF' => Currency::CHF,
            default => Currency::TL,
        };
    }

    /**
     * PaymentGroup normalize (PRODUCT/LISTING/SUBSCRIPTION).
     */

    private function normalizePaymentGroup(string $group): string
    {
        return match (strtoupper($group)) {
            'PRODUCT' => PaymentGroup::PRODUCT,
            'LISTING' => PaymentGroup::LISTING,
            'SUBSCRIPTION' => PaymentGroup::SUBSCRIPTION,
            default => PaymentGroup::PRODUCT,
        };
    }

    /**
     * PaymentChannel normalize (WEB/MOBILE/...).
     *
     * Not: CheckoutForm ve PWI init isteklerinde `setPaymentChannel` bulunmayabilir.
     * Bu yüzden sadece CreatePaymentRequest tarafında kullanılır.
     */
    private function normalizePaymentChannel(string $channel): string
    {
        return match (strtoupper($channel)) {
            'WEB' => PaymentChannel::WEB,
            'MOBILE' => PaymentChannel::MOBILE,
            'MOBILE_WEB' => PaymentChannel::MOBILE_WEB,
            'MOBILE_IOS' => PaymentChannel::MOBILE_IOS,
            'MOBILE_ANDROID' => PaymentChannel::MOBILE_ANDROID,
            'MOBILE_WINDOWS' => PaymentChannel::MOBILE_WINDOWS,
            'MOBILE_TABLET' => PaymentChannel::MOBILE_TABLET,
            'MOBILE_PHONE' => PaymentChannel::MOBILE_PHONE,
            default => PaymentChannel::WEB,
        };
    }

    /**
     * Basket item type normalize (PHYSICAL/VIRTUAL).
     */

    private function normalizeItemType(string $type): string
    {
        return match (strtoupper($type)) {
            'PHYSICAL' => BasketItemType::PHYSICAL,
            'VIRTUAL' => BasketItemType::VIRTUAL,
            default => BasketItemType::PHYSICAL,
        };
    }

    /**
     * Basit zorunlu alan kontrolü (boş string/null kabul edilmez).
     *
     * @param array<string,mixed> $data
     * @param array<int,string> $fields
     */
    private function require(array $data, array $fields): void
    {
        foreach ($fields as $field) {
            if (!array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '') {
                throw new InvalidArgumentException("Missing required field: {$field}");
            }
        }
    }

    /**
     * Iyzipay response nesnesini (Payment/ThreedsInitialize/CheckoutForm...) okunaklı diziye çevirir.
     *
     * - `ok`: status === "success" ise true
     * - `raw`: ham JSON (array) veya parse edilemediyse string
     *
     * @param object $response Iyzipay\Model\* dönen nesne
     * @return array<string,mixed>
     */
    private function responseToArray(object $response): array
    {
        // Birçok Iyzipay modelinde getRawResult() JSON string döner
        $rawResult = method_exists($response, 'getRawResult') ? $response->getRawResult() : null;
        $parsedRaw = $rawResult ? json_decode($rawResult, true) : [];

        // Yaygın alanlara hızlı erişim
        $status = method_exists($response, 'getStatus') ? $response->getStatus() : ($parsedRaw['status'] ?? null);
        $errorCode = method_exists($response, 'getErrorCode') ? $response->getErrorCode() : ($parsedRaw['errorCode'] ?? null);
        $errorMessage = method_exists($response, 'getErrorMessage') ? $response->getErrorMessage() : ($parsedRaw['errorMessage'] ?? null);

        // Ödemeye özgü bazı alanları da kolayca dönelim (varsa)
        $paymentId = $parsedRaw['paymentId'] ?? null;
        $authCode = $parsedRaw['authCode'] ?? null;
        $fraudStatus = $parsedRaw['fraudStatus'] ?? null;

        return [
            'ok' => ($status === 'success'),
            'status' => $status,
            'errorCode' => $errorCode,
            'errorMessage' => $errorMessage,
            'paymentId' => $paymentId,
            'authCode' => $authCode,
            'fraudStatus' => $fraudStatus,
            'raw' => $parsedRaw ?: $rawResult, // JSON parse edilemediyse string halinde bırak
        ];
    }
}
