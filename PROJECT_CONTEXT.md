আমি existing You2-Biz-কে multi-outlet/branch business management system করতে চাই।

Goal:
- Head admin সব outlet/branch-এর সব data দেখবে
- Outlet/branch staff শুধু নিজের outlet/branch-এর data দেখবে
- Staff attendance, salary এবং daily activity monitoring থাকবে
- Branch-wise customer, service, finance এবং reports থাকবে
- পরে You2-Wallet থেকে product, purchase, FIFO inventory, POS, return/exchange integrate করব

Important:
- Existing You2-Biz structure নষ্ট করা যাবে না
- আগে পুরো project structure ও database schema inspect করতে হবে
- সরাসরি code change নয়; আগে implementation plan দিতে হবে
- কাজ phase অনুযায়ী হবে:
  1. Branch/outlet architecture
  2. User role and branch permissions
  3. Attendance and salary
  4. Daily activity monitoring
  5. Head-office dashboard
  6. FIFO/product inventory integration
  7. Reports and audit log

You2-Wallet reference:
https://github.com/arifzamancs-prog/You2-wallet

Done list: 
----------
Multi-branch accounting system implement করা হয়েছে।
- Head Office admin navbar থেকে All Branches অথবা নির্দিষ্ট branch নির্বাচন করতে পারবেন।
- Dashboard, sales, invoices, wallets, money-in, expenses, transfers, transactions এবং reports branch অনুযায়ী filter হবে।
- All Branches নির্বাচন করলে consolidated হিসাব দেখা যাবে।
- প্রত্যেক branch-এর আলাদা system Cash wallet তৈরি হবে।
- Branch user শুধু নিজের branch-এর data দেখতে ও পরিচালনা করতে পারবে।
- অন্য branch-এর invoice direct URL দিয়েও access করা যাবে না।
- New invoice ও financial transaction selected branch-এর অধীনে save হবে।
- Existing data Head Office-এর অধীনে migrate করা হয়েছে।
- Multi Branch inactive হলে system স্বয়ংক্রিয়ভাবে Head Office context ব্যবহার করবে।
- Database-এ প্রয়োজনীয় branch_id columns এবং wallet unique index তৈরি হয়েছে।
- Head Office ও Gulshan Branch—দুইটির পৃথক Cash wallet verified।