<?php
declare(strict_types=1);

namespace Eren5\PhpIyzico;

use Iyzipay\Options;

final class OptionsFactory
{
    public static function create(Config $cfg): Options
    {
        $opt = new Options();
        $opt->setApiKey($cfg->apiKey);
        $opt->setSecretKey($cfg->secretKey);
        $opt->setBaseUrl($cfg->baseUrl);
        return $opt;
    }
}
