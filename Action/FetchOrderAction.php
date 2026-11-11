<?php

namespace Omnitrade\Shopify\Action;

use Omnitrade\Action\ActionInterface;
use Omnitrade\Action\ApiAwareInterface;
use Omnitrade\Action\ApiAwareTrait;
use Omnitrade\Exception\ProviderException;
use Omnitrade\Model\Customer;
use Omnitrade\Model\Line;
use Omnitrade\Model\Money;
use Omnitrade\Model\PlatformOrder;
use Omnitrade\Model\Status;
use Omnitrade\Request\FetchOrder;
use Omnitrade\Request\Request;
use Omnitrade\Shopify\Api;
use Omnitrade\Shopify\Api\Endpoint;
use Omnitrade\Shopify\Data;
use Omnitrade\Shopify\Documents;

/** A Shopify order, by its gid or its bare id. */
final class FetchOrderAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof FetchOrder;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof FetchOrder);
        $data = $this->api->admin->query(Documents::ORDER, ['id' => Endpoint::gid('Order', $request->reference)]);
        $order = $data['order'] ?? null;
        if (!\is_array($order)) {
            throw new ProviderException('shopify', sprintf('Shopify knows no order %s.', $request->reference));
        }
        $request->setResult(self::order(Data::map($order)));
    }

    /** @param array<string, mixed> $order as Documents::ORDER returns it */
    public static function order(array $order): PlatformOrder
    {
        $read = Data::of($order);
        $total = $read->data('totalPriceSet.shopMoney');
        $currency = strtoupper(Data::cast($total->get('currencyCode') ?? 'EUR'));
        $financial = strtoupper($read->text('displayFinancialStatus'));
        $address = $read->data('shippingAddress');
        $lines = [];
        foreach ($read->each('lineItems.nodes') as $node) {
            $unit = $node->data('originalUnitPriceSet.shopMoney');
            $lines[] = new Line(
                $node->text('title'),
                Money::fromDecimal(Data::cast($unit->get('amount') ?? '0'), Data::cast($unit->get('currencyCode') ?? $currency)),
                Data::integer($node->get('quantity') ?? 1),
                $node->textOrNull('sku'),
                reference: $node->textOrNull('variant.id'),
            );
        }

        return new PlatformOrder(
            provider: 'shopify',
            reference: $read->text('id'),
            number: $read->textOrNull('name'),
            status: match ($financial) {
                'PAID' => Status::PAID,
                'PARTIALLY_REFUNDED' => Status::PARTIALLY_REFUNDED,
                'REFUNDED' => Status::REFUNDED,
                'VOIDED' => Status::CANCELLED,
                'AUTHORIZED' => Status::AUTHORIZED,
                default => Status::PENDING,
            },
            total: Money::fromDecimal(Data::cast($total->get('amount') ?? '0'), $currency),
            customer: new Customer(
                email: Data::castOrNull($read->get('email') ?? $read->get('customer.email')),
                name: $read->textOrNull('customer.displayName'),
                phone: $address->textOrNull('phone'),
                street: array_values(array_filter([$address->textOrNull('address1'), $address->textOrNull('address2')], static fn (?string $line) => null !== $line && '' !== $line && '0' !== $line)),
                postalCode: $address->textOrNull('zip'),
                city: $address->textOrNull('city'),
                country: $address->textOrNull('countryCodeV2'),
            ),
            lines: $lines,
            financialStatus: $financial ?: null,
            fulfillmentStatus: $read->textOrNull('displayFulfillmentStatus'),
            createdAt: $read->has('createdAt') ? new \DateTimeImmutable($read->text('createdAt')) : null,
            raw: $order,
        );
    }
}
