# Live Deployment Checklist

Follow this order for each release. Do not upload only the edited page because
the stock, branch, access and sidebar changes share helper files.

## 1. Before deployment

1. Take a full database backup.
2. Take a copy of the current live application files and uploaded assets.
3. Confirm the live database user can run `CREATE TABLE`, `ALTER TABLE` and
   `CREATE INDEX`. The application uses repeat-safe schema bootstrap helpers.
4. Put the site in maintenance mode, or deploy to a release directory and
   switch the document root after upload.

## 2. Upload the release

1. Upload the complete Git release, including all new files under `includes/`,
   `warehouse/`, `tests/`, and `user_management/`.
2. Do not upload files ignored by Git, especially company backup JSON files and
   user-uploaded brand/customer files.
3. Preserve live `uploads/` content. Ensure the web server can write to the
   upload and backup directories used by the application.
4. Do not mix old and new PHP files during traffic. Use an atomic release switch
   when the host supports it.

## 3. Bootstrap and verify

1. Sign in once as Super Admin, then as a company Admin. This allows the
   repeat-safe schema helpers to create required columns, indexes and tables.
2. In Super Admin, verify Company Type and Multi Branch settings for each test
   company.
3. Test one company of every type: Housing, Others, and Stock Product.
4. For Stock Product, verify Products, Vendors, Main Warehouse, Sales and stock
   distribution. Create a test product with SKU and confirm invoice search works
   by both product name and SKU.
5. Confirm Stock Sales Report appears at the bottom of the Admin submenu and is
   absent from the Sales submenu.
6. Confirm a manager only sees permissions assigned in Access Management.
7. Test one purchase, one posted invoice, one due payment and one print view.

## 4. Finish or roll back

1. Check the PHP/server error log before taking the site out of maintenance.
2. If any schema or transaction check fails, restore both the previous files and
   the matching database backup; do not roll back only one of them.
3. Remove maintenance mode only after the smoke tests pass.

## Local verification completed

- All 266 PHP files passed `php -l`.
- Product display and Supplier/Vendor label tests passed.
- Stock integration checks passed, including FIFO, distribution, branch stock,
  reservations, permissions, wallet isolation and rollback cases.
