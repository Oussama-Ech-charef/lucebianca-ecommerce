import type { Metadata } from "next";
import Link from "next/link";
import { cache } from "react";

import { ApiError } from "@/lib/api";
import { fetchOrder, formatPrice } from "@/lib/storefront";
import type { OrderDetail } from "@/lib/types";
import ClearCart from "./clear-cart";
import GuestRegistrationPrompt from "./guest-registration-prompt";

type PageProps = { searchParams: Promise<{ id?: string | string[]; token?: string | string[] }> };

// Shared by generateMetadata and the page so the API is hit once per request.
// Guest orders require the single-purpose lookup token; owned orders allow
// either token or authenticated ownership (server component has no auth here,
// so we rely on token for guest).
const getOrder = cache(
  async (id: string, token?: string): Promise<OrderDetail | null> => {
    try {
      const response = await fetchOrder(id, token);
      return response.data;
    } catch (error) {
      if (error instanceof ApiError && error.status === 404) {
        return null;
      }
      throw error;
    }
  },
);

function PaymentStatus({ method, status }: { method: string; status: string }) {
  const methodLabel =
    method === "cod"
      ? "Cash on Delivery"
      : method === "whatsapp"
        ? "Order via WhatsApp"
        : method === "card"
          ? "Card (CMI/Payzone)"
          : method;
  // Business rule: every method starts pending; paid only after card callback
  // success or explicit admin confirmation (COD/WhatsApp included).
  const statusLabel =
    status === "pending"
      ? "Pending confirmation"
      : status === "paid"
        ? "Paid"
        : status === "failed"
          ? "Failed"
          : status;
  return (
    <p className="mt-1 text-sm text-neutral-500">
      {methodLabel} · {statusLabel}
    </p>
  );
}

function NotFoundState() {
  return (
    <main className="mx-auto w-full max-w-3xl flex-1 px-6 py-16 text-center">
      <h1 className="font-serif text-3xl">Order not found</h1>
      <p className="mt-3 text-sm text-neutral-500">
        We couldn&apos;t find that order. If you just placed one, check the
        link from your confirmation.
      </p>
      <Link
        href="/shop"
        className="mt-8 inline-block rounded-lg bg-neutral-900 px-6 py-3 text-sm font-medium text-white transition-colors hover:bg-neutral-700"
      >
        Continue shopping
      </Link>
    </main>
  );
}

export async function generateMetadata({ searchParams }: PageProps): Promise<Metadata> {
  const { id, token } = await searchParams;
  const value = Array.isArray(id) ? id[0] : id;
  const tokenValue = Array.isArray(token) ? token[0] : token;
  if (!value) {
    return { title: "Order not found", robots: { index: false, follow: false } };
  }
  const order = await getOrder(value, tokenValue);
  return {
    title: order ? `Order #${order.id} — confirmed` : "Order not found",
    robots: { index: false, follow: false },
  };
}

export default async function ConfirmationPage({ searchParams }: PageProps) {
  const { id, token } = await searchParams;
  const value = Array.isArray(id) ? id[0] : id;
  const tokenValue = Array.isArray(token) ? token[0] : token;

  if (!value) {
    return <NotFoundState />;
  }

  const order = await getOrder(value, tokenValue);
  if (!order) {
    return <NotFoundState />;
  }

  const firstName = order.customer_name.trim().split(" ")[0] || "friend";
  const placedAt = new Date(order.created_at);

  return (
    <main className="mx-auto w-full max-w-3xl flex-1 px-6 py-12">
      <ClearCart />

      <p className="text-xs uppercase tracking-[0.3em] text-neutral-500">
        Order confirmed
      </p>
      <h1 className="mt-2 font-serif text-3xl">Thank you, {firstName}!</h1>
      <p className="mt-2 text-sm text-neutral-600">
        Order #{order.id} ·{" "}
        {placedAt.toLocaleString("en-GB", {
          day: "numeric",
          month: "long",
          year: "numeric",
          hour: "2-digit",
          minute: "2-digit",
        })}
      </p>
      <PaymentStatus method={order.payment_method} status={order.payment_status} />

      <section className="mt-10">
        <h2 className="font-serif text-xl">Your items</h2>
        <ul className="mt-4 divide-y divide-neutral-200 border-y border-neutral-200">
          {order.items.map((item) => (
            <li key={item.id} className="flex items-start justify-between gap-4 py-4">
              <div className="min-w-0">
                <Link
                  href={`/product/${item.product_slug}`}
                  className="font-serif text-base text-neutral-900 hover:underline"
                >
                  {item.product_name}
                </Link>
                <p className="mt-0.5 text-sm text-neutral-500">
                  {item.size} · {item.color} · SKU {item.sku}
                </p>
                <p className="mt-0.5 text-sm text-neutral-500">
                  {formatPrice(item.price_at_purchase)} × {item.quantity}
                </p>
              </div>
              <p className="shrink-0 text-sm font-medium tabular-nums">
                {formatPrice(Number(item.price_at_purchase) * item.quantity)}
              </p>
            </li>
          ))}
        </ul>
        <div className="mt-4 space-y-2 border-t border-neutral-200 pt-4 text-sm">
          <div className="flex items-center justify-between">
            <span className="text-neutral-600">Subtotal</span>
            <span className="tabular-nums">
              {formatPrice(order.subtotal_amount ?? order.total_amount)}
            </span>
          </div>
          {Number(order.discount_amount ?? 0) > 0 ? (
            <div className="flex items-center justify-between">
              <span className="text-neutral-600">
                Discount{order.coupon_code ? ` (${order.coupon_code})` : ""}
              </span>
              <span className="tabular-nums text-green-700">
                −{formatPrice(order.discount_amount)}
              </span>
            </div>
          ) : null}
          <div className="flex items-center justify-between">
            <span className="text-neutral-600">Shipping</span>
            <span className="tabular-nums">
              {Number(order.shipping_cost ?? "0") > 0
                ? formatPrice(order.shipping_cost ?? "0")
                : "Free"}
            </span>
          </div>
          <div className="flex items-center justify-between border-t border-neutral-200 pt-3">
            <span className="font-medium text-neutral-900">Total</span>
            <span className="font-serif text-xl tabular-nums">
              {formatPrice(order.total_amount)}
            </span>
          </div>
        </div>
      </section>

      <section className="mt-10 rounded-lg border border-neutral-200 p-6">
        <h2 className="font-serif text-lg">Delivery details</h2>
        <p className="mt-3 text-sm text-neutral-700">{order.customer_name}</p>
        <p className="mt-1 text-sm text-neutral-700">{order.phone}</p>
        <p className="mt-1 whitespace-pre-line text-sm text-neutral-700">
          {order.shipping_address}
          {[order.city, order.postal_code].filter(Boolean).length > 0
            ? `\n${[order.city, order.postal_code].filter(Boolean).join(", ")}`
            : null}
        </p>
        <p className="mt-4 text-xs text-neutral-500">
          {order.payment_method === "whatsapp"
            ? "We've opened a WhatsApp chat with your order details — the store will confirm payment and delivery there."
            : order.payment_method === "card"
              ? order.payment_status === "paid"
                ? "Your card payment was confirmed. We'll email you when your order ships."
                : "Complete your card payment from the payment page — your items stay reserved for 30 minutes."
              : "The store will contact you on this phone number to confirm delivery. Payment is collected on delivery or confirmation."}
        </p>
        {order.payment_method === "card" && order.payment_status !== "paid" ? (
          <Link
            href={
              tokenValue
                ? `/payment/${order.id}?token=${encodeURIComponent(tokenValue)}`
                : `/payment/${order.id}`
            }
            className="mt-4 inline-block rounded-lg bg-neutral-900 px-6 py-3 text-sm font-medium text-white hover:bg-neutral-700"
          >
            Complete card payment
          </Link>
        ) : null}
      </section>

      {/* Guests only — hidden once a customer session exists; dismissing
          never affects the order details above. */}
      <GuestRegistrationPrompt />

      <Link
        href="/shop"
        className="mt-10 inline-block rounded-lg bg-neutral-900 px-6 py-3 text-sm font-medium text-white transition-colors hover:bg-neutral-700"
      >
        Continue shopping
      </Link>
    </main>
  );
}