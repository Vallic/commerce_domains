# Commerce Domains

Sell domain names with Drupal Commerce: one product per domain, the registrar
it is held at, and the marketplaces it is listed on.

## What it provides

- **A domain product trait.** Turn it on for a product type and its products
  get `domain`, `domain_label` and `domain_extension` (derived from the title
  on every save, using the Public Suffix List, so `example.co.uk` splits into
  `example` and `co.uk`), plus a registrar, an expiry date and an ownership
  flag. Titles must be registrable domain names.
- **An Atom premium trait** for names Atom lists as premium.
- **A sale terms trait** for variations: buy now, accept offers, minimum
  offer, floor price and lease-to-own. Empty fields are worked out from the
  price, the same way for every marketplace; each marketplace lists what it
  supports, and one that only sells at a price skips offers-only names.
- **Domain providers**: the companies domains are registered or listed with.
  A provider is a config entity with an optional API plugin. Without one it is
  just a registrar name; with one, the plugin's capabilities apply.
- **Provider plugins** with one or both capabilities: *marketplace* (keeps
  listings in line with the catalog) and *registrar* (reads the domains in the
  account, with their expiry dates).

  | Provider            | Marketplace | Registrar |
  |---------------------|-------------|-----------|
  | Afternic            | yes         |           |
  | fruits              | yes         |           |
  | Atom                | yes         | yes ¹     |
  | Dynadot             | yes ²       | yes       |
  | GNAME               | yes ²       | yes ³     |
  | NameSilo            | yes ²       | yes       |
  | Spaceship           | yes         | yes       |
  | Unstoppable Domains | yes ²       | yes       |
  | GoDaddy             |             | yes       |
  | IONOS               |             | yes       |
  | NameBright          |             | yes       |
  | Namecheap           |             | yes       |
  | Porkbun             |             | yes       |

  ¹ With a separate Registrar API token. ² Only for domains registered there.
  ³ Needs a GNAME reseller account.

  A provider without a plugin, such as Hover, is a registrar without an API:
  domains can be recorded there, and nothing is synced. A domain's registrar
  can be any registrar, with or without an API, but not a marketplace-only
  provider such as Afternic.
- **Commerce Domains Marketplace** (submodule): keeps listings on those
  marketplaces in line with the catalog, with a Drush command for reviewing
  and pushing, and optional automatic pushes from cron.

Stock comes from wherever the site keeps it: a variation counts as for sale
when its product is published, it is active, and its `in_stock` field (as
added by Commerce Escrow) is set when it has one.

Marketplaces get the variation price. In other currencies, or to price a
marketplace differently, choose a price list on the provider
([Commerce Pricelist](https://www.drupal.org/project/commerce_pricelist)):
its items win for their currency. The list needn't apply to a store, so a
disabled list keeps marketplace prices off the storefront. Currencies with
neither are converted at
[Commerce Exchanger](https://www.drupal.org/project/commerce_exchanger)
rates when it is installed. Amounts in the sale terms follow the price into
other currencies.

## Requirements

- Drupal 11.4 or later, PHP 8.3 or later
- Commerce 3 (Commerce Product)
- [jeremykendall/php-domain-parser](https://github.com/jeremykendall/php-domain-parser)

## Setup

1. Install the module, and Commerce Domains Marketplace for syncing.
2. Turn on the *Domain name* trait (and *Atom premium listing*, if needed) in
   your product type's settings.
3. Add providers at *Commerce → Configuration → Domain providers*: registrars
   by name, marketplaces with their API.
4. Put API credentials in `settings.php`, never in config:

   ```php
   $afternic = &$config['commerce_domains.provider.afternic']['configuration'];
   $afternic['api_key'] = getenv('AFTERNIC_API_KEY');
   $afternic['api_secret'] = getenv('AFTERNIC_API_SECRET');
   ```

   Each provider's settings form names the variables it expects.

## Syncing marketplaces

Every sync reads both sides and pushes only the differences, so a failed or
skipped run is fixed by the next one. Listings for names the catalog doesn't
know, and listings a marketplace shows as mid-sale, are never touched.

```sh
# Review what a full sync would change, value by value.
drush commerce-domains:marketplace-sync afternic --dry-run --details
# Push it.
drush cdms afternic
# Push a few names.
drush cdms afternic --domains=example.com,example.net
```

A dry run works while the provider is disabled. Once the output looks right,
enable the provider and, if wanted, *Push catalog changes automatically*:
changed domains are then queued and pushed on cron.

## Registrar details

Providers whose plugin is a registrar can update the catalog from the
account: every domain recorded at that registrar gets its expiry date.
Nothing is written to the registrar.

```sh
# See what would change, and which domains need a look: recorded here but
# missing from the account, or in the account but recorded elsewhere.
drush commerce-domains:registrar-sync dynadot --dry-run --details
# Update expiry dates; --fix-registrar also corrects recorded registrars.
drush cdrs dynadot --fix-registrar
```

Turn on *Refresh registration details daily* on the provider to have cron do
the update once a day.

## Offers

Commerce Domains Offers (submodule) lets buyers make offers on the site:

1. Place the *Domain offer form* block on product pages, and grant *Make
   offers on domains* to the roles that may use it. The form only shows on
   domains that are for sale and whose sale terms accept offers, and offers
   start at the domain's minimum offer, in the currency the buyer sees.
2. Answer offers at *Commerce → Domain offers*: accept, decline or counter.
   The buyer gets an email with a link to answer a counter-offer, no account
   needed.
3. Accepting creates a draft order for the domain at the agreed amount; the
   buyer pays it through checkout from the link in their email. Placing the
   order marks the offer purchased. Offers nobody answers or pays expire
   after 14 days, which *Commerce → Configuration → Domain offers* changes.

Sites add their own rules, such as no offers below a price, by subscribing
to `OfferAllowedEvent`.

Commerce Domains Offers via Escrow.com (submodule, needs
[Commerce Escrow](https://www.drupal.org/project/commerce_escrow)) adds a
*Negotiate on Escrow.com* choice to the form, for prices in USD, EUR, GBP,
AUD or CAD while an Escrow Offer gateway is enabled. The buyer goes on to
Escrow.com to make the offer, and the negotiation, payment and transfer run
there; the offer on the site follows Escrow.com's webhooks to accepted,
purchased or declined, and doesn't expire on the site. Choose the gateway,
and whether the choice starts ticked, on the offer settings page.

## Adding a provider

Write a plugin in `src/Plugin/CommerceDomains/Provider` with the
`#[CommerceDomainsProvider]` attribute, and list the settings that hold
credentials in `CREDENTIALS`. Give the API's rate limit in
`REQUESTS_PER_MINUTE` to get a setting for it.

- For a marketplace, extend `MarketplacePluginBase` and implement
  `buildListing()`, `fetchListings()`, `saveListings()` and
  `deleteListings()`.
- For a registrar, implement `RegistrarInterface` with `RegistrarTrait`:
  fetch the account's records in `registrationRecords()` and map one to a
  `Registration` in `registration()`. A registrar-only plugin extends
  `ProviderPluginBase`.

Put the HTTP client in `src/Clients`, extending `ApiClient` for retries,
rate-limit waits and keeping credentials out of error messages.
