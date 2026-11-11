<?php

namespace Omnitrade\Shopify\Api;

use Omnisense\Contracts\Policy\Backoff;
use Omnitrade\Exception\RateLimitedException;
use Omnitrade\Http\Redaction;
use Omnitrade\Http\Throttle;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * POST a GraphQL document, get the `data` back or a ShopifyApiException.
 *
 * Knows nothing about orders or products: every document is passed in. It
 * knows two things about Shopify: the Admin API is a leaky bucket costed per
 * query (extensions.cost.throttleStatus), waited for when it runs low; and a
 * query answered THROTTLED, 429 or 5xx is retried with backoff. A mutation is
 * never sent twice: a 5xx may come after it ran (a second draft order), and
 * Shopify documents no idempotency for it; THROTTLED or 429, which Shopify
 * answers before running it, is a RateLimitedException with the wait, the
 * caller's to try again. Given the gateway's Backoff, no document is sent
 * while a wait the shop asked for lasts. The access token never reaches a
 * log or an exception: not its message, its previous (a copy of the HTTP
 * client's, Redaction::copy()) nor its trace's arguments.
 */
class GraphQL
{
    /** Wait when fewer than this many points are left in the bucket. */
    private const COST_FLOOR = 200;
    private const MAX_ATTEMPTS = 3;

    /** @var callable(float): void */
    private $sleep;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly ?LoggerInterface $logger = null,
        ?callable $sleep = null,
        /** The first retry's wait in seconds, doubled at each attempt; a 429's Retry-After prevails. */
        private readonly float $retryDelay = 1.0,
        /** The waits the shop asks for (omnisense/contracts' Backoff), shared by the gateway's calls */
        private readonly ?Backoff $limits = null,
    ) {
        // Injected so a test can assert the wait instead of serving it.
        $this->sleep = $sleep ?? static function (float $seconds): void {
            usleep((int) ($seconds * 1_000_000));
        };
    }

    /**
     * @param array<string, mixed> $variables
     *
     * @return array<string, mixed> the `data` object
     *
     * @throws ShopifyApiException
     */
    public function query(string $url, array $headers, string $document, array $variables = [], int $timeout = 15): array
    {
        $mutation = 1 === preg_match('~^\s*mutation\b~', $document);
        $attempt = 0;
        while (true) {
            ++$attempt;
            Throttle::before($this->limits);
            $options = ['headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'] + $headers, 'timeout' => $timeout];
            try {
                $options['body'] = json_encode(['query' => $document, 'variables' => (object) $variables], \JSON_THROW_ON_ERROR);
                $response = $this->http->request('POST', $url, $options);
                $status = $response->getStatusCode();
                $body = $response->getContent(false);
            } catch (HttpExceptionInterface|\JsonException $e) {
                $secrets = Redaction::secrets($url, $options);

                throw Redaction::withoutArguments(new ShopifyApiException('Shopify request failed: '.Redaction::text($e->getMessage(), $secrets), [], Redaction::copy($e, $secrets)));
            }
            if ($mutation && 429 === $status) {
                Throttle::after($this->limits, 'shopify', 429, $response->getHeaders(false), 'Shopify answered 429: the mutation was not run, nor sent again.');
            }
            if ($mutation && $status >= 500) {
                throw Redaction::withoutArguments(new ShopifyApiException(sprintf('Shopify returned HTTP %d to a mutation: not sent again, it may have run.', $status)));
            }
            // A query's 429 and 5xx are worth another go; 401/403/404 never are.
            if (429 === $status || $status >= 500) {
                if ($attempt < self::MAX_ATTEMPTS) {
                    $retryAfter = $response->getHeaders(false)['retry-after'][0] ?? null;
                    ($this->sleep)(429 === $status && is_numeric($retryAfter) ? min((float) $retryAfter, 60.0) : $this->backoff($attempt));
                    continue;
                }
                throw Redaction::withoutArguments(new ShopifyApiException(sprintf('Shopify returned HTTP %d after %d attempts.', $status, $attempt)));
            }
            if ($status >= 400) {
                throw Redaction::withoutArguments(new ShopifyApiException(sprintf('Shopify returned HTTP %d.', $status)));
            }
            $payload = json_decode($body, true);
            if (!\is_array($payload)) {
                throw Redaction::withoutArguments(new ShopifyApiException('Shopify returned a body that is not JSON.'));
            }
            if (!empty($payload['errors'])) {
                // A list of errors, or one message (the shape of Shopify's REST errors: {"errors": "..."}).
                $errors = \is_array($payload['errors']) ? $payload['errors'] : [['message' => (string) $payload['errors']]];
                if ($mutation && $this->isThrottled($errors)) {
                    // The requested cost exceeded what the bucket held: Shopify ran nothing.
                    throw Redaction::withoutArguments(new RateLimitedException('shopify', 'Shopify throttled the mutation (THROTTLED): it was not run, nor sent again.', (int) ceil($this->backoff(1))));
                }
                if ($this->isThrottled($errors) && $attempt < self::MAX_ATTEMPTS) {
                    ($this->sleep)($this->backoff($attempt));
                    continue;
                }
                throw Redaction::withoutArguments(ShopifyApiException::fromErrors('Shopify rejected the query', $errors));
            }
            $this->respectBucket($payload);

            return $payload['data'] ?? [];
        }
    }

    /** Raise a mutation's own userErrors: Shopify answers 200 with them when it refuses on business grounds. */
    public function assertNoUserErrors(string $what, array $result): array
    {
        if (!empty($result['userErrors'])) {
            throw ShopifyApiException::fromErrors($what, $result['userErrors']);
        }

        return $result;
    }

    private function isThrottled(array $errors): bool
    {
        foreach ($errors as $error) {
            if ('THROTTLED' === ($error['extensions']['code'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /** Wait for the bucket to refill if this query drained it. */
    private function respectBucket(array $payload): void
    {
        $status = $payload['extensions']['cost']['throttleStatus'] ?? null;
        if (!\is_array($status)) {
            return;
        }
        $available = (float) ($status['currentlyAvailable'] ?? \PHP_INT_MAX);
        $restoreRate = (float) ($status['restoreRate'] ?? 0);
        if ($available >= self::COST_FLOOR || $restoreRate <= 0) {
            return;
        }
        $wait = ceil((self::COST_FLOOR - $available) / $restoreRate);
        $this->logger?->info('Shopify cost bucket low, waiting {seconds}s', ['seconds' => $wait, 'available' => $available]);
        ($this->sleep)($wait);
    }

    private function backoff(int $attempt): float
    {
        return $this->retryDelay * 2 ** ($attempt - 1);
    }
}
