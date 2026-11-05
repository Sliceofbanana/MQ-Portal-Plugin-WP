# MQ Portal: Plugin Specification

**Version:** 1.17.1 (database schema 11), 2026-10-14
**Client:** MQ Printing Services (MQ Prints, EasyPrint and the other brands), mqprintsph.com
**Platform:** WordPress plugin on Hostinger. It needs no other server.

---

## 1. Purpose

MQ Portal is one system for the business:

- A staff portal for sales documents, job orders, POS, attendance and reports.
- Customer-facing website features: file upload, online ordering, Get Quotation, reviews and the price list.
- Admin and developer tools inside wp-admin.

It replaces the old EasyPrint app (Next.js) and the separate backend.

## 2. Requirements

| Item | Requirement |
|---|---|
| WordPress | 6.4 or newer |
| PHP | 7.4 or newer (GD or Imagick for photos) |
| Permalinks | Anything except "Plain" |
| Timezone | Asia/Manila |
| HTTPS | Required for offline mode, GPS clock-in and notifications |
| Recommended | WP Mail SMTP (Hostinger mailbox), a Hostinger cron job every 15 min, LiteSpeed Cache (supported) |
| Optional | Cloudflare Turnstile (spam check), Google Places API key (Google reviews), a subdomain such as app.mqprintsph.com |

## 3. Users and access

### 3.1 Customers

- Customers never need an account.
- Each submission records the customer's details, brand, branch and timestamp.
- A submission gets a reference number (e.g. `U-00013`), or a tokenised link for quotations and orders.

### 3.2 Staff

- Staff sign in at `/mq-portal/` (or the portal's own address).
- The portal session is separate from wp-admin. Staff cannot sign in through `wp-login.php`.

| Role | Default access |
|---|---|
| MQ Sales | Sales tab with own documents and job orders, record payments, mark delivered |
| MQ Creatives | Approve files, attach edited designs |
| MQ Production | Production queue, mark printed |
| MQ QC Officer | Production and QC approve/reject, deliver |
| MQ Cashier | POS only |
| MQ Admin / Finance / Ops | All sales, assign sales persons, voids, commissions, attendance, POS |
| MQ Owner | Everything, including the owner dashboard and settings |
| MQ Production Monitor | Read-only production board (TV display) |

**Per-person access rights:** an admin can add or remove any of these abilities for one person.

- portal login, clock in outside the shop, clock in outside the set hours;
- sales, all sales, record payments, assign sales, void documents;
- creatives, production, QC, view board, deliver;
- POS, POS discount, POS void;
- owner dashboard, finance, settings and staff, website reviews.

Each department has an access level dropdown plus fine-tune checkboxes ("Custom"), with a **Reset to position defaults** button.

**Accounts:**
- Staff can request an account, and an admin approves or denies it. Admins can also create accounts directly.
- Accounts can be deactivated or deleted. Past records stay, marked "(deleted)".
- An admin can sign out one person or all staff.

## 4. Functional modules (staff portal)

### 4.1 Sales and Invoice Manager

- **Documents:** quotation → billing statement → invoice, with conversion between them, voiding, emailing to the customer, printing and document terms.
- **Payments:**
  - Payments can be partial; the default downpayment is 60%.
  - Recording a payment on a quotation or billing statement creates the invoice and the job order automatically.
- **Customers list:** totals (invoiced, paid, balance) and the customer's full history, with quick **+ Quotation** / **+ Invoice** buttons.
- **Customer uploads inbox** and **Online orders** queue (verify or reject payment, resend email).
- **Visibility:**
  - Sales staff see only their own documents.
  - Creatives and Production never see prices.

### 4.2 Job orders and workflow

- **Stages:** Sales → Creatives → Production → QC → Ready → Delivered.
- **Gates at Sales:** payment, complete specs and a sales person.
- **Job order details:** RUSH flag, notes, files per job, stage history and send-back reasons.
- **Production Monitor board** for a shop TV.
- **Commissions:**
  - Earned on delivery; the rate is set per brand, with a default.
  - Finance marks commissions as paid.

### 4.3 POS (EasyPrint walk-in)

- POS products and prices are set per brand, with discounts (by permission) and voids (by permission).
- Receipts are printed on 58/80 mm thermal printers, and the cash drawer kicks via the printer.
- There is a daily summary per branch.
- **Offline:**
  - Sales are saved on the device with device receipt numbers (`M1-K3F9-000123`) and sync when back online.
  - Invoice Manager documents also work offline and print "PENDING SYNC".

### 4.4 Time clock and attendance

- Logging in and clocking in are separate. Staff can clock in, take breaks and clock out.
- Open shifts close automatically after 16 hours (configurable).
- **Shop-only clock-in:**
  - A device counts as at the shop on a registered shop Wi-Fi (public IP) or within a GPS radius of a shop.
  - Any shop counts, and the shift records which one.
- **Optional clock-in hours window.**
- Both restrictions have per-person exceptions.
- **Reports:** attendance with worked hours (CSV), department reports, and a login log that includes blocked attempts.

### 4.5 Owner dashboard

- Unified sales for all brands, then per brand, then per branch, then individual receipts and invoices.
- Department reports.

### 4.6 Notifications

- An in-portal bell with an unread count (also shown in the tab title).
- Per-person settings; pop-ups and a sound per device.
- Polls every 45 s.
- Read notifications are removed after 30 days, all of them after 90.

### 4.7 Settings (portal → Admin → Settings)

- Company and documents, time clock, shops and branches (Wi-Fi/GPS), brands and commission.
- POS products and receipt footer, staff accounts.
- Online orders and the order price list, customer uploads (types and size), Turnstile.

## 5. Website features (customer-facing)

| Shortcode / link | What it does |
|---|---|
| `[mq_upload]`, `#mq-upload`, class `mq-upload-open` | Upload box. When online ordering is on, it opens the order steps in a pop-up and carries the files over. When ordering is off, it is the simple upload form (files, name, mobile, email, brand/branch, instructions) and returns a reference number. Options: `text`, `title`, `branch`, `box="no"`; `?branch=M1` on the page link works too. |
| `[mq_order]`, `[mq_order_button]`, `/order/`, `/order/track/` | Online ordering pop-up or page. |
| `[mq_quote]`, `#mq-quote`, class `mq-quote-open` | Get Quotation pop-up. Options: `button`, `brand`. |
| `[mq_prices]` | Price list from POS products marked "On the website". Options: `brand`, `category`, `button`, `note`. |
| `[mq_reviews]` | Review carousel: 3/2/1 cards per view, Google rating, Leave a Review. Options: `limit`, `source`, `button`, `summary`. |
| `[mq_featured_review]` | One review, rotated weekly from a lineup. Options: `label`, `link`, `link_text`. |
| `[mq_staff_login]` | Small "Staff Login" link. |

### 5.1 Online ordering (optional: Admin → Settings → Online orders)

1. The customer uploads files; PDF pages are counted automatically.
2. They pick paper, print mode, copies, binding and supplies.
3. They choose pick-up, campus delivery or Maxim/Lalamove.
4. They pay by GCash or UnionBank, giving the reference number and an optional screenshot.

The price is always recalculated on the server. Each order creates an unpaid invoice and a job order. Staff verify the payment in **Online orders**, and the customer gets email updates and a tracking page. Files from abandoned orders are deleted after one day.

> **Client direction (pending confirmation):** the website should only *receive files*, recording branch and timestamp. To do that, untick **Accept online orders**. The upload form then reverts to the simple form, and `/order/` shows "closed".

### 5.2 Get Quotation

- **Instant quotation:** fixed-price items produce one right away (on screen and by email).
- **Custom items:**
  - The request is marked "Needs pricing" with a 24-hour promise.
  - If it is still unpriced after 20 hours, the Owner and admins get a reminder.
- **Accept online:** the customer accepts the quotation from its link, which creates an invoice and job order. The link then shows the order's progress steps.

### 5.3 Reviews

- Website reviews wait for approval; manual entries and Google Places reviews are also supported (fetched daily).
- Only 4–5 stars can be shown; lower ratings are kept private as feedback.
- Pictures are resized to 240 px.
- **Featured review:** changes every Monday and clears the LiteSpeed cache when it changes.

### 5.4 Privacy (RA 10173, Data Privacy Act)

- Every customer form has a required consent box that links to the Privacy Policy (`/privacy-policy/`).
- **Remove personal data** (Owner and admins, or WordPress Tools → Erase Personal Data):
  - Contact details, uploaded files, payment screenshots and reviews are erased.
  - Invoices keep the name, number and amounts for the BIR.

## 6. wp-admin pages (MQ Portal menu)

| Page | For | Contents |
|---|---|---|
| Dashboard | Developer / system admin | What's new (searchable changelog), update history, health, background jobs, problems, activity, data counts, environment report, quick actions |
| Website | Owner / admin | What is waiting, this week, latest requests and uploads, storage |
| Reviews | Reviews managers | Approve/hide/edit/delete, add review, Google settings, featured lineup |
| Shortcodes | Anyone editing the site | Every shortcode and link with options and Copy buttons |
| System | WordPress administrators only | Overview health checks, Problems, Activity log (CSV), Security (locks, spam limits), Uploads & files, Tools (setup export/import, tables, sessions, sign out), Settings (portal address, maintenance mode, email alerts, login security, logs, cron steps, delete-data-on-uninstall) |

- The menu shows one combined count bubble.
- There is an "MQ Portal status" Dashboard widget.

## 7. Security and protection

- **Sessions:**
  - The portal has its own session with an `X-MQP-Token` CSRF header.
  - A session lasts until the browser closes or for 2 days.
  - Sessions end on a password change or deactivation.
- **Login lockout:** 3/5/10 wrong passwords lock the username plus network for 5–60 minutes.
- **Spam limits:**
  - Per-IP limits for uploads, quotes (5/h, 15/day), reviews, orders and signups.
  - Honeypot field, optional Turnstile.
- **Files:**
  - Stored in the protected `wp-content/uploads/mq-portal/` folder and served only through the portal.
  - Checked by extension and file content (magic bytes).
- **Storage protection:**
  - Per-connection and all-customer daily MB caps.
  - A total folder cap (default 20 GB) and minimum free disk space (default 2 GB).
  - Dismissed uploads are deleted after 30 days.
- **Prices** are always computed on the server.
- **Audit log** of all sensitive actions, kept for a configurable period (default 1 year).
- **Maintenance mode:** staff are blocked, admins keep working, customer forms keep working.

## 8. Technical architecture

- **Codebase:** about 80 files and about 18,500 lines of code. PHP classes are in `includes/`; the REST API is in `includes/rest/`.
- **Front end:** the portal is a single-page app in vanilla JS (`assets/js/views/*`) with a service worker (`sw.js`) for offline use and PWA install.
- **REST namespace:** `/wp-json/mq/v1/`. Route groups:
  - `auth`, `me` (clock, profile, notifications, preferences);
  - `documents`, `jobs`, `orders`, `uploads`, `files`, `customers`, `pos`, `finance`, `dashboard`, `board`;
  - `admin` (settings, users, caps, signups, branches, order config), `sync`;
  - `public/*` (orders, order files, payment, track, quote, quote accept, signup);
  - `health`.
- **Database:** 23 custom tables, `wp_mq_*`:
  - brands, branches, customers, documents, document_items, payments;
  - jobs, job_events, uploads, files, orders, commissions;
  - pos_products, pos_sales, pos_sale_items;
  - attendance, attendance_breaks, audit_log, signups, errors, reviews, notifications, counters.
- **Background jobs:** `mqp_daily` (cleanup, Google reviews, featured rotation) and `mqp_hourly` (quotation reminders), run by WP-Cron and triggered by the Hostinger server cron.
- **Integrations:** WP Mail SMTP, LiteSpeed Cache purge, Cloudflare Turnstile, Google Places API, GCash/UnionBank (manual verification).
- **Updates:**
  - Upload a newer zip and the data stays.
  - Every release bumps the version and the changelog.
  - Deactivating or deleting the plugin keeps the data unless the delete option is turned on.

## 9. Out of scope (this version)

- Website copy and photos (done in Elementor).
- Customer accounts, customer messaging, and low-stock / inventory tickets (from the old app; not requested).
- Automatic SMS to customers.
- Google Drive storage.
- Payment gateway auto-confirmation (payments are verified manually).
- Restricting staff *login* to the shop: only *clock-in* is restricted to the shop.

## 10. Open items

1. Confirm with the client: website upload only (ordering off), or online ordering with payment.
2. Clock-in hours: the client wrote "7pm–6pm". Confirm whether that means 7:00 AM–6:00 PM.
3. Hostinger: add the cron job; make `/privacy-policy/` reachable (the site is in maintenance mode); pay hosting.
4. If ordering stays on: align the Rush ID packages and add lamination to the order price list.
