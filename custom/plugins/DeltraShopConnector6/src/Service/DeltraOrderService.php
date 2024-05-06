<?php declare(strict_types = 1);

namespace Deltra\ShopConnector\Service;

use Deltra\ShopConnector\Utils\DeltraUtils;
use Deltra\ShopConnector\Utils\DeltraTax;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Content\Product\ProductEntity;
use SimpleXMLElement;
use DOMDocument;

class DeltraOrderService
{
    /** @var DeltraUtils */
    private $utils;

    /** @var DeltraTax */
    private $tax;

    /** @var EntityRepository */
    private $orderRepository;

    /** @var EntityRepository */
    private $productRepository;

    /** @var EntityRepository */
    private $stateMachineStateRepository;

    /** @var SystemConfigService */
    private $systemConfigService;

    /** @var bool */
    private $useVariants;

    const DATE_TIME_FORMAT = "Y-m-d H:i:s";
    const PROMOTION_TYPE = "promotion";
    const PROMOTION_SKU = "DISCOUNT";

    public function __construct(
        DeltraUtils $utils,
        DeltraTax $tax,
        EntityRepository $orderRepository,
        EntityRepository $productRepository,
        EntityRepository $stateMachineStateRepository,
        SystemConfigService $systemConfigService
    )
    {
        $this->utils = $utils;
        $this->tax = $tax;
        $this->orderRepository = $orderRepository;
        $this->productRepository = $productRepository;
        $this->stateMachineStateRepository = $stateMachineStateRepository;
        $this->systemConfigService = $systemConfigService;
    }

    public function getOpenOrders(): DOMDocument
    {
        $xml = new SimpleXMLElement("<?xml version=\"1.0\" encoding=\"utf-8\" standalone=\"yes\"?><OrderNotification></OrderNotification>");

        $context = Context::createDefaultContext();

        $fromStateConfig = $this->systemConfigService->get("DeltraShopConnector6.config.importstate");
        $this->useVariants = $this->systemConfigService->get("DeltraShopConnector6.config.variants");
        $salesChannels = $this->systemConfigService->get("DeltraShopConnector6.config.saleschannels");

        $criteria = new Criteria();
        $criteria->addSorting(new FieldSorting('orderNumber'));
        $criteria->addAssociation('addresses.country');
        $criteria->addAssociation('addresses.salutation');
        $criteria->addAssociation('lineItems');
        $criteria->addAssociation('transactions.paymentMethod');
        $criteria->addAssociation('deliveries.shippingMethod');
        $criteria->addAssociation('deliveries.shippingOrderAddress.country');
        $criteria->addAssociation('deliveries.shippingOrderAddress.salutation');
        $criteria->addAssociation('orderCustomer.salutation');
        $criteria->addFilter(new EqualsFilter('order.stateMachineState.technicalName', $fromStateConfig));
        if (!empty($salesChannels)) $criteria->addFilter(new EqualsAnyFilter('salesChannelId', $salesChannels));
        $orders = $this->orderRepository->search($criteria, $context)->getIterator();

        /** @var OrderEntity $order */
        foreach ($orders as $order)
        {
            $xmlOrder = $xml->addChild("Bestellvorgang");
            $this->utils->addCChild($xmlOrder, "BestellnummerShop", $order->getOrderNumber());
            $this->utils->addCChild($xmlOrder, "Bestelldatum", $order->getOrderDate()->format(self::DATE_TIME_FORMAT));
            $this->utils->addCChild($xmlOrder, "Wunschlieferdatum");

            $delivery = $order->getDeliveries()->getShippingMethods()->first();
            $payment = $order->getTransactions()->first() ? $order->getTransactions()->first()->getPaymentMethod() : null;
			$deliveryName = $delivery ? $delivery->getName() : "";
			$paymentName = $payment ? $payment->getName() : "";
            $this->utils->addCChild($xmlOrder, "Lieferart", $deliveryName);
            $this->utils->addCChild($xmlOrder, "Zahlungsart", $paymentName);
            $this->utils->addCChild($xmlOrder, "BestellwertBrutto", $order->getAmountTotal());
            $this->utils->addCChild($xmlOrder, "ZusatzfeldBestellung1");
            $this->utils->addCChild($xmlOrder, "ZusatzfeldBestellung2");
            $this->utils->addCChild($xmlOrder, "ZusatzfeldBestellung3");
            $this->utils->addCChild($xmlOrder, "ZusatzfeldBestellung4");
            $this->utils->addCChild($xmlOrder, "ZusatzfeldBestellung5");
            $this->utils->addCChild($xmlOrder, "AnmerkungenBestellung");
            
            $this->addCustomerData($xmlOrder, $order);

            $xmlExtraCosts = $xmlOrder->addChild("Zuschlagkosten");
            $this->utils->addCChild($xmlExtraCosts, "ZuschlagkostenNetto1");
            $this->utils->addCChild($xmlExtraCosts, "ZuschlagkostenBrutto1");
            $this->utils->addCChild($xmlExtraCosts, "ZuschlagkostenMwStProzent1");
            $this->utils->addCChild($xmlExtraCosts, "ZuschlagkostenNetto2");
            $this->utils->addCChild($xmlExtraCosts, "ZuschlagkostenBrutto2");
            $this->utils->addCChild($xmlExtraCosts, "ZuschlagkostenMwStProzent2");
            $this->utils->addCChild($xmlExtraCosts, "ZuschlagkostenNetto3");
            $this->utils->addCChild($xmlExtraCosts, "ZuschlagkostenBrutto3");
            $this->utils->addCChild($xmlExtraCosts, "ZuschlagkostenMwStProzent3");

            $this->addPositionsData($xmlOrder, $order);
        }

        $dom = dom_import_simplexml($xml)->ownerDocument;
        $dom->formatOutput = true;

        return $dom;
    }

    public function getOpenOrdersCount(): int
    {
        $context = Context::createDefaultContext();

        $fromStateConfig = $this->systemConfigService->get("DeltraShopConnector6.config.importstate");

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('order.stateMachineState.technicalName', $fromStateConfig));
        $orderCount = $this->orderRepository->search($criteria, $context)->getTotal();

        return $orderCount;
    }

    public function setOrderStatus(string $orderNo): string
    {
        $context = Context::createDefaultContext();
        $orderCriteria = new Criteria();
        $orderCriteria->addFilter(new EqualsFilter('orderNumber', $orderNo));

        /** @var OrderEntity $order */
        $order = $this->orderRepository->search($orderCriteria, $context)->first();

        $toStateConfig = $this->systemConfigService->get("DeltraShopConnector6.config.changestate");

        $statesCriteria = new Criteria();
        $statesCriteria->addAssociation('stateMachine');
        $statesCriteria->addFilter(
            new EqualsFilter('state_machine_state.technicalName', $toStateConfig),
            new EqualsFilter('state_machine_state.stateMachine.technicalName', 'order.state')
        );

        /** @var StateMachineStateEntity $state */
        $state = $this->stateMachineStateRepository->search($statesCriteria, $context)->first();

        $updateData = [
            ['id' => $order->getId(), 'stateId' => $state->getId()]
        ];
        $this->orderRepository->update($updateData, $context);

        return "OK";
    }

    private function addCustomerData(SimpleXMLElement $xmlOrder, OrderEntity $order): void
    {
        $xmlCustomerData = $xmlOrder->addChild("Kundendaten");

        $customer = $order->getOrderCustomer();
        $billingAddress = $order->getAddresses()->get($order->getBillingAddressId());
        $deliveryAddress = $order->getDeliveries()->getShippingAddress()->first() ?: $billingAddress;

        $xmlCustomer = $xmlCustomerData->addChild("Kunde");
        $this->utils->addCChild($xmlCustomer, "KundennummerWebshop", $customer->getCustomerNumber());
        $this->utils->addCChild($xmlCustomer, "Firmenname", $billingAddress->getCompany());
        $this->utils->addCChild($xmlCustomer, "Firmenzusatzname");
        $this->utils->addCChild($xmlCustomer, "PersonAnrede", $customer->getSalutation()->getDisplayName());
        $this->utils->addCChild($xmlCustomer, "PersonGeschlecht");
        $this->utils->addCChild($xmlCustomer, "PersonTitel", $customer->getTitle());
        $this->utils->addCChild($xmlCustomer, "PersonNachname", $customer->getLastName());
        $this->utils->addCChild($xmlCustomer, "PersonVorname", $customer->getFirstName());
        $this->utils->addCChild($xmlCustomer, "Strasse", $billingAddress->getStreet());
        $this->utils->addCChild($xmlCustomer, "Postleitzahl", $billingAddress->getZipcode());
        $this->utils->addCChild($xmlCustomer, "Ort", $billingAddress->getCity());
        $this->utils->addCChild($xmlCustomer, "Laendercode", $billingAddress->getCountry()->getIso());
        $this->utils->addCChild($xmlCustomer, "Land", $billingAddress->getCountry()->getName());
        $this->utils->addCChild($xmlCustomer, "Email", $customer->getEmail());
        $this->utils->addCChild($xmlCustomer, "Telefon", $billingAddress->getPhoneNumber());
        $this->utils->addCChild($xmlCustomer, "Umsatzsteueridentnummer");
        $this->utils->addCChild($xmlCustomer, "ZusatzfeldKunde1");
        $this->utils->addCChild($xmlCustomer, "ZusatzfeldKunde2");
        $this->utils->addCChild($xmlCustomer, "ZusatzfeldKunde3");
        $this->utils->addCChild($xmlCustomer, "ZusatzfeldKunde4");
        $this->utils->addCChild($xmlCustomer, "ZusatzfeldKunde5");

        $xmlBankAccount = $xmlCustomerData->addChild("Kontodaten");
        $this->utils->addCChild($xmlBankAccount, "BankkontoInhaber");
        $this->utils->addCChild($xmlBankAccount, "Bankkontonummer");
        $this->utils->addCChild($xmlBankAccount, "BankkontoBLZ");
        $this->utils->addCChild($xmlBankAccount, "BankkontoBIC");
        $this->utils->addCChild($xmlBankAccount, "BankkontoIBAN");
        $this->utils->addCChild($xmlBankAccount, "BankkontoBankName");

        $xmlShippingAddress = $xmlCustomerData->addChild("AbweichendLieferung");
        $this->utils->addCChild($xmlShippingAddress, "abweichendLieferungFirmenname", $deliveryAddress->getCompany());
        $this->utils->addCChild($xmlShippingAddress, "abweichendLieferungFirmenzusatz");
        $this->utils->addCChild($xmlShippingAddress, "abweichendLieferungPersAnrede", $deliveryAddress->getSalutation()->getDisplayName());
        $this->utils->addCChild($xmlShippingAddress, "abweichendLieferungPersGeschl");
        $this->utils->addCChild($xmlShippingAddress, "abweichendLieferungPersTitel", $deliveryAddress->getTitle());
        $this->utils->addCChild($xmlShippingAddress, "abweichendLieferungPersNachname", $deliveryAddress->getLastName());
        $this->utils->addCChild($xmlShippingAddress, "abweichendLieferungPersVorname", $deliveryAddress->getFirstName());
        $this->utils->addCChild($xmlShippingAddress, "abweichendLieferungStrasse", $deliveryAddress->getStreet());
        $this->utils->addCChild($xmlShippingAddress, "abweichendLieferungPostleitzahl", $deliveryAddress->getZipcode());
        $this->utils->addCChild($xmlShippingAddress, "abweichendLieferungOrt", $deliveryAddress->getCity());
        $this->utils->addCChild($xmlShippingAddress, "abweichendLieferungLaendercode", $deliveryAddress->getCountry()->getIso());
        $this->utils->addCChild($xmlShippingAddress, "abweichendLieferungLand", $deliveryAddress->getCountry()->getName());
        $this->utils->addCChild($xmlShippingAddress, "abweichendLieferungEmail", $customer->getEmail());
        $this->utils->addCChild($xmlShippingAddress, "abweichendLieferungTelefon", $deliveryAddress->getPhoneNumber());

        $xmlBillingAddress = $xmlCustomerData->addChild("AbweichendRechnung");
        $this->utils->addCChild($xmlBillingAddress, "abweichendRechnungFirmenname", $billingAddress->getCompany());
        $this->utils->addCChild($xmlBillingAddress, "abweichendRechnungFirmenzusatz");
        $this->utils->addCChild($xmlBillingAddress, "abweichendRechnungPersAnrede", $billingAddress->getSalutation()->getDisplayName());
        $this->utils->addCChild($xmlBillingAddress, "abweichendRechnungPersGeschl");
        $this->utils->addCChild($xmlBillingAddress, "abweichendRechnungPersTitel", $billingAddress->getTitle());
        $this->utils->addCChild($xmlBillingAddress, "abweichendRechnungPersNachname", $billingAddress->getLastName());
        $this->utils->addCChild($xmlBillingAddress, "abweichendRechnungPersVorname", $billingAddress->getFirstName());
        $this->utils->addCChild($xmlBillingAddress, "abweichendRechnungStrasse", $billingAddress->getStreet());
        $this->utils->addCChild($xmlBillingAddress, "abweichendRechnungPostleitzahl", $billingAddress->getZipcode());
        $this->utils->addCChild($xmlBillingAddress, "abweichendRechnungOrt", $billingAddress->getCity());
        $this->utils->addCChild($xmlBillingAddress, "abweichendRechnungLaendercode", $deliveryAddress->getCountry()->getIso());
        $this->utils->addCChild($xmlBillingAddress, "abweichendRechnungLand", $billingAddress->getCountry()->getName());
        $this->utils->addCChild($xmlBillingAddress, "abweichendRechnungEmail", $customer->getEmail());
        $this->utils->addCChild($xmlBillingAddress, "abweichendRechnungTelefon", $billingAddress->getPhoneNumber());

        // Shipping Costs
        $shippingCosts = $order->getShippingCosts();

        $shippingCostNet = null;
        $shippingCostGross = $shippingCosts->getTotalPrice();
        if ($order->getTaxStatus() === "net") {
            $shippingCostNet = $shippingCosts->getUnitPrice();
            $shippingCostGross = null;
        }

        $xmlShippingCosts = $xmlCustomerData->addChild("Frachtkosten");
        $this->utils->addCChild($xmlShippingCosts, "FrachtkostenNetto", $shippingCostNet);
        $this->utils->addCChild($xmlShippingCosts, "FrachtkostenBrutto", $shippingCostGross);
        $this->utils->addCChild($xmlShippingCosts, "FrachtkostenMwStProzent");
    }

    private function addPositionsData(SimpleXMLElement $xmlOrder, OrderEntity $order): void
    {
        $orderPositions = $order->getLineItems()->getIterator();

        $context = Context::createDefaultContext();

        $getProduct = function(OrderLineItemEntity $position) use (&$context)
        {
            if ($position->getType() !== LineItem::PRODUCT_LINE_ITEM_TYPE) return null;

            $productCriteria = new Criteria();
            $productCriteria->addAssociation('options');
            $productCriteria->addFilter(new EqualsFilter('id', $position->getReferencedId()));
                        
            return $this->productRepository->search($productCriteria, $context)->first();
        };

        $getName = function(string $name, ?ProductEntity $product)
        {
            if (!$product) return $name;
            $options = $product->getOptions();

            foreach ($options->getIterator() as $option)
            {
                $name .= ' - ' . $option->getName();
            }

            return $name;
        };

        $getSku = function(OrderLineItemEntity $position, ?ProductEntity $product) use (&$context)
        {
            $payload = $position->getPayload();
            if ($position->getType() == self::PROMOTION_TYPE) return $payload['code'] ?? self::PROMOTION_SKU;
			
			$hasPayload = false;
			$productNumber = "NOT_FOUND";
			if ($payload && array_key_exists('productNumber', $payload))
			{
				$hasPayload = true;
				$productNumber = $payload['productNumber'];
			}
			
			if ($hasPayload && $this->useVariants) return $productNumber;
			if (!$product) return $productNumber;
			if (!$product->getParentId()) return $productNumber;
            $parentCriteria = new Criteria();
            $parentCriteria->addFilter(new EqualsFilter('id', $product->getParentId()));

            /** @var ProductEntity $parent */
            $parent = $this->productRepository->search($parentCriteria, $context)->first();
            if (!$parent) return $productNumber;
            return $parent->getProductNumber();
        };

        $getTax = function(OrderLineItemEntity $position, ?ProductEntity $product) use (&$context, &$order)
        {
            if ($order->getTaxStatus() === "tax-free") return 0;
            $positionTax = $position->getPrice()->getCalculatedTaxes();
            if ($positionTax && $positionTax->first()) return $positionTax->first()->getTaxRate();
            if ($product) return $this->tax->getTaxRate($product, $context);
            return 0;
        };

        $campaignPositions= [];
        $sortNum = 1;
        foreach ($orderPositions as $position) {
            /** @var ProductEntity $product */
            $product = $getProduct($position);
            $netPrice = null;
            $grossPrice = $position->getUnitPrice();
            $tax = $getTax($position, $product);
            if ($grossPrice < 0) {
                $campaignPositions[] = $position;
                continue;
            }

            if ($order->getTaxStatus() === "net")
            {
                $netPrice = $grossPrice;
                $grossPrice = null;
            }
            
            $xmlPosition = $xmlOrder->addChild("BestellArtikel");
            $this->utils->addCChild($xmlPosition, "Positionsnummer", $sortNum);
            $this->utils->addCChild($xmlPosition, "Artikelnummer");
            $this->utils->addCChild($xmlPosition, "ArtikelnummerShop", $getSku($position, $product));
            $this->utils->addCChild($xmlPosition, "Menge", $position->getQuantity());
            $this->utils->addCChild($xmlPosition, "abweichenderEinzelpreisNetto", $netPrice);
            $this->utils->addCChild($xmlPosition, "abweichenderEinzelpreisBrutto", $grossPrice);
            $this->utils->addCChild($xmlPosition, "abweichendeMwStProzent", $tax, true);
            $this->utils->addCChild($xmlPosition, "abweichenderArtikeltext", $getName($position->getLabel(), $product));
            $this->utils->addCChild($xmlPosition, "RabattProzent");
            $this->utils->addCChild($xmlPosition, "ZusatzfeldPosition1");
            $this->utils->addCChild($xmlPosition, "ZusatzfeldPosition2");
            $this->utils->addCChild($xmlPosition, "ZusatzfeldPosition3");
            $this->utils->addCChild($xmlPosition, "ZusatzfeldPosition4");
            $this->utils->addCChild($xmlPosition, "ZusatzfeldPosition5");

            $sortNum++;
        }

        foreach ($campaignPositions as $campaign) {
            /** @var ProductEntity $product */
            $product = $getProduct($campaign);

            foreach ($campaign->getPrice()->getCalculatedTaxes() as $taxItem) {
                $netPrice = null;
                $grossPrice = $taxItem->getPrice();

                if ($order->getTaxStatus() === "tax-free") {
                    $tax = 0;
                } else {
                    $tax = ($taxItem)->getTaxRate();
                }

                if ($order->getTaxStatus() === "net")
                {
                    $netPrice = $grossPrice;
                    $grossPrice = null;
                }
                
                $xmlPosition = $xmlOrder->addChild("BestellArtikel");
                $this->utils->addCChild($xmlPosition, "Positionsnummer", $sortNum);
                $this->utils->addCChild($xmlPosition, "Artikelnummer");
                $this->utils->addCChild($xmlPosition, "ArtikelnummerShop", $getSku($campaign, $product));
                $this->utils->addCChild($xmlPosition, "Menge", $campaign->getQuantity());
                $this->utils->addCChild($xmlPosition, "abweichenderEinzelpreisNetto", $netPrice);
                $this->utils->addCChild($xmlPosition, "abweichenderEinzelpreisBrutto", $grossPrice);
                $this->utils->addCChild($xmlPosition, "abweichendeMwStProzent", $tax, true);
                $this->utils->addCChild($xmlPosition, "abweichenderArtikeltext", $getName($campaign->getLabel(), $product) . " (" . strval($tax) . "% MwSt.)");
                $this->utils->addCChild($xmlPosition, "RabattProzent");
                $this->utils->addCChild($xmlPosition, "ZusatzfeldPosition1");
                $this->utils->addCChild($xmlPosition, "ZusatzfeldPosition2");
                $this->utils->addCChild($xmlPosition, "ZusatzfeldPosition3");
                $this->utils->addCChild($xmlPosition, "ZusatzfeldPosition4");
                $this->utils->addCChild($xmlPosition, "ZusatzfeldPosition5");

                $sortNum++;
            }
        }
    }
}