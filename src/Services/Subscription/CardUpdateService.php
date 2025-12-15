<?php
declare(strict_types=1);

namespace Eren5\PhpIyzico\Services\Subscription;

use Eren5\PhpIyzico\Config;
use Eren5\PhpIyzico\OptionsFactory;

use Iyzipay\Model\Subscription\SubscriptionCardUpdate;
use Iyzipay\Request\Subscription\SubscriptionCardUpdateRequest;

/**
 * Müşteri kartı güncelleme (card update checkout formu başlatma)
 */
final class CardUpdateService
{
    public function __construct(private Config $config)
    {
    }

    /**
     * Müşteri referans kodu ile kart güncelleme formu başlatır
     */
    public function byCustomer(string $customerReferenceCode, string $callbackUrl)
    {
        $cardUpdateRequest = new SubscriptionCardUpdateRequest();
        $cardUpdateRequest->setLocale($this->config->locale);
        $cardUpdateRequest->setConversationId($this->config->conversationId);
        $cardUpdateRequest->setCustomerReferenceCode($customerReferenceCode);
        $cardUpdateRequest->setCallbackUrl($callbackUrl);

        return SubscriptionCardUpdate::create(
            $cardUpdateRequest,
            OptionsFactory::create($this->config)
        );
    }
}
