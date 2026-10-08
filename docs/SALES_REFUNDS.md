# Admin Sales refunds

Open Sales → Invoice → View → **Refund / History**. Only the company Admin
can use the form. The invoice must be posted, belong to the selected branch,
have a customer and have recorded cash payments. Enter the remaining amount
for a full refund or a smaller amount for a partial refund, select an active
wallet in that branch, and provide a reason.

Return quantity and money are separate. A zero quantity is a money-only
adjustment. A positive quantity records an item return; check **Restock** only
for usable goods. Unchecked returned goods do not increase stock or reverse
inventory cost. Total returned quantity cannot exceed the original sale.

Refunds cannot exceed the lesser of invoice total and recorded invoice payments,
minus prior refunds. Advance credit applied without a receipt on this invoice
is not eligible for a cash refund through this form. Wallet funds must suffice.

The original invoice is preserved. Each refund creates a linked negative Sales
credit note, a negative customer receipt, a signed wallet receipt reversal and
an audit row with actor, reason and time. Restocking creates a new batch in the
original branch at the original line's average FIFO cost. Credit value is
allocated across returned products (all original products for money-only
adjustments), including any invoice-level adjustment included in the refund.

Refund-linked originals and credit notes cannot be edited or deleted through
Sales. E-shop return requests remain requests, not proof that money was refunded.
Use Refund History to verify actual refunds. No customer email is sent by this
workflow and no external payment gateway is invoked: the Admin records money
returned through the selected business wallet.

## Validation

`tests/staging_checkout_payment.ps1 -Refund` checks the real HTTP flow through
checkout, payment and two partial refunds completing a full refund, replay
protection, optional restocking, ledger/report totals and original preservation.
`tests/sales_refund_staging_test.php <staging-database>` covers helper-level
tenant/branch/wallet validation, monetary/quantity limits and idempotency.
Both require synthetic staging data; never run against live business records.
