<?php

namespace Omnitrade\Shopify\Tests;

use Omnitrade\GatewayInterface;
use Omnitrade\Model\Media;
use Omnitrade\Model\Product;
use Omnitrade\Request\FetchInventory;
use Omnitrade\Request\FetchProduct;
use Omnitrade\Request\FetchProducts;
use Omnitrade\Shopify\Api\Hmac;
use Omnitrade\Shopify\Data;
use Omnitrade\Shopify\Products;
use Omnitrade\Shopify\ShopifyGatewayFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ShopifyCatalogueTest extends TestCase
{
    private const SECRET = 'shpss_secret';

    /** @var list<Data> the GraphQL documents sent, query and variables */
    private array $queries = [];

    /** @return array<string, mixed> */
    private static function fixture(string $name): array
    {
        return Data::map(json_decode((string) file_get_contents(__DIR__.'/Fixtures/'.$name), true, 512, \JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $options */
    private function gateway(array $options = []): GatewayInterface
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame('https://example.myshopify.com/admin/api/2026-07/graphql.json', $url);
            $sent = Data::of(json_decode(Data::cast($options['body'] ?? null), true));
            $this->queries[] = $sent;
            $query = $sent->text('query');
            $variables = $sent->data('variables');
            $shop = ['currencyCode' => 'EUR', 'taxesIncluded' => true];
            $wine = self::fixture('products.wine.graphql.json');
            $mug = self::fixture('product.graphql.json');
            $data = match (true) {
                str_contains($query, 'query Products') => match (true) {
                    str_starts_with($variables->text('query'), 'handle:') => ['shop' => $shop, 'products' => ['pageInfo' => ['hasNextPage' => false, 'endCursor' => null], 'nodes' => 'handle:"margaux-chateau-exemple"' === $variables->get('query') ? [['handle' => 'margaux-chateau-exemple-magnum'] + $mug, $wine] : []]],
                    null === $variables->get('after') => ['shop' => $shop, 'products' => ['pageInfo' => ['hasNextPage' => true, 'endCursor' => 'eyJsYXN0X2lkIjo5MDAxfQ=='], 'nodes' => [$wine]]],
                    default => ['shop' => $shop, 'products' => ['pageInfo' => ['hasNextPage' => false, 'endCursor' => 'eyJsYXN0X2lkIjo4NDcyOTEzODQ3fQ=='], 'nodes' => [$mug]]],
                },
                str_contains($query, 'query Product(') => ['shop' => $shop, 'product' => 'gid://shopify/Product/9001' === $variables->get('id') ? $wine : null],
                str_contains($query, 'query VariantStocks') => ['nodes' => array_map(static fn (mixed $id) => match ($id) {
                    'gid://shopify/ProductVariant/72' => ['id' => $id, 'sku' => 'MGX18', 'inventoryQuantity' => 12, 'inventoryItem' => ['id' => 'gid://shopify/InventoryItem/82', 'tracked' => true]],
                    'gid://shopify/ProductVariant/73' => ['id' => $id, 'sku' => 'GIFT', 'inventoryQuantity' => -3, 'inventoryItem' => ['id' => 'gid://shopify/InventoryItem/83', 'tracked' => false]],
                    default => null,
                }, (array) $variables->get('ids'))],
                str_contains($query, 'query Stocks') => null === $variables->get('after')
                    ? ['productVariants' => ['pageInfo' => ['hasNextPage' => true, 'endCursor' => 'c1'], 'nodes' => [['id' => 'gid://shopify/ProductVariant/71', 'sku' => 'MGX19', 'inventoryQuantity' => 0, 'inventoryItem' => ['id' => 'gid://shopify/InventoryItem/81', 'tracked' => true]]]]]
                    : ['productVariants' => ['pageInfo' => ['hasNextPage' => false, 'endCursor' => 'c2'], 'nodes' => [['id' => 'gid://shopify/ProductVariant/72', 'sku' => 'MGX18', 'inventoryQuantity' => 12, 'inventoryItem' => ['id' => 'gid://shopify/InventoryItem/82', 'tracked' => true]]]]],
                str_contains($query, 'query Shop') => ['shop' => ['currencyCode' => 'CHF', 'taxesIncluded' => false]],
                default => [],
            };

            return new MockResponse(json_encode(['data' => $data], \JSON_THROW_ON_ERROR));
        });

        return (new ShopifyGatewayFactory($http))->create($options + ['shop_domain' => 'example.myshopify.com', 'admin_token' => 'shpat_x', 'webhook_secret' => self::SECRET]);
    }

    public function testTheCatalogueIsReadPageByPage(): void
    {
        $gateway = $this->gateway();
        self::assertTrue($gateway->supports(FetchProducts::class));
        self::assertTrue($gateway->supports(FetchProduct::class));
        self::assertTrue($gateway->supports(FetchInventory::class));

        $first = $gateway->fetchProducts(limit: 1);
        self::assertSame(1, $this->queries[0]->get('variables.first'));
        self::assertNull($this->queries[0]->get('variables.query'));
        self::assertStringContainsString('sortKey: UPDATED_AT', $this->queries[0]->text('query'));
        self::assertTrue($first->hasMore());
        self::assertSame('eyJsYXN0X2lkIjo5MDAxfQ==', $first->next, 'the endCursor');

        $second = $gateway->fetchProducts($first->next, limit: 1);
        self::assertSame('eyJsYXN0X2lkIjo5MDAxfQ==', $this->queries[1]->get('variables.after'));
        self::assertFalse($second->hasMore(), 'no next page: no cursor, whatever endCursor says');
        self::assertSame('Enamel Mug', $second->products[0]->title);

        $gateway->fetchProducts(updatedSince: new \DateTimeImmutable('2026-10-01 10:00:00', new \DateTimeZone('Europe/Paris')), query: 'margaux', limit: 500);
        self::assertSame("updated_at:>'2026-10-01T08:00:00Z' margaux", $this->queries[2]->get('variables.query'), 'the date in UTC, the text appended');
        self::assertSame(250, $this->queries[2]->get('variables.first'), 'Shopify\'s ceiling');
    }

    public function testAProductIsMappedWhole(): void
    {
        $product = $this->gateway()->fetchProducts()->products[0];

        self::assertSame('shopify', $product->provider);
        self::assertSame('gid://shopify/Product/9001', $product->reference);
        self::assertSame('margaux-chateau-exemple', $product->handle);
        self::assertSame('Château Exemple', $product->brand, 'the vendor');
        self::assertSame(['Vin rouge', 'Bordeaux'], $product->categories, 'the product type, then the collections, once each');
        self::assertSame(['bordeaux', 'garde'], $product->tags);
        self::assertSame(['custom.appellation' => 'Margaux AOC', 'custom.cepage' => 'Cabernet sauvignon'], $product->attributes);
        self::assertSame('Millésime', $product->options[0]->name);
        self::assertSame(['2019', '2018'], $product->options[0]->values);
        self::assertSame('https://cave.example/products/margaux-chateau-exemple', $product->url);
        self::assertSame(Product::ACTIVE, $product->status);
        self::assertEquals(new \DateTimeImmutable('2026-10-02T08:30:00Z'), $product->updatedAt);

        self::assertCount(2, $product->media, 'the 3D model is not a picture');
        self::assertSame(Media::IMAGE, $product->media[0]->type);
        self::assertSame(800, $product->media[0]->width);
        self::assertSame('La bouteille', $product->media[0]->alt);
        self::assertSame(Media::VIDEO, $product->media[1]->type);

        [$v2019, $v2018] = $product->variants;
        self::assertSame('2019', $v2019->title);
        self::assertSame(['Millésime' => '2019'], $v2019->options);
        self::assertNull($v2019->barcode, 'blank is null');
        self::assertSame(1300, $v2019->weight, 'kilograms in grams');
        self::assertFalse($v2019->offer()?->available);
        self::assertNotNull($v2019->stock);
        self::assertSame(0, $v2019->stock->quantity);
        self::assertFalse($v2019->stock->available());

        $price = $v2018->price();
        $offer = $v2018->offer();
        self::assertNotNull($price);
        self::assertNotNull($offer);
        self::assertNotNull($v2018->stock);
        self::assertSame(2400, $price->amount);
        self::assertSame('EUR', $price->currency, 'the shop\'s currency');
        self::assertSame(2750, $offer->compareAt?->amount);
        self::assertTrue($offer->taxIncluded, 'the shop\'s tax setting');
        self::assertSame('https://cave.example/products/margaux-chateau-exemple?variant=72', $offer->url);
        self::assertSame(12, $v2018->stock->quantity);
        self::assertSame('gid://shopify/InventoryItem/82', $v2018->stock->item);
        self::assertSame('https://cdn.shopify.com/s/files/margaux-2018.jpg', $v2018->image?->url);
        self::assertSame(2400, $product->price()?->amount, 'the lowest');
    }

    public function testACurrencyOptionWinsOverTheShops(): void
    {
        $product = $this->gateway(['currency' => 'chf'])->fetchProducts()->products[0];
        self::assertSame('CHF', $product->price()?->currency);
    }

    public function testAProductIsFoundByIdByBareIdAndByItsPage(): void
    {
        $gateway = $this->gateway();

        self::assertSame('Margaux', $gateway->fetchProduct('gid://shopify/Product/9001')?->title);
        self::assertSame('Margaux', $gateway->fetchProduct('9001')?->title);
        self::assertSame('gid://shopify/Product/9001', $this->queries[1]->get('variables.id'), 'a bare id made a gid');
        self::assertSame('Margaux', $gateway->fetchProduct('https://example.myshopify.com/admin/products/9001')?->title);

        $byPage = $gateway->fetchProduct('https://cave.example/collections/bordeaux/products/margaux-chateau-exemple?variant=72');
        self::assertSame('handle:"margaux-chateau-exemple"', $this->queries[3]->get('variables.query'));
        self::assertSame('gid://shopify/Product/9001', $byPage?->reference, 'the exact handle among the loose matches');

        self::assertNull($gateway->fetchProduct('gid://shopify/Product/404'));
        self::assertNull($gateway->fetchProduct('https://cave.example/products/nothing'));
    }

    public function testTheInventoryIsReadByVariantOrWhole(): void
    {
        $gateway = $this->gateway();

        $stocks = $gateway->fetchInventory(['gid://shopify/ProductVariant/72', '73', 'gid://shopify/ProductVariant/404']);
        self::assertSame(['gid://shopify/ProductVariant/72', 'gid://shopify/ProductVariant/73', 'gid://shopify/ProductVariant/404'], $this->queries[0]->get('variables.ids'));
        self::assertCount(2, $stocks, 'an unknown variant is left out');
        self::assertSame(12, $stocks[0]->quantity);
        self::assertSame('MGX18', $stocks[0]->sku);
        self::assertFalse($stocks[1]->tracked);
        self::assertNull($stocks[1]->quantity, 'not counted: as many as one likes');
        self::assertTrue($stocks[1]->available());

        $all = $gateway->fetchInventory();
        self::assertSame(['gid://shopify/ProductVariant/71', 'gid://shopify/ProductVariant/72'], array_map(static fn ($s) => $s->reference, $all), 'every page');
        self::assertSame('c1', $this->queries[2]->get('variables.after'));
    }

    public function testBothShapesOfAProductGiveTheSameProduct(): void
    {
        $graphql = Products::fromGraphQL(self::fixture('product.graphql.json'), 'EUR');
        $rest = Products::fromRest(self::fixture('product.webhook.json'), 'EUR');

        foreach (['reference', 'title', 'description', 'handle', 'status', 'tags'] as $field) {
            self::assertEquals($graphql->{$field}, $rest->{$field}, $field);
        }
        self::assertEquals($graphql->updatedAt, $rest->updatedAt);
        self::assertSame([], $graphql->options, '"Title: Default Title" is no option');
        self::assertSame([], $rest->options);
        self::assertSame($graphql->media[0]->url, $rest->media[0]->url);
        self::assertCount(1, $rest->variants);
        $g = $graphql->variants[0];
        $r = $rest->variants[0];
        self::assertNull($g->title, '"Default Title" is no title');
        self::assertNull($r->title);
        self::assertSame([], $r->options);
        foreach (['reference', 'sku', 'barcode'] as $field) {
            self::assertSame($g->{$field}, $r->{$field}, $field);
        }
        self::assertEquals($g->stock, $r->stock);
        self::assertNotNull($r->stock);
        self::assertSame(17, $r->stock->quantity);
        self::assertSame('gid://shopify/InventoryItem/99001', $r->stock->item);
        $graphqlOffer = $g->offer();
        $restOffer = $r->offer();
        self::assertNotNull($graphqlOffer);
        self::assertNotNull($restOffer);
        self::assertEquals($graphqlOffer->price, $restOffer->price);
        self::assertEquals($graphqlOffer->compareAt, $restOffer->compareAt);
        self::assertSame($graphqlOffer->available, $restOffer->available);
    }

    public function testUntrackedInventoryIsANullQuantity(): void
    {
        $payload = self::fixture('product.webhook.json');
        $variants = Data::maps($payload['variants'] ?? []);
        $variants[0]['inventory_management'] = null;
        $payload['variants'] = $variants;
        $variant = Products::fromRest($payload, 'EUR')->variants[0];
        self::assertNotNull($variant->stock);
        self::assertFalse($variant->stock->tracked);
        self::assertNull($variant->stock->quantity);
        self::assertTrue($variant->offer()?->available);

        $node = self::fixture('product.graphql.json');
        $variants = Data::of($node)->fields('variants');
        $nodes = Data::maps($variants['nodes'] ?? []);
        $nodes[0]['inventoryItem'] = ['tracked' => false] + Data::of($nodes[0])->fields('inventoryItem');
        $variants['nodes'] = $nodes;
        $node['variants'] = $variants;
        self::assertNull(Products::fromGraphQL($node, 'EUR')->variants[0]->stock?->quantity);
    }

    public function testTheProductWebhooksCarryTheCatalogue(): void
    {
        $gateway = $this->gateway();
        $headers = static fn (string $body, string $topic) => ['X-Shopify-Hmac-Sha256' => Hmac::sign($body, self::SECRET), 'X-Shopify-Topic' => $topic, 'X-Shopify-Shop-Domain' => 'example.myshopify.com', 'X-Shopify-Webhook-Id' => 'wh-'.$topic];

        $body = (string) file_get_contents(__DIR__.'/Fixtures/product.webhook.json');
        $updated = $gateway->notify($body, $headers($body, 'products/update'));
        self::assertTrue($updated->isCatalogue());
        self::assertSame('gid://shopify/Product/8472913847', $updated->reference);
        self::assertNotNull($updated->product);
        self::assertSame('Enamel Mug', $updated->product->title);
        $price = $updated->product->price();
        self::assertNotNull($price);
        self::assertSame(1250, $price->amount);
        self::assertSame('CHF', $price->currency, 'the shop\'s currency, asked once');
        self::assertSame(['kitchen', 'gift'], $updated->product->tags);
        $gateway->notify($body, $headers($body, 'products/create'));
        self::assertCount(1, array_filter($this->queries, static fn (Data $q) => str_contains($q->text('query'), 'query Shop')));

        $deleted = $gateway->notify($body = '{"id":8472913847}', $headers($body, 'products/delete'));
        self::assertTrue($deleted->isCatalogue());
        self::assertNull($deleted->product);
        self::assertSame('gid://shopify/Product/8472913847', $deleted->reference);

        $body = (string) file_get_contents(__DIR__.'/Fixtures/inventory_levels_update.webhook.json');
        $level = $gateway->notify($body, $headers($body, 'inventory_levels/update'));
        self::assertTrue($level->isCatalogue());
        self::assertCount(1, $level->stocks);
        self::assertSame(4, $level->stocks[0]->quantity);
        self::assertSame('gid://shopify/InventoryItem/99001', $level->stocks[0]->item);
        self::assertSame('gid://shopify/Location/7700', $level->stocks[0]->location);
    }
}
