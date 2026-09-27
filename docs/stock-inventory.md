# Stock inventory integration

Stock In receives purchases into Head Office/Main Warehouse. Main Warehouse distributes FIFO batches to active branches. Stock Sales consumes only the invoice branch's batches, preserving purchase cost and receipt order. Distribution does not move money or change company-wide stock quantity.

Existing service Sales remains separate. Stock purchases, sales and collections use the owning branch's wallets. Stock Sales Report shows sales, FIFO cost and gross profit (not net profit). Existing module permissions still apply; branch staff cannot distribute central stock. Multi Branch being disabled does not automatically transfer historical stock or staff.

## Deployment

- Back up database and files first. Deploy the full related change set, not just warehouse pages. Preserve live database credentials in includes/db.php.
- Schema helpers create missing stock tables and add required columns automatically. The database account needs CREATE and ALTER privileges as well as normal application privileges.
- Initialization is repeat-safe. Existing aggregate stock is reconciled once against FIFO batches; discrepancies are recorded in stock_migration_adjustments. Missing opening quantities use the product's stored purchase price, which is an estimate rather than reconstructed historical cost. Review these adjustments after deployment.
- The FIFO feature is enabled for this integration. The Super Admin configuration UI is deliberately deferred until the user's next instructions.

## Verification

Run `C:\xampp\php\php.exe tests/stock_inventory_integration.php` locally. It uses a disposable database and does not copy business rows. Tests cover schema initialization, migration, branch FIFO allocation/distribution, reservations, reversal, wallet ownership, due allocation and transaction rollback.

Authenticated browser smoke testing remains necessary: create product, receive stock, distribute, sell from a branch, collect due, and compare wallet/report totals. The available browser session was at the login screen; no authenticated UI test is claimed.

Reference source: https://github.com/arifzamancs-prog/You2-wallet at d70e3bfbf43caf2eb49238c4db34fd1bd1e5f8f2. Existing compatible modules were adapted instead of replacing service Sales.
