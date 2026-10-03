"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useRef, useState } from "react";

import { ApiError } from "@/lib/api";
import { useCart } from "@/lib/cart-context";
import { fetchCustomerAccount, getCustomerSession } from "@/lib/customer-auth";
import {
  fetchPaymentConfig,
  fetchPublicSettings,
  formatPrice,
  initiateCardPayment,
  placeOrder,
  validateCart,
  type PaymentConfig,
  type PublicSettings,
} from "@/lib/storefront";
import type { OrderDetail } from "@/lib/types";
import type { CustomerUser } from "@/lib/types";
import { buildWhatsAppLink } from "@/lib/whatsapp";

type PaymentMethod = "cod" | "whatsapp" | "card";

const inputClass =
  "mt-1 block w-full rounded-lg border border-neutral-300 px-3 py-3 text-sm text-neutral-900 placeholder:text-neutral-400 focus:border-neutral-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-neutral-900 focus-visible:ring-offset-1";
const errorClass =
  "mt-1 block w-full rounded-lg border border-red-400 px-3 py-3 text-sm text-neutral-900 placeholder:text-neutral-400 focus:border-red-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-neutral-900 focus-visible:ring-offset-1 focus-visible:ring-2 focus-visible:ring-red-600 focus-visible:ring-offset-1";

const FIELD_LABELS: Record<string, string> = {
  name: "Full name",
  phone: "Phone",
  email: "Email",
  address: "Shipping address",
  city: "City",
  postalCode: "Postal code",
};

const FIELD_IDS: Record<string, string> = {
  name: "checkout-name",
  phone: "checkout-phone",
  email: "checkout-email",
  address: "checkout-address",
  city: "checkout-city",
  postalCode: "checkout-postal",
};

const FIELD_ORDER = ["name", "phone", "email", "address", "city", "postalCode"] as const;

function paymentLabel(method: string): string {
  if (method === "cod") return "Cash on Delivery";
  if (method === "whatsapp") return "Order via WhatsApp";
  if (method === "card") return "Card (CMI/Payzone)";
  return method;
}

function buildWhatsAppMessage(order: OrderDetail): string {
  const lines = order.items
    .map(
      (item) =>
        `- ${item.product_name} (${item.size}, ${item.color}) × ${item.quantity} — ${formatPrice(Number(item.price_at_purchase) * item.quantity)}`,
    )
    .join("\n");
  const addressParts = [order.shipping_address, order.city, order.postal_code].filter(Boolean).join(", ");
  const subtotal = (order as { subtotal_amount?: string }).subtotal_amount ?? order.total_amount;
  const shipping = (order as { shipping_cost?: string }).shipping_cost ?? "0.00";
  return [
    `Hello Luce Bianca! I'd like to confirm my order.`,
    ``,
    `Order #${order.id}`,
    lines,
    ``,
    `Subtotal: ${formatPrice(subtotal)}`,
    `Shipping: ${Number(shipping) > 0 ? formatPrice(shipping) : "Free"}`,
    `Total: ${formatPrice(order.total_amount)}`,
    ``,
    `Name: ${order.customer_name}`,
    `Phone: ${order.phone}`,
    `Address: ${addressParts}`,
    ``,
    `Payment: ${paymentLabel(order.payment_method)}`,
  ].join("\n");
}

/**
 * Client-side shipping estimate (preview only — the server recomputes
 * authoritatively at order time from the same settings). Mirrors
 * OrderService::calculateShippingCents: free when subtotal >= threshold.
 */
function estimateShippingCents(
  subtotal: number,
  settings: PublicSettings | null,
): number {
  const cost = Number(settings?.shipping_cost ?? 0);
  const threshold = Number(settings?.free_shipping_threshold ?? 0);
  if (!Number.isFinite(cost) || cost <= 0) return 0;
  if (Number.isFinite(threshold) && threshold > 0 && subtotal >= threshold) {
    return 0;
  }
  return Math.round(cost * 100) / 100;
}

export default function CheckoutForm() {
  const router = useRouter();
  const { items, totalPrice } = useCart();

  const [name, setName] = useState("");
  const [phone, setPhone] = useState("");
  const [email, setEmail] = useState("");
  const [address, setAddress] = useState("");
  const [city, setCity] = useState("");
  const [postalCode, setPostalCode] = useState("");
  const [paymentMethod, setPaymentMethod] = useState<PaymentMethod>("cod");
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [serverError, setServerError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [customer, setCustomer] = useState<CustomerUser | null>(null);
  const [loadingCustomer, setLoadingCustomer] = useState(true);
  const [publicSettings, setPublicSettings] = useState<PublicSettings | null>(null);
  const [paymentConfig, setPaymentConfig] = useState<PaymentConfig | null>(null);

  const redirectingRef = useRef(false);

  // Load customer data if logged in
  useEffect(() => {
    const session = getCustomerSession();
    if (session) {
      fetchCustomerAccount(session.token)
        .then((response) => {
          setCustomer(response.data);
          setName(response.data.name ?? "");
          setEmail(response.data.email ?? "");
          setPhone(response.data.phone ?? "");
        })
        .catch(() => {
          // Ignore errors, just leave customer as null
        })
        .finally(() => {
          setLoadingCustomer(false);
        });
    } else {
      // eslint-disable-next-line react-hooks/set-state-in-effect
      setLoadingCustomer(false);
    }
  }, []);

  // Load public settings (payment toggles, WhatsApp number, shipping) — cached via storefront fetch
  useEffect(() => {
    fetchPublicSettings()
      .then((res) => setPublicSettings(res.data))
      .catch(() => {
        // keep defaults (all enabled, fallback WhatsApp)
      });
    fetchPaymentConfig()
      .then((res) => setPaymentConfig(res.data))
      .catch(() => {
        // card stays hidden when the gateway status cannot be confirmed
      });
  }, []);

  // Empty-cart guard: if nothing is in the cart, go back.
  useEffect(() => {
    if (items.length === 0 && !redirectingRef.current) {
      redirectingRef.current = true;
      router.replace("/cart");
    }
  }, [items.length, router]);

  // Validate the cart against live stock/prices once on load (spec 5:
  // the client never trusts its own price/stock snapshot).
  useEffect(() => {
    if (items.length === 0) {
      return;
    }
    let cancelled = false;
    validateCart(
      items.map((item) => ({
        variant_id: item.variant_id,
        quantity: item.quantity,
        unit_price: item.unit_price,
      })),
    )
      .then((response) => {
        if (cancelled) {
          return;
        }
        const unavailable = response.data.lines.filter((line) => !line.available);
        if (unavailable.length > 0) {
          setServerError(
            unavailable
              .map((line) => {
                const item = items.find((entry) => entry.variant_id === line.variant_id);
                const label = item
                  ? `${item.product_name} (${item.size}, ${item.color})`
                  : `Item #${line.variant_id}`;
                const quantity = line.available_quantity ?? 0;
                return quantity > 0
                  ? `${label} — only ${quantity} left. Please adjust the quantity in your cart.`
                  : `${label} is no longer available. Please remove it from your cart.`;
              })
              .join(" "),
          );
        } else {
          setServerError(null);
        }
      })
      .catch((error) => {
        if (!cancelled) {
          setServerError(
            error instanceof ApiError
              ? error.message
              : "Could not validate your cart right now. Please try again.",
          );
        }
      });
    return () => {
      cancelled = true;
    };
  }, [items]);

  const isLoggedIn = customer !== null;

  // Payment toggles from settings (defaults to enabled for fresh installs).
  // Card is offered only when BOTH the admin toggle is on AND the CMI gateway
  // is configured (GET /api/payments/config) — never a hard-coded "coming soon".
  const isCodEnabled = publicSettings?.payment_cod_enabled !== "0";
  const isWhatsappEnabled = publicSettings?.payment_whatsapp_enabled !== "0";
  const isCardToggleOn = publicSettings?.payment_card_enabled !== "0";
  const isCardAvailable =
    isCardToggleOn && (paymentConfig?.card_available ?? false);
  const estimatedShipping = estimateShippingCents(totalPrice, publicSettings);
  const estimatedTotal = totalPrice + estimatedShipping;

  // If the currently selected method becomes disabled via settings, switch to an enabled one
  // (derived selection — disable the cascading-render rule here; the update is
  // conditional and idempotent, not a render loop).
  useEffect(() => {
    if (!publicSettings) return;
    const fallback: PaymentMethod | null =
      isCodEnabled ? "cod" : isWhatsappEnabled ? "whatsapp" : isCardAvailable ? "card" : null;
    if (paymentMethod === "cod" && !isCodEnabled && fallback) {
      // eslint-disable-next-line react-hooks/set-state-in-effect
      setPaymentMethod(fallback);
    } else if (paymentMethod === "whatsapp" && !isWhatsappEnabled && fallback) {
      setPaymentMethod(fallback);
    } else if (paymentMethod === "card" && !isCardAvailable && fallback) {
      setPaymentMethod(fallback);
    }
  }, [publicSettings, paymentConfig, isCodEnabled, isWhatsappEnabled, isCardAvailable, paymentMethod]);

  function validate(): boolean {
    const next: Record<string, string> = {};
    if (!isLoggedIn && name.trim().length < 2) {
      next.name = "Please enter your full name.";
    }
    if (!/^[0-9+()\-\s]{8,20}$/.test(phone.trim())) {
      next.phone = "Please enter a valid phone number.";
    }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim())) {
      next.email = "Please enter a valid email address.";
    }
    if (address.trim().length < 5) {
      next.address = "Please enter your shipping address.";
    }
    if (!city.trim()) {
      next.city = "Please enter your city.";
    }
    if (!postalCode.trim()) {
      next.postalCode = "Please enter your postal code.";
    }
    if (paymentMethod === "cod" && !isCodEnabled) {
      next.payment_method = "Cash on Delivery is currently disabled.";
    }
    if (paymentMethod === "whatsapp" && !isWhatsappEnabled) {
      next.payment_method = "Order via WhatsApp is currently disabled.";
    }
    if (paymentMethod === "card" && !isCardAvailable) {
      next.payment_method = "Card payment is currently unavailable. Please choose another method.";
    }
    setErrors(next);
    const hasErrors = Object.keys(next).length > 0;
    if (hasErrors) {
      const firstKey = FIELD_ORDER.find((key) => key in next);
      if (firstKey) {
        const id = FIELD_IDS[firstKey];
        // Defer until after React has flushed the error messages to the DOM.
        setTimeout(() => {
          const el = document.getElementById(id);
          if (el) {
            el.scrollIntoView({ behavior: "smooth", block: "center" });
            // Focus without causing an extra jump; the scroll above already
            // brought the field into view.
            try {
              (el as HTMLElement).focus({ preventScroll: true } as FocusOptions);
            } catch {
              (el as HTMLElement).focus();
            }
          }
        }, 50);
      }
    }
    return !hasErrors;
  }

  async function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setServerError(null);

    if (items.length === 0) {
      router.replace("/cart");
      return;
    }
    if (!validate()) {
      return;
    }

    // Re-validate stock at submit time — the last moment before ordering.
    try {
      const check = await validateCart(
        items.map((item) => ({ variant_id: item.variant_id, quantity: item.quantity })),
      );
      const unavailable = check.data.lines.filter((line) => !line.available);
      if (unavailable.length > 0) {
        const line = unavailable[0];
        const item = items.find((entry) => entry.variant_id === line.variant_id);
        const label = item
          ? `${item.product_name} (${item.size}, ${item.color})`
          : `Item #${line.variant_id}`;
        setServerError(
          `${label} is no longer available in that quantity. Please review your cart.`,
        );
        return;
      }
    } catch (error) {
      setServerError(
        error instanceof ApiError
          ? error.message
          : "Could not validate your cart right now. Please try again.",
      );
      return;
    }

    setSubmitting(true);
    try {
      const response = await placeOrder(
        {
          customer_name: name.trim(),
          phone: phone.trim(),
          email: email.trim(),
          shipping_address: address.trim(),
          city: city.trim(),
          postal_code: postalCode.trim(),
          payment_method: paymentMethod,
          items: items.map((item) => ({
            variant_id: item.variant_id,
            quantity: item.quantity,
          })),
        },
        getCustomerSession()?.token,
      );
      const order = response.data;

      // Card flow: order is pending → initiate CMI payment → auto-submit a
      // real HTML form POST to the gateway (3-D Secure happens on CMI's
      // hosted page). Polling/confirmation lives on /payment/[id].
      if (paymentMethod === "card") {
        try {
          const initiation = await initiateCardPayment(
            order.id,
            order.lookup_token,
            getCustomerSession()?.token,
            email.trim(),
          );
          const form = document.createElement("form");
          form.method = "POST";
          form.action = initiation.data.gateway_url;
          form.style.display = "none";
          for (const [key, value] of Object.entries(initiation.data.form_fields)) {
            const input = document.createElement("input");
            input.type = "hidden";
            input.name = key;
            input.value = String(value);
            form.appendChild(input);
          }
          document.body.appendChild(form);
          form.submit();
          return;
        } catch {
          // Order exists (stock reserved) but the gateway could not start —
          // send the customer to the payment page to retry instead of losing
          // the order. The token param preserves guest access.
          const tokenParam = order.lookup_token
            ? `?token=${encodeURIComponent(order.lookup_token)}`
            : "";
          router.push(`/payment/${order.id}${tokenParam}`);
          return;
        }
      }

      // WhatsApp flow: the order is already created (payment_status stays
      // pending) — the chat opens so the store can confirm details + arrange
      // delivery. Both tabs navigate; the confirmation page is the source of
      // truth for the order.
      if (paymentMethod === "whatsapp") {
        const whatsappNumber = publicSettings?.whatsapp_number?.replace(/[^0-9]/g, "");
        window.open(buildWhatsAppLink(buildWhatsAppMessage(order), whatsappNumber), "_blank", "noopener,noreferrer");
      }

      const tokenParam = order.lookup_token ? `&token=${encodeURIComponent(order.lookup_token)}` : "";
      router.push(`/order-confirmation?id=${order.id}${tokenParam}`);
    } catch (error) {
      setServerError(
        error instanceof ApiError
          ? error.message
          : "Something went wrong placing your order. Please try again.",
      );
      setSubmitting(false);
    }
  }

  return (
    <main className="mx-auto w-full max-w-6xl flex-1 px-6 py-10">
      <h1 className="font-serif text-3xl">Checkout</h1>

      <div className="mt-8 grid grid-cols-1 items-start gap-8 lg:grid-cols-[minmax(0,1fr)_320px]">
        <form onSubmit={handleSubmit} noValidate className="space-y-8">
          {customer && (
            <div className="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">
              <p className="font-medium text-green-700">Using your saved information</p>
              <p className="mt-1 text-xs text-neutral-600">
                You can edit any field before placing your order.
              </p>
            </div>
          )}
          <div className="space-y-4">
            <div>
              <label htmlFor="checkout-name" className="text-sm text-neutral-700">
                Full name
              </label>
              <input
                id="checkout-name"
                type="text"
                autoComplete="name"
                value={name}
                onChange={(event) => setName(event.target.value)}
                placeholder="e.g. Yasmine Alaoui"
                className={errors.name ? errorClass : inputClass}
              />
              {errors.name && (
                <p className="mt-1 text-xs text-red-700">{errors.name}</p>
              )}
            </div>
            <div>
              <label htmlFor="checkout-phone" className="text-sm text-neutral-700">
                Phone
              </label>
              <input
                id="checkout-phone"
                type="tel"
                autoComplete="tel"
                value={phone}
                onChange={(event) => setPhone(event.target.value)}
                placeholder="e.g. 06 12 34 56 78"
                className={errors.phone ? errorClass : inputClass}
              />
              {errors.phone && (
                <p className="mt-1 text-xs text-red-700">{errors.phone}</p>
              )}
            </div>
            <div>
              <label htmlFor="checkout-email" className="text-sm text-neutral-700">
                Email
              </label>
              <input
                id="checkout-email"
                type="email"
                autoComplete="email"
                value={email}
                onChange={(event) => setEmail(event.target.value)}
                placeholder="e.g. yasmine@example.com"
                className={errors.email ? errorClass : inputClass}
              />
              {errors.email ? (
                <p className="mt-1 text-xs text-red-700">{errors.email}</p>
              ) : null}
            </div>
            <div>
              <label
                htmlFor="checkout-address"
                className="text-sm text-neutral-700"
              >
                Shipping address
              </label>
              <textarea
                id="checkout-address"
                autoComplete="street-address"
                value={address}
                onChange={(event) => setAddress(event.target.value)}
                placeholder="Street, number, city…"
                rows={3}
                className={errors.address ? errorClass : inputClass}
              />
              {errors.address && (
                <p className="mt-1 text-xs text-red-700">{errors.address}</p>
              )}
            </div>
            <div className="grid grid-cols-2 gap-4">
              <div>
                <label htmlFor="checkout-city" className="text-sm text-neutral-700">
                  City
                </label>
                <input
                  id="checkout-city"
                  type="text"
                  autoComplete="address-level2"
                  value={city}
                  onChange={(event) => setCity(event.target.value)}
                  placeholder="e.g. Casablanca"
                  className={errors.city ? errorClass : inputClass}
                />
                {errors.city && (
                  <p className="mt-1 text-xs text-red-700">{errors.city}</p>
                )}
              </div>
              <div>
                <label htmlFor="checkout-postal" className="text-sm text-neutral-700">
                  Postal code
                </label>
                <input
                  id="checkout-postal"
                  type="text"
                  autoComplete="postal-code"
                  value={postalCode}
                  onChange={(event) => setPostalCode(event.target.value)}
                  placeholder="e.g. 20000"
                  className={errors.postalCode ? errorClass : inputClass}
                />
                {errors.postalCode && (
                  <p className="mt-1 text-xs text-red-700">{errors.postalCode}</p>
                )}
              </div>
            </div>
          </div>

          <section>
            <h2 className="font-serif text-lg">Payment method</h2>
            <div className="mt-4 space-y-3">
              {isCodEnabled && (
                <label
                  className={`flex cursor-pointer items-start gap-3 rounded-lg border p-4 transition-colors ${
                    paymentMethod === "cod"
                      ? "border-neutral-900 bg-neutral-50"
                      : "border-neutral-200 hover:border-neutral-400"
                  }`}
                >
                  <input
                    type="radio"
                    name="payment"
                    value="cod"
                    checked={paymentMethod === "cod"}
                    onChange={() => setPaymentMethod("cod")}
                    className="mt-1 accent-neutral-900"
                  />
                  <span>
                    <span className="block text-sm font-medium text-neutral-900">
                      Cash on Delivery
                    </span>
                    <span className="mt-0.5 block text-xs text-neutral-500">
                      Pay in cash when your order arrives.
                    </span>
                  </span>
                </label>
              )}

              {isWhatsappEnabled && (
                <label
                  className={`flex cursor-pointer items-start gap-3 rounded-lg border p-4 transition-colors ${
                    paymentMethod === "whatsapp"
                      ? "border-neutral-900 bg-neutral-50"
                      : "border-neutral-200 hover:border-neutral-400"
                  }`}
                >
                  <input
                    type="radio"
                    name="payment"
                    value="whatsapp"
                    checked={paymentMethod === "whatsapp"}
                    onChange={() => setPaymentMethod("whatsapp")}
                    className="mt-1 accent-neutral-900"
                  />
                  <span>
                    <span className="block text-sm font-medium text-neutral-900">
                      Order via WhatsApp
                    </span>
                    <span className="mt-0.5 block text-xs text-neutral-500">
                      We send your order details on WhatsApp to confirm and
                      arrange delivery.
                    </span>
                  </span>
                </label>
              )}
              {!isCodEnabled && !isWhatsappEnabled && !isCardAvailable && (
                <p className="text-sm text-red-600">All payment methods are currently disabled. Please contact the store.</p>
              )}

              {isCardAvailable ? (
                <label
                  className={`flex cursor-pointer items-start gap-3 rounded-lg border p-4 transition-colors ${
                    paymentMethod === "card"
                      ? "border-neutral-900 bg-neutral-50"
                      : "border-neutral-200 hover:border-neutral-400"
                  }`}
                >
                  <input
                    type="radio"
                    name="payment"
                    value="card"
                    checked={paymentMethod === "card"}
                    onChange={() => setPaymentMethod("card")}
                    className="mt-1 accent-neutral-900"
                  />
                  <span>
                    <span className="block text-sm font-medium text-neutral-900">
                      Card (CMI/Payzone)
                    </span>
                    <span className="mt-0.5 block text-xs text-neutral-500">
                      Pay securely by card — you will be redirected to CMI to complete 3-D Secure.
                    </span>
                  </span>
                </label>
              ) : (
                <div
                  aria-disabled="true"
                  title={
                    !isCardToggleOn
                      ? "Card payment is disabled by the store"
                      : "Card payment is not configured yet"
                  }
                  className="flex cursor-not-allowed items-start gap-3 rounded-lg border border-neutral-200 bg-neutral-50 p-4 opacity-60"
                >
                  <input
                    type="radio"
                    disabled
                    aria-label="Card payment — currently unavailable"
                    className="mt-1"
                  />
                  <span>
                    <span className="block text-sm font-medium text-neutral-500">
                      Card (CMI/Payzone)
                    </span>
                    <span className="mt-0.5 block text-xs text-neutral-400">
                      {!isCardToggleOn
                        ? "Card payment is currently disabled."
                        : "Card payment is not configured yet — contact the store."}
                    </span>
                  </span>
                </div>
              )}
            </div>
          </section>

          {serverError ? (
            <div
              role="alert"
              className="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900"
            >
              {serverError}
              <Link
                href="/cart"
                className="mt-1 block text-amber-900 underline underline-offset-2"
              >
                Review your cart
              </Link>
            </div>
          ) : null}

          {Object.keys(errors).length > 0 ? (
            <div
              id="checkout-validation-summary"
              role="alert"
              aria-live="assertive"
              className="rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-900"
            >
              <p className="font-medium">Please correct the highlighted fields before placing your order.</p>
              <ul className="mt-2 list-disc space-y-1 pl-5">
                {FIELD_ORDER.filter((key) => key in errors).map((key) => (
                  <li key={key}>
                    <a
                      href={`#${FIELD_IDS[key]}`}
                      onClick={(e) => {
                        e.preventDefault();
                        const el = document.getElementById(FIELD_IDS[key]);
                        if (el) {
                          el.scrollIntoView({ behavior: "smooth", block: "center" });
                          try {
                            (el as HTMLElement).focus({ preventScroll: true } as FocusOptions);
                          } catch {
                            (el as HTMLElement).focus();
                          }
                        }
                      }}
                      className="underline underline-offset-2 hover:text-red-700"
                    >
                      {FIELD_LABELS[key]}: {errors[key]}
                    </a>
                  </li>
                ))}
              </ul>
            </div>
          ) : null}

          <button
            type="submit"
            disabled={submitting || loadingCustomer}
            aria-busy={submitting}
            className="w-full rounded-lg bg-neutral-900 px-6 py-4 text-sm font-medium text-white transition-colors hover:bg-neutral-700 disabled:cursor-not-allowed disabled:bg-neutral-300 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-neutral-900 focus-visible:ring-offset-2"
          >
            {loadingCustomer
              ? "Loading your details…"
              : submitting
                ? "Placing order…"
                : paymentMethod === "whatsapp"
                  ? "Send order via WhatsApp"
                  : paymentMethod === "card"
                    ? `Pay ${formatPrice(estimatedTotal)} by card`
                    : "Place order — pay on delivery"}
          </button>
          <p className="text-center text-xs text-neutral-400">
            By placing this order you agree to be contacted by the store to
            confirm delivery.
          </p>
        </form>

        <aside className="h-fit rounded-lg border border-neutral-200 p-6">
          <h2 className="font-serif text-xl">Order summary</h2>
          <ul className="mt-5 space-y-3">
            {items.map((item) => (
              <li
                key={item.variant_id}
                className="flex items-center justify-between gap-3 text-sm"
              >
                <span className="min-w-0 truncate text-neutral-700">
                  {item.product_name} · {item.size}, {item.color} × {item.quantity}
                </span>
                <span className="shrink-0 tabular-nums text-neutral-900">
                  {formatPrice(item.unit_price * item.quantity)}
                </span>
              </li>
            ))}
          </ul>
          <dl className="mt-5 space-y-2 border-t border-neutral-200 pt-4 text-sm">
            <div className="flex items-center justify-between">
              <dt className="text-neutral-600">Subtotal</dt>
              <dd className="tabular-nums">{formatPrice(totalPrice)}</dd>
            </div>
            <div className="flex items-center justify-between">
              <dt className="text-neutral-600">Shipping</dt>
              <dd className="tabular-nums">
                {estimatedShipping > 0 ? formatPrice(estimatedShipping) : "Free"}
              </dd>
            </div>
            {publicSettings?.free_shipping_threshold &&
            Number(publicSettings.free_shipping_threshold) > 0 ? (
              <p className="text-xs text-neutral-500">
                Free shipping on orders over{" "}
                {formatPrice(Number(publicSettings.free_shipping_threshold))}.
              </p>
            ) : null}
            <div className="flex items-center justify-between border-t border-neutral-200 pt-3">
              <dt className="font-medium text-neutral-900">Total</dt>
              <dd className="font-serif text-lg tabular-nums">
                {formatPrice(estimatedTotal)}
              </dd>
            </div>
          </dl>
          <p className="mt-2 text-xs text-neutral-400">
            Final shipping is confirmed server-side at order time.
          </p>
        </aside>
      </div>
    </main>
  );
}