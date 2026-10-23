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
- **Domain providers**: the companies domains are registered or listed with.
  A provider is a config entity with an optional API plugin. Without one it is
  just a registrar name; with one, the plugin's capabilities apply.
- **Provider plugins** for Afternic, Spaceship, Atom, fruits and Dynadot, with
  the marketplace capability.
- **Commerce Domains Marketplace** (submodule): keeps listings on those
  marketplaces in line with the catalog, with a Drush command for reviewing
  and pushing, and optional automatic pushes from cron.

Stock comes from wherever the site keeps it: a variation counts as for sale
when its product is published, it is active, and its `in_stock` field (as
added by Commerce Escrow) is set when it has one. Prices are read per
currency from the variation price and any other price field, so per-currency
fields such as `field_price_eur` win for their currency.

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

Providers whose plugin is a registrar (Dynadot, Spaceship) can update the
catalog from the account: every domain recorded at that registrar gets its
expiry date. Nothing is written to the registrar.

```sh
# See what would change, and which domains need a look: recorded here but
# missing from the account, or in the account but recorded elsewhere.
drush commerce-domains:registrar-sync dynadot --dry-run --details
# Update expiry dates; --fix-registrar also corrects recorded registrars.
drush cdrs dynadot --fix-registrar
```

Turn on *Refresh registration details daily* on the provider to have cron do
the update once a day.

## Adding a provider

Write a plugin in `src/Plugin/CommerceDomains/Provider` with the
`#[CommerceDomainsProvider]` attribute. Extend `MarketplacePluginBase` for a
marketplace: implement `buildListing()`, `fetchListings()`,
`saveListings()` and `deleteListings()`, and list the settings that hold
credentials in `CREDENTIALS`. Put the HTTP client in `src/Clients`, extending
`ApiClient` for retries, rate-limit waits and keeping credentials out of
error messages.
