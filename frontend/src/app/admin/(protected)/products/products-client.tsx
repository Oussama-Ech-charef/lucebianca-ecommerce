"use client";

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";

import {
  adminRequest,
  clearAdminSession,
  getAdminSession,
} from "@/lib/admin";

type Product = {
  id: number;
  name: string;
  slug: string;
  base_price: string;
  sale_price: string | null;
  is_active: number;
  category_id: number | null;
  created_at: string;
};

type ProductsResponse = {
  data: Product[];
  meta: {
    page: number;
    per_page: number;
    total: number;
    pages: number;
  };
};

export default function ProductsClient() {
  const router = useRouter();
  const [products, setProducts] = useState<Product[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [page, setPage] = useState(1);
  const [meta, setMeta] = useState<ProductsResponse["meta"] | null>(null);
  const [viewProduct, setViewProduct] = useState<Product | null>(null);
  const [viewDetail, setViewDetail] = useState<
    (Product & { description: string | null; variants: { id: number; size: string; color: string; sku: string; price: string | null; stock_quantity: number }[] }) | null
  >(null);
  const [viewLoading, setViewLoading] = useState(false);

  const sessionExpired = useCallback(() => {
    setError("Your session has expired. Please sign in again.");
    clearAdminSession();
    router.replace("/admin/login");
  }, [router]);

  // Bumped by mutation handlers to refetch with the same page.
  const [reloadKey, setReloadKey] = useState(0);
  const [categoriesMap, setCategoriesMap] = useState<Record<number, string>>({});

  useEffect(() => {
    fetch(`${process.env.NEXT_PUBLIC_API_URL}/api/categories`)
      .then((res) => (res.ok ? res.json() : null))
      .then((data) => {
        if (data && Array.isArray(data.data)) {
          const map: Record<number, string> = {};
          for (const c of data.data as { id: number; name: string }[]) {
            map[c.id] = c.name;
          }
          setCategoriesMap(map);
        }
      })
      .catch(() => {});
  }, []);

  const isViewOpen = viewProduct !== null;
  function openView(product: Product) {
    setViewProduct(product);
    setViewDetail(null);
    setViewLoading(true);
    const session = getAdminSession();
    if (session === null) {
      sessionExpired();
      setViewLoading(false);
      return;
    }
    adminRequest(`/api/admin/products/${product.id}`, session)
      .then(async (res) => {
        if (res.status === 401) {
          sessionExpired();
          return;
        }
        if (res.ok) {
          const data = await res.json();
          setViewDetail(data.data);
        }
      })
      .catch(() => {})
      .finally(() => setViewLoading(false));
  }
  function closeView() {
    setViewProduct(null);
    setViewDetail(null);
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

    adminRequest(`/api/admin/products?page=${page}&per_page=20`, session)
      .then(async (response) => {
        if (cancelled) return;
        if (response.status === 401) {
          sessionExpired();
          return;
        }
        if (!response.ok) {
          throw new Error("Failed to fetch products");
        }
        const result: ProductsResponse = await response.json();
        setProducts(result.data);
        setMeta(result.meta);
        setError(null);
      })
      .catch((err: unknown) => {
        if (!cancelled) {
          setError(err instanceof Error ? err.message : "Failed to load products");
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
  }, [page, reloadKey, sessionExpired]);

  async function toggleActive(productId: number, currentStatus: number) {
    const session = getAdminSession();
    if (session === null) {
      clearAdminSession();
      router.replace("/admin/login");
      return;
    }

    try {
      const response = await adminRequest(
        `/api/admin/products/${productId}`,
        session,
        {
          method: "PUT",
          body: { is_active: currentStatus === 1 ? 0 : 1 },
        },
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
      console.error("Failed to toggle product status", err);
    }
  }

  async function deleteProduct(productId: number, productName: string) {
    if (!confirm(`Delete "${productName}"? This cannot be undone.`)) {
      return;
    }

    const session = getAdminSession();
    if (session === null) {
      clearAdminSession();
      router.replace("/admin/login");
      return;
    }

    try {
      const response = await adminRequest(
        `/api/admin/products/${productId}`,
        session,
        { method: "DELETE" },
      );

      if (response.status === 401) {
        clearAdminSession();
        router.replace("/admin/login");
        return;
      }

      if (response.ok) {
        setReloadKey((key) => key + 1);
      } else if (response.status === 409) {
        alert(
          "This product has been ordered and cannot be deleted. Deactivate it instead."
        );
      }
    } catch (err) {
      console.error("Failed to delete product", err);
    }
  }

  if (loading) {
    return (
      <div className="p-6">
        <p className="text-sm text-neutral-500">Loading products...</p>
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
      <div className="mb-6 flex items-center justify-between">
        <h1 className="text-2xl font-semibold">Products</h1>
        <Link
          href="/admin/products/new"
          className="rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-700"
        >
          Add Product
        </Link>
      </div>

      {products.length === 0 ? (
        <p className="text-sm text-neutral-500">No products found.</p>
      ) : (
        <>
          <div className="hidden overflow-x-auto rounded-lg border border-neutral-200 md:block">
            <table className="w-full text-left text-sm">
              <thead className="border-b border-neutral-200 bg-neutral-50">
                <tr>
                  <th className="px-4 py-3 font-medium">Name</th>
                  <th className="px-4 py-3 font-medium">Price</th>
                  <th className="px-4 py-3 font-medium">Status</th>
                  <th className="px-4 py-3 font-medium">Actions</th>
                </tr>
              </thead>
              <tbody>
                {products.map((product) => (
                  <tr key={product.id} className="border-b border-neutral-100">
                    <td className="px-4 py-3">
                      <div>
                        <p className="font-medium">{product.name}</p>
                        <p className="text-xs text-neutral-500">
                          /{product.slug}
                        </p>
                      </div>
                    </td>
                    <td className="px-4 py-3">
                      {product.sale_price && product.sale_price !== "" && Number(product.sale_price) > 0 && Number(product.sale_price) < Number(product.base_price) ? (
                        <>
                          <span className="font-medium text-red-600">{product.sale_price} MAD</span>
                          <span className="block text-neutral-400 line-through text-sm">{product.base_price} MAD</span>
                        </>
                      ) : (
                        <span>{product.base_price} MAD</span>
                      )}
                    </td>
                    <td className="px-4 py-3">
                      <span
                        className={`inline-block rounded px-2 py-1 text-xs font-medium ${
                          product.is_active === 1
                            ? "bg-green-100 text-green-700"
                            : "bg-neutral-100 text-neutral-600"
                        }`}
                      >
                        {product.is_active === 1 ? "Active" : "Inactive"}
                      </span>
                    </td>
                    <td className="px-4 py-3">
                      <div className="flex flex-wrap items-center gap-3">
                        <button
                          onClick={() => openView(product)}
                          className="whitespace-nowrap text-neutral-600 hover:underline"
                        >
                          View
                        </button>
                        <Link
                          href={`/admin/products/${product.id}`}
                          className="whitespace-nowrap text-blue-600 hover:underline"
                        >
                          Edit
                        </Link>
                        <button
                          onClick={() =>
                            toggleActive(product.id, product.is_active)
                          }
                          className="whitespace-nowrap text-amber-600 hover:underline"
                        >
                          {product.is_active === 1 ? "Deactivate" : "Activate"}
                        </button>
                        <button
                          onClick={() => deleteProduct(product.id, product.name)}
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
            {products.map((product) => (
              <div
                key={product.id}
                className="rounded-lg border border-neutral-200 bg-white p-4"
              >
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0">
                    <p className="font-medium leading-tight">{product.name}</p>
                    <p className="mt-1 break-all text-xs text-neutral-500">
                      /{product.slug}
                    </p>
                  </div>
                  <span
                    className={`shrink-0 rounded px-2 py-1 text-xs font-medium ${
                      product.is_active === 1
                        ? "bg-green-100 text-green-700"
                        : "bg-neutral-100 text-neutral-600"
                    }`}
                  >
                    {product.is_active === 1 ? "Active" : "Inactive"}
                  </span>
                </div>
                <div className="mt-3">
                  {product.sale_price && product.sale_price !== "" && Number(product.sale_price) > 0 && Number(product.sale_price) < Number(product.base_price) ? (
                    <div className="flex flex-wrap items-baseline gap-2">
                      <span className="font-medium text-red-600">{product.sale_price} MAD</span>
                      <span className="text-neutral-400 line-through text-sm">{product.base_price} MAD</span>
                    </div>
                  ) : (
                    <p className="text-sm font-medium">{product.base_price} MAD</p>
                  )}
                </div>
                <div className="mt-4 flex flex-wrap gap-3">
                  <button
                    onClick={() => openView(product)}
                    className="rounded-md border border-neutral-300 px-3 py-1.5 text-sm font-medium text-neutral-700 hover:bg-neutral-50"
                  >
                    View
                  </button>
                  <Link
                    href={`/admin/products/${product.id}`}
                    className="rounded-md border border-neutral-300 px-3 py-1.5 text-sm font-medium text-neutral-700 hover:bg-neutral-50"
                  >
                    Edit
                  </Link>
                  <button
                    onClick={() => toggleActive(product.id, product.is_active)}
                    className="rounded-md border border-neutral-300 px-3 py-1.5 text-sm font-medium text-neutral-700 hover:bg-neutral-50"
                  >
                    {product.is_active === 1 ? "Deactivate" : "Activate"}
                  </button>
                  <button
                    onClick={() => deleteProduct(product.id, product.name)}
                    className="rounded-md bg-red-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-red-700"
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

      {isViewOpen && viewProduct && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
          <button
            type="button"
            aria-label="Close product details"
            onClick={closeView}
            className="absolute inset-0 bg-black/40"
          />
          <div className="relative max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-xl bg-white p-6 shadow-xl">
            <div className="flex items-start justify-between gap-4">
              <div>
                <h2 className="font-serif text-xl">{viewDetail?.name ?? viewProduct.name}</h2>
                <p className="mt-1 break-all text-sm text-neutral-500">/{viewDetail?.slug ?? viewProduct.slug}</p>
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

            {viewLoading ? (
              <p className="py-6 text-sm text-neutral-500">Loading details…</p>
            ) : (
              <>
                <dl className="mt-4 space-y-3 text-sm">
                  <div className="flex justify-between gap-4">
                    <dt className="text-neutral-500">Base price</dt>
                    <dd className="font-medium">{viewDetail?.base_price ?? viewProduct.base_price} MAD</dd>
                  </div>
                  {(viewDetail?.sale_price ?? viewProduct.sale_price) ? (
                    <div className="flex justify-between gap-4">
                      <dt className="text-neutral-500">Sale price</dt>
                      <dd className="font-medium text-red-600">{viewDetail?.sale_price ?? viewProduct.sale_price} MAD</dd>
                    </div>
                  ) : null}
                  <div className="flex justify-between gap-4">
                    <dt className="text-neutral-500">Status</dt>
                    <dd>
                      <span className={`rounded px-2 py-1 text-xs font-medium ${((viewDetail?.is_active ?? viewProduct.is_active) === 1) ? "bg-green-100 text-green-700" : "bg-neutral-100 text-neutral-600"}`}>
                        {(viewDetail?.is_active ?? viewProduct.is_active) === 1 ? "Active" : "Inactive"}
                      </span>
                    </dd>
                  </div>
                  <div className="flex justify-between gap-4">
                    <dt className="text-neutral-500">Category</dt>
                    <dd>
                      {(() => {
                        const cid = viewDetail?.category_id ?? viewProduct.category_id;
                        if (cid === null || cid === undefined) return "No category";
                        return categoriesMap[cid] ?? `ID ${cid}`;
                      })()}
                    </dd>
                  </div>
                  <div className="flex justify-between gap-4">
                    <dt className="text-neutral-500">Created</dt>
                    <dd>{new Date((viewDetail?.created_at ?? viewProduct.created_at)).toLocaleString()}</dd>
                  </div>
                </dl>

                {(viewDetail?.description ?? null) ? (
                  <div className="mt-4 rounded-lg border border-neutral-200 p-4">
                    <h3 className="text-xs font-medium uppercase tracking-wide text-neutral-500">Description</h3>
                    <p className="mt-2 whitespace-pre-line break-words text-sm text-neutral-700">
                      {viewDetail?.description}
                    </p>
                  </div>
                ) : null}

                <div className="mt-4">
                  <h3 className="text-xs font-medium uppercase tracking-wide text-neutral-500">
                    Variants {viewDetail?.variants ? `(${viewDetail.variants.length})` : ""}
                  </h3>
                  {viewDetail?.variants && viewDetail.variants.length > 0 ? (
                    <ul className="mt-2 divide-y divide-neutral-100 rounded-lg border border-neutral-200">
                      {viewDetail.variants.map((v) => (
                        <li key={v.id} className="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-sm">
                          <span>
                            {v.size} · {v.color} · <span className="font-mono text-xs">{v.sku}</span>
                          </span>
                          <span className="flex items-center gap-2">
                            <span className="font-medium">{v.price ? `${v.price} MAD` : "—"}</span>
                            <span className="text-neutral-500">Stock: {v.stock_quantity}</span>
                          </span>
                        </li>
                      ))}
                    </ul>
                  ) : (
                    <p className="mt-2 text-sm text-neutral-500">
                      {viewLoading ? "Loading…" : "No variants."}
                    </p>
                  )}
                </div>
              </>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
