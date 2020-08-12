<?php declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\PayPal\IZettle\Setting\Service;

use Shopware\Core\Framework\Api\Exception\InvalidSalesChannelIdException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InvalidAggregationQueryException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\CountAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\CountResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepositoryInterface;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Swag\PayPal\IZettle\DataAbstractionLayer\Entity\IZettleSalesChannelEntity;
use Swag\PayPal\IZettle\Resource\ProductResource;
use Swag\PayPal\IZettle\Setting\Struct\ProductCount;
use Swag\PayPal\IZettle\Sync\ProductSelection;
use Swag\PayPal\SwagPayPal;

class ProductCountService
{
    /**
     * @var ProductResource
     */
    private $productResource;

    /**
     * @var ProductSelection
     */
    private $productSelection;

    /**
     * @var SalesChannelRepositoryInterface
     */
    private $productRepository;

    /**
     * @var EntityRepositoryInterface
     */
    private $salesChannelRepository;

    public function __construct(
        ProductResource $productResource,
        ProductSelection $productSelection,
        SalesChannelRepositoryInterface $productRepository,
        EntityRepositoryInterface $salesChannelRepository
    ) {
        $this->productResource = $productResource;
        $this->productSelection = $productSelection;
        $this->productRepository = $productRepository;
        $this->salesChannelRepository = $salesChannelRepository;
    }

    public function getProductCounts(string $salesChannelId, string $cloneSalesChannelId, Context $context): ProductCount
    {
        /** @var SalesChannelEntity|null $salesChannel */
        $salesChannel = $this->salesChannelRepository->search(new Criteria([$salesChannelId]), $context)->first();

        if ($salesChannel === null) {
            throw new InvalidSalesChannelIdException($salesChannelId);
        }

        $productCountResponse = new ProductCount();
        $productCountResponse->setLocalCount($this->getLocalCount($salesChannel, $cloneSalesChannelId, $context));
        $productCountResponse->setRemoteCount($this->getRemoteCount($salesChannel));

        return $productCountResponse;
    }

    private function getLocalCount(SalesChannelEntity $salesChannel, string $cloneSalesChannelId, Context $context): int
    {
        if ($cloneSalesChannelId === '') {
            return 0;
        }

        /** @var SalesChannelEntity|null $cloneSalesChannel */
        $cloneSalesChannel = $this->salesChannelRepository->search(new Criteria([$cloneSalesChannelId]), $context)->first();

        if ($cloneSalesChannel === null) {
            throw new InvalidSalesChannelIdException($cloneSalesChannelId);
        }

        /** @var IZettleSalesChannelEntity $iZettleSalesChannel */
        $iZettleSalesChannel = $salesChannel->getExtension(SwagPayPal::SALES_CHANNEL_IZETTLE_EXTENSION);

        $salesChannelContext = $this->productSelection->getSalesChannelContext($cloneSalesChannel);

        $criteria = $this->productSelection->getProductStreamCriteria($iZettleSalesChannel->getProductStreamId(), $context);
        $criteria->addAggregation(new CountAggregation('count', 'id'));

        /** @var CountResult|null $aggregate */
        $aggregate = $this->productRepository->aggregate($criteria, $salesChannelContext)->get('count');
        if ($aggregate === null) {
            throw new InvalidAggregationQueryException('Could not aggregate product count');
        }

        return $aggregate->getCount();
    }

    private function getRemoteCount(SalesChannelEntity $salesChannel): int
    {
        /** @var IZettleSalesChannelEntity $iZettleSalesChannel */
        $iZettleSalesChannel = $salesChannel->getExtension(SwagPayPal::SALES_CHANNEL_IZETTLE_EXTENSION);

        return $this->productResource->getProductCount($iZettleSalesChannel)->getProductCount();
    }
}
