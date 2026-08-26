"use client";

import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";

import {
  adminRequest,
  clearAdminSession,
  getAdminSession,
} from "@/lib/admin";

type Customer = {
  id: number;
  name: string;
  email: string;
  phone: string | null;
  email_verified: number;
  created_at: string;
};

type CustomersResponse = {
  data: Customer[];
  meta: {
    page: number;
    per_page: number;
    total: number;
    pages: number;
  };
};

type CustomerDetail = Customer & {
  orders: { id: number; customer_name: string; total_amount: string; status: string; payment_status: string; payment_method: string; created_at: string }[];
};

export default function CustomersClient() {
  const router = useRouter();
  const [customers, setCustomers] = useState<Customer[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [page, setPage] = useState(1);
  const [meta, setMeta] = useState<CustomersResponse["meta"] | null>(null);
  const [viewCustomer, setViewCustomer] = useState<CustomerDetail | null>(null);
  const [viewLoading, setViewLoading] = useState(false);

  useEffect(() => {
    async function fetchCustomers(pageNum: number) {
      setLoading(true);
      setError(null);

      try {
        const session = getAdminSession();
        if (session === null) {
          setError("Your session has expired. Please sign in again.");
          clearAdminSession();
          router.replace("/admin/login");
          setLoading(false);
          return;
        }

        const response = await adminRequest(
          `/api/admin/customers?page=${pageNum}&per_page=20`,
          session,
        );

        if (response.status === 401) {
          setError("Your session has expired. Please sign in again.");
          clearAdminSession();
          router.replace("/admin/login");
          return;
        }

        if (!response.ok) {
          throw new Error("Failed to fetch customers");
        }

        const result: CustomersResponse = await response.json();
        setCustomers(result.data);
        setMeta(result.meta);
      } catch (err) {
        setError(err instanceof Error ? err.message : "Failed to load customers");
      } finally {
        setLoading(false);
      }
    }

    fetchCustomers(page);
  }, [page, router]);

  const isViewOpen = viewCustomer !== null;
  function openView(customer: Customer) {
    setViewCustomer({ ...customer, orders: [] } as CustomerDetail);
    setViewLoading(true);
    const session = getAdminSession();
    if (session === null) {
      setViewLoading(false);
      return;
    }
    adminRequest(`/api/admin/customers/${customer.id}`, session)
      .then(async (res) => {
        if (res.ok) {
          const data = await res.json();
          setViewCustomer(data.data);
        }
      })
      .catch(() => {})
      .finally(() => setViewLoading(false));
  }
  function closeView() {
    setViewCustomer(null);
  }
  useEffect(() => {
    function onKeyDown(e: KeyboardEvent) {
      if (e.key === "Escape" && isViewOpen) closeView();
    }
    window.addEventListener("keydown", onKeyDown);
    return () => window.removeEventListener("keydown", onKeyDown);
  }, [isViewOpen]);
  useEffect(() => {
    if (isViewOpen) {
      document.body.style.overflow = "hidden";
    } else {
      document.body.style.overflow = "";
    }
    return () => {
      document.body.style.overflow = "";
    };
  }, [isViewOpen]);

  if (loading) {
    return (
      <div className="p-6">
        <p className="text-sm text-neutral-500">Loading customers...</p>
      </div>
    );
  }

  if (error) {
    return (
      <div className="p-6">
        <p className="text-sm text-red-600">{error}</p>
      </div>
    );
  }

  return (
    <div className="p-6">
      <h1 className="mb-6 text-2xl font-semibold">Customers</h1>

      {customers.length === 0 ? (
        <p className="text-sm text-neutral-500">No customers found.</p>
      ) : (
        <>
          <div className="hidden overflow-x-auto rounded-lg border border-neutral-200 md:block">
            <table className="w-full text-left text-sm">
              <thead className="border-b border-neutral-200 bg-neutral-50">
                <tr>
                  <th className="px-4 py-3 font-medium">Name</th>
                  <th className="px-4 py-3 font-medium">Email</th>
                  <th className="px-4 py-3 font-medium">Phone</th>
                  <th className="px-4 py-3 font-medium">Status</th>
                  <th className="px-4 py-3 font-medium">Joined</th>
                  <th className="px-4 py-3 font-medium">Actions</th>
                </tr>
              </thead>
              <tbody>
                {customers.map((customer) => (
                  <tr key={customer.id} className="border-b border-neutral-100">
                    <td className="px-4 py-3 font-medium">{customer.name}</td>
                    <td className="px-4 py-3 break-words">{customer.email}</td>
                    <td className="px-4 py-3">{customer.phone || "—"}</td>
                    <td className="px-4 py-3">
                      <span
                        className={`inline-block rounded px-2 py-1 text-xs font-medium ${
                          customer.email_verified === 1
                            ? "bg-green-100 text-green-700"
                            : "bg-amber-100 text-amber-700"
                        }`}
                      >
                        {customer.email_verified === 1 ? "Verified" : "Unverified"}
                      </span>
                    </td>
                    <td className="px-4 py-3 text-neutral-600">
                      {new Date(customer.created_at).toLocaleDateString()}
                    </td>
                    <td className="px-4 py-3">
                      <button
                        onClick={() => openView(customer)}
                        className="whitespace-nowrap text-sm text-neutral-600 hover:underline"
                      >
                        View
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div className="grid gap-4 md:hidden">
            {customers.map((customer) => (
              <div
                key={customer.id}
                className="rounded-lg border border-neutral-200 bg-white p-4"
              >
                <div className="flex items-start justify-between gap-3">
                  <p className="font-medium leading-tight">{customer.name}</p>
                  <span
                    className={`shrink-0 rounded px-2 py-1 text-xs font-medium ${
                      customer.email_verified === 1
                        ? "bg-green-100 text-green-700"
                        : "bg-amber-100 text-amber-700"
                    }`}
                  >
                    {customer.email_verified === 1 ? "Verified" : "Unverified"}
                  </span>
                </div>
                <dl className="mt-3 space-y-2 text-sm">
                  <div>
                    <dt className="text-xs font-medium uppercase tracking-wide text-neutral-500">
                      Email
                    </dt>
                    <dd className="mt-1 break-words break-all text-neutral-900">
                      {customer.email}
                    </dd>
                  </div>
                  <div className="flex justify-between gap-3">
                    <dt className="text-neutral-500">Phone</dt>
                    <dd className="font-medium">{customer.phone || "—"}</dd>
                  </div>
                  <div className="flex justify-between gap-3">
                    <dt className="text-neutral-500">Joined</dt>
                    <dd className="text-neutral-600">
                      {new Date(customer.created_at).toLocaleDateString()}
                    </dd>
                  </div>
                </dl>
                <button
                  onClick={() => openView(customer)}
                  className="mt-4 w-full rounded-md border border-neutral-300 px-3 py-1.5 text-sm font-medium text-neutral-700 hover:bg-neutral-50"
                >
                  View
                </button>
              </div>
            ))}
          </div>

          {meta && meta.pages > 1 && (
            <div className="mt-4 flex items-center justify-between">
              <p className="text-sm text-neutral-600">
                Page {meta.page} of {meta.pages} ({meta.total} total)
              </p>
              <div className="flex gap-2">
                <button
                  onClick={() => setPage((p) => Math.max(1, p - 1))}
                  disabled={page === 1}
                  className="rounded border border-neutral-300 px-3 py-1 text-sm disabled:opacity-50"
                >
                  Previous
                </button>
                <button
                  onClick={() => setPage((p) => Math.min(meta.pages, p + 1))}
                  disabled={page === meta.pages}
                  className="rounded border border-neutral-300 px-3 py-1 text-sm disabled:opacity-50"
                >
                  Next
                </button>
              </div>
            </div>
          )}
        </>
      )}

      {isViewOpen && viewCustomer && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
          <button
            type="button"
            aria-label="Close customer details"
            onClick={closeView}
            className="absolute inset-0 bg-black/40"
          />
          <div className="relative max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-xl bg-white p-6 shadow-xl">
            <div className="flex items-start justify-between gap-4">
              <div>
                <h2 className="font-serif text-xl">{viewCustomer.name}</h2>
                <p className="mt-1 break-all text-sm text-neutral-500">{viewCustomer.email}</p>
              </div>
              <button
                type="button"
                aria-label="Close"
                onClick={closeView}
                className="rounded-md p-1 text-neutral-500 hover:bg-neutral-100 hover:text-neutral-900"
              >
                <svg className="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.5} aria-hidden="true">
                  <path d="M6 6L18 18M18 6L6 18" strokeLinecap="round" />
                </svg>
              </button>
            </div>
            <dl className="mt-4 space-y-3 text-sm">
              <div className="flex justify-between gap-4">
                <dt className="text-neutral-500">Phone</dt>
                <dd className="font-medium">{viewCustomer.phone || "—"}</dd>
              </div>
              <div className="flex justify-between gap-4">
                <dt className="text-neutral-500">Status</dt>
                <dd>
                  <span className={`rounded px-2 py-1 text-xs font-medium ${viewCustomer.email_verified === 1 ? "bg-green-100 text-green-700" : "bg-amber-100 text-amber-700"}`}>
                    {viewCustomer.email_verified === 1 ? "Verified" : "Unverified"}
                  </span>
                </dd>
              </div>
              <div className="flex justify-between gap-4">
                <dt className="text-neutral-500">Joined</dt>
                <dd>{new Date(viewCustomer.created_at).toLocaleString()}</dd>
              </div>
              <div className="flex justify-between gap-4">
                <dt className="text-neutral-500">Email</dt>
                <dd className="break-all text-right">{viewCustomer.email}</dd>
              </div>
            </dl>
            {viewLoading ? (
              <p className="mt-4 text-sm text-neutral-500">Loading orders…</p>
            ) : viewCustomer.orders && viewCustomer.orders.length > 0 ? (
              <div className="mt-6">
                <h3 className="text-sm font-medium">Orders ({viewCustomer.orders.length})</h3>
                <ul className="mt-2 divide-y divide-neutral-100 rounded-lg border border-neutral-200">
                  {viewCustomer.orders.map((order) => (
                    <li key={order.id} className="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                      <span>#{order.id} · {order.status}</span>
                      <span className="font-medium">{order.total_amount} MAD</span>
                    </li>
                  ))}
                </ul>
              </div>
            ) : (
              !viewLoading && <p className="mt-4 text-sm text-neutral-500">No orders for this customer.</p>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
