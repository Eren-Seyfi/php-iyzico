<?php
declare(strict_types=1);

namespace Eren\PhpIyzico;

use Eren\PhpIyzico\Services\BinService;
use Eren\PhpIyzico\Services\CardStorage;
use Eren\PhpIyzico\Services\Checkout\CheckoutFormService;
use Eren\PhpIyzico\Services\Payments\Non3DS;
use Eren\PhpIyzico\Services\Payments\ThreeDS;
use Eren\PhpIyzico\Services\RefundCancel;
use Eren\PhpIyzico\Services\Status;
use Eren\PhpIyzico\Services\Subscription\ProductService;
use Eren\PhpIyzico\Services\Subscription\PricingPlanService;
use Eren\PhpIyzico\Services\Subscription\CustomerService;
use Eren\PhpIyzico\Services\Subscription\SubscriptionService;
use Eren\PhpIyzico\Services\Subscription\CardUpdateService;
use Eren\PhpIyzico\Services\Subscription\Webhook;
use PhpIyzico\Services\Links\LinkService;
use PhpIyzico\Services\PWI\PWIService;
use PhpIyzico\Services\Marketplace\SubMerchantService;
use PhpIyzico\Services\Marketplace\MarketPaymentService;
use PhpIyzico\Services\Marketplace\SettlementService;
/**
 * Iyzico servislerine erişim için ana facade/sunum sınıfı.
 * Tek bir Config örneği ile tüm alt servisleri enjekte eder.
 *
 * Örnek:
 * $iyzico = new Iyzico($config);
 * $payment = $iyzico->non3ds()->pay(...);
 */
final class Iyzico
{
    public function __construct(public readonly Config $config)
    {
    }

    /**
     * Non-3D ödeme işlemleri (tek adımda ödeme).
     *
     * Ne zaman kullanılır?
     * - 3D Secure gerekmeyen kart ödemeleri.
     * - Arka planda kart bilgisi ile doğrudan tahsilat.
     */
    public function non3ds(): Non3DS
    {
        return new Non3DS($this->config);
    }

    /**
     * 3D Secure ödeme işlemleri (iki aşamalı: init + auth).
     *
     * Ne zaman kullanılır?
     * - Banka/PCI gereklilikleri nedeniyle 3DS doğrulaması isteniyorsa.
     * - Yüksek riskli işlemlerde ek doğrulama.
     */
    public function threeds(): ThreeDS
    {
        return new ThreeDS($this->config);
    }

    /**
     * Hosted Ödeme Formu (Checkout Form) işlemleri.
     *
     * Ne zaman kullanılır?
     * - Kart verisini sizin sunucunuzda toplamadan, iyzico’nun barındırdığı form ile tahsilat.
     * - Taksit gösterimi, kayıtlı kart seçenekleri, kolay entegrasyon.
     */
    public function checkout(): CheckoutFormService
    {
        return new CheckoutFormService($this->config);
    }

    /**
     * İade & İptal işlemleri.
     *
     * Ne zaman kullanılır?
     * - Tam/partial refund (iade) veya cancel (iptal) akışları için.
     */
    public function refundCancel(): RefundCancel
    {
        return new RefundCancel($this->config);
    }

    /**
     * BIN sorgulama (kart ilk 6 haneden banka/marka/taksit bilgisi).
     *
     * Ne zaman kullanılır?
     * - Ödeme öncesi kart ait bankayı ve taksit/komisyon koşullarını göstermek için.
     */
    public function bin(): BinService
    {
        return new BinService($this->config);
    }

    /**
     * Ödeme/işlem durum sorguları.
     *
     * Ne zaman kullanılır?
     * - İşlem sonucunu, ödeme durumunu veya provizyon bilgisini kontrol etmek için.
     */
    public function status(): Status
    {
        return new Status($this->config);
    }

    /**
     * Kart saklama (Card Storage) işlemleri.
     *
     * Ne zaman kullanılır?
     * - Müşterinin kartını token’layıp sonraki ödemelerde hızlı ödeme sunmak için.
     */
    public function cards(): CardStorage
    {
        return new CardStorage($this->config);
    }

    // ===== Abonelik (Subscription) servisleri =====

    /**
     * Abonelik ürün yönetimi (Subscription Product).
     *
     * Ne zaman kullanılır?
     * - Farklı planların bağlanacağı ürün tanımlarını oluşturmak/güncellemek/silmek/listelemek için.
     */
    public function subscriptionProducts(): ProductService
    {
        return new ProductService($this->config);
    }

    /**
     * Abonelik plan yönetimi (Pricing Plan).
     *
     * Ne zaman kullanılır?
     * - Ücret, periyot, deneme süresi gibi plan parametrelerini oluşturmak ve yönetmek için.
     */
    public function subscriptionPlans(): PricingPlanService
    {
        return new PricingPlanService($this->config);
    }

    /**
     * Abonelik müşteri yönetimi.
     *
     * Ne zaman kullanılır?
     * - Abone müşteri kaydı, güncelleme, detay ve listeleme işlemleri için.
     */
    public function subscriptionCustomers(): CustomerService
    {
        return new CustomerService($this->config);
    }

    /**
     * Abonelik işlemleri (başlatma, upgrade, cancel, retry, details, search, kart güncelleme formu vb.).
     *
     * Ne zaman kullanılır?
     * - Bir plan üzerinden aboneliği başlatmak/aktif etmek/iptal etmek ya da aramak için.
     */
    public function subscriptions(): SubscriptionService
    {
        return new SubscriptionService($this->config);
    }

    /**
     * Abonelik kart güncelleme (Checkout ile) işlemleri için yardımcı servis.
     *
     * Ne zaman kullanılır?
     * - Abonenin kartını yeniden almak/güncellemek gerektiğinde (PCI yükünü azaltmak için hosted form).
     */
    public function subscriptionCards(): CardUpdateService
    {
        return new CardUpdateService($this->config);
    }

    /**
     * Abonelik webhook yönetimi.
     *
     * Ne zaman kullanılır?
     * - iyzico’nun gönderdiği abonelik olay bildirimlerini (yenileme, iptal, hata vb.) doğrulayıp işlemek için.
     */
    public function subscriptionWebhooks(): Webhook
    {
        return new Webhook($this->config);
    }


    public function links(): LinkService
    {
        return new LinkService($this->options, $this->signature);
    }

    public function pwi(): PWIService
    {
        return new PWIService($this->options, $this->signature);
    }
    public function marketplace(): object
    {
        return new class ($this->options) {
            public function __construct(private OptionsFactory $options)
            {
            }
            public function subMerchants(): SubMerchantService
            {
                return new SubMerchantService($this->options);
            }
            public function payments(): MarketPaymentService
            {
                return new MarketPaymentService($this->options);
            }
            public function settlements(): SettlementService
            {
                return new SettlementService($this->options);
            }
        };
    }


}
