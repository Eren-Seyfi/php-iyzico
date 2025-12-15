<?php
declare(strict_types=1);

namespace Eren\PhpIyzico\Services;

use Eren\PhpIyzico\Config;
use Eren\PhpIyzico\OptionsFactory;
use Iyzipay\Model\BinNumber;
use Iyzipay\Request\RetrieveBinNumberRequest;
use InvalidArgumentException;

final class BinService
{
    public function __construct(private Config $config)
    {
    }

    /**
     * BIN numarasını (ilk 6-8 hane) sorgular.
     *
     * @param  string $binNumber  En az 6, en fazla 8 haneli numerik BIN.
     */
    public function check(string $binNumber): BinNumber
    {
        $normalizedBinNumber = $this->normalizeBinNumber($binNumber);

        $retrieveBinNumberRequest = new RetrieveBinNumberRequest();
        $retrieveBinNumberRequest->setLocale($this->config->locale);
        $retrieveBinNumberRequest->setConversationId($this->config->conversationId);
        $retrieveBinNumberRequest->setBinNumber($normalizedBinNumber);

        return BinNumber::retrieve(
            $retrieveBinNumberRequest,
            OptionsFactory::create($this->config)
        );
    }

    /**
     * Basit temizlik ve doğrulama.
     */
    private function normalizeBinNumber(string $binNumber): string
    {
        // Sadece rakamları al
        $filteredNumber = preg_replace('/\D+/', '', $binNumber ?? '');

        if ($filteredNumber === null) {
            $filteredNumber = '';
        }

        $length = strlen($filteredNumber);

        if ($length < 6 || $length > 8) {
            throw new InvalidArgumentException('BIN numarası 6-8 haneli olmalıdır.');
        }

        return $filteredNumber;
    }
}
