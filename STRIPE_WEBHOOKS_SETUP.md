# Stripe Webhooks Setup Guide

To enable real-time payment confirmation, automatic domain registration/renewal provisioning, invoice synchronization, and quote updates, add the Webhook Endpoint in your **Stripe Dashboard**.

---

## 1. Webhook Endpoint URL

In your Stripe Dashboard, navigate to **Developers -> Webhooks -> Add endpoint** and enter:

```text
https://your-domain.com/api/stripe-webhook
```
*(or `https://your-domain.com/api.php?action=stripe_webhook`)*

---

## 2. Events to Listen For (15 Events)

Select the following events when creating the webhook endpoint in Stripe:

### Checkout & Domain Provisioning
1. `checkout.session.completed` — Provisions new subscriptions and automatically registers or renews domains via Namecheap/OpenSRS upon payment completion.
2. `checkout.session.async_payment_succeeded` — Handles delayed payment methods (e.g., ACH/bank transfers) once funds clear.

### Customer Profile Synchronization
3. `customer.created` — Links newly created Stripe customers to matching portal accounts by email.
4. `customer.updated` — Syncs updated billing address, phone number, and name changes from Stripe back into the portal database.
5. `customer.deleted` — Clears the local `stripe_customer_id` link if a customer record is deleted in Stripe.

### Subscriptions Lifecycle
6. `customer.subscription.created` — Logs new recurring plan activations.
7. `customer.subscription.updated` — Tracks plan upgrades, downgrades, and period-end cancellation schedules.
8. `customer.subscription.deleted` — Logs subscription termination.

### Invoices & Payments
9. `invoice.created` — Records newly generated invoices.
10. `invoice.finalized` — Makes newly finalized invoices immediately payable in the client portal.
11. `invoice.paid` — Marks invoices as paid and updates account billing history.
12. `invoice.payment_failed` — Logs failed payment attempts so clients and admins are alerted.

### Quotes & Proposals
13. `quote.finalized` — Displays new open quotes in the client's **Subscriptions & Billing** tab.
14. `quote.accepted` — Logs quote acceptance and links the resulting invoice/subscription.
15. `quote.canceled` — Updates quote status to canceled.

---

## 3. Save Your Signing Secret (`whsec_...`)

After creating the endpoint in Stripe:
1. Click **Reveal** under **Signing secret** in Stripe to copy your `whsec_...` key.
2. Sign in to your portal as an administrator (`/admin`), open the **API & Portal Settings** tab, paste the `whsec_...` secret into **Stripe Webhook Signing Secret**, and click **Save All Settings** (or paste it into `SCP_STRIPE_WEBHOOK_SECRET` inside `config/credentials.php`).
