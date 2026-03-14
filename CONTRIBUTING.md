# Contributing to 0xProcessing for WooCommerce

Thank you for your interest in contributing! Here's how you can help.

## Reporting Bugs

1. Search [existing issues](https://github.com/cyphercodes/woocommerce-crypto-payment-gateway/issues) first.
2. Open a new issue with:
   - WordPress, WooCommerce, and PHP versions
   - Steps to reproduce
   - Expected vs. actual behavior
   - Relevant log entries (`WooCommerce → Status → Logs → oxprocessing`)

## Suggesting Features

Open an issue tagged **enhancement** and describe the use case.

## Pull Requests

1. Fork the repository and create a feature branch from `main`.
2. Follow the existing code style (WordPress Coding Standards).
3. Test with WooCommerce 6.0+ and PHP 7.4+.
4. Ensure `php -l` passes on every PHP file.
5. Update `CHANGELOG.md` with your changes under an `[Unreleased]` section.
6. Open a PR with a clear description.

## Code Style

- Follow [WordPress PHP Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/).
- Use tabs for indentation in PHP.
- Prefix all functions, classes, and hooks with `oxprocessing_` or `WC_0xProcessing_`.

## Security Vulnerabilities

**Do not open a public issue.** Email security concerns to the maintainer directly.

## License

By contributing, you agree that your contributions will be licensed under the [GPL-3.0](LICENSE).
