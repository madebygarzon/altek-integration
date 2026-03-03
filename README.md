# ALTEK Integration for WooCommerce

WordPress/WooCommerce integration plugin that sends orders directly to ALTEK (PostgreSQL-backed flow), including bulk operations and operational logging.

## Value Proposition
- Bridges e-commerce operations with ERP/back-office workflows.
- Reduces manual order handling and operational friction.
- Adds traceability with logs and order-level notes.

## Main Features
- Send individual WooCommerce orders to ALTEK
- Bulk send from order list
- Configurable PostgreSQL connection settings
- Product exclusion rules (SKU/ID)
- Detailed logs in WooCommerce
- Automatic order notes with transmission status

## Stack
- WordPress + WooCommerce
- PHP
- PostgreSQL integration

## Setup
1. Copy plugin folder into `wp-content/plugins`
2. Activate plugin in WordPress admin
3. Configure ALTEK connection under WooCommerce integration settings
4. Validate logs and test with sample orders

## Operational Notes
- Supports SSL connection modes
- Includes timeout and debug controls
- Designed for production support workflows

## Business Impact
Enables reliable e-commerce → operations synchronization, improving fulfillment flow and reducing manual errors.

---
## Author

- Created by **Carlos Garzón**
- Software Engineer, Fullstack Developer.
---

## Licenses

MIT
