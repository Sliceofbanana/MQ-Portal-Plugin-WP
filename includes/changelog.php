<?php
/**
 * Version history, newest first. Shown in wp-admin → MQ Portal → System → Tools.
 * Every change round bumps MQP_VERSION in mq-portal.php and adds an entry here.
 */
defined( 'ABSPATH' ) || exit;

return array(
	'1.31.2' => array(
		'date'  => '2026-11-06',
		'items' => array(
			'Ready for delivery: SMS removed from "Notify customer via". The choices are now Messenger (the default), Call, Email and Viber. Jobs already recorded as notified by SMS still show it in their history.',
		),
	),
	'1.31.1' => array(
		'date'  => '2026-11-06',
		'items' => array(
			'Ready for delivery: removed the SMS link from the customer\'s details (it did nothing on the shop computer). To text a customer, use Copy message and paste it in your messaging app. Tap to call and Send email stay.',
		),
	),
	'1.31.0' => array(
		'date'  => '2026-11-06',
		'items' => array(
			'Ready for delivery now shows how to reach the customer: name, phone (tap to call), SMS (opens the messaging app with the message typed in), email, and how they get the order (Pick up, courier or campus). Missing details say "No phone on file" / "No email on file". Details come from the online order, else from the invoice and the customer record.',
			'New "Send email" button next to Copy message: emails the "order ready" message (with your edits) from the shop, with a Track my order button for online orders, and records "Customer notified via Email" by itself. Greyed out when there is no email on file; asks first if the customer was already notified.',
			'Staff guide: Release and pick-up updated for the new contact details and Send email.',
		),
	),
	'1.30.0' => array(
		'date'  => '2026-11-06',
		'items' => array(
			'New: a ready-made message under Ready for delivery: "Hi {name}! This is MQ Prints. Your order {order} is now ready for delivery. Daghang salamat!", with the customer name and job order number filled in. Staff can edit it, tap Copy message and paste it in SMS, Messenger or Viber, then tap Customer notified. The portal does not send it by itself.',
			'New: on the order page, the Pick up choice ends with "Message us via Facebook Page for pick up time." and the Maxim / Lalamove / Grab choice ends with "Inform the rider to call 09205177884 when arrived at our location."',
			'All three texts can be changed in Admin → Settings → Order page.',
			'New: the Staff guide (step-by-step procedures for each position) is now in the portal: "Staff guide" at the bottom of the menu and in the account menu (top right). It opens in a new tab and also works offline. Updated for this version: Approve for Printing for basic documents, Email to customer on invoices, the Needs quotation card, accepted file types, the Copy message step and the new Settings.',
		),
	),
	'1.29.0' => array(
		'date'  => '2026-11-05',
		'items' => array(
			'New: upload your own logos in wp-admin → MQ Portal → System → Settings → Logos. Three choices, each with "Choose from Media Library" and "Use the default": the white logo (portal menu, staff sign-in photo, order page header; thermal receipts print it in black), the colour logo (printed invoices, quotations, billing statements and job orders) and the small icon (above the sign-in form and on the quotation page customers see).',
			'Fixed: after an update some screens kept showing the old logo saved in the browser. Logos now load from an address that changes with every upload and every plugin version.',
			'Fixed: on the tracking page the new Payment section was squeezed into three columns. The price breakdown now shows above the QR codes.',
		),
	),
	'1.28.0' => array(
		'date'  => '2026-11-05',
		'items' => array(
			'New MQ PRINTS / PRINTING SERVICES logo everywhere the wordmark was shown: the staff portal menu, the staff sign-in page, the order page header and printed invoices, quotations and job orders (in colour). Thermal receipts print it in black. The cube icon is unchanged.',
		),
	),
	'1.27.1' => array(
		'date'  => '2026-11-05',
		'items' => array(
			'Website upload box [mq_upload]: the accepted file types (✔️ PDF format for documents ✔️ JPEG / PNG format for images ✔️ JPEG / PNG / PSD format for HD designs) are now one italic paragraph instead of three lines.',
		),
	),
	'1.27.0' => array(
		'date'  => '2026-11-05',
		'items' => array(
			'Updates without uploading a zip: wp-admin → Plugins shows "Update available" for MQ Portal when a newer release is on GitHub, with "Update now", "View details" (the release notes) and "Enable auto-updates". A "Check for updates" link under MQ Portal asks GitHub right away. For the private repository, add MQP_GITHUB_TOKEN to wp-config.php.',
			'Fixed: the plugin description in wp-admin → Plugins showed "â†’" instead of arrows.',
		),
	),
	'1.26.0' => array(
		'date'  => '2026-11-05',
		'items' => array(
			'Order page: the upload box now says "You can upload 1 or more files" with "Up to 100 MB each · or drag files here". The file limit for online orders is now 100 MB (a saved 50 MB is raised; your hosting\'s PHP upload limit still applies).',
			'Order page: the accepted files are listed as ✔️ PDF for documents, ✔️ JPEG / PNG for images, ✔️ JPEG / PNG / PSD for HD designs. JPEG and PNG files can now be uploaded (also in the website\'s upload box).',
			'Website upload box [mq_upload]: under the button it now reads, one per line: ✔️ PDF format for documents, ✔️ JPEG / PNG format for images, ✔️ JPEG / PNG / PSD format for HD designs.',
			'"What is this file for?" has new choices: Document and Certificate Printing, Shirts and Other Garments Printing, Customized Giveaways, Cards and Invitations, Print and Bind, Brochures and Flyers Printing, Sticker Printing, Product Packaging Printing, and Others (the customer types what it is). Document and Print and Bind take PDF; the others take PDF, JPEG, PNG or PSD.',
			'"Notes for this file" is now "Complete instructions for this file". Files too large to upload: customers are asked to upload a screenshot of the design and add a Google Drive link to their files.',
			'Order tracking: new "Payment" step after "Order received". Once Sales set the price, the customer sees the computation (each line, quantity × price, total, paid, amount to pay) and the payment QR codes with Save QR.',
			'New: "Email to customer" on invoices (Sales → Invoice Manager → open an invoice), like on quotations. It sends the updated invoice with each price, the total, the amount to pay, the payment QR codes and a link to the order. Prices must be set first.',
			'Job orders in Sales: two checklists — "Before this job can go to Creatives" (payment, the file requires review/checking/editing, specifications, sales person) and "Before this job is approved for printing" (payment, basic document printing only, no need for Creatives to review/check/edit, specifications, sales person). Sales tick the middle items.',
			'New "Approve for Printing" button next to Mark as RUSH and Send to Creatives: basic document printing that needs no checking goes straight to Production, so only orders that need editing or file checking pass through Creatives. Production is notified as usual.',
		),
	),
	'1.25.1' => array(
		'date'  => '2026-11-05',
		'items' => array(
			'Home dashboard: the "Needs quotation" card now has a thin red border all around instead of a thick left stripe.',
		),
	),
	'1.25.0' => array(
		'date'  => '2026-11-05',
		'items' => array(
			'Home dashboard: new "Needs quotation" card at the top for Sales and admins, listing website quotation requests still waiting for prices (oldest first) with how long ago each customer asked. Requests past 20 hours are marked Late and the card turns red. Click one to open it and add the prices. Home → To do also shows "Quotations to price".',
		),
	),
	'1.24.1' => array(
		'date'  => '2026-10-27',
		'items' => array(
			'Order page: "Add office supplies" is now a dropdown marked Optional. It stays closed until the customer opens it and shows how many supplies were added.',
			'Plugin author changed to Genesis Esdrilon Jr.',
		),
	),
	'1.24.0' => array(
		'date'  => '2026-10-27',
		'items' => array(
			'Order page in 3 steps instead of 7: 1) Your order (files, notes per file, office supplies), 2) Details & place order (pick up / courier / campus, contact details, a short "Check your order" and the Place my order button), 3) Done (order number and the payment QR codes).',
			'Online order page simplified: upload files with no price shown. Every file asks "What is this file for?" (Document, Shirt, Tarpaulin or Stickers): documents must be PDF and keep their page counter (counted for you, and editable), shirts, tarpaulins and stickers must be PSD. Each file has its own instructions box with a guide (copies or quantity, colored or black & white, size…). Office supplies are an always-open section with − / + buttons.',
			'No payment needed when ordering. The order number is made automatically and is used to follow up on the Facebook page chat (set its link in Settings → Order page). The order becomes an invoice with ₱0 lines: open it to set the price. Home → To do shows "New online orders to price".',
			'Payment QR codes are bigger, with a Save QR button, after the order is placed, on the tracking page and in the confirmation email, with no amount computed.',
			'Getting the order: pick up at MQ Printing Services Main Office (with a Google map), or Maxim / Lalamove / Grab Express that the customer books when the order is ready, with the pin address for each app (Maxim: MQ Printing Services, Lalamove: Paks Sinabaw, Cebu City, Grab Express: Angel Pharmacy - V. Rama). All editable in Settings → Order page.',
			'New brand "MQ Printing Services" (code MQ), listed first. Online orders go to it, with order numbers like MQ-261027-ABCD. Get Quotation offers MQ Printing Services first, and a new setting "Get Quotation: MQ Printing Services only" (on by default) hides the other businesses until they are ready.',
			'New wording on the order-placed screen and in the confirmation email: no "we will verify your payment", but how to follow up and pay later.',
			'Fixed: the order page used a placeholder "M" icon in the browser tab instead of the MQ logo.',
			'Online orders (Sales): shows each file with its instructions and the supplies, and the invoice price and payment. Orders from before this version keep their payment check. Mark as RUSH stays on the job order.',
			'New: Settings → Online orders → "Features on the order page": switch Campus delivery, Maxim / Lalamove / Grab Express and Office supplies to On, Coming soon (shown with a Coming soon label but cannot be chosen, for maintenance) or Off (hidden). Campus delivery is back as Coming soon; its campus list is in Settings → Order page.',
			'New: Admin → Daily check, also emailed to the admins every morning for the day before (Settings → Online orders → Daily check email): money in, voided POS receipts and documents, deleted online orders, online orders still without a price after a day, job orders released with a balance, customer uploads waiting, and the printer counter compared with the pages sold.',
			'New: POS → Printer counter. Staff write down each printer\'s page counter at opening and closing (a lower number than the last one needs a note, e.g. "printer was reset"). The daily check compares pages printed with pages sold at the POS, so prints that were not rung up stand out.',
			'The shop email replaces the developer\'s address where it was saved during setup (company contact at the bottom of the order page and quotations, new order emails, admin emails).',
			'New: Delete order (Sales → Online orders → open an order), for admins and the Owner only. It asks for a reason and removes the order with its invoice, job order and files. An order with a recorded payment cannot be deleted (void the invoice instead). Every delete is kept in the activity log ("Deleted an online order") and the admins are emailed.',
		),
	),
	'1.23.0' => array(
		'date'  => '2026-10-19',
		'items' => array(
			'Numbers per branch: quotations, billing statements and invoices of brands with branches now count per branch, e.g. EP-M1-INV-2026-00001 and EP-M2-INV-2026-00001 (brands without branches, and documents without a branch, keep the brand series: GC-INV-2026-00001). Existing numbers stay as they are.',
			'Safe with many devices at once: numbers are handed out one at a time by the database, so two documents never get the same number and none is skipped (tested with 25 at the same moment).',
			'Two people editing the same quotation or invoice: the second save is refused with who changed it and when ("Reload to see their changes"), instead of silently overwriting. Payments, voids and conversions count as changes too.',
			'POS: every sale gets an official number from the server, one continuous series per branch (EP-M1-POS-2026-000001), shown on the receipt with the device number as a reference. Offline sales get theirs when they sync. Earlier sales are numbered on update, in the order they were saved.',
			'Fixed: right after Start fresh, a staff file upload could fail ("Could not save the file"). Start fresh now keeps the (empty) folders, saving checks the folder really exists, and a failed save is recorded with its reason in System → Problems.',
		),
	),
	'1.22.0' => array(
		'date'  => '2026-10-19',
		'items' => array(
			'Safe staff delete: a Delete button on every row of Admin → Staff and a "Remove account" section on each staff page (with the reason when it is not allowed). It first shows what they still have open (job orders, quotations, unpaid commissions, an open shift) and lets you hand their open work to another sales person; then you type their username and your own password (5 wrong tries lock it for 15 minutes).',
			'Deleted accounts go to Staff → Deleted: signed out and blocked at once, restorable for 30 days, then removed for good by themselves. WordPress administrators can "Delete now". The last active Owner, WordPress administrators and your own account cannot be deleted. The admins are emailed about every delete and restore.',
			'Account requests redesigned: Waiting / Approved / Denied with counts, a card per person (position asked for, shop, contact, their note, how long ago), Approve and Deny right on the card, and a warning when the email or username is already used.',
			'Start fresh (wp-admin → System → Tools) can also remove test ("mock") staff accounts, each ticked by hand; administrators and your own account are never listed.',
			'The portal no longer shows any shortcodes (they are only in wp-admin → MQ Portal → Shortcodes).',
		),
	),
	'1.21.0' => array(
		'date'  => '2026-10-14',
		'items' => array(
			'New: [mq_print_collection], the photo carousel for the "Our Print Collection" section (only the carousel: the heading, text and button stay in Elementor): arrows, swipe on phones, click a photo to see it large, and optional sliding by itself. Gallery settings: photos side by side, photo shape, auto-slide, how many.',
			'New: wp-admin → MQ Portal → Print collection: drop many photos at once (uploaded one by one with a progress bar, so the server\'s upload limits don\'t matter) or copy them from the Media Library; drag to reorder (saved by itself); show, hide, delete or describe each photo, or several at once; numbers at the top (photos, on the website, storage used, saved). Each photo is kept as two small WebP files (about 150–250 KB together) instead of the original plus WordPress\'s 5–8 extra sizes, so it takes about 30–50 times less storage.',
			'Previous orders: its own management page (Posts / + Add posts / Settings): drag to sort, tick several to show, hide or remove, and Edit each post (caption, picture, link, read from Facebook again).',
			'Previous orders: paste many links at once in any form (one per line, spaces, commas, or a message with links in it); "story.php" and address-bar links work, and duplicates are skipped.',
			'Fixed: posts that Facebook shows to the website with a different picture link ("lookaside") got no picture. When Facebook won\'t show a post to the website at all, the error now says what Facebook answered, and the post is kept (switched off) to finish by hand; there is also "Add one by hand" with your own picture.',
		),
	),
	'1.20.0' => array(
		'date'  => '2026-10-14',
		'items' => array(
			'New: [mq_previous_orders] for the "Our Previous Orders" section: Facebook posts featuring your orders as cards (picture, caption with "Read more", "View on Facebook") and a "See more on our Facebook page" button.',
			'New: wp-admin → MQ Portal → Previous orders. Paste Facebook post links (Share → Copy link; several at once); the picture and caption are read from Facebook and a copy of the picture is kept on your site. Edit the caption, change the order, switch posts off, refresh or remove them. Optional: Facebook\'s own post cards instead.',
		),
	),
	'1.19.2' => array(
		'date'  => '2026-10-14',
		'items' => array(
			'Side menu: Sales and Admin open like a dropdown, and each of their pages (Invoice Manager, Customers, Department reports, Reviews, Staff…) is its own page instead of a row of tabs. Waiting counts show next to each page.',
			'Creatives and Production & QC are one board each (kanban columns): Not yet reviewed / Needs editing, and Production queue / Quality check / Ready for delivery. The TV monitor is a button on the board.',
			'Bottom of the side menu: My profile and Settings (portal settings, admins only). My settings and Log out are in the account menu at the top right.',
			'Reviews: no coloured edge on the cards, and Delete moved inside Edit (away from the everyday buttons).',
		),
	),
	'1.19.1' => array(
		'date'  => '2026-10-14',
		'items' => array(
			'New: Portal → Admin → Reviews. Approve, deny (hide), add, edit, feature and delete website reviews from the portal, one at a time or several at once, with search and filters, so nobody needs wp-admin for it. New permission "Website reviews" (Owner by default; give it to others in Admin → Staff → Access). The waiting count shows on the Admin menu and on Home → To do.',
			'Review notifications and the admin review email now open the portal\'s Reviews tab instead of wp-admin.',
			'Start fresh: Reviews are no longer ticked by default (they are often real ones from Google or added by hand).',
			'Fixed: "Deny" on an account request in the portal did nothing.',
		),
	),
	'1.19.0' => array(
		'date'  => '2026-10-14',
		'items' => array(
			'New review emails: the admins get a designed email (stars, the review in a quote box, waiting / shown / private, the customer\'s email, a button to the Reviews page), and customers who gave their email get a thank-you email (Reviews → Settings & emails; both can be turned off).',
			'System → Settings → Emails: "Admin emails go to" (one or more addresses) is used for every admin email: new reviews, account requests, archives, problem alerts, and orders / quotation requests unless the portal has its own address. "Sender name": emails come from your company name, never from "WordPress", and subjects no longer start with [WordPress].',
			'Reviews page redesigned: at a glance (waiting, on the website, average rating, Google, private feedback), Waiting / On the website / Hidden / All with counts, search and filters (source, stars), select several reviews to approve, hide or delete at once, 20 per page, the waiting count on the tab, and the how-to in its own "On the website" tab.',
			'New: System → Tools → "Start fresh (remove test data)": removes test quotations, invoices, job orders, online orders, customers, uploads and files, POS sales, attendance, reviews, account requests and logs (you choose), and keeps settings, shops, products, prices and staff. Type DELETE to confirm; numbering starts again at 1.',
		),
	),
	'1.18.2' => array(
		'date'  => '2026-10-14',
		'items' => array(
			'Fixed: "Please complete the verification and try again" with no verification on the page (Leave a Review, upload form, Get Quotation, order page). The spam check now only runs when both Turnstile keys (Site key and Secret key) are filled in, the website and the server always agree, and saving new keys clears the page cache. The health check warns when only one key is filled in.',
			'Fixed: MQ Portal → System opened the Dashboard instead of System (it now opens on Problems).',
		),
	),
	'1.18.1' => array(
		'date'  => '2026-10-14',
		'items' => array(
			'wp-admin tidied up: MQ Portal → Dashboard is now the one main page, with health (every check and its fix), what is waiting, this week on the website, the latest requests and uploads, storage and backups, background jobs, recent problems and activity, what\'s new, updates on this site, data, the environment report, and all quick actions. System → Overview and the Website page are part of it (old links open the Dashboard).',
			'System has 6 tabs: Problems, Activity log, Security & sessions (sign out one person or all staff moved here from Tools), Files & archives, Tools, Settings. The wp-admin home widget is one status line with a link.',
			'New on the Dashboard: "POS sales to check": sales flagged for an admin (custom or changed price, an old date, another shop) with "Checked" / "Mark all as checked". They are also in the MQ Portal menu count.',
			'New on the Dashboard: "Last copy off the site", with a reminder after 30 days without an archive download, a Google Drive copy or a setup export.',
			'New: Google Drive for archives (System → Settings → Google Drive). Each new archive is uploaded in the background in 8 MB pieces (big zips work too), Google\'s copy is checked (size and MD5), then the space on the hosting is freed automatically (can be turned off). The portal can only see the files it uploads. Set-up steps are on the page.',
			'Fixed: a website quotation request with attached files could not be saved after 1.18.0.',
		),
	),
	'1.18.0' => array(
		'date'  => '2026-10-14',
		'items' => array(
			'wp-admin and wp-login.php are for WordPress administrators only. Every portal account (also the Owner and Admin / Finance / Ops) signs in through the portal only, and existing wp-admin sign-ins of staff were ended. The "Website reviews" staff permission is gone: reviews are managed by administrators in wp-admin → MQ Portal → Reviews.',
			'Security: wp-login.php gives the same answer to staff whether the password is right or wrong, so it can\'t be used to guess passwords around the portal lockout. A username that gets many wrong passwords from different connections is locked too, and you are alerted.',
			'Security: files open only for people who may see that job order or upload. Sales staff who see their own customers can\'t open other people\'s files, payment screenshots are only for staff who handle payments, and unfinished order uploads never open from the portal.',
			'Creatives and Production no longer receive prices in the job order list (they were hidden on screen but sent along).',
			'New permission "Custom prices, discounts and changing invoices after a payment" (Admin, Finance & Ops; on for MQ Admin / Finance / Ops and the Owner). Without it: no discount in the Invoice Manager, no changes to an invoice once a payment is recorded, no payments dated more than a week back, and no "+ Custom item" in the POS. A POS sale with a custom or changed price, an old date or another shop is kept (the receipt was printed) but flagged to the admins.',
			'Settings → Company & documents → "Payment before Creatives": any payment, the downpayment, fully paid, or no payment needed.',
			'Commission: earned only when the job order is delivered and the invoice is fully paid, and only when there is a commission (no rows at 0%).',
			'Quotation links expire: customers\' links stop working a set number of days (default 90) after the order is completed or cancelled, or the quotation is no longer valid (Settings → Company & documents).',
			'New: Archives (wp-admin → MQ Portal → System → Uploads & files). Files of job orders finished at least 14 days ago go into a zip every week, and right away when the storage is nearly full; you get an email, download it to a PC, then click "free up the space". Nothing is deleted before the download. Archived files stay listed on the job order.',
			'Accepting a quotation or converting a document twice at the same moment makes one invoice and one job order; two clicks on a job step move it one step; a double tap on Clock in opens one shift.',
			'Signing out removes your saved lists from that device, and the offline copy of the portal no longer opens the previous person\'s screen after they signed out or closed the browser.',
			'Customer search in Sales follows "own customers only"; the activity log now also records documents and payments made offline, invoice price changes and every POS sale.',
			'Health check: warns when the WordPress timezone is not Manila. PDF page counting also reads newer PDFs (compressed page lists). Long lists show "Show older" after 300. Faster Customers list, POS day list and attendance report. Forgotten shifts close within the hour.',
		),
	),
	'1.17.1' => array(
		'date'  => '2026-10-14',
		'items' => array(
			'Order pop-up: no blue header bar, just a round close button; it fits any screen and website design (theme styles can\'t change it) and never scrolls sideways.',
			'The "Track an order" tabs and links are gone from the order pop-up and page (the tracking link in customers\' emails still works).',
		),
	),
	'1.17.0' => array(
		'date'  => '2026-10-14',
		'items' => array(
			'"Upload Document" is now the order: the upload box ([mq_upload]) and every #mq-upload button open the order steps in a pop-up (upload, options, instant price, pick-up or delivery, payment). Files chosen in the box are carried into the order.',
			'The older "send files, we contact you" form only comes back when online ordering is switched off (portal → Admin → Settings → Online orders).',
			'New wp-admin → MQ Portal → Dashboard for the developer / system admin: what\'s new (searchable changelog, "new since you last looked"), the update history of this site, health, background jobs, recent problems and activity, data counts, a copyable environment report, and quick actions (clear page cache, run jobs, clear spam limits, run setup).',
		),
	),
	'1.16.1' => array(
		'date'  => '2026-10-14',
		'items' => array(
			'Data Privacy Act: the upload form, Get Quotation, the online order page and "Leave a Review" ask customers to agree to the Privacy Policy (WordPress → Settings → Privacy) before sending.',
			'Remove a customer\'s personal data on request: Sales → Customers → the customer → "Remove personal data" (Owner and admins), or WordPress → Tools → Erase Personal Data by email. Invoices keep the name and amounts (BIR).',
			'Uploaded files are checked inside: a .pdf must really be a PDF, a .jpg a picture, and so on. Renamed or fake files are refused.',
			'Changing a POS product clears the website\'s page cache, so the price list and Get Quotation show the new price right away.',
			'System: new "Background jobs" check shows whether the Hostinger cron job runs, with step-by-step setup on the Settings tab.',
			'Security: the portal no longer accepts the old WordPress check code left over from the separate sign-in change.',
		),
	),
	'1.16.0' => array(
		'date'  => '2026-10-14',
		'items' => array(
			'Customers accept quotations online: their quotation link has an "Accept quotation" button. It opens the invoice and job order, notifies Sales, and emails the customer.',
			'Order tracking on the same link: Order placed, Payment received, Printing, Quality check, Ready, Completed. Customers are emailed when the order is ready and when it is completed.',
			'Reminder: website quotation requests still not priced after 20 hours notify the Owner and admins (bell and email), since the website promises a reply within 24 hours.',
			'New Sales → Customers: everyone you made a quotation or invoice for, plus website customers (quotation requests, uploads, online orders), with each customer\'s history and totals.',
			'New [mq_prices] shortcode: the live price list from the POS products, grouped by category, with a "Get a quotation" button.',
			'POS products: "On the website" is now on for every product (price list and instant price in Get Quotation). Untick it to leave a product out.',
			'wp-admin → MQ Portal → Website: what came in this week, what is waiting (pricing, uploads, reviews) and storage use. The MQ Portal menu shows one count of everything waiting.',
			'Sign out one person: in the portal (Admin → Staff → open the person → Sign out) and in System → Tools → Sessions, which lists who is signed in.',
			'Reviews on phones: exactly one review per slide.',
			'New wp-admin → MQ Portal → Shortcodes: every shortcode and button link, what it does, where to use it and its options, with Copy buttons.',
		),
	),
	'1.15.1' => array(
		'date'  => '2026-10-14',
		'items' => array(
			'Featured review: the picture, name and stars now look exactly like the other review cards (initials in a circle when there is no picture).',
		),
	),
	'1.15.0' => array(
		'date'  => '2026-10-14',
		'items' => array(
			'Featured review of the week: [mq_featured_review] shows one review card (text, picture, name, a line under the name such as "2026 BAR passer", stars), made for the "Have questions? Contact us" section. It changes by itself every Monday.',
			'Reviews: new "Line under the name" when adding or editing a review.',
			'Reviews: "Feature this week" puts a review up right away; "Add to featured lineup" chooses which reviews take turns (without a lineup, all 5-star reviews take turns).',
		),
	),
	'1.14.0' => array(
		'date'  => '2026-10-14',
		'items' => array(
			'Get Quotation on the website: link any button to #mq-quote (or use [mq_quote]). Customers pick fixed-price items and/or describe custom work (with files), and give their name, email and phone.',
			'Fixed-price items only: the quotation shows right away and is emailed with a link to view/print it. Custom items: the customer is told the team replies within 24 hours; the quotation waits in the portal as "Needs pricing" and Sales are notified.',
			'Every request is a real quotation in Sales → Invoice Manager with the customer saved (name, email, phone, company). New "Email to customer" button on quotations.',
			'POS products: new Brand field and "Fixed price on the website Get Quotation" option.',
		),
	),
	'1.13.0' => array(
		'date'  => '2026-10-14',
		'items' => array(
			'Reviews: only 4- and 5-star reviews are ever shown on the website. Lower ones (from the website or Google) are kept private under Reviews → Hidden as feedback for you, and can\'t be shown.',
			'Review spam limits: per connection per hour (System → Settings) and per day, 50 a day from everyone, 300 waiting at most, the same text only once, pictures at most 3 MB.',
			'Storage protection for files customers send (upload form, online-order files, review pictures): megabytes per connection per day, megabytes per day for the whole site, a size limit for the portal\'s files, and minimum free space on the server. When a limit is hit, customers are asked to email their files, and you are alerted.',
			'Files of dismissed customer uploads are deleted after 30 days (changeable; uploads turned into job orders keep their files).',
			'New staff sign-in page: a shop photo beside the form (on phones, a banner on top) with the logo and a welcome line, and a "Back to website" button. Choose your own photo in System → Settings → Staff sign-in page.',
			'On small phones, the "Online" pill in the portal\'s top bar only shows when something needs attention.',
		),
	),
	'1.12.0' => array(
		'date'  => '2026-10-14',
		'items' => array(
			'Notifications in the portal: a bell in the top bar with a red count (also in the browser tab), a list of the latest notifications, "Mark all as read", and a click takes you to the job order, order, upload or request.',
			'You get what your position works on: job orders arriving at Creatives, Production or QC, ready for delivery; updates on your own job orders (assigned to you, sent back, needs editing, ready, notes, RUSH); new customer uploads and online orders; website reviews; account requests; system problems. Never your own actions.',
			'My settings → Notifications: switch each type on or off. Admins and the Owner start with only the admin-level ones on. Per device: pop-up notifications and a short sound.',
			'The portal checks for new notifications every 45 seconds and right away when you come back to the tab.',
		),
	),
	'1.11.0' => array(
		'date'  => '2026-10-13',
		'items' => array(
			'Website reviews: new [mq_reviews] shortcode with review cards (stars, profile picture or initials, "Read more"), arrows, dots and swipe on phones, plus your Google rating.',
			'"Leave a Review" opens a pop-up: stars, name, review, optional email and profile picture. New reviews wait for approval (or show right away, if you choose), and you can be emailed about them.',
			'wp-admin → MQ Portal → Reviews: approve, hide, edit, delete and add reviews; connect Google reviews (fetched every day); settings.',
			'New permission "Website → Reviews manager": staff with it can sign in to wp-admin and see only the Reviews page.',
			'Admins can delete staff accounts (staff record → Delete). Their past work stays and shows their name with "(deleted)".',
		),
	),
	'1.10.1' => array(
		'date'  => '2026-10-13',
		'items' => array(
			'Fixed: signing out of the portal could fail ("403") right after updating, while the browser still ran the previous portal files. Signing out now always works.',
			'The portal’s offline copy now keeps files by version, so an update never mixes in old files from the browser, LiteSpeed or the host’s CDN.',
			'Portal pages and answers are never stored by LiteSpeed Cache or other caching plugins (they are personal to the signed-in staff member).',
			'Fixed the "Failed to execute clone on Response" message in the browser console.',
		),
	),
	'1.10.0' => array(
		'date'  => '2026-10-13',
		'items' => array(
			'Header and menu buttons can open the upload pop-up: link the button to #mq-upload (or give it the CSS class mq-upload-open). The pop-up shows the upload box and the details form together, on every page of the website.',
			'The upload pop-up keeps its upload box until files are chosen; removing the last file shows the box again instead of closing.',
			'New option [mq_upload box="no"]: only the pop-up, no box on the page.',
			'The portal now has its own sign-in: signing out of the portal no longer signs you out of wp-admin (and a wp-admin login no longer opens the portal by itself). Changing a password, disabling an account or "Sign out all staff now" ends portal sessions on every device.',
			'Portal icons: the browser tab and phone home-screen icon are now the white MQ cube on the portal blue; the sign-in screen shows the colored MQ cube.',
		),
	),
	'1.9.1' => array(
		'date'  => '2026-10-13',
		'items' => array(
			'Upload pop-up no longer scrolls sideways: long file names, long shop names and the spam check now fit inside the pop-up on phones and computers.',
		),
	),
	'1.9.0' => array(
		'date'  => '2026-10-13',
		'items' => array(
			'New [mq_upload] design: one big orange upload box (click or drag files). Choosing files opens a pop-up with the files (remove / add more) and the details form; after sending, the same pop-up shows the reference number. On phones the pop-up slides up from the bottom.',
			'New options: [mq_upload text="Start Uploading PDF Files" title="Almost done! Tell us about your order"].',
			'The upload form brings its own design and settings, so it looks and works the same with any theme, page builder, pop-up or caching plugin (e.g. LiteSpeed Cache).',
			'If the page’s scripts are blocked, the form still sends as a normal form and shows the result.',
		),
	),
	'1.8.0' => array(
		'date'  => '2026-10-13',
		'items' => array(
			'Portal address: the portal can have its own address, e.g. app.mqprintsph.com, which stays in the address bar (System → Settings → Portal address). It only turns on after a live check that the address reaches this site; the old /mq-portal/ link forwards to it.',
			'On the portal address, website pages and wp-admin open on the main site; the online order page always stays on the main site.',
			'Overview checks that the portal address keeps working.',
		),
	),
	'1.7.0' => array(
		'date'  => '2026-10-13',
		'items' => array(
			'System → Settings: email alert timing, how long logs are kept, maintenance mode, login lockout rules, spam limits for orders and account requests, optional PHP warning log, and "delete all data when the plugin is deleted".',
			'Maintenance mode: staff see a "Back soon" screen and cannot sign in; WordPress administrators keep working with a red reminder bar. Customer ordering is not affected.',
			'System → Tools: export and import the setup (settings, order pricing, brands, shops, POS products) as a file, database table sizes, "Sign out all staff now", and this version history.',
			'System → Uploads & files: upload limits checked against the server, spam check status, latest customer uploads, 7-day counts, storage by file type, and the website shortcodes.',
			'Activity log now includes customer uploads and online orders (received and turned away).',
			'Overview checks that the server accepts the upload sizes the portal allows.',
			'"Portal maintenance ON" reminder in the wp-admin top bar.',
		),
	),
	'1.6.0' => array(
		'date'  => '2026-10-13',
		'items' => array(
			'New wp-admin page MQ Portal → System: health checks with fixes, problem log with email alerts, activity log with CSV export, security overview, uptime check at /wp-json/mq/v1/health, and a Dashboard status box.',
			'New sign-in screen: white card, MQ cube, icon fields, show-password button, "Don\'t have an account? Sign up".',
			'Home redesign (no on-duty list), My profile and My settings redesign, underlined tabs inside pages, Account requests as its own Admin tab.',
		),
	),
	'1.5.0' => array(
		'date'  => '2026-10',
		'items' => array(
			'Staff account requests with admin approval.',
			'Profile pictures.',
			'Access rights by department level with checkboxes.',
		),
	),
	'Earlier' => array(
		'date'  => '',
		'items' => array(
			'Staff portal, time clock (shop-only clock-in), Invoice Manager, job order workflow, Creatives, Production & QC, POS, owner dashboard, offline sync, customer uploads and online ordering with the [mq_order] popup.',
		),
	),
);
