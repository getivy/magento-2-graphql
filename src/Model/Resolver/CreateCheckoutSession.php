<?php

declare(strict_types=1);

namespace Esparksinc\IvyPaymentGraphql\Model\Resolver;

use Esparksinc\IvyPaymentGraphql\Model\Api\CreateCheckoutSession as CreateCheckoutSessionApi;
use GuzzleHttp\Exception\GuzzleException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Exception\GraphQlNoSuchEntityException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\QuoteGraphQl\Model\Cart\GetCartForUser;

/**
 * Resolver for createIvyCheckoutSession mutation
 *
 * @inheritdoc
 */
class CreateCheckoutSession implements ResolverInterface
{
    /**
     * @var GetCartForUser
     */
    private $getCartForUser;

    /**
     * @var CreateCheckoutSessionApi
     */
    private $createCheckoutSessionApi;

    public function __construct(
        CreateCheckoutSessionApi $createCheckoutSessionApi,
        GetCartForUser $getCartForUser
    ) {
        $this->createCheckoutSessionApi = $createCheckoutSessionApi;
        $this->getCartForUser = $getCartForUser;
    }

    /**
     * @inheritdoc
     */
    public function resolve(Field $field, $context, ResolveInfo $info, array $value = null, array $args = null)
    {
        if (empty($args['input'])) {
            throw new GraphQlInputException(__('Input is missing'));
        }
        $args = $args['input'];

        if (empty($args['cartId'])) {
            throw new GraphQlInputException(__('Required parameter "cartId" is missing'));
        }
        if (!array_key_exists('express', $args)) {
            throw new GraphQlInputException(__('Required parameter "express" is missing'));
        }

        $maskedCartId = $args['cartId'];
        $isExpress = $args['express'];
        $storeId = (int)$context->getExtensionAttributes()->getStore()->getId();

        $errorMessage = '';
        $redirectUrl = '';

        try {
            $cart = $this->getCartForUser->execute($maskedCartId, $context->getUserId(), $storeId);

            $responseData = $this->createCheckoutSessionApi->execute($cart, $isExpress);
            $redirectUrl = $responseData['redirectUrl'] ?? '';
        } catch (GraphQlNoSuchEntityException
                |GraphQlAuthorizationException
                |NoSuchEntityException
                |GraphQlInputException
                |GuzzleException $e
        ) {
            $errorMessage = $e->getMessage();
        }

        return [
            'redirectUrl'  => $redirectUrl,
            'errorMessage' => $errorMessage
        ];
    }
}
