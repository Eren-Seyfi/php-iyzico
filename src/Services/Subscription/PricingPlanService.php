<?php
declare(strict_types=1);

namespace Eren\PhpIyzico\Services\Subscription;

use Eren\PhpIyzico\Config;
use Eren\PhpIyzico\OptionsFactory;

use Iyzipay\Model\Subscription\SubscriptionPricingPlan;
use Iyzipay\Model\Subscription\RetrieveList;
use Iyzipay\Request\Subscription\SubscriptionCreatePricingPlanRequest;
use Iyzipay\Request\Subscription\SubscriptionUpdatePricingPlanRequest;
use Iyzipay\Request\Subscription\SubscriptionDeletePricingPlanRequest;
use Iyzipay\Request\Subscription\SubscriptionRetrievePricingPlanRequest;
use Iyzipay\Request\Subscription\SubscriptionListPricingPlanRequest;

/**
 * Pricing Plan CRUD işlemleri
 */
final class PricingPlanService
{
    public function __construct(private Config $config)
    {
    }

    /**
     * Plan oluşturma
     *
     * @param array<string,mixed> $planData
     */
    public function create(array $planData)
    {
        $createPricingPlanRequest = new SubscriptionCreatePricingPlanRequest();
        $createPricingPlanRequest->setLocale($this->config->locale);
        $createPricingPlanRequest->setConversationId($this->config->conversationId);

        // Zorunlu alanlar
        $createPricingPlanRequest->setName((string) $planData['name']);
        $createPricingPlanRequest->setProductReferenceCode((string) $planData['productReferenceCode']);
        $createPricingPlanRequest->setPrice((string) $planData['price']);
        $createPricingPlanRequest->setCurrencyCode((string) $planData['currencyCode']);
        $createPricingPlanRequest->setPaymentInterval((string) $planData['paymentInterval']);
        $createPricingPlanRequest->setPaymentIntervalCount((int) $planData['paymentIntervalCount']);

        // Opsiyoneller
        if (isset($planData['trialPeriodDays'])) {
            $createPricingPlanRequest->setTrialPeriodDays((int) $planData['trialPeriodDays']);
        }

        if (isset($planData['planPaymentType'])) {
            $createPricingPlanRequest->setPlanPaymentType((string) $planData['planPaymentType']); // RECURRING or PREPAID
        }

        if (array_key_exists('recurrenceCount', $planData)) {
            $recurrenceCount = $planData['recurrenceCount'];
            $createPricingPlanRequest->setRecurrenceCount(
                $recurrenceCount !== null ? (int) $recurrenceCount : null
            );
        }

        return SubscriptionPricingPlan::create(
            $createPricingPlanRequest,
            OptionsFactory::create($this->config)
        );
    }

    /**
     * Tek plan getirme
     */
    public function retrieve(string $planReferenceCode)
    {
        $retrievePricingPlanRequest = new SubscriptionRetrievePricingPlanRequest();
        $retrievePricingPlanRequest->setLocale($this->config->locale);
        $retrievePricingPlanRequest->setConversationId($this->config->conversationId);
        $retrievePricingPlanRequest->setPricingPlanReferenceCode($planReferenceCode);

        return SubscriptionPricingPlan::retrieve(
            $retrievePricingPlanRequest,
            OptionsFactory::create($this->config)
        );
    }

    /**
     * Bir ürün altındaki tüm planları listeleme
     */
    public function list(string $productReferenceCode, int $page = 1, int $count = 20)
    {
        $listPricingPlanRequest = new SubscriptionListPricingPlanRequest();
        $listPricingPlanRequest->setLocale($this->config->locale);
        $listPricingPlanRequest->setConversationId($this->config->conversationId);
        $listPricingPlanRequest->setProductReferenceCode($productReferenceCode);

        if (method_exists($listPricingPlanRequest, 'setPage')) {
            $listPricingPlanRequest->setPage($page);
        }
        if (method_exists($listPricingPlanRequest, 'setCount')) {
            $listPricingPlanRequest->setCount($count);
        }

        return RetrieveList::pricingPlan(
            $listPricingPlanRequest,
            OptionsFactory::create($this->config)
        );
    }

    /**
     * Plan güncelleme
     *
     * @param array{name?:string, trialPeriodDays?:int} $updateData
     */
    public function update(string $planReferenceCode, array $updateData)
    {
        $updatePricingPlanRequest = new SubscriptionUpdatePricingPlanRequest();
        $updatePricingPlanRequest->setLocale($this->config->locale);
        $updatePricingPlanRequest->setConversationId($this->config->conversationId);
        $updatePricingPlanRequest->setPricingPlanReferenceCode($planReferenceCode);

        if (isset($updateData['name'])) {
            $updatePricingPlanRequest->setName((string) $updateData['name']);
        }

        if (isset($updateData['trialPeriodDays'])) {
            $updatePricingPlanRequest->setTrialPeriodDays((int) $updateData['trialPeriodDays']);
        }

        return SubscriptionPricingPlan::update(
            $updatePricingPlanRequest,
            OptionsFactory::create($this->config)
        );
    }

    /**
     * Plan silme
     */
    public function delete(string $planReferenceCode)
    {
        $deletePricingPlanRequest = new SubscriptionDeletePricingPlanRequest();
        $deletePricingPlanRequest->setLocale($this->config->locale);
        $deletePricingPlanRequest->setConversationId($this->config->conversationId);
        $deletePricingPlanRequest->setPricingPlanReferenceCode($planReferenceCode);

        return SubscriptionPricingPlan::delete(
            $deletePricingPlanRequest,
            OptionsFactory::create($this->config)
        );
    }
}
