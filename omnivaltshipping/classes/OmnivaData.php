<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class OmnivaData
{
    public static function getOrderObjects(int $id_order): object
    {
        $order = new Order($id_order);
        $customer = new Customer((int) $order->id_customer);
        $address = new Address((int) $order->id_address_delivery);
        $carrier = new Carrier((int) $order->id_carrier);

        return (object) [
            'order' => $order,
            'customer' => $customer,
            'address' => $address,
            'carrier' => $carrier,
        ];
    }

    public static function getOmnivaObjects(Order $order): object
    {
        return (object) [
            'order' => new OmnivaOrder($order->id),
            'cart_terminal' => new OmnivaCartTerminal($order->id_cart),
        ];
    }

    public static function getCountryIso(Address $address): string
    {
        return strtoupper(Country::getIsoById($address->id_country));
    }

    public static function getReceiverData(Address $address, Customer $customer): object
    {
        $mobile_phone = $address->phone_mobile ?: ($address->phone ?: null);

        $name = trim($address->firstname . ' ' . $address->lastname);
        if (!empty($address->company)) {
            $name = $address->company;
        }

        $street = $address->address1;
        if (!empty($address->address2)) {
            $street .= ' - ' . $address->address2;
        }

        return (object) [
            'name' => $name,
            'country' => self::getCountryIso($address),
            'postcode' => $address->postcode,
            'city' => $address->city,
            'street' => trim($street),
            'email' => $customer->email,
            'phone' => $address->phone ?: null,
            'mobile' => $mobile_phone,
        ];
    }
}
