<?php
declare(strict_types=1);

namespace Eren5\PhpIyzico\Services\Marketplace;

use InvalidArgumentException;
use Eren5\PhpIyzico\Config;
use Eren5\PhpIyzico\OptionsFactory;

use Iyzipay\Options;
use Iyzipay\Model\Currency;
use Iyzipay\Model\Locale;
use Iyzipay\Model\SubMerchant;
use Iyzipay\Model\SubMerchantType;
use Iyzipay\Model\SubMerchantPaymentItemUpdate;

use Iyzipay\Request\CreateSubMerchantRequest;
use Iyzipay\Request\UpdateSubMerchantRequest;
use Iyzipay\Request\RetrieveSubMerchantRequest;
use Iyzipay\Request\SubMerchantPaymentItemUpdateRequest;

/**
 * Alt Üye İş Yeri Yönetimi Servisi
 *
 * Sağladığı işlemler:
 * - createSubMerchant()
 * - updateSubMerchant()
 * - retrieveSubMerchantByExternalId()
 * - retrieve()
 * - updateSubMerchantPaymentItem()
 */
final class SubMerchantService
{
    private Options $options;

    public function __construct(private Config $config)
    {
        $this->options = OptionsFactory::create($this->config);
    }

    /* ============================================================
     *  SUB MERCHANT CREATE
     * ============================================================ */
    public function createSubMerchant(array $requestData): array
    {
        $normalizedData = $this->normalizeCommonFields($requestData);

        $this->assertRequiredFields($normalizedData, [
            'subMerchantType',
            'subMerchantExternalId',
            'name',
            'email',
            'address',
            'iban',
        ]);

        $subMerchantType = $this->normalizeSubMerchantType($normalizedData['subMerchantType']);

        $this->assertRequiredFieldsForType($subMerchantType, $normalizedData);

        $request = new CreateSubMerchantRequest();
        $request->setLocale($normalizedData['locale'] ?? Locale::TR);

        if (!empty($normalizedData['conversationId'])) {
            $request->setConversationId((string) $normalizedData['conversationId']);
        }

        $request->setSubMerchantExternalId($normalizedData['subMerchantExternalId']);
        $request->setSubMerchantType($subMerchantType);

        $request->setName($normalizedData['name']);
        $request->setEmail($normalizedData['email']);
        $request->setAddress($normalizedData['address']);
        $request->setIban($normalizedData['iban']);
        $request->setCurrency(
            $this->normalizeCurrency($normalizedData['currency'] ?? Currency::TL)
        );

        // Opsiyonel ortak alanlar
        $request->setGsmNumber($normalizedData['gsmNumber'] ?? null);
        $request->setContactName($normalizedData['contactName'] ?? null);
        $request->setContactSurname($normalizedData['contactSurname'] ?? null);

        // Tipe özgü zorunlu alanlar
        if ($subMerchantType === SubMerchantType::PERSONAL) {
            $request->setIdentityNumber($normalizedData['identityNumber']);
        }

        if ($subMerchantType === SubMerchantType::PRIVATE_COMPANY) {
            $request->setIdentityNumber($normalizedData['identityNumber']);
            $request->setTaxOffice($normalizedData['taxOffice']);
            $request->setLegalCompanyTitle($normalizedData['legalCompanyTitle']);
        }

        if ($subMerchantType === SubMerchantType::LIMITED_OR_JOINT_STOCK_COMPANY) {
            $request->setTaxOffice($normalizedData['taxOffice']);
            $request->setTaxNumber($normalizedData['taxNumber']);
            $request->setLegalCompanyTitle($normalizedData['legalCompanyTitle']);
        }

        $response = SubMerchant::create($request, $this->options);

        return $this->normalizeIyzipayResponse($response);
    }

    /* ============================================================
     *  SUB MERCHANT UPDATE
     * ============================================================ */
    public function updateSubMerchant(string $subMerchantKey, array $requestData): array
    {
        if (trim($subMerchantKey) === '') {
            throw new InvalidArgumentException('subMerchantKey cannot be empty.');
        }

        $normalizedData = $this->normalizeCommonFields($requestData);

        $request = new UpdateSubMerchantRequest();
        $request->setLocale($normalizedData['locale'] ?? Locale::TR);

        if (!empty($normalizedData['conversationId'])) {
            $request->setConversationId((string) $normalizedData['conversationId']);
        }

        $request->setSubMerchantKey($subMerchantKey);

        // Güncellenebilen tüm alanlar
        $this->applyUpdatableFields($request, $normalizedData);

        $response = SubMerchant::update($request, $this->options);

        return $this->normalizeIyzipayResponse($response);
    }

    /* ============================================================
     *  SUB MERCHANT RETRIEVE
     * ============================================================ */
    public function retrieveSubMerchantByExternalId(string $externalId): array
    {
        if (trim($externalId) === '') {
            throw new InvalidArgumentException('externalId cannot be empty.');
        }

        $request = new RetrieveSubMerchantRequest();
        $request->setLocale(Locale::TR);
        $request->setSubMerchantExternalId($externalId);

        $response = SubMerchant::retrieve($request, $this->options);

        return $this->normalizeIyzipayResponse($response);
    }

    /** Backward compatible alias */
    public function retrieve(string $externalId): array
    {
        return $this->retrieveSubMerchantByExternalId($externalId);
    }

    /* ============================================================
     *  SUB MERCHANT PAYMENT ITEM UPDATE
     * ============================================================ */
    public function updateSubMerchantPaymentItem(array $requestData): array
    {
        $this->assertRequiredFields($requestData, [
            'paymentTransactionId',
            'subMerchantKey',
            'subMerchantPrice'
        ]);

        $request = new SubMerchantPaymentItemUpdateRequest();
        $request->setLocale($requestData['locale'] ?? Locale::TR);

        if (!empty($requestData['conversationId'])) {
            $request->setConversationId((string) $requestData['conversationId']);
        }

        $request->setPaymentTransactionId((string) $requestData['paymentTransactionId']);
        $request->setSubMerchantKey((string) $requestData['subMerchantKey']);
        $request->setSubMerchantPrice((float) $requestData['subMerchantPrice']);

        $response = SubMerchantPaymentItemUpdate::create($request, $this->options);

        return $this->normalizeIyzipayResponse($response);
    }

    /* ============================================================
     *  PRIVATE HELPERS
     * ============================================================ */

    private function assertRequiredFields(array $data, array $requiredFields): void
    {
        foreach ($requiredFields as $fieldName) {
            if (!isset($data[$fieldName]) || $data[$fieldName] === '') {
                throw new InvalidArgumentException("Missing required field: {$fieldName}");
            }
        }
    }

    private function normalizeCommonFields(array $data): array
    {
        if (!empty($data['iban'])) {
            $data['iban'] = strtoupper(str_replace(' ', '', $data['iban']));
        }

        if (!empty($data['gsmNumber'])) {
            $phone = preg_replace('/\s+/', '', $data['gsmNumber']);
            if (str_starts_with($phone, '0')) {
                $phone = '+90' . substr($phone, 1);
            }
            $data['gsmNumber'] = $phone;
        }

        return $data;
    }

    private function normalizeCurrency(string $currencyCode): string
    {
        $currencyCode = strtoupper(trim($currencyCode));

        return match ($currencyCode) {
            'TRY', 'TL' => Currency::TL,
            'USD' => Currency::USD,
            'EUR' => Currency::EUR,
            'GBP' => Currency::GBP,
            'IRR' => Currency::IRR,
            'RUB' => Currency::RUB,
            'CHF' => Currency::CHF,
            'NOK' => Currency::NOK,
            default => Currency::TL,
        };
    }

    private function normalizeSubMerchantType(string|int $type): string
    {
        if (is_int($type)) {
            return (string) $type;
        }

        return match (strtoupper($type)) {
            'PERSONAL' => SubMerchantType::PERSONAL,
            'PRIVATE', 'PRIVATE_COMPANY' => SubMerchantType::PRIVATE_COMPANY,
            'LIMITED', 'LTD', 'JOINT_STOCK', 'LIMITED_OR_JOINT_STOCK_COMPANY'
            => SubMerchantType::LIMITED_OR_JOINT_STOCK_COMPANY,
            default => throw new InvalidArgumentException("Invalid subMerchantType: {$type}")
        };
    }

    private function assertRequiredFieldsForType(string $type, array $data): void
    {
        if ($type === SubMerchantType::PERSONAL) {
            $this->assertRequiredFields($data, ['identityNumber']);
        }

        if ($type === SubMerchantType::PRIVATE_COMPANY) {
            $this->assertRequiredFields($data, [
                'identityNumber',
                'taxOffice',
                'legalCompanyTitle'
            ]);
        }

        if ($type === SubMerchantType::LIMITED_OR_JOINT_STOCK_COMPANY) {
            $this->assertRequiredFields($data, [
                'taxOffice',
                'taxNumber',
                'legalCompanyTitle'
            ]);
        }
    }

    private function applyUpdatableFields(UpdateSubMerchantRequest $request, array $data): void
    {
        foreach ($data as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $method = 'set' . ucfirst($key);

            if (method_exists($request, $method)) {
                $request->{$method}($value);
            }
        }
    }

    private function normalizeIyzipayResponse(object $iyzipayResponse): array
    {
        $rawJson = method_exists($iyzipayResponse, 'getRawResult')
            ? $iyzipayResponse->getRawResult()
            : null;

        $decoded = $rawJson ? json_decode($rawJson, true) : null;

        return [
            'ok' => ($decoded['status'] ?? null) === 'success',
            'status' => $decoded['status'] ?? null,
            'errorCode' => $decoded['errorCode'] ?? null,
            'errorMessage' => $decoded['errorMessage'] ?? null,
            'subMerchantKey' => $decoded['subMerchantKey'] ?? null,
            'raw' => $decoded ?: $rawJson,
        ];
    }
}
