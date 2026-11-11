<?php

namespace Omnitrade\Shopify\Tests;

use Omnitrade\Model\Money;
use Omnitrade\Model\Payment;
use Omnitrade\Shopify\Api\Hmac;
use Omnitrade\Shopify\Data;
use Omnitrade\Shopify\ShopifyGatewayFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The gateway's tags (the "tags" option) on a Shopify draft order, as the
 * mutation goes on the wire: custom attributes (the order's note_attributes)
 * under the payment's metadata, "site" the configuration's, and a
 * "site:<name>" tag beside draft_order_tags; an order's webhook gives them back.
 */
final class ShopifyTagsTest extends TestCase
{
    private const SECRET = 'shpss_test';

    /** @var list<Data> the GraphQL documents sent */
    private array $queries = [];

    public function testADraftOrderCarriesTheSite(): void
    {
        $this->gateway(['site' => 'solstay', 'env' => 'demo'])->purchase(new Payment(Money::of(1250, 'EUR'), 'B-42', 'Two nights', metadata: ['env' => 'mine', 'site' => 'wineseller']));

        $input = $this->queries[0]->data('variables.input');
        self::assertSame([
            ['key' => 'omnitrade_reference', 'value' => 'B-42'],
            ['key' => 'site', 'value' => 'solstay'],
            ['key' => 'env', 'value' => 'mine'],
        ], $input->get('customAttributes'));
        self::assertSame(['omnitrade', 'site:solstay'], $input->get('tags'));
    }

    public function testWithoutTagsTheDraftOrderIsAsBefore(): void
    {
        $this->gateway([])->purchase(new Payment(Money::of(1250, 'EUR'), 'B-42', 'Two nights', metadata: ['env' => 'mine']));

        $input = $this->queries[0]->data('variables.input');
        self::assertSame([['key' => 'omnitrade_reference', 'value' => 'B-42'], ['key' => 'env', 'value' => 'mine']], $input->get('customAttributes'));
        self::assertSame(['omnitrade'], $input->get('tags'));
    }

    public function testAnOrdersWebhookGivesTheSiteBack(): void
    {
        $gateway = $this->gateway(['site' => 'solstay']);

        $theirs = $gateway->notify(...self::event(['id' => 1042, 'note_attributes' => [['name' => 'omnitrade_reference', 'value' => 'W-1'], ['name' => 'site', 'value' => 'wineseller']]]));
        self::assertSame(['site' => 'wineseller'], $theirs->tags);
        self::assertTrue($theirs->isFromAnotherSite('solstay'));

        $tagged = $gateway->notify(...self::event(['id' => 1043, 'note_attributes' => [], 'tags' => 'omnitrade, site:solstay']));
        self::assertSame('solstay', $tagged->site(), 'the order\'s tag, when no attribute says it');
    }

    /** @param array<string, mixed> $tags */
    private function gateway(array $tags): \Omnitrade\GatewayInterface
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->queries[] = Data::of(json_decode(Data::cast($options['body'] ?? null), true));

            return new MockResponse((string) json_encode(['data' => ['draftOrderCreate' => ['draftOrder' => ['id' => 'gid://shopify/DraftOrder/7', 'name' => '#D7', 'invoiceUrl' => 'https://example.myshopify.com/invoices/abc', 'status' => 'OPEN', 'totalPrice' => '12.50'], 'userErrors' => []]]], \JSON_THROW_ON_ERROR));
        });

        return (new ShopifyGatewayFactory($http))->create(['shop_domain' => 'example.myshopify.com', 'admin_token' => 'shpat_x', 'webhook_secret' => self::SECRET, 'draft_order_tags' => ['omnitrade']] + ([] === $tags ? [] : ['tags' => $tags]));
    }

    /**
     * @param array<string, mixed> $order
     *
     * @return array{string, array<string, string>}
     */
    private static function event(array $order): array
    {
        $id = Data::cast($order['id'] ?? null);
        $body = (string) json_encode($order + ['admin_graphql_api_id' => 'gid://shopify/Order/'.$id], \JSON_THROW_ON_ERROR);

        return [$body, ['X-Shopify-Hmac-Sha256' => Hmac::sign($body, self::SECRET), 'X-Shopify-Topic' => 'orders/paid', 'X-Shopify-Shop-Domain' => 'example.myshopify.com', 'X-Shopify-Webhook-Id' => 'wh-'.$id]];
    }
}
