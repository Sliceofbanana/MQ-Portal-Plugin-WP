# MQ Portal (WordPress plugin)

Staff portal for MQ Printing Services. It runs inside the existing WordPress site on Hostinger and needs no other server.

- **Online ordering (`/order/`):** customers upload files, pick options, see the price and pay by GCash/UnionBank. No account: they get a reference number by email and can track the order. Orders land in Sales → Online orders.
- **Customer file upload:** no customer account needed. Each upload records the brand, branch and time.
- **Invoice Manager:** quotations/estimates, billing statements and invoices.
- **Job orders:** each order moves Sales → Creatives → Production → QC → Ready → Delivered.
- **Commissions:** earned when the job order is delivered and fully paid (only when the brand or default rate is above 0%).
- **POS:** for walk-in sales at EasyPrint branches. Works offline.
- **Time clock:** logging in and clocking in are separate. Staff clock in/out and take breaks on the Time Clock page. Staff must be at a shop (shop Wi-Fi or GPS) to clock in; optional clock-in hours.
- **Owner dashboard:** total sales for MQ Prints, then per brand, then per EasyPrint branch, then individual receipts.
- **Admin settings:** brands, branches, POS prices, staff accounts and per-person permissions.

---

## 1. Install

1. **Back up the site first:** hPanel → WordPress → Backups.
2. **Upload the plugin:** in wp-admin, go to Plugins → Add New → Upload Plugin and choose `mq-portal.zip`.
3. **Activate it.** Activation creates the database tables and roles. It also adds the 4 brands, one EasyPrint branch (code `M1`) and sample POS prices.
4. **Set the timezone:** Settings → General → Timezone must be **Manila**. Attendance and daily sales depend on it.
5. **Check the permalink setting:** Settings → Permalinks must **not** be "Plain". "Post name" is fine.
6. **Open the portal:** go to `https://mqprintsph.com/mq-portal/`, or click **MQ Portal** in the wp-admin menu.

WordPress administrators can always log in, at any time and from anywhere.

## 2. First-time setup (Admin, Finance & Ops → Settings)

| What | Where |
|---|---|
| Company details, document terms, 60% downpayment, what must be paid before Creatives, default commission, when quotation links expire | Settings → Company & documents |
| Clock in at the shop only, clock-in hours | Settings → Time clock |
| Shops: POS branch, order pick-up, and the Wi-Fi / GPS used for clock-in | Settings → Shops & branches |
| Commission per brand | Settings → Brands → click a brand |
| Receipt footer, POS products and prices (the seeded prices are samples) | Settings → POS |
| Staff accounts and access rights | Admin → Staff → New |

Settings works like Odoo: pick a section on the left (or search at the top), change what you need, then click **Save** at the top. **Discard** undoes unsaved changes. Shops, brands and products save on their own when you edit one.

### Roles

| Role | Default access |
|---|---|
| MQ Sales | Sales tab (own documents and job orders), record payments, mark delivered |
| MQ Creatives | Creatives tab: approve files, attach edited designs |
| MQ Production | Production queue: mark as printed |
| MQ QC Officer | Production and QC: approve as READY FOR DELIVERY or reject |
| MQ Cashier (POS) | POS only |
| MQ Admin / Finance / Ops | All sales, assign sales persons, void documents and receipts, commissions, attendance |
| MQ Owner | Everything, including the dashboard and settings |
| MQ Production Monitor | Read-only production board, for the TV in the production area |

Open a staff member to see their record. Their **Position** (the role above) sets the default access. Under **Access rights**, each department has:

- **An access level dropdown**, e.g. Sales: No access / Sales staff — own customers / Sales manager — all sales. Picking a level ticks the matching boxes.
- **Checkboxes** for each thing in that department, to fine-tune one person. A department that doesn't match a level shows **Custom**.

Changes apply to that person only (marked "added" or "removed"). **Reset to position defaults** undoes them. Changing someone's position starts them from the new position's defaults.

**wp-admin is for WordPress administrators only.** Every portal account, including the Owner, signs in at the portal; wp-login.php refuses them. Website reviews are managed in the portal (Admin → Reviews, with the "Website reviews" permission); wp-admin → MQ Portal → Reviews has the same list plus the Google and website settings.

**Custom prices, discounts and changing invoices after a payment** (Admin, Finance & Ops) is for admins: it is on for MQ Admin / Finance / Ops and the Owner. Without it, staff can't give discounts in the Invoice Manager, change an invoice after a payment, record a payment dated more than a week back, or add a "+ Custom item" in the POS. A POS sale with a custom or changed price, an old date or another shop is kept (the receipt was already printed) and the admins are notified.

**Deleting a staff member:** open their record and click **Delete** (top right). The account is removed for good and they are signed out everywhere. Their past work (job orders, sales, attendance and the activity log) stays and shows their name with "(deleted)". If they might come back, switch off **Active** instead. You can't delete your own account or a WordPress administrator, and only a WordPress administrator can delete an Owner.

### Brands, branches and numbering

MQ Printing Services → brands (EasyPrint, Gifts n Crafts, ID Express Cebu, MQ Signature) → each brand's branches. Switch on **Has branches** for a brand and add its branches in Admin → Settings (each with its own code, e.g. M1, M2), and give staff their **Home shop**.

- **Quotations, billing statements and invoices** count per branch: `EP-M1-INV-2026-00001`, `EP-M2-INV-2026-00001`. Brands without branches (and documents without a branch) use the brand series: `GC-INV-2026-00001`. Every series starts again each year.
- **POS sales** get an official number from the server, per branch: `EP-M1-POS-2026-000001`. The device number (e.g. `M1-A1B2C3-000123`) stays on the receipt as a reference; offline sales get their official number when they sync, so the official order is the order sales reach the server (the sale time is always kept).
- **Many devices at once:** numbers are handed out one at a time by the database: never the same number twice, never a skipped number. If two people save the same document at once, the second is told who changed it and asked to reload, so nobody's change is overwritten.
- Not BIR-accredited: if receipts or invoices must be official BIR documents, check with the accountant first.

### Deleting staff (safe delete)

Admin → Staff: **Delete** on a row, or **Remove account** at the bottom of a staff page. You see what they still have open (and can hand their open job orders and quotations to another sales person), then type their **username** and **your own password**. The account goes to **Staff → Deleted**: signed out and blocked at once, **restorable for 30 days**, then removed for good automatically (WordPress administrators can **Delete now**). You cannot delete yourself, a WordPress administrator or the last active Owner; only a WordPress administrator can delete an Owner. The admins are emailed about every delete and restore. To remove test accounts in one go, use wp-admin → System → Tools → Start fresh → Test staff accounts.

### New staff accounts (admin approval)

Only an admin can let someone into the portal. There are two ways:

1. **Staff ask, an admin approves:**
   1. On the portal login screen, new staff click **Request an account**. They enter their name, username, email, mobile, position, shop and the password they want.
   2. This does **not** create an account. The request waits, and the site admin email is told about it.
   3. Under Admin → **Account requests**, open the request. You can change the position, home shop and brand.
      - **Approve & create account** creates the account with the password they chose. They're emailed that they can log in, and you're taken to their record to check their access rights.
      - **Deny** (with an optional reason) creates nothing. They're emailed that the request wasn't approved.
   4. A red count on the Admin menu shows how many requests are waiting.
   5. Until a request is approved, logging in says "Your account request is still waiting for approval".
2. **An admin creates the account directly:** Admin → Staff → **New**.

To stop requests, untick Settings → Staff accounts → **Let new staff request an account**. Only admins can create accounts then.

### Profile pictures

- Staff add or change their photo on **My profile**: click the picture, or **Change photo**. Admins can do it from a staff member's record.
- JPG, PNG or WebP photos up to 8 MB are accepted. Each photo is cropped to a square and resized.
- Photos are stored in the portal's protected folder on Hostinger, so only logged-in staff can see them.
- People without a photo show their initials.

## 3. Put the upload form and staff login on the website

### Upload documents = online order

Since 1.17.0 the upload box (`[mq_upload]`) and every "Upload Document" button (`#mq-upload`) open the **order steps** in a pop-up: upload, options and the price right away, pick-up or delivery, contact details, payment. Files chosen in the box are carried into the order. Orders land in Sales → Online orders. When online ordering is switched off (portal → Admin → Settings → Online orders), the older form described below comes back.

- **Upload form:** add `[mq_upload]` with a **Shortcode** block (or Elementor's Shortcode widget) where the upload box should be. It is the whole form, so don't put it inside another form plugin.
  - **How it works:**
    1. Customers see a big orange box. They click it or drag files onto it.
    2. A pop-up opens with the chosen files (remove one, or add more) and the details form: name, mobile, email, brand and shop, and instructions.
    3. After **Upload**, the same pop-up shows the reference number, e.g. `U-00013`.
    - On phones the pop-up slides up from the bottom.
  - **Change the texts:** `[mq_upload text="Start Uploading PDF Files" title="Almost done! Tell us about your order"]`.
    - If only PDF is allowed (Settings → Customer uploads → types), the box already says "Start Uploading PDF Files".
  - **Header or menu button ("Upload Documents"):** set the button's link to `#mq-upload`. Clicking it opens the pop-up with the upload box and the details form together, on any page of the website. In Elementor you can instead put `mq-upload-open` in the button's **Advanced → CSS Classes**. A link from elsewhere, like `https://mqprintsph.com/#mq-upload`, opens the pop-up as the page loads.
    - On a page that has `[mq_upload]`, the button opens that form's pop-up. Other pages get a hidden copy of the pop-up automatically, so no shortcode is needed there.
    - `[mq_upload box="no"]` adds only the pop-up, with no orange box on the page.
  - **Pre-select a branch:** use `[mq_upload branch="M1"]`, or add `?branch=M1` to the page link. This is useful for a QR code at each shop.
  - **Design:** the form brings its own design and is transparent around the orange box, so it sits on your page's own panel. It works with any theme, page builder, pop-up builder and LiteSpeed Cache.
- **Staff sign-in page:** shows a photo of your shop beside the form (on phones, as a banner on top), with a **Back to website** button. Pick your own photo from the Media Library in **wp-admin → MQ Portal → System → Settings → Staff sign-in page**. A wide photo of at least 1200 px works best.
- **Staff login link:** add `[mq_staff_login]` near the bottom of the homepage. It shows a small "Staff Login" link to the portal.
- **Bigger files:** customer uploads are limited to 25 MB each by default. Change this in Settings → Customer uploads, and raise the server limit in **hPanel → Advanced → PHP Configuration** (`upload_max_filesize` and `post_max_size`).
- **Spam protection (optional):** Cloudflare Turnstile is free. Add its keys in Settings. It protects both the upload form and the order page.

### Get Quotation (website)

Customers ask for a quotation from the website, and it becomes a real quotation in the portal with their contact details.

- **Put it on the website:** set your homepage "Get Quotation" button's link to `#mq-quote`, or give it the CSS class `mq-quote-open` in Elementor (Advanced → CSS Classes). Every page has the pop-up ready. You can also add `[mq_quote]` for its own orange button. Options: `[mq_quote button="Request a quote" brand="EP"]`. With `brand` the shop is fixed; otherwise customers choose it.
- **Fixed prices:** in the portal, go to Admin → Settings → POS products, open a product and tick **Fixed price on the website "Get Quotation"**, and choose its **Brand**. These items show in the pop-up with their price.
- **How it works for the customer:**
  1. They pick fixed-price items (with quantity) and/or **describe custom items** (what, quantity, details), and can attach up to 5 files.
  2. They enter **name, email and mobile number** (required), plus company, needed-by date and notes (optional).
  3. **Only fixed-price items:** the quotation shows on the screen right away and is emailed to them, with a link to view and print it.
  4. **Any custom item:** they see "Thank you! We'll get back to you within 24 hours", and get an email saying so.
- **In the portal:** every request is a quotation under Sales → Invoice Manager, with the customer saved (name, email, phone, company) and any files attached. Requests with custom items show **Needs pricing**. Sales get a notification (bell) and an email.
  1. Open it and click **Edit**.
  2. Add the prices to the custom items, which are marked "estimate".
  3. Save, then click **Email to customer**.
  The customer gets the final quotation by email. Their link now shows the prices. From there it converts to a billing statement or invoice as usual.
- **Protection:** prices always come from the price list on the server, never from the customer's browser. There are at most 5 requests per hour and 15 per day from one connection, a spam trap, and the Turnstile check when it is set up. Attached files count toward the storage protection limits.

### Customer reviews

Add `[mq_reviews]` with a **Shortcode** widget where the reviews should go, and remove the old testimonial carousel. It shows:

- **Review cards** with the quote mark, the review, a profile picture (or the initials), the name, stars and "… ago". Long reviews get **Read more**.
- **Arrows and dots**. On a computer it shows 3 cards, on a tablet 2, and on a phone 1 (swipe, with the next card peeking).
- **Your Google rating** above the cards ("4.9 ★★★★★ 23 reviews on Google"), once Google is connected.
- **Leave a Review:** a pop-up where customers pick the stars and enter their name, the review, and optionally their email (never shown) and a profile picture.

Manage reviews in the **portal → Admin → Reviews** (Owner, or staff with the "Website reviews" permission): approve, deny (hide), add, edit, feature or delete, one at a time or several at once. wp-admin → MQ Portal → Reviews has the same list plus the Google connection and the website settings:

- **Reviews:** new reviews from the website wait under **Waiting for approval**. A count on the menu shows how many. **Approve and show**, **Hide**, **Edit** or **Delete** each one.
- **Add a review:** type in a review yourself, e.g. from Facebook or a message, with stars, date and an optional picture.
- **Google reviews:** add a Google API key and your Place ID (the page has the steps), then click **Save and fetch reviews**. They're fetched again every day. Google gives its 5 most relevant reviews at a time, and new ones are kept, so the list grows. New Google reviews with at least 4 stars show right away (you can change this). Google reviews can only be shown or hidden here, not edited.
- **Settings & emails:** show website reviews right away instead of waiting for approval; email the admins about new reviews (a designed email with the stars, the review and a button), and send the customer a thank-you email when they gave their email.
- **The list:** at a glance (waiting, on the website, average rating, Google, private feedback), Waiting / On the website / Hidden / All, search and filters (source, stars), and tick several reviews to approve, hide or delete at once.
- **Options:** `[mq_reviews limit="12" source="google" button="no" summary="no"]`.
- **Only 4 and 5 stars are shown.** Reviews below 4 stars, from the website or Google, are kept private under **Hidden** so you can read the feedback. They can't be shown on the website. The customer just sees "Thank you for your feedback!".
- **Spam limits:** a few reviews per hour from one connection (System → Settings), twice that per day, 50 a day from everyone, 300 waiting at most, the same text only once, and pictures at most 3 MB (saved as a small 240 px picture).

### Online ordering

The order page is built into the plugin at `https://mqprintsph.com/order/` (tracking: `/order/track/`). It replaces the old Next.js EasyPrint order site.

- **Put it on the website as a popup:** add the shortcode `[mq_order]` to any page, e.g. the homepage hero or the EasyPrint page. It shows an **Order online** button. Clicking it opens the order steps in a popup over the page, so it takes no space on the website.
  - The popup looks the same as the staff portal, whatever the WordPress theme.
  - Closing the popup halfway asks first. Reopening it continues the same order.
  - On phones the popup fills the screen.
  - Options: `[mq_order text="Order now"]` changes the label. `[mq_order track="yes"]` makes a "Track my order" button. `[mq_order popup="no"]` links to the full `/order/` page instead of a popup.
  - `[mq_order_button]` from version 1.2 still works and does the same.
- **How an order flows:**
  1. The customer uploads files (PDF page counts are read automatically), chooses paper, print mode, copies, binding and supplies, then pick-up, campus delivery or Maxim/Lalamove.
  2. They pay by GCash or UnionBank, enter the reference number and optionally attach a screenshot. They receive the reference number by email.
  3. The order creates an **invoice** (unpaid) and a **job order** with the customer's files and complete specs. Staff get an email.
  4. Sales opens **Sales → Online orders**, checks the payment in the GCash/UnionBank app, then clicks **Payment received ✓**. The invoice is marked paid and the customer is emailed.
     - If the payment can't be found, **Reject payment** with a reason. The customer is emailed and can send the correct reference from the tracking page.
  5. Assign the sales person (or set one in Settings so it happens automatically), then the job order moves through Creatives → Production → QC as usual. The customer is emailed when it is ready and when it is completed.
- **Prices are always recalculated on the server** from the price list. A customer can't change the total from their browser.
- **Price list, campuses and payment accounts:** Settings → **Online order price list**. For your own QR codes, upload the images in wp-admin → Media and paste their links.
- **Settings → Online orders:** turn ordering on or off, set the staff notification email, the sales person who gets online orders, and the file size limit.
- **Files are stored on Hostinger** in the protected `wp-content/uploads/mq-portal/` folder, the same as other customer uploads. Uploads that were never part of a placed order are deleted after a day.
- **Email:** WordPress mail from Hostinger often lands in spam. Install **WP Mail SMTP** and connect it to a Hostinger mailbox (e.g. `orders@mqprintsph.com`), then send a test email to a Gmail address.

### Accept online and order tracking

On their quotation link (in the email), customers see an **Accept quotation** button when the quotation is priced and still valid.
- Accepting turns it into an **invoice and a job order** in the Sales stage, as if you had clicked Convert → Invoice. Sales get a notification (bell) and an email; the customer gets a confirmation.
- Links stop working 90 days (Settings → Company & documents → "Quotation links expire") after the order is completed or cancelled, or the quotation is no longer valid.
- The same link then shows **how far the order is**: Order placed, Payment received, Printing, Quality check, Ready for pick-up / delivery, Completed. The customer is emailed when it is ready and when it is completed.
- **24-hour promise:** requests still "Needs pricing" after 20 hours remind the Owner and admins (bell and email), once per request.

### Price list on the website

`[mq_prices]` shows the POS products marked **On the website** (Admin → Settings → POS products), grouped by category, with a "Get a quotation" button. Changing a price in the portal changes it on the website.
- Options: `brand="EP"`, `category="Printing, Binding"`, `button="no"` (or other text), `note="no"`.
- "On the website" is on for every product; untick it to leave a product out of the price list and the instant prices.

### Customers (Sales → Customers)

Everyone you made a quotation or invoice for, and the customers who sent a quotation request, uploaded files or ordered online. Click a customer for their totals (invoiced, paid, balance) and their quotations, invoices, job orders, online orders and uploads, with **+ Quotation** / **+ Invoice** buttons filled in with their details. Sales staff who only see their own documents only see those here too.

### Privacy (Data Privacy Act, RA 10173)

- Every form that collects personal data (upload, Get Quotation, online order, Leave a Review) has a required "I agree… Privacy Policy" box. The link goes to the page chosen in **WordPress → Settings → Privacy**, or else to your published `/privacy-policy/` page.
- **Remove personal data:** Sales → Customers → open the customer → **Remove personal data** (Owner and admins). Their mobile, email and address are removed from every document, their uploaded files, payment screenshots and reviews are deleted, and they leave the Customers list. Invoices keep the name, number and amounts for the BIR. WordPress's **Tools → Erase Personal Data** (by email) does the same.

### Background jobs (Hostinger cron)

Quotation reminders (hourly), the daily cleanup and the weekly featured review only run when someone opens an uncached page unless a server cron job triggers them. System → Settings shows the command and the steps for **Hostinger → Advanced → Cron Jobs** (every 15 minutes). System → Overview → "Background jobs" turns green once it runs.

### Archives (free up space)

**wp-admin → MQ Portal → System → Uploads & files → Archives.** Files of job orders delivered or cancelled at least 14 days ago go into a zip (one folder per job order, plus `files-in-this-archive.csv`).
1. A new archive is made **every week**, and right away when the portal's files reach 80% of "Portal files at most". You get an email. **Make an archive now** does it by hand.
2. **Download** it and keep it on a PC (and a second copy, e.g. a USB drive).
3. Click **I saved it — free up the space**: the files and the zip leave the hosting. Nothing is deleted before the download.
- Job orders keep the file names, marked "Archived"; opening one says which archive it is in.
- Settings (System → Settings): on/off, after how many days, the largest zip, and when "nearly full" is.
- **Google Drive** (System → Settings → Google Drive): each new archive is uploaded automatically, Google's copy is checked (size and MD5), then the space is freed (you can turn that off). Set-up, once:
  1. In [Google Cloud Console](https://console.cloud.google.com/), with the Google account that should keep the archives, create a project and enable the **Google Drive API**.
  2. OAuth consent screen: External, add yourself as a test user, then **Publish app** (otherwise Google asks you to connect again every 7 days).
  3. Credentials → OAuth client ID → Web application, with the redirect URI shown on the settings card.
  4. Paste the Client ID and secret, Save, then **Connect Google Drive**.
  The portal can only see the files it uploads, not the rest of the Drive.

### Dashboard (wp-admin, for the developer / system admin)

**MQ Portal → Dashboard** is the main page (it includes what used to be System → Overview and the Website page):
- **Waiting now:** health, new problems, quotations to price, new uploads, reviews to approve and **POS sales to check** (custom or changed price, old date or another shop by staff without that permission; click **Checked** after looking at it).
- **Health:** every check with its fix, and **Check again**.
- **This week on the website**, the latest quotation requests and uploads.
- **Storage & backups:** storage use, archives to download, Google Drive, and **Last copy off the site** (a reminder after 30 days).
- **Right now:** job orders in progress, orders to verify, who is clocked in, account requests, background jobs and server cron.
- Also: what's new (the whole changelog, searchable, with "new since you last looked"), every version installed on this site and when, health and new problems at a glance, background jobs (last/next run, server cron), recent problems and activity, data counts, an **environment report** to copy into a support message (versions, plugins, server, proxy/CDN headers, key settings; no secrets), and quick actions: clear page cache, run the hourly/daily job, clear spam limits, run setup again.

### Shortcodes page (wp-admin)

**MQ Portal → Shortcodes** lists every shortcode and button link (`#mq-quote`, `#mq-upload`, the order and portal links): what each does, where to use it, its options, and a Copy button.

The **MQ Portal** menu shows one count of everything waiting (including POS sales to check) plus new problems.

### Sign out one person

- Portal: Admin → Staff → open the person → **Sign out** (shown when they are signed in).
- wp-admin: System → **Security & sessions** lists who is signed in, with a Sign out button for each, and **Sign out all staff now**.

### Featured review (one per week)

`[mq_featured_review]` shows a single review card: quote mark, text, picture (or initials, the same as the other review cards), name, stars and a line under the name. It's made for the review column of the "Have questions? Contact Us Before You Print" section. Put a Shortcode widget in that column, in place of the old card.

- **Changes every Monday by itself.** Without a lineup, all 5-star reviews shown on the website take turns. Click **Add to featured lineup** on reviews (Reviews → Shown) to choose which ones take turns. **Feature this week** puts a review up right away.
- **Line under the name** (e.g. "2026 BAR passer"): set it with **Edit** on the review. Without one, the card shows "Google review" or "… ago".
- Options: `label="Review of the week"` adds a small label; `link="/about-us/" link_text="See all reviews"` adds a link below.
- When the review changes, the LiteSpeed page cache is cleared, so visitors see the new one.

### Our print collection (photo carousel)

`[mq_print_collection]` is only the photo carousel of the "Our Print Collection" section: keep the section's heading, text and **Upload Document** button in Elementor and replace the old gallery widget with a Shortcode widget. Arrows, swipe on phones, click to see a photo large, optional sliding by itself. Manage it in **wp-admin → MQ Portal → Print collection** (WordPress administrators only):

- **Drop photos** on the upload box (many at once, straight from the phone; they upload one by one with a progress bar) or **Copy from the Media Library** (for the photos of the old gallery).
- **Gallery settings:** photos side by side (2–6 on computers), photo shape, slide by itself, how many photos.
- Each photo is kept as two small WebP files (about 700px for the carousel and 1600px for the large view, about 150–250 KB together). The original isn't kept and WordPress doesn't make its usual extra sizes, so a phone photo takes about 30–50 times less space than in the Media Library. The page shows how much storage the collection uses.
- Drag to reorder (saved by itself). Point at a photo for **See it large**, **Show/Hide** and **Delete**; tick several to do it at once; type a description under a photo (shown when enlarged, and read out to blind visitors).
- After switching the page to `[mq_print_collection]`, delete the old gallery's originals in **Media → Library** to free their space (they are not deleted automatically, in case another page uses them).

### Our previous orders (Facebook posts)

`[mq_previous_orders]` is a whole section: a heading, a short text, cards with the picture and caption of Facebook posts featuring your orders ("Read more", "View on Facebook"), and a **See more on our Facebook page** button. Manage it in **wp-admin → MQ Portal → Previous orders** (WordPress administrators only; not in the portal):

- Three tabs: **Posts** (drag to sort, tick several to show, hide or remove, **Edit** each one), **+ Add posts** and **Settings**.
- Paste post links in any form: one per line, with spaces or commas, or a whole message with links in it (Share → Copy link or the address bar both work; duplicates are skipped). The post must be public. The picture and the start of the caption are read from Facebook, and a copy of the picture is kept on your site (Facebook's picture links expire).
- Facebook only gives the start of a caption: paste the full caption in the post's Caption box to show all of it.
- If Facebook won't show a post to the website (it sometimes refuses web hosts), the post is still kept, switched off and marked **Needs a picture**: open **Edit** and add the picture (save it from Facebook) and the caption, or use **Add one by hand**. The message says what Facebook answered.
- Settings: heading, the text under it, how many posts, the Facebook page for the button (found by itself), the button text, and the look: our own cards (fast, no Facebook tracking) or Facebook's own post cards (full caption and likes, slower).

### Notifications (the bell)

The bell at the top of the portal shows a red count of unread notifications, which also shows in the browser tab, e.g. "(3) Home · MQ Portal". Click it to see the latest ones. Clicking one opens the job order, online order, upload list or account request it is about. **Mark all as read** clears the count.

| Who | Gets notified when |
|---|---|
| Creatives | a job order is sent to Creatives |
| Production | files are approved (ready to print), or QC sends a job back (with the reason) |
| QC | a job order is printed |
| Delivery (mq_deliver) | a job order passes QC |
| The job's sales person | it is assigned to them, Creatives sends it back or marks "needs editing", QC sends it back, it is ready for delivery, someone adds a note, it is marked RUSH |
| Sales | a customer uploads files, an online order or a new payment comes in (staff tied to one brand only get their brand) |
| WordPress administrators | a customer sends a website review |
| Admins (Settings access) | someone requests an account |
| Admins (custom prices permission) | a POS sale has a custom or changed price, an old date or another shop |
| WordPress administrators | a new problem appears in wp-admin → System |

- Nobody is notified about their own actions.
- Each person chooses in **My settings → Notifications**. Admins and the Owner start with only online orders, reviews, account requests, system problems and their own job orders, so they aren't flooded with every job step. They can switch on more.
- **On this device** (same page): **Pop-up notifications** show new ones on the screen while the portal is open in another tab or in the background, and **Play a sound** gives a short chime, which is handy at the shop counter. These are saved per computer or phone.
- The portal checks for new notifications every 45 seconds while it's open. Read notifications are removed after 30 days, all after 90.

## 4. How the workflow runs

```
Customer upload ─┐
Sales staff ─────┼─► Quotation ─► Billing statement ─► (payment) ─► Invoice = sale ─► JOB ORDER
                 │                                                                     │
                 │   SALES  → needs: payment ✓  specs complete ✓  sales person ✓        │
                 │   CREATIVES → approve files / attach edited design                  │
                 │   PRODUCTION → printed                                              │
                 │   QC → APPROVED / READY FOR DELIVERY (or back to production)        │
                 │   READY → customer notified → DELIVERED → commission earned         │
Walk-in (EasyPrint shop) ─► POS receipt
```

- **Recording a payment on a quotation or billing statement creates the invoice and the job order automatically.**
- **Sales staff only see their own documents and job orders.** Admin/Finance/Ops and the Owner see all of them.
- **Creatives and Production don't see prices.**

## 5. Time clock and attendance

- **Logging in doesn't record attendance.** Staff log in at `/mq-portal/`, then use the **Time Clock** page (also reachable from the clock badge in the header):
  - **Clock in** when the shift starts.
  - **Start break** / **End break** as often as needed. Break time isn't counted as worked time.
  - **Clock out** when the shift ends. Logging out while clocked in asks whether to clock out too.
- **Forgotten clock-outs:** if someone forgets, the shift and any open break are closed automatically after 16 hours (configurable).
- **Clock in at the shop only** (Settings → Time clock, on by default): staff can only clock in at a shop. They count as at the shop when either is true:
  - **Shop Wi-Fi:** their device is on a shop's internet connection. Set it up once per shop: while on the shop Wi-Fi, go to Settings → Shops & branches and click **Register this network**. If the internet provider changes the shop's IP, register it again.
  - **GPS:** their phone is within the allowed distance of a shop. Enter the shop's latitude/longitude on the shop (right-click the shop in Google Maps to copy them). The Time Clock asks for location only when the person isn't on a shop Wi-Fi.
  - Any shop counts, so staff who move between branches can clock in at whichever shop they're in. The shift records which shop it was.
  - Until at least one shop has Wi-Fi or GPS set up, nobody is locked out, and Settings shows a warning.
- **Exceptions:** give a person **Can clock in outside the shop** under Admin → Staff → (person) → **Access rights**, e.g. for delivery or remote staff. The owner, WP administrators and the Production Monitor have it by default.
- **Optional clock-in hours** (Settings → Time clock): allow clock-in only between set hours, for example 7:00 PM–6:00 AM. **Can clock in outside the set hours** exempts a person; the owner, WP administrators and the Production Monitor have it by default.
- **Reports:** Admin → Attendance lists every shift with its breaks and worked hours (CSV export). Department reports show hours per person.
- **Login log:** logins, clock-ins and blocked clock-in attempts (with the reason) are listed under Attendance → Login log.
- **Wrong passwords:** 5 wrong passwords lock that username on that network for 15 minutes.
- **Login page:** staff can't log in through `wp-login.php`; they must use the portal.

## 6. Offline (POS and Invoice Manager)

- **Requirements:** open the portal at least once while online on each device. It works best over HTTPS (Hostinger provides SSL for free).
- **When the internet drops:**
  - POS sales are saved on the device and receipts still print. Receipt numbers are generated on the device, for example `M1-K3F9-000123`.
  - New quotations, billing statements, invoices and payments are also saved on the device. The printout says "PENDING SYNC" until the document receives its official number.
- **When the internet returns:** everything syncs automatically. The badge in the top bar shows Online / Offline / items waiting. Tap it to see the queue.
- **What needs a connection:** logging in, the dashboard, job order steps and file uploads.
- **The portal has its own sign-in.** Signing in or out of the portal does not sign you in or out of wp-admin, and the other way round. Administrators sign in to the portal with the same email and password, but separately. A portal sign-in lasts until the browser closes or for 2 days, whichever comes first. Changing a password, disabling an account, or **System → Tools → Sign out all staff now** ends that person's portal sessions on every device.
- **Don't log out with unsynced records.** You get a warning first, because those records only sync when the same person logs in again on that device.

## 7. POS hardware

- **Thermal printer (58 mm or 80 mm):** install the printer's Windows driver and set it as the default printer.
  - In Chrome, turn off headers and footers and set margins to *None* once; Chrome remembers this.
  - For one-click printing, start Chrome with `--kiosk-printing`.
- **Cash drawer:** most drawers plug into the receipt printer. Turn on "Open cash drawer" (kick drawer) in the printer driver settings, and the drawer opens every time a receipt prints.

## 7b. Protection against floods and full storage

Customers can send files in three places: the upload form, the online order page and review pictures. To stop anyone from filling up your Hostinger storage, **System → Settings → Storage protection** sets:

- **Per customer connection:** megabytes per day (default 300 MB).
- **All customers together:** megabytes per day (default 3,000 MB).
- **Portal files at most:** the portal's file folder never grows past this (default 20 GB). Check your Hostinger plan's disk space and leave room for the website and backups.
- **Keep free on the server:** files are refused when less space is free (default 2 GB).
- **Dismissed customer uploads:** their files are deleted after 30 days (0 = keep). Uploads turned into a job order keep their files.

When a limit is reached, the customer is asked to try again tomorrow or email the files. You get an alert (System → Problems, email, and the portal bell), and the activity log shows "Customer upload blocked". Staff uploads inside the portal are not limited.

## 8. System page for the site owner (wp-admin)

**wp-admin → MQ Portal → System** is for WordPress administrators only. Staff and the Owner role never see it, and it still works if the portal itself breaks.

Health checks and quick actions (Check again, Run setup again, run jobs) are on **MQ Portal → Dashboard**. The System tabs:

- **Problems:** crashes, failed requests, database errors, emails that didn't send and a late daily job. The same problem appears once, with a count. New problems show a red count on the menu.
- **Activity log:** who did what. It covers:
  - logins, time clock, account requests and accounts;
  - access rights, settings and order pricing;
  - verified or rejected payments, payments, voids and commissions;
  - customer uploads and online orders, received or turned away;
  - System actions.

  Filter by type, person, date or text, and export to CSV. Old entries are removed after the period you choose (default 1 year).
- **Security & sessions:** locked logins (with **Unlock all now**), wrong passwords in the last 24 hours, spam limits (with **Clear limits**), who is signed in (Sign out per person, **Sign out all staff now**), who can change settings and staff, and disabled accounts.
- **Files & archives:**
  - upload limits checked against what the server allows (a common Hostinger cause of failed uploads), and whether the spam check is on;
  - the latest customer uploads and the last 7 days (received, orders placed, turned away);
  - storage by file type, including profile photos;
  - **Archives** (see "Archives" above).
- **Tools:**
  - export the setup (settings, order pricing, brands, shops, POS products) to a file and import it on another site, e.g. staging → live, instead of "Publish to live";
  - database tables with row counts and sizes;
  - **Start fresh (remove test data):** after trying the system on the live site, remove test quotations, invoices, job orders, online orders, customers, uploads and files, POS sales, attendance, reviews, account requests and logs (you choose). Settings, shops, products, prices and staff stay. Type DELETE to confirm; numbering starts again at 1. Take a Hostinger backup first.
- **Settings** (technical; business settings stay in the portal):
  - **Portal address:** gives the portal its own address, e.g. `app.mqprintsph.com`, which stays in the address bar. See "Own address for the portal" below.
  - **Maintenance mode:** staff see your message and can't sign in, administrators keep working, and customer ordering keeps working. A red reminder shows in the wp-admin top bar and the portal while it's on.
  - **Emails:** **Admin emails go to** (one or more addresses: new reviews, account requests, archives, problem alerts, and orders / quotation requests unless the portal has its own address), **Sender name** (emails come from your company, never "WordPress"), problem alerts on or off, and at most one alert every 15 minutes to 1 day. There's also a test email button.
  - **Login security:** lock after 3, 5 or 10 wrong passwords, for 5 to 60 minutes.
  - **Spam limits:** orders and account requests per IP per hour.
  - **Logs:** how long to keep the activity log and read problems, and an optional log of PHP warnings.
  - **Archives** and **Google Drive** (see "Archives" above).
  - **Delete all data when the plugin is deleted:** off by default.
  - How to add the Hostinger cron job and a free UptimeRobot monitor on `/wp-json/mq/v1/health`.
- **Dashboard widget:** one status line on the wp-admin home, with a link to the MQ Portal dashboard.

### Own address for the portal (app.mqprintsph.com)

1. **Create the subdomain in Hostinger:** hPanel → Domains → Subdomains, create `app`. Turn on **custom folder** and choose the **same folder as the website** (usually `public_html`), so the subdomain runs the same WordPress. If hPanel doesn't allow that folder, ask Hostinger support to "point the app subdomain to public_html".
2. **DNS:**
   - If the domain's DNS is at Hostinger, step 1 adds the record.
   - If it's still at Vercel, add an **A record** named `app` with the Hostinger server IP. It wins over Vercel's `*` record.
3. **SSL:** in hPanel → Security → SSL, install SSL for `app.mqprintsph.com`.
4. **Turn it on:** in wp-admin → MQ Portal → System → Settings → Portal address, enter `app.mqprintsph.com` and click **Check and turn on**. The plugin first checks that the address reaches this site. If the check fails, it says what's missing and nothing changes.

Once it's on:
- **Staff use the new address:** they open `app.mqprintsph.com` and sign in once more, because each address keeps its own login. Re-add the phone home-screen icon from the new address.
- **The old link still works:** `mqprintsph.com/mq-portal/` forwards to the new address.
- **Website pages and wp-admin stay on the main site:** opened from the new address, they go back to `mqprintsph.com`. The online order page always stays on `mqprintsph.com`.

**Undo:** click **Go back to …/mq-portal/** on the same screen.

Every update raises the version number (`MQ Portal x.y.z` on the Plugins page) and adds a line to System → Tools → Version history.

## 9. Not included in this version

- **Website text changes:** done separately in the page editor.
- **Google Drive storage:** files are stored privately on the server in `wp-content/uploads/mq-portal/`, which visitors can't open directly. Staff open them through the portal.
- **Automatic SMS or email to customers:** staff notify the customer and record it with "Customer notified".

## 10. Updating and removing

- **Updating (1.27.0 and later):** wp-admin → Plugins shows **Update available** under MQ Portal when a newer release is on GitHub ([Sliceofbanana/MQ-Portal-Plugin-WP](https://github.com/Sliceofbanana/MQ-Portal-Plugin-WP/releases)). Click **Update now**, or **Enable auto-updates** once. **Check for updates** under MQ Portal asks GitHub right away (otherwise WordPress checks about twice a day). Your data stays.
  - Private repository: add `define( 'MQP_GITHUB_TOKEN', 'github_pat_…' );` to `wp-config.php` (a fine-grained token with read-only **Contents** access to this repository).
  - Older versions: upload the zip once (Plugins → Add New → Upload → Replace current with uploaded); after that, updates come from GitHub.
- **Releasing a new version (developer):**
  1. Make the changes, bump `Version:` and `MQP_VERSION` in `mq-portal.php`, and add the version to `includes/changelog.php` (the release notes come from it).
  2. Commit and push: `git commit -am "1.27.1: …"` then `git push`.
  3. Tag it: `git tag v1.27.1` then `git push origin v1.27.1`.
  4. GitHub → Actions builds `mq-portal.zip` and publishes the release (it stops if the tag and the version in `mq-portal.php` differ, a PHP file has a syntax error, or the changelog has no entry). The sites see the update within 12 hours, or at once with **Check for updates**.
- **Removing:** deactivating or deleting the plugin does **not** delete any data.
