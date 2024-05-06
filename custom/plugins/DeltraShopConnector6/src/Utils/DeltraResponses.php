<?php declare(strict_types = 1);

namespace Deltra\ShopConnector\Utils;

use Deltra\ShopConnector\Utils\DeltraEncrypt;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Response;
use DOMDocument;

class DeltraResponses
{
    /** @var DeltraEncrypt */
    private $encrypt;

    /** @var SystemConfigService */
    private $systemConfigService;

    public function __construct(DeltraEncrypt $encrypt, SystemConfigService $systemConfigService)
    {
        $this->encrypt = $encrypt;
        $this->systemConfigService = $systemConfigService;
    }

    public function createXmlResponse(DOMDocument $dom): Response
    {
        $headers = ["Content-Type" => "text/xml"];
        return new Response($dom->saveXML(), 200, $headers);
    }

    public function createCryptedResponse(DOMDocument $dom): Response
    {
        $password = $this->systemConfigService->get("DeltraShopConnector6.config.password");

        if ($password)
        {
            $headers = ["Content-Type" => "text/plain"];
            $encryptedContent = $this->encrypt->encryptString($dom->saveXML(), $password);
            return new Response($encryptedContent, 200, $headers);
        }
        else
        {
            $headers = ["Content-Type" => "text/xml"];
            return new Response($dom->saveXML(), 200, $headers);
        }
    }

    public function createTextResponse(string $text): Response
    {
        $headers = ["Content-Type" => "text/plain"];
        return new Response($text, 200, $headers);
    }
}