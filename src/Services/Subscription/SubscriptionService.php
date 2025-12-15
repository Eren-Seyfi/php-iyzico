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
 * Abonelik işlemleri servisi
 */
final class SubscriptionService
{
    public function __construct(private Config $config)
    {
    }

    /**
     * Checkout Form ile abonelik başlatma
     */
    public function createCheckoutForm(array $subscriptionData, array $customerData)
    {
        $createCheckoutFormRequest = new SubscriptionCreateCheckoutFormRequest();
        $createCheckoutFormRequest->setLocale($this->config->locale);
        $createCheckoutFormRequest->setConversationId($this->config->conversationId);
        $createCheckoutFormRequest->setPricingPlanReferenceCode((string) $subscriptionData['pricingPlanReferenceCode']);

        if (!empty($subscriptionData['subscriptionInitialStatus'])) {
            $createCheckoutFormRequest->setSubscriptionInitialStatus((string) $subscriptionData['subscriptionInitialStatus']);
        }

        if (!empty($subscriptionData['callbackUrl'])) {
            $createCheckoutFormRequest->setCallbackUrl((string) $subscriptionData['callbackUrl']);
        }

        $customerModel = $this->buildCustomer($customerData);
        $createCheckoutFormRequest->setCustomer($customerModel);

        return SubscriptionCreateCheckoutForm::create(
            $createCheckoutFormRequest,
            OptionsFactory::create($this->config)
        );
    }

    /**
     * Checkout formu sonucunu token ile döndürür
     */
    public function retrieveCheckoutFormResult(string $checkoutFormToken)
    {
        $retrieveFormRequest = new RetrieveSubscriptionCreateCheckoutFormRequest();
        $retrieveFormRequest->setCheckoutFormToken($checkoutFormToken);

        return RetrieveSubscriptionCheckoutForm::retrieve(
            $retrieveFormRequest,
            OptionsFactory::create($this->config)
        );
    }

    /**
     * NON-3D Kredi Kartı ile doğrudan abonelik oluşturma
     */
    public function createNon3D(array $subscriptionData, array $paymentCardData, array $customerData)
    {
        $createSubscriptionRequest = new SubscriptionCreateRequest();
        $createSubscriptionRequest->setLocale($this->config->locale);
        $createSubscriptionRequest->setConversationId($this->config->conversationId);
        $createSubscriptionRequest->setPricingPlanReferenceCode((string) $subscriptionData['pricingPlanReferenceCode']);

        if (!empty($subscriptionData['subscriptionInitialStatus'])) {
            $createSubscriptionRequest->setSubscriptionInitialStatus((string) $subscriptionData['subscriptionInitialStatus']);
        }

        $paymentCardModel = new PaymentCard();
        $paymentCardModel->setCardHolderName((string) $paymentCardData['cardHolderName']);
        $paymentCardModel->setCardNumber((string) $paymentCardData['cardNumber']);
        $paymentCardModel->setExpireMonth((string) $paymentCardData['expireMonth']);
        $paymentCardModel->setExpireYear((string) $paymentCardData['expireYear']);
        $paymentCardModel->setCvc((string) $paymentCardData['cvc']);
        $createSubscriptionRequest->setPaymentCard($paymentCardModel);

        $customerModel = $this->buildCustomer($customerData);
        $createSubscriptionRequest->setCustomer($customerModel);

        return SubscriptionCreate::create(
            $createSubscriptionRequest,
            OptionsFactory::create($this->config)
        );
    }

    /**
     * Mevcut müşteri ile abonelik başlatma
     */
    public function createWithCustomer(array $subscriptionData)
    {
        $createWithCustomerRequest = new SubscriptionCreateWithCustomerRequest();
        $createWithCustomerRequest->setLocale($this->config->locale);
        $createWithCustomerRequest->setConversationId($this->config->conversationId);
        $createWithCustomerRequest->setPricingPlanReferenceCode((string) $subscriptionData['pricingPlanReferenceCode']);
        $createWithCustomerRequest->setCustomerReferenceCode((string) $subscriptionData['customerReferenceCode']);

        if (!empty($subscriptionData['subscriptionInitialStatus'])) {
            $createWithCustomerRequest->setSubscriptionInitialStatus((string) $subscriptionData['subscriptionInitialStatus']);
        }

        return SubscriptionCreateWithCustomer::create(
            $createWithCustomerRequest,
            OptionsFactory::create($this->config)
        );
    }

    /**
     * Aboneliği aktifleştir
     */
    public function activate(string $subscriptionReferenceCode)
    {
        $activateRequest = new SubscriptionActivateRequest();
        $activateRequest->setLocale($this->config->locale);
        $activateRequest->setConversationId($this->config->conversationId);
        $activateRequest->setSubscriptionReferenceCode($subscriptionReferenceCode);

        return SubscriptionActivate::update(
            $activateRequest,
            OptionsFactory::create($this->config)
        );
    }

    /**
     * Başarısız ödemeyi tekrar dene
     */
    public function retry(string $retryReferenceCode)
    {
        $retryRequest = new SubscriptionRetryRequest();
        $retryRequest->setLocale($this->config->locale);
        $retryRequest->setConversationId($this->config->conversationId);
        $retryRequest->setReferenceCode($retryReferenceCode);

        return SubscriptionRetry::update(
            $retryRequest,
            OptionsFactory::create($this->config)
        );
    }

    /**
     * Aboneliği başka bir plana yükselt
     */
    public function upgrade(array $upgradeData)
    {
        $upgradeRequest = new SubscriptionUpgradeRequest();
        $upgradeRequest->setLocale($this->config->locale);
        $upgradeRequest->setConversationId($this->config->conversationId);
        $upgradeRequest->setSubscriptionReferenceCode((string) $upgradeData['subscriptionReferenceCode']);
        $upgradeRequest->setNewPricingPlanReferenceCode((string) $upgradeData['newPricingPlanReferenceCode']);
        $upgradeRequest->setUpgradePeriod((string) $upgradeData['upgradePeriod']);

        if (array_key_exists('useTrial', $upgradeData)) {
            $upgradeRequest->setUseTrial((bool) $upgradeData['useTrial']);
        }
        if (array_key_exists('resetRecurrenceCount', $upgradeData)) {
            $upgradeRequest->setResetRecurrenceCount((bool) $upgradeData['resetRecurrenceCount']);
        }

        return SubscriptionUpgrade::update(
            $upgradeRequest,
            OptionsFactory::create($this->config)
        );
    }

    /**
     * Aboneliği iptal et
     */
    public function cancel(string $subscriptionReferenceCode)
    {
        $cancelRequest = new SubscriptionCancelRequest();
        $cancelRequest->setLocale($this->config->locale);
        $cancelRequest->setConversationId($this->config->conversationId);
        $cancelRequest->setSubscriptionReferenceCode($subscriptionReferenceCode);

        return SubscriptionCancel::cancel(
            $cancelRequest,
            OptionsFactory::create($this->config)
        );
    }

    /**
     * Abonelik detaylarını getir
     */
    public function details(string $subscriptionReferenceCode)
    {
        $detailsRequest = new SubscriptionDetailsRequest();
        $detailsRequest->setSubscriptionReferenceCode($subscriptionReferenceCode);

        return SubscriptionDetails::retrieve(
            $detailsRequest,
            OptionsFactory::create($this->config)
        );
    }

    /**
     * Abonelik arama
     */
    public function search(array $filters = [])
    {
        $searchRequest = new SubscriptionSearchRequest();

        if (isset($filters['page']) && method_exists($searchRequest, 'setPage')) {
            $searchRequest->setPage((int) $filters['page']);
        }
        if (isset($filters['count']) && method_exists($searchRequest, 'setCount')) {
            $searchRequest->setCount((int) $filters['count']);
        }

        if (!empty($filters['subscriptionReferenceCode'])) {
            $searchRequest->setSubscriptionReferenceCode((string) $filters['subscriptionReferenceCode']);
        }
        if (!empty($filters['customerReferenceCode'])) {
            $searchRequest->setCustomerReferenceCode((string) $filters['customerReferenceCode']);
        }
        if (!empty($filters['pricingPlanReferenceCode'])) {
            $searchRequest->setPricingPlanReferenceCode((string) $filters['pricingPlanReferenceCode']);
        }
        if (!empty($filters['parent'])) {
            $searchRequest->setParentReferenceCode((string) $filters['parent']);
        }
        if (!empty($filters['subscriptionStatus'])) {
            $searchRequest->setSubscriptionStatus((string) $filters['subscriptionStatus']);
        }
        if (!empty($filters['startDate'])) {
            $searchRequest->setStartDate((string) $filters['startDate']);
        }
        if (!empty($filters['endDate'])) {
            $searchRequest->setEndDate((string) $filters['endDate']);
        }

        return RetrieveList::subscriptions(
            $searchRequest,
            OptionsFactory::create($this->config)
        );
    }

    /**
     * Kart güncelleme checkout formu
     */
    public function cardUpdateCheckout(array $updateData)
    {
        $cardUpdateRequest = new SubscriptionCardUpdateRequest();
        $cardUpdateRequest->setLocale($this->config->locale);
        $cardUpdateRequest->setConversationId($this->config->conversationId);
        $cardUpdateRequest->setCustomerReferenceCode((string) $updateData['customerReferenceCode']);
        $cardUpdateRequest->setCallBackUrl((string) $updateData['callbackUrl']);

        return \Iyzipay\Model\Subscription\SubscriptionCardUpdate::update(
            $cardUpdateRequest,
            OptionsFactory::create($this->config)
        );
    }

    /**
     * Customer model builder
     */
    private function buildCustomer(array $data): Customer
    {
        $customerModel = new Customer();

        if (!empty($data['name'])) {
            $customerModel->setName((string) $data['name']);
        }
        if (!empty($data['surname'])) {
            $customerModel->setSurname((string) $data['surname']);
        }
        if (!empty($data['gsmNumber'])) {
            $customerModel->setGsmNumber((string) $data['gsmNumber']);
        }
        if (!empty($data['email'])) {
            $customerModel->setEmail((string) $data['email']);
        }
        if (!empty($data['identityNumber'])) {
            $customerModel->setIdentityNumber((string) $data['identityNumber']);
        }

        if (!empty($data['shippingContactName'])) {
            $customerModel->setShippingContactName((string) $data['shippingContactName']);
        }
        if (!empty($data['shippingCity'])) {
            $customerModel->setShippingCity((string) $data['shippingCity']);
        }
        if (!empty($data['shippingDistrict'])) {
            $customerModel->setShippingDistrict((string) $data['shippingDistrict']);
        }
        if (!empty($data['shippingCountry'])) {
            $customerModel->setShippingCountry((string) $data['shippingCountry']);
        }
        if (!empty($data['shippingAddress'])) {
            $customerModel->setShippingAddress((string) $data['shippingAddress']);
        }
        if (!empty($data['shippingZipCode'])) {
            $customerModel->setShippingZipCode((string) $data['shippingZipCode']);
        }

        if (!empty($data['billingContactName'])) {
            $customerModel->setBillingContactName((string) $data['billingContactName']);
        }
        if (!empty($data['billingCity'])) {
            $customerModel->setBillingCity((string) $data['billingCity']);
        }
        if (!empty($data['billingDistrict'])) {
            $customerModel->setBillingDistrict((string) $data['billingDistrict']);
        }
        if (!empty($data['billingCountry'])) {
            $customerModel->setBillingCountry((string) $data['billingCountry']);
        }
        if (!empty($data['billingAddress'])) {
            $customerModel->setBillingAddress((string) $data['billingAddress']);
        }
        if (!empty($data['billingZipCode'])) {
            $customerModel->setBillingZipCode((string) $data['billingZipCode']);
        }

        return $customerModel;
    }
}
