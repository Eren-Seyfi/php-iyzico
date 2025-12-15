<?php
declare(strict_types=1);

namespace Eren\PhpIyzico\Services\Marketplace;

use Eren\PhpIyzico\Config;
use Eren\PhpIyzico\OptionsFactory;

use InvalidArgumentException;
use Iyzipay\Options;
use Iyzipay\Model\Locale;
use Iyzipay\Model\Approval;
use Iyzipay\Model\Disapproval;
use Iyzipay\Request\CreateApprovalRequest;

/**
 * TransactionApprovalService
 *
 * Pazaryeri ödemelerinde, her bir sepet kalemi için:
 *  - Onay Verme
 *  - Onay Kaldırma
 *
 * NOT:
 *   İşlem kimliği "paymentTransactionId"dir.
 *   Bu ID, Payment içerisindeki her basket item için farklıdır.
 */
final class TransactionApprovalService
{
    private Options $options;

    public function __construct(private Config $config)
    {
        $this->options = OptionsFactory::create($this->config);
    }

    /**
     * Sepet kalemi için ONAY VERİR (Approval)
     *
     * @param array{
     *   paymentTransactionId: string|int,
     *   locale?: string,
     *   conversationId?: string
     * } $requestData
     *
     * @return array{
     *   ok: bool,
     *   status: string|null,
     *   errorCode: string|null,
     *   errorMessage: string|null,
     *   raw: array<string,mixed>|string|null
     * }
     */
    public function approve(array $requestData): array
    {
        $this->requireFields($requestData, ['paymentTransactionId']);

        $request = new CreateApprovalRequest();
        $request->setLocale($requestData['locale'] ?? Locale::TR);
        $request->setConversationId($requestData['conversationId'] ?? (string) microtime(true));
        $request->setPaymentTransactionId((string) $requestData['paymentTransactionId']);

        $response = Approval::create($request, $this->options);

        return $this->normalizeResponse($response);
    }

    /**
     * Sepet kalemi için ONAY KALDIRIR (Disapproval)
     *
     * @param array{
     *   paymentTransactionId: string|int,
     *   locale?: string,
     *   conversationId?: string
     * } $requestData
     *
     * @return array{
     *   ok: bool,
     *   status: string|null,
     *   errorCode: string|null,
     *   errorMessage: string|null,
     *   raw: array<string,mixed>|string|null
     * }
     */
    public function disapprove(array $requestData): array
    {
        $this->requireFields($requestData, ['paymentTransactionId']);

        // Disapproval da aynı request sınıfını kullanır
        $request = new CreateApprovalRequest();
        $request->setLocale($requestData['locale'] ?? Locale::TR);
        $request->setConversationId($requestData['conversationId'] ?? (string) microtime(true));
        $request->setPaymentTransactionId((string) $requestData['paymentTransactionId']);

        $response = Disapproval::create($request, $this->options);

        return $this->normalizeResponse($response);
    }

    /* ============================================================
     * HELPER METHODS
     * ============================================================
     */

    /**
     * Zorunlu alan kontrolü
     *
     * @param array<string,mixed> $data
     * @param string[] $requiredFields
     */
    private function requireFields(array $data, array $requiredFields): void
    {
        foreach ($requiredFields as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                throw new InvalidArgumentException("Required field missing: {$field}");
            }
        }
    }

    /**
     * Iyzipay response objesini okunabilir tek tip diziye çevirir.
     *
     * @param object $response
     * @return array<string,mixed>
     */
    private function normalizeResponse(object $response): array
    {
        $rawResult = method_exists($response, 'getRawResult') ? $response->getRawResult() : null;
        $rawArray = ($rawResult && is_string($rawResult)) ? json_decode($rawResult, true) : null;

        $status = method_exists($response, 'getStatus') ? $response->getStatus() : ($rawArray['status'] ?? null);
        $errorCode = method_exists($response, 'getErrorCode') ? $response->getErrorCode() : ($rawArray['errorCode'] ?? null);
        $errorMessage = method_exists($response, 'getErrorMessage') ? $response->getErrorMessage() : ($rawArray['errorMessage'] ?? null);

        return [
            'ok' => ($status === 'success'),
            'status' => $status,
            'errorCode' => $errorCode,
            'errorMessage' => $errorMessage,
            'raw' => $rawArray ?: $rawResult,
        ];
    }
}
