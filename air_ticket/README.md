# Dummy Air Ticket

Flight search and itinerary PDF module, brought in from the `biz-flight`
(Explore Flights) Node.js app and ported to run inside You2-Biz on PHP. No Node.js is needed.

## Access
- Super Admin → Subscription → company row → **Company Settings → Dummy Air Ticket → On/Off**.
- When it is On, the company **administrator** sees **Dummy Air Ticket** in the sidebar.
  Staff accounts and Super Admin cannot open it.
- The setting is stored in `users.air_ticket_enabled` (created automatically, default `0`).

## Files
- `index.php` – page inside the You2-Biz layout (shows the app in an iframe).
- `app/index.php` – the flight app page (was `biz-flight/index.html`).
- `app/*.js`, `app/styles.css`, `app/assets/` – copied from `biz-flight`, then changed for You2-Biz:
  - six server URLs (`/api/...`, `/assets/airlines/...`) use `window.AIR_TICKET`;
  - only the booking flow is shown (search → results → travellers → review/PDF), without the
    public site's header, footer and marketing pages;
  - branded **biz-flights** (logo, itinerary, PDF file name, `BF-` references); developer credit removed;
  - passport scan: the photo is straightened first (projection deskew), the MRZ is located
    anywhere in the photo, `P<` / filler misreads and one passport-number letter/digit swap are
    corrected (only when the check digits confirm it), and a name ending in look-alike letters is
    checked against the printed "Surname / Given Name" on the page.
- Tests: `C:\xampp\php\php.exe tests/air_ticket_api_test.php` and
  `node --test tests/air_ticket_passport_ocr_test.js`
- `api.php` – PHP version of `biz-flight/server.js` (search, more results, offer recheck,
  airline logo, health, config). Pure helpers: `includes/air_ticket_api_helper.php`.

## Live flight search (optional)
Without a token the module runs in **demo mode**: sample flights, itinerary and PDF all work.

For live Duffel offers, Super Admin → **Settings** → card **"Dummy Air Ticket – Duffel API"**:
paste the token, Save (it is tested against Duffel right away), or use **Test Duffel Connection**.
It is stored in `system_settings` (`air_ticket_duffel_token`, optional `air_ticket_airhex_key`)
and is never sent to the browser.

If nothing is saved there, the server can instead provide it through:
- environment variable `DUFFEL_ACCESS_TOKEN` (optional `AIRHEX_API_KEY`), or
- `includes/air_ticket_config.local.php` (git-ignored):

```php
<?php
const AIR_TICKET_DUFFEL_TOKEN = 'duffel_live_or_test_token';
// const AIR_TICKET_AIRHEX_KEY = '';
```

Never put the token in JavaScript or commit it. Itineraries are not airline bookings.
