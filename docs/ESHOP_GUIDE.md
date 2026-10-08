# E-shop operating guide

## 1. Enable a company shop

1. Super Admin opens **Super Admin → E-shop Management** from the company list.
2. Set a unique shop URL name and turn **E-shop access** on.
3. The company administrator can then open **E-shop** from the sidebar.

Only the enabled company can manage its own shop, products, orders, and checkout settings.

## 2. Prepare the storefront

1. In **E-shop → Shop Settings**, set the shop name, contact details, address, delivery charge, and return policy.
2. In **E-shop → Checkout Settings**, select the branch that holds online-order stock.
3. When a delivery charge is set in Shop Settings, select the matching active fixed *add* invoice charge type. The checkout settings page prevents saving an incomplete delivery setup.
4. In **E-shop → Shop Products**, publish only active physical stock products. An optional online price and shop description can be set per product.

Non-stock products cannot be published to the E-shop.

## 3. Variants and stock

For a product with variants, create the variants in the Products module and receive/distribute stock against each variant. The storefront shows the variant selector and only allows available variants to be ordered.

Stock is evaluated at the configured E-shop branch. A placed online order creates a pending Sales invoice and reserves only the chosen variant's stock. Stock is deducted through the existing Sales confirmation flow, not at cart-add time.

## 4. Order workflow

1. A customer places a cash-on-delivery order from the public shop.
2. The system creates a pending Sales invoice and an E-shop order reference.
3. Admin reviews it in **E-shop → Orders**, then confirms the linked Sales invoice using the normal Sales process.
4. Update the delivery status: New → Processing → Shipped → Delivered. Add a courier or tracking reference when applicable.
5. The customer can use **Track order** with the order reference and checkout phone number. It shows order items, variants, delivery, payment, refund status, and delivery history only after both values match.

## 5. Cancellations, returns, and refunds

- A pending, unconfirmed order can be cancelled from E-shop Orders. Its pending invoice is removed and its reservation is released.
- After shipment/delivery, an administrator can record a return request for review.
- Refunds are completed from **Sales → Refund / History** on the original confirmed invoice. That process creates the credit record and can restore stock when appropriate.

## 6. Customer emails

Order-update email is available only when the customer has a valid email address. The sender uses the company name. Email delivery history remains visible in the order detail.

## 7. Reports

Open **E-shop → Reports** to review a selected date range. It shows order, pending, delivery, gross-sales, refund, and net-sales totals without changing any order, invoice, stock, or payment record. Refund and net amounts are calculated from refunds recorded against orders placed in the selected period.

## Safety rules

- Do not alter a pending E-shop invoice outside the established Sales workflow.
- Select the original invoice's branch before Sales confirmation, cancellation, or refund work.
- Keep product variants and their stock names consistent; an order stores the exact selected variant name.
