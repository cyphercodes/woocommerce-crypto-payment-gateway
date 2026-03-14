# Changelog

All notable changes to **0xProcessing for WooCommerce** will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] — 2026-03-14

### Added
- Fixed-amount cryptocurrency payment support via 0xProcessing
- HPOS compatibility (WooCommerce 7.1+)
- Multi-currency support with automatic fiat-to-USD conversion
- Webhook signature verification (MD5)
- Custom database table for payment tracking and analytics
- Test mode with proper order isolation
- Active currency filtering from 0xProcessing API
- Configurable order status after successful payment
- Payment status banners on order details / thank-you page
- Insufficient (underpaid) payment handling with admin email alerts
- Admin notice for missing webhook password
- Gateway checkout icon (SVG)
- Clean uninstall (drops table, removes options and order meta)
- CSS custom properties for theme-neutral styling
- WordPress.org `readme.txt`
- GitHub Actions deploy workflow for WordPress.org SVN
