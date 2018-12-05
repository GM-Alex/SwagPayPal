<?php declare(strict_types=1);
/**
 * (c) shopware AG <info@shopware.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SwagPayPal\PayPal\Payment;

use Shopware\Core\Checkout\Cart\Price\Struct\Price;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemStruct;
use Shopware\Core\Checkout\Order\OrderStruct;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Read\ReadCriteria;
use Shopware\Core\Framework\DataAbstractionLayer\RepositoryInterface;
use Shopware\Core\System\Language\LanguageCollection;
use Shopware\Core\System\Language\LanguageStruct;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\System\SalesChannel\SalesChannelStruct;
use SwagPayPal\PayPal\Api\Payment;
use SwagPayPal\PayPal\Api\Payment\ApplicationContext;
use SwagPayPal\PayPal\Api\Payment\Payer;
use SwagPayPal\PayPal\Api\Payment\RedirectUrls;
use SwagPayPal\PayPal\Api\Payment\Transaction;
use SwagPayPal\PayPal\Api\Payment\Transaction\Amount;
use SwagPayPal\PayPal\Api\Payment\Transaction\Amount\Details;
use SwagPayPal\PayPal\Api\Payment\Transaction\ItemList;
use SwagPayPal\PayPal\Api\Payment\Transaction\ItemList\Item;
use SwagPayPal\Setting\Service\SettingsProviderInterface;
use SwagPayPal\Setting\SwagPayPalSettingGeneralStruct;

class PaymentBuilderService implements PaymentBuilderInterface
{
    /**
     * @var RepositoryInterface
     */
    private $languageRepo;

    /**
     * @var RepositoryInterface
     */
    private $salesChannelRepo;

    /**
     * @var RepositoryInterface
     */
    private $orderRepo;

    /**
     * @var SettingsProviderInterface
     */
    private $settingsProvider;

    /**
     * @var SwagPayPalSettingGeneralStruct
     */
    private $settings;

    public function __construct(
        RepositoryInterface $languageRepo,
        RepositoryInterface $salesChannelRepo,
        RepositoryInterface $orderRepo,
        SettingsProviderInterface $settingsProvider
    ) {
        $this->languageRepo = $languageRepo;
        $this->salesChannelRepo = $salesChannelRepo;
        $this->settingsProvider = $settingsProvider;
        $this->orderRepo = $orderRepo;
    }

    /**
     * {@inheritdoc}
     */
    public function getPayment(PaymentTransactionStruct $paymentTransaction, Context $context): Payment
    {
        $this->settings = $this->settingsProvider->getSettings($context);
        $requestPayment = new Payment();
        $requestPayment->setIntent('sale');

        $payer = new Payer();
        $payer->setPaymentMethod('paypal');

        $redirectUrls = new RedirectUrls();
        $redirectUrls->setCancelUrl($paymentTransaction->getReturnUrl() . '&cancel=1');
        $redirectUrls->setReturnUrl($paymentTransaction->getReturnUrl());

        $currency = $paymentTransaction->getOrder()->getCurrency()->getShortName();

        $amount = new Amount();
        $amount->setTotal($this->formatPrice($paymentTransaction->getAmount()->getTotalPrice()));
        $amount->setCurrency($currency);
        $amount->setDetails($this->getAmountDetails($paymentTransaction));

        $transaction = new Transaction();
        $transaction->setAmount($amount);

        if ($this->settings->getSubmitCart()) {
            $items = $this->getItemList($paymentTransaction, $context, $currency);

            if (!empty($items)) {
                $itemList = new ItemList();
                $itemList->setItems($items);
                $transaction->setItemList($itemList);
            }
        }

        $requestPayment->setPayer($payer);
        $requestPayment->setRedirectUrls($redirectUrls);
        $requestPayment->setTransactions([$transaction]);

        $applicationContext = $this->getApplicationContext($context);

        $requestPayment->setApplicationContext($applicationContext);

        return $requestPayment;
    }

    private function getAmountDetails(PaymentTransactionStruct $paymentTransaction): Details
    {
        $amountDetails = new Details();

        $amountDetails->setShipping($this->formatPrice($paymentTransaction->getOrder()->getShippingTotal()));
        $totalAmount = $paymentTransaction->getAmount()->getTotalPrice();
        $taxAmount = $paymentTransaction->getAmount()->getCalculatedTaxes()->getAmount();
        $amountDetails->setSubtotal($this->formatPrice($totalAmount - $taxAmount));
        $amountDetails->setTax($this->formatPrice($taxAmount));

        return $amountDetails;
    }

    private function getApplicationContext(Context $context): ApplicationContext
    {
        $applicationContext = new ApplicationContext();
        $applicationContext->setLocale($this->getLocaleCode($context));
        $applicationContext->setBrandName($this->getBrandName($context));
        $applicationContext->setLandingPage($this->getLandingPageType());

        return $applicationContext;
    }

    private function getLocaleCode(Context $context): string
    {
        $languageId = $context->getLanguageId();
        /** @var LanguageCollection $languageCollection */
        $languageCollection = $this->languageRepo->read(new ReadCriteria([$languageId]), $context);
        /** @var LanguageStruct $language */
        $language = $languageCollection->get($languageId);

        return $language->getLocale()->getCode();
    }

    private function getBrandName(Context $context): string
    {
        $brandName = $this->settings->getBrandName();

        if (empty($brandName)) {
            $brandName = $this->useSalesChannelNameAsBrandName($context);
        }

        return $brandName;
    }

    private function useSalesChannelNameAsBrandName(Context $context): string
    {
        $brandName = '';
        $salesChannelId = $context->getSourceContext()->getSalesChannelId();
        if ($salesChannelId === null) {
            return $brandName;
        }

        /** @var SalesChannelCollection $salesChannelCollection */
        $salesChannelCollection = $this->salesChannelRepo->read(new ReadCriteria([$salesChannelId]), $context);
        /** @var SalesChannelStruct $salesChannel */
        $salesChannel = $salesChannelCollection->get($salesChannelId);
        if ($salesChannel !== null) {
            $brandName = $salesChannel->getName();
        }

        return $brandName;
    }

    private function formatPrice(float $price): string
    {
        return (string) round($price, 2);
    }

    private function getItemList(PaymentTransactionStruct $transactionStruct, Context $context, string $currency): array
    {
        $items = [];
        $order = $this->getOrder($transactionStruct, $context);

        if ($order === null || $order->getLineItems() === null) {
            return [];
        }

        /** @var OrderLineItemStruct[] $lineItems */
        $lineItems = $order->getLineItems()->getElements();

        foreach ($lineItems as $id => $lineItem) {
            $price = $lineItem->getPrice();

            if ($price === null) {
                return [];
            }

            $items[] = $this->createItemFromLineItem($lineItem, $currency, $price);
        }

        return $items;
    }

    private function getOrder(PaymentTransactionStruct $transactionStruct, Context $context): ?OrderStruct
    {
        $orderId = $transactionStruct->getOrder()->get('id');
        $criteria = new ReadCriteria([$orderId]);
        $criteria->addAssociation('order.lineItems');

        /** @var OrderStruct $order */
        $order = $this->orderRepo->read($criteria, $context)->get($orderId);

        return $order;
    }

    private function createItemFromLineItem(OrderLineItemStruct $lineItem, string $currency, Price $price): Item
    {
        $taxAmount = $price->getCalculatedTaxes()->getAmount();

        $item = new Item();
        $item->setName($lineItem->getLabel());
        $item->setSku($lineItem->getPayload()['id']);
        $item->setPrice($this->formatPrice($price->getTotalPrice() - $taxAmount));
        $item->setCurrency($currency);
        $item->setQuantity($lineItem->getQuantity());
        $item->setTax($this->formatPrice($taxAmount));

        return $item;
    }

    private function getLandingPageType(): string
    {
        $landingPageType = $this->settings->getLandingPage();
        if ($landingPageType !== ApplicationContext::LANDINGPAGE_TYPE_BILLING) {
            $landingPageType = ApplicationContext::LANDINGPAGE_TYPE_LOGIN;
        }

        return $landingPageType;
    }
}
