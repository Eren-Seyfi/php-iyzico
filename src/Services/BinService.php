<?php
declare(strict_types=1);

namespace Eren5\PhpIyzico\Services;

use Eren5\PhpIyzico\Config;
use Eren5\PhpIyzico\OptionsFactory;
use Iyzipay\Model\BinNumber;
use Iyzipay\Request\RetrieveBinNumberRequest;
use InvalidArgumentException;

final class BinService
{
    public function __construct(private Config $cfg)
    {
    }

    /**
     * BIN numarasını (ilk 6-8 hane) sorgular.
     *
     * @param  string $binNumber  En az 6, en fazla 8 haneli numerik BIN.
     * @return BinNumber
     *
     * @throws InvalidArgumentException
     */
    public function check(string $binNumber): BinNumber
    {
        $bin = $this->normalizeBin($binNumber);

        $req = new RetrieveBinNumberRequest();
        $req->setLocale($this->cfg->locale);
        $req->setConversationId($this->cfg->conversationId);
        $req->setBinNumber($bin);

        return BinNumber::retrieve($req, OptionsFactory::create($this->cfg));
    }

    /**
     * Yalnızca basit bir doğrulama ve trim/cleanup yapar.
     */
    private function normalizeBin(string $bin): string
    {
        $bin = preg_replace('/\D+/', '', $bin ?? '');
        if ($bin === null) {
            $bin = '';
        }

        // Iyzico örneklerinde 6 hane kullanılır; bazı bankalarda 7–8 hane görülebilir.
        if (strlen($bin) < 6 || strlen($bin) > 8) {
            throw new InvalidArgumentException('BIN numarası 6-8 haneli olmalıdır.');
        }
        return $bin;
    }
}
