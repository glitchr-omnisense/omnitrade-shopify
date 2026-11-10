<?php

namespace Omnitrade\Shopify\Tests;

use Omnisense\Contracts\Policy\PolicyExport;
use Omnisense\Contracts\Policy\UsagePolicy;
use Omnitrade\Exception\ProviderException;
use Omnitrade\Exception\RateLimitedException;
use Omnitrade\Model\Money;
use Omnitrade\Model\Payment;
use Omnitrade\Shopify\ShopifyGatewayFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Shopify's usage policy (the stricter form the schema can say, the real
 * cost-based rule in its note) and a draft order's creation, a mutation, never
 * sent twice: a 5xx may come after it ran, THROTTLED and 429 come before.
 * Queries are still retried.
 */
final class ShopifyPolicyTest extends TestCase
{
    private int $requests = 0;

    public function testThePolicyIsDeclaredAsData(): void
    {
        $declared = (new PolicyExport())->folders(\dirname(__DIR__))->entries();
        self::assertCount(1, $declared, 'composer.json\'s extra.omnisense.policy names it');
        self::assertSame(['omnitrade/shopify', 'policy.json'], [$declared[0]['package'], $declared[0]['file']]);
        self::assertArrayNotHasKey('error', $declared[0], 'it reads as the schema says');
        $policy = UsagePolicy::fromFile(__DIR__.'/../policy.json');

        self::assertSame('shopify', $policy->provider);
        self::assertSame(2, $policy->limitsFor('purchase')[0]->max);
        self::assertStringContainsString('cost-based', (string) $policy->limitsFor('purchase')[0]->note);
    }

    /** @return iterable<string, array{MockResponse, class-string<ProviderException>}> */
    public static function refusals(): iterable
    {
        yield 'a 500, which may come after the draft order was made' => [new MockResponse('{"errors":"Internal error"}', ['http_code' => 500]), ProviderException::class];
        yield 'a 503' => [new MockResponse('<html>down</html>', ['http_code' => 503]), ProviderException::class];
        yield 'a 429' => [new MockResponse('{"errors":"Throttled"}', ['http_code' => 429, 'response_headers' => ['retry-after' => '2']]), RateLimitedException::class];
        yield 'THROTTLED' => [new MockResponse('{"errors":[{"message":"Throttled","extensions":{"code":"THROTTLED"}}]}'), RateLimitedException::class];
    }

    /** @param class-string<ProviderException> $exception */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function testADraftOrderIsNeverSentTwice(MockResponse $answer, string $exception): void
    {
        try {
            $this->gateway($answer, $answer)->purchase(new Payment(Money::of(1250, 'EUR'), 'B-42', 'Two nights'));
            self::fail('refused');
        } catch (ProviderException $e) {
            self::assertInstanceOf($exception, $e);
        }
        self::assertSame(1, $this->requests, 'one mutation sent');
    }

    public function testAQueryIsStillRetried(): void
    {
        $order = new MockResponse('{"data":{"draftOrder":{"id":"gid://shopify/DraftOrder/7","name":"#D7","status":"OPEN","invoiceUrl":"https://example.myshopify.com/invoices/abc","order":null}}}');

        $this->gateway(new MockResponse('{}', ['http_code' => 503]), $order)->fetch('gid://shopify/DraftOrder/7');
        self::assertSame(2, $this->requests);
    }

    private function gateway(MockResponse ...$answers): \Omnitrade\GatewayInterface
    {
        $http = new MockHttpClient(function () use (&$answers): MockResponse {
            ++$this->requests;

            return array_shift($answers) ?? new MockResponse('{}', ['http_code' => 500]);
        });

        return (new ShopifyGatewayFactory($http))->create(['shop_domain' => 'example.myshopify.com', 'admin_token' => 'shpat_x', 'retry_delay' => 0]);
    }
}
