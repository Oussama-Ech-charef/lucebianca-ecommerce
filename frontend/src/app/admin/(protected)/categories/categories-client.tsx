"use client";

import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";

import {
  adminRequest,
  clearAdminSession,
  getAdminSession,
} from "@/lib/admin";

type Category = {
  id: number;
  name: string;
  slug: string;
  created_at: string;
};

export default function CategoriesClient() {
  const router = useRouter();
  const [categories, setCategories] = useState<Category[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [showForm, setShowForm] = useState(false);
  const [editId, setEditId] = useState<number | null>(null);
  const [name, setName] = useState("");
  const [slug, setSlug] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [viewCategory, setViewCategory] = useState<Category | null>(null);
  const [viewProducts, setViewProducts] = useState<{ name: string; slug: string }[] | null>(null);

  // The admin JWT was rejected — end the session and return to login.
  function handleSessionExpired() {
    setError("Your session has expired. Please sign in again.");
    clearAdminSession();
    router.replace("/admin/login");
  }

  const isViewOpen = viewCategory !== null;

  function openView(category: Category) {
    setViewCategory(category);
    setViewProducts(null);
    // Load products for this category if API supports it
    fetch(`${process.env.NEXT_PUBLIC_API_URL}/api/products?category=${encodeURIComponent(category.slug)}&per_page=20`)
      .then((res) => (res.ok ? res.json() : null))
      .then((data) => {
        if (data && Array.isArray(data.data)) {
          setViewProducts(
            data.data.map((p: { name: string; slug: string }) => ({ name: p.name, slug: p.slug }))
          );
        }
      })
      .catch(() => {});
  }

  function closeView() {
    setViewCategory(null);
    setViewProducts(null);
  }

  // Escape + scroll lock for View modal
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

  useEffect(() => {
    fetchCategories();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  async function fetchCategories() {
    setLoading(true);
    setError(null);

    try {
      const session = getAdminSession();
      if (session === null) {
        handleSessionExpired();
        setLoading(false);
        return;
      }

      const response = await adminRequest("/api/admin/categories", session);

      if (response.status === 401) {
        handleSessionExpired();
        return;
      }
      if (!response.ok) {
        throw new Error("Failed to fetch categories");
      }

      const result = await response.json();
      setCategories(result.data);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to load categories");
    } finally {
      setLoading(false);
    }
  }

  function openCreateForm() {
    setEditId(null);
    setName("");
    setSlug("");
    setShowForm(true);
  }

  function openEditForm(category: Category) {
    setEditId(category.id);
    setName(category.name);
    setSlug(category.slug);
    setShowForm(true);
  }

  function cancelForm() {
    setShowForm(false);
    setEditId(null);
    setName("");
    setSlug("");
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setSubmitting(true);

    const session = getAdminSession();
    if (session === null) {
      handleSessionExpired();
      setSubmitting(false);
      return;
    }

    try {
      const url = editId
        ? `/api/admin/categories/${editId}`
        : "/api/admin/categories";

      const response = await adminRequest(url, session, {
        method: editId ? "PUT" : "POST",
        body: { name, slug: slug || undefined },
      });

      if (response.status === 401) {
        handleSessionExpired();
        return;
      }

      if (response.ok) {
        cancelForm();
        fetchCategories();
      } else {
        alert("Failed to save category");
      }
    } catch (err) {
      console.error("Failed to save category", err);
    } finally {
      setSubmitting(false);
    }
  }

  async function deleteCategory(id: number, name: string) {
    if (!confirm(`Delete "${name}"? Products in this category will be uncategorized.`)) {
      return;
    }

    const session = getAdminSession();
    if (session === null) {
      handleSessionExpired();
      return;
    }

    try {
      const response = await adminRequest(`/api/admin/categories/${id}`, session, {
        method: "DELETE",
      });

      if (response.status === 401) {
        handleSessionExpired();
        return;
      }

      if (response.ok) {
        fetchCategories();
      }
    } catch (err) {
      console.error("Failed to delete category", err);
    }
  }

  if (loading) {
    return (
      <div className="p-6">
        <p className="text-sm text-neutral-500">Loading categories...</p>
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

  const inputClass =
    "w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm focus:border-neutral-900 focus:outline-none";

  return (
    <div className="p-6">
      <div className="mb-6 flex items-center justify-between">
        <h1 className="text-2xl font-semibold">Categories</h1>
        {!showForm && (
          <button
            onClick={openCreateForm}
            className="rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-700"
          >
            Add Category
          </button>
        )}
      </div>

      {showForm && (
        <div className="mb-6 rounded-lg border border-neutral-200 p-4">
          <h2 className="mb-4 text-lg font-medium">
            {editId ? "Edit Category" : "New Category"}
          </h2>
          <form onSubmit={handleSubmit} className="space-y-4">
            <div>
              <label className="mb-1.5 block text-sm font-medium">Name *</label>
              <input
                type="text"
                value={name}
                onChange={(e) => setName(e.target.value)}
                className={inputClass}
                required
              />
            </div>
            <div>
              <label className="mb-1.5 block text-sm font-medium">
                Slug (leave empty to auto-generate)
              </label>
              <input
                type="text"
                value={slug}
                onChange={(e) => setSlug(e.target.value)}
                className={inputClass}
              />
            </div>
            <div className="flex gap-2">
              <button
                type="submit"
                disabled={submitting}
                className="rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-700 disabled:opacity-50"
              >
                {submitting ? "Saving..." : editId ? "Update" : "Create"}
              </button>
              <button
                type="button"
                onClick={cancelForm}
                className="rounded-lg border border-neutral-300 px-4 py-2 text-sm font-medium hover:bg-neutral-50"
              >
                Cancel
              </button>
            </div>
          </form>
        </div>
      )}

      {categories.length === 0 ? (
        <p className="text-sm text-neutral-500">No categories found.</p>
      ) : (
        <>
          <div className="hidden overflow-x-auto rounded-lg border border-neutral-200 md:block">
            <table className="w-full text-left text-sm">
              <thead className="border-b border-neutral-200 bg-neutral-50">
                <tr>
                  <th className="px-4 py-3 font-medium">Name</th>
                  <th className="px-4 py-3 font-medium">Slug</th>
                  <th className="px-4 py-3 font-medium">Actions</th>
                </tr>
              </thead>
              <tbody>
                {categories.map((category) => (
                  <tr key={category.id} className="border-b border-neutral-100">
                    <td className="px-4 py-3 font-medium">{category.name}</td>
                    <td className="px-4 py-3 text-neutral-600">{category.slug}</td>
                    <td className="px-4 py-3">
                      <div className="flex flex-wrap items-center gap-3">
                        <button
                          onClick={() => openView(category)}
                          className="whitespace-nowrap text-neutral-600 hover:underline"
                        >
                          View
                        </button>
                        <button
                          onClick={() => openEditForm(category)}
                          className="whitespace-nowrap text-blue-600 hover:underline"
                        >
                          Edit
                        </button>
                        <button
                          onClick={() => deleteCategory(category.id, category.name)}
                          className="whitespace-nowrap text-red-600 hover:underline"
                        >
                          Delete
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div className="grid gap-4 md:hidden">
            {categories.map((category) => (
              <div
                key={category.id}
                className="rounded-lg border border-neutral-200 bg-white p-4"
              >
                <p className="font-medium leading-tight">{category.name}</p>
                <p className="mt-1 break-all text-sm text-neutral-500">/{category.slug}</p>
                <p className="mt-2 text-xs text-neutral-500">
                  Created {new Date(category.created_at).toLocaleDateString()}
                </p>
                <div className="mt-4 flex flex-wrap gap-2">
                  <button
                    onClick={() => openView(category)}
                    className="rounded-md border border-neutral-300 px-3 py-1.5 text-sm font-medium text-neutral-700 hover:bg-neutral-50"
                  >
                    View
                  </button>
                  <button
                    onClick={() => openEditForm(category)}
                    className="rounded-md border border-neutral-300 px-3 py-1.5 text-sm font-medium text-neutral-700 hover:bg-neutral-50"
                  >
                    Edit
                  </button>
                  <button
                    onClick={() => deleteCategory(category.id, category.name)}
                    className="rounded-md bg-red-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-red-700"
                  >
                    Delete
                  </button>
                </div>
              </div>
            ))}
          </div>
        </>
      )}

      {isViewOpen && viewCategory && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
          <button
            type="button"
            aria-label="Close category details"
            onClick={closeView}
            className="absolute inset-0 bg-black/40"
          />
          <div className="relative max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-xl bg-white p-6 shadow-xl">
            <div className="flex items-start justify-between gap-4">
              <div>
                <h2 className="font-serif text-xl">{viewCategory.name}</h2>
                <p className="mt-1 break-all text-sm text-neutral-500">/{viewCategory.slug}</p>
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
            <dl className="mt-6 space-y-3 text-sm">
              <div className="flex justify-between gap-4">
                <dt className="text-neutral-500">Slug</dt>
                <dd className="font-medium break-all">{viewCategory.slug}</dd>
              </div>
              <div className="flex justify-between gap-4">
                <dt className="text-neutral-500">Created</dt>
                <dd>{new Date(viewCategory.created_at).toLocaleString()}</dd>
              </div>
            </dl>
            <div className="mt-6">
              <h3 className="text-sm font-medium">Products in this category</h3>
              {viewProducts === null ? (
                <p className="mt-2 text-sm text-neutral-500">Loading…</p>
              ) : viewProducts.length === 0 ? (
                <p className="mt-2 text-sm text-neutral-500">No products in this category.</p>
              ) : (
                <ul className="mt-2 divide-y divide-neutral-100 rounded-lg border border-neutral-200">
                  {viewProducts.map((p) => (
                    <li key={p.slug} className="px-3 py-2 text-sm">
                      <a
                        href={`/product/${p.slug}`}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="font-medium text-neutral-900 hover:underline"
                      >
                        {p.name}
                      </a>
                      <span className="ml-2 text-xs text-neutral-500">/{p.slug}</span>
                    </li>
                  ))}
                </ul>
              )}
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
