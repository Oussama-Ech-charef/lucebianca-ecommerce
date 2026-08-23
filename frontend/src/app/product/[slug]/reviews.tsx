"use client";

import { useState } from "react";

import { ApiError, api, fetchCsrfToken } from "@/lib/api";
import { getCustomerSession } from "@/lib/customer-auth";
import type { ProductDetail } from "@/lib/types";

interface Props {
  product: ProductDetail;
}

function StarRating({ rating, size = "text-lg" }: { rating: number; size?: string }) {
  return (
    <div className="flex items-center">
      {Array.from({ length: 5 }).map((_, i) => (
        <span
          key={i}
          className={`${size} ${
            i < rating ? "text-amber-500" : "text-neutral-300"
          }`}
        >
          ★
        </span>
      ))}
      <span className={`ml-2 ${size} text-neutral-600`}>
        {rating}/5
      </span>
    </div>
  );
}

export default function ProductReviews({ product }: Props) {
  const [showForm, setShowForm] = useState(false);
  const [rating, setRating] = useState(5);
  const [comment, setComment] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState(false);
  const [submitting, setSubmitting] = useState(false);

  const token = getCustomerSession()?.token;

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    setSuccess(false);
    setSubmitting(true);

    try {
      // CsrfMiddleware requires a fresh token from GET /api/csrf-token,
      // sent back in the X-CSRF-Token header on the POST.
      const csrfToken = await fetchCsrfToken();
      await api<{ data: { id: number; message: string } }>(
        `/api/products/${encodeURIComponent(product.slug)}/reviews`,
        {
          method: "POST",
          token,
          headers: { "X-CSRF-Token": csrfToken },
          body: { rating, comment },
        },
      );

      // Reviews are moderated (admin approval), so there is nothing to
      // refresh on the page yet — confirm and close the form instead.
      setRating(5);
      setComment("");
      setShowForm(false);
      setSuccess(true);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Failed to submit review");
    } finally {
      setSubmitting(false);
    }
  }

  const hasReviews = product.reviews && product.reviews.length > 0;
  const averageRating = product.average_rating ?? 0;

  return (
    <section className="mt-12 border-t border-neutral-200 pt-10">
      <h2 className="font-serif text-2xl">Customer Reviews</h2>

      {/* Average Rating */}
      <div className="mt-4 flex items-center gap-4">
        <StarRating rating={Math.round(averageRating)} size="text-2xl" />
        <span className="text-sm text-neutral-600">
          {hasReviews
            ? `${product.reviews.length} ${product.reviews.length === 1 ? "review" : "reviews"}`
            : "No reviews yet"}
        </span>
      </div>

      {/* Write Review Button */}
      <div className="mt-6">
        {token ? (
          <button
            onClick={() => setShowForm(!showForm)}
            className="text-sm font-medium text-neutral-900 hover:underline"
          >
            {showForm ? "Cancel" : "Write a review"}
          </button>
        ) : (
          <p className="text-sm text-neutral-500">
            Please sign in to write a review
          </p>
        )}
      </div>

      {/* Review Form */}
      {showForm && (
        <form onSubmit={handleSubmit} className="mt-6 space-y-4 rounded-lg border border-neutral-200 p-6">
          {error && (
            <div className="rounded bg-red-50 p-3 text-sm text-red-700">
              {error}
            </div>
          )}

          <div>
            <label className="mb-2 block text-sm font-medium text-neutral-700">
              Rating
            </label>
            <div className="flex gap-2">
              {[1, 2, 3, 4, 5].map((star) => (
                <button
                  key={star}
                  type="button"
                  onClick={() => setRating(star)}
                  className="text-3xl transition-transform hover:scale-110 focus:outline-none"
                >
                  <span className={star <= rating ? "text-amber-500" : "text-neutral-300"}>
                    ★
                  </span>
                </button>
              ))}
            </div>
          </div>

          <div>
            <label htmlFor="review-comment" className="mb-2 block text-sm font-medium text-neutral-700">
              Comment
            </label>
            <textarea
              id="review-comment"
              rows={4}
              value={comment}
              onChange={(e) => setComment(e.target.value)}
              placeholder="Share your thoughts about this product..."
              className="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm focus:border-neutral-900 focus:outline-none"
            />
          </div>

          <button
            type="submit"
            disabled={submitting}
            className="rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-700 disabled:opacity-50"
          >
            {submitting ? "Submitting..." : "Submit Review"}
          </button>
        </form>
      )}

      {success && (
        <p className="mt-4 text-sm text-green-700">
          Thank you! Your review was submitted and will appear once approved.
        </p>
      )}

      {/* Reviews List */}
      {hasReviews && (
        <div className="mt-8 space-y-6">
          {product.reviews.map((review) => (
            <div key={review.id} className="rounded-lg border border-neutral-200 p-4">
              <div className="flex items-center justify-between">
                <StarRating rating={review.rating} />
                <span className="text-xs text-neutral-500">
                  {new Date(review.created_at).toLocaleDateString()}
                </span>
              </div>
              {review.user_name && (
                <p className="mt-2 text-sm font-medium text-neutral-900">
                  {review.user_name}
                </p>
              )}
              {review.comment && (
                <p className="mt-2 text-sm text-neutral-700">{review.comment}</p>
              )}
            </div>
          ))}
        </div>
      )}
    </section>
  );
}
