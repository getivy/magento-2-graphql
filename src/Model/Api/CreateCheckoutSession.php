<?php

declare(strict_types=1);

namespace Esparksinc\IvyPaymentGraphql\Model\Api;

use Esparksinc\IvyPayment\Model\Config;
use Esparksinc\IvyPayment\Model\IvyFactory;
use GuzzleHttp\Client;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Cart\CartTotalRepository;
use Magento\Quote\Model\Quote;
use Psr\Http\Message\ResponseInterface;

class CreateCheckoutSession
{
    /**
     * @var CartRepositoryInterface
     */
    protected $quoteRepository;

    /**
     * @var Config
     */
    protected $config;

    /**
     * @var Json
     */
    protected $json;

    /**
     * @var IvyFactory
     */
    protected $ivyFactory;

    /**
     * @var CartTotalRepository
     */
    protected $cartTotalRepository;

    public function __construct(
        CartRepositoryInterface $quoteRepository,
        Json $json,
        Config $config,
        IvyFactory $ivyFactory,
        CartTotalRepository $cartTotalRepository
    ) {
        $this->quoteRepository = $quoteRepository;
        $this->json = $json;
        $this->config = $config;
        $this->ivyFactory = $ivyFactory;
        $this->cartTotalRepository = $cartTotalRepository;
    }

    /**
     * @param Quote $quote
     * @param bool $isExpress
     * @return ResponseInterface
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function execute(Quote $quote, bool $isExpress = false): ResponseInterface
    {
        $ivyModel = $this->ivyFactory->create();

        if (!$quote->getReservedOrderId()) {
            $quote->reserveOrderId();
        }

        $this->quoteRepository->save($quote);
        $orderId = $quote->getReservedOrderId();
        $ivyModel->setMagentoOrderId($orderId);

        //Price
        $price = $this->getPrice($quote);

        // Line Items
        $ivyLineItems = $this->getLineItems($quote);

        // Shipping Methods
        $shippingMethod = $this->getShippingMethod($quote);

        //billingAddress
        $billingAddress = $this->getBillingAddress($quote);

        $mcc = $this->config->getMcc();

        if ($isExpress) {
            $phone = ['phone' => true];
            $data = [
                'express'     => true,
                'referenceId' => $orderId,
                'category'    => $mcc,
                'price'       => $price,
                'lineItems'   => $ivyLineItems,
                'required'    => $phone
            ];
        } else {
            $prefill = ["email" => $quote->getCustomerEmail()];
            $data = [
                'handshake'       => true,
                'referenceId'     => $orderId,
                'category'        => $mcc,
                'price'           => $price,
                'lineItems'       => $ivyLineItems,
                'shippingMethods' => [$shippingMethod],
                'billingAddress'  => $billingAddress,
                'prefill'         => $prefill,
            ];
        }

        $jsonContent = $this->json->serialize($data);

        $headers['content-type'] = 'application/json';
        $options = [
            'headers' => $headers,
            'body' => $jsonContent,
        ];

        $client = new Client([
            'base_uri' => $this->config->getApiUrl(),
            'headers' => [
                'X-Ivy-Api-Key' => $this->config->getApiKey(),
            ],
        ]);

        $response = $client->post('checkout/session/create', $options);

        if ($response->getStatusCode() === 200) {
            $arrData = $this->json->unserialize((string)$response->getBody());

            $ivyModel->setIvyCheckoutSession($arrData['id']);
            $ivyModel->setIvyRedirectUrl($arrData['redirectUrl']);
            $ivyModel->save();
        }

        return $response;
    }

    private function getLineItems($quote): array
    {
        $ivyLineItems = [];
        foreach ($quote->getAllVisibleItems() as $lineItem) {
            $lineItem = [
                'name'        => $lineItem->getName(),
                'referenceId' => $lineItem->getSku(),
                'singleNet'   => $lineItem->getBasePrice(),
                'singleVat'   => $lineItem->getBaseTaxAmount()?:0,
                'amount'      => $lineItem->getBaseRowTotalInclTax()?:0,
                'quantity'    => $lineItem->getQty(),
                'image'       => '',
            ];

            $ivyLineItems[] = $lineItem;
        }

        $totals = $this->cartTotalRepository->get($quote->getId());
        $discountAmount = $totals->getDiscountAmount();
        if ($discountAmount < 0) {
            $lineItem = [
                'name'      => 'Discount',
                'singleNet' => $discountAmount,
                'singleVat' => 0,
                'amount'    => $discountAmount
            ];

            $ivyLineItems[] = $lineItem;
        }

        return $ivyLineItems;
    }

    private function getPrice($quote): array
    {
        return [
            'totalNet' => $quote->getBaseSubtotal()?:0,
            'vat'      => $quote->getShippingAddress()->getBaseTaxAmount()?:0,
            'shipping' => $quote->getBaseShippingAmount()?:0,
            'total'    => $quote->getBaseGrandTotal()?:0,
            'currency' => $quote->getBaseCurrencyCode(),
        ];
    }

    private function getShippingMethod($quote): array
    {
        $countryId = $quote->getShippingAddress()->getCountryId();

        return [
            'price'     => $quote->getBaseShippingAmount()?:0,
            'name'      => $quote->getShippingAddress()->getShippingMethod(),
            'countries' => [$countryId]
        ];
    }

    private function getBillingAddress($quote): array
    {
        return [
            'line1'   => $quote->getBillingAddress()->getStreet()[0],
            'city'    => $quote->getBillingAddress()->getCity(),
            'zipCode' => $quote->getBillingAddress()->getPostcode(),
            'country' => $quote->getBillingAddress()->getCountryId(),
        ];
    }
}
