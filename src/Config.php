<?php
declare(strict_types=1);

namespace Eren\PhpIyzico;

final class Config
{
    public function __construct(
        public readonly string $apiKey,
        public readonly string $secretKey,
        public readonly string $baseUrl = 'https://sandbox-api.iyzipay.com',
        public readonly string $locale = \Iyzipay\Model\Locale::TR,
        public readonly ?string $conversationId = null
    ) {
    }
}
