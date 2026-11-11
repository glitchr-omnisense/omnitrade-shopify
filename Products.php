<?php

namespace Omnitrade\Shopify;

use Omnitrade\Model\Media;
use Omnitrade\Model\Money;
use Omnitrade\Model\Offer;
use Omnitrade\Model\Option;
use Omnitrade\Model\Product;
use Omnitrade\Model\ProductVariant;
use Omnitrade\Model\Stock;
use Omnitrade\Shopify\Api\Endpoint;

/**
 * Shopify's two product shapes, read as one Product.
 *
 * Shopify sends two different shapes for the same product and does not warn
 * you:
 *
 *   fromGraphQL()  what the products(...) and product(id:) queries return -
 *                  "id" is a gid, variants are under variants.nodes, prices
 *                  are decimal strings, the fields are camelCase.
 *   fromRest()     what the products/create|update webhook POSTs - "id" is a
 *                  bare number, "variants" is a flat array, the fields are
 *                  snake_case, the tags one comma-separated string.
 *
 * The webhook payload is REST-shaped even when the subscription was created
 * through GraphQL, so both converge here, and the tests assert they give the
 * same Product for the same product. Pure: arrays in, models out.
 */
final class Products
{
    /**
     * @param array<string, mixed> $node     a products(...).nodes[] entry, or product(id:)
     * @param bool|null            $taxIncluded the shop's taxesIncluded
     */
    public static function fromGraphQL(array $node, string $currency, ?bool $taxIncluded = null): Product
    {
        $read = Data::of($node);
        $url = self::blankToNull($node['onlineStoreUrl'] ?? null);
        $nodes = $read->each('variants.nodes');
        $variants = [];
        foreach ($nodes as $variant) {
            $tracked = (bool) ($variant->get('inventoryItem.tracked') ?? false);
            $reference = $variant->text('id');
            $options = [];
            foreach ($variant->each('selectedOptions') as $selected) {
                if ($selected->has('name')) {
                    $options[$selected->text('name')] = $selected->text('value');
                }
            }
            $weight = $variant->get('inventoryItem.measurement.weight');
            $image = \is_array($variant->get('image')) && !empty($variant->get('image.url'))
                ? new Media($variant->text('image.url'), self::blankToNull($variant->get('image.altText')), Media::IMAGE, self::int($variant->get('image.width')), self::int($variant->get('image.height')))
                : null;

            $variants[] = new ProductVariant(
                reference: $reference,
                title: self::variantTitle($variant->get('title')),
                sku: self::blankToNull($variant->get('sku')),
                barcode: self::blankToNull($variant->get('barcode')),
                offers: self::offers($variant->get('price'), $variant->get('compareAtPrice'), $currency, (bool) ($variant->get('availableForSale') ?? false), self::variantUrl($url, $reference, \count($nodes)), $taxIncluded),
                options: self::withoutDefault($options),
                // Untracked inventory is "as many as you like": a null quantity.
                stock: new Stock($reference, $tracked ? $variant->number('inventoryQuantity') : null, $tracked, self::blankToNull($variant->get('sku')), item: self::blankToNull($variant->get('inventoryItem.id'))),
                image: $image,
                weight: \is_array($weight) ? self::grams($weight['value'] ?? null, Data::cast($weight['unit'] ?? 'GRAMS')) : null,
                raw: $variant->toArray(),
            );
        }

        $options = [];
        foreach ($read->each('options') as $option) {
            $values = $option->has('optionValues')
                ? array_map(static fn (Data $v) => $v->text('name'), $option->each('optionValues'))
                : Data::texts($option->get('values') ?? []);
            $options[] = new Option($option->text('name'), array_values(array_filter($values, static fn (string $v) => '' !== $v)));
        }

        $categories = [$read->text('productType')];
        foreach ($read->each('collections.nodes') as $collection) {
            $categories[] = $collection->text('title');
        }

        $attributes = [];
        foreach ($read->each('metafields.nodes') as $metafield) {
            if ($metafield->has('namespace') && $metafield->has('key')) {
                $attributes[$metafield->text('namespace').'.'.$metafield->text('key')] = $metafield->text('value');
            }
        }

        $media = [];
        foreach ($read->each('media.nodes') as $item) {
            if (null !== $m = self::media($item)) {
                $media[] = $m;
            }
        }
        if (!$media && $read->has('featuredMedia.image.url')) {
            $media[] = new Media($read->text('featuredMedia.image.url'), self::blankToNull($read->get('featuredMedia.image.altText')), reference: Data::castOrNull($read->get('featuredMedia.id')));
        }

        return new Product(
            provider: 'shopify',
            reference: $read->text('id'),
            title: $read->text('title'),
            description: self::blankToNull($node['descriptionHtml'] ?? null),
            handle: self::blankToNull($node['handle'] ?? null),
            brand: self::blankToNull($node['vendor'] ?? null),
            url: $url,
            status: self::status($node['status'] ?? null),
            tags: self::tags($node['tags'] ?? []),
            categories: self::unique($categories),
            attributes: $attributes,
            options: self::onlyRealOptions($options),
            variants: $variants,
            media: $media,
            updatedAt: self::date($node['updatedAt'] ?? null),
            raw: $node,
        );
    }

    /**
     * @param array<string, mixed> $payload a products/create|update webhook body
     */
    public static function fromRest(array $payload, string $currency, ?bool $taxIncluded = null): Product
    {
        $read = Data::of($payload);
        $status = self::status($payload['status'] ?? null);
        $optionNames = [];
        $options = [];
        foreach ($read->each('options') as $i => $option) {
            $position = Data::integer($option->get('position') ?? $i + 1);
            $optionNames[$position] = $option->text('name');
            $options[] = new Option($option->text('name'), Data::texts($option->get('values') ?? []));
        }

        $imagesById = [];
        $media = [];
        foreach ($read->each('images') as $image) {
            if (empty($image->get('src'))) {
                continue;
            }
            $m = new Media($image->text('src'), self::blankToNull($image->get('alt')), Media::IMAGE, self::int($image->get('width')), self::int($image->get('height')), $image->has('admin_graphql_api_id') ? $image->text('admin_graphql_api_id') : $image->textOrNull('id'));
            $media[] = $m;
            if ($image->has('id')) {
                $imagesById[$image->text('id')] = $m;
            }
        }
        if (!$media && !empty($read->get('image.src'))) {
            $media[] = new Media($read->text('image.src'), self::blankToNull($read->get('image.alt')));
        }

        $variants = [];
        foreach ($read->each('variants') as $variant) {
            $fields = $variant->toArray();
            // Older payloads say inventory_management ("shopify" or null);
            // a payload without it counts when it gives a quantity.
            $tracked = \array_key_exists('inventory_management', $fields)
                ? null !== $fields['inventory_management'] && '' !== $fields['inventory_management']
                : ($variant->has('inventory_item.tracked') ? (bool) $variant->get('inventory_item.tracked') : \array_key_exists('inventory_quantity', $fields));
            $quantity = $variant->number('inventory_quantity');
            $reference = self::gid('ProductVariant', $variant->get('admin_graphql_api_id') ?? $variant->get('id'));
            $values = [];
            foreach ($optionNames as $position => $name) {
                $value = $variant->get('option'.$position);
                if (null !== $value && '' !== $name) {
                    $values[$name] = Data::cast($value);
                }
            }
            $item = $variant->has('inventory_item_id') ? self::gid('InventoryItem', $variant->get('inventory_item_id')) : null;
            // The REST shape has no per-variant availability flag: for sale
            // when the product is active and it has stock, does not count
            // it, or sells on when it is out.
            $available = Product::ACTIVE === $status && (!$tracked || $quantity > 0 || 'continue' === $variant->get('inventory_policy'));

            $variants[] = new ProductVariant(
                reference: $reference,
                title: self::variantTitle($variant->get('title')),
                sku: self::blankToNull($variant->get('sku')),
                barcode: self::blankToNull($variant->get('barcode')),
                offers: self::offers($variant->get('price'), $variant->get('compare_at_price'), $currency, $available, null, $taxIncluded),
                options: self::withoutDefault($values),
                stock: new Stock($reference, $tracked ? $quantity : null, $tracked, self::blankToNull($variant->get('sku')), item: $item),
                image: $variant->has('image_id') ? ($imagesById[$variant->text('image_id')] ?? null) : null,
                weight: $variant->has('grams') ? $variant->number('grams') : ($variant->has('weight') ? self::grams($variant->get('weight'), Data::cast($variant->get('weight_unit') ?? 'g')) : null),
                raw: $fields,
            );
        }

        return new Product(
            provider: 'shopify',
            reference: self::gid('Product', $read->get('admin_graphql_api_id') ?? $read->get('id')),
            title: $read->text('title'),
            description: self::blankToNull($payload['body_html'] ?? null),
            handle: self::blankToNull($payload['handle'] ?? null),
            brand: self::blankToNull($payload['vendor'] ?? null),
            status: $status,
            tags: self::tags($payload['tags'] ?? []),
            categories: self::unique([$read->text('product_type')]),
            options: self::onlyRealOptions($options),
            variants: $variants,
            media: $media,
            updatedAt: self::date($payload['updated_at'] ?? null),
            raw: $payload,
        );
    }

    /**
     * A variant's inventory, as VARIANT_STOCKS and STOCKS return it.
     *
     * @param array<string, mixed> $node
     */
    public static function stock(array $node): Stock
    {
        $read = Data::of($node);
        $tracked = (bool) ($read->get('inventoryItem.tracked') ?? false);

        return new Stock(
            $read->text('id'),
            $tracked ? $read->number('inventoryQuantity') : null,
            $tracked,
            self::blankToNull($node['sku'] ?? null),
            item: self::blankToNull($read->get('inventoryItem.id')),
        );
    }

    /** "ACTIVE", "active" -> Product::ACTIVE; DRAFT, UNLISTED -> DRAFT; ARCHIVED. */
    public static function status(mixed $status): string
    {
        return match (strtoupper(Data::cast($status))) {
            'ARCHIVED' => Product::ARCHIVED,
            'DRAFT', 'UNLISTED' => Product::DRAFT,
            default => Product::ACTIVE,
        };
    }

    /** @return list<Offer> */
    private static function offers(mixed $price, mixed $compareAt, string $currency, bool $available, ?string $url, ?bool $taxIncluded): array
    {
        if (null === $price || '' === $price) {
            return [];
        }
        $price = Money::fromDecimal(Data::cast($price), $currency);
        $compareAt = null === $compareAt || '' === $compareAt ? null : Money::fromDecimal(Data::cast($compareAt), $currency);
        if (null !== $compareAt && $compareAt->amount <= $price->amount) {
            $compareAt = null;
        }

        return [new Offer($price, $compareAt, $available, $url, taxIncluded: $taxIncluded)];
    }

    private static function media(Data $item): ?Media
    {
        $type = strtoupper(Data::cast($item->get('mediaContentType') ?? 'IMAGE'));
        $reference = self::blankToNull($item->get('id'));
        $alt = self::blankToNull($item->get('alt'));
        if ('IMAGE' === $type && !empty($item->get('image.url'))) {
            return new Media($item->text('image.url'), $alt, Media::IMAGE, self::int($item->get('image.width')), self::int($item->get('image.height')), $reference);
        }
        if ('VIDEO' === $type && !empty($item->get('sources.0.url'))) {
            return new Media($item->text('sources.0.url'), $alt, Media::VIDEO, self::int($item->get('sources.0.width')), self::int($item->get('sources.0.height')), $reference);
        }
        if ('EXTERNAL_VIDEO' === $type && !empty($item->get('originUrl'))) {
            return new Media($item->text('originUrl'), $alt, Media::VIDEO, reference: $reference);
        }

        return null; // a 3D model: not a picture
    }

    /** The product page with its variant chosen, when there are several. */
    private static function variantUrl(?string $url, string $gid, int $count): ?string
    {
        if (null === $url || $count < 2) {
            return $url;
        }
        $parts = explode('/', $gid);

        return $url.(str_contains($url, '?') ? '&' : '?').'variant='.end($parts);
    }

    /**
     * "Default Title" is what Shopify calls the single variant of a product
     * that has no options. It is not a name anyone wants on a product page.
     */
    private static function variantTitle(mixed $title): ?string
    {
        $title = trim(Data::cast($title));

        return ('' === $title || 'Default Title' === $title) ? null : $title;
    }

    /**
     * A product without options has one, "Title: Default Title": not one.
     *
     * @param array<string, string> $options
     *
     * @return array<string, string>
     */
    private static function withoutDefault(array $options): array
    {
        return ['Title' => 'Default Title'] === $options ? [] : $options;
    }

    /**
     * @param list<Option> $options
     *
     * @return list<Option>
     */
    private static function onlyRealOptions(array $options): array
    {
        return array_values(array_filter($options, static fn (Option $o) => !('Title' === $o->name && ['Default Title'] === $o->values)));
    }

    /**
     * GraphQL gives an array of tags, REST a comma-separated string.
     *
     * @return list<string>
     */
    private static function tags(mixed $tags): array
    {
        if (\is_string($tags)) {
            $tags = explode(',', $tags);
        }

        return self::unique(Data::texts($tags));
    }

    /**
     * @param array<string> $values
     *
     * @return list<string> trimmed, without blanks and repeats
     */
    private static function unique(array $values): array
    {
        return array_values(array_unique(array_filter(array_map(static fn (string $v) => trim($v), $values), static fn (string $v) => '' !== $v)));
    }

    private static function grams(mixed $value, string $unit): ?int
    {
        if (null === $value || '' === $value) {
            return null;
        }

        return (int) round(Data::decimal($value) * match (strtolower($unit)) {
            'kilograms', 'kg' => 1000,
            'ounces', 'oz' => 28.349523125,
            'pounds', 'lb' => 453.59237,
            default => 1,
        });
    }

    private static function gid(string $type, mixed $id): string
    {
        return null === $id || '' === $id ? '' : Endpoint::gid($type, Data::cast($id));
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return null;
        }
        try {
            return new \DateTimeImmutable(Data::cast($value));
        } catch (\Exception) {
            return null;
        }
    }

    private static function int(mixed $value): ?int
    {
        return null === $value || '' === $value ? null : Data::integer($value);
    }

    private static function blankToNull(mixed $value): ?string
    {
        $value = trim(Data::cast($value));

        return '' === $value ? null : $value;
    }
}
