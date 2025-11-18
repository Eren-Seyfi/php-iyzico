<?php
declare(strict_types=1);

namespace Eren5\PhpIyzico\Services\Subscription;

use Eren5\PhpIyzico\Config;
use Eren5\PhpIyzico\OptionsFactory;

// Requests
use Iyzipay\Request\Subscription\SubscriptionCreateCheckoutFormRequest;
use Iyzipay\Request\Subscription\RetrieveSubscriptionCreateCheckoutFormRequest;
use Iyzipay\Request\Subscription\SubscriptionCreateRequest;
use Iyzipay\Request\Subscription\SubscriptionCreateWithCustomerRequest;
use Iyzipay\Request\Subscription\SubscriptionActivateRequest;
use Iyzipay\Request\Subscription\SubscriptionRetryRequest;
use Iyzipay\Request\Subscription\SubscriptionUpgradeRequest;
use Iyzipay\Request\Subscription\SubscriptionCancelRequest;
use Iyzipay\Request\Subscription\SubscriptionDetailsRequest;
use Iyzipay\Request\Subscription\SubscriptionSearchRequest;
use Iyzipay\Request\Subscription\SubscriptionCardUpdateRequest;

// Models
use Iyzipay\Model\PaymentCard;
use Iyzipay\Model\Customer;
use Iyzipay\Model\Subscription\SubscriptionCreateCheckoutForm;
use Iyzipay\Model\Subscription\RetrieveSubscriptionCheckoutForm;
use Iyzipay\Model\Subscription\SubscriptionCreate;
use Iyzipay\Model\Subscription\SubscriptionCreateWithCustomer;
use Iyzipay\Model\Subscription\SubscriptionActivate;
use Iyzipay\Model\Subscription\SubscriptionRetry;
use Iyzipay\Model\Subscription\SubscriptionUpgrade;
use Iyzipay\Model\Subscription\SubscriptionCancel;
use Iyzipay\Model\Subscription\SubscriptionDetails;
use Iyzipay\Model\Subscription\RetrieveList;

/**
 * Abonelik işlemleri (checkout formu, create, upgrade, cancel, search, card update vb.)
 */
final class SubscriptionService
{
    public function __construct(private Config $cfg)
    {

    }

    /**
     * Checkout Form ile abonelik başlatma (iyzico hosted form)
     *
     * @param array{
     *   pricingPlanReferenceCode:string,
     *   subscriptionInitialStatus?:'ACTIVE'|'PENDING',
     *   callbackUrl?:string
     * } $data
     * @param array<string,string> $customerData  // Customer alanları (name,surname,email,... shipping/billing)
     */
    public function createCheckoutForm(array $data, array $customerData)
    {
        $r = new SubscriptionCreateCheckoutFormRequest();
        $r->setLocale($this->cfg->locale);
        $r->setConversationId($this->cfg->conversationId);
        $r->setPricingPlanReferenceCode((string) $data['pricingPlanReferenceCode']);

        if (!empty($data['subscriptionInitialStatus'])) {
            $r->setSubscriptionInitialStatus((string) $data['subscriptionInitialStatus']); // ACTIVE|PENDING
        }
        if (!empty($data['callbackUrl'])) {
            $r->setCallbackUrl((string) $data['callbackUrl']);
        }

        $customer = $this->buildCustomer($customerData);
        $r->setCustomer($customer);

        return SubscriptionCreateCheckoutForm::create($r, OptionsFactory::create($this->cfg));
    }

    /** Checkout form sonucunu token ile çek */
    public function retrieveCheckoutFormResult(string $checkoutFormToken)
    {
        $r = new RetrieveSubscriptionCreateCheckoutFormRequest();
        $r->setCheckoutFormToken($checkoutFormToken);

        return RetrieveSubscriptionCheckoutForm::retrieve($r, OptionsFactory::create($this->cfg));
    }

    /**
     * NON3D abonelik başlatma (kredi kartı ile doğrudan)
     *
     * @param array{pricingPlanReferenceCode:string, subscriptionInitialStatus?:'ACTIVE'|'PENDING'} $data
     * @param array{cardHolderName:string, cardNumber:string, expireMonth:string, expireYear:string, cvc:string} $paymentCardData
     * @param array<string,string> $customerData
     */
    public function createNon3D(array $data, array $paymentCardData, array $customerData)
    {
        $r = new SubscriptionCreateRequest();
        $r->setLocale($this->cfg->locale);
        $r->setConversationId($this->cfg->conversationId);
        $r->setPricingPlanReferenceCode((string) $data['pricingPlanReferenceCode']);

        if (!empty($data['subscriptionInitialStatus'])) {
            $r->setSubscriptionInitialStatus((string) $data['subscriptionInitialStatus']); // PENDING/ACTIVE
        }

        $pc = new PaymentCard();
        $pc->setCardHolderName((string) $paymentCardData['cardHolderName']);
        $pc->setCardNumber((string) $paymentCardData['cardNumber']);
        $pc->setExpireMonth((string) $paymentCardData['expireMonth']);
        $pc->setExpireYear((string) $paymentCardData['expireYear']);
        $pc->setCvc((string) $paymentCardData['cvc']);
        $r->setPaymentCard($pc);

        $customer = $this->buildCustomer($customerData);
        $r->setCustomer($customer);

        return SubscriptionCreate::create($r, OptionsFactory::create($this->cfg));
    }

    /**
     * Mevcut müşteri ile abonelik başlatma
     *
     * @param array{
     *   pricingPlanReferenceCode:string,
     *   customerReferenceCode:string,
     *   subscriptionInitialStatus?:'ACTIVE'|'PENDING'
     * } $data
     */
    public function createWithCustomer(array $data)
    {
        $r = new SubscriptionCreateWithCustomerRequest();
        $r->setLocale($this->cfg->locale);
        $r->setConversationId($this->cfg->conversationId);
        $r->setPricingPlanReferenceCode((string) $data['pricingPlanReferenceCode']);
        $r->setCustomerReferenceCode((string) $data['customerReferenceCode']);

        if (!empty($data['subscriptionInitialStatus'])) {
            $r->setSubscriptionInitialStatus((string) $data['subscriptionInitialStatus']);
        }

        return SubscriptionCreateWithCustomer::create($r, OptionsFactory::create($this->cfg));
    }

    /** Aboneliği aktifleştir */
    public function activate(string $subscriptionRef)
    {
        $r = new SubscriptionActivateRequest();
        $r->setLocale($this->cfg->locale);
        $r->setConversationId($this->cfg->conversationId);
        $r->setSubscriptionReferenceCode($subscriptionRef);

        return SubscriptionActivate::update($r, OptionsFactory::create($this->cfg));
    }

    /** Başarısız ödemeyi/denemeyi tekrar dene (retry) */
    public function retry(string $referenceCode)
    {
        $r = new SubscriptionRetryRequest();
        $r->setLocale($this->cfg->locale);
        $r->setConversationId($this->cfg->conversationId);
        $r->setReferenceCode($referenceCode);

        return SubscriptionRetry::update($r, OptionsFactory::create($this->cfg));
    }

    /**
     * Aboneliği başka bir plana yükselt
     *
     * @param array{
     *   subscriptionReferenceCode:string,
     *   newPricingPlanReferenceCode:string,
     *   upgradePeriod:'NOW'|'NEXT_PERIOD',
     *   useTrial?:bool,
     *   resetRecurrenceCount?:bool
     * } $data
     */
    public function upgrade(array $data)
    {
        $r = new SubscriptionUpgradeRequest();
        $r->setLocale($this->cfg->locale);
        $r->setConversationId($this->cfg->conversationId);
        $r->setSubscriptionReferenceCode((string) $data['subscriptionReferenceCode']);
        $r->setNewPricingPlanReferenceCode((string) $data['newPricingPlanReferenceCode']);
        $r->setUpgradePeriod((string) $data['upgradePeriod']); // NOW | NEXT_PERIOD

        if (array_key_exists('useTrial', $data)) {
            $r->setUseTrial((bool) $data['useTrial']);
        }
        if (array_key_exists('resetRecurrenceCount', $data)) {
            $r->setResetRecurrenceCount((bool) $data['resetRecurrenceCount']);
        }

        return SubscriptionUpgrade::update($r, OptionsFactory::create($this->cfg));
    }

    /** Aboneliği iptal et */
    public function cancel(string $subscriptionRef)
    {
        $r = new SubscriptionCancelRequest();
        $r->setLocale($this->cfg->locale);
        $r->setConversationId($this->cfg->conversationId);
        $r->setSubscriptionReferenceCode($subscriptionRef);

        return SubscriptionCancel::cancel($r, OptionsFactory::create($this->cfg));
    }

    /** Abonelik detayı */
    public function details(string $subscriptionRef)
    {
        $r = new SubscriptionDetailsRequest();
        $r->setSubscriptionReferenceCode($subscriptionRef);

        return SubscriptionDetails::retrieve($r, OptionsFactory::create($this->cfg));
    }

    /**
     * Abonelik arama (RetrieveList::subscriptions)
     *
     * @param array{
     *   page?:int, count?:int,
     *   subscriptionStatus?:'ACTIVE'|'PENDING'|'CANCELED'|'UNPAID'|string,
     *   startDate?:string, endDate?:string, // 'YYYY-MM-DD'
     *   pricingPlanReferenceCode?:string
     * } $filters
     */
    public function search(array $filters = [])
    {
        $r = new SubscriptionSearchRequest();

        if (isset($filters['page']) && method_exists($r, 'setPage')) {
            $r->setPage((int) $filters['page']);
        }
        if (isset($filters['count']) && method_exists($r, 'setCount')) {
            $r->setCount((int) $filters['count']);
        }
        if (!empty($filters['subscriptionStatus'])) {
            $r->setSubscriptionStatus((string) $filters['subscriptionStatus']);
        }
        if (!empty($filters['startDate'])) {
            $r->setStartDate((string) $filters['startDate']);
        }
        if (!empty($filters['endDate'])) {
            $r->setEndDate((string) $filters['endDate']);
        }
        if (!empty($filters['pricingPlanReferenceCode'])) {
            $r->setPricingPlanReferenceCode((string) $filters['pricingPlanReferenceCode']);
        }

        return RetrieveList::subscriptions($r, OptionsFactory::create($this->cfg));
    }

    /**
     * Abonelik kartı güncelleme (Checkout formu ile)
     *
     * @param array{
     *   customerReferenceCode:string,
     *   callbackUrl:string
     * } $data
     */
    public function cardUpdateCheckout(array $data)
    {
        $r = new SubscriptionCardUpdateRequest();
        $r->setLocale($this->cfg->locale);
        $r->setConversationId($this->cfg->conversationId);
        $r->setCustomerReferenceCode((string) $data['customerReferenceCode']);
        $r->setCallBackUrl((string) $data['callbackUrl']);

        return \Iyzipay\Model\Subscription\SubscriptionCardUpdate::update($r, OptionsFactory::create($this->cfg));
    }

    /**
     * SDK Customer nesnesi kurucu yardımcı
     * @param array<string,string> $data
     */
    private function buildCustomer(array $data): Customer
    {
        $c = new Customer();

        // Temel
        if (!empty($data['name']))
            $c->setName((string) $data['name']);
        if (!empty($data['surname']))
            $c->setSurname((string) $data['surname']);
        if (!empty($data['gsmNumber']))
            $c->setGsmNumber((string) $data['gsmNumber']);
        if (!empty($data['email']))
            $c->setEmail((string) $data['email']);
        if (!empty($data['identityNumber']))
            $c->setIdentityNumber((string) $data['identityNumber']);

        // Shipping
        if (!empty($data['shippingContactName']))
            $c->setShippingContactName((string) $data['shippingContactName']);
        if (!empty($data['shippingCity']))
            $c->setShippingCity((string) $data['shippingCity']);
        if (!empty($data['shippingDistrict']))
            $c->setShippingDistrict((string) $data['shippingDistrict']);   // <—
        if (!empty($data['shippingCountry']))
            $c->setShippingCountry((string) $data['shippingCountry']);
        if (!empty($data['shippingAddress']))
            $c->setShippingAddress((string) $data['shippingAddress']);
        if (!empty($data['shippingZipCode']))
            $c->setShippingZipCode((string) $data['shippingZipCode']);

        // Billing
        if (!empty($data['billingContactName']))
            $c->setBillingContactName((string) $data['billingContactName']);
        if (!empty($data['billingCity']))
            $c->setBillingCity((string) $data['billingCity']);
        if (!empty($data['billingDistrict']))
            $c->setBillingDistrict((string) $data['billingDistrict']);     // <—
        if (!empty($data['billingCountry']))
            $c->setBillingCountry((string) $data['billingCountry']);
        if (!empty($data['billingAddress']))
            $c->setBillingAddress((string) $data['billingAddress']);
        if (!empty($data['billingZipCode']))
            $c->setBillingZipCode((string) $data['billingZipCode']);

        return $c;
    }
}
