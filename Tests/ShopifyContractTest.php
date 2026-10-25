<?php

namespace Omnitrade\Shopify\Tests;

use Omnitrade\GatewayFactoryInterface;
use Omnitrade\Shopify\Api\Hmac;
use Omnitrade\Shopify\ShopifyGatewayFactory;
use Omnitrade\Testing\GatewayContractTests;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Omnitrade's conformance suite, on Shopify's recorded answers (Admin GraphQL). */
final class ShopifyContractTest extends TestCase
{
    use GatewayContractTests;

    private const SECRET = 'shpss_contract';

    protected function factory(HttpClientInterface $http): GatewayFactoryInterface
    {
        return new ShopifyGatewayFactory($http);
    }

    protected function options(): array
    {
        return ['shop_domain' => 'example.myshopify.com', 'admin_token' => 'shpat_x', 'webhook_secret' => self::SECRET, 'retry_delay' => 0];
    }

    protected function transactionReference(): string
    {
        return 'gid://shopify/DraftOrder/7';
    }

    protected function productReference(): string
    {
        return 'gid://shopify/Product/9001';
    }

    protected function recordedAnswers(): array
    {
        $wine = json_decode((string) file_get_contents(__DIR__.'/Fixtures/products.wine.graphql.json'), true, 512, \JSON_THROW_ON_ERROR);
        $shop = ['currencyCode' => 'EUR', 'taxesIncluded' => true];
        $money = ['shopMoney' => ['amount' => '12.50', 'currencyCode' => 'EUR']];

        return ['POST /admin/api/2026-07/graphql.json' => static function (string $method, string $url, array $options) use ($wine, $shop, $money): MockResponse {
            $query = json_decode($options['body'], true)['query'];

            return new MockResponse(json_encode(['data' => match (true) {
                str_contains($query, 'draftOrderCreate') => ['draftOrderCreate' => ['draftOrder' => ['id' => 'gid://shopify/DraftOrder/7', 'name' => '#D7', 'invoiceUrl' => 'https://example.myshopify.com/invoices/abc', 'status' => 'OPEN', 'totalPrice' => '12.50'], 'userErrors' => []]],
                str_contains($query, 'query DraftOrder') => ['draftOrder' => ['id' => 'gid://shopify/DraftOrder/7', 'name' => '#D7', 'status' => 'OPEN', 'invoiceUrl' => 'https://example.myshopify.com/invoices/abc', 'order' => null]],
                str_contains($query, 'query Order') => ['order' => ['id' => 'gid://shopify/Order/1042', 'name' => '#1042', 'createdAt' => '2026-10-01T10:00:00Z', 'displayFinancialStatus' => 'PAID', 'displayFulfillmentStatus' => 'UNFULFILLED', 'totalPriceSet' => $money, 'email' => 'camille@example.org', 'lineItems' => ['nodes' => [['title' => 'Ten hours', 'quantity' => 1, 'sku' => null, 'variant' => null, 'originalUnitPriceSet' => $money]]]]],
                str_contains($query, 'query Products') => ['shop' => $shop, 'products' => ['pageInfo' => ['hasNextPage' => true, 'endCursor' => 'eyJsYXN0X2lkIjo5MDAxfQ=='], 'nodes' => [$wine]]],
                str_contains($query, 'query Product(') => ['shop' => $shop, 'product' => $wine],
                str_contains($query, 'query Shop') => ['shop' => $shop],
                default => [],
            }]));
        }];
    }

    protected function signedNotification(): ?array
    {
        $body = json_encode(['id' => 1042, 'admin_graphql_api_id' => 'gid://shopify/Order/1042', 'draft_order_id' => 7, 'note_attributes' => [['name' => 'omnitrade_reference', 'value' => 'ORDER-1042']]]);

        return [$body, ['X-Shopify-Hmac-Sha256' => Hmac::sign($body, self::SECRET), 'X-Shopify-Topic' => 'orders/paid', 'X-Shopify-Shop-Domain' => 'example.myshopify.com', 'X-Shopify-Webhook-Id' => 'wh-1']];
    }
}
