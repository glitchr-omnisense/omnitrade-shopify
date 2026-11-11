<?php

namespace Omnitrade\Shopify\Api;

use Omnitrade\Exception\ProviderException;
use Omnitrade\Shopify\Data;

/**
 * Shopify said no, or said nothing. Carries the userErrors/errors it came
 * with; never the access token (the headers are built at request time).
 */
class ShopifyApiException extends ProviderException
{
    /** @param array<mixed> $errors */
    public function __construct(string $message, public readonly array $errors = [], ?\Throwable $previous = null)
    {
        parent::__construct('shopify', $message, null, $previous);
    }

    /** @param array<mixed> $errors Shopify's errors or userErrors: {message, field} each */
    public static function fromErrors(string $what, array $errors): self
    {
        $messages = [];
        foreach ($errors as $error) {
            $read = Data::of($error);
            $field = $read->get('field');
            $messages[] = ($field ? implode('.', Data::texts($field)).': ' : '').Data::cast($read->get('message') ?? 'unknown error');
        }

        return new self($what.': '.implode('; ', $messages ?: ['unknown error']), $errors);
    }
}
