<?php declare(strict_types = 1);

namespace Deltra\ShopConnector\Utils;

use SimpleXMLElement;

class DeltraUtils
{
    public function addCChild(SimpleXMLElement $xml, string $name, $value = null, $forceValue = false): void
    {
        $new_child = $xml->addChild($name);
        if ($new_child === null) return;
        if (!empty($value) || $forceValue)
        {
            $node = dom_import_simplexml($new_child);
            $no = $node->ownerDocument;
            $node->appendChild($no->createCDATASection(strval($value)));
        }
    }

    public function getXmlString(SimpleXMLElement $xmlElement, string $key) : ?string
    {
        $xmlValue = $xmlElement->xpath($key)[0];
        if (!$xmlValue) return null;

        return strval($xmlValue);
    }

    public function getXmlFloat(SimpleXMLElement $xmlElement, string $key) : ?float
    {
        $xmlValue = $xmlElement->xpath($key)[0];
        if (!$xmlValue) return null;

        $xmlValue = str_replace(',', '.', strval($xmlValue));
        return floatval($xmlValue);
    }

    public function getXmlInt(SimpleXMLElement $xmlElement, string $key) : ?int
    {
        $xmlValue = $xmlElement->xpath($key)[0];
        if (!$xmlValue) return null;

        return intval($xmlValue);
    }
}