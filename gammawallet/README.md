# gammawallet — developer notes

PrestaShop module for Gamma Wallet. The shop owners' guide is the README one level up; this file is for whoever changes the code.

## Layout

| Path | What it does |
|---|---|
| `gammawallet.php` | The module (`PaymentModule`): install/uninstall, the order status *Awaiting Gamma store credits*, the payment option, every hook, the settings page |
| `classes/GammaWalletApi.php` | Calls to the Gamma Integration API (`Connection/Me`, `Bill/Create`, `Bill/Get`, `Credit/Start`, `Credit/Check`) with the shop's `GWINT_` token |
| `classes/GammaWalletSettings.php` | Settings, the cached connection check (refreshed hourly), the Reward-service and currency checks |
| `classes/GammaWalletOrder.php` | The `PREFIX_gammawallet_order` table (one row per order), the reward logic and the store-credit logic |
| `controllers/front/validation.php` | Creates the order in *Awaiting Gamma store credits* and starts the credit request |
| `controllers/front/status.php` | Polled by the customer's page every 5 s; protected by the order's `secure_key` |
| `controllers/front/newcode.php` | A new store-credit code; asks Gamma about the current one first |
| `views/` | Templates, CSS and the polling script (shared design with the WooCommerce plugin) |
| `mails/en/gammawallet_reward.*` | The reward email for pay-later orders and for sending it again |

## Rules it follows

- A reward is created when the order enters a **paid** order state (`actionOrderStatusPostUpdate`), only for ticked payment modules, only while the business has a Reward service (`canClaim`), and only when the currencies match. Orders settled with store credits never earn one.
- Pay-later modules (`ps_cashondelivery`, `ps_wirepayment`, `ps_checkpayment`) get the reward in an email of their own, sent once; their order confirmation email carries nothing.
- Store credits settle the whole order. The order waits in its own state until `Credit/Check` says Paid, then moves to `PS_OS_PAYMENT`, and Gamma's request id is stored as the payment's transaction id.
- The order confirmation email gets the reward block through `actionEmailAddAfterContent` (a placeholder) and `sendMailAlterTemplateVars` (its value; always set, empty when there is no reward).
- Amounts are JSON-encoded with `serialize_precision = -1` (`GammaWalletApi::json`). With PHP's older default of 17, 0.80 would go out as 0.80000000000000004, Gamma would sign that, and the customer's app (sending 0.8) would be refused with CreditRequestInvalid.
- The code stays PHP 7.2-compatible, as PrestaShop 8 allows it.
- For testing against another Integration API, define `GAMMAWALLET_API_URL` in `config/defines.inc.php`.

## Tested

PrestaShop 8.2.8 with the Classic theme, PHP 8.1, in Docker, against the live Integration API:

- store credits: QR, countdown, settled from the app's flow, order moved to *Payment accepted* with the request id;
- new code: refused while the old one is valid, a fresh one after expiry, settled-before-asking detected;
- rewards for an order paid by card: QR on the confirmation page, the customer's order page, the guest tracking page and in the order confirmation email;
- cash on delivery: no reward when unticked; when ticked, a note at checkout, then the reward and one email when the order is marked *Payment accepted* in the back office;
- the back-office box and its "send again" button; the settings page; the module switching itself off without a Reward service.

Not tested yet: PrestaShop 9, the Hummingbird theme.
