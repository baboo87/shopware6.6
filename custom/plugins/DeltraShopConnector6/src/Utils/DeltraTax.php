<?php declare(strict_types = 1);

namespace Deltra\ShopConnector\Utils;

use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\Tax\TaxEntity;
use Shopware\Core\Content\Product\ProductEntity;

class DeltraTax
{
    /** @var EntityRepository */
    private $productRepository;

    const DELTRA_DEFAULT_TAXRATE = 19;

    public function __construct(
        EntityRepository $productRepository
    )
    {
        $this->productRepository = $productRepository;
    }

    public function getTaxRate(ProductEntity $product, Context $context): float
    {
        /** @var TaxEntity $tax */
        $tax = $product->getTax();

        $parentId = $product->getParentId();
        if (!$tax && $parentId)
        {
            $productCriteria = new Criteria();
            $productCriteria->addFilter(new EqualsFilter('id', $parentId));

            /** @var ProductEntity $parent */
            $parent = $this->productRepository->search($productCriteria, $context)->first();
            $tax = $parent->getTax();
        }

        $taxRate = $tax ? $tax->getTaxRate() : self::DELTRA_DEFAULT_TAXRATE;

        return $taxRate;
    }
}