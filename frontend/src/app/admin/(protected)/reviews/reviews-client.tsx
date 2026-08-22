"use client";

import { useEffect, useState } from "react";

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
  const [reviews, setReviews] = useState<Review[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [page, setPage] = useState(1);
  const [meta, setMeta] = useState<ReviewsResponse["meta"] | null>(null);
  const [filter, setFilter] = useState<"all" | "pending" | "approved">("pending");

  useEffect(() => {
    fetchReviews(page, filter);
  }, [page, filter]);

  async function fetchReviews(pageNum: number, status: string) {
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
        `${process.env.NEXT_PUBLIC_API_URL}/api/admin/reviews?page=${pageNum}&per_page=20${statusParam}`,
        {
          headers: { Authorization: `Bearer ${token}` },
        }
      );

      if (!response.ok) {
        throw new Error("Failed to fetch reviews");
      }

      const result: ReviewsResponse = await response.json();
      setReviews(result.data);
      setMeta(result.meta);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to load reviews");
    } finally {
      setLoading(false);
    }
  }

  async function approveReview(id: number) {
    const token = localStorage.getItem("lucebianca:admin:session:v1");
    if (!token) return;

    try {
      const response = await fetch(
        `${process.env.NEXT_PUBLIC_API_URL}/api/admin/reviews/${id}/approve`,
        {
          method: "PUT",
          headers: { Authorization: `Bearer ${token}` },
        }
      );

      if (response.ok) {
        fetchReviews(page, filter);
      }
    } catch (err) {
      console.error("Failed to approve review", err);
    }
  }

  async function rejectReview(id: number) {
    const token = localStorage.getItem("lucebianca:admin:session:v1");
    if (!token) return;

    try {
      const response = await fetch(
        `${process.env.NEXT_PUBLIC_API_URL}/api/admin/reviews/${id}/reject`,
        {
          method: "PUT",
          headers: { Authorization: `Bearer ${token}` },
        }
      );

      if (response.ok) {
        fetchReviews(page, filter);
      }
    } catch (err) {
      console.error("Failed to reject review", err);
    }
  }

  async function deleteReview(id: number) {
    if (!confirm("Delete this review permanently?")) return;

    const token = localStorage.getItem("lucebianca:admin:session:v1");
    if (!token) return;

    try {
      const response = await fetch(
        `${process.env.NEXT_PUBLIC_API_URL}/api/admin/reviews/${id}`,
        {
          method: "DELETE",
          headers: { Authorization: `Bearer ${token}` },
        }
      );

      if (response.ok) {
        fetchReviews(page, filter);
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
