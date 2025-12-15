<?php
declare(strict_types=1);

namespace Eren5\PhpIyzico\Services\Subscription;

use Eren5\PhpIyzico\Config;
use Eren5\PhpIyzico\OptionsFactory;

use Iyzipay\Model\Customer as IyzicoCustomer;
use Iyzipay\Model\Subscription\RetrieveList;
use Iyzipay\Model\Subscription\SubscriptionCustomer;

use Iyzipay\Request\Subscription\SubscriptionCreateCustomerRequest;
use Iyzipay\Request\Subscription\SubscriptionUpdateCustomerRequest;
use Iyzipay\Request\Subscription\SubscriptionRetrieveCustomerRequest;
use Iyzipay\Request\Subscription\SubscriptionListCustomersRequest;
use Iyzipay\Request\Subscription\SubscriptionDeleteCustomerRequest;

/**
 * Abonelik Müşterisi CRUD işlemleri
 */
final class CustomerService
{
    public function __construct(private Config $config)
    {
    }

    /**
     * Müşteri oluşturma
     *
     * @param array<string, string> $customerData
     */
    public function create(array $customerData)
    {
        $createCustomerRequest = new SubscriptionCreateCustomerRequest();
        $createCustomerRequest->setLocale($this->config->locale);
        $createCustomerRequest->setConversationId($this->config->conversationId);

        $customerModel = $this->buildCustomerModel($customerData);
        $createCustomerRequest->setCustomer($customerModel);

        return SubscriptionCustomer::create(
            $createCustomerRequest,
            OptionsFactory::create($this->config)
        );
    }

    /**
     * Müşteri getirme
     */
    public function retrieve(string $customerReferenceCode)
    {
        $retrieveCustomerRequest = new SubscriptionRetrieveCustomerRequest();
        $retrieveCustomerRequest->setLocale($this->config->locale);
        $retrieveCustomerRequest->setConversationId($this->config->conversationId);
        $retrieveCustomerRequest->setCustomerReferenceCode($customerReferenceCode);

        return SubscriptionCustomer::retrieve(
            $retrieveCustomerRequest,
            OptionsFactory::create($this->config)
        );
    }

    /**
     * Müşteri listeleme (sayfalama)
     */
    public function list(int $page = 1, int $count = 20)
    {
        $listCustomerRequest = new SubscriptionListCustomersRequest();
        $listCustomerRequest->setLocale($this->config->locale);
        $listCustomerRequest->setConversationId($this->config->conversationId);

        if (method_exists($listCustomerRequest, 'setPage')) {
            $listCustomerRequest->setPage($page);
        }
        if (method_exists($listCustomerRequest, 'setCount')) {
            $listCustomerRequest->setCount($count);
        }

        return RetrieveList::customers(
            $listCustomerRequest,
            OptionsFactory::create($this->config)
        );
    }

    /**
     * Müşteri güncelleme
     *
     * @param array<string, string> $customerData
     */
    public function update(string $customerReferenceCode, array $customerData)
    {
        $updateCustomerRequest = new SubscriptionUpdateCustomerRequest();
        $updateCustomerRequest->setLocale($this->config->locale);
        $updateCustomerRequest->setConversationId($this->config->conversationId);
        $updateCustomerRequest->setCustomerReferenceCode($customerReferenceCode);

        $customerModel = $this->buildCustomerModel($customerData);
        $updateCustomerRequest->setCustomer($customerModel);

        return SubscriptionCustomer::update(
            $updateCustomerRequest,
            OptionsFactory::create($this->config)
        );
    }

    /**
     * Müşteri silme
     */
    public function delete(string $customerReferenceCode)
    {
        $deleteCustomerRequest = new SubscriptionDeleteCustomerRequest();
        $deleteCustomerRequest->setLocale($this->config->locale);
        $deleteCustomerRequest->setConversationId($this->config->conversationId);
        $deleteCustomerRequest->setCustomerReferenceCode($customerReferenceCode);

        return \call_user_func(
            [SubscriptionCustomer::class, 'delete'],
            $deleteCustomerRequest,
            OptionsFactory::create($this->config)
        );
    }

    /**
     * Iyzipay Customer modelini dolduran yardımcı
     *
     * @param array<string,string> $data
     */
    private function buildCustomerModel(array $data): IyzicoCustomer
    {
        $customer = new IyzicoCustomer();

        // Temel alanlar
        if (!empty($data['name'])) {
            $customer->setName((string) $data['name']);
        }
        if (!empty($data['surname'])) {
            $customer->setSurname((string) $data['surname']);
        }
        if (!empty($data['email'])) {
            $customer->setEmail((string) $data['email']);
        }
        if (!empty($data['gsmNumber'])) {
            $customer->setGsmNumber((string) $data['gsmNumber']);
        }
        if (!empty($data['identityNumber'])) {
            $customer->setIdentityNumber((string) $data['identityNumber']);
        }

        // Shipping bilgileri
        if (!empty($data['shippingContactName'])) {
            $customer->setShippingContactName((string) $data['shippingContactName']);
        }
        if (!empty($data['shippingCity'])) {
            $customer->setShippingCity((string) $data['shippingCity']);
        }
        if (!empty($data['shippingDistrict'])) {
            $customer->setShippingDistrict((string) $data['shippingDistrict']);
        }
        if (!empty($data['shippingCountry'])) {
            $customer->setShippingCountry((string) $data['shippingCountry']);
        }
        if (!empty($data['shippingAddress'])) {
            $customer->setShippingAddress((string) $data['shippingAddress']);
        }
        if (!empty($data['shippingZipCode'])) {
            $customer->setShippingZipCode((string) $data['shippingZipCode']);
        }

        // Billing bilgileri
        if (!empty($data['billingContactName'])) {
            $customer->setBillingContactName((string) $data['billingContactName']);
        }
        if (!empty($data['billingCity'])) {
            $customer->setBillingCity((string) $data['billingCity']);
        }
        if (!empty($data['billingDistrict'])) {
            $customer->setBillingDistrict((string) $data['billingDistrict']);
        }
        if (!empty($data['billingCountry'])) {
            $customer->setBillingCountry((string) $data['billingCountry']);
        }
        if (!empty($data['billingAddress'])) {
            $customer->setBillingAddress((string) $data['billingAddress']);
        }
        if (!empty($data['billingZipCode'])) {
            $customer->setBillingZipCode((string) $data['billingZipCode']);
        }

        return $customer;
    }
}
