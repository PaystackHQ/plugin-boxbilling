# paystack-boxbilling
BoxBilling plugin for Paystack Payments

## Prepare

Before you can start taking payments through Paystack, you will first need to sign up at:
[https://dashboard.paystack.co/#/signup][link-signup]. To receive live payments, you should request a Go-live after
you are done with configuration and have successfully made a test payment.

## Requirements

- PHP with the 19 Nov 2026 security fixes, which include remote code execution flaws in the Date and Session extensions (GHSA-gr4f-wgx5-jpfv, GHSA-fm2m-fjf5-v3cp, GHSA-658j-r8rw-q6mx) and, on PHP 8.5, in Core (GHSA-8mjj-2r7p-4hg2) ([PHP changelog](https://www.php.net/ChangeLog-8.php#8.5.13)): **8.2.36, 8.3.37, 8.4.28, 8.5.13 or later** (PHP 8.6.0 also includes them). PHP 8.2 reaches end of life on 31 December 2026, and PHP 8.1 and older no longer receive security fixes. These flaws are in the PHP runtime, not in this plugin, so upgrading PHP on your server is what fixes them.
- The adapter itself is checked to load and run without errors, warnings or deprecations on PHP 7.4, 8.3 and 8.5 against stubbed BoxBilling classes (see the pull request for how it was tested).
- BoxBilling: the last BoxBilling release (4.22.1.5, April 2022) documents PHP 8.0 as its recommended version and says PHP 8.1 is untested ([BoxBilling README](https://github.com/boxbilling/boxbilling#requirements)). The repository is archived and BoxBilling is no longer maintained, so **BoxBilling has not been shown to run a patched PHP 8.2+ release; merchants on BoxBilling should migrate to a maintained platform**.
- [FOSSBilling](https://fossbilling.org/), the maintained fork of BoxBilling, currently requires PHP 8.3 or later ([composer.json](https://github.com/FOSSBilling/FOSSBilling/blob/main/composer.json)), but its payment adapter API differs from the one this plugin implements, and this plugin has **not** been ported to or tested on FOSSBilling.

## Install

- Add the `Paystack.php` file as `/bb-library/Payment/Adapter/Paystack.php` .
- Add the `paystack.png` file as `/bb-themes/paystack/paystack.png` .
- Configure it by copying keys from https://dashboard.paystack.co/#/settings/developer .
- Set your Test Webhook url (and Live Webhook URL) on https://dashboard.paystack.co/#/settings/developer to the IPN Callback URL on the Paystack configuration Page.
- Add these lines to your `/bb-themes/{theme-name}/css/logos.css` file:
```css
        .logo-Paystack {
            background: transparent url("../../../paystack/paystack.png") no-repeat scroll 0% 0%;
            background-size: contain;
            width: 139px;
            height: 36px;
            border: 0;
            margin: 10px;
            margin-bottom: 24px;
        }
```
- Accept payments!

## Security

If you discover any security related issues, please email `support@paystack.com` instead of using the issue tracker.

## Credits

- [Paystack Support][link-author]
- [Ibrahim Lawal][link-author2]

## License

Apache-2.0 License

[link-author]: https://github.com/paystackhq
[link-signup]: https://dashboard.paystack.co/#/signup
[link-keys]: https://dashboard.paystack.co/#/settings/developer
[link-author2]: https://github.com/ibrahimlawal

## Support
For bug reports and feature requests directly related to this plugin, please use the [issue tracker](https://github.com/PaystackHQ/plugin-boxbilling/issues). 

For general support or questions about your Paystack account, you can reach out by sending a message from [our website](https://paystack.com/contact).

## Community
If you are a developer, please join our Developer Community on [Slack](https://slack.paystack.com).

## Contributing to Paystack Plugin for Boxbilling

If you have a patch or have stumbled upon an issue with this plugin, you can contribute this back to the code. Please read our [contributor guidelines](CONTRIBUTING.md) for more information how you can do this.
