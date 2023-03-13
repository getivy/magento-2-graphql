<?php

declare(strict_types=1);

namespace Esparksinc\IvyPaymentGraphql\Model\Api;

use Esparksinc\IvyPayment\Helper\Discount as DiscountHelper;
use Esparksinc\IvyPayment\Model\Config;
use Esparksinc\IvyPayment\Model\ErrorResolver;
use Esparksinc\IvyPayment\Model\IvyFactory;
use Esparksinc\IvyPaymentGraphql\Helper\Api as ApiHelper;
use Esparksinc\IvyPaymentGraphql\Model\Logger;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\UrlInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Cart\CartTotalRepository;
use Magento\Theme\Block\Html\Header\Logo;
use Magento\Quote\Model\Quote;

class CreateCheckoutSession
{
    protected $quoteRepository;
    protected $scopeConfig;
    protected $logo;
    protected $config;
    protected $ivy;
    protected $cartTotalRepository;
    protected $logger;
    protected $errorResolver;
    protected $apiHelper;
    protected $discountHelper;
    protected $_url;

    /**
     * @param CartRepositoryInterface $quoteRepository
     * @param ScopeConfigInterface $scopeConfig
     * @param Logo $logo
     * @param Config $config
     * @param IvyFactory $ivy
     * @param CartTotalRepository $cartTotalRepository
     * @param Logger $logger
     * @param ErrorResolver $errorResolver
     * @param ApiHelper $apiHelper
     * @param DiscountHelper $discountHelper
     * @param UrlInterface $url
     */
    public function __construct(
        CartRepositoryInterface $quoteRepository,
        ScopeConfigInterface    $scopeConfig,
        Logo                    $logo,
        Config                  $config,
        IvyFactory              $ivy,
        CartTotalRepository     $cartTotalRepository,
        Logger                  $logger,
        ErrorResolver           $errorResolver,
        ApiHelper               $apiHelper,
        DiscountHelper          $discountHelper,
        UrlInterface            $url
    ) {
        $this->quoteRepository = $quoteRepository;
        $this->scopeConfig = $scopeConfig;
        $this->logo = $logo;
        $this->config = $config;
        $this->ivy = $ivy;
        $this->cartTotalRepository = $cartTotalRepository;
        $this->logger = $logger;
        $this->errorResolver = $errorResolver;
        $this->apiHelper = $apiHelper;
        $this->discountHelper = $discountHelper;
        $this->_url = $url;
    }

    /**
     * @param Quote $quote
     * @param bool $express
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function execute(Quote $quote, bool $express = false): array
    {
        $ivyModel = $this->ivy->create();

        if (!$quote->getReservedOrderId()) {
            $quote->reserveOrderId();
            $ivyModel->setMagentoOrderId($quote->getReservedOrderId());
        }

        $orderId = $quote->getReservedOrderId();

        $quote->collectTotals();

        $this->quoteRepository->save($quote);

        if($express) {
            $phone = ['phone' => true];
            $data = [
                'express' => true,
                'required' => $phone,
            ];
        } else {
            $prefill = ["email" => $quote->getBillingAddress()->getEmail()];
            $shippingMethods = $quote->isVirtual() ? [] : $this->getShippingMethod($quote);
            $billingAddress = $this->getBillingAddress($quote);

            $data = [
                'handshake' => true,
                'shippingMethods' => $shippingMethods,
                'billingAddress' => $billingAddress,
                'prefill' => $prefill,
            ];
        }

        $data = array_merge($data, [
            'referenceId'           => $orderId,
            'category'              => $this->config->getMcc(),
            'price'                 => $this->getPrice($quote, $express),
            'lineItems'             => $this->getLineItems($quote),
            'plugin'                => $this->getPluginVersion(),

            "metadata"              => [
                'quote_id'          => $quote->getId()
            ],

            'successCallbackUrl'    => $this->_url->getUrl('ivypayment/success'),
            'errorCallbackUrl'      => $this->_url->getUrl('ivypayment/fail'),
            'quoteCallbackUrl'      => $this->_url->getUrl('ivypayment/quote'),
            'webhookUrl'            => $this->_url->getUrl('ivypayment/webhook'),
            'completeCallbackUrl'   => $this->_url->getUrl('ivypayment/order/complete'),
            'shopLogo'              => $this->getLogoSrc(),
        ]);

        $responseData = $this->apiHelper->requestApi('CreateCheckoutSession', 'checkout/session/create', $data, $orderId,
            function ($exception) use ($quote) {
                $this->errorResolver->tryResolveException($quote, $exception);
            }
        );

        if ($responseData) {
            $ivyModel->setIvyCheckoutSession($responseData['id']);
            $ivyModel->setIvyRedirectUrl($responseData['redirectUrl']);
            $ivyModel->save();
        }

        return $responseData;
    }

    private function getLineItems($quote)
    {
        $ivyLineItems = array();
        foreach ($quote->getAllVisibleItems() as $lineItem) {
            $ivyLineItems[] = [
                'name'          => $lineItem->getName(),
                'referenceId'   => $lineItem->getSku(),
                'singleNet'     => $lineItem->getBasePrice(),
                'singleVat'     => $lineItem->getBaseTaxAmount() ?: 0,
                'amount'        => $lineItem->getBaseRowTotalInclTax() ?: 0,
                'quantity'      => $lineItem->getQty(),
                'image'         => '',
            ];
        }

        $discountAmount = $this->discountHelper->getDiscountAmount($quote);
        if ($discountAmount !== 0.0) {
            $discountAmount = -1 * abs($discountAmount);
            $ivyLineItems[] = [
                'name'      => 'Discount',
                'singleNet' => $discountAmount,
                'singleVat' => 0,
                'amount'    => $discountAmount
            ];
        }

        return $ivyLineItems;
    }

    private function getPrice($quote, $express)
    {
        $totals = $this->cartTotalRepository->get($quote->getId());

        $shippingNet = $totals->getBaseShippingAmount();
        $shippingVat = $totals->getBaseShippingTaxAmount();
        $shippingTotal = $shippingNet + $shippingVat;

        $total = $totals->getBaseGrandTotal();
        $vat = $totals->getBaseTaxAmount();

        $totalNet = $total - $vat;

        $currency = $quote->getBaseCurrencyCode();

        if ($express) {
            $total -= $shippingTotal;
            $vat -= $shippingVat;
            $totalNet -= $shippingNet;
            $shippingTotal = 0;
            $shippingVat = 0;
            $shippingNet = 0;
        }

        return [
            'totalNet' => $totalNet,
            'vat' => $vat,
            'shipping' => $shippingTotal,
            'total' => $total,
            'currency' => $currency,
        ];
    }

    private function getShippingMethod($quote): array
    {
        $countryId = $quote->getShippingAddress()->getCountryId();
        $shippingMethod = array();
        $shippingLine = [
            'price'     => $quote->getBaseShippingAmount() ?: 0,
            'name'      => $quote->getShippingAddress()->getShippingMethod(),
            'countries' => [$countryId]
        ];

        $shippingMethod[] = $shippingLine;

        return $shippingMethod;
    }

    private function getBillingAddress($quote): array
    {
        return [
            'firstName' => $quote->getBillingAddress()->getFirstname(),
            'LastName'  => $quote->getBillingAddress()->getLastname(),
            'line1'     => $quote->getBillingAddress()->getStreet()[0],
            'city'      => $quote->getBillingAddress()->getCity(),
            'zipCode'   => $quote->getBillingAddress()->getPostcode(),
            'country'   => $quote->getBillingAddress()->getCountryId(),
        ];
    }

    protected function getLogoSrc(): string
    {
        $path = $this->scopeConfig->getValue(
            'payment/ivy/frontend_settings/custom_logo',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );

        if ($path) {
            $shopLogo = $this->_url
                    ->getBaseUrl(['_type' => \Magento\Framework\UrlInterface::URL_TYPE_MEDIA]) .'ivy/logo/'. $path;
        } else{
            $shopLogo = $this->logo->getLogoSrc();
        }
        return $shopLogo;
    }

    private function getPluginVersion(): string {
        $composerJson = json_decode(file_get_contents(__DIR__ . '/../../../composer.json'), true);
        return 'm2-graphql-'.$composerJson['version'];
    }
}
