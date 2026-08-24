"use client";

import { useRouter } from "next/navigation";
import { useCallback, useEffect, useState } from "react";

import {
  adminRequest,
  clearAdminSession,
  getAdminSession,
} from "@/lib/admin";

type Review = {
  id: number;
  product_id: number;
  product_name: string;
  product_slug: string;
  user_id: number | null;
  user_name: string | null;
  rating: number;
  comment: string | null;
  is_approved: number;
  created_at: string;
};

type ReviewsResponse = {
  data: Review[];
  meta: {
    page: number;
    per_page: number;
    total: number;
    pages: number;
  };
};

export default function ReviewsClient() {
  const router = useRouter();
  const [reviews, setReviews] = useState<Review[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [page, setPage] = useState(1);
  const [meta, setMeta] = useState<ReviewsResponse["meta"] | null>(null);
  const [filter, setFilter] = useState<"all" | "pending" | "approved">("pending");

  const sessionExpired = useCallback(() => {
    setError("Your session has expired. Please sign in again.");
    clearAdminSession();
    router.replace("/admin/login");
  }, [router]);

  // Bumped by mutation handlers to refetch with the same page/filter.
  const [reloadKey, setReloadKey] = useState(0);

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
    adminRequest(`/api/admin/reviews?page=${page}&per_page=20${statusParam}`, session)
      .then(async (response) => {
        if (cancelled) return;
        if (response.status === 401) {
          sessionExpired();
          return;
        }
        if (!response.ok) {
          throw new Error("Failed to fetch reviews");
        }
        const result: ReviewsResponse = await response.json();
        setReviews(result.data);
        setMeta(result.meta);
        setError(null);
      })
      .catch((err: unknown) => {
        if (!cancelled) {
          setError(err instanceof Error ? err.message : "Failed to load reviews");
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

  function requireSession() {
    const session = getAdminSession();
    if (session === null) {
      clearAdminSession();
      router.replace("/admin/login");
      return null;
    }
    return session;
  }

  async function approveReview(id: number) {
    const session = requireSession();
    if (session === null) return;

    try {
      const response = await adminRequest(
        `/api/admin/reviews/${id}/approve`,
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
      console.error("Failed to approve review", err);
    }
  }

  async function rejectReview(id: number) {
    const session = requireSession();
    if (session === null) return;

    try {
      const response = await adminRequest(
        `/api/admin/reviews/${id}/reject`,
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
      console.error("Failed to reject review", err);
    }
  }

  async function deleteReview(id: number) {
    if (!confirm("Delete this review permanently?")) return;

    const session = requireSession();
    if (session === null) return;

    try {
      const response = await adminRequest(`/api/admin/reviews/${id}`, session, {
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
      console.error("Failed to delete review", err);
    }
  }

  if (loading) {
    return (
      <div className="p-6">
        <p className="text-sm text-neutral-500">Loading reviews...</p>
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
      <h1 className="mb-6 text-2xl font-semibold">Reviews</h1>

      <div className="mb-4 flex gap-2">
        <button
          onClick={() => { setFilter("pending"); setPage(1); }}
          className={`rounded px-4 py-2 text-sm font-medium ${
            filter === "pending"
              ? "bg-neutral-900 text-white"
              : "bg-neutral-100 hover:bg-neutral-200"
          }`}
        >
          Pending
        </button>
        <button
          onClick={() => { setFilter("approved"); setPage(1); }}
          className={`rounded px-4 py-2 text-sm font-medium ${
            filter === "approved"
              ? "bg-neutral-900 text-white"
              : "bg-neutral-100 hover:bg-neutral-200"
          }`}
        >
          Approved
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

      {reviews.length === 0 ? (
        <p className="text-sm text-neutral-500">No reviews found.</p>
      ) : (
        <>
          <div className="space-y-4">
            {reviews.map((review) => (
              <div
                key={review.id}
                className="rounded-lg border border-neutral-200 p-4"
              >
                <div className="mb-2 flex items-start justify-between">
                  <div>
                    <p className="font-medium">{review.product_name}</p>
                    <p className="text-sm text-neutral-600">
                      By {review.user_name || "Guest"} •{" "}
                      {new Date(review.created_at).toLocaleDateString()}
                    </p>
                  </div>
                  <span
                    className={`inline-block rounded px-2 py-1 text-xs font-medium ${
                      review.is_approved === 1
                        ? "bg-green-100 text-green-700"
                        : "bg-amber-100 text-amber-700"
                    }`}
                  >
                    {review.is_approved === 1 ? "Approved" : "Pending"}
                  </span>
                </div>

                <div className="mb-3 flex items-center gap-1">
                  {Array.from({ length: 5 }).map((_, i) => (
                    <span
                      key={i}
                      className={`text-lg ${
                        i < review.rating ? "text-amber-500" : "text-neutral-300"
                      }`}
                    >
                      ★
                    </span>
                  ))}
                  <span className="ml-2 text-sm text-neutral-600">
                    {review.rating}/5
                  </span>
                </div>

                {review.comment && (
                  <p className="mb-3 text-sm text-neutral-700">{review.comment}</p>
                )}

                <div className="flex gap-2">
                  {review.is_approved === 0 && (
                    <button
                      onClick={() => approveReview(review.id)}
                      className="text-sm text-green-600 hover:underline"
                    >
                      Approve
                    </button>
                  )}
                  {review.is_approved === 1 && (
                    <button
                      onClick={() => rejectReview(review.id)}
                      className="text-sm text-amber-600 hover:underline"
                    >
                      Reject
                    </button>
                  )}
                  <button
                    onClick={() => deleteReview(review.id)}
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
