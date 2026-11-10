# Changelog

## Unreleased (1.x)

### Added
- The gateway's `tags` option (omnisense/omnitrade): the draft order's custom attributes (the order's
  `note_attributes`, under the payment's metadata, `site` the configuration's) and a `site:<name>`
  tag beside `draft_order_tags`; `notify()` gives them back from an order's `note_attributes`, else
  its `site:` tag (`Notification::$tags`). Not on `refunds/create` (its payload has neither). Tests on
  recorded requests (`ShopifyTagsTest`).

### Removed
- The `storefront_token` option, which no action read: the factory built a Storefront API client
  with it and nothing called it. This is the provider's public API: an application that passes
  `storefront_token` has it ignored now. Removed with it: `Omnitrade\Shopify\Api\StorefrontApi`,
  `Api::$storefront`, `Endpoint::$storefrontToken` and `Endpoint::storefrontUrl()`, and
  `Endpoint`'s `$storefrontToken` constructor argument (the arguments after it, `$webhookSecret`
  and `$timeout`, move up one place).

### Fixed
- A `ProviderException` no longer carries the admin token. The message quoted the HTTP client's (the
  whole URL), the previous was the client's own exception, and every trace of the chain kept the
  arguments of the calls under it (the request's options, the headers with the credentials) when
  `zend.exception_ignore_args` is off: a log of the whole chain wrote them. The message masks them
  as `***`, the previous is a copy of the client's exception (the same class and code), and no
  exception `Api\GraphQL` throws keeps its trace's arguments (`Omnitrade\Http\Redaction`).
