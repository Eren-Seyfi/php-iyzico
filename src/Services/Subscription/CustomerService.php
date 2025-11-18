<?php
declare(strict_types=1);

namespace Eren5\PhpIyzico\Services\Subscription;

use Eren5\PhpIyzico\Config;
use Eren5\PhpIyzico\OptionsFactory;

use Iyzipay\Model\Customer as IyzCustomer;
use Iyzipay\Model\Subscription\RetrieveList;
use Iyzipay\Model\Subscription\SubscriptionCustomer;

use Iyzipay\Request\Subscription\SubscriptionCreateCustomerRequest;
use Iyzipay\Request\Subscription\SubscriptionUpdateCustomerRequest;
use Iyzipay\Request\Subscription\SubscriptionRetrieveCustomerRequest;
use Iyzipay\Request\Subscription\SubscriptionListCustomersRequest;
use Iyzipay\Request\Subscription\SubscriptionDeleteCustomerRequest;

/**
 * Abonelik Müşterisi CRUD
 */
final class CustomerService
{
    public function __construct(private Config $cfg)
    {
    }

    /**
     * Müşteri oluştur (SDK checkout/customer örneklerindeki alanlarla uyumlu).
     *
     * Zorunlu: name, surname, email
     * Opsiyonel: gsmNumber, identityNumber,
     *   shippingContactName, shippingCity, shippingCountry, shippingAddress, shippingZipCode,
     *   billingContactName,  billingCity,  billingCountry,  billingAddress,  billingZipCode
     *
     * @param array{
     *   name:string,
     *   surname:string,
     *   email:string,
     *   gsmNumber?:string,
     *   identityNumber?:string,
     *   shippingContactName?:string, shippingCity?:string, shippingCountry?:string, shippingAddress?:string, shippingZipCode?:string,
     *   billingContactName?:string,  billingCity?:string,  billingCountry?:string,  billingAddress?:string,  billingZipCode?:string
     * } $data
     */
    public function create(array $data)
    {
        $req = new SubscriptionCreateCustomerRequest();
        $req->setLocale($this->cfg->locale);
        $req->setConversationId($this->cfg->conversationId);

        // Customer modelini kur ve isteğe ekle (IDE undefined method uyarılarını önler)
        $c = $this->buildCustomer($data);
        $req->setCustomer($c);

        return SubscriptionCustomer::create($req, OptionsFactory::create($this->cfg));
    }

    /** Müşteri getir (referenceCode ile) */
    public function retrieve(string $customerRef)
    {
        $req = new SubscriptionRetrieveCustomerRequest();
        $req->setLocale($this->cfg->locale);
        $req->setConversationId($this->cfg->conversationId);
        $req->setCustomerReferenceCode($customerRef);

        return SubscriptionCustomer::retrieve($req, OptionsFactory::create($this->cfg));
    }

    /** Müşteri listele (sayfalı) */
    public function list(int $page = 1, int $count = 20)
    {
        $req = new SubscriptionListCustomersRequest();
        $req->setLocale($this->cfg->locale);
        $req->setConversationId($this->cfg->conversationId);

        if (method_exists($req, 'setPage')) {
            $req->setPage($page);
        }
        if (method_exists($req, 'setCount')) {
            $req->setCount($count);
        }

        // IDE uyumlu ve SDK örnekleriyle tutarlı: RetrieveList::customers(...)
        return RetrieveList::customers($req, OptionsFactory::create($this->cfg));
    }

    /**
     * Müşteri güncelle.
     * Desteklenen alanlar: name, surname, email, gsmNumber, identityNumber + shipping/billing alanları
     * @param array<string,string> $data
     */
    public function update(string $customerRef, array $data)
    {
        $req = new SubscriptionUpdateCustomerRequest();
        $req->setLocale($this->cfg->locale);
        $req->setConversationId($this->cfg->conversationId);
        $req->setCustomerReferenceCode($customerRef);

        // Customer modelini (yalnızca gönderilen alanlarla) kurup isteğe ekle
        $c = $this->buildCustomer($data);
        $req->setCustomer($c);

        return SubscriptionCustomer::update($req, OptionsFactory::create($this->cfg));
    }

    /** Müşteri sil */
    public function delete(string $customerRef)
    {
        $req = new SubscriptionDeleteCustomerRequest();
        $req->setLocale($this->cfg->locale);
        $req->setConversationId($this->cfg->conversationId);
        $req->setCustomerReferenceCode($customerRef);

        // Bazı IDE’lerde statik delete metodu için “undefined” uyarısı çıkabiliyor.
        // call_user_func ile lint'i susturuyoruz; çalışma zamanı davranışı aynı.
        return \call_user_func(
            [SubscriptionCustomer::class, 'delete'],
            $req,
            OptionsFactory::create($this->cfg)
        );
    }

    /**
     * Iyzipay Customer modelini veriden inşa eder (yalnızca gelen alanları set eder)
     * @param array<string,string> $data
     */
    private function buildCustomer(array $data): IyzCustomer
    {
        $c = new IyzCustomer();

        // Temel
        if (!empty($data['name'])) {
            $c->setName((string) $data['name']);
        }
        if (!empty($data['surname'])) {
            $c->setSurname((string) $data['surname']);
        }
        if (!empty($data['email'])) {
            $c->setEmail((string) $data['email']);
        }
        if (!empty($data['gsmNumber'])) {
            $c->setGsmNumber((string) $data['gsmNumber']);
        }
        if (!empty($data['identityNumber'])) {
            $c->setIdentityNumber((string) $data['identityNumber']);
        }

        // Shipping
        if (!empty($data['shippingContactName'])) {
            $c->setShippingContactName((string) $data['shippingContactName']);
        }
        if (!empty($data['shippingCity'])) {
            $c->setShippingCity((string) $data['shippingCity']);
        }
        if (!empty($data['shippingCountry'])) {
            $c->setShippingCountry((string) $data['shippingCountry']);
        }
        if (!empty($data['shippingAddress'])) {
            $c->setShippingAddress((string) $data['shippingAddress']);
        }
        if (!empty($data['shippingZipCode'])) {
            $c->setShippingZipCode((string) $data['shippingZipCode']);
        }

        if (!empty($data['shippingDistrict']))
            $c->setShippingDistrict((string) $data['shippingDistrict']);

        // Billing
        if (!empty($data['billingContactName'])) {
            $c->setBillingContactName((string) $data['billingContactName']);
        }
        if (!empty($data['billingCity'])) {
            $c->setBillingCity((string) $data['billingCity']);
        }
        if (!empty($data['billingCountry'])) {
            $c->setBillingCountry((string) $data['billingCountry']);
        }
        if (!empty($data['billingAddress'])) {
            $c->setBillingAddress((string) $data['billingAddress']);
        }
        if (!empty($data['billingZipCode'])) {
            $c->setBillingZipCode((string) $data['billingZipCode']);
        }
        if (!empty($data['billingDistrict']))
            $c->setBillingDistrict((string) $data['billingDistrict']);

        return $c;
    }
}
