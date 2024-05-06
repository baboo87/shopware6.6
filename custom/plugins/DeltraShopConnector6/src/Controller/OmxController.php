<?php declare(strict_types=1);

namespace Deltra\ShopConnector\Controller;

use Deltra\ShopConnector\Interfaces\DeltraController;
use Deltra\ShopConnector\Utils\DeltraResponses;
use Deltra\ShopConnector\Service\DeltraProductService;
use Deltra\ShopConnector\Service\DeltraOrderService;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use SimpleXMLElement;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class OmxController extends StorefrontController implements DeltraController
{
    /** @var DeltraResponses */
    private $deltraResponses;

    /** @var DeltraProductService */
    private $deltraProductService;

    /** @var DeltraOrderService */
    private $deltraOrderService;

    public function __construct(DeltraResponses $deltraResponses, DeltraProductService $deltraProductService, DeltraOrderService $deltraOrderService)
    {
        $this->deltraResponses = $deltraResponses;
        $this->deltraProductService = $deltraProductService;
        $this->deltraOrderService = $deltraOrderService;
    }

    #[Route(
        path: '/DeltraShopConnector',
        name: 'deltra.shopconnector.index',
        methods: ['GET']
    )]
    public function indexAction(): Response
    {
        return $this->deltraResponses->createTextResponse("DeltraShopConnector - Shopware 6.x");
    }

    #[Route(
        path: '/DeltraShopConnector/getConnection',
        name: 'deltra.shopconnector.get_connection',
        methods: ['GET']
    )]
    public function getConnectionAction(): Response
    {
        return $this->deltraResponses->createTextResponse("1");
    }

    #[Route(
        path: '/DeltraShopConnector/getOpenOrders',
        name: 'deltra.shopconnector.get_open_orders',
        methods: ['GET']
    )]
    public function getOpenOrdersAction(): Response
    {
        $ordersXml = $this->deltraOrderService->getOpenOrders();
        return $this->deltraResponses->createCryptedResponse($ordersXml);
    }

    #[Route(
        path: '/DeltraShopConnector/getOpenOrdersCount',
        name: 'deltra.shopconnector.get_open_orders_count',
        methods: ['GET']
    )]
    public function getOpenOrdersCountAction(): Response
    {
        $ordersCount = $this->deltraOrderService->getOpenOrdersCount();
        return $this->deltraResponses->createTextResponse(strval($ordersCount));
    }

    #[Route(
        path: '/DeltraShopConnector/setOrderStatus/id/{orderNo}',
        name: 'deltra.shopconnector.set_order_status',
        methods: ['GET']
    )]
    public function setOrderStatusAction(Request $request): Response
    {
        $orderNumber = $request->attributes->get('orderNo');
        $setStatusResult = $this->deltraOrderService->setOrderStatus($orderNumber);
        return $this->deltraResponses->createTextResponse($setStatusResult);
    }

    #[Route(
        path: '/DeltraShopConnector/getArticles',
        name: 'deltra.shopconnector.get_articles',
        methods: ['GET']
    )]
    public function getArticlesAction(Request $request): Response
    {
        $getParam = function(string $key) use (&$request)
        {
            $var = $request->query->getInt($key);
            if ($var === 0) return null;
            return $var;
        };
        $limit = $getParam('limit');
        $offset = $getParam('offset');
        $articlesXml = $this->deltraProductService->getArticles($limit, $offset);
        return $this->deltraResponses->createCryptedResponse($articlesXml);
    }

    #[Route(
        path: '/DeltraShopConnector/getArticleList',
        name: 'deltra.shopconnector.get_article_list',
        methods: ['GET']
    )]
    public function getArticleListAction(): Response
    {
        $articlesXml = $this->deltraProductService->getArticleList();
        return $this->deltraResponses->createCryptedResponse($articlesXml);
    }

    #[Route(
        path: '/DeltraShopConnector/getArticlesCount',
        name: 'deltra.shopconnector.get_article_count',
        methods: ['GET']
    )]
    public function getArticlesCountAction(): Response
    {
        $articleCount = $this->deltraProductService->getArticlesCount();
        return $this->deltraResponses->createTextResponse(strval($articleCount));
    }

    #[Route(
        path: '/DeltraShopConnector/getPluginConfiguration',
        name: 'deltra.shopconnector.get_plugin_configuration',
        methods: ['GET']
    )]
    public function getPluginConfigurationAction(): Response
    {
        $pluginXml = $this->deltraProductService->getPluginConfiguration();
        return $this->deltraResponses->createXmlResponse($pluginXml);
    }

    #[Route(
        path: '/DeltraShopConnector/setArticles',
        name: 'deltra.shopconnector.set_articles',
        defaults: ['csrf_protected' => false],
        methods: ['POST']
    )]
    public function setArticlesAction(Request $request): Response
    {
        $xmlContent = new SimpleXMLElement($request->getContent());
        $protocolXml = $this->deltraProductService->setArticles($xmlContent);
        return $this->deltraResponses->createXmlResponse($protocolXml);
    }

    #[Route(
        path: '/DeltraShopConnector/setArticlePrices',
        name: 'deltra.shopconnector.set_article_prices',
        defaults: ['csrf_protected' => false],
        methods: ['POST']
    )]
    public function setArticlePricesAction(Request $request): Response
    {
        $xmlContent = new SimpleXMLElement($request->getContent());
        $protocolXml = $this->deltraProductService->setArticlePrices($xmlContent);
        return $this->deltraResponses->createXmlResponse($protocolXml);
    }

    #[Route(
        path: '/DeltraShopConnector/setStock',
        name: 'deltra.shopconnector.set_stock',
        defaults: ['csrf_protected' => false],
        methods: ['POST']
    )]
    public function setStockAction(Request $request): Response
    {
        $xmlContent = new SimpleXMLElement($request->getContent());
        $protocolXml = $this->deltraProductService->setStock($xmlContent);
        return $this->deltraResponses->createXmlResponse($protocolXml);
    }
}