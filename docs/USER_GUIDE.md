# User Guide

Everything a salesperson, a support agent or a manager needs to work in the CRM.
Anything about *configuring* it — users, roles, settings, integrations — is in
the [Administrator Guide](ADMIN_GUIDE.md).

Screens you cannot see are not missing: the sidebar only shows the modules your
role has permission to view, so two people on the same installation see
different menus.

---

## 1. Signing in

1. Open the address your administrator gave you.
2. Enter your email address and password and press **Sign in**.
3. If you have two-factor authentication switched on, enter the six-digit code
   from your authenticator app, or one of your recovery codes.

**Forgotten your password?** Use *Forgot password?* on the sign-in page. A reset
link is emailed to you. If nothing arrives, ask your administrator whether email
is configured — on a fresh installation it may not be.

**Invited by email?** The invitation link takes you to a page where you set your
own name and password. It works once and expires; ask for another if it has.

### Your profile

`/profile` — from the avatar menu at the top right.

- Change your name, phone and avatar.
- Change your password. Doing so signs out your other devices.
- Turn on **two-factor authentication**: scan the QR code, confirm with a code,
  then save the recovery codes somewhere safe. They are shown once.

---

## 2. Finding your way around

The sidebar lists the modules: Dashboard, Leads, Contacts, Accounts, Deals,
Activities, Calendar, Products, Quotes, Support, Knowledge, Reports and
Automation, plus any custom modules your administrator has added.

The bar across the top carries the global search, the notification bell, the
light/dark toggle and your avatar menu.

### The dashboard

`/` — your own numbers, not the company's: open deals you own, activities due
today, leads waiting on you, and the pipeline you are working. Tiles link
through to the list they summarise.

---

## 3. Every list works the same way

Leads, Contacts, Accounts, Deals, Activities, Products, Quotes and Tickets all
share one list screen, so learning it once is enough.

| Control | What it does |
| --- | --- |
| Search box | Matches the fields that identify a record — name, company, email, reference. |
| **Filters** | Builds a condition per field (*is*, *is not*, *contains*, *is between*, *is empty*…). Conditions combine with AND/OR. |
| Filter chips | Each active condition appears as a chip. Click the × to drop just that one. |
| **Columns** | Choose which columns show, and drag them into the order you want. |
| Sort | Click a column header. Click again to reverse it. |
| **Saved views** | Save the current filters, columns and sort under a name, and switch between them. Views can be private or shared with everyone. |
| View mode | Table, cards, and — on Deals — the kanban board. |
| **Export** | CSV or Excel of *what you are looking at*, filters and columns included. Large exports are prepared in the background and arrive as a notification. |
| Tick boxes | Select rows for a bulk action: reassign the owner, change status, delete. |

Everything is paginated. Changing page keeps your filters.

### Duplicates

When you type an email address, phone number or company name that the system has
seen before, a warning appears above the form with the records it matched. You
can open the match instead of creating a second one.

If two records already exist, open one and choose **Merge** — pick which value
wins field by field; the losing record is kept as a redirect so its history and
links survive.

---

## 4. Leads

`/leads` — people who have shown interest but are not yet customers.

**Creating one:** *New lead*, or let one arrive by itself from a public capture
form, an email, or the website chat widget.

**Working one:** open the lead and use the timeline on the right to log a call,
add a note, attach a document, or schedule a follow-up. The status moves through
*New → Contacted → Nurturing → Qualified*, or to *Unqualified* when it goes
nowhere. A **score** is calculated from the rules your administrator has set —
the higher the number, the better the lead matches what usually closes.

**Converting one:** `/leads/{lead}/convert` (the **Convert** button). One step
creates:

- an **Account** — the organisation,
- a **Contact** — the person,
- optionally a **Deal** — the opportunity, with a value and an expected close
  date.

The lead stays, marked *Converted*, linked to everything it became. Conversion
is not reversible, so check the account matching first: if the company is
already on the system, pick the existing account rather than creating a second.

---

## 5. Contacts and accounts

`/contacts` — people. `/accounts` — the organisations they work for.

- An account can have many contacts; one of them is the **primary** contact.
- Accounts can be nested: a subsidiary sits under its parent, and the parent's
  page lists everything beneath it.
- Each record's page carries a **timeline**: notes, emails, calls, meetings,
  documents, field changes — in one thread, newest first.
- Deals, quotes, tickets and activities that point at the record are listed on
  it, so the account page is the whole relationship on one screen.

---

## 6. Deals

`/deals` — the opportunities you are working.

**The board.** Switch the view to kanban and drag a deal between stages. The
column headers carry the count and the total value. If your company runs more
than one pipeline, pick which one the board is showing.

**A deal's fields.** Value, expected close date, stage, owner, the account and
contact it belongs to, and the products on it.

**Closing one.** Mark it *Won* or *Lost* and record the reason. The reason is
what makes the win/loss report worth reading, so pick the honest one.

**Stage history.** Every move is stamped, which is where the "how long do deals
sit in Proposal?" answer comes from. You cannot edit it.

---

## 7. Activities and the calendar

`/activities` — tasks, calls and meetings. `/calendar` — the same work laid out
by day, week or month.

- An activity can be attached to a lead, contact, account, deal or ticket; it
  then appears on that record's timeline.
- Set a **reminder** and you are notified the chosen number of minutes before it
  is due.
- Set a **recurrence** (daily, weekly, monthly) and future occurrences are
  created for you.
- Mark one **Completed** when it is done. Overdue work is flagged on the
  dashboard and in the list.

Times are shown in the company's timezone, which may not be the one your
computer is set to.

---

## 8. Products and quotes

`/products` — the catalogue: products, services and bundles, with list prices,
cost prices and units.

`/quotes` — what you send the customer.

1. **New quote**, choose the account and contact.
2. Add lines from the catalogue. Quantity, discount and tax are per line; a
   price book can set different prices for a particular customer segment.
3. Choose whether tax is **inclusive** or **exclusive** — it changes what the
   totals mean, not just how they are displayed.
4. Save it as a draft, then **send** it.
5. `/quotes/{quote}/pdf` is the PDF the customer receives, rendered from the
   stored figures rather than recalculated, so an old quote always prints what
   was quoted.

A sent quote cannot be edited. Raise a **new version** instead — versions are
numbered and kept together, so you can see what changed between v1 and v2. An
accepted quote can be turned into an order and then an invoice.

Quotes lapse automatically once their validity date has passed.

---

## 9. Support

`/tickets` — cases raised by customers.

- A ticket carries a status (*New, Open, Pending, On hold, Resolved, Closed*), a
  priority, the contact and account it belongs to, and its owner.
- The conversation thread is the ticket: replies to the customer and internal
  notes sit in the same place, marked differently.
- **Watchers** are people kept informed without owning it.
- If an SLA policy applies, the target times for first response and resolution
  are shown on the ticket and counted down. A breach is recorded, not hidden.
- `/tickets/analytics` — volume, resolution times and SLA attainment.

`/knowledge` — the knowledge base. Search it before answering; link an article
into a reply rather than retyping it. Articles are grouped into sections, and
published articles are the ones customers can be pointed at.

---

## 9a. Campaigns

`/campaigns` — what marketing costs, and what it brought in.

A campaign is any piece of marketing the company spends money on: a Facebook
campaign, an email send, an exhibition stand, a referral scheme. Each carries a
type, a status, the dates it runs between, a budget and what has actually been
spent.

Attribute a lead, a contact or a deal to a campaign and its page adds up what
followed: how many leads came in, how many became deals, how many were won, the
revenue behind them, the cost per lead and the return.

Two things worth knowing:

- **Every figure is worked out from the records, never stored.** Open the page
  again after a deal moves and the numbers have moved with it.
- **You see your own slice.** The totals respect your access level, so two
  people can legitimately see different numbers on the same campaign.

A campaign that has overspent shows its budget figure in red, and the **Over
budget** chip on the list finds them all.

---

## 9b. The chat inbox

`/inbox` — WhatsApp and Facebook Messenger, in one place.

The sidebar offers them as two entries, **WhatsApp chat** and **Messenger
chat**, which open the same screen filtered to that channel. Three columns:
the conversations, the one you are reading, and what to do about it.

### The reply window is Meta's, not ours

This is the thing to understand before anything else. Meta only lets you reply
freely for a while after the customer last wrote:

| Channel | You may reply freely for | After that |
| --- | --- | --- |
| WhatsApp | 24 hours | only a template Meta has approved |
| Messenger | 7 days | nothing until they write again |

The thread says how long is left. When the window has closed the reply box is
replaced by the reason and, on WhatsApp, by the approved templates you may send
instead. The clock runs from **their** message — answering does not extend it.

### What happens on its own

- A message from somebody the CRM does not recognise **creates a lead**, so
  nobody has to remember to. On WhatsApp, a number already on a contact or a
  lead joins that record instead of becoming a second one.
- A conversation that came from a click-to-message advertisement **keeps which
  advertisement**, and the panel says so. That is what lets the campaign be
  credited when the deal is won.
- Attachments are fetched and kept; the thread shows them.

### What you do

- **Take it** puts your name on the conversation so two people do not answer at
  once. **Close** ends it — a new message from the customer reopens it.
- **Create a lead** if the conversation was matched to nobody and you want one.
- Add a note or a task against the record without leaving the screen.
- From a lead or a contact, **Message on WhatsApp** starts a thread with their
  number, or opens the one that already exists. Meta opens no window when *we*
  write first, so that conversation begins with a template.

---

## 10. Reports

`/reports` — saved reports anyone with permission can run.

- **New report** picks a source (leads, deals, activities, tickets…), the
  columns, the filters, the grouping and the chart.
- `/reports/dashboard` — the KPI dashboard, built from report widgets.
- `/reports/forecast` — the sales forecast: open pipeline weighted by each
  stage's probability, against closed business.
- `/reports/scheduled` — have a report emailed to a list of people daily,
  weekly or monthly. The schedule runs on the hour.

Every report respects your access level, so two people running the same report
can legitimately see different numbers.

---

## 11. Importing and exporting

**Import:** `/import/leads`, `/import/contacts`, `/import/accounts` (and the
other modules that support it).

1. Upload a CSV or Excel file.
2. Map each column in your file to a field in the CRM. The mapping is
   remembered.
3. Choose what to do about duplicates: skip them, or update the existing record.
4. Run it. Large files are processed in the background; you are notified when it
   finishes, with a per-row report of anything rejected and why.

**Export:** the **Export** button on any list. What you are looking at is what
you get.

---

## 12. Notifications

The bell in the top bar carries anything addressed to you: a record assigned to
you, an activity reminder, an approval waiting, an SLA about to breach, an
import or export that finished.

Where each kind of notification reaches you — in the app, by email, or not at
all — is set per user against the categories your administrator has enabled.
Quiet hours are respected, so a reminder that falls in the middle of the night
arrives in the morning.

---

## 13. Automation

`/workflows` — rules your administrator has set up: "when a deal reaches
Negotiation, create a task for the owner", "when a lead scores above 80, notify
the sales manager".

`/approvals` — things waiting for *you* to approve, such as a discount beyond
your authority. Approve or reject with a comment; the workflow continues either
way.

You do not need permission to manage workflows to be an approver — the approvals
screen shows you only what has been asked of you.

---

## 14. Getting help

This guide and the administrator one are on your CRM at `/guide`, readable
without signing in — so you can send the link to somebody who has not been given
an account yet.

- Your administrator can see the audit trail, so "who changed this?" is an
  answerable question.
- Nothing you delete from a list is destroyed immediately — most records are
  soft-deleted and can be restored by an administrator.
- If a screen says you are not permitted to do something, that is a role
  setting, not a bug. Ask your administrator.
