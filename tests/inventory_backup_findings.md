# Inventory & Sales backup investigation

## Confirmed defects corrected

- Import assigned new branch IDs but did not update branch references in financial/stock records. Branch-filtered dashboards, invoice lists, wallets and reports could therefore miss restored records.
- Purchase payment wallets, invoice receiving wallets, salary wallets, stock distribution references and several other links were missing from the ID mapping.
- Product category IDs incorrectly used the expense-category mapping. Product categories now restore before products and use their own map.
- A single restore pass could not resolve forward references. A second pass now rebuilds references from original backup values after all record IDs are known. Manager-to-staff links are also repaired.
- The selected branch in the current session could still refer to a deleted branch. Successful import clears that selection.
- Stock distribution and damage-return detail tables were absent from export and cleanup. They are now included.
- Missing financial tables could be treated as an empty backup and erase existing data. Import now rejects missing financial tables before deletion, raises database errors and rolls back failures.
- New backups include table row counts, checked before replacement.

## Verification

Run `C:\xampp\php\php.exe tests/company_backup_roundtrip_test.php`.

The test clones schema only into a randomly named disposable database and creates synthetic Inventory & Sales records. It runs the actual export collector, Delete function and Import POST handler (authentication/UI includes replaced by the synthetic account). It also simulates automatic Cash-wallet creation between delete and import. The disposable database is removed afterwards.

Passed: all exported table row counts; branch ID replacement; invoice total 200; expense total 25; invoice gross-profit inputs 120; wallet balance total 750; four wallet transactions; product-category, purchase-wallet, invoice-wallet, transfer-wallet and stock-distribution links; forward transaction references. Invalid-row import rolls back; incomplete backup leaves existing records intact.

PHP syntax and diff whitespace checks passed. This verifies data and representative report queries, not a browser walkthrough of every report or every optional module. File attachments and company_id-owned optional modules such as E-shop have not been round-trip verified by this test.

## Existing missing data

At inspection, company 70 had zero invoice rows, so its current empty Invoice List is not solely a display issue. Available backup files in the local Downloads folder belonged to other company IDs. No live company data was deleted, imported or reconstructed during testing. Historical invoices absent from the database require the correct pre-delete backup; this code fix cannot recreate missing source records.

The Tools backup/import code is shared. The regression scenario and requested investigation target Inventory & Sales; no company-specific presentation or financial calculation rules were changed.

## Fashion Retail follow-up

Run the same test with `--fashion` to use Fashion house with multi-branch enabled, XL/XXL variants, distribution batches, invoice FIFO allocations, sales-return batches and damage-return details.

The extended test reproduced a missing mapping for `stock_batches.source_type = sales_return`: its source is an invoice item, not an invoice. Import now remaps that source ID after invoice items have been restored.

After the correction, all shared financial checks and seven Fashion Retail checks passed: variant catalogue, FIFO cost 80, distribution source link, sales-return source link, damage-return detail links, XL remaining quantity 9 and XXL remaining quantity 5. Exported table row counts and malformed/incomplete import rollback checks also passed. These are isolated database/controller tests, not a browser walkthrough or a restoration of existing company data.

## Housing / Real Estate follow-up

Run the test with `--housing`. The extended test reproduced missing booking invoice charges in export. Export now includes `booking_invoice_charges`; Delete and Import remove existing charges through their company-owned booking invoices. Import maps the booking-invoice foreign key and the `invoice_income` / `invoice_expense` transaction references to restored booking invoices.

Passed after correction: confirmed booking income 1000 with customer/project/package/wallet/branch links intact; booking charges 50 with charge-type links; booking wallet transaction reference; Land Ledger area 10 and sold plot count 1 with project links. All shared financial checks, exported row counts and rollback tests passed. Synthetic records were used in a disposable database; no existing Housing business data was changed. Optional modules, file attachments and browser presentation are outside this database/controller verification.

## Service Business follow-up (single and multi-branch)

Run `php tests/company_backup_roundtrip_test.php --service` and repeat with `--multi-branch`. Both passed using internal company type `Service type`. Service categories and services use projects/packages and service invoices use booking_invoices, so the shared booking-charge and invoice_income/invoice_expense reference fixes already cover this flow; no additional production defect was reproduced in these scenarios.

Added regression coverage for custom income/expense invoice types, confirmed service income 1000, refund 100, net income 900, booking charges 50, service/category/customer/wallet links, and refund transaction references. Multi-branch checks force regenerated branch IDs, preserve the enabled flag, separate head-office income from the other branch refund, and verify that the refund wallet belongs to the restored invoice branch. Shared financial checks, exported row counts, stale selected-branch clearing and failed-import rollback passed in both modes.

Only disposable schema-clone databases and synthetic records were used; existing Service Business data was not modified. This is controller/database validation and representative total queries, not a browser walkthrough or historical data recovery. Optional modules and uploaded attachments remain outside this test coverage.

## Restaurant & Cafe follow-up (single and multi-branch)

Run `php tests/company_backup_roundtrip_test.php --restaurant` and repeat with `--multi-branch`. Both passed using company type `Restaurant & Cafe`. Restaurant table records are company-owned and invoice `restaurant_table_id` references are restored after their regenerated IDs are available; the Import reference mapper already covered this relationship.

The added regression cases verify the active table catalogue, invoice-to-table link, invoice amount and all shared sales, wallet, expense, report-input and rollback checks. The multi-branch case forces new branch IDs, moves a restaurant sale and its wallet transaction to the non-head-office branch, and verifies that the restored invoice, wallet and table references remain correct. No additional production fault was reproduced; no existing Restaurant/Cafe data was modified.

## Car Parking follow-up

The investigation found a production defect: Parking records use `company_id`, while the original generic export and delete flows only selected tables owned by `user_id`. As a result, Parking settings, vehicle types, rate plans, gates, assignments, zones, slots, tickets, payments, logs and shifts could be omitted from a backup and survive Delete All Data.

The shared backup collector and delete routine now support company-owned tables (while excluding the backup audit table). Import assigns the current company ID and restores Parking foreign keys in dependency order: vehicle type, rate plan, gate, zone, slot, ticket and payment. Operator/assignment links are preserved. A parking `money_in` wallet transaction is also linked back to its restored parking payment when it is identified as a Parking transaction.

`php tests/company_backup_roundtrip_test.php --parking` passed with regenerated IDs: Parking settings; ticket links to vehicle/gate/zone/slot/rate plan; payment links to ticket/wallet; gate activity and shift; gate staff assignment; and Parking wallet transaction reference. Standard Inventory and Restaurant multi-branch regression tests, PHP lint, and diff whitespace checks also passed. Only disposable test databases were created and removed; no live Parking data was changed.
