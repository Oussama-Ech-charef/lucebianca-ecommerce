"use client";

import { useRouter } from "next/navigation";
import { useCallback, useEffect, useState } from "react";

import {
  adminRequest,
  clearAdminSession,
  getAdminSession,
} from "@/lib/admin";

type Message = {
  id: number;
  name: string;
  email: string;
  message: string;
  is_read: number;
  created_at: string;
};

type MessagesResponse = {
  data: Message[];
  meta: {
    page: number;
    per_page: number;
    total: number;
    pages: number;
  };
};

export default function MessagesClient() {
  const router = useRouter();
  const [messages, setMessages] = useState<Message[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [page, setPage] = useState(1);
  const [meta, setMeta] = useState<MessagesResponse["meta"] | null>(null);
  const [filter, setFilter] = useState<"all" | "unread" | "read">("unread");
  const [viewMessage, setViewMessage] = useState<Message | null>(null);

  const sessionExpired = useCallback(() => {
    setError("Your session has expired. Please sign in again.");
    clearAdminSession();
    router.replace("/admin/login");
  }, [router]);

  // Bumped by mutation handlers to refetch with the same page/filter.
  const [reloadKey, setReloadKey] = useState(0);

  const isViewOpen = viewMessage !== null;
  function openView(message: Message) {
    setViewMessage(message);
  }
  function closeView() {
    setViewMessage(null);
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

  // Data loads inline in the effect with state updates confined to promise
  // continuations (react-hooks/set-state-in-effect). A missing session here
  // needs no local handling — the admin shell already redirects to login.
  useEffect(() => {
    const session = getAdminSession();
    if (session === null) {
      // No local handling: the admin shell redirects logged-out admins.
      return;
    }
    let cancelled = false;

    const statusParam = filter === "all" ? "" : `&status=${filter}`;
    adminRequest(`/api/admin/messages?page=${page}&per_page=20${statusParam}`, session)
      .then(async (response) => {
        if (cancelled) return;
        if (response.status === 401) {
          sessionExpired();
          return;
        }
        if (!response.ok) {
          throw new Error("Failed to fetch messages");
        }
        const result: MessagesResponse = await response.json();
        setMessages(result.data);
        setMeta(result.meta);
        setError(null);
      })
      .catch((err: unknown) => {
        if (!cancelled) {
          setError(err instanceof Error ? err.message : "Failed to load messages");
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
  }, [page, filter, reloadKey, sessionExpired]);

  async function markAsRead(id: number) {
    const session = getAdminSession();
    if (session === null) {
      clearAdminSession();
      router.replace("/admin/login");
      return;
    }

    try {
      const response = await adminRequest(
        `/api/admin/messages/${id}/mark-read`,
        session,
        { method: "PUT" },
      );

      if (response.status === 401) {
        clearAdminSession();
        router.replace("/admin/login");
        return;
      }

      if (response.ok) {
        setReloadKey((key) => key + 1);
      }
    } catch (err) {
      console.error("Failed to mark as read", err);
    }
  }

  async function deleteMessage(id: number) {
    if (!confirm("Delete this message permanently?")) return;

    const session = getAdminSession();
    if (session === null) {
      clearAdminSession();
      router.replace("/admin/login");
      return;
    }

    try {
      const response = await adminRequest(`/api/admin/messages/${id}`, session, {
        method: "DELETE",
      });

      if (response.status === 401) {
        clearAdminSession();
        router.replace("/admin/login");
        return;
      }

      if (response.ok) {
        setReloadKey((key) => key + 1);
      }
    } catch (err) {
      console.error("Failed to delete message", err);
    }
  }

  if (loading) {
    return (
      <div className="p-6">
        <p className="text-sm text-neutral-500">Loading messages...</p>
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
      <h1 className="mb-6 text-2xl font-semibold">Contact Messages</h1>

      <div className="mb-4 flex gap-2">
        <button
          onClick={() => { setFilter("unread"); setPage(1); }}
          className={`rounded px-4 py-2 text-sm font-medium ${
            filter === "unread"
              ? "bg-neutral-900 text-white"
              : "bg-neutral-100 hover:bg-neutral-200"
          }`}
        >
          Unread
        </button>
        <button
          onClick={() => { setFilter("read"); setPage(1); }}
          className={`rounded px-4 py-2 text-sm font-medium ${
            filter === "read"
              ? "bg-neutral-900 text-white"
              : "bg-neutral-100 hover:bg-neutral-200"
          }`}
        >
          Read
        </button>
        <button
          onClick={() => { setFilter("all"); setPage(1); }}
          className={`rounded px-4 py-2 text-sm font-medium ${
            filter === "all"
              ? "bg-neutral-900 text-white"
              : "bg-neutral-100 hover:bg-neutral-200"
          }`}
        >
          All
        </button>
      </div>

      {messages.length === 0 ? (
        <p className="text-sm text-neutral-500">No messages found.</p>
      ) : (
        <>
          <div className="space-y-4">
            {messages.map((message) => (
              <div
                key={message.id}
                className={`rounded-lg border p-4 ${
                  message.is_read === 0
                    ? "border-blue-200 bg-blue-50"
                    : "border-neutral-200"
                }`}
              >
                <div className="mb-2 flex items-start justify-between">
                  <div>
                    <p className="font-medium">{message.name}</p>
                    <p className="text-sm text-neutral-600">{message.email}</p>
                    <p className="text-xs text-neutral-500">
                      {new Date(message.created_at).toLocaleString()}
                    </p>
                  </div>
                  <span
                    className={`inline-block rounded px-2 py-1 text-xs font-medium ${
                      message.is_read === 0
                        ? "bg-blue-100 text-blue-700"
                        : "bg-neutral-100 text-neutral-600"
                    }`}
                  >
                    {message.is_read === 0 ? "Unread" : "Read"}
                  </span>
                </div>

                <p className="mb-3 text-sm text-neutral-700">{message.message}</p>

                <div className="flex flex-wrap gap-3">
                  <button
                    onClick={() => openView(message)}
                    className="text-sm text-neutral-600 hover:underline"
                  >
                    View
                  </button>
                  {message.is_read === 0 && (
                    <button
                      onClick={() => markAsRead(message.id)}
                      className="text-sm text-blue-600 hover:underline"
                    >
                      Mark as Read
                    </button>
                  )}
                  <a
                    href={`mailto:${message.email}`}
                    className="text-sm text-neutral-600 hover:underline"
                  >
                    Reply via Email
                  </a>
                  <button
                    onClick={() => deleteMessage(message.id)}
                    className="text-sm text-red-600 hover:underline"
                  >
                    Delete
                  </button>
                </div>
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

      {isViewOpen && viewMessage && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
          <button
            type="button"
            aria-label="Close message details"
            onClick={closeView}
            className="absolute inset-0 bg-black/40"
          />
          <div className="relative max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-xl bg-white p-6 shadow-xl">
            <div className="flex items-start justify-between gap-4">
              <div>
                <h2 className="font-serif text-xl">{viewMessage.name}</h2>
                <p className="mt-1 break-all text-sm text-neutral-500">{viewMessage.email}</p>
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
                <dt className="text-neutral-500">Status</dt>
                <dd>
                  <span className={`rounded px-2 py-1 text-xs font-medium ${viewMessage.is_read === 0 ? "bg-blue-100 text-blue-700" : "bg-neutral-100 text-neutral-600"}`}>
                    {viewMessage.is_read === 0 ? "Unread" : "Read"}
                  </span>
                </dd>
              </div>
              <div className="flex justify-between gap-4">
                <dt className="text-neutral-500">Date</dt>
                <dd>{new Date(viewMessage.created_at).toLocaleString()}</dd>
              </div>
            </dl>
            <div className="mt-4 rounded-lg border border-neutral-200 p-4">
              <h3 className="text-xs font-medium uppercase tracking-wide text-neutral-500">Message</h3>
              <p className="mt-2 whitespace-pre-line break-words text-sm text-neutral-700">{viewMessage.message}</p>
            </div>
            <div className="mt-4 flex gap-2">
              <a
                href={`mailto:${viewMessage.email}`}
                className="rounded-md border border-neutral-300 px-3 py-1.5 text-sm font-medium text-neutral-700 hover:bg-neutral-50"
              >
                Reply via Email
              </a>
              <button
                type="button"
                onClick={closeView}
                className="rounded-md bg-neutral-900 px-4 py-1.5 text-sm font-medium text-white hover:bg-neutral-700"
              >
                Close
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
