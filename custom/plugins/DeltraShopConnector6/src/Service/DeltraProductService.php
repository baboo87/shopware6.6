<?php declare(strict_types = 1);

namespace Deltra\ShopConnector\Service;

use Deltra\ShopConnector\Utils\DeltraUtils;
use Deltra\ShopConnector\Utils\DeltraTax;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Pricing\Price;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\System\Unit\UnitEntity;
use Shopware\Core\System\Tax\TaxEntity;
use Shopware\Core\Content\Product\ProductEntity;
use SimpleXMLElement;
use DOMDocument;

class DeltraProductService
{
    /** @var DeltraUtils */
    private $utils;

    /** @var DeltraTax */
    private $tax;

    /** @var EntityRepository */
    private $productRepository;

    /** @var EntityRepository */
    private $taxRepository;

    /** @var EntityRepository */
    private $manufacturerRepository;

    /** @var SystemConfigService */
    private $systemConfigService;

    const DELTRA_MANUFACTURER_NAME = "Deltra Upload";
    const DELTRA_DEFAULT_TAXRATE = 19;

    public function __construct(
        DeltraUtils $utils,
        DeltraTax $tax,
        EntityRepository $productRepository,
        EntityRepository $taxRepository,
        EntityRepository $manufacturerRepository,
        SystemConfigService $systemConfigService)
    {
        $this->utils = $utils;
        $this->tax = $tax;
        $this->productRepository = $productRepository;
        $this->taxRepository = $taxRepository;
        $this->manufacturerRepository = $manufacturerRepository;
        $this->systemConfigService = $systemConfigService;
    }

    public function getArticles(?int $limit, ?int $offset): DOMDocument
    {
        $xml = new SimpleXMLElement("<?xml version=\"1.0\" encoding=\"utf-8\" standalone=\"yes\"?><Artikelimport></Artikelimport>");

        $context = Context::createDefaultContext();

        $useVariants = $this->systemConfigService->get("DeltraShopConnector6.config.variants");

        $criteria = new Criteria();
        $criteria->addSorting(new FieldSorting('productNumber'));
        $criteria->addFilter(new EqualsFilter('parentId', null));
        if ($useVariants) $criteria->addAssociation('children.options');

        $criteria->setLimit($limit);
        $criteria->setOffset($offset);

        $products = $this->productRepository->search($criteria, $context)->getIterator();

        $writeXmlRow = function(ProductEntity $product, ?ProductEntity $parent) use (&$context, &$xml)
        {
            $get = function(string $name, ...$parameter) use (&$product, &$parent)
            {
                $funcName = 'get'.$name;
                $productResult = call_user_func([$product, $funcName], ...$parameter);
                if (!is_null($productResult)) return $productResult;
                if (!$parent) return null;
    
                return call_user_func([$parent, $funcName], ...$parameter);
            };

            $getName = function() use (&$product, &$parent)
            {
                $productName = $product->getName();
                if ($productName) return $productName;
                if (!$parent) return null;

                $combinedName = $parent->getName();
                foreach ($product->getOptions()->getIterator() as $option)
                {
                    $combinedName .= ' - ' . $option->getName();
                }

                return $combinedName;
            };

            $getPurchasePrice = function() use (&$get)
            {
                try {
                    return $get('PurchasePrice');
                } catch (\Throwable $th) {
                    $prices = $get('PurchasePrices');
                    if (!$prices) return 0.0;
                    $price = $prices->first();
                    if (!$price) return 0.0;
                    
                    return $price->getNet();
                }
            };
    
            $xmlRow = $xml->addChild("row");
            $this->utils->addCChild($xmlRow, "Artikelnummer");
            $this->utils->addCChild($xmlRow, "ArtikelnummerWebshop", $product->getProductNumber());
            $this->utils->addCChild($xmlRow, "Artikelbeschreibung", $getName());
            $this->utils->addCChild($xmlRow, "MwStCode");
    
            /** @var TaxEntity $tax */
            $tax = $get('Tax');
            $this->utils->addCChild($xmlRow, "MwStValue", $tax->getTaxRate(), true);
    
            /** @var UnitEntity $unit */
            $unit = $get('Unit');
            $this->utils->addCChild($xmlRow, "Einheit", ($unit ? $unit->getName() : null));
            $this->utils->addCChild($xmlRow, "Artikelkategorie");
            $this->utils->addCChild($xmlRow, "Gewicht", $get('Weight'));
            $this->utils->addCChild($xmlRow, "Volumen");
            $this->utils->addCChild($xmlRow, "Anmerkungen", $get('Description'));
    
            /** @var Price $price */
            $price = $get('CurrencyPrice', $context->getCurrencyId());
            $this->utils->addCChild($xmlRow, "ArtikelpreisNetto", round($price->getNet(), 2));
            $this->utils->addCChild($xmlRow, "ArtikelpreisBrutto", round($price->getGross(), 2));
            $this->utils->addCChild($xmlRow, "Einkaufspreis", round($getPurchasePrice(), 2));
            $this->utils->addCChild($xmlRow, "Artikelbild");
            
            $xmlFields = $xmlRow->addChild("IndividuelleFelder");
            $this->utils->addCChild($xmlFields, "IndividuellesFeld1");
            $this->utils->addCChild($xmlFields, "IndividuellesFeld2");
            $this->utils->addCChild($xmlFields, "IndividuellesFeld3");
            $this->utils->addCChild($xmlFields, "IndividuellesFeld4");
            $this->utils->addCChild($xmlFields, "IndividuellesFeld5");
        };

        $childSort = function(ProductEntity $e1, ProductEntity $e2)
        {
            $e1Name = $e1->getProductNumber();
            $e2Name = $e2->getProductNumber();
            if ($e1Name == $e2Name) return 0;
            return ($e1Name < $e2Name) ? -1 : 1;
        };

        /** @var ProductEntity $product */
        foreach ($products as $product)
        {
            $writeXmlRow($product, null);

            $children = $product->getChildren();
            if (!$children || ($children->count() == 0)) continue;
            
            $children->sort($childSort);
            foreach ($children->getIterator() as $child) {
                $writeXmlRow($child, $product);
            }
        }

        $dom = dom_import_simplexml($xml)->ownerDocument;
        $dom->formatOutput = false;

        return $dom;
    }

    public function getArticleList(): DOMDocument
    {
        $xml = new SimpleXMLElement("<?xml version=\"1.0\" encoding=\"utf-8\" standalone=\"yes\"?><ArtikelListeWebshop></ArtikelListeWebshop>");

        $context = Context::createDefaultContext();

        $useVariants = $this->systemConfigService->get("DeltraShopConnector6.config.variants");

        $criteria = new Criteria();
        $criteria->addSorting(new FieldSorting('productNumber'));
        if (!$useVariants) $criteria->addFilter(new EqualsFilter('parentId', null));

        $products = $this->productRepository->search($criteria, $context)->getIterator();

        /** @var ProductEntity $product */
        foreach ($products as $product)
        {
            $xmlRow = $xml->addChild("row");
            $this->utils->addCChild($xmlRow, "ArtikelnummerWebshop", $product->getProductNumber());
            $this->utils->addCChild($xmlRow, "Artikelbeschreibung", $product->getName());
        }

        $dom = dom_import_simplexml($xml)->ownerDocument;
        $dom->formatOutput = true;

        return $dom;
    }

    public function getArticlesCount(): int
    {
        $context = Context::createDefaultContext();

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('parentId', null));

        $productCount = $this->productRepository->search($criteria, $context)->getTotal();

        return $productCount;
    }
    
    public function setArticles(SimpleXMLElement $xml): DOMDocument
    {
        $xmlProducts = $xml->xpath("row");

        $context = Context::createDefaultContext();

        $productCount = 0;
        $successCount = 0;

        foreach ($xmlProducts as $xmlProduct)
        {
            $productCount++;

            $sku = $this->utils->getXmlString($xmlProduct, "ArtikelnummerWebshop");
            $name = $this->utils->getXmlString($xmlProduct, "Artikelbeschreibung");
            $description = $this->utils->getXmlString($xmlProduct, "Anmerkungen");
            $weight = $this->utils->getXmlFloat($xmlProduct, "Gewicht");
            $taxRate = $this->utils->getXmlFloat($xmlProduct, "MwStValue");

            $priceNet = $this->utils->getXmlFloat($xmlProduct, "ArtikelpreisNetto");
            $priceGross = $this->utils->getXmlString($xmlProduct, "ArtikelpreisBrutto");
            $purchasePrice = $this->utils->getXmlFloat($xmlProduct, "Einkaufspreis");

            if ($priceGross === 'DSC_IGNORE')
            {
                $priceGross = $priceNet + ($priceNet * ($taxRate / 100));
            }
            else
            {
                $priceGross = floatval($priceGross);
            }

            $articleData = [
                'active' => false,
                'productNumber' => $sku,
                'name' => mb_substr($name, 0, 255),
                'description' => $description,
                'weight' => $weight,
                'stock' => 0,
                'price' => [['gross' => $priceGross, 'net' => $priceNet, 'currencyId' => $context->getCurrencyId(), 'linked' => true]],
                'purchasePrice' => $purchasePrice
            ];

            // Set or Create TaxEntity
            $taxCriteria = new Criteria();
            $taxCriteria->addFilter(new EqualsFilter('taxRate', $taxRate));
            $taxId = $this->taxRepository->searchIds($taxCriteria, $context)->firstId();
            $articleData['tax'] = $taxId ? ['id' => $taxId] : ['name' => $taxRate.'%', 'taxRate' => $taxRate];

            // Set or Create ManufacturerEntity
            $manufacturerCriteria = new Criteria();
            $manufacturerCriteria->addFilter(new EqualsFilter('name', self::DELTRA_MANUFACTURER_NAME));
            $manufacturerId = $this->manufacturerRepository->searchIds($manufacturerCriteria, $context)->firstId();
            $articleData['manufacturer'] = $manufacturerId ? ['id' => $manufacturerId] : ['name' => self::DELTRA_MANUFACTURER_NAME];

            try {
                $this->productRepository->upsert([$articleData], $context);
                $successCount++;
            } catch (\Throwable $th) {   
            }
        }

        $xmlProtocol = new SimpleXMLElement("<?xml version=\"1.0\" encoding=\"utf-8\"?><Exportprotokoll></Exportprotokoll>");
        $this->utils->addCChild($xmlProtocol, "Export_Status", ($successCount === $productCount) ? "SUCCESS" : "ERROR");
        $this->utils->addCChild($xmlProtocol, "Anzahl_Datensaetze_Gesamt", $productCount);
        $this->utils->addCChild($xmlProtocol, "Anzahl_Datensaetze_Erfolgreich_Uebergeben", $successCount);

        $dom = dom_import_simplexml($xmlProtocol)->ownerDocument;
        $dom->formatOutput = true;

        return $dom;
    }

    public function setArticlePrices(SimpleXMLElement $xml): DOMDocument
    {
        $xmlProducts = $xml->xpath("row");

        $context = Context::createDefaultContext();

        $productCount = 0;
        $successCount = 0;

        foreach ($xmlProducts as $xmlProduct)
        {
            $productCount++;

            $sku = $this->utils->getXmlString($xmlProduct, "ArtikelnummerWebshop");
            $priceNet = $this->utils->getXmlFloat($xmlProduct, "ArtikelpreisNetto");
            $priceGross = $this->utils->getXmlString($xmlProduct, "ArtikelpreisBrutto");
            $purchasePrice = $this->utils->getXmlFloat($xmlProduct, "Einkaufspreis");

            $productCriteria = new Criteria();
            $productCriteria->addFilter(new EqualsFilter('productNumber', $sku));

            /** @var ProductEntity $product */
            $product = $this->productRepository->search($productCriteria, $context)->first();
            if (!$product) continue;
            
            $oldListPriceGross = null;
            $oldListPriceNet = null;
            if ($product->getPrice()) {
                foreach ($product->getPrice() as $price) {
                    if ($price->getCurrencyId() !== $context->getCurrencyId()) {
                        continue;
                    }
                    $listPrice = $price->getListPrice();
                    if ($listPrice) {
                        $oldListPriceGross = $listPrice->getGross();
                        $oldListPriceNet = $listPrice->getNet();
                    };
                }
            }

            if ($priceGross === 'DSC_IGNORE')
            {
                $taxRate = $this->tax->getTaxRate($product, $context);
                $priceGross = $priceNet + ($priceNet * ($taxRate / 100));
            }
            else
            {
                $priceGross = floatval($priceGross);
            }

            $articleData = [
                'id' => $product->getId(),
                'price' => [['gross' => $priceGross, 
                    'net' => $priceNet, 
                    'currencyId' => $context->getCurrencyId(), 
                    'linked' => true, 
                    "listPrice" => 
                    ($oldListPriceGross && $oldListPriceNet) ? [
                        'currencyId' => $context->getCurrencyId(), 
                        'gross' => $oldListPriceGross, 
                        'linked' => true, 
                        'net' => $oldListPriceNet
                    ] : null
                ]],
                'purchasePrice' => $purchasePrice
            ];

            try {
                $this->productRepository->upsert([$articleData], $context);
                $successCount++;
            } catch (\Throwable $th) {
            }
        }

        $xmlProtocol = new SimpleXMLElement("<?xml version=\"1.0\" encoding=\"utf-8\"?><Exportprotokoll></Exportprotokoll>");
        $this->utils->addCChild($xmlProtocol, "Export_Status", ($successCount === $productCount) ? "SUCCESS" : "ERROR");
        $this->utils->addCChild($xmlProtocol, "Anzahl_Datensaetze_Gesamt", $productCount);
        $this->utils->addCChild($xmlProtocol, "Anzahl_Datensaetze_Erfolgreich_Uebergeben", $successCount);

        $dom = dom_import_simplexml($xmlProtocol)->ownerDocument;
        $dom->formatOutput = true;

        return $dom;
    }

    public function setStock(SimpleXMLElement $xml): DOMDocument
    {
        $xmlProducts = $xml->xpath("Artikel");

        $context = Context::createDefaultContext();

        $productCount = 0;
        $successCount = 0;

        foreach ($xmlProducts as $xmlProduct)
        {
            $productCount++;

            $sku = $this->utils->getXmlString($xmlProduct, "ArtikelnummerWebshop");
            $stock = $this->utils->getXmlInt($xmlProduct, "LagerBestandAktuell");
            $restockTime = $this->utils->getXmlInt($xmlProduct, "LagerWiederbeschaffungsdauer");

            $productCriteria = new Criteria();
            $productCriteria->addFilter(new EqualsFilter('productNumber', $sku));

            /** @var ProductEntity $product */
            $product = $this->productRepository->search($productCriteria, $context)->first();
            if (!$product) continue;

            $articleData = [
                'id' => $product->getId(),
                'stock' => $stock,
                'restockTime' => $restockTime
            ];

            try {
                $this->productRepository->update([$articleData], $context);
                $successCount++;
            } catch (\Throwable $th) {
            }
        }

        $xmlProtocol = new SimpleXMLElement("<?xml version=\"1.0\" encoding=\"utf-8\"?><Exportprotokoll></Exportprotokoll>");
        $this->utils->addCChild($xmlProtocol, "Export_Status", ($successCount === $productCount) ? "SUCCESS" : "ERROR");
        $this->utils->addCChild($xmlProtocol, "Anzahl_Datensaetze_Gesamt", $productCount);
        $this->utils->addCChild($xmlProtocol, "Anzahl_Datensaetze_Erfolgreich_Uebergeben", $successCount);

        $dom = dom_import_simplexml($xmlProtocol)->ownerDocument;
        $dom->formatOutput = true;

        return $dom;
    }

    public function getPluginConfiguration(): DOMDocument
    {
        $xml = new SimpleXMLElement("<?xml version=\"1.0\" encoding=\"utf-8\" standalone=\"yes\"?><PluginConfiguration></PluginConfiguration>");

        $batchSize = $this->systemConfigService->get("DeltraShopConnector6.config.batchsize");
        $batchSize = $batchSize ?? 0;
        $xml->addChild("ArticlesImportLimit", strval($batchSize));

        $dom = dom_import_simplexml($xml)->ownerDocument;
        $dom->formatOutput = true;

        return $dom;       
    }
}