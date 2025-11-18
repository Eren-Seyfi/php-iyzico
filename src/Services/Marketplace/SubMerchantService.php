<?php
declare(strict_types=1);
namespace Eren5\PhpIyzico\Services\Marketplace;

use InvalidArgumentException;
use Eren5\PhpIyzico\Config;
use Eren5\PhpIyzico\OptionsFactory;

// Iyzipay
use Iyzipay\Options;
use Iyzipay\Model\Currency;
use Iyzipay\Model\Locale;
use Iyzipay\Model\SubMerchant;
use Iyzipay\Model\SubMerchantPaymentItemUpdate;
use Iyzipay\Model\SubMerchantType;

use Iyzipay\Request\CreateSubMerchantRequest;
use Iyzipay\Request\UpdateSubMerchantRequest;
use Iyzipay\Request\RetrieveSubMerchantRequest;
use Iyzipay\Request\SubMerchantPaymentItemUpdateRequest;

/**
 * Alt Üye İş Yeri Servisi
 *
 * - create(array $data)                         : Alt üye iş yeri oluşturma (PERSONAL | PRIVATE_COMPANY | LIMITED_OR_JOINT_STOCK_COMPANY)
 * - update(string $subMerchantKey, array $data) : Alt üye iş yeri güncelleme
 * - retrieveByExternalId(string $externalId)    : Alt üye sorgulama (externalId ile)
 * - retrieve(string $externalId)                : retrieveByExternalId alias'ı
 * - updatePaymentItem(array $data)              : Hak ediş/kalem güncelleme (subMerchantPrice vb.)
 */
final class SubMerchantService
{
    private Options $options;

    public function __construct(private Config $config)
    {
        $this->options = OptionsFactory::create($this->config);
    }

    /* =========================
     * CREATE
     * ========================= */
    public function create(array $data): array
    {
        $data = $this->normalizeCommon($data);

        // Ortak zorunlular
        $this->require($data, [
            'subMerchantType',
            'subMerchantExternalId',
            'name',
            'email',
            'address',
            'iban',
        ]);

        // Tipe göre zorunlular
        $type = $this->normalizeType($data['subMerchantType']);
        $this->assertTypeSpecificRequired($type, $data);

        $req = new CreateSubMerchantRequest();
        $req->setLocale($data['locale'] ?? Locale::TR);
        if (!empty($data['conversationId'])) {
            $req->setConversationId((string) $data['conversationId']);
        }
        $req->setSubMerchantExternalId($data['subMerchantExternalId']);
        $req->setSubMerchantType($type);

        $req->setName($data['name']);
        $req->setEmail($data['email']);
        $req->setAddress($data['address']);
        $req->setIban($data['iban']);
        $req->setCurrency($this->normalizeCurrency($data['currency'] ?? Currency::TL));

        // Ortak/opsiyonel
        if (!empty($data['gsmNumber']))
            $req->setGsmNumber($data['gsmNumber']);
        if (!empty($data['contactName']))
            $req->setContactName($data['contactName']);
        if (!empty($data['contactSurname']))
            $req->setContactSurname($data['contactSurname']);

        // Tipe özgü alanlar
        if ($type === SubMerchantType::PERSONAL) {
            $req->setIdentityNumber($data['identityNumber']);
        } elseif ($type === SubMerchantType::PRIVATE_COMPANY) {
            $req->setIdentityNumber($data['identityNumber']);
            $req->setTaxOffice($data['taxOffice']);
            $req->setLegalCompanyTitle($data['legalCompanyTitle']);
        } elseif ($type === SubMerchantType::LIMITED_OR_JOINT_STOCK_COMPANY) {
            $req->setTaxOffice($data['taxOffice']);
            $req->setTaxNumber($data['taxNumber']);
            $req->setLegalCompanyTitle($data['legalCompanyTitle']);
        }

        $res = SubMerchant::create($req, $this->options);

        return $this->responseToArray($res);
    }

    /* =========================
     * UPDATE
     * ========================= */
    public function update(string $subMerchantKey, array $data): array
    {
        if ($subMerchantKey === '') {
            throw new InvalidArgumentException('subMerchantKey is required.');
        }

        $data = $this->normalizeCommon($data);

        $req = new UpdateSubMerchantRequest();
        $req->setLocale($data['locale'] ?? Locale::TR);
        if (!empty($data['conversationId'])) {
            $req->setConversationId((string) $data['conversationId']);
        }
        $req->setSubMerchantKey($subMerchantKey);

        // Güncellenebilen alanlar (Boş olmayanları set et)
        if (!empty($data['name']))
            $req->setName($data['name']);
        if (!empty($data['email']))
            $req->setEmail($data['email']);
        if (!empty($data['gsmNumber']))
            $req->setGsmNumber($data['gsmNumber']);
        if (!empty($data['address']))
            $req->setAddress($data['address']);
        if (!empty($data['iban']))
            $req->setIban($data['iban']);
        if (!empty($data['currency']))
            $req->setCurrency($this->normalizeCurrency($data['currency']));
        if (!empty($data['contactName']))
            $req->setContactName($data['contactName']);
        if (!empty($data['contactSurname']))
            $req->setContactSurname($data['contactSurname']);

        // Tipe özgü alanlar
        if (!empty($data['identityNumber']))
            $req->setIdentityNumber($data['identityNumber']);
        if (!empty($data['taxOffice']))
            $req->setTaxOffice($data['taxOffice']);
        if (!empty($data['taxNumber']))
            $req->setTaxNumber($data['taxNumber']);
        if (!empty($data['legalCompanyTitle']))
            $req->setLegalCompanyTitle($data['legalCompanyTitle']);

        $res = SubMerchant::update($req, $this->options);

        return $this->responseToArray($res);
    }

    /* =========================
     * RETRIEVE (externalId ile)
     * ========================= */
    public function retrieveByExternalId(string $subMerchantExternalId): array
    {
        if ($subMerchantExternalId === '') {
            throw new InvalidArgumentException('subMerchantExternalId is required.');
        }

        $req = new RetrieveSubMerchantRequest();
        $req->setLocale(Locale::TR);
        $req->setSubMerchantExternalId($subMerchantExternalId);

        $res = SubMerchant::retrieve($req, $this->options);

        return $this->responseToArray($res);
    }

    /** Backward-compatible alias */
    public function retrieve(string $externalId): array
    {
        return $this->retrieveByExternalId($externalId);
    }

    /* =========================
     * Hak Ediş / Kalem Güncelleme
     * ========================= */
    public function updatePaymentItem(array $data): array
    {
        $this->require($data, ['paymentTransactionId', 'subMerchantKey', 'subMerchantPrice']);

        $req = new SubMerchantPaymentItemUpdateRequest();
        $req->setLocale($data['locale'] ?? Locale::TR);
        if (!empty($data['conversationId'])) {
            $req->setConversationId((string) $data['conversationId']);
        }
        $req->setPaymentTransactionId((string) $data['paymentTransactionId']);
        $req->setSubMerchantKey((string) $data['subMerchantKey']);
        $req->setSubMerchantPrice((float) $data['subMerchantPrice']);

        $res = SubMerchantPaymentItemUpdate::create($req, $this->options);

        return $this->responseToArray($res);
    }

    /* =========================
     * Yardımcılar
     * ========================= */

    /** Zorunlu alan kontrolü */
    private function require(array $data, array $fields): void
    {
        foreach ($fields as $f) {
            if (!array_key_exists($f, $data) || $data[$f] === null || $data[$f] === '') {
                throw new InvalidArgumentException("Missing required field: {$f}");
            }
        }
    }

    /** Tipe göre zorunlu alanlar */
    private function assertTypeSpecificRequired(string $type, array $data): void
    {
        if ($type === SubMerchantType::PERSONAL) {
            $this->require($data, ['identityNumber']);
        } elseif ($type === SubMerchantType::PRIVATE_COMPANY) {
            $this->require($data, ['identityNumber', 'taxOffice', 'legalCompanyTitle']);
        } elseif ($type === SubMerchantType::LIMITED_OR_JOINT_STOCK_COMPANY) {
            $this->require($data, ['taxOffice', 'taxNumber', 'legalCompanyTitle']);
        } else {
            throw new InvalidArgumentException('Invalid subMerchantType.');
        }
    }

    /** TR IBAN / telefon normalize vb. */
    private function normalizeCommon(array $data): array
    {
        if (!empty($data['iban'])) {
            $data['iban'] = strtoupper(str_replace(' ', '', (string) $data['iban']));
        }
        if (!empty($data['gsmNumber'])) {
            $g = preg_replace('/\s+/', '', (string) $data['gsmNumber']);
            if (str_starts_with($g, '0')) {
                $g = '+90' . substr($g, 1);
            }
            // Ülke kodu yoksa dokunma; +905... beklenir
            $data['gsmNumber'] = $g;
        }
        return $data;
    }

    /** 'TL'|'TRY' -> Currency::TL; diğer yaygın birimler */
    private function normalizeCurrency(string $cur): string
    {
        $cur = strtoupper($cur);
        if ($cur === 'TRY' || $cur === 'TL')
            return Currency::TL;

        $map = [
            'USD' => Currency::USD,
            'EUR' => Currency::EUR,
            'GBP' => Currency::GBP,
            'IRR' => Currency::IRR,
            'NOK' => Currency::NOK,
            'RUB' => Currency::RUB,
            'CHF' => Currency::CHF

        ];
        return $map[$cur] ?? Currency::TL;
    }

    /** String/enum normalize: "PERSONAL" | 1 => SubMerchantType::PERSONAL vb. */
    private function normalizeType(string|int $type): string
    {
        if (is_int($type)) {
            // SDK int sabit kabul ediyor; direkt dön
            return (string) $type;
        }
        $t = strtoupper((string) $type);
        return match ($t) {
            'PERSONAL' => SubMerchantType::PERSONAL,
            'PRIVATE', 'PRIVATE_COMPANY' => SubMerchantType::PRIVATE_COMPANY,
            'LIMITED', 'JOINT_STOCK', 'LTD',
            'LIMITED_OR_JOINT_STOCK_COMPANY' => SubMerchantType::LIMITED_OR_JOINT_STOCK_COMPANY,
            default => throw new InvalidArgumentException('Unknown subMerchantType: ' . $type),
        };
    }

    /** Iyzipay response nesnesini diziye çevir */
    private function responseToArray(object $response): array
    {
        $raw = method_exists($response, 'getRawResult') ? $response->getRawResult() : null;
        $arr = $raw ? json_decode($raw, true) : [];

        $status = method_exists($response, 'getStatus') ? $response->getStatus() : ($arr['status'] ?? null);
        $errCode = method_exists($response, 'getErrorCode') ? $response->getErrorCode() : ($arr['errorCode'] ?? null);
        $errMsg = method_exists($response, 'getErrorMessage') ? $response->getErrorMessage() : ($arr['errorMessage'] ?? null);

        $subKey = $arr['subMerchantKey'] ?? null;

        return [
            'ok' => ($status === 'success'),
            'status' => $status,
            'errorCode' => $errCode,
            'errorMessage' => $errMsg,
            'subMerchantKey' => $subKey,
            'raw' => $arr ?: $raw,
        ];
    }
}
