<?php
declare(strict_types=1);

namespace Eren5\PhpIyzico\Services\Subscription;

use Eren5\PhpIyzico\Config;
use Eren5\PhpIyzico\OptionsFactory;

// Iyzipay Models & Requests
use Iyzipay\Model\Subscription\SubscriptionProduct;
use Iyzipay\Model\Subscription\RetrieveList; // <- LISTELEME İÇİN GEREKLİ
use Iyzipay\Request\Subscription\SubscriptionCreateProductRequest;
use Iyzipay\Request\Subscription\SubscriptionUpdateProductRequest;
use Iyzipay\Request\Subscription\SubscriptionDeleteProductRequest;
use Iyzipay\Request\Subscription\SubscriptionRetrieveProductRequest;
use Iyzipay\Request\Subscription\SubscriptionListProductsRequest;

/**
 * Ürün CRUD (Subscription Product)
 */
final class ProductService
{
    public function __construct(private Config $cfg)
    {
    }

    /** Ürün oluştur */
    public function create(string $name, ?string $description = null)
    {
        $r = new SubscriptionCreateProductRequest();
        $r->setLocale($this->cfg->locale);
        $r->setConversationId($this->cfg->conversationId);
        $r->setName($name);
        if ($description !== null) {
            $r->setDescription($description);
        }

        return SubscriptionProduct::create($r, OptionsFactory::create($this->cfg));
    }

    /** Ürün getir (referenceCode ile) */
    public function retrieve(string $productRefCode)
    {
        $r = new SubscriptionRetrieveProductRequest();
        // (SDK örneğinde locale/conversationId zorunlu değil ama eklemek sakıncalı değil)
        $r->setLocale($this->cfg->locale);
        $r->setConversationId($this->cfg->conversationId);
        $r->setProductReferenceCode($productRefCode);

        return SubscriptionProduct::retrieve($r, OptionsFactory::create($this->cfg));
    }

    /** Ürünleri listele (sayfalı) */
    public function list(int $page = 1, int $count = 10)
    {
        $r = new SubscriptionListProductsRequest();
        $r->setLocale($this->cfg->locale);
        $r->setConversationId($this->cfg->conversationId);

        // SDK örneği: $request->setPage(1); $request->setCount(10);
        // Bazı sürümlerde bu setter'lar farklı olabilir; method_exists ile güvence.
        if (method_exists($r, 'setPage')) {
            $r->setPage($page);
        }
        if (method_exists($r, 'setCount')) {
            $r->setCount($count);
        }

        // DÜZELTME: SubscriptionProduct::list(...) DEĞİL,
        // \Iyzipay\Model\Subscription\RetrieveList::products(...) kullanılmalı.
        return RetrieveList::products($r, OptionsFactory::create($this->cfg));
    }

    /** Ürün güncelle (ad / açıklama) */
    public function update(string $productRefCode, ?string $name = null, ?string $description = null)
    {
        $r = new SubscriptionUpdateProductRequest();
        $r->setLocale($this->cfg->locale);
        $r->setConversationId($this->cfg->conversationId);
        $r->setProductReferenceCode($productRefCode);

        if ($name !== null) {
            $r->setName($name);
        }
        if ($description !== null) {
            $r->setDescription($description);
        }

        return SubscriptionProduct::update($r, OptionsFactory::create($this->cfg));
    }

    /** Ürün sil (plan bağlıysa silinmeyebilir) */
    public function delete(string $productRefCode)
    {
        $r = new SubscriptionDeleteProductRequest();
        $r->setLocale($this->cfg->locale);
        $r->setConversationId($this->cfg->conversationId);
        $r->setProductReferenceCode($productRefCode);

        return SubscriptionProduct::delete($r, OptionsFactory::create($this->cfg));
    }
}
