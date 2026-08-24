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

  // The admin JWT was rejected — end the session and return to login.
  function handleSessionExpired() {
    setError("Your session has expired. Please sign in again.");
    clearAdminSession();
    router.replace("/admin/login");
  }

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
        <div className="overflow-x-auto rounded-lg border border-neutral-200">
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
                    <div className="flex gap-2">
                      <button
                        onClick={() => openEditForm(category)}
                        className="text-blue-600 hover:underline"
                      >
                        Edit
                      </button>
                      <button
                        onClick={() => deleteCategory(category.id, category.name)}
                        className="text-red-600 hover:underline"
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
      )}
    </div>
  );
}
