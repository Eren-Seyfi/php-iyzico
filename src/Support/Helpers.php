<?php
declare(strict_types=1);

namespace Eren5\PhpIyzico\Support;

use Iyzipay\Model\Buyer;
use Iyzipay\Model\Address;
use Iyzipay\Model\BasketItem;

final class Helpers
{
    /** @param array<string,mixed> $a */
    public static function buyer(array $a): Buyer
    {
        $b = new Buyer();
        isset($a['id']) && $b->setId($a['id']);
        isset($a['name']) && $b->setName($a['name']);
        isset($a['surname']) && $b->setSurname($a['surname']);
        isset($a['gsmNumber']) && $b->setGsmNumber($a['gsmNumber']);
        isset($a['email']) && $b->setEmail($a['email']);
        isset($a['identityNumber']) && $b->setIdentityNumber($a['identityNumber']);
        isset($a['lastLoginDate']) && $b->setLastLoginDate($a['lastLoginDate']);
        isset($a['registrationDate']) && $b->setRegistrationDate($a['registrationDate']);
        isset($a['registrationAddress']) && $b->setRegistrationAddress($a['registrationAddress']);
        isset($a['ip']) && $b->setIp($a['ip']);
        isset($a['city']) && $b->setCity($a['city']);
        isset($a['country']) && $b->setCountry($a['country']);
        isset($a['zipCode']) && $b->setZipCode($a['zipCode']);
        return $b;
    }

    /** @param array<string,mixed> $a */
    public static function address(array $a): Address
    {
        $addr = new Address();
        isset($a['contactName']) && $addr->setContactName($a['contactName']);
        isset($a['city']) && $addr->setCity($a['city']);
        isset($a['country']) && $addr->setCountry($a['country']);
        isset($a['address']) && $addr->setAddress($a['address']);
        isset($a['zipCode']) && $addr->setZipCode($a['zipCode']);
        return $addr;
    }

    /** @param array<string,mixed> $a */
    public static function basketItem(array $a): BasketItem
    {
        $bi = new BasketItem();
        isset($a['id']) && $bi->setId($a['id']);
        isset($a['name']) && $bi->setName($a['name']);
        isset($a['category1']) && $bi->setCategory1($a['category1']);
        isset($a['category2']) && $bi->setCategory2($a['category2']);
        isset($a['itemType']) && $bi->setItemType($a['itemType']);
        isset($a['price']) && $bi->setPrice((string) $a['price']);
        return $bi;
    }
}
