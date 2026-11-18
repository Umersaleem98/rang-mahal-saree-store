# Rang Mahal business platform handover

The existing Laravel 12, MySQL, React/Vite application is preserved. Production uses the existing GoDaddy deployment; local XAMPP remains a separate source/runtime copy. No new customer login or payment gateway is introduced.

## Database update

`2026_10_31_200000_business_platform.php` adds:

- `business_settings`: brand, shipping, stitching, reference format and return-policy settings.
- `order_sequences`: transaction-locked daily counters per prefix/category code.
- Product SKU, stitching enable/override/stock mode, restricted nullable cost fields, shipping information and SEO fields.
- Order payment/collection/refund fields, courier details, estimated date and recorded status history.
- Order-item stock-pool ID, stitching charge, nullable historical unit cost and category/brand/SKU snapshots.
- Staff last-login timestamps and guest/customer classification profiles.

Existing order references, line prices, quantities, customer records and available stock are retained. Existing payments remain **Unrecorded** rather than inferred from delivery. Old cost and event-time fields stay unknown. Only the exact former store brand label is renamed; historical order/customer text is not rewritten.

## Pricing and delivery

The default standard delivery charge is PKR 350. Free delivery applies **strictly above** PKR 10,000; an order of exactly PKR 10,000 pays the standard fee. These defaults were selected from the supplied requirements and are editable in Business settings. The same values feed the announcement, product details, bag, checkout, receipt and policy. Shipping is charged once per order.

Stitched price = effective unstitched selling price + product override or global stitching charge (default PKR 5,000). The unstitched price is preserved. The surcharge is part of the unit price and is not added again at checkout. Each new order item stores the applied surcharge.

Existing enabled, separately stocked stitched variations keep separate inventory. Products whose stitched variation was previously unavailable use their existing unstitched saree pool when stitching is enabled. Shared stock is counted and decremented once, including mixed stitched/unstitched carts. Disable stitching globally or per product when the service is not offered. Separate stitched stock can still be managed explicitly.

## Tracking, receipts and fulfilment

New references use the configured prefix/category code, local order date and database sequence, such as `RM-SR-20261031-000001`. Sequence rows are locked inside checkout transactions; internal IDs remain separate. References cannot be changed through admin APIs. Old formats remain valid. Tracking at `/track` requires the order reference **and checkout email**, for every format.

The tracking page shows real payment state, processing/shipping state, courier fields, optional ETA and actual recorded status events. Older orders without event history show that limitation. Staff can record a courier's HTTPS tracking URL; no automatic courier API integration or delivery location feed is claimed. Marking an order Returned does not automatically refund money or declare returned stock resalable.

Order confirmation includes line quantities/prices, delivery, total, seller/return address and policy information. Email receipts send only through a configured real mailer; log/array transports do not send. Existing COD/bank settings and disabled card-payment behavior remain intact.

## Analytics definitions and privacy

- Recorded net collections = recorded amount paid − recorded refunds. Unknown receipts are never treated as paid revenue.
- AOV = matched order value / matched order count; this is distinct from collected revenue.
- Date filters use order-placement cohorts. Product/category/variant filters select matching orders; merchandise charts filter the corresponding items. Payment/collection KPIs include the matched orders, including shipping. Product/category charts exclude shipping and attribute actual collected money proportionally to each line's share of the order total.
- Delivered units and selling-price statistics use delivered orders. Unranked/unsold products have no fabricated rank.
- Customer spending and profiles use lifetime actual records for customers matching filters. A guest's first order serves as customer-since, never a fabricated registration date. Possible duplicate phone numbers are flagged, not merged.
- Gross profit uses only historical unit-cost snapshots and verified full payments. Product, additional and applicable stitching costs must all be supplied explicitly; unknown costs are not zero. Partial payments, returns and refunds without complete allocation/cost-credit accounting make profit unavailable. Net profit is not claimed; operational expenses are not collected.
- Empty periods produce meaningful empty states. Prior-period growth is shown only with comparable data; zero-revenue denominators are not converted into invented percentages.

New `analytics.view` and `costs.manage` permissions are available for explicit assignment. Existing non-owner roles receive neither automatically. Super Admin retains full owner access. Costs are stripped from public and unauthorized staff product payloads. Customer details remain restricted to customer-management permission. Role editing no longer silently selects extra permissions.

## Policy and business information needed

The initial return address is the user-supplied **Rang Mahal, NUST, Islamabad, Pakistan**. Enter the actual support contact, eligibility period, condition requirements, return postage responsibility, exchange/refund conditions and damage procedure in Business settings. Unspecified fields remain unset and prompt customers to contact support; no unconditional refund promise or invented time limit is introduced.

Shipping/returns conditions are displayed before checkout and linked in the receipt. The policy preserves statutory rights. Disclosure and receipt requirements were checked against the [ICT Consumers Protection Rules](https://www.cda.gov.pk/public/Assets/pdf/1699885321_26bb7cbe_2f92_4e9f_9dbf_9a6e6defde10_.pdf) and [Punjab Consumer Protection Act](https://pccmdpunjab.gov.pk/custom/mateen/pdf/ruleregulation/20-Punjab%20Consumer%20Protection%20Act%202005.pdf). Business-specific eligibility and remedy terms still need the owner's completion and legal review; software cannot certify blanket legal compliance.

Privacy copy describes actual guest/order data, staff access, HTTPS, cookies, device storage, operational logs, configured email/courier sharing and possible remote product/font requests. It does not claim perfect security or that operational data is never shared.

## Integrations and known limits

Provide SMTP credentials to send confirmations, a courier account/API specification for automated events, actual cost records and verified payment/refund amounts for financial reporting, and approved support/return terms. There is no fabricated courier feed, accounting ledger for per-item refund cost credits, customer signup dashboard, automatic bank reconciliation or card gateway. The existing guest checkout and authorized staff dashboard are retained; tracking and customer profiles provide order history.

The premium transparent WebP logo was edited with built-in imagegen: alternating upper red/green beads, preserved Urdu/crown branding and an inset lower ornament. Project asset: `public/images/rang-mahal-premium.webp`; favicon and touch-icon exports derive from it. The campaign hero photo is unchanged.

## Testing and deployment

Run `php artisan test` only against development MySQL, then `npm run build`. Tests cover shipping boundary/settings updates, shared stock, mixed options, surcharge/cost snapshots, sequence uniqueness, email verification, permissions, paid/refunded/missing-cost analytics, customer search/profile filters, shipment records, taxonomy renames, SEO routes and existing commerce regressions. Synthetic test records are rolled back and are never used as production analytics.

Build assets are committed because cPanel has no Node runtime. Push to main with an ordinary commit message, then deploy through the existing cPanel/SSH script. It takes private source/public/database backups before migrations. Compare original stock, prices, references and record IDs after deployment. Never reseed or reset production to populate charts.
