=== Lumaha Simple Raffle Tickets ===
Contributors: f3zz3h
Donate link: https://buymeacoffee.com/lumaha
Tags: raffle, tickets, woocommerce, fundraising, charity
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 0.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sell paper raffle tickets through WooCommerce. Buyers enter the first number of each book, the rest fill in, and the numbers are saved on the order.

== Description ==

Made for clubs, schools and charities that sell printed raffle books and want to take orders online. Buyers pick a book or a single ticket, type the number printed on it, and the plugin fills in the rest of the book. The numbers are saved to the order, so the committee knows which stubs belong to whom.

= For buyers =

* One number box for each book or ticket they buy
* Type the first number of a book and the rest fill in: 101 gives 101 to 105
* Ticket numbers shown in the basket, on the order and in the order emails

= For organisers =

* No settings: works on any product in a "Raffle tickets" category (or a sub-category of it) or tagged "raffle"
* Duplicate last year's product for a new raffle and it just works
* Reads variations such as "Book of 5", "Book" (5 tickets) and "Single", or set "Tickets per book" on each variation
* Quantity locked in the basket, so the numbers always match what was bought
* The same number twice in one basket is refused
* A number already paid for on another order adds an order note for you to check
* Numbers on cancelled, refunded or failed orders are freed up again
* **WooCommerce → Raffle tickets** lists every ticket sold online in number order, by year and raffle, with buyer, order and payment, to tally against the paper stubs. Print it or download a CSV. Numbers sold twice are flagged
* Works with the block and classic basket, and with High-Performance Order Storage

== Installation ==

1. Install and activate WooCommerce.
2. In **Plugins → Add New Plugin**, upload the plugin zip, then activate **Lumaha Simple Raffle Tickets**.
3. Create a category called **Raffle tickets** (slug `raffle-tickets`), or tag products **raffle**.
4. Add a variable product to it with variations such as **Book of 5** and **Single**.

== Frequently Asked Questions ==

= How many tickets is a "Book"? =

A plain "Book" counts as 5. "Book of 10", "Book 10" or "10 tickets" count as 10. To set it exactly, fill in "Tickets per book" on the variation.

= What happens if someone enters a number that has already been sold? =

The order goes through, and once it's paid the plugin adds an order note naming the number and the other order, so you can check with the buyer. The same number twice in one basket is refused straight away.

= Why can't buyers change the quantity in the basket? =

Each number box belongs to one book or ticket, so the quantity is locked to keep them matching. To buy more, add the product again from its page.

= Can I sell single tickets from a simple product? =

Yes. A simple product in the raffle category sells single tickets, one number each.

= Will next year's raffle remember this year's numbers? =

No. Duplicating a product copies its settings but starts with an empty sold list.

= Does it send data anywhere? =

No. The plugin uses no external services.

== Changelog ==

= 0.4.0 =
* New: **WooCommerce → Raffle tickets**, for administrators and shop managers. Every ticket number sold online, in number order, one tab per year and one list per raffle, with the buyer, order, payment method and status. Print it or download a CSV to tally against the paper stubs. Unpaid orders and numbers sold on two orders are highlighted.

= 0.3.1 =
* Fixed: on phones, the ticket number boxes no longer push the add to basket button off the right of the screen.

= 0.3.0 =
* New: updates from GitHub releases show up as normal plugin updates (Dashboard → Updates and the Plugins screen), with auto-updates, for copies installed from the GitHub release zip.
* Fixed: the ticket boxes keep what the buyer typed after a refused add to basket on themes that show the error above the product (most themes).

= 0.2.1 =
* First public release, moved out of the Horfield SA website into its own repository.
* Fixed: after a refused add to basket (for example a repeated number), the ticket boxes keep what the buyer typed instead of coming back empty.

= 0.1.1 =
* First version, built for and run on the Horfield SA website.

== Upgrade Notice ==

= 0.4.0 =
Adds WooCommerce → Raffle tickets, a list of every ticket sold for tallying the paper stubs. No data changes.

= 0.3.1 =
Add to basket button stays on screen on phones. No data changes.

= 0.3.0 =
Ticket numbers stay in the boxes after a basket error on all themes. No data changes.

= 0.2.1 =
Ticket boxes no longer empty themselves after an error. No data changes.
