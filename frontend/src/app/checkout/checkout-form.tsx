"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useRef, useState } from "react";

import { ApiError } from "@/lib/api";
import { useCart } from "@/lib/cart-context";
import { getCustomerSession } from "@/lib/customer-auth";
import { formatPrice, placeOrder, validateCart } from "@/lib/storefront";
import type { OrderDetail } from "@/lib/types";
import { buildWhatsAppLink } from "@/lib/whatsapp";

type PaymentMethod = "cod" | "whatsapp";

const inputClass =
  "mt-1 block w-full rounded-lg border border-neutral-300 px-3 py-3 text-sm text-neutral-900 placeholder:text-neutral-400 focus:border-neutral-900 focus:outline-none";
const errorClass =
  "mt-1 block w-full rounded-lg border border-red-400 px-3 py-3 text-sm text-neutral-900 placeholder:text-neutral-400 focus:border-red-500 focus:outline-none";

function paymentLabel(method: string): string {
  if (method === "cod") return "Cash on Delivery";
  if (method === "whatsapp") return "Order via WhatsApp";
  return method;
}

function buildWhatsAppMessage(order: OrderDetail): string {
  const lines = order.items
    .map(
      (item) =>
        `- ${item.product_name} (${item.size}, ${item.color}) × ${item.quantity} — ${formatPrice(Number(item.price_at_purchase) * item.quantity)}`,
    )
    .join("\n");
  return [
    `Hello Luce Bianca! I'd like to confirm my order.`,
    ``,
    `Order #${order.id}`,
    lines,
    ``,
    `Total: ${formatPrice(order.total_amount)}`,
    ``,
    `Name: ${order.customer_name}`,
    `Phone: ${order.phone}`,
    `Address: ${order.shipping_address}`,
    ``,
    `Payment: ${paymentLabel(order.payment_method)}`,
  ].join("\n");
}

export default function CheckoutForm() {
  const router = useRouter();
  const { items, totalPrice } = useCart();

  const [name, setName] = useState("");
  const [phone, setPhone] = useState("");
  const [address, setAddress] = useState("");
  const [paymentMethod, setPaymentMethod] = useState<PaymentMethod>("cod");
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [serverError, setServerError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const redirectingRef = useRef(false);

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

  function validate(): boolean {
    const next: Record<string, string> = {};
    if (name.trim().length < 2) {
      next.name = "Please enter your full name.";
    }
    if (!/^[0-9+()\-\s]{8,20}$/.test(phone.trim())) {
      next.phone = "Please enter a valid phone number.";
    }
    if (address.trim().length < 5) {
      next.address = "Please enter your shipping address.";
    }
    setErrors(next);
    return Object.keys(next).length === 0;
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
          shipping_address: address.trim(),
          payment_method: paymentMethod,
          items: items.map((item) => ({
            variant_id: item.variant_id,
            quantity: item.quantity,
          })),
        },
        getCustomerSession()?.token,
      );
      const order = response.data;

      // WhatsApp flow: the order is already created (payment_status stays
      // pending) — the chat opens so the store can confirm details + arrange
      // delivery. Both tabs navigate; the confirmation page is the source of
      // truth for the order.
      if (paymentMethod === "whatsapp") {
        window.open(buildWhatsAppLink(buildWhatsAppMessage(order)), "_blank", "noopener,noreferrer");
      }

      router.push(`/order-confirmation?id=${order.id}`);
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
          <section>
            <h2 className="font-serif text-lg">Contact & delivery</h2>
            <div className="mt-4 space-y-4">
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
                {errors.name ? (
                  <p className="mt-1 text-xs text-red-700">{errors.name}</p>
                ) : null}
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
                {errors.phone ? (
                  <p className="mt-1 text-xs text-red-700">{errors.phone}</p>
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
                {errors.address ? (
                  <p className="mt-1 text-xs text-red-700">{errors.address}</p>
                ) : null}
              </div>
            </div>
          </section>

          <section>
            <h2 className="font-serif text-lg">Payment method</h2>
            <div className="mt-4 space-y-3">
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

              <div
                aria-disabled="true"
                className="flex cursor-not-allowed items-start gap-3 rounded-lg border border-neutral-200 bg-neutral-50 p-4 opacity-60"
              >
                <input
                  type="radio"
                  disabled
                  aria-label="Card payment — coming soon"
                  className="mt-1"
                />
                <span>
                  <span className="block text-sm font-medium text-neutral-500">
                    Card (CMI/Payzone)
                  </span>
                  <span className="mt-0.5 block text-xs text-neutral-400">
                    Card payments arrive in a later phase — coming soon.
                  </span>
                </span>
              </div>
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

          <button
            type="submit"
            disabled={submitting}
            className="w-full rounded-lg bg-neutral-900 px-6 py-4 text-sm font-medium text-white transition-colors hover:bg-neutral-700 disabled:cursor-not-allowed disabled:bg-neutral-300"
          >
            {submitting
              ? "Placing order…"
              : paymentMethod === "whatsapp"
                ? "Send order via WhatsApp"
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
          <div className="mt-5 flex items-center justify-between border-t border-neutral-200 pt-4">
            <span className="text-sm text-neutral-600">Total</span>
            <span className="font-serif text-lg tabular-nums">
              {formatPrice(totalPrice)}
            </span>
          </div>
        </aside>
      </div>
    </main>
  );
}