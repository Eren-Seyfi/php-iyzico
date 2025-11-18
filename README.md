# Eren5/PhpIyzico – Integration Helpers for iyzico (iyzipay-php)

Resmi **[`iyzico/iyzipay-php`]** SDK’sı etrafında hafif **service** ve **security** yardımcıları.  
Tekrarlayan kodu azaltır; **Non-3D/3DS ödeme**, **Checkout Form**, **Kart Saklama**, **Abonelik (Subscription)**, **İade/İptal**, **Durum Sorgu** ve **İmza Doğrulama** akışlarını tek API ile düzenler.

> **PHP:** 7.4+  
> **SDK:** `iyzico/iyzipay-php` (Composer)  
> **TLS:** en az **TLS 1.2**

---

## İçindekiler

- [Eren5/PhpIyzico – Integration Helpers for iyzico (iyzipay-php)](#eren5phpiyzico--integration-helpers-for-iyzico-iyzipay-php)
  - [İçindekiler](#i̇çindekiler)
  - [Kurulum](#kurulum)
  - [Hızlı Başlangıç](#hızlı-başlangıç)
  - [Mimari ve Sınıflar](#mimari-ve-sınıflar)
  - [Güvenlik \& İmza Doğrulama](#güvenlik--i̇mza-doğrulama)
  - [Ödeme Akışları](#ödeme-akışları)
    - [Non-3D Ödeme](#non-3d-ödeme)
      - [Metotlar](#metotlar)
      - [`$order` Parametreleri](#order-parametreleri)
    - [3DS Ödeme](#3ds-ödeme)
      - [Metotlar](#metotlar-1)
    - [Checkout Form](#checkout-form)
      - [Metotlar](#metotlar-2)
      - [`$order` Parametreleri](#order-parametreleri-1)
  - [Kart Saklama (Card Storage)](#kart-saklama-card-storage)
  - [İade / İptal](#i̇ade--i̇ptal)
  - [Durum Sorgu](#durum-sorgu)
  - [BIN Sorgu](#bin-sorgu)
  - [Abonelik (Subscription)](#abonelik-subscription)
    - [Ürün CRUD (SubscriptionProduct)](#ürün-crud-subscriptionproduct)
    - [Plan CRUD (PricingPlan)](#plan-crud-pricingplan)
    - [Müşteri CRUD (Subscription Customer)](#müşteri-crud-subscription-customer)
    - [Abonelik İşlemleri (SubscriptionService)](#abonelik-i̇şlemleri-subscriptionservice)
    - [Kart Güncelleme (Hosted Form)](#kart-güncelleme-hosted-form)
    - [Webhook Doğrulama (Subscription)](#webhook-doğrulama-subscription)
  - [Alanlar \& Zorunluluk Tabloları](#alanlar--zorunluluk-tabloları)
    - [Buyer](#buyer)
    - [Address](#address)
    - [BasketItem](#basketitem)
  - [Klasör Yapısı](#klasör-yapısı)
  - [Sandbox \& Test Kartları](#sandbox--test-kartları)
  - [Güvenlik Önerileri](#güvenlik-önerileri)
  - [Değişiklikler](#değişiklikler)
  - [Lisans](#lisans)

---

## Kurulum

```bash
composer require iyzico/iyzipay-php
```

Projene helper’ları dahil et:

```php
require_once __DIR__.'/vendor/autoload.php';
```

---

## Hızlı Başlangıç

```php
use Eren5\PhpIyzico\Config;
use Eren5\PhpIyzico\Iyzico;

// 1) Temel yapılandırma
$cfg = new Config(
  apiKey: 'sandbox-xxxxx',
  secretKey: 'sandbox-xxxxx',
  baseUrl: 'https://sandbox-api.iyzipay.com',
  locale: \Iyzipay\Model\Locale::TR,
  conversationId: 'order-123' // benzersiz üret
);

// 2) Facade üzerinden servisler
$iyz = new Iyzico($cfg);

// Örnek: Non-3D ödeme
$payment = $iyz->non3ds()->pay(
  order: ['price'=>'1.00','paidPrice'=>'1.20','basketId'=>'B67832'],
  card: (new \Iyzipay\Model\PaymentCard())
          ->setCardHolderName('John Doe')
          ->setCardNumber('5528790000000008')
          ->setExpireMonth('12')
          ->setExpireYear('2030')
          ->setCvc('123'),
  buyer: $buyerArray,
  shipAddress: $shipAddressArray,
  billAddress: $billingAddressArray,
  basketItems: $basketItemsArray
);
```

---

## Mimari ve Sınıflar

| Sınıf | Amaç | Not |
|---|---|---|
| `Config` | API anahtarı, secret, baseUrl, locale, conversationId | Tüm servislere tek noktadan geçer |
| `OptionsFactory` | `Iyzipay\Options` üretimi | SDK aramaları için |
| `Iyzico` | Facade: servisleri bir araya getirir | `$iyz->non3ds()`, `$iyz->subscriptions()` |
| `Security\Signature` | HMAC-SHA256 imza hesaplama/doğrulama | Callback/Webhook/Payment doğrulama |
| `Support\Helpers` | Buyer/Address/BasketItem kurulum yardımcıları | Veri normalizasyonu |
| `Services\Payments\Non3DS` | Non-3D ödeme akışı | `pay()`, `payAndVerify()`, `retrieve()` |
| `Services\Payments\ThreeDS` | 3DS akışı | `init()`, `auth()`, `...AndVerify()` |
| `Services\Checkout\CheckoutFormService` | Hosted Checkout Form | `initialize()`, `retrieve()`, verify |
| `Services\CardStorage` | Kart saklama | create/list/delete |
| `Services\RefundCancel` | İade / İptal | cancel/refund |
| `Services\Status` | Ödeme durumu | paymentDetail |
| `Services\BinService` | BIN sorgu | banka/marka/taksit |
| `Services\Subscription\*` | Abonelik modülü | Ürün/Plan/Müşteri/Abonelik/Webhook |

---

## Güvenlik & İmza Doğrulama

`Security\Signature` HMAC-SHA256 + Base64 (ikili çıktı) hesaplar. Dokümana göre tipik sıralar:

- **Payment / ThreedsPayment**  
  `paymentId, currency, basketId, conversationId, paidPrice, price`
- **ThreedsInitialize**  
  `paymentId, conversationId`
- **CheckoutForm Initialize**  
  `conversationId, token`
- **CheckoutForm Retrieve**  
  `paymentStatus, paymentId, currency, basketId, conversationId, paidPrice, price, token`

> Varsayılan ayraç `:` kabul edilmiştir. Sende farklıysa helper’da değiştir.

Örnek:

```php
$ok = \Eren5\PhpIyzico\Security\Signature::verifyPayment(
  $payment,
  \Eren5\PhpIyzico\OptionsFactory::create($cfg)
);
```

---

## Ödeme Akışları

### Non-3D Ödeme

**Servis:** `Services\Payments\Non3DS`

#### Metotlar

| Metot | Geri Dönüş | Açıklama |
|---|---|---|
| `pay($order,$card,$buyer,$ship,$bill,$items)` | `Iyzipay\Model\Payment` | Tek adımda ödeme |
| `payAndVerify(...)` | `['payment'=>Payment,'verified'=>bool,'calculated'=>string]` | Ödeme + imza doğrulama |
| `retrieve($query)` | `Payment` | `paymentId` / `paymentConversationId` ile sorgu |
| `retrieveAndVerify($query)` | aynı | Sorgu + imza doğrulama |

#### `$order` Parametreleri

| Parametre | Zorunlu | Tip | Açıklama |
|---|:---:|---|---|
| `price` | ✔ | numeric-string | Ürün toplam tutarı |
| `paidPrice` | ✖ | numeric-string | Komisyon/vergiler dahil (≥ price) |
| `currency` | ✖ | string (SDK sabiti) | Varsayılan `Currency::TL` |
| `installment` | ✖ | int | Varsayılan `1` |
| `basketId` | ✖ | string | Sipariş no |
| `paymentChannel` | ✖ | string (sabit) | `PaymentChannel::WEB` (default) |
| `paymentGroup` | ✖ | string (sabit) | `PaymentGroup::PRODUCT` (default) |
| `locale` / `conversationId` | ✖ | string | Aksi halde `Config`’ten alınır |

**Örnek**

```php
$resp = $iyz->non3ds()->payAndVerify($order, $card, $buyer, $ship, $bill, $basketItems);
if (!$resp['verified']) { /* alarm/log */ }
```

---

### 3DS Ödeme

**Servis:** `Services\Payments\ThreeDS`

#### Metotlar

| Metot | Geri Dönüş | Açıklama |
|---|---|---|
| `init($order,$card,$buyer,$ship,$bill,$items,$callbackUrl)` | `ThreedsInitialize` | 3DS başlat |
| `initAndVerify(...)` | `['init'=>ThreedsInitialize,'verified'=>bool,'calculated'=>string]` | Initialize + imza |
| `auth($paymentId,$conversationData)` | `ThreedsPayment` | 3DS tamamlama |
| `authAndVerify($paymentId,$conversationData)` | `['payment'=>ThreedsPayment,'verified'=>bool,'calculated'=>string]` | Tamamlama + imza |

> `callbackUrl` **zorunlu** (init). `paidPrice ≥ price` kuralını koruyun.

---

### Checkout Form

**Servis:** `Services\Checkout\CheckoutFormService`

#### Metotlar

| Metot | Geri Dönüş | Açıklama |
|---|---|---|
| `initialize($order,$buyer,$ship,$bill,$items,$callback)` | `CheckoutFormInitialize` | Standart CF |
| `initializePreAuth(...)` | `CheckoutFormInitializePreAuth` | Ön provizyon CF |
| `initializeAndVerify(..., $preAuth=false)` | `['init'=>..., 'verified'=>bool, 'calculated'=>string]` | Init + imza |
| `verifyInitializeSignature($init)` | `bool` | `conversationId:token` sırasıyla |
| `retrieve($token)` | `CheckoutForm` | CF sonucunu çek |
| `retrieveAndVerify($token)` | `['form'=>CheckoutForm,'verified'=>bool,'calculated'=>string]` | CF sonucu + imza |

#### `$order` Parametreleri

| Parametre | Zorunlu | Tip | Açıklama |
|---|:---:|---|---|
| `price` | ✔ | numeric-string | Temel tutar |
| `paidPrice` | ✖ | numeric-string | (≥ price) |
| `currency` | ✖ | string (sabit) | Varsayılan `Currency::TL` |
| `basketId` | ✖ | string | Sipariş no |
| `paymentGroup` | ✖ | string (sabit) | Varsayılan `PaymentGroup::PRODUCT` |
| `enabledInstallments` | ✖ | int[] | `[2,3,6,9]` gibi |
| `cardUserKey` | ✖ | string | Kayıtlı kartları göstermek için |
| `locale` / `conversationId` | ✖ | string | Aksi halde `Config` |

**Örnek**

```php
$init = $iyz->checkout()->initialize(
  $order, $buyer, $ship, $bill, $basketItems, 'https://merchant/cb'
);
$valid = $iyz->checkout()->verifyInitializeSignature($init);

$result = $iyz->checkout()->retrieve($_POST['token'] ?? '');
list('verified'=>$ok) = $iyz->checkout()->retrieveAndVerify($_POST['token'] ?? '');
```

---

## Kart Saklama (Card Storage)

**Servis:** `Services\CardStorage`

| Metot | Geri Dönüş | Açıklama |
|---|---|---|
| `createUserAndAddCard($email,$externalId,$cardInfo)` | `Card` | Yeni user + kart ekle |
| `addCard($cardUserKey,$cardInfo)` | `Card` | Var kullanıcıya kart ekle |
| `list($cardUserKey)` | `CardList` | Kayıtlı kartlar |
| `delete($cardUserKey,$cardToken)` | `Card` | Kart sil |

**$cardInfo (CardInformation)**

| Alan | Zorunlu | Tip | Örnek |
|---|:---:|---|---|
| `number` | ✔ | string | `5528790000000008` |
| `expireMonth` | ✔ | string | `"12"` |
| `expireYear` | ✔ | string | `"2030"` |
| `holderName` | ✔ | string | `"John Doe"` |
| `cardAlias` | ✖ | string | `"Main Card"` |

> **CVC yoktur** (PCI-DSS).

---

## İade / İptal

**Servis:** `Services\RefundCancel`

| Metot | Zorunlu Alanlar | Açıklama |
|---|---|---|
| `cancel($paymentId, ?$ip, ?$reason, ?$desc)` | `paymentId` | Tam iptal |
| `refund($paymentTransactionId, $price, $currency='TL', ?$ip, ?$reason, ?$desc)` | `paymentTransactionId`, `price` | Kısmi/Tam iade |

---

## Durum Sorgu

**Servis:** `Services\Status`

| Metot | Parametreler | Açıklama |
|---|---|---|
| `paymentDetail(?$paymentId=null, ?$paymentConversationId=null)` | En az birisi | Payment detayını döndürür |

---

## BIN Sorgu

**Servis:** `Services\BinService`

| Metot | Parametre | Açıklama |
|---|---|---|
| `lookup(string $bin)` | İlk 6 hane | Banka/marka/taksit bilgisi |

---

## Abonelik (Subscription)

### Ürün CRUD (SubscriptionProduct)

**Servis:** `Subscription\ProductService`

| Metot | Parametreler | Açıklama |
|---|---|---|
| `create($name, ?$description)` | `name` ✔ | Ürün oluştur |
| `retrieve($productRefCode)` | `productRefCode` ✔ | Ürün detay |
| `list($page=1,$count=20)` | ✖ | Ürün liste (sayfalı) |
| `update($productRefCode, ?$name, ?$description)` | `productRefCode` ✔ | Ürün güncelle |
| `delete($productRefCode)` | `productRefCode` ✔ | Ürün sil |

### Plan CRUD (PricingPlan)

**Servis:** `Subscription\PricingPlanService`

| Metot | Parametreler | Açıklama |
|---|---|---|
| `create($data)` | Aşağıdaki tablo | Plan oluştur |
| `retrieve($planRef)` | `planRef` ✔ | Plan detay |
| `list($productRef,$page=1,$count=20)` | `productRef` ✔ | Ürüne bağlı planları listele |
| `update($planRef,$data)` | `planRef` ✔ | Plan güncelle |
| `delete($planRef)` | `planRef` ✔ | Plan sil |

**$data (create/update) – Önemli Alanlar**

| Alan | Zorunlu | Tip | Not |
|---|:---:|---|---|
| `name` | ✔ | string | Plan adı |
| `productReferenceCode` | ✔ (create) | string | Ürün ref |
| `price` | ✔ | numeric-string | |
| `currencyCode` | ✔ | string | `TRY`, `USD`, … |
| `paymentInterval` | ✔ | string | `DAY|WEEK|MONTH|YEAR` |
| `paymentIntervalCount` | ✔ | int | |
| `trialPeriodDays` | ✖ | int | |
| `planPaymentType` | ✖ | `RECURRING|PREPAID` | |
| `recurrenceCount` | ✖ | int\|null | Süreli planlar için |

### Müşteri CRUD (Subscription Customer)

**Servis:** `Subscription\CustomerService`

| Metot | Parametreler | Açıklama |
|---|---|---|
| `create($data)` | Aşağıdaki tablo | Müşteri oluştur |
| `retrieve($customerRef)` | `customerRef` ✔ | Müşteri detay |
| `list($page=1,$count=20)` | ✖ | Müşteri liste |
| `update($customerRef,$data)` | `customerRef` ✔ | Müşteri güncelle |
| `delete($customerRef)` | `customerRef` ✔ | Müşteri sil |

**$data (create/update) – Alanlar**

| Alan | Zorunlu | Tip | Not |
|---|:---:|---|---|
| `name`, `surname`, `email` | ✔ | string | Temel bilgiler |
| `gsmNumber`, `identityNumber` | ✖ | string | |
| `shippingContactName/city/district/country/address/zipCode` | ✖ | string | |
| `billingContactName/city/district/country/address/zipCode` | ✖ | string | |

### Abonelik İşlemleri (SubscriptionService)

**Servis:** `Subscription\SubscriptionService`

| Metot | Parametreler | Açıklama |
|---|---|---|
| `createCheckoutForm($data,$customerData)` | `pricingPlanReferenceCode` ✔, `subscriptionInitialStatus?`, `callbackUrl?` | CF ile abonelik başlat |
| `retrieveCheckoutFormResult($checkoutFormToken)` | `token` ✔ | CF sonucunu çek |
| `createNon3D($data,$paymentCardData,$customerData)` | plan + kart + müşteri | API ile abonelik başlat (non3D) |
| `createWithCustomer($data)` | `pricingPlanReferenceCode` ✔, `customerReferenceCode` ✔ | Mevcut müşteri ile başlat |
| `activate($subscriptionRef)` | ✔ | Aboneliği aktifleştir |
| `retry($referenceCode)` | ✔ | Başarısız ödemeyi tekrar dene |
| `upgrade($data)` | `subscriptionReferenceCode` ✔, `newPricingPlanReferenceCode` ✔, `upgradePeriod` ✔ | Yükseltme |
| `cancel($subscriptionRef)` | ✔ | İptal |
| `details($subscriptionRef)` | ✔ | Detay |
| `search($filters)` | sayfalama + durum + tarih | Liste/arama |
| `cardUpdateCheckout($data)` | `customerReferenceCode` ✔, `callbackUrl` ✔ | Kart güncelleme checkout’u |

**Upgrade $data Alanları**

| Alan | Zorunlu | Tip | Not |
|---|:---:|---|---|
| `subscriptionReferenceCode` | ✔ | string | |
| `newPricingPlanReferenceCode` | ✔ | string | |
| `upgradePeriod` | ✔ | `NOW|NEXT_PERIOD` | |
| `useTrial` | ✖ | bool | |
| `resetRecurrenceCount` | ✖ | bool | |

### Kart Güncelleme (Hosted Form)

**Servis:** `Subscription\CardUpdateService`

| Metot | Parametreler | Açıklama |
|---|---|---|
| `create($customerRefCode,$callbackUrl)` | ✔ | Kart güncelleme formu |

### Webhook Doğrulama (Subscription)

**Servis:** `Subscription\Webhook`

| Metot | Parametreler | Açıklama |
|---|---|---|
| `verifyV3($payload,$headers)` | `X-Iyz-Signature-V3` | Abonelik event’leri için imza doğrulama |

---

## Alanlar & Zorunluluk Tabloları

### Buyer

| Alan | Zorunlu | Tip |
|---|:---:|---|
| `id`, `name`, `surname`, `email` | ✔ | string |
| `gsmNumber`, `identityNumber` | ✖ | string |
| `registrationAddress`, `city`, `country`, `zipCode` | ✔ | string |
| `ip` | ✔ | string (IPv4/IPv6) |
| `lastLoginDate`, `registrationDate` | ✖ | `Y-m-d H:i:s` |

### Address

| Alan | Zorunlu | Tip |
|---|:---:|---|
| `contactName` | ✔ | string |
| `city`, `country`, `address`, `zipCode` | ✔ | string |
| `district` | ✖ | string |

### BasketItem

| Alan | Zorunlu | Tip | Not |
|---|:---:|---|---|
| `id`, `name`, `category1` | ✔ | string | |
| `category2` | ✖ | string | |
| `itemType` | ✔ | sabit | `BasketItemType::PHYSICAL|VIRTUAL` |
| `price` | ✔ | numeric-string | |

---

## Klasör Yapısı

```text
php-iyzico/
├─ composer.json
├─ src/
│  ├─ Config.php
│  ├─ OptionsFactory.php
│  ├─ Support/
│  │  └─ Helpers.php
│  ├─ Security/
│  │  └─ Signature.php
│  ├─ Services/
│  │  ├─ Status.php                    # Ödeme/işlem durum sorguları
│  │  ├─ BinService.php                # BIN (ilk 6 hane) sorguları
│  │  ├─ RefundCancel.php              # İade (refund) & İptal (cancel)
│  │  ├─ CardStorage.php               # Kart saklama işlemleri
│  │  ├─ Checkout/
│  │  │  └─ CheckoutForm.php           # Hosted Checkout Form initialize/retrieve + verify
│  │  ├─ Payments/
│  │  │  ├─ Non3DS.php                 # Non-3D ödeme ve verify yardımcıları
│  │  │  └─ ThreeDS.php                # 3DS init/auth + verify yardımcıları
│  │  └─ Subscription/
│  │     ├─ ProductService.php         # Ürün CRUD
│  │     ├─ PricingPlanService.php     # Plan CRUD
│  │     ├─ CustomerService.php        # Müşteri CRUD
│  │     ├─ SubscriptionService.php    # Başlat/activate/upgrade/retry/cancel/details/search
│  │     ├─ CardUpdateService.php      # Kart güncelleme hosted form
│  │     └─ Webhook.php                # X-Iyz-Signature-V3 doğrulama
│  └─ Iyzico.php                       # Facade
└─ examples/
   ├─ non3ds.php
   ├─ threeds.php
   ├─ checkoutform.php
   ├─ refund_cancel.php
   ├─ status.php
   ├─ cards.php
   └─ subscription/
      ├─ subscription_product_plan.php
      ├─ subscription_checkout.php
      ├─ subscription_direct.php
      ├─ subscription_manage.php
      ├─ subscription_card_update.php
      └─ subscription_webhook.php
```

---

## Sandbox & Test Kartları

- **Sandbox:** `https://sandbox-api.iyzipay.com`  
- **Prod:** `https://api.iyzipay.com`  
- Örnek kartlar: `5528790000000008`, `5400360000000003`, `4543590000000006` (yalnızca sandbox).

---

## Güvenlik Önerileri

- **CVC saklanmaz/loglanmaz** (PCI-DSS).  
- **TLS 1.2+** kullan.  
- Her **callback/webhook**’ta **imza doğrulaması** yap.  
- **Idempotency:** `conversationId`/sipariş no ile tekrar eden istekleri engelle.  
- KVKK/GDPR & PCI yükümlülüklerine uy.

---

## Değişiklikler

- CheckoutForm: `enabledInstallments`, `cardUserKey`, preauth, init + retrieve signature verify.
- Payments: `Non3DS::retrieve(AndVerify)`, `ThreeDS::init/auth AndVerify` eklendi.
- Subscription: Ürün/Plan/Müşteri/Abonelik tüm CRUD & aksiyonlar toparlandı.
- Security: V3 Webhook ve tüm callback’ler için imza yardımcıları.

---

## Lisans

Bu yardımcılar projenize göre uyarlanabilir. Resmi `iyzico/iyzipay-php` SDK lisansı kendi deposunda geçerlidir.
