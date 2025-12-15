<?php
declare(strict_types=1);

namespace Eren\PhpIyzico\Support;

use Iyzipay\Model\Buyer;
use Iyzipay\Model\Address;
use Iyzipay\Model\BasketItem;

final class Helpers
{
    /** @param array<string,mixed> $buyerData */
    public static function buyer(array $buyerData): Buyer
    {
        $buyerModel = new Buyer();

        isset($buyerData['id']) && $buyerModel->setId($buyerData['id']);
        isset($buyerData['name']) && $buyerModel->setName($buyerData['name']);
        isset($buyerData['surname']) && $buyerModel->setSurname($buyerData['surname']);
        isset($buyerData['gsmNumber']) && $buyerModel->setGsmNumber($buyerData['gsmNumber']);
        isset($buyerData['email']) && $buyerModel->setEmail($buyerData['email']);
        isset($buyerData['identityNumber']) && $buyerModel->setIdentityNumber($buyerData['identityNumber']);
        isset($buyerData['lastLoginDate']) && $buyerModel->setLastLoginDate($buyerData['lastLoginDate']);
        isset($buyerData['registrationDate']) && $buyerModel->setRegistrationDate($buyerData['registrationDate']);
        isset($buyerData['registrationAddress']) && $buyerModel->setRegistrationAddress($buyerData['registrationAddress']);
        isset($buyerData['ip']) && $buyerModel->setIp($buyerData['ip']);
        isset($buyerData['city']) && $buyerModel->setCity($buyerData['city']);
        isset($buyerData['country']) && $buyerModel->setCountry($buyerData['country']);
        isset($buyerData['zipCode']) && $buyerModel->setZipCode($buyerData['zipCode']);

        return $buyerModel;
    }

    /** @param array<string,mixed> $addressData */
    public static function address(array $addressData): Address
    {
        $addressModel = new Address();

        isset($addressData['contactName']) && $addressModel->setContactName($addressData['contactName']);
        isset($addressData['city']) && $addressModel->setCity($addressData['city']);
        isset($addressData['country']) && $addressModel->setCountry($addressData['country']);
        isset($addressData['address']) && $addressModel->setAddress($addressData['address']);
        isset($addressData['zipCode']) && $addressModel->setZipCode($addressData['zipCode']);

        return $addressModel;
    }

    /** @param array<string,mixed> $basketItemData */
    public static function basketItem(array $basketItemData): BasketItem
    {
        $basketItemModel = new BasketItem();

        isset($basketItemData['id']) && $basketItemModel->setId($basketItemData['id']);
        isset($basketItemData['name']) && $basketItemModel->setName($basketItemData['name']);
        isset($basketItemData['category1']) && $basketItemModel->setCategory1($basketItemData['category1']);
        isset($basketItemData['category2']) && $basketItemModel->setCategory2($basketItemData['category2']);
        isset($basketItemData['itemType']) && $basketItemModel->setItemType($basketItemData['itemType']);
        isset($basketItemData['price']) && $basketItemModel->setPrice((string) $basketItemData['price']);

        return $basketItemModel;
    }
}
