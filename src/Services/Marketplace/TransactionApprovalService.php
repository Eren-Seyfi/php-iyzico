<?php
declare(strict_types=1);

namespace Eren5\PhpIyzico\Services\Marketplace;

use Eren5\PhpIyzico\Config;
use Eren5\PhpIyzico\OptionsFactory;
use InvalidArgumentException;

// Iyzipay
use Iyzipay\Options;
use Iyzipay\Model\Locale;                       // "tr" | "en"
use Iyzipay\Request\CreateApprovalRequest;      // Ortak istek sınıfı
use Iyzipay\Model\Approval;                     // Onay verme endpoint'i
use Iyzipay\Model\Disapproval;                  // Onay kaldırma endpoint'i

/**
 * TransactionApprovalService
 *
 * Pazaryeri ödemelerinde sepet kalemi (payment item) bazında:
 * - Onay Verme (Approval::create)
 * - Onay Kaldırma (Disapproval::create)
 *
 * NOT:
 * - Buradaki kimlik "paymentTransactionId"dir (ödemenin her basket item’ına aittir),
 *   "paymentId" değildir.
 */
final class TransactionApprovalService
{
    /** @var Options Iyzipay Options (apiKey/secret/baseUrl vs.) */
    private Options $options;

    public function __construct(private Config $config)
    {
        $this->options = OptionsFactory::create($this->config);
    }

    /**
     * Onay Verme (Approval)
     *
     * @param array{
     *   paymentTransactionId: string|int,   // Zorunlu: Onaylanacak işlem kalemi
     *   locale?: string,                    // Opsiyonel: Locale::TR | Locale::EN
     *   conversationId?: string             // Opsiyonel: İzleme amaçlı
     * } $data
     *
     * @return array{
     *   ok: bool,
     *   status: string|null,
     *   errorCode: string|null,
     *   errorMessage: string|null,
     *   raw: array<string,mixed>|string|null
     * }
     */
    public function approve(array $data): array
    {
        $this->require($data, ['paymentTransactionId']);

        $req = new CreateApprovalRequest();
        $req->setLocale($data['locale'] ?? Locale::TR);
        $req->setConversationId($data['conversationId'] ?? (string) microtime(true));
        $req->setPaymentTransactionId((string) $data['paymentTransactionId']);

        $resp = Approval::create($req, $this->options);

        return $this->responseToArray($resp);
    }

    /**
     * Onay Kaldırma (Disapproval)
     *
     * @param array{
     *   paymentTransactionId: string|int,   // Zorunlu: Onayı kaldırılacak işlem kalemi
     *   locale?: string,                    // Opsiyonel
     *   conversationId?: string             // Opsiyonel
     * } $data
     *
     * @return array{
     *   ok: bool,
     *   status: string|null,
     *   errorCode: string|null,
     *   errorMessage: string|null,
     *   raw: array<string,mixed>|string|null
     * }
     */
    public function disapprove(array $data): array
    {
        $this->require($data, ['paymentTransactionId']);

        $req = new CreateApprovalRequest(); // Aynı request sınıfı kullanılır
        $req->setLocale($data['locale'] ?? Locale::TR);
        $req->setConversationId($data['conversationId'] ?? (string) microtime(true));
        $req->setPaymentTransactionId((string) $data['paymentTransactionId']);

        $resp = Disapproval::create($req, $this->options);

        return $this->responseToArray($resp);
    }

    /* =========================
     * Helpers
     * ========================= */

    /**
     * Zorunlu alan kontrolü (null/boş string kabul edilmez)
     *
     * @param array<string,mixed> $data
     * @param string[] $fields
     */
    private function require(array $data, array $fields): void
    {
        foreach ($fields as $f) {
            if (!array_key_exists($f, $data) || $data[$f] === null || $data[$f] === '') {
                throw new InvalidArgumentException("Missing required field: {$f}");
            }
        }
    }

    /**
     * Iyzipay response nesnesini okunaklı diziye çevirir.
     *
     * Dönen ortak alanlar:
     * - ok: status === "success" ise true
     * - status, errorCode, errorMessage
     * - raw: ham JSON (array) veya parse edilemediyse string
     *
     * @param object $response Iyzipay\Model\* nesnesi
     * @return array<string,mixed>
     */
    private function responseToArray(object $response): array
    {
        $raw = method_exists($response, 'getRawResult') ? $response->getRawResult() : null;
        $arr = $raw ? json_decode($raw, true) : [];

        $status = method_exists($response, 'getStatus') ? $response->getStatus() : ($arr['status'] ?? null);
        $errCode = method_exists($response, 'getErrorCode') ? $response->getErrorCode() : ($arr['errorCode'] ?? null);
        $errMsg = method_exists($response, 'getErrorMessage') ? $response->getErrorMessage() : ($arr['errorMessage'] ?? null);

        return [
            'ok' => ($status === 'success'),
            'status' => $status,
            'errorCode' => $errCode,
            'errorMessage' => $errMsg,
            'raw' => $arr ?: $raw,
        ];
    }
}
