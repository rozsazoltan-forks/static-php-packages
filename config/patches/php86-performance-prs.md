# PHP 8.6 performance patch

`php86-performance-prs.patch` is the combined final diff of these php-src PR heads against the `php-8.6.0RC2` source archive, applied in this order:

| PR | Head commit |
| --- | --- |
| [#22729](https://github.com/php/php-src/pull/22729) | `6abe2e2da4d387104f1f55d57883866c46a312a3` |
| [#22728](https://github.com/php/php-src/pull/22728) | `f44fe350a88b67c29a677c7d751a28ac47ea8010` |
| [#22722](https://github.com/php/php-src/pull/22722) | `ab7759262a7dc8f3f3f9f5a86d674e8252ff5454` |
| [#23604](https://github.com/php/php-src/pull/23604) | `c93fd4c5da840e4ff3d92b587927149619e23ebd` |

The #22729 diff uses RC2's `constant_text` variable in readline completion. The patch is generated with `git diff --text`.
