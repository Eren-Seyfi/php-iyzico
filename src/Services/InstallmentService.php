<?php
declare(strict_types=1);

namespace Eren5\PhpIyzico\Services;

use Eren5\PhpIyzico\Config;
use Eren5\PhpIyzico\OptionsFactory;
use Iyzipay\Model\InstallmentInfo;
use Iyzipay\Request\RetrieveInstallmentInfoRequest;
use InvalidArgumentException;

final class InstallmentService
{
    public function __construct(private Config $cfg)
    {
    }

    /**
     * Taksit tablolarını döndürür.
     *
     * Iyzico örnekleri:
     * - Zorunlu: price
     * - Opsiyonel: binNumber (sağlanırsa o karta özel taksit tablosu gelir)
     *
     * @param  string|int|float $price      Örn: "100" ya da 100.00
     * @param  string|null      $binNumber  (opsiyonel) 6-8 haneli BIN
     * @return InstallmentInfo
     */
    public function query(string|int|float $price, ?string $binNumber = null): InstallmentInfo
    {
        $normalizedPrice = $this->normalizePrice($price);

        $req = new RetrieveInstallmentInfoRequest();
        $req->setLocale($this->cfg->locale);
        $req->setConversationId($this->cfg->conversationId);
        $req->setPrice($normalizedPrice);

        if ($binNumber !== null && $binNumber !== '') {
            $req->setBinNumber($this->normalizeBin($binNumber));
        }

        return InstallmentInfo::retrieve($req, OptionsFactory::create($this->cfg));
    }

    private function normalizePrice(string|int|float $price): string
    {
        if (is_string($price)) {
            $price = trim($price);
        }
        // Iyzico SDK string bekler. İki ondalık basamakla normalize edelim.
        $num = (float) $price;
        if ($num <= 0) {
            throw new InvalidArgumentException('Fiyat (price) 0’dan büyük olmalıdır.');
        }
        // Ondalık ayırıcı olarak nokta kullan (ör: "100.00")
        return number_format($num, 2, '.', '');
    }

    private function normalizeBin(string $bin): string
    {
        $bin = preg_replace('/\D+/', '', $bin ?? '');
        if ($bin === null) {
            $bin = '';
        }
        if (strlen($bin) < 6 || strlen($bin) > 8) {
            throw new InvalidArgumentException('BIN numarası 6-8 haneli olmalıdır.');
        }
        return $bin;
    }
}
