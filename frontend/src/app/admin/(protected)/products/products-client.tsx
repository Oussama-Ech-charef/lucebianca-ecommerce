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

  const sessionExpired = useCallback(() => {
    setError("Your session has expired. Please sign in again.");
    clearAdminSession();
    router.replace("/admin/login");
  }, [router]);

  // Bumped by mutation handlers to refetch with the same page.
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
          <div className="overflow-x-auto rounded-lg border border-neutral-200">
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
                      <div className="flex gap-2">
                        <Link
                          href={`/admin/products/${product.id}`}
                          className="text-blue-600 hover:underline"
                        >
                          Edit
                        </Link>
                        <button
                          onClick={() =>
                            toggleActive(product.id, product.is_active)
                          }
                          className="text-amber-600 hover:underline"
                        >
                          {product.is_active === 1 ? "Deactivate" : "Activate"}
                        </button>
                        <button
                          onClick={() => deleteProduct(product.id, product.name)}
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
