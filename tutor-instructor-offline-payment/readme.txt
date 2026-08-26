=== Tutor LMS Instructor Offline Payment ===
Contributors: yourname
Tags: tutor lms, lms, offline payment, manual payment, instructor
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Replaces the Tutor LMS checkout with an offline checkout where every course author publishes their own payment details and approves their own sales.

== Description ==

Tutor LMS collects money into one place: the site owner's gateway account. On a
marketplace where instructors are independent — a language school, a group of
tutors sharing a site, a country where card gateways are impractical — that is
the wrong shape. This plugin turns the checkout around.

* Each instructor publishes their own payment methods from their Tutor dashboard:
  bank transfer, mobile money, cash in person, a QR code image, anything they can
  describe in words.
* At checkout the student sees the payment details of the author of the course
  they are buying, pays them directly, then reports what they paid — with an
  optional receipt upload.
* The order is created **unpaid**. Only the course author (or an administrator)
  can confirm it, and confirming is what enrols the student.
* A cart holding courses from several authors becomes **one order per author**, so
  each author only ever sees, confirms and is credited for their own sale.

Everything else stays native Tutor: orders appear in Tutor's own order screens,
enrolment goes through Tutor's `mark_as_paid()`, and Tutor's earnings and email
pipelines fire exactly as they do for a gateway payment.

= What the instructor gets =

Three new dashboard pages:

* **Payment Details** — publish up to ten payment methods, each with a name,
  free-text instructions, an optional image, and a show/hide switch. Plus one
  general note shown above them all at checkout.
* **Offline Payments** — every payment students say they made to them, with the
  reference, the message, the receipt, and two buttons: *I received this payment*
  and *Reject*. Rejecting asks for a reason, which the student sees.
* **My Payments** — the same student-side view every buyer gets, because an
  instructor buying a colleague's course is also a student.

= What the site owner gets =

* **Tutor LMS → Offline Payment** — settings.
* **Tutor LMS → Offline Payments** — a read-only overview of every offline order
  across all authors, with the same two buttons when administrator approval is
  enabled.
* Offline orders are also annotated inline in Tutor's own order list: who is owed
  the money, the reference, a link to the receipt, and the two decisions.

= Privacy =

Receipts are stored **outside** the media library, in
`wp-content/uploads/tutor-offline-payments/`, under randomised filenames, and are
served through a capability-checked endpoint. Only the student who uploaded the
file, the course author who is owed the money, and administrators can open one.

Payment instructions and method images, by contrast, are published deliberately:
every buyer of that author's courses can read them. The instructor dashboard says
so on the form.

== Installation ==

1. Install and activate Tutor LMS 3.0 or newer.
2. Under **Tutor LMS → Settings → Monetization**, set *Monetize by* to
   **Tutor** (the native e-commerce engine) and make sure the checkout page
   exists.
3. Upload this plugin to `wp-content/plugins/` and activate it.
4. Visit **Tutor LMS → Offline Payment** to review the settings.
5. Ask each instructor to fill in **Dashboard → Payment Details**.

The plugin flushes rewrite rules once on activation so the new dashboard URLs
resolve. If a dashboard page 404s, re-save your permalinks.

== Frequently Asked Questions ==

= Does this touch the Tutor LMS plugin files? =

No. The checkout page is replaced through Tutor's own `tutor_get_template_path`
filter, and everything else hangs off documented Tutor hooks. Tutor updates
cleanly.

= Can I keep online payments as well? =

Yes — enable *Online gateways* in the settings. The offline checkout then shows a
"Pay online" link that hands the request back to Tutor's stock checkout. Be aware
that an online payment is a single combined order paid into the site owner's
gateway account, split by Tutor's normal commission rules; it is not the
per-instructor flow.

= What if an instructor has not published any payment details? =

By default their paid courses cannot be checked out, and the student is told to
contact them — better than taking an order nobody knows how to pay. Turn off
*Courses without payment details* to allow it anyway.

= How is a theme override done? =

Copy any file from this plugin's `templates/` directory to
`wp-content/themes/<your-theme>/tutor-offline-payment/` at the same relative
path. If your theme already overrides Tutor's own
`tutor/ecommerce/checkout.php`, that file keeps winning and this plugin leaves
the checkout alone.

= Which filters can I use? =

* `tioc_should_replace_checkout` — decide per request whether to replace the checkout.
* `tioc_course_payee_id` — change who is paid for a given course (defaults to the post author).
* `tioc_template_map` — add or repoint templates.
* `tioc_can_manage_order` — widen or narrow who may confirm an order.
* `tioc_checkout_submit_text`, `tioc_after_checkout_redirect`.
* Actions: `tioc_order_submitted`, `tioc_order_approved`, `tioc_order_rejected`,
  `tioc_checkout_completed`, `tioc_method_saved`.

== Known limitations ==

Please read these before going live. None of them are bugs to be fixed later —
they are consequences of money changing hands outside the site.

1. **The earnings ledger is a claim, not a fact.** Tutor records an admin
   commission on every sale, but with offline payment the *author* holds the cash,
   so the ledger will show the site as owing the author their share while in
   reality the author owes the site its commission. Set *Earnings ledger* to
   "Credit the full amount to the course author" if you would rather the ledger
   match reality and settle commission yourself; leave it on the default if you
   want Tutor's withdrawal flow to keep working as usual. There is no setting that
   makes both true at once.

2. **Refunds on offline orders must be handled manually.** Tutor decides whether
   an order can be refunded through its gateway by asking
   `OrderModel::is_manual_payment()`, which this plugin's payment method is not
   registered with (registering it triggers a pre-existing bug in that method's
   loop). Refund an offline order by having the author return the money directly
   and then marking the order refunded in Tutor's own order screen.

3. **Receipt protection relies on `.htaccess` on Apache.** The plugin writes a
   deny rule, an `index.php`, and randomised filenames into the receipt folder.
   On **nginx**, `.htaccess` is ignored — add a location block denying direct
   access to `/wp-content/uploads/tutor-offline-payments/`, or the randomised
   filename is the only thing standing between a receipt and a stranger with the
   URL.

4. **Flat-amount coupons are dropped in multi-instructor carts.** A "$10 off"
   coupon cannot be applied once when the cart is split into one order per
   author — it would come off every author's total. When that happens the student
   is told the coupon does not apply to a mixed cart. Percentage coupons split
   correctly and are unaffected.

5. **A coupon's "minimum purchase" condition is evaluated per author group**, not
   against the whole cart, for the same reason. A $50 minimum on a cart holding
   $30 from two different authors will not qualify.

6. **Subscription and membership plan checkouts are left to Tutor.** A checkout
   carrying a `plan` parameter falls through to the stock page, because a
   recurring charge cannot be settled by a one-off manual confirmation.

7. **Buy-now and cart flows are both supported, but tax is Tutor's.** Tax is read
   from Tutor's own settings and applied per group; it is not recalculated per
   instructor jurisdiction.

== Screenshots ==

1. The replaced checkout, with one payment card per course author.
2. An instructor publishing their payment details.
3. An instructor confirming a payment.
4. The site-wide overview under Tutor LMS.

== Changelog ==

= 1.0.1 =
* Fix: instructors and students could not open payment receipts. Receipts are now served from a front-end endpoint instead of wp-admin, which Tutor LMS and security plugins block for non-administrators.
* Fix: instructors on older orders (saved before the payee meta existed) are matched by course author.
* Improve: separate messages for expired links, logged-out visitors and genuine permission failures.

= 1.0.0 =
* Initial release.
