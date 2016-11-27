# paystack-boxbilling
BoxBilling plugin for Paystack Payments

## Prepare

Before you can start taking payments through Paystack, you will first need to sign up at:
[https://dashboard.paystack.co/#/signup][link-signup]. To receive live payments, you should request a Go-live after
you are done with configuration and have successfully made a test payment.

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
