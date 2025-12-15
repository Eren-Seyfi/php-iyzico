<?php
declare(strict_types=1);

namespace Eren5\PhpIyzico\Services\Subscription;

use Eren5\PhpIyzico\Config;
use Eren5\PhpIyzico\OptionsFactory;

// Iyzipay Models & Requests
use Iyzipay\Model\Subscription\SubscriptionProduct;
use Iyzipay\Model\Subscription\RetrieveList;
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
    public function __construct(private Config $config)
    {
    }

    /**
     * Ürün oluşturma
     */
    public function create(string $productName, ?string $productDescription = null)
    {
        $createProductRequest = new SubscriptionCreateProductRequest();
        $createProductRequest->setLocale($this->config->locale);
        $createProductRequest->setConversationId($this->config->conversationId);
        $createProductRequest->setName($productName);

        if ($productDescription !== null) {
            $createProductRequest->setDescription($productDescription);
        }

        return SubscriptionProduct::create(
            $createProductRequest,
            OptionsFactory::create($this->config)
        );
    }

    /**
     * Ürün getirme (referenceCode ile)
     */
    public function retrieve(string $productReferenceCode)
    {
        $retrieveProductRequest = new SubscriptionRetrieveProductRequest();
        $retrieveProductRequest->setLocale($this->config->locale);
        $retrieveProductRequest->setConversationId($this->config->conversationId);
        $retrieveProductRequest->setProductReferenceCode($productReferenceCode);

        return SubscriptionProduct::retrieve(
            $retrieveProductRequest,
            OptionsFactory::create($this->config)
        );
    }

    /**
     * Ürünleri listeleme
     */
    public function list(int $page = 1, int $count = 10)
    {
        $listProductsRequest = new SubscriptionListProductsRequest();
        $listProductsRequest->setLocale($this->config->locale);
        $listProductsRequest->setConversationId($this->config->conversationId);

        if (method_exists($listProductsRequest, 'setPage')) {
            $listProductsRequest->setPage($page);
        }
        if (method_exists($listProductsRequest, 'setCount')) {
            $listProductsRequest->setCount($count);
        }

        return RetrieveList::products(
            $listProductsRequest,
            OptionsFactory::create($this->config)
        );
    }

    /**
     * Ürün güncelleme (ad / açıklama)
     */
    public function update(
        string $productReferenceCode,
        ?string $productName = null,
        ?string $productDescription = null
    ) {
        $updateProductRequest = new SubscriptionUpdateProductRequest();
        $updateProductRequest->setLocale($this->config->locale);
        $updateProductRequest->setConversationId($this->config->conversationId);
        $updateProductRequest->setProductReferenceCode($productReferenceCode);

        if ($productName !== null) {
            $updateProductRequest->setName($productName);
        }
        if ($productDescription !== null) {
            $updateProductRequest->setDescription($productDescription);
        }

        return SubscriptionProduct::update(
            $updateProductRequest,
            OptionsFactory::create($this->config)
        );
    }

    /**
     * Ürün silme
     */
    public function delete(string $productReferenceCode)
    {
        $deleteProductRequest = new SubscriptionDeleteProductRequest();
        $deleteProductRequest->setLocale($this->config->locale);
        $deleteProductRequest->setConversationId($this->config->conversationId);
        $deleteProductRequest->setProductReferenceCode($productReferenceCode);

        return SubscriptionProduct::delete(
            $deleteProductRequest,
            OptionsFactory::create($this->config)
        );
    }
}
