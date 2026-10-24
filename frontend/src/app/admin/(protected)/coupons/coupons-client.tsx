"use client";

import { useRouter } from "next/navigation";
import { useCallback, useEffect, useState } from "react";

import {
  adminRequest,
  clearAdminSession,
  getAdminSession,
} from "@/lib/admin";
import type { Coupon } from "@/lib/types";

type FormState = {
  code: string;
  discount_type: "percentage" | "fixed";
  discount_value: string;
  expires_at: string;
  is_active: boolean;
};

const EMPTY_FORM: FormState = {
  code: "",
  discount_type: "percentage",
  discount_value: "",
  expires_at: "",
  is_active: true,
};

function toDateTimeLocal(value: string | null): string {
  if (!value) {
    return "";
  }
  // MySQL DATETIME ("YYYY-MM-DD HH:MM:SS") → datetime-local input value.
  return value.replace(" ", "T").slice(0, 16);
}

function formatExpiry(value: string | null): string {
  if (!value) {
    return "No expiry";
  }
  const date = new Date(value.replace(" ", "T"));
  if (Number.isNaN(date.getTime())) {
    return value;
  }
  return date.toLocaleString("en-GB", {
    day: "numeric",
    month: "short",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
}

function isExpired(coupon: Coupon): boolean {
  if (!coupon.expires_at) {
    return false;
  }
  const date = new Date(coupon.expires_at.replace(" ", "T"));
  return !Number.isNaN(date.getTime()) && date.getTime() <= Date.now();
}

function discountLabel(coupon: Coupon): string {
  return coupon.discount_type === "percentage"
    ? `${Number(coupon.discount_value)}%`
    : `${Number(coupon.discount_value).toFixed(2)} MAD`;
}

async function readErrorMessage(
  response: Response,
  fallback: string,
): Promise<string> {
  const data = (await response.json().catch(() => null)) as
    | { error?: string; errors?: Record<string, string> }
    | null;
  const firstFieldError = data?.errors
    ? Object.values(data.errors)[0]
    : undefined;
  return firstFieldError ?? data?.error ?? fallback;
}

export default function CouponsClient() {
  const router = useRouter();
  const [coupons, setCoupons] = useState<Coupon[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  const [form, setForm] = useState<FormState>(EMPTY_FORM);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [deletingId, setDeletingId] = useState<number | null>(null);
  // Bumped after create/delete to refetch the list.
  const [reloadKey, setReloadKey] = useState(0);

  const handleSessionExpired = useCallback(() => {
    clearAdminSession();
    router.replace("/admin/login");
  }, [router]);

  // Data loads inline so every state update happens in a promise continuation
  // (react-hooks/set-state-in-effect). A missing session needs no local
  // handling — the admin shell already redirects logged-out admins.
  useEffect(() => {
    const session = getAdminSession();
    if (session === null) {
      return;
    }

    let cancelled = false;
    adminRequest("/api/admin/coupons", session)
      .then(async (response) => {
        if (cancelled) return;
        if (response.status === 401) {
          handleSessionExpired();
          return;
        }
        if (!response.ok) {
          throw new Error(
            await readErrorMessage(response, "Failed to load coupons."),
          );
        }
        const result = (await response.json()) as { data: Coupon[] };
        if (cancelled) return;
        setCoupons(result.data ?? []);
        setError(null);
      })
      .catch((err: unknown) => {
        if (!cancelled) {
          setError(
            err instanceof Error ? err.message : "Failed to load coupons.",
          );
        }
      })
      .finally(() => {
        if (!cancelled) {
          setLoading(false);
        }
      });

    return () => {
      cancelled = true;
    };
  }, [reloadKey, handleSessionExpired]);

  function resetForm() {
    setForm(EMPTY_FORM);
    setEditingId(null);
  }

  function startEdit(coupon: Coupon) {
    setEditingId(coupon.id);
    setForm({
      code: coupon.code,
      discount_type: coupon.discount_type,
      discount_value: coupon.discount_value,
      expires_at: toDateTimeLocal(coupon.expires_at),
      is_active: coupon.is_active === 1,
    });
    setError(null);
    setSuccess(null);
    window.scrollTo({ top: 0, behavior: "smooth" });
  }

  async function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError(null);
    setSuccess(null);

    const session = getAdminSession();
    if (session === null) {
      handleSessionExpired();
      return;
    }

    setSubmitting(true);
    try {
      const payload = {
        code: form.code.trim().toUpperCase(),
        discount_type: form.discount_type,
        discount_value: form.discount_value.trim(),
        expires_at: form.expires_at === "" ? null : form.expires_at,
        is_active: form.is_active,
      };

      const response = await adminRequest(
        editingId
          ? `/api/admin/coupons/${editingId}`
          : "/api/admin/coupons",
        session,
        { method: editingId ? "PUT" : "POST", body: payload },
      );

      if (response.status === 401) {
        handleSessionExpired();
        return;
      }
      if (!response.ok) {
        setError(
          await readErrorMessage(response, "Failed to save the coupon."),
        );
        return;
      }

      setSuccess(editingId ? "Coupon updated." : "Coupon created.");
      resetForm();
      setReloadKey((key) => key + 1);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to save the coupon.");
    } finally {
      setSubmitting(false);
    }
  }

  async function handleDelete(coupon: Coupon) {
    if (
      !window.confirm(
        `Delete coupon "${coupon.code}"? Existing orders keep their discount snapshot.`,
      )
    ) {
      return;
    }

    const session = getAdminSession();
    if (session === null) {
      handleSessionExpired();
      return;
    }

    setDeletingId(coupon.id);
    setError(null);
    setSuccess(null);
    try {
      const response = await adminRequest(
        `/api/admin/coupons/${coupon.id}`,
        session,
        { method: "DELETE" },
      );
      if (response.status === 401) {
        handleSessionExpired();
        return;
      }
      if (!response.ok) {
        setError(
          await readErrorMessage(response, "Failed to delete the coupon."),
        );
        return;
      }
      if (editingId === coupon.id) {
        resetForm();
      }
      setSuccess(`Coupon "${coupon.code}" deleted.`);
      setReloadKey((key) => key + 1);
    } catch (err) {
      setError(
        err instanceof Error ? err.message : "Failed to delete the coupon.",
      );
    } finally {
      setDeletingId(null);
    }
  }

  const inputClass =
    "mt-1 block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 placeholder:text-neutral-400 focus:border-neutral-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-neutral-900 focus-visible:ring-offset-1";
  const labelClass = "block text-sm font-medium text-neutral-700";

  return (
    <div>
      <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <h1 className="font-serif text-2xl">Coupons</h1>
          <p className="mt-0.5 text-sm text-neutral-500">
            Create discount codes applied at checkout. Discounts apply to
            merchandise only, never to shipping.
          </p>
        </div>
      </div>

      {error ? (
        <p
          role="alert"
          className="mt-4 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700"
        >
          {error}
        </p>
      ) : null}
      {success ? (
        <p
          role="status"
          className="mt-4 rounded-md border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-700"
        >
          {success}
        </p>
      ) : null}

      <div className="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_340px]">
        <section className="rounded-lg border border-neutral-200 bg-white">
          <div className="border-b border-neutral-200 px-4 py-3">
            <h2 className="text-sm font-medium text-neutral-900">
              All coupons
            </h2>
          </div>

          {loading ? (
            <p className="px-4 py-10 text-center text-sm text-neutral-500">
              Loading coupons…
            </p>
          ) : coupons.length === 0 ? (
            <div className="px-4 py-12 text-center">
              <p className="font-serif text-lg text-neutral-700">
                No coupons yet
              </p>
              <p className="mt-1 text-sm text-neutral-500">
                Create a code to offer a discount at checkout.
              </p>
            </div>
          ) : (
            <>
              {/* Desktop table */}
              <div className="hidden overflow-x-auto sm:block">
                <table className="w-full border-collapse text-left text-sm">
                  <thead>
                    <tr className="border-b border-neutral-200 text-xs uppercase tracking-wider text-neutral-500">
                      <th className="px-4 py-3 font-medium">Code</th>
                      <th className="px-4 py-3 font-medium">Discount</th>
                      <th className="px-4 py-3 font-medium">Expires</th>
                      <th className="px-4 py-3 font-medium">Status</th>
                      <th className="px-4 py-3 font-medium">
                        <span className="sr-only">Actions</span>
                      </th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-neutral-100">
                    {coupons.map((coupon) => {
                      const expired = isExpired(coupon);
                      return (
                        <tr key={coupon.id}>
                          <td className="px-4 py-3 font-medium text-neutral-900">
                            {coupon.code}
                          </td>
                          <td className="px-4 py-3 tabular-nums text-neutral-700">
                            {discountLabel(coupon)}
                          </td>
                          <td className="px-4 py-3 text-neutral-600">
                            {formatExpiry(coupon.expires_at)}
                          </td>
                          <td className="px-4 py-3">
                            <span
                              className={`inline-block rounded border px-2 py-0.5 text-xs font-medium ${
                                expired
                                  ? "border-amber-200 bg-amber-50 text-amber-700"
                                  : coupon.is_active === 1
                                    ? "border-green-200 bg-green-50 text-green-700"
                                    : "border-neutral-300 bg-neutral-100 text-neutral-600"
                              }`}
                            >
                              {expired
                                ? "Expired"
                                : coupon.is_active === 1
                                  ? "Active"
                                  : "Inactive"}
                            </span>
                          </td>
                          <td className="px-4 py-3">
                            <div className="flex items-center justify-end gap-2">
                              <button
                                type="button"
                                onClick={() => startEdit(coupon)}
                                className="rounded-md border border-neutral-300 px-3 py-1.5 text-xs font-medium text-neutral-700 transition-colors hover:border-neutral-900 hover:text-neutral-900"
                              >
                                Edit
                              </button>
                              <button
                                type="button"
                                onClick={() => void handleDelete(coupon)}
                                disabled={deletingId === coupon.id}
                                className="rounded-md border border-red-200 px-3 py-1.5 text-xs font-medium text-red-700 transition-colors hover:border-red-600 hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-50"
                              >
                                {deletingId === coupon.id ? "Deleting…" : "Delete"}
                              </button>
                            </div>
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>

              {/* Mobile cards */}
              <ul className="divide-y divide-neutral-100 sm:hidden">
                {coupons.map((coupon) => {
                  const expired = isExpired(coupon);
                  return (
                    <li key={coupon.id} className="px-4 py-4">
                      <div className="flex items-start justify-between gap-3">
                        <div>
                          <p className="font-medium text-neutral-900">
                            {coupon.code}
                          </p>
                          <p className="mt-0.5 text-sm text-neutral-600">
                            {discountLabel(coupon)} · {formatExpiry(coupon.expires_at)}
                          </p>
                        </div>
                        <span
                          className={`inline-block shrink-0 rounded border px-2 py-0.5 text-xs font-medium ${
                            expired
                              ? "border-amber-200 bg-amber-50 text-amber-700"
                              : coupon.is_active === 1
                                ? "border-green-200 bg-green-50 text-green-700"
                                : "border-neutral-300 bg-neutral-100 text-neutral-600"
                          }`}
                        >
                          {expired
                            ? "Expired"
                            : coupon.is_active === 1
                              ? "Active"
                              : "Inactive"}
                        </span>
                      </div>
                      <div className="mt-3 flex gap-2">
                        <button
                          type="button"
                          onClick={() => startEdit(coupon)}
                          className="rounded-md border border-neutral-300 px-3 py-1.5 text-xs font-medium text-neutral-700"
                        >
                          Edit
                        </button>
                        <button
                          type="button"
                          onClick={() => void handleDelete(coupon)}
                          disabled={deletingId === coupon.id}
                          className="rounded-md border border-red-200 px-3 py-1.5 text-xs font-medium text-red-700 disabled:opacity-50"
                        >
                          {deletingId === coupon.id ? "Deleting…" : "Delete"}
                        </button>
                      </div>
                    </li>
                  );
                })}
              </ul>
            </>
          )}
        </section>

        <section className="h-fit rounded-lg border border-neutral-200 bg-white p-5">
          <h2 className="font-serif text-xl">
            {editingId ? "Edit coupon" : "New coupon"}
          </h2>
          <form onSubmit={handleSubmit} className="mt-4 space-y-4" noValidate>
            <div>
              <label htmlFor="coupon-code" className={labelClass}>
                Code
              </label>
              <input
                id="coupon-code"
                type="text"
                autoComplete="off"
                spellCheck={false}
                maxLength={40}
                value={form.code}
                onChange={(event) =>
                  setForm((current) => ({ ...current, code: event.target.value }))
                }
                placeholder="e.g. WELCOME10"
                className={`${inputClass} uppercase placeholder:normal-case`}
              />
              <p className="mt-1 text-xs text-neutral-500">
                Letters, numbers, hyphens and underscores (2–40 characters).
              </p>
            </div>

            <div>
              <label htmlFor="coupon-type" className={labelClass}>
                Discount type
              </label>
              <select
                id="coupon-type"
                value={form.discount_type}
                onChange={(event) =>
                  setForm((current) => ({
                    ...current,
                    discount_type: event.target.value as "percentage" | "fixed",
                  }))
                }
                className={inputClass}
              >
                <option value="percentage">Percentage</option>
                <option value="fixed">Fixed amount (MAD)</option>
              </select>
            </div>

            <div>
              <label htmlFor="coupon-value" className={labelClass}>
                Discount value
              </label>
              <input
                id="coupon-value"
                type="number"
                inputMode="decimal"
                min="0.01"
                max={form.discount_type === "percentage" ? "100" : undefined}
                step="0.01"
                value={form.discount_value}
                onChange={(event) =>
                  setForm((current) => ({
                    ...current,
                    discount_value: event.target.value,
                  }))
                }
                placeholder={form.discount_type === "percentage" ? "10" : "50.00"}
                className={inputClass}
              />
              <p className="mt-1 text-xs text-neutral-500">
                {form.discount_type === "percentage"
                  ? "A percentage between 0 and 100."
                  : "A fixed amount in MAD, capped at the order subtotal."}
              </p>
            </div>

            <div>
              <label htmlFor="coupon-expiry" className={labelClass}>
                Expires at (optional)
              </label>
              <input
                id="coupon-expiry"
                type="datetime-local"
                value={form.expires_at}
                onChange={(event) =>
                  setForm((current) => ({
                    ...current,
                    expires_at: event.target.value,
                  }))
                }
                className={inputClass}
              />
              <p className="mt-1 text-xs text-neutral-500">
                Leave empty for a coupon that never expires.
              </p>
            </div>

            <label className="flex items-center gap-2 text-sm font-medium text-neutral-700">
              <input
                type="checkbox"
                checked={form.is_active}
                onChange={(event) =>
                  setForm((current) => ({
                    ...current,
                    is_active: event.target.checked,
                  }))
                }
                className="h-4 w-4 accent-neutral-900"
              />
              Active
            </label>

            <div className="flex items-center gap-2 border-t border-neutral-200 pt-4">
              <button
                type="submit"
                disabled={submitting}
                className="rounded-lg bg-neutral-900 px-5 py-2 text-sm font-medium text-white transition-colors hover:bg-neutral-700 disabled:cursor-not-allowed disabled:opacity-50"
              >
                {submitting
                  ? "Saving…"
                  : editingId
                    ? "Save changes"
                    : "Create coupon"}
              </button>
              {editingId ? (
                <button
                  type="button"
                  onClick={resetForm}
                  disabled={submitting}
                  className="rounded-lg border border-neutral-300 px-4 py-2 text-sm font-medium text-neutral-700 transition-colors hover:border-neutral-900 hover:text-neutral-900 disabled:opacity-50"
                >
                  Cancel
                </button>
              ) : null}
            </div>
          </form>
        </section>
      </div>
    </div>
  );
}
