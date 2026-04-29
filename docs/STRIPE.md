# Stripe Setup Guide — Ecstatic Dance Viseu

Stripe handles all paid ticket processing, including MB Way and Multibanco.

---

## Account setup

1. Create a Stripe account at [stripe.com](https://stripe.com) — register as a business in **Portugal**
2. Complete identity verification (required to accept live payments)
3. Set the default currency to **EUR**

---

## Enable MB Way and Multibanco

1. Go to **Dashboard → Settings → Payment Methods**
2. Enable:
   - **MB WAY** — Portuguese digital wallet (phone-based approval)
   - **Multibanco** — ATM reference payment (widely used in Portugal)
   - **Cards** — Visa, Mastercard (already enabled by default)

**MB Way notes:**
- Requires EUR currency ✓ (already configured)
- Amount must be between €0.50 and €5,000 ✓
- Does **not** support recurring payments (not relevant here)
- Customer must have the MB Way app installed
- Payment expires after ~10 minutes if not approved

**Multibanco notes:**
- Customer pays at any ATM within 3 days
- Good for customers who prefer not to use online card payments

---

## API keys

In **Dashboard → Developers → API Keys**:

| Key | Use |
|---|---|
| Publishable key (`pk_live_...`) | Frontend (safe to expose) |
| Secret key (`sk_live_...`) | Backend `config.php` only — never expose |

During development, use **test mode** keys (`pk_test_` / `sk_test_`).

```php
// config.php
define('STRIPE_PUBLIC_KEY', 'pk_live_...');
define('STRIPE_SECRET_KEY', 'sk_live_...');
```

---

## Webhook configuration

### Create the webhook endpoint

1. Go to **Dashboard → Developers → Webhooks**
2. Click **Add endpoint**
3. Set URL to: `https://ecstaticdanceviseu.pt/api/webhook.php`
4. Select events to listen for:
   - `checkout.session.completed`
   - `checkout.session.expired`
5. Click **Add endpoint**
6. Copy the **Signing secret** (`whsec_...`)
7. Add to `config.php`:
   ```php
   define('STRIPE_WEBHOOK_SECRET', 'whsec_...');
   ```

### Test the webhook locally (optional)

Install the [Stripe CLI](https://stripe.com/docs/stripe-cli) and run:

```bash
stripe listen --forward-to localhost:5173/api/webhook.php
```

This forwards Stripe events to your local dev server.

---

## Sliding scale Checkout

The booking form lets users choose their amount (€25–€80). The backend creates a Stripe Checkout Session with the exact amount chosen.

The Checkout session is configured with `locale: 'pt'` so Stripe's hosted page appears in Portuguese.

**Supported payment methods on Checkout:**
- Card (Visa, Mastercard, Amex)
- MB Way (shown to Portuguese customers)
- Multibanco (shown to Portuguese customers)

Stripe automatically shows the relevant methods based on the customer's location when using automatic payment methods. To enable this, leave `payment_method_types` unset or use `automatic_payment_methods[enabled]=true` in the Checkout Session creation.

---

## Testing

### Test cards (card payments)

| Card number | Scenario |
|---|---|
| `4242 4242 4242 4242` | Successful payment |
| `4000 0000 0000 0002` | Card declined |
| `4000 0025 0000 3155` | 3D Secure required |

Use any future expiry date and any 3-digit CVC.

### Test MB Way

Use the test phone number provided by Stripe:
- **Phone:** `+351 912 345 678` (Stripe test number for Portugal)
- The approval notification appears automatically in test mode

### Test Multibanco

In test mode, the Multibanco reference is generated immediately. Use Stripe's test instructions to simulate payment confirmation.

---

## Going live checklist

- [ ] Complete Stripe account verification
- [ ] Switch from test keys to live keys in `config.php`
- [ ] Update webhook endpoint to use live mode (or create a new webhook for live mode)
- [ ] Enable MB Way + Multibanco in live mode Dashboard settings
- [ ] Make one live test payment with a real card (refund immediately after)
- [ ] Verify webhook receives the `checkout.session.completed` event
- [ ] Verify confirmation email is sent after payment

---

## Viewing payments

- **Dashboard → Payments** — all transactions
- **Dashboard → Webhooks → Recent deliveries** — webhook log
- **Dashboard → Reports** — revenue summaries

## Issuing refunds

1. Go to **Dashboard → Payments**
2. Find the payment
3. Click **Refund**
4. After refund, manually delete or mark the ticket in the database (see [DATABASE.md](./DATABASE.md))
