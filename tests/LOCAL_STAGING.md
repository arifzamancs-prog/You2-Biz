# Local E-shop staging

Run `C:\xampp\php\php.exe tests/create_local_staging.php` from the project root.
It creates a uniquely named database with schema only and synthetic company,
product, stock and wallet records. It prints the database and test login.
Do not import customer records or SMTP credentials.

In a dedicated PowerShell terminal, use the exact printed database name:

```powershell
.\tests\start_local_staging.ps1 -Database 'the printed database name'
```

Bind only to loopback. The disabled transports prevent outbound PHP email/network
requests. Missing external images or failed notification attempts are expected.
Apache continues using the live database; only this PHP development server can
use the staging override. Always use the launcher, which requires a staging name.

Open `/shop/index.php?shop=staging-shop` on that server; `/login.php` accepts the
generated test login. Stop the server when done; the launcher restores its environment.
The synthetic database is retained for follow-up testing; do not deploy it.

## HTTP regression test

With a fresh staging database and its server running:

```powershell
.\tests\staging_checkout_payment.ps1 -Database 'the printed database name' -TestPassword 'the generated test password'
```

This uses separate shopper/admin cookie sessions and the real form CSRF token.
It verifies checkout and replay protection, authenticated invoice access, Sales
confirmation and repeat confirmation, then a 200 BDT synthetic payment against
both the customer ledger and wallet. It refuses an already-used invoice database.
The test passed on 2026-10-07. It does not validate browser rendering or refunds.

Remaining browser acceptance checks:

- Checkout creates exactly one Pending Sales invoice with zero paid amount.
- Confirm that invoice in Sales; verify stock changes once and due remains correct.
- Receive a test payment into Test Cash; verify payment history and wallet balance.
- Repeat confirmation: no duplicate stock allocation or payment.
- Test cancellation before confirmation and return review after delivery.
- Process refunds only through Admin Sales; verify stock and money separately.

These are acceptance steps, not a claim that the HTTP flows have passed.
