<?php
declare(strict_types=1);

namespace Eren\PhpIyzico\Services;

use Eren\PhpIyzico\Config;
use Eren\PhpIyzico\OptionsFactory;
use Iyzipay\Model\InstallmentInfo;
use Iyzipay\Request\RetrieveInstallmentInfoRequest;
use InvalidArgumentException;

final class InstallmentService
{
    public function __construct(private Config $config)
    {
    }

    /**
     * Taksit tablolarını döndürür.
     *
     * Zorunlu: price
     * Opsiyonel: binNumber
     */
    public function query(string|int|float $price, ?string $binNumber = null): InstallmentInfo
    {
        $normalizedPrice = $this->normalizePrice($price);

        $installmentRequest = new RetrieveInstallmentInfoRequest();
        $installmentRequest->setLocale($this->config->locale);
        $installmentRequest->setConversationId($this->config->conversationId);
        $installmentRequest->setPrice($normalizedPrice);

        if ($binNumber !== null && $binNumber !== '') {
            $installmentRequest->setBinNumber($this->normalizeBinNumber($binNumber));
        }

        return InstallmentInfo::retrieve($installmentRequest, OptionsFactory::create($this->config));
    }

    private function normalizePrice(string|int|float $price): string
    {
        if (is_string($price)) {
            $price = trim($price);
        }

        $numericPrice = (float) $price;

        if ($numericPrice <= 0) {
            throw new InvalidArgumentException('Fiyat (price) 0’dan büyük olmalıdır.');
        }

        return number_format($numericPrice, 2, '.', '');
    }

    private function normalizeBinNumber(string $binNumber): string
    {
        // Sadece rakamları alalım
        $filteredBinNumber = preg_replace('/\D+/', '', $binNumber ?? '');

        if ($filteredBinNumber === null) {
            $filteredBinNumber = '';
        }

        $length = strlen($filteredBinNumber);

        if ($length < 6 || $length > 8) {
            throw new InvalidArgumentException('BIN numarası 6-8 haneli olmalıdır.');
        }

        return $filteredBinNumber;
    }
}
