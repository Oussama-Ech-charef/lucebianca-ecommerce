"use client";

import { useRouter } from "next/navigation";
import { useCallback, useEffect, useState } from "react";

import {
  adminRequest,
  clearAdminSession,
  getAdminSession,
} from "@/lib/admin";
import type { AdminAuthPayload } from "@/lib/types";

type AdminAccount = AdminAuthPayload["admin"];

type FormState = {
  name: string;
  email: string;
  password: string;
};

const EMPTY_FORM: FormState = { name: "", email: "", password: "" };

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

/**
 * Account management for the Settings screen.
 *
 * Only name/email/password are supported by the admins schema. Deleting an
 * account is guarded server-side: you cannot delete yourself or the last
 * remaining admin. There is intentionally no role editing or deactivation
 * flag — the schema has no active column and all admins are equal operators.
 */
export default function AccountsTab() {
  const router = useRouter();
  const [accounts, setAccounts] = useState<AdminAccount[]>([]);
  const [currentAdminId, setCurrentAdminId] = useState<number | null>(null);
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
    adminRequest("/api/admin/accounts", session)
      .then(async (response) => {
        if (cancelled) return;
        if (response.status === 401) {
          handleSessionExpired();
          return;
        }
        if (!response.ok) {
          throw new Error(
            await readErrorMessage(response, "Failed to load admin accounts."),
          );
        }
        const result = (await response.json()) as { data: AdminAccount[] };
        if (cancelled) return;
        setAccounts(result.data ?? []);
        setCurrentAdminId(session.admin.id);
        setError(null);
      })
      .catch((err: unknown) => {
        if (!cancelled) {
          setError(
            err instanceof Error
              ? err.message
              : "Failed to load admin accounts.",
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

  function startEdit(account: AdminAccount) {
    setEditingId(account.id);
    setForm({ name: account.name, email: account.email, password: "" });
    setError(null);
    setSuccess(null);
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
      const payload = editingId
        ? {
            name: form.name.trim(),
            email: form.email.trim(),
            ...(form.password !== "" ? { password: form.password } : {}),
          }
        : {
            name: form.name.trim(),
            email: form.email.trim(),
            password: form.password,
          };

      const response = await adminRequest(
        editingId
          ? `/api/admin/accounts/${editingId}`
          : "/api/admin/accounts",
        session,
        { method: editingId ? "PUT" : "POST", body: payload },
      );

      if (response.status === 401) {
        handleSessionExpired();
        return;
      }
      if (!response.ok) {
        setError(
          await readErrorMessage(response, "Failed to save the admin account."),
        );
        return;
      }

      setSuccess(editingId ? "Account updated." : "Account created.");
      resetForm();
      setReloadKey((key) => key + 1);
    } catch (err) {
      setError(
        err instanceof Error
          ? err.message
          : "Failed to save the admin account.",
      );
    } finally {
      setSubmitting(false);
    }
  }

  async function handleDelete(account: AdminAccount) {
    if (
      !window.confirm(
        `Delete the admin account for ${account.name} (${account.email})? They will be signed out immediately.`,
      )
    ) {
      return;
    }

    const session = getAdminSession();
    if (session === null) {
      handleSessionExpired();
      return;
    }

    setDeletingId(account.id);
    setError(null);
    setSuccess(null);
    try {
      const response = await adminRequest(
        `/api/admin/accounts/${account.id}`,
        session,
        { method: "DELETE" },
      );
      if (response.status === 401) {
        handleSessionExpired();
        return;
      }
      if (!response.ok) {
        setError(
          await readErrorMessage(
            response,
            "Failed to delete the admin account.",
          ),
        );
        return;
      }
      if (editingId === account.id) {
        resetForm();
      }
      setSuccess(`Admin account for ${account.name} deleted.`);
      setReloadKey((key) => key + 1);
    } catch (err) {
      setError(
        err instanceof Error
          ? err.message
          : "Failed to delete the admin account.",
      );
    } finally {
      setDeletingId(null);
    }
  }

  const inputClass =
    "mt-1 block w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-900 placeholder:text-neutral-400 focus:border-neutral-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-neutral-900 focus-visible:ring-offset-1";
  const labelClass = "block text-sm font-medium text-neutral-700";

  return (
    <div className="space-y-6">
      <div>
        <h2 className="text-lg font-medium">Admin Accounts</h2>
        <p className="mt-1 text-sm text-neutral-600">
          Everyone listed here can sign in to the admin panel. All accounts have
          the same permissions.
        </p>
      </div>

      {error ? (
        <p
          role="alert"
          className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"
        >
          {error}
        </p>
      ) : null}
      {success ? (
        <p
          role="status"
          className="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700"
        >
          {success}
        </p>
      ) : null}

      <div className="rounded-lg border border-neutral-200 bg-white">
        {loading ? (
          <p className="px-4 py-8 text-center text-sm text-neutral-500">
            Loading accounts…
          </p>
        ) : accounts.length === 0 ? (
          <p className="px-4 py-8 text-center text-sm text-neutral-500">
            No admin accounts found.
          </p>
        ) : (
          <ul className="divide-y divide-neutral-100">
            {accounts.map((account) => (
              <li
                key={account.id}
                className="flex flex-col gap-3 px-4 py-4 sm:flex-row sm:items-center sm:justify-between"
              >
                <div className="min-w-0">
                  <p className="font-medium text-neutral-900">
                    {account.name}
                    {account.id === currentAdminId ? (
                      <span className="ml-2 rounded border border-neutral-300 bg-neutral-100 px-2 py-0.5 text-xs font-medium text-neutral-600">
                        You
                      </span>
                    ) : null}
                  </p>
                  <p className="truncate text-sm text-neutral-600">
                    {account.email}
                  </p>
                  <p className="mt-0.5 text-xs text-neutral-400">
                    {account.role} · joined{" "}
                    {new Date(account.created_at).toLocaleDateString("en-GB", {
                      day: "numeric",
                      month: "short",
                      year: "numeric",
                    })}
                  </p>
                </div>
                <div className="flex shrink-0 items-center gap-2">
                  <button
                    type="button"
                    onClick={() => startEdit(account)}
                    className="rounded-md border border-neutral-300 px-3 py-1.5 text-xs font-medium text-neutral-700 transition-colors hover:border-neutral-900 hover:text-neutral-900"
                  >
                    Edit
                  </button>
                  <button
                    type="button"
                    onClick={() => void handleDelete(account)}
                    disabled={
                      deletingId === account.id ||
                      account.id === currentAdminId
                    }
                    title={
                      account.id === currentAdminId
                        ? "You cannot delete your own account"
                        : undefined
                    }
                    className="rounded-md border border-red-200 px-3 py-1.5 text-xs font-medium text-red-700 transition-colors hover:border-red-600 hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-50"
                  >
                    {deletingId === account.id ? "Deleting…" : "Delete"}
                  </button>
                </div>
              </li>
            ))}
          </ul>
        )}
      </div>

      <form
        onSubmit={handleSubmit}
        noValidate
        className="max-w-xl space-y-4 rounded-lg border border-neutral-200 bg-white p-5"
      >
        <h3 className="font-serif text-xl">
          {editingId ? "Edit account" : "Add an account"}
        </h3>

        <div>
          <label htmlFor="account-name" className={labelClass}>
            Name
          </label>
          <input
            id="account-name"
            type="text"
            autoComplete="name"
            maxLength={100}
            value={form.name}
            onChange={(event) =>
              setForm((current) => ({ ...current, name: event.target.value }))
            }
            className={inputClass}
          />
        </div>

        <div>
          <label htmlFor="account-email" className={labelClass}>
            Email
          </label>
          <input
            id="account-email"
            type="email"
            autoComplete="email"
            maxLength={150}
            value={form.email}
            onChange={(event) =>
              setForm((current) => ({ ...current, email: event.target.value }))
            }
            className={inputClass}
          />
        </div>

        <div>
          <label htmlFor="account-password" className={labelClass}>
            {editingId ? "New password (optional)" : "Password"}
          </label>
          <input
            id="account-password"
            type="password"
            autoComplete="new-password"
            value={form.password}
            onChange={(event) =>
              setForm((current) => ({
                ...current,
                password: event.target.value,
              }))
            }
            placeholder={editingId ? "Leave blank to keep unchanged" : "At least 8 characters"}
            className={inputClass}
          />
        </div>

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
                : "Create account"}
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
    </div>
  );
}
