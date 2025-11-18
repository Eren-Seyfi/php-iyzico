<?php
declare(strict_types=1);

namespace Eren5\PhpIyzico\Services\Subscription;

use Eren5\PhpIyzico\Config;
use Eren5\PhpIyzico\OptionsFactory;

use Iyzipay\Model\Subscription\SubscriptionCardUpdate;
use Iyzipay\Request\Subscription\SubscriptionCardUpdateRequest;

/**
 * Müşteri kartı güncelleme formu (checkout content döner)
 */
final class CardUpdateService
{
    public function __construct(private Config $cfg)
    {
    }

    /** Müşteri referansı ile kart güncelleme formu başlatır */
    public function byCustomer(string $customerRef, string $callbackUrl)
    {
        $r = new SubscriptionCardUpdateRequest();
        $r->setLocale($this->cfg->locale);
        $r->setConversationId($this->cfg->conversationId);
        $r->setCustomerReferenceCode($customerRef);
        $r->setCallbackUrl($callbackUrl);

        return SubscriptionCardUpdate::create($r, OptionsFactory::create($this->cfg));
    }
}
