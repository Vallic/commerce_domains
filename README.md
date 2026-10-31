# Commerce Domains

Sell domain names with Drupal Commerce: one product per domain, the registrar
it is held at, and the marketplaces it is listed on.

## What it provides

- **A domain product trait.** Turn it on for a product type and its products
  get `domain`, `domain_label` and `domain_extension` (derived from the title
  on every save, using the Public Suffix List, so `example.co.uk` splits into
  `example` and `co.uk`), plus a registrar, an expiry date and an ownership
  flag. Titles must be registrable domain names.
- **An Atom premium trait** for names Atom lists as premium. With Atom
  enabled, each Atom check sets it from the listing (on for a premium
  listing, off for a standard one; unlisted domains keep it), and the
  product form locks it.
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

  Tick *Marketplace* on a provider without a listing API to record that you
  list domains there by hand. It can then be picked under *Not listed on*
  and *Sold through*, in bulk edits and imports, and the domain's
  *Marketplaces* tab says whether to list it there or leave it off. Nothing
  is synced, so add and remove its listings yourself. Providers whose plugin
  lists domains, by API or CSV file, are marketplaces anyway.
- **Conditions** for promotions, payment gateways and shipping methods, under
  *Domains*: the registrar, Atom premium (or not), and the sale terms (buy
  now, offers, lease-to-own; each required, ruled out or either). Each comes
  for order items, as promotion offers use, and for orders, which match when
  any of their domains does. Lease-to-own counts the domain's own term, or
  the one worked out from its price.
- **A lander redirect** at `/domain-lander`: point a registrar's forwarding
  of parked domains there, and visitors land on the domain's page here, by
  the Referer or a `domain` query parameter. Domains not sold here go to the
  route's `fallback` path, which a site's own route can set.
- **Price tokens** for structured data on domain pages:
  `[commerce_product:current_price]` and `[commerce_product:current_currency]`,
  as the visitor sees them. The domain's parts have field tokens, such as
  `[commerce_product:domain_label]` and `[commerce_product:domain_extension]`.
- **Commerce Domains Checkout** (submodule): a checkout for one domain at a
  time. *Buy now* empties the cart and opens checkout, and is left out for
  domains whose sale terms take offers only; the *Domain:
  details, transfer and payment, review* flow asks, in the *Domain transfer*
  pane, whether to push the domain to the buyer's account at its registrar
  or transfer it elsewhere, and keeps the answer on the order (`Transfer`)
  and in the customer comment. The cart page goes to the open checkout.
- **Commerce Domains Handover** (submodule): hands each sold domain over to
  its buyer once the order is paid: unlocks it and emails the buyer the auth
  code, or records the push to their account, with an email at each step
  and a *Handover* tab on the order.
- **Commerce Domains Marketplace** (submodule): keeps listings on those
  marketplaces in line with the catalog, with a Drush command for reviewing
  and pushing, and optional automatic pushes from cron through
  [Advanced Queue](https://www.drupal.org/project/advancedqueue); takes a
  domain a marketplace sold off sale here, and gets its other listings
  removed; and writes the CSV files marketplaces import.
- **Commerce Domains Import** (submodule): adds domains to the catalog from a
  pasted list, a CSV file or a registrar account, with default prices and
  sale terms, at *Commerce → Domains → Import* and with
  `drush commerce-domains:import`.
- **Commerce Domains Pricing** (submodule): pricing rules for domains saved
  without a price, rounding steps, and a price in each currency, written to a
  price list and to price fields.
- **Commerce Domains Bulk editing** (submodule): actions for a selection on the
  Domains overview: apply the pricing rules, reprice by a factor, set sale
  terms, choose marketplaces, publish and unpublish.
- **Commerce Domains Owner** (submodule): running the portfolio day to day:
  what each domain cost and earned, which domains to renew, mail to the
  owner when something needs them, and a dashboard.
- **Commerce Domains Offers** (submodule): offers from buyers, answered on the
  site or, with Commerce Domains Offers via Escrow.com, negotiated there.

Stock comes from wherever the site keeps it: a variation counts as for sale
when its product is published, it is active, and its `in_stock` field (as
added by Commerce Escrow) is set when it has one. Placing an order takes its
domains off sale: out of stock, or, without an `in_stock` field,
unpublished, and with them any other variation of the domain. The next
marketplace sync then removes their listings, at once where pushes are
automatic. A sale on a marketplace does the same; see
[Sales on marketplaces](#sales-on-marketplaces).

Domains you keep for your own use are marked *Internal* on the product
form: never for sale, on the site or on marketplaces, and their product
stays unpublished whatever publishes it. Renewals and registrar checks
still count them. Untick it, and publish the product, to sell one.

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

- Drupal 11.4 or later, PHP 8.4 or later
- Commerce 3 (Commerce Product)
- [Advanced Queue](https://www.drupal.org/project/advancedqueue), for
  Commerce Domains Marketplace
- [jeremykendall/php-domain-parser](https://github.com/jeremykendall/php-domain-parser)
- [Key](https://www.drupal.org/project/key), optional, to read API
  credentials from keys instead of `settings.php`

## Setup

1. Install the module, and Commerce Domains Marketplace for syncing and CSV
   files.
2. Turn on the *Domain name* trait (and *Atom premium listing*, if needed) in
   your product type's settings.
3. Add providers at *Commerce → Configuration → Domains → Domain providers*:
   registrars by name, marketplaces with their API.
4. Give each API its credentials, never in config, which is exported:
   in `settings.php`,

   ```php
   $afternic = &$config['commerce_domains.provider.afternic']['configuration'];
   $afternic['api_key'] = getenv('AFTERNIC_API_KEY');
   $afternic['api_secret'] = getenv('AFTERNIC_API_SECRET');
   ```

   or, with the [Key](https://www.drupal.org/project/key) module, by
   choosing a key for each on the provider's settings form (see *API
   credentials*). The form names the variables each provider expects, and
   says where each credential comes from.

The settings of the module and its submodules are grouped under *Commerce →
Configuration → Domains*.

## API credentials

A provider looks for each credential in this order, when it calls the API:

1. **`settings.php`.** A value set there as a config override wins over a
   key, so sites that set credentials there need no change. An empty one,
   as `getenv()` returns for an unset variable, counts as not set.
2. **The key chosen for it**, under *Keys* on the provider's settings form,
   once the Key module is installed. Add the key at *Configuration → System
   → Keys* with the *Environment* provider (the variable's name) or the
   *File* provider (a file outside the web root, with *Strip trailing line
   breaks*), so the value can come from a hosting control panel. Keys with
   the *Configuration* provider are refused, on the form and when the API is
   called: their value would be exported with the site's config.
3. Otherwise the credential is missing. Syncs, Drush and the provider's
   pages then name the variable to set, or the key that is empty, missing
   or refused, never a value; queued pushes wait for it.

The provider keeps only the keys' names, and depends on the keys in config:
an import brings them along, and deleting a key, or uninstalling Key, takes
it off the provider instead of deleting the provider. A value saved in the
provider's own config still works, but its form flags it: move it to
`settings.php` or a key.

API errors never quote a credential: the clients replace each one, as sent
and as URLs and JSON bodies spell it, with `***`.

## Syncing marketplaces

Every sync reads both sides and pushes only the differences, so a failed or
skipped run is fixed by the next one. Listings for names the catalog doesn't
know, and listings a marketplace locks or shows as sold, are never touched.

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
on cron. Only changes a listing reads count: the name, price, sale terms,
stock, status, registrar and description, not costs, dates or notes (see
`CatalogSubscriber::PRODUCT_FIELDS` and `VARIATION_FIELDS`). An outage or bad keys are retried every 15 minutes for a day; the
queue's job list shows what waits and what failed. With automatic pushes
on, cron also queues a *Marketplace sale check* of the whole marketplace
every hour, which reads it and pushes nothing.

*Commerce → Domains → Sync marketplaces* (also an action on the domain
overview, the listings and the providers) checks or syncs whole
marketplaces from the browser, one batch step each: *Check* reads them and
records their listings, *Sync* also pushes what differs. Very large
accounts may be quicker with Drush. To push sooner than cron:

```sh
drush advancedqueue:queue:process commerce_domains_marketplace
```

## Sales on marketplaces

When a sync finds a marketplace selling one of the catalog's domains, or
showing it sold, it takes the domain off sale here the way an order does,
and leaves that listing alone. The domain is then no longer for sale, so its
listings on the other marketplaces are removed: queued at once where pushes
are automatic, otherwise on their next sync. Syncs, the hourly sale checks
and the *Check marketplaces* button on a domain all do this, whether or not
anything is pushed. A dry run only lists the sale, and changes nothing.

Each sale is acted on once. While the marketplace shows it, the listing is
*Sold*, with when the sale was found, on the domain overview, the
*Marketplace listings* tab and the domain's *Marketplaces* tab. If the sale
falls through, put the domain back on sale (back in stock, or its variation
published): the sale's record ends, and the next syncs list the domain
again. A listing that still shows the same sale stays locked until the
marketplace catches up. A new status for it, such as a hold that became a
sale, takes the domain off sale again.

| Marketplace         | Listing statuses that are sales                                   |
|---------------------|-------------------------------------------------------------------|
| Afternic            | `ON_HOLD` (pending sale), `SOLD`                                  |
| Atom                | any with sold, hold, purchase or transfer, premium listings too   |
| Dynadot             | pending sale (`PendingSaleLocked`)                                |
| NameSilo            | any with payment, sold, purchase, escrow or transfer              |
| Spaceship           | `saleProcessing`, `privateSale` (SafePay), `leaseActive`, `sold`  |
| Unstoppable Domains | `pending` (sale in progress)                                      |

fruits and GNAME listings show no sale, so sales there aren't seen: fruits
keeps them as orders, and GNAME moves a sold listing to a list of its own.

Sites hear about sales and failures through events:

- `MarketplaceEvents::SOLD_ELSEWHERE`, once per sale: a `SoldElsewhereEvent`
  with the marketplace (`provider`), the `domain`, its `product` (NULL when
  it just left the catalog) and the marketplace's `status`.
- `MarketplaceEvents::SYNC_FAILED`: a `SyncFailedEvent` with the marketplace
  and the `errors`, keyed by domain, or by `''` when the whole run failed.
  Dispatched when a queued push or sale check fails for good (after Advanced
  Queue's retries), when a sync someone runs fails, and when a marketplace
  refuses a domain for a reason it hadn't given before.

Commerce Domains Owner mails the owner about both.

## Domain overview

With Views, *Commerce → Domains* lists every catalog domain: its registrar,
when that registrar's account was last read (and whether it still held the
domain), the expiry date, the price, and its status on each marketplace.
The *Marketplace listings* tab has one row per domain and marketplace, with
what is out of date, why a listing is left alone, or the marketplace's
error, and when it was last checked and pushed; filter it by marketplace and
status. A sold listing says when the sale was found.

Every sync records what it found, dry runs included, and every push what it
did. Syncs never read these records back: they always compare the catalog
with the marketplace, except that a whole sync also removes listings it
recorded for products deleted since, and a sale on record isn't acted on
again. A domain that isn't listed and needn't be has no row. Files from the
CSV export aren't tracked.

Marketplaces that report it show the interest in each listing beside its
status, on the overview and as *Views*, *Leads* and *Offers* columns on
*Marketplace listings*: Afternic's views and leads over the last 30 days,
and Atom's views, shortlists and offers of all time. Cron reads each listing
once a day, up to 60 per marketplace each cron run, starting with those
never read.
Read some now with Drush:

```bash
drush cdmst afternic --limit=100
drush cdmst atom --domains=example.com
```

Each domain has a *Marketplaces* tab beside *Registrar*: its status on every
marketplace and whether the CSV files include it, with buttons to check the
marketplaces again for that domain or push it.

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
the next sync, and gets the account's expiry date (with Commerce Domains
Owner, its auto-renew setting too). A domain recorded there that the account
no longer has is only reported, for a person to look at. Nothing is written
to the registrar.

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

Syncs also record when the domain was registered, from registrars that say:
GoDaddy, Namecheap, NameSilo, Dynadot, Spaceship and Atom. After a transfer
some show the transfer date instead.

Once an enabled registrar API has read the whole account, found the domain
there and set its expiry date, the date is locked on the product form: it
follows the registrar. So are the registration date and the auto-renew
setting once the registrar has set them; turn auto-renew on or off at the
registrar on the *Expiring soon* page. The expiry, registration date and
auto-renew setting are updated by every sync, and whenever the domain's
*Registrar* tab reads the registrar.

A sold domain (Commerce Domains Owner records the sale) is the buyer's to
manage: it has no *Registrar* tab, and a sync does not count it missing
once it leaves the account.

## Importing domains

Commerce Domains Import (submodule) adds domains at *Commerce → Domains →
Import*, for people with *Import domains*, in one of three ways:

- **Paste a list**: one domain per line, optionally followed by its price:
  `example.com 1500` or `example.net, 2500 EUR`.
- **Upload a CSV file** with a header row. Columns go by their header, in any
  order and case: `domain` (or *domain name*; required), `price` (or *selling
  price*), `currency`, `buy_now`, `offers` and `marketplaces` (yes or no),
  `not_listed_on` (marketplaces by ID or name, separated by commas),
  `minimum_offer`, `floor_price`, `lease_months` (0 for none),
  `description`, `registrar` (a provider's ID or name), `expires` (a date),
  and `atom_premium` and `internal` (yes or no).
- **From a registrar account**: *Read the account* lists the domains a
  registrar provider's account holds that the catalog lacks, read through
  its API; take out the line of any to leave out. They are recorded at that
  registrar with the expiry date the account gives, and marked verified.
  Where the registrar says which domains it lists for sale (Dynadot does),
  those it doesn't list come in internal, unless you untick that or the
  row says (`--unlisted=import` in Drush). The provider's *Registrations*
  tab links here when there are such domains.

New domains get the chosen product type, store, sale terms (buy now, offers,
listed on marketplaces), published status and whether they are internal
where their row says nothing. A row without a price gets the price of the
first matching pricing rule (Commerce Domains Pricing), else the import's
default price. Domains already in the catalog are skipped,
or updated: the row's price and sale terms replace theirs, and whatever the
row leaves out stays as it is, as does whether the domain is published. A
price without a currency stays in the domain's.

Names must be registrable domains and appear once. *Dry run* reports without
saving anything. The report at the end lists what was created, updated and
skipped, and each row's error with its line.

*Look up registrars* asks the registry's public RDAP service who holds each
domain without a registrar, and records the provider of that name with the
expiry date: GoDaddy matches "Go Daddy, LLC". Registrars without a provider
are listed, to add one. ccTLDs that publish no RDAP service, such as .io, .co
and .me, are not looked up.

The page takes up to 10,000 rows. Drush takes any number, with the same
choices. Files ending in .txt are read as lists, unless `--format` says
otherwise. The command exits with an error when any row fails, so a dry run
can check a file first:

```sh
# Check a CSV file, listing what would happen to each domain.
drush commerce-domains:import domains.csv --dry-run --details
# Import a list as offers-only domains at 2,500 EUR, unless a line has a price.
drush cdi names.txt --price=2500 --currency=EUR --no-buy-now
# Update the price and sale terms of domains already in the catalog.
drush cdi domains.csv --update
# Import the Dynadot domains the catalog lacks, unpublished.
drush cdi --from-registrar=dynadot --unpublished
# Import domains kept for your own use: internal, never for sale.
drush cdi own.txt --internal
# Import domains sold on this site only, not on marketplaces.
drush cdi site-only.txt --no-marketplaces
```

Sites map their own columns by subscribing to `DomainImportEvent`. It holds
the row as given, the product and variation before they are saved, and
whether the domain is new, in dry runs too. `value()` finds a cell by its
header, and `setError()` refuses the row.

## Pricing

Commerce Domains Pricing (submodule) prices domains that come without a
price, rounds prices, and keeps a price in each currency. Its settings are at
*Commerce → Configuration → Domains → Domain pricing*, for *Administer domain
pricing*.

- **Pricing rules.** A domain saved without a price, as imports save them,
  gets the price of the first rule that matches it, from the top: by
  extension, label length, word count and Atom premium, each left empty to
  match any domain. A domain with a price keeps it, also when the rules
  change; *Apply pricing rules* on the Domains overview reprices a selection.
  Words are counted from capitals and hyphens in the name as written:
  LearnPhpOnline.com has three, and a name written in lowercase has one.
  Code asks `PriceRules::price('Example.com')`.
- **Rounding.** A step covers the amounts up to its limit, and rounds them
  up, down or to the nearest multiple of an amount, less a fixed amount, at
  most a cap. Up to 10,000 up to a multiple of 100 less 1, and above that to
  the nearest 100, turns 1,234 into 1,299 and 12,345 into 12,300. *Drop the
  cents first* counts 1,200.50 as 1,200. The same steps apply in every
  currency. The steps round prices in other currencies and repriced domains;
  a domain's own price, and the prices rules give, are kept as entered.
- **Prices in other currencies.** On each save of a domain, its price is
  converted into each enabled currency, or the ones chosen, its own included,
  at [Commerce Exchanger](https://www.drupal.org/project/commerce_exchanger)
  rates, then rounded. The prices go into a price list
  ([Commerce Pricelist](https://www.drupal.org/project/commerce_pricelist)),
  one item per currency, and into price fields named after the currency, such
  as `field_price_usd`, where the variation has them. Name that list on the
  marketplace providers and they list the rounded prices; a disabled list
  keeps them off the storefront. Currencies without a rate keep what they
  have. Syncing saves, such as migrations, keep the stored prices, and the
  list follows the fields. *Update every domain now* applies changed settings
  to the whole catalog; otherwise each domain follows on its next save.

## Bulk editing

Commerce Domains Bulk editing (submodule, needs Commerce Domains Pricing)
adds actions to the *With selection* list of the Domains overview, next to
Commerce's *Publish product* and *Unpublish product*. Tick domains on the
page, choose an action, and a form asks for the rest:

- *Apply pricing rules* shows the price each domain would get, and sets it.
  Domains no rule matches keep theirs.
- *Reprice by a factor* multiplies the prices, 1.1 for 10% more, rounds them
  with the rounding steps, and can multiply the minimum offers and floor
  prices that domains set too.
- *Set sale terms* leaves each term as it is or sets it for all: buy now,
  offers, minimum offer, floor price and lease-to-own. The amounts and the
  lease can also go back to being worked out from the price.
- *Choose marketplaces* lists the domains on all marketplaces, keeps them off
  all, or off the ones ticked.

The changes are saved in batches, on the domains the user may edit, products
and variations. Prices in other currencies and marketplace listings follow,
as on any save. Installing the module on a site whose Domains overview is
older adds the bulk form to the view.

## Domain management

Registrars whose API can change domains add a *Registrar* tab to the domain's
product, for people with *Manage domains at registrars*: the registration as
the registrar has it, the nameservers, the transfer lock, automatic renewal,
and the transfer auth code. Sold domains have none. The page reads the
registrar each time it is shown, and every button acts on the registrar at once. Changes are logged
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
   after 14 days, which *Commerce → Configuration → Domains → Domain offers*
   changes.

The settings page also sets the least price that takes offers, in each
domain's own currency, whether Atom premium names take them, and whether
buyers must give a phone number. With
[Phone Number](https://www.drupal.org/project/phone_number) installed, the
phone field has a country list and checks the number. Sites add their own
rules by subscribing to `OfferAllowedEvent`.

Against spam, the form takes five offers an hour from one visitor. For
more, add its form ID, `commerce_domains_offer_form`, to
[Antibot](https://www.drupal.org/project/antibot), which works on cached
pages. A CAPTCHA point works too, but only where the page isn't cached for
everyone: a cached form shares one CAPTCHA session among its visitors.

Commerce Domains Offers via Escrow.com (submodule, needs
[Commerce Escrow](https://www.drupal.org/project/commerce_escrow)) adds a
*Negotiate on Escrow.com* choice to the form, for prices in USD, EUR, GBP,
AUD or CAD while an Escrow Offer gateway is enabled. The buyer goes on to
Escrow.com to make the offer, and the negotiation, payment and transfer run
there; the offer on the site follows Escrow.com's webhooks to accepted,
purchased or declined, and doesn't expire on the site. Once accepted, the
domain is off sale, with any other variation of it. The *Escrow.com offer*
condition keeps the other payment gateways off those orders, and the Escrow
Offer gateway on them. Choose the gateway, and whether the choice starts
ticked, on the offer settings page.

## Handing domains over

Commerce Domains Handover (submodule) keeps a handover for each domain an
order sells, from payment to the buyer's account.

1. **It starts when the order is paid in full**, and emails the buyer what
   happens next, from the store's address: a push to their account at the
   registrar, or a transfer with the auth code, as they chose at checkout.
   Placing the order already took the domain off sale, so nothing is lost by
   waiting for the money, and nothing that can't be undone happens before
   it: an order paid by bank transfer starts when its payment is recorded on
   the *Payments* tab. Orders paid on Escrow.com are left to Escrow.com,
   which holds the money until the buyer confirms they have the domain.
   *Start the handover* on the order's *Handover* tab starts any other
   placed order by hand, such as one paid before the module was installed.
   Canceling the order cancels its handovers.
2. **The order's *Handover* tab** shows each domain's state, where the buyer
   wants it, the registrar, and what to do next, with a button for each step
   open now. Each step asks first:
   - *Unlock at Dynadot*, say, turns the transfer lock off through the
     registrar's API.
   - *Email the auth code* gets the code from the registrar's API and emails
     it to the buyer, with how to start the transfer. Where the API can't
     give it, or the registrar emails it to you (see *Domain management*),
     enter it yourself and it goes the same way. The code is never shown,
     kept or logged, and the email goes to the buyer alone.
   - *Mark pushed*, once you have pushed the domain to the buyer's account
     at the registrar, which no API here does. The buyer is told it is on its
     way, and to accept it if their account asks.
   - *Mark transferred*, once the domain has left your account, records the
     day. The buyer is emailed that it is theirs, and the product no longer
     names your registrar, so registrar syncs stop expecting the domain
     there. A sync that still finds it in the account records it there
     again, so the form warns while the account holds it.
   - *Cancel the handover* emails nobody, and leaves the domain off sale.
3. **Commerce → Domain handovers** lists the open handovers, longest waiting
   first. While a domain's handover is open, its *Registrar* tab points to
   the order's *Handover* tab.

Steps need *Hand over sold domains*; *View domain handovers* shows the tab
and the list. With Commerce Log, each step and email is in the order's
activity, without codes. The emails use the
`commerce-domains-handover-mail.html.twig` template, laid out like the order
receipt; a theme overrides it, or one email with a `--started`,
`--auth-code`, `--pushed` or `--transferred` suggestion. Modules that log or
queue outgoing mail keep the auth code email like any other.

Uninstalling takes the handover steps out of orders' activity, which
Commerce Log can't show without the module.

## Running the portfolio

Commerce Domains Owner (submodule) adds a *Domain costs and sale* trait,
turned on for the product types that sell domains when the module is
installed (turn it on for types added later): the purchase price and date,
the yearly renewal cost, auto-renew, whether to keep the domain or let it
expire, and the sale: what it sold for, when, through which marketplace,
and for a lease to own the number of monthly payments. The product form
shows them in vertical tabs: *Domain details* (expiry, registration date,
registrar, internal, Atom premium), *Costs and renewal*, *Sale*, open once
the domain sold, and *Valuation*.
Only people with *View the domain dashboard* or *Manage the domain
portfolio* see these fields, in forms, Views and JSON:API alike. A sale on
the site fills in the sale from its order, and cancelling the order clears
it; a sale a marketplace reports fills in the date and the marketplace, and
you enter what it brought after commission (for a lease to own, what the
payments add up to, and their number). Orders on the site are paid at once.
A marketplace with a sales report (Atom) fills those in too: the sale date
replaces when the sale was found, and what it brought after commission and
the number of payments are filled in where empty, so your entries stand.
A sale is only recorded once the report says what it brought: until then
the domain is off sale but has no sale, so saving it, e.g. as internal,
stores none. Cron reads the reports once a day, as the amounts are often
worked out days after the sale, and then records the sale of a domain off
sale with none recorded. Sales on the site or another marketplace stand.

```sh
drush cdsales atom --dry-run
drush cdsales atom --domains=example.com
```
Auto-renew follows the
registrar where its API reports it, the way expiry dates do.

- **Appraisals**: with an enabled provider that appraises (Atom, with its
  seller API token), cron appraises the portfolio: domains never appraised
  first, then appraisals older than 90 days, up to 50 a day (*Domain
  portfolio* settings; Atom allows 100 a day on most accounts). Sold domains
  are skipped. The *Valuation* tab shows the estimate in dollars, the score,
  how many extensions the same name is registered in, the date, and what
  raises or lowers the value; Views lists them as fields. A failure, e.g.
  Atom's limit reached, stops appraisals until the next day. GoDaddy's
  GoValue API is restricted to GoDaddy's partners, so it isn't supported.

  ```bash
  drush cdap --limit=20
  drush cdap --domains=example.com
  ```

- **Sales and profit** (*Commerce → Domains*): what each sold domain earned,
  and the totals by year. Profit is the sale price minus the purchase price
  minus the renewals paid. Renewals paid is the yearly renewal cost times
  the renewals while the domain was held: one on each anniversary of its
  expiry date (or of its purchase date, without one) between the purchase
  and the sale. Costs in another currency than the sale are converted at
  Commerce Exchanger's rates, when it is installed. A lease to own counts
  in full in the year it started, with the month its payments end; if the
  buyer stops paying, clear the sale fields and put the domain back on
  sale.
- **Expiring soon**: the domains that expire within 60 days, and those that
  expired in the last 30, which registrars still renew. *Keep* or *Let
  expire* each one. A domain left to expire goes off sale (its product is
  unpublished) 14 days before its expiry date, and keeping it again puts it
  back on sale. Deciding changes nothing at the registrar: with *Manage
  domains at registrars*, separate buttons turn auto-renew on or off there,
  for registrars whose API can (see *Domain management*).
- **Notifications** to the owner, at the address set at *Commerce →
  Configuration → Domains → Domain portfolio* (the store's email until
  then). Each kind can be turned off:
  - a domain sold on the site, with where the buyer wants it;
  - a domain a marketplace sold;
  - a failing marketplace sync, at most once a day for each marketplace;
  - a daily digest of the expiring domains that need a decision or a
    renewal by hand, or that you let expire but still renew automatically;
  - domains a registrar's account no longer holds, other than sold ones;
  - offers that expired.

  The offers module sends its own mails, to the same address when its own
  is not set. No mail carries a credential, an auth code or a buyer's offer
  link.
- **The dashboard** (*Commerce → Domains → Dashboard*):
  - offers waiting for an answer;
  - recent sales and their profit;
  - the domains expiring soon;
  - each marketplace's sync: last check and push, listings out of date or
    refused, the last failure, pushes waiting in the queue;
  - when each registrar account was last read;
  - the next steps of setting up the site: add a registrar, import your
    domains, connect a marketplace, enable a payment gateway, point parked
    domains at `/domain-lander` (done once a visitor comes through it), and
    set the notification address.

  The page asks no registrar or marketplace for anything.

Uninstalling takes the trait off the product types, and keeps the fields
and what was entered in them.

## Adding a provider

Write a plugin in `src/Plugin/CommerceDomains/Provider` with the
`#[CommerceDomainsProvider]` attribute, and list the settings that hold
credentials in `CREDENTIALS` (and those only some calls need in
`OPTIONAL_CREDENTIALS`), each with a label in the plugin's config schema,
which the settings form shows. Read them from `$this->configuration` after
`assertReady()` (and `assertCredential()` for an optional one): that fills
the ones `settings.php` leaves empty from the provider's keys, while
`getConfiguration()` leaves keys' values out, so they never reach config.
Give the API's rate limit in `REQUESTS_PER_MINUTE` to get a setting for it.

- For a marketplace, extend `MarketplacePluginBase` and implement
  `buildListing()`, `fetchListings()`, `saveListings()` and
  `deleteListings()`. Return the marketplace's status from `saleStatus()`
  for a listing that shows a sale, and a reason from `lockReason()` for any
  other listing to leave alone.
- For a CSV file, implement `CsvExportInterface`: `csvHeader()` and
  `csvRow()`, plus `csvFiles()` when the marketplace takes several. A
  marketplace without an API extends `ListingPluginBase`, which has the price
  factors and the shared pricing rules.
- For a registrar, implement `RegistrarInterface` with `RegistrarTrait`:
  fetch the account's records in `registrationRecords()` and map one to a
  `Registration` in `registration()`. A registrar-only plugin extends
  `ProviderPluginBase`.
- For listing views, leads and offers, implement `ListingStatsInterface`:
  `fetchStats()` returns a `ListingStats` per listed domain.
- For appraisals, implement `AppraisalInterface`: `appraise()` returns an
  `Appraisal` (value in dollars, score, extensions registered, signals), and
  throws once the service's daily limit is reached.
- For sales reports, implement `SalesReportInterface`: `fetchSales()`
  returns a `MarketplaceSale` per sold domain (date, price, what the seller
  received, lease payments).

Put the HTTP client in `src/Clients`, extending `ApiClient` for retries,
rate-limit waits and keeping credentials out of error messages.
