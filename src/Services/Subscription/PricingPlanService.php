<?php
declare(strict_types=1);

namespace Eren5\PhpIyzico\Services\Subscription;

use Eren5\PhpIyzico\Config;
use Eren5\PhpIyzico\OptionsFactory;

use Iyzipay\Model\Subscription\SubscriptionPricingPlan;
use Iyzipay\Model\Subscription\RetrieveList; // <- Listeleme için gerekli
use Iyzipay\Request\Subscription\SubscriptionCreatePricingPlanRequest;
use Iyzipay\Request\Subscription\SubscriptionUpdatePricingPlanRequest;
use Iyzipay\Request\Subscription\SubscriptionDeletePricingPlanRequest;
use Iyzipay\Request\Subscription\SubscriptionRetrievePricingPlanRequest;
use Iyzipay\Request\Subscription\SubscriptionListPricingPlanRequest;

/**
 * Plan CRUD (Pricing Plan)
 */
final class PricingPlanService
{
    public function __construct(private Config $cfg)
    {
    }

    /**
     * Plan oluştur.
     * Zorunlular: name, productReferenceCode, price, currencyCode, paymentInterval, paymentIntervalCount
     * Opsiyoneller: trialPeriodDays, planPaymentType (RECURRING|PREPAID), recurrenceCount
     * @param array<string,mixed> $data
     */
    public function create(array $data)
    {
        $r = new SubscriptionCreatePricingPlanRequest();
        $r->setLocale($this->cfg->locale);
        $r->setConversationId($this->cfg->conversationId);

        // Zorunlu alanlar
        $r->setName((string) $data['name']);
        $r->setProductReferenceCode((string) $data['productReferenceCode']);
        $r->setPrice((string) $data['price']);
        $r->setCurrencyCode((string) $data['currencyCode']);        // TRY|USD|EUR...
        $r->setPaymentInterval((string) $data['paymentInterval']);  // DAILY|WEEKLY|MONTHLY|YEARLY (SDK’a göre)
        $r->setPaymentIntervalCount((int) $data['paymentIntervalCount']);

        // Opsiyoneller
        if (isset($data['trialPeriodDays'])) {
            $r->setTrialPeriodDays((int) $data['trialPeriodDays']);
        }
        if (isset($data['planPaymentType'])) {
            $r->setPlanPaymentType((string) $data['planPaymentType']); // RECURRING|PREPAID
        }
        if (array_key_exists('recurrenceCount', $data)) {
            $r->setRecurrenceCount($data['recurrenceCount'] !== null ? (int) $data['recurrenceCount'] : null);
        }

        return SubscriptionPricingPlan::create($r, OptionsFactory::create($this->cfg));
    }

    /** Plan getir (referenceCode ile) */
    public function retrieve(string $planRef)
    {
        $r = new SubscriptionRetrievePricingPlanRequest();
        // SDK örneğinde locale/conversationId olmasa da eklemek zararsız
        $r->setLocale($this->cfg->locale);
        $r->setConversationId($this->cfg->conversationId);
        $r->setPricingPlanReferenceCode($planRef);

        return SubscriptionPricingPlan::retrieve($r, OptionsFactory::create($this->cfg));
    }

    /** Bir ürün altındaki planları listele */
    public function list(string $productRef, int $page = 1, int $count = 20)
    {
        $r = new SubscriptionListPricingPlanRequest();
        $r->setLocale($this->cfg->locale);
        $r->setConversationId($this->cfg->conversationId);
        $r->setProductReferenceCode($productRef);

        if (method_exists($r, 'setPage')) {
            $r->setPage($page);
        }
        if (method_exists($r, 'setCount')) {
            $r->setCount($count);
        }

        // DÜZELTME: SubscriptionPricingPlan::list(...) yerine RetrieveList::pricingPlan(...)
        return RetrieveList::pricingPlan($r, OptionsFactory::create($this->cfg));
    }

    /**
     * Plan güncelle
     * SDK örneğine uygun olarak yalnızca name ve trialPeriodDays güncelleniyor.
     * @param array{name?:string, trialPeriodDays?:int} $data
     */
    public function update(string $planRef, array $data)
    {
        $r = new SubscriptionUpdatePricingPlanRequest();
        $r->setLocale($this->cfg->locale);
        $r->setConversationId($this->cfg->conversationId);
        $r->setPricingPlanReferenceCode($planRef);

        if (isset($data['name'])) {
            $r->setName((string) $data['name']);
        }
        if (isset($data['trialPeriodDays'])) {
            $r->setTrialPeriodDays((int) $data['trialPeriodDays']);
        }

        return SubscriptionPricingPlan::update($r, OptionsFactory::create($this->cfg));
    }

    /** Plan sil */
    public function delete(string $planRef)
    {
        $r = new SubscriptionDeletePricingPlanRequest();
        $r->setLocale($this->cfg->locale);
        $r->setConversationId($this->cfg->conversationId);
        $r->setPricingPlanReferenceCode($planRef);

        return SubscriptionPricingPlan::delete($r, OptionsFactory::create($this->cfg));
    }
}
