<?php declare(strict_types=1);

namespace Deltra\ShopConnector\Subscriber;

use Deltra\ShopConnector\Interfaces\DeltraController;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class ControllerSubscriber implements EventSubscriberInterface
{
    /** @var SystemConfigService */
    private $systemConfigService;

    public function __construct(SystemConfigService $systemConfigService)
    {
        $this->systemConfigService = $systemConfigService;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => 'onKernelController'
        ];
    }

    public function onKernelController(ControllerEvent $event)
    {
        $controller = $event->getController();

        if (!is_array($controller)) return;
        if (!($controller[0] instanceof DeltraController)) return;
        
        $userAgentConfig = $this->systemConfigService->get("DeltraShopConnector6.config.useragent");

        $userAgentRequest = $event->getRequest()->headers->get('user-agent');
        if ($userAgentRequest !== $userAgentConfig)
        {
            throw new UnauthorizedHttpException("Invalid user");
        }
    }
}