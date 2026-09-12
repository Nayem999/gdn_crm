---
paths:
  - 'app/Domain/Products/**, app/Livewire/Products/**, resources/views/livewire/products/**'
---

# Products

## Prices resolve in one order, and a bundle is a product
**`PriceResolver` is the only thing that answers "what do we charge".** The order is: the named book (if it applies today and lists the product) → the default book, on the same terms → the product's own `list_price`. Every document that sells something must go through it, or two screens will disagree about a price.

A book that does not apply — switched off, or outside its dates — is **stepped over**, never treated as pricing the product at nothing. That is the difference between a promotion ending and every product becoming free.

A named book **falls through to the default** for anything it does not list, because a book is an override list rather than a complete catalogue. Somebody building a reseller book adds the twenty products that differ; the other four hundred keep their usual price.

**A bundle is a row in `products`** with `kind = bundle` and `product_bundle_items` holding its parts — not a second table. Everything that sells something then handles one shape. `SaveProductAction` refuses a bundle that contains itself at any depth: every price, cost and line-item explosion walks the tree, and a cycle recurses until the process dies. Deleting a product that is inside a bundle is refused with the bundle named (the FK would refuse it anyway, but a database error is not something a screen can show).

Money is `decimal(15,2)` and cast `decimal:2`, never float. Round once, at the end of a line — rounding a unit price first and multiplying turns a third of a penny into a penny per unit.

Single currency, from the company profile. Price books vary the price, not the currency; multi-currency needs a rate table and its own rounding rules, and a `currency` column alone would be worse than nothing.
