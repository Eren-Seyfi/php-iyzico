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
        $req = $this->buildCreatePaymentRequest($payment);
        $resp = Payment::create($req, $this->options);

        // Basit normalize edilmiş dizi döndür
        return $this->responseToArray($resp);
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
        $req = $this->buildCreatePaymentRequest($payment);

        // 3DS callback adresi: bankadan dönüş bu adrese gönderilir
        $req->setCallbackUrl((string) $payment['callbackUrl']);

        /** @var ThreedsInitialize $resp */
        $resp = ThreedsInitialize::create($req, $this->options);

        $arr = $this->responseToArray($resp);

        // HTML içerik genelde 'threeDSHtmlContent' ya da 'htmlContent' alanı ile gelir
        $raw = $arr['raw'];
        if (is_array($raw)) {
            $arr['threeDSHtmlContent'] = $raw['threeDSHtmlContent'] ?? $raw['htmlContent'] ?? null;
        }
        return $arr;
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

        $req = new CreateThreedsPaymentRequest();
        $req->setLocale($data['locale'] ?? Locale::TR);
        $req->setConversationId($data['conversationId'] ?? (string) microtime(true));
        $req->setPaymentId((string) $data['paymentId']);
        $req->setConversationData((string) $data['conversationData']);

        /** @var ThreedsPayment $resp */
        $resp = ThreedsPayment::create($req, $this->options);
        return $this->responseToArray($resp);
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

        $req = new CreateCheckoutFormInitializeRequest();
        $req->setLocale($payment['locale'] ?? Locale::TR);
        $req->setConversationId($payment['conversationId'] ?? (string) microtime(true));
        $req->setPrice((string) $payment['price']);
        $req->setPaidPrice((string) $payment['paidPrice']);
        $req->setCurrency($this->normalizeCurrency($payment['currency']));
        $req->setCallbackUrl((string) $payment['callbackUrl']);
        if (!empty($payment['basketId'])) {
            $req->setBasketId((string) $payment['basketId']);
        }
        // setPaymentChannel yok — çağrılmıyor
        $req->setPaymentGroup($this->normalizePaymentGroup($payment['paymentGroup'] ?? PaymentGroup::PRODUCT));

        // Taksit kısıtlaması (banka bazlı)
        if (!empty($payment['enabledInstallments']) && is_array($payment['enabledInstallments'])) {
            $req->setEnabledInstallments($payment['enabledInstallments']);
        }

        // Zorunlu parçalar
        $req->setBuyer($this->buildBuyer($payment['buyer']));
        $req->setShippingAddress($this->buildAddress($payment['shippingAddress']));
        $req->setBillingAddress($this->buildAddress($payment['billingAddress']));
        $req->setBasketItems($this->buildBasketItems($payment['basketItems']));

        $resp = CheckoutFormInitialize::create($req, $this->options);

        // Yanıtı normalize et
        $arr = $this->responseToArray($resp);
        $raw = $arr['raw'];
        if (is_array($raw)) {
            $arr['checkoutFormContent'] = $raw['checkoutFormContent'] ?? null;
            $arr['token'] = $raw['token'] ?? null;
            $arr['tokenExpireTime'] = $raw['tokenExpireTime'] ?? null;
        }
        return $arr;
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

        $req = new RetrieveCheckoutFormRequest();
        $req->setLocale($data['locale'] ?? Locale::TR);
        $req->setConversationId($data['conversationId'] ?? (string) microtime(true));
        $req->setToken((string) $data['token']);

        $resp = CheckoutForm::retrieve($req, $this->options);
        return $this->responseToArray($resp);
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

        $req = new CreatePayWithIyzicoInitializeRequest();
        $req->setLocale($payment['locale'] ?? Locale::TR);
        $req->setConversationId($payment['conversationId'] ?? (string) microtime(true));
        $req->setPrice((string) $payment['price']);
        $req->setPaidPrice((string) $payment['paidPrice']);
        $req->setCurrency($this->normalizeCurrency($payment['currency']));
        $req->setCallbackUrl((string) $payment['callbackUrl']);
        if (!empty($payment['basketId'])) {
            $req->setBasketId((string) $payment['basketId']);
        }

        // setPaymentChannel yok — çağrılmıyor
        $req->setPaymentGroup($this->normalizePaymentGroup($payment['paymentGroup'] ?? PaymentGroup::PRODUCT));

        if (!empty($payment['enabledInstallments']) && is_array($payment['enabledInstallments'])) {
            $req->setEnabledInstallments($payment['enabledInstallments']);
        }
        $req->setBuyer($this->buildBuyer($payment['buyer']));
        $req->setShippingAddress($this->buildAddress($payment['shippingAddress']));
        $req->setBillingAddress($this->buildAddress($payment['billingAddress']));
        $req->setBasketItems($this->buildBasketItems($payment['basketItems']));

        $resp = PayWithIyzicoInitialize::create($req, $this->options);

        $arr = $this->responseToArray($resp);
        $raw = $arr['raw'];
        if (is_array($raw)) {
            $arr['payWithIyzicoPageUrl'] = $raw['payWithIyzicoPageUrl'] ?? null;
            $arr['token'] = $raw['token'] ?? null;
            $arr['tokenExpireTime'] = $raw['tokenExpireTime'] ?? null;
        }
        return $arr;
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

        $req = new RetrievePayWithIyzicoRequest();
        $req->setLocale($data['locale'] ?? Locale::TR);
        $req->setConversationId($data['conversationId'] ?? (string) microtime(true));
        $req->setToken((string) $data['token']);

        $resp = PayWithIyzico::retrieve($req, $this->options);
        return $this->responseToArray($resp);
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
    private function buildCreatePaymentRequest(array $p): CreatePaymentRequest
    {
        $req = new CreatePaymentRequest();

        // Locale/conversationId yoksa varsayılanları kullan (mikro saniyeli fallback)
        $req->setLocale($p['locale'] ?? Locale::TR);
        $req->setConversationId($p['conversationId'] ?? (string) microtime(true));

        // Zorunlu parasal alanlar (string olarak set edilir)
        $req->setPrice((string) $p['price']);
        $req->setPaidPrice((string) $p['paidPrice']);

        // Para birimi normalize edilir (TL/TRY farkı gözetilir)
        $req->setCurrency($this->normalizeCurrency($p['currency']));

        // Taksit sayısı (yoksa 1)
        $req->setInstallment((int) ($p['installment'] ?? 1));

        // Sepet ID (opsiyonel)
        if (!empty($p['basketId'])) {
            $req->setBasketId((string) $p['basketId']);
        }

        // Ödeme kanalı/grubu normalize edilir
        $req->setPaymentChannel($this->normalizePaymentChannel($p['paymentChannel'] ?? PaymentChannel::WEB));
        $req->setPaymentGroup($this->normalizePaymentGroup($p['paymentGroup'] ?? PaymentGroup::PRODUCT));

        // Kart/alıcı/adres/sepet builder’ları
        $req->setPaymentCard($this->buildPaymentCard($p['paymentCard']));
        $req->setBuyer($this->buildBuyer($p['buyer']));
        $req->setShippingAddress($this->buildAddress($p['shippingAddress']));
        $req->setBillingAddress($this->buildAddress($p['billingAddress']));
        $req->setBasketItems($this->buildBasketItems($p['basketItems']));

        return $req;
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

        $pc = new PaymentCard();
        $pc->setCardHolderName((string) $card['cardHolderName']);
        $pc->setCardNumber((string) $card['cardNumber']);
        $pc->setExpireMonth((string) $card['expireMonth']);
        $pc->setExpireYear((string) $card['expireYear']);
        $pc->setCvc((string) $card['cvc']);
        $pc->setRegisterCard((int) ($card['registerCard'] ?? 0));

        // Opsiyoneller (SDK modelinde mevcut)
        if (isset($card['cardAlias']))
            $pc->setCardAlias((string) $card['cardAlias']);
        if (isset($card['cardToken']))
            $pc->setCardToken((string) $card['cardToken']);
        if (isset($card['cardUserKey']))
            $pc->setCardUserKey((string) $card['cardUserKey']);
        if (isset($card['registerConsumerCard']))
            $pc->setRegisterConsumerCard((int) $card['registerConsumerCard']);
        if (isset($card['ucsToken']))
            $pc->setUcsToken((string) $card['ucsToken']);
        if (isset($card['consumerToken']))
            $pc->setConsumerToken((string) $card['consumerToken']);

        return $pc;
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
    private function buildBuyer(array $b): Buyer
    {
        $this->require($b, [
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
        $buyer->setId((string) $b['id']);
        $buyer->setName((string) $b['name']);
        $buyer->setSurname((string) $b['surname']);
        $buyer->setGsmNumber((string) $b['gsmNumber']);
        $buyer->setEmail((string) $b['email']);
        $buyer->setIdentityNumber((string) $b['identityNumber']);
        if (!empty($b['lastLoginDate']))
            $buyer->setLastLoginDate((string) $b['lastLoginDate']);
        if (!empty($b['registrationDate']))
            $buyer->setRegistrationDate((string) $b['registrationDate']);
        $buyer->setRegistrationAddress((string) $b['registrationAddress']);
        $buyer->setIp((string) $b['ip']);
        $buyer->setCity((string) $b['city']);
        $buyer->setCountry((string) $b['country']);
        $buyer->setZipCode((string) $b['zipCode']);

        return $buyer;
    }

    /**
     * Address builder
     *
     * Zorunlu alanlar: contactName, city, country, address, zipCode
     *
     * @param array<string,mixed> $a
     */
    private function buildAddress(array $a): Address
    {
        $this->require($a, ['contactName', 'city', 'country', 'address', 'zipCode']);

        $ad = new Address();
        $ad->setContactName((string) $a['contactName']);
        $ad->setCity((string) $a['city']);
        $ad->setCountry((string) $a['country']);
        $ad->setAddress((string) $a['address']);
        $ad->setZipCode((string) $a['zipCode']);

        return $ad;
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
        $out = [];

        foreach ($items as $it) {
            // Pazaryeri bölüşüm için subMerchantKey/subMerchantPrice zorunlu
            $this->require($it, ['id', 'name', 'price', 'subMerchantKey', 'subMerchantPrice']);

            $bi = new BasketItem();
            $bi->setId((string) $it['id']);
            $bi->setName((string) $it['name']);

            // Kategori alanları opsiyonel
            $bi->setCategory1((string) ($it['category1'] ?? ''));
            if (!empty($it['category2'])) {
                $bi->setCategory2((string) $it['category2']);
            }

            // Ürün tipi: PHYSICAL/VIRTUAL (varsayılan PHYSICAL)
            $bi->setItemType($this->normalizeItemType($it['itemType'] ?? BasketItemType::PHYSICAL));

            // Fiyat alanları string olarak gönderilmeli
            $bi->setPrice((string) $it['price']);

            // Pazaryeri zorunlu alanları
            $bi->setSubMerchantKey((string) $it['subMerchantKey']);
            $bi->setSubMerchantPrice((string) $it['subMerchantPrice']);

            // Tevkifat (opsiyonel) — SDK modelinde alan mevcut
            if (isset($it['withholdingTax'])) {
                // Tipini SDK'ya uygun gönder (genelde sayı/oran)
                $bi->setWithholdingTax($it['withholdingTax']);
            }

            $out[] = $bi;
        }

        return $out;
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
        foreach ($items as $it) {
            $this->require($it, ['id', 'name', 'price', 'subMerchantKey', 'subMerchantPrice']);
        }
    }

    /**
     * Para birimini Iyzipay Currency sabitlerine normalize eder.
     *
     * - "TL" veya "TRY" -> Currency::TL (değeri "TRY")
     * - Bilinmeyenler -> Currency::TL
     */
    private function normalizeCurrency(string $cur): string
    {
        $cur = strtoupper($cur);
        if ($cur === 'TRY' || $cur === 'TL') {
            return Currency::TL; // SDK’da "TRY"
        }

        return match ($cur) {
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
    private function normalizePaymentChannel(string $ch): string
    {
        return match (strtoupper($ch)) {
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
    
    private function normalizeItemType(string $t): string
    {
        return match (strtoupper($t)) {
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
        foreach ($fields as $f) {
            if (!array_key_exists($f, $data) || $data[$f] === null || $data[$f] === '') {
                throw new InvalidArgumentException("Missing required field: {$f}");
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
        $raw = method_exists($response, 'getRawResult') ? $response->getRawResult() : null;
        $arr = $raw ? json_decode($raw, true) : [];

        // Yaygın alanlara hızlı erişim
        $status = method_exists($response, 'getStatus') ? $response->getStatus() : ($arr['status'] ?? null);
        $errCode = method_exists($response, 'getErrorCode') ? $response->getErrorCode() : ($arr['errorCode'] ?? null);
        $errMsg = method_exists($response, 'getErrorMessage') ? $response->getErrorMessage() : ($arr['errorMessage'] ?? null);

        // Ödemeye özgü bazı alanları da kolayca dönelim (varsa)
        $paymentId = $arr['paymentId'] ?? null;
        $authCode = $arr['authCode'] ?? null;
        $fraudStatus = $arr['fraudStatus'] ?? null;

        return [
            'ok' => ($status === 'success'),
            'status' => $status,
            'errorCode' => $errCode,
            'errorMessage' => $errMsg,
            'paymentId' => $paymentId,
            'authCode' => $authCode,
            'fraudStatus' => $fraudStatus,
            'raw' => $arr ?: $raw, // JSON parse edilemediyse string halinde bırak
        ];
    }
}
