"use client";

import { useRouter } from "next/navigation";
import { useCallback, useEffect, useState } from "react";

import {
  clearAdminSession,
  fetchAdminOrderDetail,
  fetchAdminOrders,
  getAdminSession,
  updateAdminOrder,
} from "@/lib/admin";
import { ApiError } from "@/lib/api";
import { formatPrice } from "@/lib/storefront";
import type {
  AdminOrder,
  OrderDetail,
  OrderStatus,
  PaymentStatus,
} from "@/lib/types";

const STATUS_OPTIONS: OrderStatus[] = [
  "pending",
  "processing",
  "shipped",
  "delivered",
  "cancelled",
];

const STATUS_LABELS: Record<OrderStatus, string> = {
  pending: "Pending",
  processing: "Processing",
  shipped: "Shipped",
  delivered: "Delivered",
  cancelled: "Cancelled",
};

const STATUS_STYLES: Record<OrderStatus, string> = {
  pending: "border-amber-200 bg-amber-50 text-amber-700",
  processing: "border-blue-200 bg-blue-50 text-blue-700",
  shipped: "border-violet-200 bg-violet-50 text-violet-700",
  delivered: "border-green-200 bg-green-50 text-green-700",
  cancelled: "border-neutral-300 bg-neutral-100 text-neutral-600",
};

const PAYMENT_LABELS: Record<string, string> = {
  card: "Card",
  cod: "Cash on Delivery",
  whatsapp: "WhatsApp",
};

const PAYMENT_STATUS_LABELS: Record<PaymentStatus, string> = {
  pending: "Pending",
  paid: "Paid",
  failed: "Failed",
};

function formatDate(value: string): string {
  return new Date(value).toLocaleString("en-GB", {
    day: "numeric",
    month: "short",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
}

function StatusBadge({ status }: { status: OrderStatus }) {
  return (
    <span
      className={`inline-block rounded border px-2 py-0.5 text-xs font-medium ${STATUS_STYLES[status]}`}
    >
      {STATUS_LABELS[status]}
    </span>
  );
}

function OrderItems({ detail }: { detail: OrderDetail }) {
  return (
    <div className="text-sm">
      <ul className="divide-y divide-neutral-100">
        {detail.items.map((item) => (
          <li
            key={item.id}
            className="flex items-start justify-between gap-3 py-2"
          >
            <div className="min-w-0">
              <p className="font-medium text-neutral-900">{item.product_name}</p>
              <p className="text-xs text-neutral-500">
                {item.size} · {item.color} · SKU {item.sku}
              </p>
              <p className="text-xs text-neutral-500">
                {formatPrice(item.price_at_purchase)} × {item.quantity}
              </p>
            </div>
            <p className="shrink-0 font-medium tabular-nums">
              {formatPrice(Number(item.price_at_purchase) * item.quantity)}
            </p>
          </li>
        ))}
      </ul>
      <div className="mt-1 flex items-center justify-between border-t border-neutral-200 pt-2">
        <span className="text-neutral-600">Total</span>
        <span className="font-serif text-base tabular-nums">
          {formatPrice(detail.total_amount)}
        </span>
      </div>
      <p className="mt-2 whitespace-pre-line text-xs text-neutral-500">
        {detail.shipping_address}
      </p>
    </div>
  );
}

type Meta = { page: number; per_page: number; total: number; pages: number };

/**
 * /admin/orders — list, filter, paginate and change order status.
 *
 * Desktop (lg+) renders a table; below lg it renders stacked cards so the
 * data never horizontally overflows a phone viewport (spec 3.5 mobile-first).
 * Row details use the protected admin GET /api/admin/orders/{id} (admin JWT,
 * same join as the customer confirmation page, no lookup_token). Status changes
 * PUT to the admin API and update the row in place without a page reload.
 */
export default function OrdersClient() {
  const router = useRouter();
  const [orders, setOrders] = useState<AdminOrder[]>([]);
  const [meta, setMeta] = useState<Meta | null>(null);
  const [page, setPage] = useState(1);
  const [statusFilter, setStatusFilter] = useState<OrderStatus | "">("");
  const [loading, setLoading] = useState(true);
  const [listError, setListError] = useState<string | null>(null);

  const [selectedOrder, setSelectedOrder] = useState<AdminOrder | null>(null);
  const [detail, setDetail] = useState<OrderDetail | null>(null);
  const [detailLoading, setDetailLoading] = useState(false);
  const [detailError, setDetailError] = useState<string | null>(null);
  const [isModalOpen, setIsModalOpen] = useState(false);

  const [updatingId, setUpdatingId] = useState<number | null>(null);
  const [updateError, setUpdateError] = useState<string | null>(null);

  const redirectToLogin = useCallback(() => {
    clearAdminSession();
    router.replace("/admin/login");
  }, [router]);

  // loading starts true for the initial fetch; manual page/filter changes flip
  // it back on before the effect re-runs (avoiding synchronous setState inside
  // the effect body — react-hooks/set-state-in-effect).
  const changePage = (next: number) => {
    setLoading(true);
    setPage(next);
  };

  const changeFilter = (status: OrderStatus | "") => {
    setLoading(true);
    setStatusFilter(status);
    setPage(1);
  };

  useEffect(() => {
    let cancelled = false;

    // Inline async fetch (React-docs pattern): every setState happens after
    // an await, so the effect body itself never calls setState synchronously.
    async function run() {
      const token = getAdminSession()?.token;
      if (!token) {
        redirectToLogin();
        return;
      }
      try {
        const response = await fetchAdminOrders(
          { page, status: statusFilter },
          token,
        );
        if (cancelled) {
          return;
        }
        setOrders(response.data);
        setMeta(response.meta);
        setDetail(null);
      } catch (error) {
        if (cancelled) {
          return;
        }
        if (error instanceof ApiError && error.status === 401) {
          redirectToLogin();
          return;
        }
        setListError(
          error instanceof Error ? error.message : "Failed to load orders.",
        );
      } finally {
        if (!cancelled) {
          setLoading(false);
        }
      }
    }

    run();
    return () => {
      cancelled = true;
    };
  }, [page, statusFilter, redirectToLogin]);

  async function handleStatusChange(orderId: number, status: OrderStatus) {
    const token = getAdminSession()?.token;
    if (!token) {
      redirectToLogin();
      return;
    }
    setUpdatingId(orderId);
    setUpdateError(null);
    try {
      const response = await updateAdminOrder(orderId, { status }, token);
      setOrders((current) =>
        current.map((order) =>
          order.id === orderId
            ? {
                ...order,
                status: response.data.status,
                payment_status: response.data.payment_status,
              }
            : order,
        ),
      );
      setDetail((current) =>
        current !== null && current.id === orderId
          ? { ...current, status: response.data.status }
          : current,
      );
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) {
        redirectToLogin();
        return;
      }
      setUpdateError(
        error instanceof Error
          ? error.message
          : "Failed to update the order status.",
      );
    } finally {
      setUpdatingId(null);
    }
  }

  async function openOrderDetail(order: AdminOrder) {
    setSelectedOrder(order);
    setIsModalOpen(true);
    setDetail(null);
    setDetailLoading(true);
    setDetailError(null);
    try {
      const token = getAdminSession()?.token;
      if (!token) {
        redirectToLogin();
        return;
      }
      const response = await fetchAdminOrderDetail(order.id, token);
      setDetail(response.data);
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) {
        redirectToLogin();
        return;
      }
      setDetailError(
        error instanceof Error ? error.message : "Failed to load order items.",
      );
    } finally {
      setDetailLoading(false);
    }
  }

  function closeModal() {
    setIsModalOpen(false);
    setDetail(null);
    setDetailError(null);
  }

  useEffect(() => {
    function onKeyDown(event: KeyboardEvent) {
      if (event.key === "Escape" && isModalOpen) {
        closeModal();
      }
    }
    window.addEventListener("keydown", onKeyDown);
    return () => window.removeEventListener("keydown", onKeyDown);
  }, [isModalOpen]);

  useEffect(() => {
    if (isModalOpen) {
      document.body.style.overflow = "hidden";
    } else {
      document.body.style.overflow = "";
    }
    return () => {
      document.body.style.overflow = "";
    };
  }, [isModalOpen]);

  const statusSelect = (order: AdminOrder, compact = false) => (
    <select
      aria-label={`Status for order #${order.id}`}
      value={order.status}
      disabled={updatingId === order.id}
      onChange={(event) =>
        handleStatusChange(order.id, event.target.value as OrderStatus)
      }
      className={`select-field rounded-md border border-neutral-300 bg-white px-2 py-1.5 text-sm outline-none transition-colors focus:border-neutral-900 focus:ring-1 focus:ring-neutral-900 disabled:cursor-not-allowed disabled:opacity-60 ${
        compact ? "w-full" : ""
      }`}
    >
      {STATUS_OPTIONS.map((status) => (
        <option key={status} value={status}>
          {STATUS_LABELS[status]}
        </option>
      ))}
    </select>
  );

  const paymentText = (order: AdminOrder) => (
    <span className="text-sm text-neutral-700">
      {PAYMENT_LABELS[order.payment_method] ?? order.payment_method}
      <span className="text-neutral-400"> · </span>
      {PAYMENT_STATUS_LABELS[order.payment_status]}
    </span>
  );

  const emptyState = () => {
    if (statusFilter !== "") {
      return (
        <div className="py-16 text-center">
          <p className="text-neutral-600">
            No orders with status{" "}
            <span className="font-medium">{STATUS_LABELS[statusFilter]}</span>.
          </p>
          <button
            type="button"
            onClick={() => changeFilter("")}
            className="mt-4 rounded-md border border-neutral-300 px-4 py-2 text-sm font-medium text-neutral-700 transition-colors hover:border-neutral-900 hover:text-neutral-900"
          >
            Show all orders
          </button>
        </div>
      );
    }
    return (
      <div className="py-16 text-center">
        <p className="font-serif text-lg text-neutral-700">No orders yet</p>
        <p className="mt-1 text-sm text-neutral-500">
          Orders placed through the storefront will appear here.
        </p>
      </div>
    );
  };

  return (
    <div>
      <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <h1 className="font-serif text-2xl">Orders</h1>
          <p className="mt-0.5 text-sm text-neutral-500">
            {meta !== null
              ? `${meta.total} order${meta.total === 1 ? "" : "s"}`
              : "Manage customer orders"}
          </p>
        </div>
        <label className="flex items-center gap-2 text-sm text-neutral-600">
          <span className="shrink-0">Status</span>
          <select
            aria-label="Filter orders by status"
            value={statusFilter}
            onChange={(event) =>
              changeFilter(event.target.value as OrderStatus | "")
            }
            className="select-field rounded-md border border-neutral-300 bg-white px-2 py-1.5 text-sm outline-none transition-colors focus:border-neutral-900 focus:ring-1 focus:ring-neutral-900"
          >
            <option value="">All statuses</option>
            {STATUS_OPTIONS.map((status) => (
              <option key={status} value={status}>
                {STATUS_LABELS[status]}
              </option>
            ))}
          </select>
        </label>
      </div>

      {updateError !== null && (
        <p
          role="alert"
          className="mt-4 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700"
        >
          {updateError}
        </p>
      )}

      {listError !== null && (
        <p
          role="alert"
          className="mt-4 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700"
        >
          {listError}
        </p>
      )}

      {loading ? (
        <p className="py-16 text-center text-sm text-neutral-500">
          Loading orders…
        </p>
      ) : orders.length === 0 ? (
        emptyState()
      ) : (
        <>
          {/* Desktop table (lg+) */}
          <div className="mt-6 hidden overflow-x-auto rounded-lg border border-neutral-200 bg-white lg:block">
            <table className="w-full border-collapse text-left">
              <thead>
                <tr className="border-b border-neutral-200 text-xs uppercase tracking-wider text-neutral-500">
                  <th className="px-4 py-3 font-medium">Order</th>
                  <th className="px-4 py-3 font-medium">Customer</th>
                  <th className="px-4 py-3 font-medium">Total</th>
                  <th className="px-4 py-3 font-medium">Payment</th>
                  <th className="px-4 py-3 font-medium">Date</th>
                  <th className="px-4 py-3 font-medium">Status</th>
                  <th className="px-4 py-3 font-medium">
                    <span className="sr-only">Details</span>
                  </th>
                </tr>
              </thead>
              <tbody className="divide-y divide-neutral-100">
                {orders.map((order) => (
                  <tr key={order.id}>
                    <td className="px-4 py-3 align-top">
                      <p className="font-medium text-neutral-900">#{order.id}</p>
                      <p className="text-xs text-neutral-500">
                        {order.item_count} item{order.item_count === 1 ? "" : "s"}
                      </p>
                    </td>
                    <td className="px-4 py-3 align-top">
                      <p className="text-neutral-900">{order.customer_name}</p>
                      <p className="text-sm text-neutral-500">{order.phone}</p>
                    </td>
                    <td className="px-4 py-3 align-top font-medium tabular-nums">
                      {formatPrice(order.total_amount)}
                    </td>
                    <td className="px-4 py-3 align-top">{paymentText(order)}</td>
                    <td className="px-4 py-3 align-top text-sm text-neutral-600">
                      {formatDate(order.created_at)}
                    </td>
                    <td className="px-4 py-3 align-top">
                      <div className="flex flex-col gap-2">
                        <StatusBadge status={order.status} />
                        {statusSelect(order)}
                      </div>
                    </td>
                    <td className="px-4 py-3 align-top">
                      <button
                        type="button"
                        onClick={() => openOrderDetail(order)}
                        className="text-sm font-medium text-neutral-700 underline-offset-4 hover:underline"
                      >
                        View
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {/* Mobile/tablet stacked cards (< lg) */}
          <div className="mt-6 space-y-4 lg:hidden">
            {orders.map((order) => (
              <article
                key={order.id}
                className="rounded-lg border border-neutral-200 bg-white p-4"
              >
                <div className="flex items-start justify-between gap-3">
                  <div>
                    <p className="font-serif text-base">#{order.id}</p>
                    <p className="mt-0.5 text-sm text-neutral-500">
                      {formatDate(order.created_at)}
                    </p>
                  </div>
                  <StatusBadge status={order.status} />
                </div>

                <dl className="mt-3 space-y-1.5 text-sm">
                  <div className="flex items-baseline justify-between gap-4">
                    <dt className="text-neutral-500">Customer</dt>
                    <dd className="text-right">
                      {order.customer_name}
                      <span className="block text-xs text-neutral-500">
                        {order.phone}
                      </span>
                    </dd>
                  </div>
                  <div className="flex items-baseline justify-between gap-4">
                    <dt className="text-neutral-500">Items</dt>
                    <dd>
                      {order.item_count} item{order.item_count === 1 ? "" : "s"}
                    </dd>
                  </div>
                  <div className="flex items-baseline justify-between gap-4">
                    <dt className="text-neutral-500">Payment</dt>
                    <dd className="text-right">{paymentText(order)}</dd>
                  </div>
                  <div className="flex items-baseline justify-between gap-4">
                    <dt className="text-neutral-500">Total</dt>
                    <dd className="font-medium tabular-nums">
                      {formatPrice(order.total_amount)}
                    </dd>
                  </div>
                </dl>

                <div className="mt-4 flex items-center justify-between gap-3">
                  <label className="flex flex-1 items-center gap-2 text-sm text-neutral-600">
                    <span className="shrink-0">Status</span>
                    {statusSelect(order, true)}
                  </label>
                  <button
                    type="button"
                    onClick={() => openOrderDetail(order)}
                    className="shrink-0 text-sm font-medium text-neutral-700 underline-offset-4 hover:underline"
                  >
                    View
                  </button>
                </div>
              </article>
            ))}
          </div>

          {/* Pagination */}
          {meta !== null && meta.pages > 1 && (
            <div className="mt-6 flex items-center justify-between gap-4">
              <button
                type="button"
                disabled={meta.page <= 1}
                onClick={() => changePage(meta.page - 1)}
                className="rounded-md border border-neutral-300 bg-white px-4 py-2 text-sm font-medium text-neutral-700 transition-colors hover:border-neutral-900 hover:text-neutral-900 disabled:cursor-not-allowed disabled:opacity-50"
              >
                Previous
              </button>
              <p className="text-sm text-neutral-500">
                Page {meta.page} of {meta.pages}
              </p>
              <button
                type="button"
                disabled={meta.page >= meta.pages}
                onClick={() => changePage(meta.page + 1)}
                className="rounded-md border border-neutral-300 bg-white px-4 py-2 text-sm font-medium text-neutral-700 transition-colors hover:border-neutral-900 hover:text-neutral-900 disabled:cursor-not-allowed disabled:opacity-50"
              >
                Next
              </button>
            </div>
          )}
        </>
      )}

      {isModalOpen && selectedOrder && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
          <button
            type="button"
            aria-label="Close order details"
            onClick={closeModal}
            className="absolute inset-0 bg-black/40"
          />
          <div className="relative max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-xl bg-white p-6 shadow-xl">
            <div className="flex items-start justify-between gap-4">
              <div>
                <h2 className="font-serif text-xl">Order #{selectedOrder.id}</h2>
                <p className="mt-1 text-sm text-neutral-500">
                  {formatDate(selectedOrder.created_at)}
                </p>
                <div className="mt-2">
                  <StatusBadge status={selectedOrder.status} />
                </div>
              </div>
              <button
                type="button"
                aria-label="Close"
                onClick={closeModal}
                className="rounded-md p-1 text-neutral-500 hover:bg-neutral-100 hover:text-neutral-900"
              >
                <svg
                  className="h-5 w-5"
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  strokeWidth={1.5}
                  aria-hidden="true"
                >
                  <path d="M6 6L18 18M18 6L6 18" strokeLinecap="round" />
                </svg>
              </button>
            </div>

            {detailLoading ? (
              <p className="py-6 text-sm text-neutral-500">Loading details…</p>
            ) : detailError ? (
              <p role="alert" className="mt-4 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                {detailError}
              </p>
            ) : detail ? (
              <div className="mt-6 space-y-6">
                <div className="grid gap-4 sm:grid-cols-2">
                  <div className="rounded-lg border border-neutral-200 p-4">
                    <h3 className="text-xs font-medium uppercase tracking-wide text-neutral-500">
                      Customer
                    </h3>
                    <p className="mt-2 font-medium text-neutral-900">{detail.customer_name}</p>
                    <p className="text-sm text-neutral-600">{detail.phone}</p>
                    {detail.email ? (
                      <p className="text-sm text-neutral-600">{detail.email}</p>
                    ) : null}
                  </div>
                  <div className="rounded-lg border border-neutral-200 p-4">
                    <h3 className="text-xs font-medium uppercase tracking-wide text-neutral-500">
                      Payment & Status
                    </h3>
                    <p className="mt-2 text-sm">
                      <span className="text-neutral-500">Method:</span>{" "}
                      {PAYMENT_LABELS[detail.payment_method] ?? detail.payment_method}
                    </p>
                    <p className="text-sm">
                      <span className="text-neutral-500">Payment:</span>{" "}
                      {PAYMENT_STATUS_LABELS[detail.payment_status]}
                    </p>
                    <p className="text-sm">
                      <span className="text-neutral-500">Order status:</span> {STATUS_LABELS[detail.status]}
                    </p>
                    <p className="text-sm">
                      <span className="text-neutral-500">Date:</span> {formatDate(detail.created_at)}
                    </p>
                  </div>
                </div>

                <div className="rounded-lg border border-neutral-200 p-4">
                  <h3 className="text-xs font-medium uppercase tracking-wide text-neutral-500">
                    Shipping address
                  </h3>
                  <p className="mt-2 whitespace-pre-line text-sm text-neutral-700">
                    {detail.shipping_address}
                  </p>
                </div>

                <div>
                  <h3 className="text-sm font-medium">Items</h3>
                  <div className="mt-3">
                    <OrderItems detail={detail} />
                  </div>
                </div>
              </div>
            ) : null}
          </div>
        </div>
      )}
    </div>
  );
}