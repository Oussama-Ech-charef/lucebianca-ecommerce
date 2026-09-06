"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useCallback, useEffect, useState } from "react";

import {
  adminRequest,
  clearAdminSession,
  getAdminSession,
} from "@/lib/admin";
import { formatPrice } from "@/lib/storefront";
import type { OrderStatus } from "@/lib/types";

// ---------- Types mirroring DashboardController.php ----------

type Overview = {
  total_sales: string | number | null;
  total_orders: number | null;
  total_customers: number | null;
};

type SalesPoint = {
  date: string;
  order_count: number | null;
  sales: string | number | null;
};

type TopProduct = {
  id: number;
  name: string;
  slug: string;
  units_sold: number;
  revenue: string | number | null;
};

type RecentOrder = {
  id: number;
  customer_name: string;
  total_amount: string;
  status: OrderStatus;
  payment_status: string;
  payment_method: string;
  created_at: string;
};

type DashboardStats = {
  overview: Overview;
  order_status_breakdown: Record<string, number>;
  top_products: TopProduct[];
  recent_orders: RecentOrder[];
  sales_chart: SalesPoint[];
};

// ---------- Helpers ----------

const STATUS_LABELS: Record<string, string> = {
  pending: "Pending",
  processing: "Processing",
  shipped: "Shipped",
  delivered: "Delivered",
  cancelled: "Cancelled",
};

const STATUS_STYLES: Record<string, string> = {
  pending: "border-amber-200 bg-amber-50 text-amber-700",
  processing: "border-blue-200 bg-blue-50 text-blue-700",
  shipped: "border-violet-200 bg-violet-50 text-violet-700",
  delivered: "border-green-200 bg-green-50 text-green-700",
  cancelled: "border-neutral-300 bg-neutral-100 text-neutral-600",
};

function formatDate(value: string): string {
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return value;
  return d.toLocaleString("en-GB", {
    day: "numeric",
    month: "short",
    year: "numeric",
  });
}

function formatChartDate(value: string): string {
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return value;
  return d.toLocaleDateString("en-GB", { month: "short", day: "numeric" });
}

function toNumber(value: string | number | null | undefined): number {
  if (value === null || value === undefined || value === "") return 0;
  const n = Number(value);
  return Number.isFinite(n) && n >= 0 ? n : 0;
}

// ---------- Lightweight accessible SVG chart ----------

function SalesChart({ data }: { data: SalesPoint[] }) {
  // Normalize: ensure sales values never crash even when null/zero/invalid.
  const points = data.map((p) => ({
    date: p.date,
    sales: toNumber(p.sales),
    order_count: typeof p.order_count === "number" && Number.isFinite(p.order_count) ? p.order_count : 0,
  }));

  if (points.length === 0) {
    return (
      <p className="py-10 text-center text-sm text-neutral-500">
        No sales data for the last 30 days.
      </p>
    );
  }

  const values = points.map((p) => p.sales);
  const max = Math.max(...values, 0);
  const hasSales = max > 0;

  if (!hasSales) {
    return (
      <div className="py-8 text-center">
        <p className="text-sm text-neutral-500">No sales recorded in this period.</p>
        <p className="mt-1 text-xs text-neutral-400">
          Sales appear here once realized orders are recorded.
        </p>
      </div>
    );
  }

  // Dimensions: responsive SVG with viewBox; wrapper allows horizontal scroll on small screens.
  const width = 800;
  const height = 220;
  const padLeft = 48;
  const padRight = 16;
  const padTop = 20;
  const padBottom = 36;
  const chartW = width - padLeft - padRight;
  const chartH = height - padTop - padBottom;

  // Compute coordinates; handle single point gracefully.
  const xFor = (i: number) =>
    padLeft + (points.length === 1 ? chartW / 2 : (chartW * i) / (points.length - 1));
  const yFor = (v: number) => padTop + chartH - (v / max) * chartH;

  const pathD = points
    .map((p, i) => `${i === 0 ? "M" : "L"} ${xFor(i).toFixed(2)} ${yFor(p.sales).toFixed(2)}`)
    .join(" ");

  const areaD =
    points.length > 0
      ? `${pathD} L ${xFor(points.length - 1).toFixed(2)} ${(padTop + chartH).toFixed(2)} L ${xFor(0).toFixed(2)} ${(padTop + chartH).toFixed(2)} Z`
      : "";

  // Y-axis ticks
  const ticks = 4;
  const tickValues = Array.from({ length: ticks + 1 }, (_, i) => (max * i) / ticks);

  // Decide label density: show all if <= 12, otherwise every nth to avoid crowding.
  const labelStep = points.length > 15 ? 3 : points.length > 10 ? 2 : 1;

  return (
    <div className="w-full">
      {/* Accessible data table for screen readers */}
      <table className="sr-only">
        <caption>Sales by date, last 30 days</caption>
        <thead>
          <tr>
            <th scope="col">Date</th>
            <th scope="col">Sales</th>
            <th scope="col">Orders</th>
          </tr>
        </thead>
        <tbody>
          {points.map((p) => (
            <tr key={p.date}>
              <td>{p.date}</td>
              <td>{formatPrice(p.sales)}</td>
              <td>{p.order_count}</td>
            </tr>
          ))}
        </tbody>
      </table>

      <div className="overflow-x-auto">
        <div className="min-w-[520px] sm:min-w-0">
          <svg
            role="img"
            aria-label="Sales chart for the last 30 days. Each point shows daily realized sales."
            viewBox={`0 0 ${width} ${height}`}
            className="h-[220px] w-full"
            preserveAspectRatio="xMidYMid meet"
          >
            {/* Grid lines */}
            {tickValues.map((v, i) => {
              const y = yFor(v);
              return (
                <g key={i}>
                  <line
                    x1={padLeft}
                    x2={width - padRight}
                    y1={y}
                    y2={y}
                    stroke="#e5e5e5"
                    strokeWidth={i === 0 ? 1.2 : 0.8}
                    strokeDasharray={i === 0 ? undefined : "3 4"}
                  />
                  <text
                    x={padLeft - 8}
                    y={y + 4}
                    textAnchor="end"
                    fontSize="10"
                    fill="#737373"
                  >
                    {v === 0
                      ? "0"
                      : v >= 1000
                        ? `${(v / 1000).toFixed(v >= 10000 ? 0 : 1)}k`
                        : String(Math.round(v))}
                  </text>
                </g>
              );
            })}

            {/* Area fill */}
            <path d={areaD} fill="rgba(23,23,23,0.06)" stroke="none" />

            {/* Line */}
            <path d={pathD} fill="none" stroke="#171717" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" />

            {/* Points and value labels */}
            {points.map((p, i) => {
              const x = xFor(i);
              const y = yFor(p.sales);
              const showLabel = i % labelStep === 0 || i === points.length - 1;
              return (
                <g key={p.date + i}>
                  {/* Vertical guide on hover focus? keep subtle */}
                  <line
                    x1={x}
                    x2={x}
                    y1={padTop}
                    y2={padTop + chartH}
                    stroke="transparent"
                    strokeWidth={Math.max(12, chartW / points.length)}
                  >
                    <title>{`${formatChartDate(p.date)}: ${formatPrice(p.sales)} (${p.order_count} order${p.order_count === 1 ? "" : "s"})`}</title>
                  </line>
                  <circle cx={x} cy={y} r={p.sales === max ? 3.5 : 2.8} fill="#171717" stroke="white" strokeWidth={1.2}>
                    <title>{`${formatChartDate(p.date)}: ${formatPrice(p.sales)} — ${p.order_count} order${p.order_count === 1 ? "" : "s"}`}</title>
                  </circle>
                  {showLabel ? (
                    <text
                      x={x}
                      y={padTop + chartH + 14}
                      textAnchor={i === 0 ? "start" : i === points.length - 1 ? "end" : "middle"}
                      fontSize="10"
                      fill="#525252"
                    >
                      {formatChartDate(p.date)}
                    </text>
                  ) : null}
                </g>
              );
            })}

            {/* Y-axis label hint */}
            <text x={padLeft} y={padTop - 6} fontSize="10" fill="#a3a3a3" className="uppercase tracking-wide">
              MAD
            </text>
          </svg>
        </div>
      </div>

      <p className="mt-2 text-xs text-neutral-500">
        Showing daily realized sales for the last 30 days. Hover or focus points to see values. Scroll horizontally on mobile if needed.
      </p>
    </div>
  );
}

// ---------- Main dashboard client ----------

export default function DashboardClient() {
  const router = useRouter();
  const [stats, setStats] = useState<DashboardStats | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const redirectToLogin = useCallback(() => {
    clearAdminSession();
    router.replace("/admin/login");
  }, [router]);

  useEffect(() => {
    let cancelled = false;

    async function run() {
      const session = getAdminSession();
      if (session === null) {
        redirectToLogin();
        return;
      }
      try {
        const response = await adminRequest("/api/admin/dashboard-stats", session);
        if (cancelled) return;
        if (response.status === 401) {
          redirectToLogin();
          return;
        }
        if (!response.ok) {
          const body = await response.json().catch(() => null) as { error?: string } | null;
          throw new Error(body?.error || `Failed to load dashboard (${response.status})`);
        }
        const result = (await response.json()) as { data: DashboardStats } & DashboardStats;
        if (cancelled) return;
        // DashboardController wraps in { data: stats }, but handle both shapes for resilience.
        const maybeWrapped = result as { data?: DashboardStats };
        const rawPayload =
          maybeWrapped.data && typeof maybeWrapped.data === "object" && "overview" in maybeWrapped.data
            ? maybeWrapped.data
            : (result as unknown as DashboardStats);
        if (!rawPayload || typeof rawPayload !== "object" || !("overview" in rawPayload)) {
          throw new Error("Dashboard data is unavailable.");
        }
        setStats(rawPayload);
        setError(null);
      } catch (err) {
        if (cancelled) return;
        setError(err instanceof Error ? err.message : "Failed to load dashboard.");
      } finally {
        if (!cancelled) setLoading(false);
      }
    }

    run();
    return () => {
      cancelled = true;
    };
  }, [redirectToLogin]);

  if (loading) {
    return (
      <div>
        <div className="flex flex-col gap-1">
          <h1 className="font-serif text-2xl">Dashboard</h1>
          <p className="text-sm text-neutral-500">Overview of sales, orders, and recent activity</p>
        </div>

        <div className="mt-6 grid gap-4 sm:grid-cols-3">
          {[0, 1, 2].map((i) => (
            <div key={i} className="animate-pulse rounded-lg border border-neutral-200 bg-white p-5">
              <div className="h-3 w-20 rounded bg-neutral-100" />
              <div className="mt-3 h-7 w-24 rounded bg-neutral-100" />
              <div className="mt-2 h-3 w-16 rounded bg-neutral-100" />
            </div>
          ))}
        </div>

        <div className="mt-6 rounded-lg border border-neutral-200 bg-white p-4 sm:p-5">
          <div className="h-4 w-32 rounded bg-neutral-100" />
          <div className="mt-6 h-40 rounded bg-neutral-100" />
        </div>

        <p className="py-10 text-center text-sm text-neutral-500">Loading dashboard…</p>
      </div>
    );
  }

  if (error !== null) {
    return (
      <div>
        <div className="flex flex-col gap-1">
          <h1 className="font-serif text-2xl">Dashboard</h1>
          <p className="text-sm text-neutral-500">Overview of sales, orders, and recent activity</p>
        </div>
        <div
          role="alert"
          className="mt-6 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"
        >
          <p className="font-medium">Failed to load dashboard</p>
          <p className="mt-1">{error}</p>
          <button
            type="button"
            onClick={() => window.location.reload()}
            className="mt-3 rounded-md border border-red-300 bg-white px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-50"
          >
            Retry
          </button>
        </div>
      </div>
    );
  }

  if (stats === null) {
    return (
      <div>
        <div className="flex flex-col gap-1">
          <h1 className="font-serif text-2xl">Dashboard</h1>
          <p className="text-sm text-neutral-500">Overview of sales, orders, and recent activity</p>
        </div>
        <p className="mt-10 text-center text-sm text-neutral-500">No dashboard data available.</p>
      </div>
    );
  }

  const overview = stats.overview ?? { total_sales: "0", total_orders: 0, total_customers: 0 };
  const totalSales = toNumber(overview.total_sales);
  const totalOrders = typeof overview.total_orders === "number" ? overview.total_orders : toNumber(overview.total_orders);
  const totalCustomers = typeof overview.total_customers === "number" ? overview.total_customers : toNumber(overview.total_customers);

  const statusEntries = Object.entries(stats.order_status_breakdown ?? {});
  const totalStatusCount = statusEntries.reduce((sum, [, c]) => sum + (Number.isFinite(c) ? c : 0), 0);

  const topProducts = Array.isArray(stats.top_products) ? stats.top_products : [];
  const recentOrders = Array.isArray(stats.recent_orders) ? stats.recent_orders : [];
  const salesChart = Array.isArray(stats.sales_chart) ? stats.sales_chart : [];

  return (
    <div>
      <div className="flex flex-col gap-1">
        <h1 className="font-serif text-2xl">Dashboard</h1>
        <p className="text-sm text-neutral-500">Overview of sales, orders, and recent activity</p>
      </div>

      {/* Summary cards */}
      <div className="mt-6 grid gap-4 sm:grid-cols-3">
        <div className="rounded-lg border border-neutral-200 bg-white p-5">
          <p className="text-xs font-medium uppercase tracking-wide text-neutral-500">Total sales</p>
          <p className="mt-2 font-serif text-2xl tabular-nums text-neutral-900">{formatPrice(totalSales)}</p>
          <p className="mt-1 text-xs text-neutral-500">Realized orders</p>
        </div>
        <div className="rounded-lg border border-neutral-200 bg-white p-5">
          <p className="text-xs font-medium uppercase tracking-wide text-neutral-500">Total orders</p>
          <p className="mt-2 font-serif text-2xl tabular-nums text-neutral-900">{totalOrders}</p>
          <p className="mt-1 text-xs text-neutral-500">Realized orders</p>
        </div>
        <div className="rounded-lg border border-neutral-200 bg-white p-5">
          <p className="text-xs font-medium uppercase tracking-wide text-neutral-500">Total customers</p>
          <p className="mt-2 font-serif text-2xl tabular-nums text-neutral-900">{totalCustomers}</p>
          <p className="mt-1 text-xs text-neutral-500">Registered accounts</p>
        </div>
      </div>

      {/* Sales chart */}
      <section className="mt-6 rounded-lg border border-neutral-200 bg-white p-4 sm:p-5" aria-labelledby="sales-chart-heading">
        <div className="flex items-baseline justify-between gap-4">
          <h2 id="sales-chart-heading" className="text-sm font-medium text-neutral-900">
            Sales — last 30 days
          </h2>
          <span className="hidden text-xs text-neutral-500 sm:inline">Realized orders · {salesChart.length} day{salesChart.length === 1 ? "" : "s"}</span>
          <span className="text-xs text-neutral-500 sm:hidden">{salesChart.length}d</span>
        </div>
        <div className="mt-4">
          <SalesChart data={salesChart} />
        </div>
      </section>

      {/* Middle row: status + top products */}
      <div className="mt-6 grid gap-6 lg:grid-cols-2">
        {/* Order status breakdown */}
        <section className="rounded-lg border border-neutral-200 bg-white p-4 sm:p-5" aria-labelledby="status-heading">
          <h2 id="status-heading" className="text-sm font-medium text-neutral-900">
            Orders by status
          </h2>
          {statusEntries.length === 0 ? (
            <p className="mt-6 py-6 text-center text-sm text-neutral-500">No orders yet.</p>
          ) : (
            <>
              <ul className="mt-4 space-y-3" aria-label="Order status breakdown">
                {statusEntries
                  .sort((a, b) => b[1] - a[1])
                  .map(([status, count]) => {
                    const pct = totalStatusCount > 0 ? Math.round((count / totalStatusCount) * 100) : 0;
                    return (
                      <li key={status} className="flex items-center gap-3">
                        <span className={`inline-flex min-w-24 justify-center rounded border px-2 py-1 text-xs font-medium ${STATUS_STYLES[status] ?? "border-neutral-200 bg-neutral-50 text-neutral-700"}`}>
                          {STATUS_LABELS[status] ?? status}
                        </span>
                        <div className="flex flex-1 items-center gap-3">
                          <div className="h-2 flex-1 overflow-hidden rounded-full bg-neutral-100">
                            <div
                              className="h-full rounded-full bg-neutral-900 transition-all"
                              style={{ width: `${pct}%` }}
                              aria-hidden="true"
                            />
                          </div>
                          <span className="shrink-0 text-sm tabular-nums text-neutral-700">
                            {count}
                            <span className="ml-1 text-xs text-neutral-500">({pct}%)</span>
                          </span>
                        </div>
                      </li>
                    );
                  })}
              </ul>
              <p className="mt-4 text-xs text-neutral-500">{totalStatusCount} total order{totalStatusCount === 1 ? "" : "s"}</p>
            </>
          )}
        </section>

        {/* Top products */}
        <section className="rounded-lg border border-neutral-200 bg-white p-4 sm:p-5" aria-labelledby="top-products-heading">
          <div className="flex items-baseline justify-between gap-4">
            <h2 id="top-products-heading" className="text-sm font-medium text-neutral-900">
              Top-selling products
            </h2>
            <Link href="/admin/products" className="text-xs font-medium text-neutral-600 hover:text-neutral-900 hover:underline">
              View products
            </Link>
          </div>
          {topProducts.length === 0 ? (
            <p className="mt-6 py-6 text-center text-sm text-neutral-500">
              No sales yet. Top products will appear once orders are realized.
            </p>
          ) : (
            <ul className="mt-4 divide-y divide-neutral-100 rounded-lg border border-neutral-200">
              {topProducts.map((product, idx) => (
                <li key={product.id} className="flex items-start justify-between gap-3 px-3 py-3">
                  <div className="min-w-0 flex gap-3">
                    <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-neutral-900 text-xs font-medium text-white" aria-hidden="true">
                      {idx + 1}
                    </span>
                    <div className="min-w-0">
                      <p className="truncate text-sm font-medium text-neutral-900">{product.name}</p>
                      <p className="truncate text-xs text-neutral-500">/{product.slug}</p>
                      <p className="mt-1 text-xs text-neutral-600">
                        {product.units_sold} unit{product.units_sold === 1 ? "" : "s"} sold
                      </p>
                    </div>
                  </div>
                  <span className="shrink-0 text-sm font-medium tabular-nums text-neutral-900">{formatPrice(toNumber(product.revenue))}</span>
                </li>
              ))}
            </ul>
          )}
        </section>
      </div>

      {/* Recent orders */}
      <section className="mt-6 rounded-lg border border-neutral-200 bg-white" aria-labelledby="recent-orders-heading">
        <div className="flex items-center justify-between gap-4 border-b border-neutral-200 px-4 py-3 sm:px-5">
          <h2 id="recent-orders-heading" className="text-sm font-medium text-neutral-900">
            Recent orders
          </h2>
          <Link href="/admin/orders" className="text-xs font-medium text-neutral-600 hover:text-neutral-900 hover:underline">
            View all
          </Link>
        </div>

        {recentOrders.length === 0 ? (
          <p className="px-4 py-10 text-center text-sm text-neutral-500 sm:px-5">
            No recent orders.
          </p>
        ) : (
          <>
            {/* Desktop table */}
            <div className="hidden overflow-x-auto md:block">
              <table className="w-full text-left text-sm">
                <thead className="border-b border-neutral-200 bg-neutral-50">
                  <tr className="text-xs uppercase tracking-wide text-neutral-500">
                    <th className="px-4 py-3 font-medium">Order</th>
                    <th className="px-4 py-3 font-medium">Customer</th>
                    <th className="px-4 py-3 font-medium">Total</th>
                    <th className="px-4 py-3 font-medium">Status</th>
                    <th className="px-4 py-3 font-medium">Date</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-neutral-100">
                  {recentOrders.map((order) => (
                    <tr key={order.id} className="hover:bg-neutral-50/60">
                      <td className="px-4 py-3 font-medium text-neutral-900">#{order.id}</td>
                      <td className="px-4 py-3 text-neutral-700">{order.customer_name}</td>
                      <td className="px-4 py-3 font-medium tabular-nums">{formatPrice(order.total_amount)}</td>
                      <td className="px-4 py-3">
                        <span className={`inline-block rounded border px-2 py-0.5 text-xs font-medium ${STATUS_STYLES[order.status] ?? "border-neutral-200 bg-neutral-50 text-neutral-700"}`}>
                          {STATUS_LABELS[order.status] ?? order.status}
                        </span>
                      </td>
                      <td className="px-4 py-3 text-neutral-600">{formatDate(order.created_at)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            {/* Mobile cards */}
            <div className="space-y-3 p-4 md:hidden">
              {recentOrders.map((order) => (
                <div key={order.id} className="rounded-lg border border-neutral-200 bg-white p-3">
                  <div className="flex items-start justify-between gap-3">
                    <p className="font-medium text-neutral-900">#{order.id}</p>
                    <span className={`inline-block rounded border px-2 py-0.5 text-xs font-medium ${STATUS_STYLES[order.status] ?? "border-neutral-200 bg-neutral-50 text-neutral-700"}`}>
                      {STATUS_LABELS[order.status] ?? order.status}
                    </span>
                  </div>
                  <dl className="mt-2 space-y-1 text-sm">
                    <div className="flex justify-between gap-3">
                      <dt className="text-neutral-500">Customer</dt>
                      <dd className="font-medium">{order.customer_name}</dd>
                    </div>
                    <div className="flex justify-between gap-3">
                      <dt className="text-neutral-500">Total</dt>
                      <dd className="font-medium tabular-nums">{formatPrice(order.total_amount)}</dd>
                    </div>
                    <div className="flex justify-between gap-3">
                      <dt className="text-neutral-500">Date</dt>
                      <dd className="text-neutral-600">{formatDate(order.created_at)}</dd>
                    </div>
                  </dl>
                </div>
              ))}
            </div>
          </>
        )}
      </section>
    </div>
  );
}
