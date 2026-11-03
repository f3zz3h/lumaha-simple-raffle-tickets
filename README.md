# Lumaha Simple Raffle Tickets

A small WooCommerce plugin for selling paper raffle tickets online. When someone buys a book of tickets or a single ticket, they type the number printed on it and the plugin fills in the rest of the book's numbers. The numbers are saved on the order and shown in the basket, on the order screen and in the emails, so the committee knows which stubs belong to whom. First built for [Horfield SA](https://www.horfieldsa.co.uk).

- **Requires:** WordPress 6.0+, PHP 7.4+, WooCommerce
- **Slug:** `lumaha-simple-raffle-tickets` (code prefix `lsrt`)
- **Licence:** GPL-2.0-or-later

---

## For site admins

### 1. Install

1. Download `lumaha-simple-raffle-tickets-<version>.zip` from the [Releases](https://github.com/f3zz3h/lumaha-simple-raffle-tickets/releases) page on GitHub.
2. In WordPress, go to **Plugins → Add New Plugin → Upload Plugin**, choose the zip, then **Install Now** and **Activate**.
3. WooCommerce must be active. The plugin does nothing without it.

There is no settings page. The plugin works on any product you put in the raffle category or tag (see below).

**Updating:** from version 0.3.0, new GitHub releases show up as normal plugin updates (Dashboard → Updates and the Plugins screen, with **View details** showing the release notes), and **Enable auto-updates** on the Plugins screen installs them by itself. WordPress checks about twice a day. On 0.2.1 or earlier, upload the newer zip the same way as installing; WordPress offers **Replace current with uploaded**. Either way, ticket numbers on existing orders and the sold-number lists are kept.

### 2. Set up a raffle product

The plugin switches on for any product that is:

- in a category with the slug `raffle-tickets` or `raffle` (for example **Raffle tickets**), or any sub-category of it, or
- tagged `raffle`.

There's nothing else to turn on. **Duplicate** last year's product for a new raffle and it works straight away; the copy starts with an empty list of sold numbers.

**Books and singles.** Use a variable product with a variation for each way you sell tickets, for example **Book of 5** and **Single**. The plugin works out how many tickets each variation is:

1. **Tickets per book** on the variation (under **Variations**, shown only on raffle products), if filled in.
2. Otherwise the variation's attribute value: "Book of 5", "Book 10", "5 tickets" or "5 x tickets" give that number, and a plain "Book" counts as 5.
3. Anything else is a single ticket.

A simple (non-variable) product in the raffle category sells single tickets.

### 3. What buyers see

- On the product page, one number box for each book or ticket, matching the quantity they choose.
- For a book, they type the first number and the rest are listed underneath: 101 gives 101, 102, 103, 104, 105.
- On shop and category pages, the button reads **Enter ticket numbers** and goes to the product page, since the numbers can't be typed there.
- Numbers must be whole numbers. A number entered twice, whether in the same add or already in the basket for that product, is refused with a message, and the boxes keep what the buyer typed.
- Quantity is locked in the basket (classic and block baskets), so the numbers always match what was bought. To change it, remove the line and add it again.

### 4. Orders

- Each order line gets a **Ticket numbers** entry (for example `101–105, 230`). It shows on the order screen, in the customer's emails and in the admin emails.
- When an order becomes **Processing** or **Completed**, its numbers are added to that product's sold list. If a number was already paid for on another order, the plugin adds an order note: *Raffle: these ticket numbers were already paid for on another order: 103 (order #1234). Please check with the buyer.* The order still goes through.
- When an order is **Cancelled**, **Refunded** or **Failed**, its numbers are taken off the sold list again.

---

## For developers

### Layout

```
lumaha-simple-raffle-tickets.php   The whole plugin: class Lumaha_Simple_Raffle_Tickets
assets/
  raffle.js                        Product page: one box per book/ticket, shows the filled-in numbers
  raffle.css                       Styles for the ticket boxes
```

### Data

| Key | Stored on | Holds |
|---|---|---|
| `_lsrt_book_size` | variation (post meta) | Tickets per book set by the admin |
| `_lsrt_sold` | product (post meta) | Paid-for numbers: `number => order id`. Left out when a product is duplicated |
| `_lsrt_numbers` | order item meta | Every number on the line, comma separated |
| `Ticket numbers` | order item meta | The readable ranges shown to people |
| `_lsrt_recorded` | order meta | Set once the order's numbers are on the sold list |
| `lsrt` | cart item data | `size` and `starts` (first number of each book) |

The plugin declares compatibility with WooCommerce's High-Performance Order Storage. It doesn't create tables, and deleting it leaves its data in place.

### Hooks

| Hook | Type | What it does |
|---|---|---|
| `lsrt_category_slugs` | filter | Category slugs that mark a raffle product (default `raffle-tickets`, `raffle`; sub-categories count too) |
| `lsrt_tag_slugs` | filter | Tag slugs that mark a raffle product (default `raffle`) |
| `lsrt_is_raffle_product` | filter | Final say on whether a product is a raffle (`$is`, `$product`) |
| `lsrt_default_book_size` | filter | Tickets in a plain "Book" (default 5; `$size`, `$variation`) |

### Releasing a version

1. On a branch, bump `Version:` in the plugin header and `VERSION` in `Lumaha_Simple_Raffle_Tickets`, and update `Stable tag` and the changelog in `readme.txt`. The zip build fails if `Stable tag` doesn't match the plugin version. Merge it.
2. Tag `main` and push the tag: `git tag v0.3.0 && git push origin v0.3.0`.
3. The **Release** workflow checks the tag matches the plugin version, builds the zip and publishes a GitHub release with `lumaha-simple-raffle-tickets-<version>.zip` attached.
4. Sites on 0.3.0 or later are offered the update within about 12 hours (the plugin header's `Update URI` sends WordPress's update check to the plugin's GitHub updater, which reads the latest release, cached for 12 hours). A release must stay non-draft, non-prerelease and keep its zip, or it isn't offered.

Build a zip locally with `bash .github/scripts/build-zip.sh` (output in `dist/`). `bash .github/scripts/build-zip.sh --wporg` builds the WordPress.org copy, without the GitHub updater and the `Update URI` header, as WordPress.org requires.

### WordPress.org readme

`readme.txt` is the plugin's WordPress.org-style listing (description, FAQ, changelog). Keep `Stable tag` and the changelog in step with the version when releasing.

### Testing locally

Any WordPress with WooCommerce will do (for example [wp-env](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/) or the official Docker images). Copy the plugin folder to `wp-content/plugins/lumaha-simple-raffle-tickets/`, activate it, make a **Raffle tickets** category and a variable product in it with **Book of 5** and **Single** variations, then buy from the product page.

---

## Known gaps

- No settings page: the category, tag and default book size can only be changed with the filters above.
- No translation files yet (strings are ready, text domain `lumaha-simple-raffle-tickets`).
- Numbers aren't checked against a range, so a typo like 10001 is accepted.
- The sold-number check only adds an order note; it doesn't stop the sale.
- Deleting the plugin leaves its order and product data behind.
