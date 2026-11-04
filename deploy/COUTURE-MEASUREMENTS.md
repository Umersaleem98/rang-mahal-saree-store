# Couture identity and tailoring checkout — 4 November 2026

Existing Laravel/React architecture, product prices, stock and orders are preserved. Mobile now uses one hamburger navigation; the category selector below the compact hero uses four columns/two rows for the current seven options. The duplicate collection heading is removed. Story copy is the supplied one-paragraph version. Desktop navigation remains available. Footer Explore/Help columns adapt to narrow screens; social links use the supplied Instagram/Facebook URLs and a disabled, unassigned WhatsApp button until the business supplies its URL.

## Assets

Transparent gold palace/saree logo: `public/images/rang-mahal-couture.webp` (768×768) and `rang-mahal-couture-small.webp` (256×256), responsive sources. Favicon, 32px browser icon and 180px touch icon derive from the same full composition; no cropping or stretching is applied. Header, footer, tracking and admin use the new identity. Original prior logo assets remain for compatibility. Alpha at the source corner is fully transparent; tested on the ivory surface. Built-in imagegen was used for background extraction; PHP GD only exports/resizes formats while preserving alpha.

Final image-edit prompt: “Remove only the flat black background to true alpha transparency, including the empty negative spaces around and inside the architecture and calligraphy. Preserve the entire original composition and proportions: gold palace arch and domes, saree-clad woman with flowing embroidered pallu, exact Urdu lettering رنگ محل, exact English RANG MAHAL, gold horizontal flourishes and bottom ornament. Preserve gold texture and fine ornamental detail and any intentional dark fabric/shading that belongs to the woman or embroidery. Do not redraw, redesign, change the spelling, add, crop, distort or stretch the logo. Full square composition, clean sharp edges, transparent background, no checkerboard baked into pixels.”

## Tailoring data and configuration

Additive migration `2026_11_04_000000_stitching_measurements.php` adds products.stitching_service (default blouse) and nullable order_items.measurement_data JSON. Existing order items retain null measurement data; no measurements are fabricated or backfilled.

Business settings → Stitching measurement requirements lets authorized admins choose shown/required fields for Blouse, Saree, and Blouse & Saree services. Products → Edit → Stitching options selects the matching service. Default blouse requirements contain eleven blouse fields; saree service requires waist and saree length with optional pallu length. The business should review these service requirements against its actual tailoring workflow. Required fields must be enabled; invalid definitions are rejected on the backend.

Customers see the fit notice immediately after selecting Stitched. At checkout, each stitched line gets its own profile. Measurements for an earlier product with the same service can be copied instead of re-entered. Values remain in centimetres internally while unit switching converts the display; original chosen unit/submitted values, normalized centimetres, service label, fit and special instructions are stored as an immutable order-item snapshot. One profile applies to all quantities of a product option; customers needing different fits for identical pieces are instructed to place separate orders or contact the store. No body measurements are saved in device storage.

Review is available before submission and on the confirmation. Order management staff see the saved profile in the order details. Public product payloads and reference/email tracking do not disclose body measurements. Server checks the actual product option, relevant service, required fields, finite numeric ranges and instruction length. Unstitched lines require no profile and reject supplied measurements. Failed validation rolls back stock changes. Idempotent retries retain the same order, with measurement edits requiring a new checkout attempt.

Social URLs are editable under Business settings → Social media, validated as HTTPS. Blank WhatsApp stays disabled and has no invented phone number. Email setup remains on hold: the SMTP mailer/cron are not enabled by this deployment.

## Verification

Run Laravel tests only against development MySQL and build Vite assets. Functional tests cover mixed options/services, independent profiles, inches-to-cm normalization, invalid/negative/missing values, stock rollback, idempotency, permissions, hidden measurements and business settings. Browser checks cover phone/tablet/desktop layouts, two-row categories, menu toggle, stitched notice, right-side bag, checkout unit round-trip (38.1 cm ↔ 15 in), footer links and admin controls. Never create test orders in production; compare the private pre-deployment baseline with the original products, raw variants, orders, order lines, users and payment configuration after the live additive migration.
