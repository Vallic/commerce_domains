`public_suffix_list.dat` is a snapshot of the [Public Suffix List](https://publicsuffix.org/),
distributed under the Mozilla Public License 2.0 (see the header of the file).

It is only a fallback: cron replaces it with a fresh copy once a week, stored in
the `commerce_domains` key/value collection.
