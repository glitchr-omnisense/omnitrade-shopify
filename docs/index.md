# omnitrade/shopify

Shopify for [omniscient/omnitrade](https://github.com/glitchr-studio/omnitrade): a sale paid on the
shop's invoice page (a draft order with the shop's prices or the application's), the shop's
orders, its catalogue (products, variants, inventory) and its webhooks - over the Admin GraphQL
API, its cost bucket respected. Every call is one `POST` of a GraphQL document to

```
https://<shop_domain>/admin/api/<api_version>/graphql.json
```

with the `X-Shopify-Access-Token` header (`api_version` `2026-07` by default). Shopify has no
sandbox address: a development store is the test shop.

| Operation | GraphQL |
|---|---|
| purchase | `draftOrderCreate` mutation |
| fetch | `draftOrder(id:)`, with the order it became |
| fetch order | `order(id:)` |
| products | `products(first:, after:, query:, sortKey: UPDATED_AT)` with `shop { currencyCode taxesIncluded }` |
| product | `product(id:)`, or `products(query: "handle:...")` |
| inventory | `nodes(ids:)` on the variants, or `productVariants` page by page |
| notify | none (the HMAC is checked locally); `shop { currencyCode taxesIncluded }` once for a product webhook's prices |

## Installation

```sh
composer require omnitrade/shopify
```

Requires PHP 8.2, `omniscient/omnitrade` (1.x), `symfony/http-client` (6.4, 7 or 8) and `psr/log`.
In a Symfony application with `Omnitrade\Bridge\Symfony\OmnitradeBundle`, the factory is
registered as soon as the package is installed.

```php
$gateway = (new ShopifyGatewayFactory($http))->create(['shop_domain' => 'example.myshopify.com', 'admin_token' => '...']);   // $http: the application's HTTP client; none given, the factory makes its own
```

## Credentials

- A **custom app** in the shop's admin (Settings → Apps → Develop apps) with the Admin API scopes
  `write_draft_orders` and `read_orders` for the sales, `read_products` and `read_inventory` for
  the catalogue; its **Admin API access token** (`shpat_...`) is `admin_token`.
- The app's **API secret** is `webhook_secret`: Shopify signs the app's webhooks with it.
  Subscribe the app to `orders/paid`, `orders/cancelled`, `refunds/create`, and to
  `products/create`, `products/update`, `products/delete`, `inventory_levels/update` for the
  catalogue.

## Options

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `shop_domain` | string | - | yes | `example.myshopify.com` |
| `admin_token` | string | - | yes | the Admin API access token |
| `webhook_secret` | ?string | `null` | for `notify()` | the app's API secret |
| `storefront_token` | ?string | `null` | no | a Storefront API token: the package builds a Storefront client with it, but no action calls it |
| `api_version` | string | `2026-07` | no | the Admin API version |
| `timeout` | int | `15` | no | seconds |
| `draft_order_tags` | list of strings | `[]` | no | tags put on the draft orders `purchase()` creates |
| `currency` | ?string | `null` | no | the currency the catalogue's prices are read in; `null`: the shop's, read with them. A label: nothing is converted |
| `retry_delay` | float | `1` | no | seconds before retrying a 429 or a 5xx, doubled at each attempt (3 attempts); a 429's `Retry-After` prevails, capped at 60 seconds |

```yaml
omnitrade:
    gateways:
        shopify:
            factory: shopify
            options:
                shop_domain: '%env(SHOPIFY_SHOP_DOMAIN)%'       # example.myshopify.com
                admin_token: '%env(SHOPIFY_ADMIN_TOKEN)%'       # shpat_..., a custom app's Admin API token
                webhook_secret: '%env(SHOPIFY_WEBHOOK_SECRET)%' # the app's API secret, for notify()
                draft_order_tags: [omnitrade]
                currency: null                                  # the catalogue's currency; null: the shop's
                retry_delay: 1                                  # seconds before retrying a 429 / 5xx, doubled each time (3 attempts); a 429's Retry-After prevails
```

## Capabilities

| Request | Supported |
|---|---|
| `Purchase` | yes (a draft order, paid on its invoice page) |
| `Authorize`, `Capture`, `VoidAuthorization`, `Refund` | no: Shopify's admin does those on its orders |
| `FetchTransaction` | yes (the draft order and what it became) |
| `GetPaymentMethods` | no |
| `Notify` | yes |
| `FetchOrder` | yes |
| `FetchProducts`, `FetchProduct`, `FetchInventory` | yes |
| `AffiliateLink`, connected accounts, subscriptions | no |

## Behaviour

**Calls, limits and retries.** A 429 or a 5xx is retried, 3 attempts in all: after `Retry-After`
(capped at 60 seconds) for a 429 that has one, else after `retry_delay` seconds doubled at each
attempt; then a `ProviderException` (`Shopify returned HTTP 503 after 3 attempts.`). An answer whose
GraphQL `errors` say `THROTTLED` is retried the same way. After each answer, when the cost bucket
(`extensions.cost.throttleStatus`) has fewer than 200 points left, the client waits until it has
refilled to 200 (`ceil((200 - currentlyAvailable) / restoreRate)` seconds). 401, 403 and 404 are
never retried. The access token never reaches a message.

**Purchase.** A draft order, answered PENDING with its `invoiceUrl` as `redirectUrl`, its gid as
`reference` (`gid://shopify/DraftOrder/...`), its `totalPrice` as the amount and its `name` and the
shop in `metadata`. Shopify's checkout never redirects back: `returnUrl` and `cancelUrl` are not
used; the sale is known by `fetch()` or the `orders/paid` webhook. The `DraftOrderInput`:

| Payment | Draft order |
|---|---|
| `lines`, when they add up | `lineItems`: a line whose `reference` is a variant gid (`gid://shopify/ProductVariant/...`) names the variant (`variantId`, so Shopify counts its stock), any other is a custom line (`title`, `requiresShipping` from `physical`, `sku`); each at the line's unit price (`originalUnitPriceWithCurrency`) |
| no lines adding up | one custom line of the amount, titled by the description (else the reference), not shipped |
| `shipping`, `discount` (lines adding up) | `shippingLine` (`Shipping`), `appliedDiscount` (`Discount`, fixed amount) |
| `amount->currency` | `presentmentCurrencyCode` |
| `description` | `note` (else `Order <reference>`) |
| `reference` | the custom attribute `omnitrade_reference` |
| `metadata` | more custom attributes |
| `customer->email` | `email` |
| the `draft_order_tags` option | `tags` |

Not sent: `idempotencyKey`, `method`, `notice`, `locale`, the customer's other fields. A mutation's
`userErrors` are a `ProviderException` listing them (`draftOrderCreate: lineItems.0.quantity: ...`).

**Fetch.** The draft order and the order it became: completed and the order `PAID` (or no status)
is PAID; completed and `REFUNDED`, `PARTIALLY_REFUNDED`, `VOIDED` are REFUNDED,
PARTIALLY_REFUNDED, CANCELLED; a draft order Shopify no longer has (deleted) is CANCELLED with
`message: deleted`; anything else PENDING with the invoice URL. `metadata` holds the draft's
`name`, and the order's gid (`order`) and name (`order_name`) once there is one.

**Fetch order.** A shop order by its gid or bare id: `number` is its `name` (`#1042`), `status`
from `displayFinancialStatus` (`PAID`; `PARTIALLY_REFUNDED`; `REFUNDED`; `VOIDED` CANCELLED;
`AUTHORIZED`; else PENDING), `total` its `totalPriceSet.shopMoney`, the customer from its email,
`customer.displayName` and shipping address, its first 100 line items (title, unit price,
quantity, SKU, variant gid as `reference`), `financialStatus` and `fulfillmentStatus` as Shopify
names them, `createdAt`. An unknown order is a `ProviderException` (`Shopify knows no order ...`).

**Notify.** `X-Shopify-Hmac-Sha256` is checked first (base64 of the HMAC-SHA256 of the raw body
with `webhook_secret`; there is no timestamp in the signature), then `X-Shopify-Shop-Domain`, when
sent, must be the configured shop (`The notification is another shop's (...)`). The topic is
`X-Shopify-Topic`, the event's `id` `X-Shopify-Webhook-Id` (keep it: Shopify resends a delivery
with the same id).

| Topic | `status` | `reference` | Carries |
|---|---|---|---|
| `orders/paid` | PAID | the draft order the sale began with (`draft_order_id`), else the order's gid | |
| `orders/cancelled` | CANCELLED | the same | |
| other `orders/*` (`orders/fulfilled`...) | none | the same | |
| `refunds/create` | REFUNDED (a partial refund too) | the refund's gid | |
| `products/create`, `products/update` | none | the product's gid | `product` |
| `products/delete` | none | the product's gid | no product |
| `inventory_levels/update` | none | the inventory item's gid | `stocks`: one level at one location |

`raw` holds the topic, the shop, the merchant's reference (the note attribute
`omnitrade_reference`), the order's gid and the payload. The catalogue's webhooks:
[Shopify's catalogue](catalogue.md#shopifys-catalogue).

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnitrade\Model\Customer;
use Omnitrade\Model\Line;
use Omnitrade\Model\Money;
use Omnitrade\Model\Payment;
use Omnitrade\Shopify\ShopifyGatewayFactory;
use Symfony\Component\HttpClient\HttpClient;

$shopify = (new ShopifyGatewayFactory(HttpClient::create()))->create([
    'shop_domain' => getenv('SHOPIFY_SHOP_DOMAIN'),
    'admin_token' => getenv('SHOPIFY_ADMIN_TOKEN'),
    'webhook_secret' => getenv('SHOPIFY_WEBHOOK_SECRET'),
    'draft_order_tags' => ['site'],
]);

$transaction = $shopify->purchase(new Payment(
    amount: Money::of(2750, 'EUR'),
    reference: 'ORDER-1042',
    customer: new Customer(email: 'camille@example.org'),
    lines: [new Line('Margaux 2019', Money::of(1200, 'EUR'), 2, reference: 'gid://shopify/ProductVariant/7')],
    shipping: Money::of(350, 'EUR'),
));
header('Location: '.$transaction->redirectUrl);   // Shopify's invoice page

// later: the webhook, or
$shopify->fetch($transaction->reference)->isPaid();
$order = $shopify->fetchOrder($shopify->fetch($transaction->reference)->metadata['order']);
```

In the core's Docker harness: `docker compose run --rm omnitrade purchase shopify --line
"Margaux=1200x2@gid://shopify/ProductVariant/7"`, `catalogue shopify --since='-1 day'`.

## Errors

Every failure is an `Omnitrade\Shopify\Api\ShopifyApiException`, a `ProviderException` whose
`provider` is `shopify` and whose `errors` holds what Shopify said; `providerCode` is never set:

- `Shopify returned HTTP <status>.` (401, 403, 404...), `Shopify returned HTTP <status> after 3
  attempts.` (429, 5xx), `Shopify returned a body that is not JSON.`, `Shopify request failed: ...`
  (a network failure, the exception as `previous`);
- GraphQL `errors`: `Shopify rejected the query: <message>; ...`; a mutation's `userErrors`:
  `draftOrderCreate: <field>: <message>`;
- `Shopify created no draft order with an invoice URL.`, `Shopify knows no order ...`;
- a webhook: `InvalidNotificationException` without `webhook_secret`, for a signature that does
  not match or another shop's; a body that is not JSON is a `ProviderException`.

A missing `shop_domain` or `admin_token` is an `InvalidConfigException` when the gateway is built;
`Authorize`, `Capture`, `VoidAuthorization`, `Refund`, `GetPaymentMethods`, `AffiliateLink`,
connected accounts and subscriptions raise `RequestNotSupportedException` before any call.
`fetchProduct()` of a product the shop does not have is `null`.

## Verified, and not

Tested offline, 24 tests on answers shaped as the Admin GraphQL API documents them:
`Tests/ShopifyGatewayTest.php` (a draft order paid on its invoice page, fetched as what became of
it, a shop order, a webhook's HMAC and shop), `Tests/ShopifyCatalogueTest.php` (the catalogue page by page, a product mapped whole, the `currency`
option winning over the shop's, a product found by id, bare id and page, the inventory by variant
or whole, the REST and GraphQL shapes giving the same product, untracked inventory as a `null`
quantity, the product and inventory webhooks) and `Tests/ShopifyContractTest.php`,
the family's conformance suite. The suite found a 429 retried after a fixed backoff whatever
`Retry-After` said; `Retry-After` now prevails, and `retry_delay` sets the backoff (0 in tests).

Not verified: no call was made to a Shopify shop, development store or live; the scopes listed are
those the queries need, not checked against a real app.

## Responsibility

An independent, unofficial client, not affiliated with, endorsed by or sponsored by Shopify Inc.
Whoever runs it complies with Shopify's terms ([Shopify API License and Terms of
Use](https://www.shopify.com/legal/api-terms)) and obtains what they require: see the README's
Responsibility section.
