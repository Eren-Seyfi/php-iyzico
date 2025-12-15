<?php
declare(strict_types=1);

namespace Eren5\PhpIyzico\Services\Links;

use Eren5\PhpIyzico\Config;
use Eren5\PhpIyzico\OptionsFactory;
use InvalidArgumentException;

// Iyzipay core
use Iyzipay\Options;
use Iyzipay\FileBase64Encoder;
use Iyzipay\Model\Locale;

// IyziLink REQUEST classes
use Iyzipay\Request as BaseRequest;
use Iyzipay\Request\PagininRequest;
use Iyzipay\Request\Iyzilink\IyziLinkSaveProductRequest;
use Iyzipay\Request\Iyzilink\IyziLinkUpdateProductStatusRequest;
use Iyzipay\Request\Iyzilink\IyziLinkCreateFastLinkRequest;

// IyziLink MODEL (endpoint) classes
use Iyzipay\Model\Iyzilink\IyziLinkSaveProduct;
use Iyzipay\Model\Iyzilink\IyziLinkUpdateProduct;
use Iyzipay\Model\Iyzilink\IyziLinkUpdateProductStatus;
use Iyzipay\Model\Iyzilink\IyziLinkDeleteProduct;
use Iyzipay\Model\Iyzilink\IyziLinkRetrieveProduct;
use Iyzipay\Model\Iyzilink\IyziLinkRetrieveAllProduct;
use Iyzipay\Model\Iyzilink\IyziLinkFastLink;

/**
 * iyzico Link & FastLink sarmalayıcı servis (helpersız sürüm).
 */

final class LinkService
{
    private Options $options;


    public function __construct(private Config $config)
    {
        $this->options = OptionsFactory::create($this->config);
    }

    /**
     * Kalıcı ödeme linki oluşturur (IyziLinkSaveProduct).
     *
     * @param array{
     *   name:string,
     *   price:float|int|string,
     *   currency:mixed,
     *   description?:string,
     *   base64Image?:string,
     *   imagePath?:string,
     *   addressIgnorable?:bool,
     *   soldLimit?:int,
     *   installmentRequest?:bool,
     *   sourceType?:string,
     *   stockEnabled?:bool,
     *   stockCount?:int,
     *   conversationId?:string,
     *   locale?:string
     * } $data
     */

    public function createPermanentLink(array $data): array
    {
        // Zorunlu alan kontrolü
        foreach (['name', 'price', 'currency'] as $f) {
            if (!array_key_exists($f, $data) || $data[$f] === null || $data[$f] === '') {
                throw new InvalidArgumentException("Missing required field: {$f}");
            }
        }

        $req = new IyziLinkSaveProductRequest();
        $req->setLocale($data['locale'] ?? Locale::TR);
        $req->setConversationId($data['conversationId'] ?? (string) microtime(true));
        $req->setName((string) $data['name']);
        $req->setDescription((string) ($data['description'] ?? ''));

        // Görsel (Data URL ise pure base64'e indir)
        if (!empty($data['base64Image'])) {
            $img = (string) $data['base64Image'];
            if (str_starts_with($img, 'data:')) {
                $parts = explode(',', $img, 2);
                $img = $parts[1] ?? '';
            }
            $req->setBase64EncodedImage($img);
        } elseif (!empty($data['imagePath'])) {
            $req->setBase64EncodedImage(FileBase64Encoder::encode((string) $data['imagePath']));
        }

        // Fiyat (2 ondalığa normalize etmeden doğrudan float)
        $req->setPrice((float) $data['price']);
        $req->setCurrency($data['currency']);
        $req->setAddressIgnorable((bool) ($data['addressIgnorable'] ?? false));

        if (array_key_exists('soldLimit', $data)) {
            $req->setSoldLimit((int) $data['soldLimit']);
        }
        $req->setInstallmentRequest((bool) ($data['installmentRequest'] ?? false));

        if (!empty($data['sourceType'])) {
            $req->setSourceType((string) $data['sourceType']);
        }
        if (array_key_exists('stockEnabled', $data)) {
            $req->setStockEnabled((bool) $data['stockEnabled']);
        }
        if (array_key_exists('stockCount', $data)) {
            $req->setStockCount((int) $data['stockCount']);
        }

        $resp = IyziLinkSaveProduct::create($req, $this->options);

        // Normalize (inline)
        $raw = method_exists($resp, 'getRawResult') ? $resp->getRawResult() : null;
        $arr = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($arr) ? $arr : ['rawResult' => $raw, 'debug' => (string) print_r($resp, true)];
    }

    /**
     * Kalıcı link güncelleme – IyziLinkUpdateProduct.
     */
    public function updatePermanentLink(string $token, array $data): array
    {
        foreach (['name', 'price', 'currency'] as $f) {
            if (!array_key_exists($f, $data) || $data[$f] === null || $data[$f] === '') {
                throw new InvalidArgumentException("Missing required field: {$f}");
            }
        }

        $req = new IyziLinkSaveProductRequest();
        $req->setLocale($data['locale'] ?? Locale::TR);
        $req->setConversationId($data['conversationId'] ?? (string) microtime(true));
        $req->setName((string) $data['name']);
        $req->setDescription((string) ($data['description'] ?? ''));

        if (!empty($data['base64Image'])) {
            $img = (string) $data['base64Image'];
            if (str_starts_with($img, 'data:')) {
                $parts = explode(',', $img, 2);
                $img = $parts[1] ?? '';
            }
            $req->setBase64EncodedImage($img);
        } elseif (!empty($data['imagePath'])) {
            $req->setBase64EncodedImage(FileBase64Encoder::encode((string) $data['imagePath']));
        }

        $req->setPrice((float) $data['price']);
        $req->setCurrency($data['currency']);
        $req->setAddressIgnorable((bool) ($data['addressIgnorable'] ?? false));

        if (array_key_exists('soldLimit', $data)) {
            $req->setSoldLimit((int) $data['soldLimit']);
        }
        $req->setInstallmentRequest((bool) ($data['installmentRequest'] ?? false));

        $resp = IyziLinkUpdateProduct::create($req, $this->options, $token);

        $raw = method_exists($resp, 'getRawResult') ? $resp->getRawResult() : null;
        $arr = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($arr) ? $arr : ['rawResult' => $raw, 'debug' => (string) print_r($resp, true)];
    }

    /** Link statüsünü günceller (ACTIVE / PASSIVE). */

    public function updateStatus(string $token, string $status, ?string $locale = null): array
    {
        $req = new IyziLinkUpdateProductStatusRequest();
        $req->setLocale($locale ?? Locale::TR);
        $req->setProductStatus($status);
        $req->setToken($token);

        $resp = IyziLinkUpdateProductStatus::create($req, $this->options);

        $raw = method_exists($resp, 'getRawResult') ? $resp->getRawResult() : null;
        $arr = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($arr) ? $arr : ['rawResult' => $raw, 'debug' => (string) print_r($resp, true)];
    }


    /** Link silme. */
    public function delete(string $token, ?string $conversationId = null, ?string $locale = null): array
    {
        $req = new BaseRequest();
        $req->setLocale($locale ?? Locale::TR);
        $req->setConversationId($conversationId ?? (string) microtime(true));

        $resp = IyziLinkDeleteProduct::create($req, $this->options, $token);

        $raw = method_exists($resp, 'getRawResult') ? $resp->getRawResult() : null;
        $arr = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($arr) ? $arr : ['rawResult' => $raw, 'debug' => (string) print_r($resp, true)];
    }

    /** Tekil link detayları (token). */
    public function retrieve(string $token, ?string $conversationId = null, ?string $locale = null): array
    {
        $req = new BaseRequest();
        $req->setLocale($locale ?? Locale::TR);
        $req->setConversationId($conversationId ?? (string) microtime(true));

        $resp = IyziLinkRetrieveProduct::create($req, $this->options, $token);

        $raw = method_exists($resp, 'getRawResult') ? $resp->getRawResult() : null;
        $arr = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($arr) ? $arr : ['rawResult' => $raw, 'debug' => (string) print_r($resp, true)];
    }

    /**
     * Linkleri sayfalı listeleme.
     */

    public function listAll(int $page = 1, int $count = 20, ?string $conversationId = null, ?string $locale = null): array
    {
        if ($page < 1) {
            throw new InvalidArgumentException('page 1 veya daha büyük olmalı.');
        }
        if ($count < 1) {
            throw new InvalidArgumentException('count 1 veya daha büyük olmalı.');
        }

        $req = new PagininRequest();
        $req->setLocale($locale ?? Locale::TR);
        $req->setConversationId($conversationId ?? (string) microtime(true));
        $req->setPage($page);
        $req->setCount($count);

        $resp = IyziLinkRetrieveAllProduct::create($req, $this->options);

        $raw = method_exists($resp, 'getRawResult') ? $resp->getRawResult() : null;
        $arr = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($arr) ? $arr : ['rawResult' => $raw, 'debug' => (string) print_r($resp, true)];
    }

    /**
     * FastLink (tek kullanımlık) oluşturma.
     *
     * @param array{
     *   price:float|int|string,
     *   currencyCode:string,
     *   description?:string,
     *   sourceType?:string,
     *   conversationId?:string,
     *   locale?:string
     * } $data
     */
    public function createFastLink(array $data): array
    {
        foreach (['price', 'currencyCode'] as $f) {
            if (!array_key_exists($f, $data) || $data[$f] === null || $data[$f] === '') {
                throw new InvalidArgumentException("Missing required field: {$f}");
            }
        }

        $price = (float) $data['price'];
        if ($price > 750.0) {
            throw new InvalidArgumentException('FastLink, 750 TL ve altındaki tutarlar için kullanılabilir.');
        }

        $req = new IyziLinkCreateFastLinkRequest();
        $req->setLocale($data['locale'] ?? Locale::TR);
        $req->setConversationId($data['conversationId'] ?? (string) microtime(true));
        $req->setDescription((string) ($data['description'] ?? ''));
        $req->setPrice($price);
        $req->setCurrencyCode((string) $data['currencyCode']);

        if (!empty($data['sourceType'])) {
            $req->setSourceType((string) $data['sourceType']);
        }

        $resp = IyziLinkFastLink::create($req, $this->options);

        $raw = method_exists($resp, 'getRawResult') ? $resp->getRawResult() : null;
        $arr = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($arr) ? $arr : ['rawResult' => $raw, 'debug' => (string) print_r($resp, true)];
    }
}
