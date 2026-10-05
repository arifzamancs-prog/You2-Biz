# Installation reporting deployment

This is a lightweight self-reported installation registry, not copy protection or proof of theft.

## Original you2biz.com hosting

1. Back up the live code/database. Upload the changed files preserving paths. Do NOT replace live database credentials with local includes/db.php.
2. In includes/installation_reporting_config.php, set receiver_enabled to true ONLY on the original hosting. Keep endpoint as https://you2biz.com/installation_report.php (no redirects).
3. A random shared_key is already configured. Keep that same key in the original and distributed app copies. Keep this PHP file private; do not publish the key or put it in a URL. A key shipped with source can be extracted; it only filters unauthenticated internet requests, not malicious copy owners.
4. Open Super Admin > Installed Websites. The two registry tables are created automatically; the database account needs CREATE TABLE permission.
5. Click Test Connection. A successful test checks cURL, outbound HTTPS, receiver activation and the shared key; it does not create an installation record. TLS verification stays enabled. Ensure server clocks are accurate (five-minute tolerance).
6. On subsequent ordinary page use, a production installation reports automatically at most once every 24 hours. A failed attempt also waits 24 hours; use Test Connection for immediate diagnosis. This is activity-driven, not cron monitoring. On non-FastCGI hosting an attempted report may add up to four seconds once a day.

## Distributed copies

Keep receiver_enabled=false, retain the same shared_key and fixed reporting endpoint. Localhost, IP-address and .local/.test domains skip automatic reports. Test Connection still works locally without registering localhost. Each different site URL gets a random installation ID, so a copied database on a new domain gets a separate record.

The dashboard's New counter identifies unreviewed reports; Mark Reviewed does not verify the domain. Lists are visible only to authenticated Super Admin. A copied site's Super Admin cannot retrieve the original site's registry through the report endpoint.

## Data and limitations

The payload contains site URL (including application path), random installation ID and a test flag. The receiver timestamps first/last activity. No customer, financial, account or password data is transmitted. Ordinary web-server access logs may record request IP addresses; manage their retention in hosting settings.

Disclose reporting in your deployment/license/privacy terms before distributing installations. Example: “This application sends its installation URL and random installation identifier to you2biz.com approximately daily while in use, to maintain an installation registry. It does not send customer or transaction data.”

The domain is self-reported from the HTTP Host header and is NOT ownership-verified. Reports may be spoofed by someone with the shared key; reporting can be removed or blocked. Never automatically suspend a site based on these reports. Unknown links should be treated cautiously.

For public exposure, configure hosting/WAF rate limiting on /installation_report.php (for example 30 requests per minute per source IP) and a small request-body limit. The receiver itself caps accepted bodies at 2048 bytes, requires timestamped HMAC authentication and uses prepared SQL. It never fetches submitted domains. TLS failures must be fixed through the hosting CA/cURL configuration, never by disabling verification.

To disable all reporting, empty shared_key. To disable receiving only, set receiver_enabled=false. Existing registry rows remain. Do not enable the central receiver flag in distributed packages.
