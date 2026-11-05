# Rang Mahal retail operations upgrade — 5 November 2026

## Customer and staff changes

The mobile hero is vertically centered; desktop positioning is unchanged. Product detail galleries fit the complete image, with thumbnails, previous/next, mobile touch gestures, fullscreen viewing, zoom, keyboard navigation and an optional video. Cards retain their consistent crop.

Stitched purchases require an enabled XS, S, M, L or XL size. Size follows each bag line through checkout, order confirmation, tracking, admin and notification emails. Different sizes remain separate lines while reserving the same applicable stock. New orders do not accept body-measurement forms. Historical measurements and existing purchase snapshots remain intact.

Guest checkout validates name, phone, email, address, Pakistan, city and postal code on both client and server, with inline errors and preserved input. Existing cash-on-delivery and payment controls remain in force. Footer help links precede the contact email and existing social links.

Admin overview metrics precede the collapsed filters. Permissions are grouped in expandable sections, with existing assignments preserved. Navigation includes brands, categories, products, Add New Product, inventory and media.

## Schema

Additive migration: `2026_11_05_000000_retail_operations.php`.

- Products: publication status, enabled sizes, optional size chart and notes, video URL, inventory tracking, low-stock threshold, weight and delivery restrictions.
- Categories: optional image.
- Orders: country, delivery arrangement, actual dispatch/delivery timestamps and customer-facing delivery notes.
- Order items: selected size, purchase-time chart snapshot, stock-reservation flag. Existing price, SKU, stitching charge and historical measurements are retained.
- New tables: `media_assets` (actual upload metadata and quarantine state), `product_media` (association, order, primary image), `order_email_attempts` (actual transport attempts).

Existing active products remain published; inactive products remain inactive/archived. No products, orders, customers or stock are seeded. New timestamps/sizes are not invented for historical orders. Migration rollback deliberately refuses destructive data removal; recover using a verified backup.

## Product workflow

1. Basic information: name, description, brand/category selection or inline creation, SKU, featured status.
2. Media: five configurable image slots and one video, preview/replace/remove/reorder/set primary.
3. Prices: existing unstitched price plus stitching charge, discounts and permission-protected costs.
4. Stitching: enable stitching, select available sizes, optionally override the global garment chart.
5. Inventory: shared/separate variation stock, tracking flag, low-stock threshold.
6. Shipping: weight, shipping information and delivery restriction notes.
7. Review: real gallery, pricing, sizes and inventory, then Save as Draft or Publish.

Drafts can be incomplete and remain hidden from customers. Publishing requires complete basic information, an image and valid pricing. CSV imports without descriptions become drafts for review. Disabling stock tracking displays availability without inventing a numerical stock count; cancellation does not restock items that never reserved inventory.

## Sizing data that the business must supply

All 12 requested garment dimensions are editable globally under business settings or per product. Stored inches/cm convert with 1 inch = 2.54 cm. Orders preserve the applicable purchase-time chart.

No verified Rang Mahal finished-garment, saree or pallu measurements were supplied. Unknown dimensions remain blank and display a dash, with an explicit explanation. Enter actual garment measurements before relying on the chart for fit advice.

A separate, attributed manufacturer **body** reference is included for XS–XL bust/waist/shoulder. It is not presented as Rang Mahal garment measurements or a universal sizing standard. Primary source: [Suta Code White product and linked size guide](https://suta.in/products/code-white). This reference does not establish sleeve, armhole, neck, saree or pallu dimensions.

## Fulfillment and notifications

Existing stored lifecycle remains Pending → Confirmed → Processing → Packed → Shipped → Out for Delivery → Delivered, with customer labels “Ready for dispatch” and “Dispatched”. Staff explicitly review and confirm transitions. Cancellation before dispatch restores reserved stock once. Delivered orders may be marked Returned; returns do not automatically imply restocking or refunds.

Couriers can be prepared during processing, but customer tracking/emails withhold shipping details until dispatch. Courier dispatch requires a carrier name; requiring a tracking number is configurable. Direct delivery/collection does not require courier data. Dispatch records the actual transition time unless a valid actual date is provided. Payment records remain independent of delivery status.

Existing durable outbox, retry worker, mail configuration and cron are retained. New attempts record sending, transport acceptance or failure. Repeated identical status requests do not enqueue duplicate messages. Authorized staff can view history. Old attempts are not fabricated. Transport acceptance is not proof of inbox placement; SPF/DKIM/DMARC and the receiving provider affect delivery. No provider can guarantee every email avoids spam.

## Media storage and maintenance

Images: JPEG/PNG/WebP, maximum 8 MB each, 4096 pixels per side and 12 megapixels. Uploads are orientation-corrected and stored as WebP at quality 90, retaining allowed source dimensions and transparency. These are bounded high-quality derivatives, not original-file archival storage. Existing smaller images cannot gain missing source detail.

Video: one MP4 or WebM file or direct HTTPS `.mp4`/`.webm` URL. Default upload cap 20 MB, configurable to 50 MB. Direct video files are supported; arbitrary YouTube/Vimeo page embeds are not. Uploaded video responses support browser byte ranges. Images/videos use generated names and validated MIME types, outside executable public directories. Metadata records actual dimensions where known; legacy unknown metadata is labelled honestly.

Image-slot cap defaults to five and is configurable (up to ten). PHP web limits in `public/.user.ini`: upload 50 MB, POST 64 MB, ten files, memory 256 MB; shared-host limits may impose a lower ceiling. Uploads are sequential. Production serves only the public directory and denies dotfiles.

Removing a product image detaches the association; archived product media remains referenced. `php artisan media:prune` is **dry run** by default and considers only registered uploads older than seven days. `--execute` moves genuinely unreferenced managed files to private `storage/app/private/media-trash/YYYY-MM-DD` and marks the record quarantined. It does not permanently erase the file. Product JSON, primary image/video, category images and association rows are all checked. Unregistered legacy files, including the four previously retained test images, are untouched. Review dry-run output before maintenance; no automatic prune job is installed.

Local-only `/gallery-demo` demonstrates three clearly labelled styling examples and an openly licensed MDN playback sample. It creates no inventory and is unavailable in production. These are not represented as multiple verified views of one actual saree.

## Verification and deployment

Run `npm run build` and `C:\xampp\php\php.exe artisan test` against the development MySQL database only. Database transaction tests roll back order/user/product fixtures; mail uses fakes or array transport. Real video upload/range playback, five-image uploads, order/primary metadata, drafts, publishing, authorization, standard size snapshots, required checkout fields, stock behavior, sequential dispatch, notification deduplication and quarantine retention are covered.

Verification result: production build passed; 31 tests passed. Browser checks confirmed the 390 px mobile hero is centered, the complete product image fits the fullscreen viewer, stitched size M persists through bag/checkout, missing-field errors retain typed values, the demo gallery changes views and its video reaches a ready playback state, and the admin wizard exposes five media slots. Touch swipe handlers are implemented; physical-device swipe remains a manual acceptance check. No production customer order was placed for QA.

Sync built code to the separate XAMPP installation with `scripts/sync-xampp.ps1`, preserving private environment/runtime/dependencies. Commit frontend build assets and use `[skip netlify]`; Netlify does not host Laravel. The cPanel deployment script backs up files/database, runs additive migrations, publishes public assets and rebuilds caches. Do not run seeders or production tests.

Limitations: no courier-provider API was requested/configured; tracking data is entered by staff. Delivery restrictions and weight are informational and do not add a new rate engine. Actual garment dimensions and real multi-view product media must come from the business. No real customer order is created just to test deployment.
