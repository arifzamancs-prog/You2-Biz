# E-shop release checklist

Run the read-only preflight from the project root before deployment:

```powershell
C:\xampp\php\php.exe tests/eshop_release_preflight.php
```

It checks required E-shop, inventory, invoice, and variant schema; product-upload directory availability; and non-sensitive SMTP readiness. It does not create, alter, or change data.

Before publishing the E-shop:

1. Back up the production database and uploads directory through the normal hosting/operations process.
2. Ensure the site is served over HTTPS and the public shop URL resolves to the intended domain.
3. Confirm E-shop Store Readiness is green for each enabled company.
4. Perform the synthetic staging acceptance flow before a production release.
5. Verify delivery with an explicitly approved test mailbox; do not use a customer address for testing.
6. Keep the Sales confirmation, cancellation, return, and refund workflows unchanged.

The preflight validates configuration format only. It does not send email or test external SMTP connectivity.
