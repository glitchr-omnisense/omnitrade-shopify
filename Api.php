<?php

namespace Omnitrade\Shopify;

use Omnitrade\Shopify\Api\AdminApi;
use Omnitrade\Shopify\Api\Endpoint;
use Omnitrade\Shopify\Api\GraphQL;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** One shop's APIs, for the gateway's actions. */
final class Api
{
    public readonly AdminApi $admin;

    /** @param string[] $draftOrderTags */
    public function __construct(
        public readonly Endpoint $endpoint,
        HttpClientInterface $http,
        public readonly array $draftOrderTags = [],
        ?LoggerInterface $logger = null,
        float $retryDelay = 1.0,
    ) {
        $graphql = new GraphQL($http, $logger, null, $retryDelay);
        $this->admin = new AdminApi($graphql, $endpoint);
    }
}
