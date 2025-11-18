<?php
declare(strict_types=1);

namespace Eren5\PhpIyzico\Services\PWI;

use Eren5\PhpIyzico\Config;
use Eren5\PhpIyzico\OptionsFactory;
use Eren5\PhpIyzico\Security\Signature;

// Iyzipay Core/Enums
use Iyzipay\Options;
use Iyzipay\Model\Locale;
use Iyzipay\Model\Currency;
use Iyzipay\Model\PaymentGroup;
use Iyzipay\Model\BasketItemType;

// Iyzipay Domain Models
use Iyzipay\Model\Buyer;
use Iyzipay\Model\Address;
use Iyzipay\Model\BasketItem;

// Iyzipay Requests & Models (PWI)
use Iyzipay\Request\CreatePayWithIyzicoInitializeRequest;
use Iyzipay\Request\RetrievePayWithIyzicoRequest;
use Iyzipay\Model\PayWithIyzicoInitialize;
use Iyzipay\Model\PayWithIyzico;

/**
 * Pay with iyzico (PWI) sarmalayıcı servis.
 *
 * - create():   PWI başlatma (token üretir, kullanıcıyı iyzico sayfasına yönlendirirsiniz)
 * - retrieve(): PWI sonucu/ödeme detaylarını token ile çeker
 * - İmza doğrulama: create/retrieve cevapları için Signature sınıfı kullanılır
 */
final class PWIService
{
    private Options $options;

    public function __construct(
        private Config $config,
        private Signature $signature
    ) {
        // Senin OptionsFactory'in statik create(Config $cfg): Options
        $this->options = OptionsFactory::create($this->config);
    }

    /**
     * PWI Başlatma (CreatePayWithIyzicoInitialize) — BASİT İSİM: create
     *
     * Zorunlu: price, paidPrice, currency, basketId, paymentGroup, callbackUrl,
     * buyer, shippingAddress, billingAddress, basketItems
     *
     * @param array{
     *   locale?:string,
     *   conversationId?:string,
     *   price:float|int|string,
     *   paidPrice:float|int|string,
     *   currency:string|int,
     *   basketId:string,
     *   paymentGroup:string|int,
     *   callbackUrl:string,
     *   enabledInstallments?:int[],
     *   buyer: array<string,mixed>,
     *   shippingAddress: array<string,mixed>,
     *   billingAddress: array<string,mixed>,
     *   basketItems: array<int,array<string,mixed>>
     * } $data
     *
     * @return array{verified:bool}|array<string,mixed>
     */
    public function create(array $data): array
    {
        foreach (['price', 'paidPrice', 'currency', 'basketId', 'paymentGroup', 'callbackUrl', 'buyer', 'shippingAddress', 'billingAddress', 'basketItems'] as $f) {
            if (!array_key_exists($f, $data)) {
                throw new \InvalidArgumentException("Missing required field: {$f}");
            }
        }

        $req = new CreatePayWithIyzicoInitializeRequest();
        $req->setLocale($data['locale'] ?? Locale::TR);
        $req->setConversationId($data['conversationId'] ?? (string) microtime(true));
        $req->setPrice((string) $data['price']);
        $req->setPaidPrice((string) $data['paidPrice']);
        $req->setCurrency(is_string($data['currency']) ? Currency::TL : $data['currency']); // string gelirse TRY varsayımı
        $req->setBasketId((string) $data['basketId']);
        $req->setPaymentGroup(is_string($data['paymentGroup']) ? PaymentGroup::PRODUCT : $data['paymentGroup']);
        $req->setCallbackUrl((string) $data['callbackUrl']);

        if (!empty($data['enabledInstallments']) && is_array($data['enabledInstallments'])) {
            $req->setEnabledInstallments(array_values(array_map('intval', $data['enabledInstallments'])));
        }

        // Buyer / Address / Items
        $req->setBuyer($this->mapBuyer($data['buyer']));
        $req->setShippingAddress($this->mapAddress($data['shippingAddress']));
        $req->setBillingAddress($this->mapAddress($data['billingAddress']));
        $req->setBasketItems($this->mapBasketItems($data['basketItems']));

        // API çağrısı
        $res = PayWithIyzicoInitialize::create($req, $this->options);

        // İmza doğrulama: [conversationId, token]
        $conversationId = (string) $res->getConversationId();
        $token = (string) $res->getToken();
        $sigServer = (string) $res->getSignature();

        $sigCalc = $this->signature::calculateColonSeparated([$conversationId, $token], (string) $this->options->getSecretKey());
        $verified = hash_equals($sigServer, $sigCalc);

        return $this->normalize($res) + ['verified' => $verified];
    }

    /**
     * @deprecated Eski ad. Yeni adı: create().
     *             Geriye dönük uyumluluk için bırakıldı.
     *             Kullandığın yerlerde create(...) ile değiştir.
     */
    public function initialize(array $data): array
    {
        return $this->create($data);
    }

    /**
     * PWI Sonucu/Ödeme Detayı Getirme (RetrievePayWithIyzico) — BASİT İSİM: retrieve
     *
     * @return array{verified:bool}|array<string,mixed>
     */
    public function retrieve(string $token, ?string $conversationId = null, ?string $locale = null): array
    {
        $req = new RetrievePayWithIyzicoRequest();
        $req->setLocale($locale ?? Locale::TR);
        $req->setConversationId($conversationId ?? (string) microtime(true));
        $req->setToken($token);

        $res = PayWithIyzico::retrieve($req, $this->options);

        // İmza doğrulama:
        // [paymentStatus, paymentId, currency, basketId, conversationId, paidPrice, price, token]
        $sigServer = (string) $res->getSignature();
        $parts = [
            (string) $res->getPaymentStatus(),
            (string) $res->getPaymentId(),
            (string) $res->getCurrency(),
            (string) $res->getBasketId(),
            (string) $res->getConversationId(),
            (string) $res->getPaidPrice(),
            (string) $res->getPrice(),
            (string) $res->getToken(),
        ];
        $sigCalc = $this->signature::calculateColonSeparated($parts, (string) $this->options->getSecretKey());
        $verified = hash_equals($sigServer, $sigCalc);

        return $this->normalize($res) + ['verified' => $verified];
    }

    // -----------------------------
    // Mapping helpers (yalın & net)
    // -----------------------------

    /** @param array<string,mixed> $src */
    private function mapBuyer(array $src): Buyer
    {
        $b = new Buyer();
        isset($src['id']) && $b->setId((string) $src['id']);
        isset($src['name']) && $b->setName((string) $src['name']);
        isset($src['surname']) && $b->setSurname((string) $src['surname']);
        isset($src['gsmNumber']) && $b->setGsmNumber((string) $src['gsmNumber']);
        isset($src['email']) && $b->setEmail((string) $src['email']);
        isset($src['identityNumber']) && $b->setIdentityNumber((string) $src['identityNumber']);
        isset($src['lastLoginDate']) && $b->setLastLoginDate((string) $src['lastLoginDate']);
        isset($src['registrationDate']) && $b->setRegistrationDate((string) $src['registrationDate']);
        isset($src['registrationAddress']) && $b->setRegistrationAddress((string) $src['registrationAddress']);
        isset($src['ip']) && $b->setIp((string) $src['ip']);
        isset($src['city']) && $b->setCity((string) $src['city']);
        isset($src['country']) && $b->setCountry((string) $src['country']);
        isset($src['zipCode']) && $b->setZipCode((string) $src['zipCode']);
        return $b;
    }

    /** @param array<string,mixed> $src */
    private function mapAddress(array $src): Address
    {
        $a = new Address();
        isset($src['contactName']) && $a->setContactName((string) $src['contactName']);
        isset($src['city']) && $a->setCity((string) $src['city']);
        isset($src['country']) && $a->setCountry((string) $src['country']);
        isset($src['address']) && $a->setAddress((string) $src['address']);
        isset($src['zipCode']) && $a->setZipCode((string) $src['zipCode']);
        return $a;
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @return BasketItem[]
     */
    private function mapBasketItems(array $items): array
    {
        $out = [];
        foreach ($items as $i => $src) {
            $bi = new BasketItem();
            isset($src['id']) && $bi->setId((string) $src['id']);
            isset($src['name']) && $bi->setName((string) $src['name']);
            isset($src['category1']) && $bi->setCategory1((string) $src['category1']);
            isset($src['category2']) && $bi->setCategory2((string) $src['category2']);
            if (isset($src['itemType'])) {
                $bi->setItemType(is_string($src['itemType']) ? BasketItemType::PHYSICAL : $src['itemType']);
            }
            isset($src['price']) && $bi->setPrice((string) $src['price']);
            $out[$i] = $bi;
        }
        return array_values($out);
    }

    /** SDK cevabını diziye normalleştirir. */
    private function normalize(object $sdkResponse): array
    {
        if (method_exists($sdkResponse, 'getRawResult')) {
            $raw = $sdkResponse->getRawResult();
            if (is_string($raw) && $raw !== '') {
                $arr = json_decode($raw, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($arr)) {
                    return $arr;
                }
                return ['rawResult' => $raw];
            }
        }
        $json = json_encode($sdkResponse, JSON_UNESCAPED_UNICODE);
        if ($json !== false) {
            $arr = json_decode($json, true);
            if (is_array($arr)) {
                return $arr;
            }
        }
        return ['result' => (string) print_r($sdkResponse, true)];
    }
}
