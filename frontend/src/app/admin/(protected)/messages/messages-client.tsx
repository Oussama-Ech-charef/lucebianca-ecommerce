"use client";

import { useEffect, useState } from "react";

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
  const [messages, setMessages] = useState<Message[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [page, setPage] = useState(1);
  const [meta, setMeta] = useState<MessagesResponse["meta"] | null>(null);
  const [filter, setFilter] = useState<"all" | "unread" | "read">("unread");

  useEffect(() => {
    fetchMessages(page, filter);
  }, [page, filter]);

  async function fetchMessages(pageNum: number, status: string) {
    setLoading(true);
    setError(null);

    try {
      const token = localStorage.getItem("lucebianca:admin:session:v1");
      if (!token) {
        setError("Not authenticated");
        setLoading(false);
        return;
      }

      const statusParam = status === "all" ? "" : `&status=${status}`;
      const response = await fetch(
        `${process.env.NEXT_PUBLIC_API_URL}/api/admin/messages?page=${pageNum}&per_page=20${statusParam}`,
        {
          headers: { Authorization: `Bearer ${token}` },
        }
      );

      if (!response.ok) {
        throw new Error("Failed to fetch messages");
      }

      const result: MessagesResponse = await response.json();
      setMessages(result.data);
      setMeta(result.meta);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to load messages");
    } finally {
      setLoading(false);
    }
  }

  async function markAsRead(id: number) {
    const token = localStorage.getItem("lucebianca:admin:session:v1");
    if (!token) return;

    try {
      const response = await fetch(
        `${process.env.NEXT_PUBLIC_API_URL}/api/admin/messages/${id}/mark-read`,
        {
          method: "PUT",
          headers: { Authorization: `Bearer ${token}` },
        }
      );

      if (response.ok) {
        fetchMessages(page, filter);
      }
    } catch (err) {
      console.error("Failed to mark as read", err);
    }
  }

  async function deleteMessage(id: number) {
    if (!confirm("Delete this message permanently?")) return;

    const token = localStorage.getItem("lucebianca:admin:session:v1");
    if (!token) return;

    try {
      const response = await fetch(
        `${process.env.NEXT_PUBLIC_API_URL}/api/admin/messages/${id}`,
        {
          method: "DELETE",
          headers: { Authorization: `Bearer ${token}` },
        }
      );

      if (response.ok) {
        fetchMessages(page, filter);
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

                <div className="flex gap-2">
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
    </div>
  );
}
