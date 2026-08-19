import type { Metadata } from "next";
import Link from "next/link";
import { cache } from "react";

import { ApiError } from "@/lib/api";
import { fetchOrder, formatPrice } from "@/lib/storefront";
import type { OrderDetail } from "@/lib/types";
import ClearCart from "./clear-cart";

type PageProps = { searchParams: Promise<{ id?: string | string[] }> };

// Shared by generateMetadata and the page so the API is hit once per request.
const getOrder = cache(
  async (id: string): Promise<OrderDetail | null> => {
    try {
      const response = await fetchOrder(id);
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
        : method;
  const statusLabel = status === "pending" ? "Pending confirmation" : status;
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
  const { id } = await searchParams;
  const value = Array.isArray(id) ? id[0] : id;
  if (!value) {
    return { title: "Order not found" };
  }
  const order = await getOrder(value);
  return {
    title: order ? `Order #${order.id} — confirmed` : "Order not found",
  };
}

export default async function ConfirmationPage({ searchParams }: PageProps) {
  const { id } = await searchParams;
  const value = Array.isArray(id) ? id[0] : id;

  if (!value) {
    return <NotFoundState />;
  }

  const order = await getOrder(value);
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
        <div className="mt-4 flex items-center justify-between">
          <span className="text-sm text-neutral-600">Total</span>
          <span className="font-serif text-xl tabular-nums">
            {formatPrice(order.total_amount)}
          </span>
        </div>
      </section>

      <section className="mt-10 rounded-lg border border-neutral-200 p-6">
        <h2 className="font-serif text-lg">Delivery details</h2>
        <p className="mt-3 text-sm text-neutral-700">{order.customer_name}</p>
        <p className="mt-1 text-sm text-neutral-700">{order.phone}</p>
        <p className="mt-1 whitespace-pre-line text-sm text-neutral-700">
          {order.shipping_address}
        </p>
        <p className="mt-4 text-xs text-neutral-500">
          {order.payment_method === "whatsapp"
            ? "We've opened a WhatsApp chat with your order details — the store will confirm payment and delivery there."
            : "The store will contact you on this phone number to confirm delivery."}
        </p>
      </section>

      <Link
        href="/shop"
        className="mt-10 inline-block rounded-lg bg-neutral-900 px-6 py-3 text-sm font-medium text-white transition-colors hover:bg-neutral-700"
      >
        Continue shopping
      </Link>
    </main>
  );
}