<?php
declare(strict_types=1);

namespace Eren5\PhpIyzico\Services\Checkout;

use Eren5\PhpIyzico\Config;
use Eren5\PhpIyzico\OptionsFactory;
use Eren5\PhpIyzico\Support\Helpers;
use Eren5\PhpIyzico\Security\Signature as Sig;

use Iyzipay\Model\CheckoutForm;
use Iyzipay\Model\CheckoutFormInitialize;
use Iyzipay\Model\CheckoutFormInitializePreAuth;
use Iyzipay\Model\Currency;
use Iyzipay\Model\PaymentGroup;
use Iyzipay\Options;
use Iyzipay\Request\CreateCheckoutFormInitializeRequest;
use Iyzipay\Request\RetrieveCheckoutFormRequest;
use InvalidArgumentException;

final class CheckoutFormService
{
    public function __construct(private Config $cfg)
    {
    }

    /**
     * Standart Checkout Form başlatma.
     *
     * @param array<string,mixed> $order
     *   Zorunlu: price
     *   Opsiyonel: paidPrice, currency (default TL), basketId, paymentGroup ('PRODUCT'), locale, conversationId,
     *              enabledInstallments (int[]), cardUserKey
     * @param array<string,mixed> $buyer
     * @param array<string,mixed> $shipAddress
     * @param array<string,mixed> $billAddress
     * @param array<int,array<string,mixed>> $basketItems
     */
    public function initialize(
        array $order,
        array $buyer,
        array $shipAddress,
        array $billAddress,
        array $basketItems,
        string $callbackUrl
    ): CheckoutFormInitialize {
        
        if (!isset($order['price'])) {
            throw new InvalidArgumentException('order.price zorunludur.');
        }

        $r = new CreateCheckoutFormInitializeRequest();
        $r->setLocale($order['locale'] ?? $this->cfg->locale);
        $r->setConversationId($order['conversationId'] ?? $this->cfg->conversationId);
        $r->setPrice((string) $order['price']);
        $r->setPaidPrice((string) ($order['paidPrice'] ?? $order['price']));
        $r->setCurrency($order['currency'] ?? Currency::TL);

        if (!empty($order['basketId'])) {
            $r->setBasketId((string) $order['basketId']);
        }
        $r->setPaymentGroup($order['paymentGroup'] ?? PaymentGroup::PRODUCT);
        $r->setCallbackUrl($callbackUrl);

        // Taksite izinli liste
        if (!empty($order['enabledInstallments']) && is_array($order['enabledInstallments'])) {
            $r->setEnabledInstallments(array_values($order['enabledInstallments']));
        }

        // Kayıtlı kart gösterimi (SDK sürümüne bağlı)
        if (!empty($order['cardUserKey']) && method_exists($r, 'setCardUserKey')) {
            $r->setCardUserKey((string) $order['cardUserKey']);
        }

        // Buyer / Address / Basket
        $r->setBuyer(Helpers::buyer($buyer));
        $r->setShippingAddress(Helpers::address($shipAddress));
        $r->setBillingAddress(Helpers::address($billAddress));

        $items = [];
        foreach ($basketItems as $it) {
            $items[] = Helpers::basketItem($it);
        }
        $r->setBasketItems($items);

        return CheckoutFormInitialize::create($r, OptionsFactory::create($this->cfg));
    }

    /**
     * Pre-Authorization (Ön Provizyon) için Checkout Form başlatma.
     */
    public function initializePreAuth(
        array $order,
        array $buyer,
        array $shipAddress,
        array $billAddress,
        array $basketItems,
        string $callbackUrl
    ): CheckoutFormInitializePreAuth {
        if (!isset($order['price'])) {
            throw new InvalidArgumentException('order.price zorunludur.');
        }

        $r = new CreateCheckoutFormInitializeRequest();
        $r->setLocale($order['locale'] ?? $this->cfg->locale);
        $r->setConversationId($order['conversationId'] ?? $this->cfg->conversationId);
        $r->setPrice((string) $order['price']);
        $r->setPaidPrice((string) ($order['paidPrice'] ?? $order['price']));
        $r->setCurrency($order['currency'] ?? Currency::TL);

        if (!empty($order['basketId'])) {
            $r->setBasketId((string) $order['basketId']);
        }
        $r->setPaymentGroup($order['paymentGroup'] ?? PaymentGroup::PRODUCT);
        $r->setCallbackUrl($callbackUrl);

        if (!empty($order['enabledInstallments']) && is_array($order['enabledInstallments'])) {
            $r->setEnabledInstallments(array_values($order['enabledInstallments']));
        }

        if (!empty($order['cardUserKey']) && method_exists($r, 'setCardUserKey')) {
            $r->setCardUserKey((string) $order['cardUserKey']);
        }

        $r->setBuyer(Helpers::buyer($buyer));
        $r->setShippingAddress(Helpers::address($shipAddress));
        $r->setBillingAddress(Helpers::address($billAddress));

        $items = [];
        foreach ($basketItems as $it) {
            $items[] = Helpers::basketItem($it);
        }
        $r->setBasketItems($items);

        return CheckoutFormInitializePreAuth::create($r, OptionsFactory::create($this->cfg));
    }

    /**
     * Initialize cevabının imzasını doğrular (sıra: conversationId, token).
     * Hem CheckoutFormInitialize hem de PreAuth initialize için geçerlidir.
     */
    public function verifyInitializeSignature(object $init, ?Options $opt = null): bool
    {
        if (
            !method_exists($init, 'getToken') ||
            !method_exists($init, 'getConversationId') ||
            !method_exists($init, 'getSignature')
        ) {
            throw new InvalidArgumentException('Geçersiz initialize nesnesi.');
        }

        $options = $opt ?? OptionsFactory::create($this->cfg);
        $secret = (string) $options->getSecretKey();

        $params = [
            (string) $init->getConversationId(),
            (string) $init->getToken(),
        ];
        // Önceki yanıtta eklediğimiz Signature::calculate (":" ile birleştirir)
        $calc = Sig::calculate($params, $secret);

        return hash_equals((string) $init->getSignature(), $calc);
    }

    /**
     * Initialize + imza doğrulama (tek adımda).
     * @return array{init: CheckoutFormInitialize|CheckoutFormInitializePreAuth, verified: bool, calculated: string}
     */
    public function initializeAndVerify(
        array $order,
        array $buyer,
        array $shipAddress,
        array $billAddress,
        array $basketItems,
        string $callbackUrl,
        bool $preAuth = false
    ): array {
        $opt = OptionsFactory::create($this->cfg);
        $init = $preAuth
            ? $this->initializePreAuth($order, $buyer, $shipAddress, $billAddress, $basketItems, $callbackUrl)
            : $this->initialize($order, $buyer, $shipAddress, $billAddress, $basketItems, $callbackUrl);

        $params = [
            (string) $init->getConversationId(),
            (string) $init->getToken(),
        ];
        $calc = Sig::calculate($params, (string) $opt->getSecretKey());
        $verified = hash_equals((string) $init->getSignature(), $calc);

        return ['init' => $init, 'verified' => $verified, 'calculated' => $calc];
    }

    /**
     * Ödeme sonucunu CheckoutForm token’ı ile getirir.
     */
    public function retrieve(string $token): CheckoutForm
    {
        $r = new RetrieveCheckoutFormRequest();
        $r->setLocale($this->cfg->locale);
        $r->setConversationId($this->cfg->conversationId);
        $r->setToken($token);

        return CheckoutForm::retrieve($r, OptionsFactory::create($this->cfg));
    }

    /**
     * CheckoutForm sonucunun imzasını doğrular.
     * Sıra: paymentStatus, paymentId, currency, basketId, conversationId, paidPrice, price, token
     */
    public function verifyRetrieveSignature(CheckoutForm $cf, ?Options $opt = null): bool
    {
        $options = $opt ?? OptionsFactory::create($this->cfg);
        $secret = (string) $options->getSecretKey();

        $params = [
            (string) $cf->getPaymentStatus(),
            (string) $cf->getPaymentId(),
            (string) $cf->getCurrency(),
            (string) $cf->getBasketId(),
            (string) $cf->getConversationId(),
            (string) $cf->getPaidPrice(),
            (string) $cf->getPrice(),
            (string) $cf->getToken(),
        ];
        $calc = Sig::calculate($params, $secret);

        return hash_equals((string) $cf->getSignature(), $calc);
    }

    /**
     * Retrieve + imza doğrulama (tek adımda).
     * @return array{form: CheckoutForm, verified: bool, calculated: string}
     */
    public function retrieveAndVerify(string $token): array
    {
        $opt = OptionsFactory::create($this->cfg);
        $cf = $this->retrieve($token);

        $params = [
            (string) $cf->getPaymentStatus(),
            (string) $cf->getPaymentId(),
            (string) $cf->getCurrency(),
            (string) $cf->getBasketId(),
            (string) $cf->getConversationId(),
            (string) $cf->getPaidPrice(),
            (string) $cf->getPrice(),
            (string) $cf->getToken(),
        ];
        $calc = Sig::calculate($params, (string) $opt->getSecretKey());
        $verified = hash_equals((string) $cf->getSignature(), $calc);

        return ['form' => $cf, 'verified' => $verified, 'calculated' => $calc];
    }
}
