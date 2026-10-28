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
  *List on marketplaces* and *Not listed on* keep a domain for sale on the
  site but off all or some marketplaces: the next sync removes its listings
  there, and CSV files leave it out.
- **Domain providers**: the companies domains are registered or listed with.
  A provider is a config entity with an optional API plugin. Without one it is
  just a registrar name; with one, the plugin's capabilities apply.
- **Provider plugins** with one or more capabilities: *marketplace* (keeps
  listings in line with the catalog), *CSV export* (writes the file a
  marketplace's upload page takes), *registrar* (reads the domains in the
  account, with their expiry dates) and *domain management* (changes
  nameservers, locks and renewal at the registrar).

  | Provider            | Marketplace | CSV export | Registrar |
  |---------------------|-------------|------------|-----------|
  | Afternic            | yes         | yes        |           |
  | fruits              | yes         | yes        |           |
  | Atom                | yes         | yes        | yes ¹     |
  | Dynadot             | yes ²       | yes ⁴      | yes       |
  | GNAME               | yes ²       |            | yes ³     |
  | NameSilo            | yes ²       |            | yes       |
  | Spaceship           | yes         | yes        | yes       |
  | Unstoppable Domains | yes ²       | yes ²      | yes       |
  | BrandStore          |             | yes        |           |
  | DaaZ                |             | yes        |           |
  | DNX                 |             | yes        |           |
  | NameClub            |             | yes ⁵      |           |
  | Sedo                |             | yes        |           |
  | GoDaddy             |             |            | yes       |
  | IONOS               |             |            | yes       |
  | NameBright          |             |            | yes       |
  | Namecheap           |             |            | yes       |
  | Porkbun             |             |            | yes       |

  ¹ With a separate Registrar API token. ² Only for domains registered there.
  ³ Needs a GNAME reseller account. ⁴ Two files: domains at Dynadot, and
  domains registered elsewhere. ⁵ Domains registered elsewhere; Dynadot's
  marketplace listings appear on NameClub already.

  A provider without a plugin, such as Hover, is a registrar without an API:
  domains can be recorded there, and nothing is synced. A domain's registrar
  can be any registrar, with or without an API, but not a marketplace-only
  provider such as Afternic.
- **Conditions** for promotions, payment gateways and shipping methods, under
  *Domains*: the registrar, Atom premium (or not), and the sale terms (buy
  now, offers, lease-to-own; each required, ruled out or either). Each comes
  for order items, as promotion offers use, and for orders, which match when
  any of their domains does. Lease-to-own counts the domain's own term, or
  the one worked out from its price.
- **Commerce Domains Marketplace** (submodule): keeps listings on those
  marketplaces in line with the catalog, with a Drush command for reviewing
  and pushing, and optional automatic pushes from cron through
  [Advanced Queue](https://www.drupal.org/project/advancedqueue); and writes
  the CSV files marketplaces import.

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
- [Advanced Queue](https://www.drupal.org/project/advancedqueue), for
  Commerce Domains Marketplace
- [jeremykendall/php-domain-parser](https://github.com/jeremykendall/php-domain-parser)

## Setup

1. Install the module, and Commerce Domains Marketplace for syncing and CSV
   files.
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
changed domains are then queued in the *Marketplace sync* queue of
[Advanced Queue](https://www.drupal.org/project/advancedqueue) and pushed
on cron. An outage or bad keys are retried every 15 minutes for a day; the
queue's job list shows what waits and what failed. To push sooner than cron:

```sh
drush advancedqueue:queue:process commerce_domains_marketplace
```

## Domain overview

With Views, *Commerce → Domains* lists every catalog domain: its registrar,
when that registrar's account was last read (and whether it still held the
domain), the expiry date, the price, and its status on each marketplace.
The *Marketplace listings* tab has one row per domain and marketplace, with
what is out of date, why a listing is left alone, or the marketplace's
error, and when it was last checked and pushed; filter it by marketplace and
status.

Every sync records what it found, dry runs included, and every push what it
did. Syncs never read these records back: they always compare the catalog
with the marketplace. A domain that isn't listed and needn't be has no row.
Files from the CSV export aren't tracked.

## CSV files

Some marketplaces only take a file, and some sellers would rather upload one
than hand over API keys. A provider's *CSV export* tab shows the file its
upload page takes, with the domains for sale priced the same way a sync
would (price list, price factor and sale terms), and downloads it. Nothing
is sent to the marketplace.

```sh
drush commerce-domains:csv-export sedo > sedo.csv
drush cdcsv dynadot --file=external > dynadot-external.csv
```

## Registrar details

Providers whose plugin is a registrar can update the catalog from the
account. The account is the record of where a domain is: every catalog domain
it holds is recorded at that registrar, so a domain transferred in moves on
the next sync, and gets the account's expiry date. A domain recorded there
that the account no longer has is only reported, for a person to look at.
Nothing is written to the registrar.

The *Registrations* tab on the provider compares the account with the catalog
and applies the result; Drush does the same:

```sh
# See what would change, and which domains need a look: recorded here but
# missing from the account, or in the account but recorded elsewhere.
drush commerce-domains:registrar-sync dynadot --dry-run --details
# Record Dynadot on the domains it holds, and update expiry dates.
drush cdrs dynadot
```

Turn on *Refresh registration details daily* on the provider to have cron do
the update once a day.

## Domain management

Registrars whose API can change domains add a *Registrar* tab to the domain's
product, for people with *Manage domains at registrars*: the registration as
the registrar has it, the nameservers, the transfer lock, automatic renewal,
and the transfer auth code. The page reads the registrar each time it is
shown, and every button acts on the registrar at once. Changes are logged
with who made them; an auth code is shown on the page and never logged.

| Registrar | Nameservers | Lock | Unlock | Auto-renew | Auth code |
|-----------|-------------|------|--------|------------|-----------|
| Dynadot   | yes         | yes  | ¹      | yes        | yes ²     |
| NameBright | yes        | yes  | yes    | yes        | yes       |
| Namecheap | yes         | yes  | yes    | ³          | ³         |
| NameSilo  | yes         | yes  | yes    | yes        | emailed ⁴ |
| Porkbun   | yes         | ⁵    | ⁵      | yes        | ⁵         |
| Spaceship | yes         | yes  | yes    | yes        | yes       |

¹ Dynadot's API cannot unlock a domain: unlock it in the account.
² Dynadot gives one only for an unlocked domain.
³ Namecheap's API has no command for these: use the account, which emails
  the auth code.
⁴ NameSilo emails the code to the domain's administrative contact.
⁵ Porkbun's API has no endpoint for these: use the account. Porkbun also
  needs API access turned on for each domain.

Dynadot nameservers must already be added to the account before a domain can
use them. The Spaceship key needs the domains write scope for nameservers and
auto-renew, and domains transfer for the lock and the auth code.

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
- For a CSV file, implement `CsvExportInterface`: `csvHeader()` and
  `csvRow()`, plus `csvFiles()` when the marketplace takes several. A
  marketplace without an API extends `ListingPluginBase`, which has the price
  factors and the shared pricing rules.
- For a registrar, implement `RegistrarInterface` with `RegistrarTrait`:
  fetch the account's records in `registrationRecords()` and map one to a
  `Registration` in `registration()`. A registrar-only plugin extends
  `ProviderPluginBase`.

Put the HTTP client in `src/Clients`, extending `ApiClient` for retries,
rate-limit waits and keeping credentials out of error messages.
