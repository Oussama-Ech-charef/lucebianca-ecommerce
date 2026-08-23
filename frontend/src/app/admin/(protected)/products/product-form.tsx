"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import Link from "next/link";

import type { Product } from "@/lib/types";

type Variant = {
  size: string;
  color: string;
  sku: string;
  price: string;
  stock_quantity: number;
};

type AdminProductsResponse = {
  data: Product[];
};

type ProductFormProps = {
  productId?: number;
};

export default function ProductForm({ productId }: ProductFormProps) {
  const router = useRouter();
  const isEdit = productId !== undefined;

  const [name, setName] = useState("");
  const [slug, setSlug] = useState("");
  const [description, setDescription] = useState("");
  const [basePrice, setBasePrice] = useState("");
  const [categoryId, setCategoryId] = useState("");
  const [isActive, setIsActive] = useState(true);
  const [metaTitle, setMetaTitle] = useState("");
  const [metaDescription, setMetaDescription] = useState("");
  const [variants, setVariants] = useState<Variant[]>([]);

  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});

  useEffect(() => {
    if (productId === undefined) {
      return;
    }

    async function fetchProduct() {
      const token = localStorage.getItem("lucebianca:admin:session:v1");
      if (!token) return;

      try {
        const response = await fetch(
          `${process.env.NEXT_PUBLIC_API_URL}/api/admin/products?page=1&per_page=100`,
          {
            headers: { Authorization: `Bearer ${token}` },
          }
        );

        if (response.ok) {
          const result: AdminProductsResponse = await response.json();
          const product = result.data.find((p) => p.id === productId);

          if (product) {
            setName(product.name || "");
            setSlug(product.slug || "");
            setDescription(product.description || "");
            setBasePrice(product.base_price || "");
            setCategoryId(product.category_id?.toString() || "");
            setIsActive(product.is_active === 1);
            setMetaTitle(product.meta_title || "");
            setMetaDescription(product.meta_description || "");
          }
        }
      } catch (err) {
        console.error("Failed to fetch product", err);
      }
    }

    fetchProduct();
  }, [productId]);

  function addVariant() {
    setVariants([
      ...variants,
      { size: "", color: "", sku: "", price: "", stock_quantity: 0 },
    ]);
  }

  function removeVariant(index: number) {
    setVariants(variants.filter((_, i) => i !== index));
  }

  function updateVariant(index: number, field: keyof Variant, value: string | number) {
    const updated = [...variants];
    updated[index] = { ...updated[index], [field]: value };
    setVariants(updated);
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setLoading(true);
    setError(null);
    setErrors({});

    const token = localStorage.getItem("lucebianca:admin:session:v1");
    if (!token) {
      setError("Not authenticated");
      setLoading(false);
      return;
    }

    const payload: {
      name: string;
      slug?: string;
      description: string | null;
      base_price: string;
      category_id: string | null;
      is_active: 0 | 1;
      meta_title: string;
      meta_description: string;
      variants?: Variant[];
    } = {
      name,
      slug: slug || undefined,
      description: description || null,
      base_price: basePrice,
      category_id: categoryId || null,
      is_active: isActive ? 1 : 0,
      meta_title: metaTitle,
      meta_description: metaDescription,
    };

    if (!isEdit && variants.length > 0) {
      payload.variants = variants;
    }

    try {
      const url = isEdit
        ? `${process.env.NEXT_PUBLIC_API_URL}/api/admin/products/${productId}`
        : `${process.env.NEXT_PUBLIC_API_URL}/api/admin/products`;

      const response = await fetch(url, {
        method: isEdit ? "PUT" : "POST",
        headers: {
          "Content-Type": "application/json",
          Authorization: `Bearer ${token}`,
        },
        body: JSON.stringify(payload),
      });

      if (response.ok) {
        router.push("/admin/products");
      } else if (response.status === 422) {
        const result = await response.json();
        setErrors(result.errors || {});
        setError("Validation failed. Please check the form.");
      } else if (response.status === 409) {
        setError("Slug or SKU already exists.");
      } else {
        setError("Failed to save product.");
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to save product");
    } finally {
      setLoading(false);
    }
  }

  const inputClass =
    "w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm focus:border-neutral-900 focus:outline-none";
  const labelClass = "mb-1.5 block text-sm font-medium text-neutral-700";

  return (
    <div className="p-6">
      <div className="mb-6">
        <Link
          href="/admin/products"
          className="text-sm text-blue-600 hover:underline"
        >
          ← Back to Products
        </Link>
      </div>

      <h1 className="mb-6 text-2xl font-semibold">
        {isEdit ? "Edit Product" : "New Product"}
      </h1>

      {error && (
        <div className="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
          {error}
        </div>
      )}

      <form onSubmit={handleSubmit} className="max-w-3xl space-y-6">
        <div>
          <label htmlFor="name" className={labelClass}>
            Product Name *
          </label>
          <input
            id="name"
            type="text"
            value={name}
            onChange={(e) => setName(e.target.value)}
            className={inputClass}
            required
          />
          {errors.name && <p className="mt-1 text-xs text-red-600">{errors.name}</p>}
        </div>

        <div>
          <label htmlFor="slug" className={labelClass}>
            Slug (leave empty to auto-generate)
          </label>
          <input
            id="slug"
            type="text"
            value={slug}
            onChange={(e) => setSlug(e.target.value)}
            className={inputClass}
          />
          {errors.slug && <p className="mt-1 text-xs text-red-600">{errors.slug}</p>}
        </div>

        <div>
          <label htmlFor="description" className={labelClass}>
            Description
          </label>
          <textarea
            id="description"
            rows={4}
            value={description}
            onChange={(e) => setDescription(e.target.value)}
            className={inputClass}
          />
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div>
            <label htmlFor="base_price" className={labelClass}>
              Base Price (MAD) *
            </label>
            <input
              id="base_price"
              type="text"
              value={basePrice}
              onChange={(e) => setBasePrice(e.target.value)}
              className={inputClass}
              required
            />
            {errors.base_price && (
              <p className="mt-1 text-xs text-red-600">{errors.base_price}</p>
            )}
          </div>

          <div>
            <label htmlFor="category_id" className={labelClass}>
              Category ID (optional)
            </label>
            <input
              id="category_id"
              type="text"
              value={categoryId}
              onChange={(e) => setCategoryId(e.target.value)}
              className={inputClass}
            />
          </div>
        </div>

        <div>
          <label htmlFor="meta_title" className={labelClass}>
            Meta Title *
          </label>
          <input
            id="meta_title"
            type="text"
            value={metaTitle}
            onChange={(e) => setMetaTitle(e.target.value)}
            className={inputClass}
            required
          />
          {errors.meta_title && (
            <p className="mt-1 text-xs text-red-600">{errors.meta_title}</p>
          )}
        </div>

        <div>
          <label htmlFor="meta_description" className={labelClass}>
            Meta Description *
          </label>
          <textarea
            id="meta_description"
            rows={2}
            value={metaDescription}
            onChange={(e) => setMetaDescription(e.target.value)}
            className={inputClass}
            required
          />
          {errors.meta_description && (
            <p className="mt-1 text-xs text-red-600">{errors.meta_description}</p>
          )}
        </div>

        <div>
          <label className="flex items-center gap-2">
            <input
              type="checkbox"
              checked={isActive}
              onChange={(e) => setIsActive(e.target.checked)}
              className="h-4 w-4"
            />
            <span className="text-sm font-medium">Active</span>
          </label>
        </div>

        {!isEdit && (
          <div>
            <div className="mb-3 flex items-center justify-between">
              <h2 className="text-lg font-medium">Variants</h2>
              <button
                type="button"
                onClick={addVariant}
                className="rounded bg-neutral-100 px-3 py-1 text-sm hover:bg-neutral-200"
              >
                Add Variant
              </button>
            </div>

            {variants.map((variant, index) => (
              <div
                key={index}
                className="mb-4 rounded-lg border border-neutral-200 p-4"
              >
                <div className="mb-2 flex items-center justify-between">
                  <p className="text-sm font-medium">Variant {index + 1}</p>
                  <button
                    type="button"
                    onClick={() => removeVariant(index)}
                    className="text-sm text-red-600 hover:underline"
                  >
                    Remove
                  </button>
                </div>

                <div className="grid grid-cols-2 gap-3">
                  <input
                    type="text"
                    placeholder="Size (e.g., M)"
                    value={variant.size}
                    onChange={(e) => updateVariant(index, "size", e.target.value)}
                    className={inputClass}
                  />
                  <input
                    type="text"
                    placeholder="Color"
                    value={variant.color}
                    onChange={(e) => updateVariant(index, "color", e.target.value)}
                    className={inputClass}
                  />
                  <input
                    type="text"
                    placeholder="SKU"
                    value={variant.sku}
                    onChange={(e) => updateVariant(index, "sku", e.target.value)}
                    className={inputClass}
                  />
                  <input
                    type="text"
                    placeholder="Price (optional)"
                    value={variant.price}
                    onChange={(e) => updateVariant(index, "price", e.target.value)}
                    className={inputClass}
                  />
                  <input
                    type="number"
                    placeholder="Stock"
                    value={variant.stock_quantity}
                    onChange={(e) =>
                      updateVariant(index, "stock_quantity", parseInt(e.target.value, 10) || 0)
                    }
                    className={inputClass}
                  />
                </div>
                {errors[`variants.${index}.size`] && (
                  <p className="mt-1 text-xs text-red-600">
                    {errors[`variants.${index}.size`]}
                  </p>
                )}
              </div>
            ))}
          </div>
        )}

        <div className="flex gap-3">
          <button
            type="submit"
            disabled={loading}
            className="rounded-lg bg-neutral-900 px-6 py-2 text-sm font-medium text-white hover:bg-neutral-700 disabled:opacity-50"
          >
            {loading ? "Saving..." : isEdit ? "Update Product" : "Create Product"}
          </button>
          <Link
            href="/admin/products"
            className="rounded-lg border border-neutral-300 px-6 py-2 text-sm font-medium hover:bg-neutral-50"
          >
            Cancel
          </Link>
        </div>
      </form>
    </div>
  );
}
