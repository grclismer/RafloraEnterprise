# Raflora Enterprises — User Manual

Floral event booking and management system. This manual covers the four portals:

1. [Public & Guest](#1-public--guest-portal) — anyone, no account needed
2. [Client](#2-client-portal) — registered customers
3. [Admin](#3-admin-portal) — Raflora administrators
4. [Staff](#4-staff-portal) — Raflora operations staff

At the end you will find the [booking status guide](#5-booking-status-guide), a [checklist of what was inspected](#6-inspection-checklist), and a [list of issues that still need verification](#7-unresolved-issues-requiring-verification).

> How this manual was prepared: from the application's routes, controllers, services, views and automated tests. Sections marked **(not exercised)** were read in the code but not tried end to end while this manual was written. See section 6.

---

## The booking journey at a glance

The system tracks every booking through 14 stages in three groups. The same tracker appears on the Admin, Client and Staff pages.

| Group | Stages |
|---|---|
| Guest | 1. Request Submitted → 2. Awaiting Claim |
| Client | 3. Raflora Review → 4. Material Preparation / Validation → 5. Quotation → 6. Approval → 7. Payment → 8. Confirmed |
| Staff | 9. Preparation & Reservation → 10. Dispatch → 11. Event Execution → 12. Material Return → 13. Inventory Reconciliation → 14. Completion |

**Delivery orders** (event type "Delivery") use 12 stages: Event Execution is called **Delivery**, and Material Return and Inventory Reconciliation are skipped because the items are sold, not rented.

---

## 1. Public & Guest Portal

### 1.1 Home, Gallery, Packages, About

The top menu shows **HOME**, **GALLERY**, **PACKAGES**, **ABOUT** and **BOOK EVENT**.

1. **Gallery** shows Raflora's past event photos, grouped by event.
2. **Packages** lists the ready-made floral packages with their catalog price. Click a package's booking button to start a booking with that package already selected.
3. **Book Event** starts a booking:
   - not signed in → the **Guest Booking** form (section 1.2);
   - signed in as a client → the **Client Booking** form (section 2.3);
   - signed in as Admin or Staff → you are sent back to your own dashboard with the message "The client portal is only available to client accounts."

### 1.2 Guest Booking form (`/guest/booking`)

A four-step form. A guest can send a request without an account, but must create an account and **claim** it within 24 hours for Raflora to continue.

**Step 1 — Event Details**
1. Choose the **Event Type**: Wedding, Birthday, Corporate, Delivery or Other. For Other, type the event type in the box that appears.
   - Choosing **Delivery** hides *Expected Guests* and *Number of Tables*, which a delivery does not need.
2. Pick the **Event Date** (today or later), **Start Time** and **End Time** (both required for guests).
3. Enter the venue: city/municipality and the specific address. Optionally click **Select on Map** to pin the exact spot.
4. Optionally choose **Indoor / Outdoor / Indoor & outdoor / Not sure**, and enter the expected guests and number of tables.
5. Click **Next**.

**Step 2 — Booking Method & Design**
1. Choose a method:
   - **Smart AI Custom Design**: upload up to 5 inspiration photos. The AI suggests floral materials for each photo and shows an *indicative price range per area*. This is **not** a quotation.
   - **Pre-Set Curated Package**: choose a package with a fixed catalog price.
2. For Smart AI, wait for each photo's analysis to finish. Optionally fill in the design brief (colours, style and similar).
3. Click **Next**.

**Step 3 — Contact Information**
1. Enter your full name, email, 11-digit mobile number (starting with 09) and address.
2. Click **Next**.

**Step 4 — Review & Submit**
1. Check the summary cards. Each card opens a dialog where you can review or correct that part.
2. Click **Submit**.

**What happens next**
- Your request is saved and you are taken to its status page (section 1.3).
- An email with your private request link is sent to the address you entered.
- **The request expires in 24 hours if it is not claimed.**
- No payment is needed at this stage.

### 1.3 Guest request status page (`/guest/bookings/{private link}`)

1. Shows your event details, the AI material suggestions with per-area price ranges (or your package), and a live **expiration countdown**.
2. The page checks for updates every 10 seconds.
3. To continue, claim the request (section 1.4). A guest **cannot** accept a quotation or pay. The system refuses both and asks you to sign in.
4. After 24 hours without a claim the page shows the request as expired, and you must submit a new one.

### 1.4 Claiming a guest request

1. Open the link from the email, or click the claim/sign-in option on the status page.
2. **Register** or **Log In** with the **same email address** used on the request. A different email is refused.
3. Verify your email with the 6-digit code (section 1.5) if you have not already.
4. On **Claim Booking**, check the details and click **Yes, Claim & Submit Request**.
5. The request becomes a client booking in **My Bookings**, and the workflow moves to **Raflora Review**.

### 1.5 Accounts: register, sign in, verify, reset

**Register**
1. Click **Log In**, then the link to register. Enter your name, email and password, then submit.
2. New accounts are always **client** accounts.
3. You are taken to email verification.

**Verify email**
1. Enter the **6-digit code** sent to your email. Use **Resend** if needed; there is a short waiting time between resends.
2. Clients must be verified before using the client portal.

**Sign in**
1. Enter your email and password.
2. Clients can also use **Sign in with Google**. Google sign-in is **not** allowed for Admin or Staff accounts.
3. Admin and Staff signing in on a new device must enter a **device verification code** sent by email. They can choose to trust the device so the code is not asked again.

**Forgot password**
1. Click **Forgot password**, enter your email and submit.
2. Clients and Staff receive a reset link by email.
3. Admin accounts get a **6-digit code** flow instead: enter the code, then set a new password.

---

## 2. Client Portal

The menu shows **DASHBOARD**, **MY BOOKINGS** and **BOOK EVENT**. The avatar menu has **History**, **Account** and **Log out**, and the bell opens **Notifications**.

### 2.1 Dashboard (`/client/dashboard`)

1. **Active Bookings** lists your current bookings. Click **View Details** to open one.
2. **Booking updates** shows recent notifications. Click **All updates** or **View all** for the full list.
3. Click **Start a New Booking** to open the booking form.

### 2.2 My Bookings (`/client/bookings`) and History (`/client/booking-history`)

1. **My Bookings** lists each booking with its current stage (for example "Stage 5 of 14 · Quotation"). Click **View Booking** to open it.
2. **History** lists past bookings. Click **View details** to open one.

### 2.3 New Booking form (`/client/bookings/create`)

A three-step form. Your contact details come from your account.

1. **Step 1 — Event Details**: same fields as the guest form (section 1.2, Step 1). Start and end times are optional. Choosing **Delivery** hides guests and tables.
2. **Step 2 — Booking Method & Design**: Smart AI Custom Design (up to 5 photos) or Pre-Set Curated Package.
3. **Step 3 — Review & Submit**: check the summary cards, then submit.

**What happens next**
- The booking is created straight away with status **Pending** (stage *Raflora Review*), and you are taken to its booking page.
- No claim is needed and no payment is required yet.

### 2.4 Booking page (`/client/bookings/{id}`)

This page shows your booking from start to finish.

**What you see**
1. The event summary at the top: event type, date and time, venue, guests and tables.
2. For **Delivery** orders, a blue **Delivery order** card that explains no items need to be returned.
3. **Booking Progress**: the stage tracker. The Staff stages appear once your booking is confirmed.
4. The **status card**: the current status in plain words and what you need to do.
5. Supporting sections: **Event Details**, **Inspiration Image & AI Recommendation**, **AI Suggested Materials**, **Quotation Breakdown**, **Selected Package**, and **Proposals & Feedback**.

**Messages and meetings (floating chat)**
1. Click the round **chat button** in the bottom-right corner. A red badge shows unread messages from Raflora.
2. **Messages**: type and send messages, attach files, and see change-request cards and Raflora's replies.
3. **Meetings** (active bookings only):
   - pick a meeting type and your preferred date and time, add an agenda, then click **Request Meeting**;
   - Raflora confirms the final schedule; an online meeting shows a **Join meeting** link, an in-person meeting shows the location;
   - you can **Cancel this meeting** while it is still open.
4. Close the chat with **×**, the chat button or **Esc**.

**When the quotation is ready (status *Quotation Sent*)**
1. Review the **Quotation Breakdown** and its "valid until" date.
2. Choose one:
   - **Accept Quotation**: status becomes *Quotation Accepted*. Raflora then gives final approval (section 3.3, step 6).
   - **Request Changes**: see below.
   - **Request Cancellation**: see below.
3. If the quotation has expired, accepting is locked until Raflora re-issues it.

**Request Changes** (any time before the event starts)
1. Click **Request Changes** and choose a **Change Type**:
   - **Material / Item**: enter the item and quantity. Details are optional.
   - **Schedule**: the current schedule is shown. Pick a **New Event Date** (not in the past), optionally new start and end times, and optionally add a reason.
   - **Other**: describe the change. This field is required.
2. Click **Send Request**.
3. **What happens next**
   - If the quotation was waiting for you, the booking moves to *Change Requested* and Raflora revises the quotation.
   - At later stages (accepted, paid, confirmed) the booking keeps its status, and Raflora is alerted.
   - A schedule request shows a note under the status card: *requested*, then *accepted* (with the new date) or *declined* (with Raflora's reason).

**Paying (status *Admin Approved*: "Payment Required")**
1. Pay Raflora by **GCash** or **bank transfer**, outside the system. See issue I-1: the system does not show the account details.
2. On the booking page choose the **Payment Method** and **Payment Option**: 50% downpayment (the percentage is set by Raflora) or full payment.
3. Enter the **Reference Number** and click **Submit Payment Reference**.
4. **What happens next**
   - The status becomes *Payment Submitted* and shows "Verification Pending".
   - Raflora checks the reference. If it's verified, the booking is **confirmed** (*Downpayment Received* or *Confirmed*).
   - If it's rejected, a red notice asks you to submit a corrected reference.
   - Only one reference can wait for verification at a time, and the same reference number cannot be reused.

**Paying the remaining balance** (from the event day onward)
1. Click **Pay Remaining Balance**, enter the method and reference, then submit.
2. This is not available while Raflora is reconciling returned materials (*Pending Return*).

**Delivery orders: confirming receipt**
1. When the order is **Out for Delivery**, click **Item Received** on the blue Delivery order card, then **Yes, I received it**.
2. If fully paid, the booking is **Completed**. Otherwise it waits for the remaining balance.

**Request Cancellation**
1. Click **Request Cancellation**, give a reason, then click **Submit Cancellation Request**. Click **Keep Booking** to back out.
2. The status becomes *Cancellation Requested* until Raflora approves or denies it.

**Proposals & Feedback**
1. When Raflora uploads a design proposal (v1, v2, and so on), open it, write your feedback and click **Submit Proposal Feedback**.

### 2.5 Notifications (`/client/notifications`)

1. Lists Raflora's updates: quotation ready, payment verified, schedule decisions, and so on.
2. Click a notification to read it. **Open Booking** or **Open Conversation** takes you to the booking or the chat.
3. Use **Mark all as read** to clear the list.

### 2.6 Account settings (`/client/account-settings`)

1. Edit your first and last name, email, mobile number, address and profile photo.
2. To change your password, enter the current password and the new password twice (at least 8 characters).
3. Click **Save changes**.
4. **Changing your email** logs the change and sends a new 6-digit code. You must verify the new address before using the portal again. An email already used by another client record is refused.

---

## 3. Admin Portal

The sidebar groups: **Overview** (Dashboard), **Bookings** (Booking Management, Notifications), **Operations** (Gallery, Package, Inventory Management, Return Tracking), **Clients** (Client Records), **Analytics** (Reports & Analytics), and **System** (Account Management).

### 3.1 First-time setup and recovery

1. **First sign-in** (bootstrap admin): you are sent to **Setup**.
   1. Enter a real email address.
   2. Verify it with the 6-digit code.
   3. Set a new password.
   4. Save the **recovery code** shown and confirm you have stored it.
2. **Locked out**: open **Admin Recovery** (`/admin/recovery`). Enter your email and the recovery code, then the 6-digit email code, then a new password.
3. **Change admin email**: Account Management → Email Security. Enter the new email, then verify the code sent to it.

### 3.2 Dashboard (`/admin/dashboard`)

1. **Needs your attention**: pending reviews, payments to verify, and active alerts.
2. **Revenue Overview**, **Payment Summary**, **Bookings by Event Type**, **Upcoming Events**, **Recent Bookings**, **Top Packages**, **Inventory Status**, **Low Stock Items** and **Recent Activities**.
3. **Shortcuts**: Review Bookings, Quotations, Return Tracking, Manage Inventory and **AI Material Plan**.
4. This page has no booking search or New Booking button. Use **Booking Management** to find bookings.

### 3.3 Booking Management (`/admin/bookings`)

**Finding a booking**
1. Use the stage tabs: **All Bookings, Awaiting Claim, Review, Quotation, Approval, Payment, Confirmed, Cancelled**. *Awaiting Claim* lists guest requests not yet claimed.
2. Filter by event type, event date range and sort order.
3. **Search** by client or guest name, email, venue or event type, or by **booking number**. Any of "53", "#53", "booking 53", "Booking #53" or "BOOKING NO. 53" finds booking #53, in any letter case.
4. Click **View / Review Booking** to open a booking.

**The booking page (`/admin/bookings/{id}`)**
- The **Overview** stays on the page. **Event Details & AI**, **Quotation**, **Materials & Inventory**, **Preparation** and **Staff Assignment** open as pop-ups over it.
- Tab colours: **green** = completed, **orange** = needs action, **blue** = waiting for the client.
- The **Booking Workflow** header shows a **Next step** shortcut button that opens the section you need next.
- The round **chat button** (bottom-right) opens **Messages** and **Client Meetings**. Messages can be shared with the client (*Client & Admin*) or kept internal (*Admin & Staff*).

**Workflow, step by step**

1. **Raflora Review**
   1. Open **Event Details & AI** to check the details, inspiration photos and the AI analysis.
   2. Click **Complete Raflora Review**. Confirming, linking or promoting any material also completes the review.
2. **Material Preparation / Validation** (Quotation pop-up → *Material review & quotation*)
   - **Confirm Material** for each AI-suggested line, or **Confirm all linked (N)** to confirm every line that is already linked to inventory.
   - **Unmatched / AI Suggestion** lines (not yet linked to inventory):
     - **Link to Existing Inventory** picks an existing catalog item;
     - **Promote to Catalog** creates a new inventory item only when no likely duplicate is detected. If the name resembles an existing item, review the candidates; link the right item or explicitly confirm that it is genuinely different.
   - Adjust quantities and unit costs, or tick **Remove** for lines not needed.
   - Known names such as "Floral Foam Brick" and "Floral Foam Blocks" match the canonical foam item. If the suggested unit differs from the catalog unit, the system asks Admin to link it manually rather than silently combining stock units. Spelling/word-order lookalikes are held for review before a new catalog item can be created. After linking, the analysis-only suggestion is removed from the unmatched list.
3. **Quotation**
   1. Check the **Pricing** panel: BOM subtotal × markup multiplier = calculated total. Optionally type a **Final Quoted Total (Override)**.
   2. Lines linked to the same inventory item appear as one row with their quantities combined, including while awaiting confirmation. Confirming that row confirms all of its source lines; the underlying booking lines remain separate. The client-facing image-based suggestions remain unchanged.
   3. The notification **Add Missing AI Items to Inventory** action links recognized catalog matches, creates clearly new items, and skips likely lookalikes for review from the booking. CSV import also stops on likely duplicate names; it does not silently create another catalog record.
   3. Write a note to the client if needed.
   4. Click **Approve & Send Official Quote to Client**. The status becomes *Quotation Sent* and the quotation is valid for 7 days.
   5. If it expires, click **Re-issue Quotation (Refresh 7-Day Window)**.
   6. For long-term bookings, reconfirm the price when prompted: unchanged, or a revised quote.
4. **Change requests from the client**
   - The chat shows a **Change Request** card. **Review & Update Quotation** opens the Quotation pop-up.
   - For a **Schedule** request, a **Schedule change requested** panel appears beside Approve & Send, with:
     - the current and requested schedule;
     - Raflora's availability that day (other bookings and material stock).

     Confirm with the client in Messages, then click **Accept New Schedule** (the event date and time change) or **Decline Request**. Either way, an optional note goes to the client in Messages.
   - Then send the revised quotation with **Approve & Send Official Quote**.
5. **Proposals**: upload design files under **Proposal & Presentation Versions** (PDF, Word, PowerPoint or images, up to 20 MB). The client is notified.
6. **Approval**: after the client accepts (*Quotation Accepted*), click **Final Approve & Enable Payment**. The status becomes *Admin Approved* and the client can pay.
7. **Payment** (Overview → *Submitted Payments*, or **Notifications**)
   - Check the reference against Raflora's GCash or bank records, then **Verify** (the booking is confirmed) or **Reject** (the client is asked for a corrected reference).
8. **Staff Assignment**: assign an operations staff member. This is needed from the Confirmed stage onward.
9. **Preparation & Reservation** (Preparation pop-up)
   1. Operational preparation and dispatch are locked until Admin verifies a down payment or full payment.
   2. **Schedule Preparation**: choose a date from today through at least 14 days before the event date. The valid range is calculated for each booking; past dates and dates within the final 14 days before the event are not allowed.
   3. **Reserve Materials** locks reusable materials as soon as payment is verified and a valid preparation date is saved. The scheduled date records when preparation is planned; it does not delay reservation or procurement. Fresh/perishable items are not placed on reusable-stock reservations.
   4. **Fresh Flower Procurement** appears for confirmed booking items classified for fresh procurement. Review item names, quantities and statuses before confirming. Admin can reclassify an incorrectly listed item as reusable inventory for this booking; the booking item remains, and the inventory catalog classification is unchanged.
10. **Dispatch**: after payment is verified and a valid preparation date is saved, enter the quantity per item and dispatch it. Admin or the assigned Staff can do this, and stock is deducted. Mistakes can be fixed with a dispatch correction.
11. **Event Execution**: in Overview → **Operational Actions**, click **Mark Event In Progress**. The system checks fresh-flower readiness, dispatch and locked stock first.
    - The Next-step shortcut changes to *Start Event · Mark In Progress* once everything is dispatched.
    - For Delivery orders the button reads **Mark Out for Delivery**.
12. **After the event**
    - Click **Mark Event Completed**. The booking moves to return reconciliation, or waits for the balance first.
    - **Log Final Payment** records the remaining balance. If the client sent a reference, check it, then log it or reject it.
13. **Material Return & Inventory Reconciliation**: click **Manage Return Audit** (section 3.8).
14. **Completion**: once the return audit is complete and nothing is owed, the booking is **Completed**.

**Delivery orders** (event type Delivery)
- A blue **Delivery order · No return audit** banner shows on the Overview.
- After **Mark Out for Delivery**, click **Mark Item Received** when the client confirms by message or call. The client can also confirm in their portal.
- If fully paid, the booking completes. Otherwise it completes when the balance is paid.
- There is no Mark Event Completed and no return audit, unless the order is cancelled after dispatch.

**Other booking actions**
- **Reject / Decline Booking**: available until the event starts.
- **Cancellation requests**: approve (the booking is cancelled, reserved stock is released, and a return record opens if items were already dispatched) or deny.
- **Client Meetings** (chat → Client Meetings): schedule a meeting, or confirm a client's requested meeting, then mark it completed or cancel it.

### 3.4 Notifications (`/admin/notifications`)

1. Alerts are grouped as Payments, Inventory, Meetings and System. Click an alert or its action button to open details.
2. **Resolve Inventory Shortages – Booking #N** (booking shortage alerts)
   1. The table shows each material's physical stock, stock held for other bookings, and the shortfall.
   2. Enter stock to add for catalog items. **Resolve & Restock** unlocks once every shortfall is covered.
   3. **Add Missing AI Items to Inventory** creates catalog items for lines that are not in inventory.
   4. **Request Substitution / Edit Booking** opens the booking.
   5. **Export CSV** (header) downloads this booking's shortage list.
   6. **Print Report** (header) opens the printable **Booking Shortage Report** with the Raflora logo. Use **Print / Save as PDF**.
   - Only shortage items are listed:
     - *To canvass* = not in inventory;
     - *To procure* = in inventory but short;
     - *High* priority = none available, or the event is within 7 days.
3. **Review Count**: Staff physical counts that differ from the system. **Approve** applies the stock adjustment; **Reject** leaves stock unchanged.
4. **Review Payment Verification**: opens the payment to verify.
5. **Low stock / Restock**: add stock to an item directly.
6. Other alerts (staff material requests, staff issue reports, client change requests, delivery confirmations, client messages) open as **System alert** details with a link to the booking. See issue I-2.
7. **Dismiss All** (top right) marks all alerts as read. Stock-count requests and payments stay until they are handled.

### 3.5 Gallery Management (`/admin/gallery`) (not exercised)

1. **Add** a gallery: title, event date (today or earlier), event type, theme and photos. Save it.
2. **Edit** a gallery to change its details or photos.
3. **Archive** hides a gallery from the public Gallery page. **Archived** lists archived galleries; **Restore** brings one back.

### 3.6 Package Management (`/admin/packages`) (not exercised)

1. **Create** a package: title, category, description, price, included items, linked inventory materials, images, and whether it is active (shown publicly). Save it.
2. **Edit**, **Archive** or **Restore** packages. **Delete** permanently, which is refused if past bookings use the package.
3. **CSV**: download templates, **Import** packages or package materials, and **Export** packages or materials.

### 3.7 Inventory Management (`/admin/inventory`)

1. **Add Item**: name, category, perishable (fresh) or not, stock, unit cost, minimum stock, unit and supplier details.
2. **Edit** an item. **Receive Stock** adds delivered stock. **Adjust Stock** corrects the count with a reason.
3. **Archive** an item. **Archived Items** lists them; **Restore** brings one back.
4. **CSV**: **Download Template**, **Import Inventory CSV** (adds or updates in bulk), and **Export all inventory items**.
5. Catalog matching recognizes only defined quantity/form variants (for example "Floral Foam Brick" and "Floral Foam Blocks"). Distinct foam shapes and composite products remain separate. Unit mismatches are flagged for manual review; do not merge stock records until their units and perishability are verified.

### 3.8 Return Tracking (`/admin/return-tracking`)

1. The list shows return records with filters for status, staff, inspector, approval and event date.
2. From a booking, **Manage Return Audit** opens the audit. If none exists yet, **Start return audit**.
3. For each dispatched item, record the quantities returned **good**, **damaged** or **lost**, the condition, and any damage charge. Save.
4. **Assign** the responsible staff member and the inspector.
5. If every item came back good, the audit is **Completed**. If anything is damaged or lost, the audit needs a decision: **Approve** (the charges are added to what the client owes) or **Reject**.
6. A booking with no reusable materials dispatched can close an empty audit.

### 3.9 Client Records (`/admin/client-records`)

1. Lists clients with contact details, booking activity and last activity.
2. Open a client to see their bookings.

### 3.10 Reports & Analytics (`/admin/reports`)

1. Shows the reporting period (the current month):
   - new and confirmed bookings, verified revenue, average booking value, quoted pipeline value and low-stock count;
   - a six-month revenue and booking trend, booking status distribution, top requested materials, low stock items and recent activity.
2. Click **Export Report (CSV)** (top right) to download the same figures.

### 3.11 Quotations (`/admin/quotations`) and AI Material Plan (`/admin/ai-analysis`)

These pages are reached from the Dashboard **Shortcuts**.
1. **Quotations**: pending quotations with version, estimate, status and validity. Filter, then click **Review quotation**.
2. **AI Material Plan**: recent AI analyses of inspiration photos.

### 3.12 Account Management (`/admin/settings`)

1. **Profile Information**: update your name and contact details. **Business Information** is display-only.
2. **Change Password**: enter the current password, then the new one twice.
3. **Email Security**: change your admin email (with a code).
4. **Trusted Devices**: revoke devices that skip the sign-in code. **Active Sessions**: sign out all other sessions.
5. **Team Accounts**: **Add Account** creates a **Staff** account. Only Staff accounts can be created here.
6. **Business Configuration** (each change requires explicit confirmation and a reason):
   - downpayment percentage;
   - long-term booking threshold;
   - price reconfirmation threshold.
7. **AI Estimate Pricing**: the markup and labour/setup values used for clients' indicative AI price ranges.
8. **Audit Trail**: recent recorded actions.
9. **System Data Management** (not exercised): **Export** business data, **Preview → Import** a data file, or download a **demo** dataset.

---

## 4. Staff Portal

The sidebar shows: **Dashboard**, **My Tasks**, **Event Assignments**, **Work Orders**, **Inventory Requests**, **Dispatch**, **Returns**, **Event Checklist**, **Messages**, **Activity Logs**, **Event Calendar** and **Resource Guide**. Staff only see bookings assigned to them.

### 4.1 Dashboard (`/staff/dashboard`)

1. **Current Event**, **Today's Schedule** and **Today's Tasks**.
2. Click **View Full Details** to open the event, or **View Calendar**.

### 4.2 My Tasks, Event Assignments, Work Orders, Event Calendar

1. **My Tasks**: preparation checklist, dispatch, return and inspection tasks. Filter by view and status (pending, in progress, completed).
2. **Event Assignments**: active assigned events (filter by event type) and **Past & Completed Events**.
3. **Work Orders**: the materials list for each active event.
4. **Event Calendar**: assigned events by date.

### 4.3 Event page (`/staff/events/{id}`)

1. **Event information** and **Venue location** (map).
2. **Event preparation checklist**
   1. Tick an item, optionally add an operational note, and click **Save**.
   2. Every assigned event gets the standard checklist automatically.
3. **Confirmed materials & Dispatch**
   1. Enter the quantity to dispatch per item, add a reason if needed, and dispatch.
   2. Stock is deducted and the booking moves to the Dispatch stage.
   3. Only **Admin** can then start the event (Mark Event In Progress).
4. **Physical stock recording**: enter the quantity you counted and click **Submit Count**. Admin approves or rejects the adjustment (section 3.4, item 3).
5. **Material Return (Physical Count)**
   1. After the event, record the good, damaged and lost quantities per item, with submission notes.
   2. **Condition Observation & Evidence** records the condition, with photo evidence.
   3. Admin completes the return audit.
6. **Event Discussion**: messages with Admin (internal; the client cannot see them).

### 4.4 Dispatch, Returns, Event Checklist (lists)

1. **Dispatch**: every assigned event's materials still to dispatch.
2. **Returns**: return and inspection work after events.
3. **Event Checklist**: checklist progress across events.

### 4.5 Inventory Requests (`/staff/requests`)

1. **Request Materials from Inventory**: choose the event, item and quantity, add a note, and submit. Admin receives an alert.
2. **Report Operational Issue**: choose the event and issue category, describe the issue, and submit. Admin receives an alert.
3. **Submitted Requests & Alerts History** lists what you sent.

### 4.6 Messages, Activity Logs, Resource Guide, Search

1. **Messages**: conversations with Admin about your events. The badge shows unread messages.
2. **Activity Logs**: your recorded actions by day.
3. **Resource Guide**: the 14-stage booking workflow explained.
4. **Search**: find your events and tasks.

---

## 5. Booking status guide

| Status (what users see) | Meaning | Who acts next |
|---|---|---|
| Pending | Request received; Raflora is reviewing and validating materials | Admin |
| Quotation Sent | Official quotation issued (valid 7 days) | Client: accept, request changes or cancel |
| Change Requested | Client asked for changes | Admin: revise and re-send |
| Quotation Accepted | Client accepted | Admin: final approval |
| Admin Approved | Payment enabled | Client: pay and submit reference |
| Payment Submitted / Payment Pending | Reference awaiting verification | Admin: verify or reject |
| Downpayment Received / Confirmed | Booking confirmed | Admin and Staff: prepare, reserve, dispatch |
| Event In Progress (*Out for Delivery*) | Event running or delivery on the way | Admin: mark completed (*client or Admin: item received*) |
| Event Completed | Event over | Admin: final payment and return audit |
| Pending Return | Waiting for returned materials | Staff and Admin: return audit |
| Pending Resolution | Damage or loss charges to decide | Admin |
| Completed | All done | — |
| Cancellation Requested | Client asked to cancel | Admin: approve or deny |
| Cancelled / Declined | Stopped | — |

---

## 6. Inspection checklist

Key:
- **Code** = routes, controller, view and business logic read for this manual.
- **Tested** = covered by automated feature tests that passed during recent work on this system.
- **Browser** = also checked in a headless browser during that work.

| Area | Page / workflow | Code | Tested | Browser |
|---|---|:-:|:-:|:-:|
| Public | Home, Gallery, Packages, About, Book Event routing | ✔ | partial | — |
| Auth | Register, login, email code, device code, Google (clients only), forgot/reset, admin OTP reset, admin setup and recovery | ✔ | ✔ | — |
| Guest | 4-step booking form incl. Delivery hiding guests/tables | ✔ | ✔ | ✔ |
| Guest | Request status page, 24-hour expiry, live updates | ✔ | ✔ | ✔ |
| Guest | Claim with matching, verified email | ✔ | ✔ | — |
| Client | Dashboard, My Bookings, History, Notifications | ✔ | ✔ | — |
| Client | 3-step booking form | ✔ | ✔ | ✔ |
| Client | Booking page: accept, request changes (material/schedule/other), cancellation | ✔ | ✔ | ✔ |
| Client | Payment reference, remaining balance | ✔ | ✔ | — |
| Client | Floating chat (Messages / Meetings), meeting requests | ✔ | ✔ | ✔ |
| Client | Delivery "Item Received" | ✔ | ✔ | — |
| Client | Account settings incl. email re-verification | ✔ | partial | — |
| Admin | Dashboard (no search / New Booking) | ✔ | ✔ | — |
| Admin | Booking list: tabs, filters, name and number search | ✔ | ✔ | — |
| Admin | Booking page pop-ups, tab colours, Next-step shortcut, chat panel | ✔ | ✔ | ✔ |
| Admin | Review, material confirm / confirm all / link / promote | ✔ | ✔ | ✔ |
| Admin | Quotation send, re-issue, price reconfirmation, final approval | ✔ | ✔ | — |
| Admin | Schedule change accept / decline | ✔ | ✔ | ✔ |
| Admin | Payment verify / reject, final payment | ✔ | ✔ | — |
| Admin | Preparation, reserve, fresh flowers, dispatch, Mark In Progress / Completed | ✔ | ✔ | ✔ |
| Admin | Delivery orders (out for delivery, item received, no return audit) | ✔ | ✔ | — |
| Admin | Return Tracking (start, record, assign, approve) | ✔ | ✔ | — |
| Admin | Notifications incl. shortage CSV / printable report | ✔ | ✔ | ✔ |
| Admin | Gallery, Package management (incl. CSV import/export) | ✔ | partial | — |
| Admin | Inventory management (incl. CSV import/export) | ✔ | ✔ | — |
| Admin | Client Records, Reports (incl. CSV export), Quotations, AI Material Plan | ✔ | ✔ | — |
| Admin | Account Management: profile, password, devices, sessions, staff accounts, configuration, audit trail | ✔ | partial | — |
| Admin | System Data export / import / demo | ✔ | partial | — |
| Staff | Dashboard, Tasks, Assignments, Work Orders, Calendar, Guide, Search, Activity | ✔ | ✔ | — |
| Staff | Event page: checklist, dispatch, physical count, return count and evidence, discussion | ✔ | ✔ | — |
| Staff | Inventory requests, issue reports | ✔ | ✔ | — |

---

## 7. Unresolved issues requiring verification

| # | Issue | Evidence | Suggested check |
|---|---|---|---|
| I-1 | **Clients are not told where to pay.** The payment form asks for a GCash or bank reference, but no GCash number or bank account details appear anywhere in the system. | No view contains payment account details; *Business Information* holds only company, email and phone. | Confirm how clients receive payment details (chat, email, offline). Consider showing them on the payment step. |
| I-2 | **Several alerts have no dedicated action.** Staff material requests, staff issue reports, client change requests, delivery confirmations and client messages appear as generic "System alert" cards (View Details / mark read). Admin must act manually elsewhere. | `admin/notifications.blade.php` handles only shortage, low stock, payment, physical count and meeting types specially. | Confirm this is acceptable, especially for Staff material requests. |
| I-3 | **Email-dependent steps not tested end to end:** email verification codes, device codes, admin OTP/recovery, the guest claim email, and client notification emails. | All rely on the configured mail service (Brevo); tests use captured mail. | Send a real test of each email in the deployed environment. |
| I-4 | **AI photo analysis needs a live Gemini key.** Tests block real AI calls, so the analysis quality and quota handling were not tried live. | `GeminiVisionService`; quota errors show a friendly message. | Upload real photos with the production key. |
| I-5 | **Legacy guest booking path.** Older guest bookings stored as full bookings with a guest token use `/guest/booking/analysis/{token}`. The alias `/guest/booking/{token}` is not linked anywhere, and the guest accept/payment routes always refuse (no page uses them). | Routes `guest.booking.analysis`, `guest.booking.show`, `guest.bookings.accept`, `guest.bookings.payment.reference`. | Check whether any such legacy bookings still exist. If not, these routes could be retired. |
| I-6 | **Unused page `admin/users.blade.php`** ("Users Management"). Its route redirects to Account Management, so it is never shown. | `routes/web.php` (`admin.users` redirect). | Remove, or link if it is still wanted. |
| I-7 | **Only Staff accounts can be created in Account Management.** Additional Admin accounts are not possible from the UI. | `AdminSettingsController@storeAccount` accepts role `staff` only. | Confirm this is intended. |
| I-8 | **Gallery, Package and System Data import/export were not exercised in a browser.** | Read in code only for this manual. | Try each once with sample files. |
| I-9 | **Remaining balance cannot be paid while the return audit is in progress** (*Pending Return*). | `BookingController@submitPaymentReference`. | Confirm this business rule with Raflora. |
| I-10 | **Developer-only routes:** `/mock/*` (UI previews) and `/test-gemini` (live AI test) exist but return 404 outside the `local` environment. The `scratch/` folder holds developer scripts that the app never loads. | `routes/web.php` environment checks. | Make sure production runs with `APP_ENV=production`. |
| I-11 | **Booking #53** had two material lines manually unlinked during testing (wrong automatic matches). | Audit log entries `material_unlinked`. | Re-check that booking's materials before using it as a reference. |
