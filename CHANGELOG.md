# Changelog

## Unreleased (1.x)

### Removed
- The `storefront_token` option, which no action read: the factory built a Storefront API client
  with it and nothing called it. This is the provider's public API: an application that passes
  `storefront_token` has it ignored now. Removed with it: `Omnitrade\Shopify\Api\StorefrontApi`,
  `Api::$storefront`, `Endpoint::$storefrontToken` and `Endpoint::storefrontUrl()`, and
  `Endpoint`'s `$storefrontToken` constructor argument (the arguments after it, `$webhookSecret`
  and `$timeout`, move up one place).
