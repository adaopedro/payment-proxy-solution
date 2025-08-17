<?php

declare(strict_types=1);

namespace Solution\Http;

class CreatePaymentRequestValidator
{
    public function validate(array $data): array
    {
        if (
            empty($data['correlationId']) ||
            !is_string($data['correlationId']) ||
            !preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
                $data['correlationId']
            )
        ) {
            throw new \InvalidArgumentException('Invalid or missing correlationId');
        }

        if (
            !isset($data['amount']) ||
            !is_numeric($data['amount']) ||
            $data['amount'] <= 0
        ) {
            throw new \InvalidArgumentException('Invalid or missing amount');
        }

        return $data;
    }
}
