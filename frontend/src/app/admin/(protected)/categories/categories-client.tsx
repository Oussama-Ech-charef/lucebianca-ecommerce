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

type Size = {
  id: number;
  name: string;
  created_at: string;
};

type Color = {
  id: number;
  name: string;
  created_at: string;
};

export default function CategoriesClient() {
  const router = useRouter();
  const [activeTab, setActiveTab] = useState<"categories" | "sizes" | "colors">("categories");

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

  const [sizes, setSizes] = useState<Size[]>([]);
  const [colors, setColors] = useState<Color[]>([]);
  const [sizesLoading, setSizesLoading] = useState(true);
  const [colorsLoading, setColorsLoading] = useState(true);
  const [sizeName, setSizeName] = useState("");
  const [colorName, setColorName] = useState("");
  const [sizeError, setSizeError] = useState<string | null>(null);
  const [colorError, setColorError] = useState<string | null>(null);
  const [sizeSubmitting, setSizeSubmitting] = useState(false);
  const [colorSubmitting, setColorSubmitting] = useState(false);

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
    fetchSizes();
    fetchColors();
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

  async function fetchSizes() {
    setSizesLoading(true);
    setSizeError(null);
    try {
      const session = getAdminSession();
      if (session === null) return;
      const res = await adminRequest("/api/admin/sizes", session);
      if (res.status === 401) {
        handleSessionExpired();
        return;
      }
      if (!res.ok) throw new Error("Failed to fetch sizes");
      const data = await res.json();
      setSizes(data.data || []);
    } catch (err) {
      setSizeError(err instanceof Error ? err.message : "Failed to load sizes");
    } finally {
      setSizesLoading(false);
    }
  }

  async function fetchColors() {
    setColorsLoading(true);
    setColorError(null);
    try {
      const session = getAdminSession();
      if (session === null) return;
      const res = await adminRequest("/api/admin/colors", session);
      if (res.status === 401) {
        handleSessionExpired();
        return;
      }
      if (!res.ok) throw new Error("Failed to fetch colors");
      const data = await res.json();
      setColors(data.data || []);
    } catch (err) {
      setColorError(err instanceof Error ? err.message : "Failed to load colors");
    } finally {
      setColorsLoading(false);
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
        const data = await response.json().catch(() => null);
        const msg = data?.errors?.name || data?.error || "Failed to save category";
        alert(msg);
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

  async function handleCreateSize(e: React.FormEvent) {
    e.preventDefault();
    setSizeError(null);
    const trimmed = sizeName.trim();
    if (!trimmed) {
      setSizeError("Name is required.");
      return;
    }
    if (trimmed.length > 50) {
      setSizeError("Name must be at most 50 characters.");
      return;
    }
    const session = getAdminSession();
    if (session === null) {
      handleSessionExpired();
      return;
    }
    setSizeSubmitting(true);
    try {
      const res = await adminRequest("/api/admin/sizes", session, {
        method: "POST",
        body: { name: trimmed },
      });
      if (res.status === 401) {
        handleSessionExpired();
        return;
      }
      if (res.ok) {
        setSizeName("");
        fetchSizes();
      } else {
        const data = await res.json().catch(() => null);
        setSizeError(data?.errors?.name || data?.error || "Failed to create size");
      }
    } catch (err) {
      setSizeError(err instanceof Error ? err.message : "Failed to create size");
    } finally {
      setSizeSubmitting(false);
    }
  }

  async function handleDeleteSize(id: number, name: string) {
    if (!confirm(`Delete size "${name}"?`)) return;
    const session = getAdminSession();
    if (session === null) {
      handleSessionExpired();
      return;
    }
    try {
      const res = await adminRequest(`/api/admin/sizes/${id}`, session, { method: "DELETE" });
      if (res.status === 401) {
        handleSessionExpired();
        return;
      }
      if (res.status === 409) {
        const data = await res.json().catch(() => null);
        setSizeError(data?.error || "Cannot delete size that is currently used by product variants.");
        return;
      }
      if (res.ok) {
        fetchSizes();
      } else {
        const data = await res.json().catch(() => null);
        setSizeError(data?.error || "Failed to delete size");
      }
    } catch (err) {
      setSizeError(err instanceof Error ? err.message : "Failed to delete size");
    }
  }

  async function handleCreateColor(e: React.FormEvent) {
    e.preventDefault();
    setColorError(null);
    const trimmed = colorName.trim();
    if (!trimmed) {
      setColorError("Name is required.");
      return;
    }
    if (trimmed.length > 50) {
      setColorError("Name must be at most 50 characters.");
      return;
    }
    const session = getAdminSession();
    if (session === null) {
      handleSessionExpired();
      return;
    }
    setColorSubmitting(true);
    try {
      const res = await adminRequest("/api/admin/colors", session, {
        method: "POST",
        body: { name: trimmed },
      });
      if (res.status === 401) {
        handleSessionExpired();
        return;
      }
      if (res.ok) {
        setColorName("");
        fetchColors();
      } else {
        const data = await res.json().catch(() => null);
        setColorError(data?.errors?.name || data?.error || "Failed to create color");
      }
    } catch (err) {
      setColorError(err instanceof Error ? err.message : "Failed to create color");
    } finally {
      setColorSubmitting(false);
    }
  }

  async function handleDeleteColor(id: number, name: string) {
    if (!confirm(`Delete color "${name}"?`)) return;
    const session = getAdminSession();
    if (session === null) {
      handleSessionExpired();
      return;
    }
    try {
      const res = await adminRequest(`/api/admin/colors/${id}`, session, { method: "DELETE" });
      if (res.status === 401) {
        handleSessionExpired();
        return;
      }
      if (res.status === 409) {
        const data = await res.json().catch(() => null);
        setColorError(data?.error || "Cannot delete color that is currently used by product variants.");
        return;
      }
      if (res.ok) {
        fetchColors();
      } else {
        const data = await res.json().catch(() => null);
        setColorError(data?.error || "Failed to delete color");
      }
    } catch (err) {
      setColorError(err instanceof Error ? err.message : "Failed to delete color");
    }
  }

  if (loading && sizesLoading && colorsLoading && activeTab === "categories" && categories.length === 0) {
    // initial loading state for categories tab
  }

  const inputClass =
    "w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm focus:border-neutral-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-neutral-900 focus-visible:ring-offset-1";

  return (
    <div className="p-6">
      <h1 className="text-2xl font-semibold">Catalog</h1>
      <p className="mt-1 text-sm text-neutral-500">Manage categories, sizes, and colors used for product variants.</p>

      <div className="mt-6 -mx-6 overflow-x-auto px-6">
        <div className="flex gap-2 border-b border-neutral-200">
          <button
            onClick={() => setActiveTab("categories")}
            className={`shrink-0 whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium ${
              activeTab === "categories"
                ? "border-neutral-900 text-neutral-900"
                : "border-transparent text-neutral-600 hover:text-neutral-900"
            }`}
          >
            Categories
          </button>
          <button
            onClick={() => setActiveTab("sizes")}
            className={`shrink-0 whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium ${
              activeTab === "sizes"
                ? "border-neutral-900 text-neutral-900"
                : "border-transparent text-neutral-600 hover:text-neutral-900"
            }`}
          >
            Sizes
          </button>
          <button
            onClick={() => setActiveTab("colors")}
            className={`shrink-0 whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium ${
              activeTab === "colors"
                ? "border-neutral-900 text-neutral-900"
                : "border-transparent text-neutral-600 hover:text-neutral-900"
            }`}
          >
            Colors
          </button>
        </div>
      </div>

      {activeTab === "categories" && (
        <div className="mt-6">
          <div className="mb-6 flex items-center justify-between">
            <h2 className="text-lg font-medium">Categories</h2>
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
              <h3 className="mb-4 text-lg font-medium">
                {editId ? "Edit Category" : "New Category"}
              </h3>
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

          {loading ? (
            <p className="text-sm text-neutral-500">Loading categories...</p>
          ) : error ? (
            <p className="text-sm text-red-600">{error}</p>
          ) : categories.length === 0 ? (
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
        </div>
      )}

      {activeTab === "sizes" && (
        <div className="mt-6">
          <h2 className="text-lg font-medium">Sizes</h2>
          <p className="mt-1 text-sm text-neutral-500">Manage available sizes for product variants (e.g., S, M, L, XL).</p>

          <form onSubmit={handleCreateSize} className="mt-4 flex gap-2">
            <input
              type="text"
              value={sizeName}
              onChange={(e) => setSizeName(e.target.value)}
              placeholder="New size (e.g., M)"
              className="flex-1 rounded-lg border border-neutral-300 px-3 py-2 text-sm focus:border-neutral-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-neutral-900 focus-visible:ring-offset-1"
              maxLength={50}
            />
            <button
              type="submit"
              disabled={sizeSubmitting}
              className="shrink-0 rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-700 disabled:opacity-50"
            >
              {sizeSubmitting ? "Adding…" : "Add"}
            </button>
          </form>
          {sizeError && (
            <p className="mt-2 text-sm text-red-600" role="alert">
              {sizeError}
            </p>
          )}

          <div className="mt-6">
            {sizesLoading ? (
              <p className="text-sm text-neutral-500">Loading sizes…</p>
            ) : sizes.length === 0 ? (
              <p className="text-sm text-neutral-500">No sizes yet. Add one above.</p>
            ) : (
              <>
                <div className="hidden overflow-x-auto rounded-lg border border-neutral-200 md:block">
                  <table className="w-full text-left text-sm">
                    <thead className="border-b border-neutral-200 bg-neutral-50">
                      <tr>
                        <th className="px-4 py-3 font-medium">Name</th>
                        <th className="px-4 py-3 font-medium">Created</th>
                        <th className="px-4 py-3 font-medium">Actions</th>
                      </tr>
                    </thead>
                    <tbody>
                      {sizes.map((size) => (
                        <tr key={size.id} className="border-b border-neutral-100">
                          <td className="px-4 py-3 font-medium">{size.name}</td>
                          <td className="px-4 py-3 text-neutral-600">
                            {new Date(size.created_at).toLocaleDateString()}
                          </td>
                          <td className="px-4 py-3">
                            <button
                              onClick={() => handleDeleteSize(size.id, size.name)}
                              className="whitespace-nowrap text-red-600 hover:underline"
                            >
                              Delete
                            </button>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
                <div className="grid gap-3 md:hidden">
                  {sizes.map((size) => (
                    <div key={size.id} className="flex items-center justify-between rounded-lg border border-neutral-200 bg-white p-4">
                      <div>
                        <p className="font-medium">{size.name}</p>
                        <p className="text-xs text-neutral-500">{new Date(size.created_at).toLocaleDateString()}</p>
                      </div>
                      <button
                        onClick={() => handleDeleteSize(size.id, size.name)}
                        className="rounded-md bg-red-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-red-700"
                      >
                        Delete
                      </button>
                    </div>
                  ))}
                </div>
              </>
            )}
          </div>
        </div>
      )}

      {activeTab === "colors" && (
        <div className="mt-6">
          <h2 className="text-lg font-medium">Colors</h2>
          <p className="mt-1 text-sm text-neutral-500">Manage available colors for product variants (e.g., Black, White, Navy).</p>

          <form onSubmit={handleCreateColor} className="mt-4 flex gap-2">
            <input
              type="text"
              value={colorName}
              onChange={(e) => setColorName(e.target.value)}
              placeholder="New color (e.g., Black)"
              className="flex-1 rounded-lg border border-neutral-300 px-3 py-2 text-sm focus:border-neutral-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-neutral-900 focus-visible:ring-offset-1"
              maxLength={50}
            />
            <button
              type="submit"
              disabled={colorSubmitting}
              className="shrink-0 rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-700 disabled:opacity-50"
            >
              {colorSubmitting ? "Adding…" : "Add"}
            </button>
          </form>
          {colorError && (
            <p className="mt-2 text-sm text-red-600" role="alert">
              {colorError}
            </p>
          )}

          <div className="mt-6">
            {colorsLoading ? (
              <p className="text-sm text-neutral-500">Loading colors…</p>
            ) : colors.length === 0 ? (
              <p className="text-sm text-neutral-500">No colors yet. Add one above.</p>
            ) : (
              <>
                <div className="hidden overflow-x-auto rounded-lg border border-neutral-200 md:block">
                  <table className="w-full text-left text-sm">
                    <thead className="border-b border-neutral-200 bg-neutral-50">
                      <tr>
                        <th className="px-4 py-3 font-medium">Name</th>
                        <th className="px-4 py-3 font-medium">Created</th>
                        <th className="px-4 py-3 font-medium">Actions</th>
                      </tr>
                    </thead>
                    <tbody>
                      {colors.map((color) => (
                        <tr key={color.id} className="border-b border-neutral-100">
                          <td className="px-4 py-3 font-medium">{color.name}</td>
                          <td className="px-4 py-3 text-neutral-600">
                            {new Date(color.created_at).toLocaleDateString()}
                          </td>
                          <td className="px-4 py-3">
                            <button
                              onClick={() => handleDeleteColor(color.id, color.name)}
                              className="whitespace-nowrap text-red-600 hover:underline"
                            >
                              Delete
                            </button>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
                <div className="grid gap-3 md:hidden">
                  {colors.map((color) => (
                    <div key={color.id} className="flex items-center justify-between rounded-lg border border-neutral-200 bg-white p-4">
                      <div>
                        <p className="font-medium">{color.name}</p>
                        <p className="text-xs text-neutral-500">{new Date(color.created_at).toLocaleDateString()}</p>
                      </div>
                      <button
                        onClick={() => handleDeleteColor(color.id, color.name)}
                        className="rounded-md bg-red-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-red-700"
                      >
                        Delete
                      </button>
                    </div>
                  ))}
                </div>
              </>
            )}
          </div>
        </div>
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
