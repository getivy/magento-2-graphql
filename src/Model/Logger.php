<?php

declare(strict_types=1);

namespace Esparksinc\IvyPaymentGraphql\Model;

use Monolog\DateTimeImmutable;

class Logger extends \Esparksinc\IvyPayment\Model\Logger
{
    public function debugGraphql(
        $graphqlActionName,
        string $orderId,
        string $message,
        array $context = []
    ) {
        $message = sprintf('#%s graphql::%s: %s',
            $orderId,
            $graphqlActionName,
            $message
        );
        $this->debug($message, $context);
    }
}
