"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import Link from "next/link";

import type { Product, ProductImage } from "@/lib/types";
import type { Category } from "@/lib/types";
import {
  adminRequest,
  clearAdminSession,
  getAdminSession,
} from "@/lib/admin";
import { fetchCsrfToken } from "@/lib/api";

type Variant = {
  id?: number;
  size: string;
  color: string;
  sku: string;
  price: string;
  stock_quantity: number;
};

type AdminProductsResponse = {
  data: Product[];
};

type ProductDetailResponse = {
  data: Product & { variants: Variant[]; images?: ProductImage[] };
};

type ProductFormProps = {
  productId?: number;
};

const MAX_IMAGE_BYTES = 5 * 1024 * 1024;
const ALLOWED_MIME_TYPES = ["image/jpeg", "image/png", "image/webp", "image/gif"];
const API_BASE = process.env.NEXT_PUBLIC_API_URL ?? "http://127.0.0.1:8090";

export default function ProductForm({ productId }: ProductFormProps) {
  const router = useRouter();
  const isEdit = productId !== undefined;

  const [name, setName] = useState("");
  const [slug, setSlug] = useState("");
  const [description, setDescription] = useState("");
  const [basePrice, setBasePrice] = useState("");
  const [salePrice, setSalePrice] = useState("");
  const [categoryId, setCategoryId] = useState("");
  const [categories, setCategories] = useState<Category[]>([]);
  const [availableSizes, setAvailableSizes] = useState<string[]>([]);
  const [availableColors, setAvailableColors] = useState<string[]>([]);
  const [isActive, setIsActive] = useState(true);
  const [metaTitle, setMetaTitle] = useState("");
  const [metaDescription, setMetaDescription] = useState("");
  const [variants, setVariants] = useState<Variant[]>([]);
  const [existingVariants, setExistingVariants] = useState<Variant[]>([]);
  const [newVariant, setNewVariant] = useState<Variant>({
    size: "",
    color: "",
    sku: "",
    price: "",
    stock_quantity: 0,
  });
  const [showNewVariantForm, setShowNewVariantForm] = useState(false);

  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [variantErrors, setVariantErrors] = useState<Record<string, string>>({});

  // --- Image upload state ---
  const [existingImages, setExistingImages] = useState<ProductImage[]>([]);
  const [selectedFiles, setSelectedFiles] = useState<File[]>([]);
  const [selectedPreviews, setSelectedPreviews] = useState<string[]>([]);
  const [mainSelectedIndex, setMainSelectedIndex] = useState<number | null>(null);
  const [imageUploading, setImageUploading] = useState(false);
  const [imageError, setImageError] = useState<string | null>(null);
  const [imageSuccess, setImageSuccess] = useState<string | null>(null);

  // Preview object URLs for selected files — revokes on change/unmount to avoid leaks
  useEffect(() => {
    if (selectedFiles.length === 0) {
      // eslint-disable-next-line react-hooks/set-state-in-effect -- clearing derived preview urls when files empty
      setSelectedPreviews([]);
      return;
    }
    const urls = selectedFiles.map((file) => URL.createObjectURL(file));
    setSelectedPreviews(urls);
    return () => {
      urls.forEach((url) => URL.revokeObjectURL(url));
    };
  }, [selectedFiles]);

  function handleFilesSelected(event: React.ChangeEvent<HTMLInputElement>) {
    const files = Array.from(event.target.files ?? []);
    // Reset input so same file can be re-selected after removal
    event.target.value = "";
    if (files.length === 0) return;

    setImageSuccess(null);
    const valid: File[] = [];
    const rejected: string[] = [];

    for (const file of files) {
      if (file.size === 0) {
        rejected.push(`${file.name}: empty file`);
        continue;
      }
      if (file.size > MAX_IMAGE_BYTES) {
        rejected.push(`${file.name}: exceeds 5 MB`);
        continue;
      }
      const extension = file.name.split(".").pop()?.toLowerCase() ?? "";
      const allowedExtensions = ["jpg", "jpeg", "png", "webp", "gif"];
      const mimeAllowed = ALLOWED_MIME_TYPES.includes(file.type);
      const extAllowed = allowedExtensions.includes(extension);
      // Strict MIME check; fall back to extension when browser omits MIME (e.g. type === "")
      const isAllowed = file.type ? mimeAllowed : extAllowed;
      if (!isAllowed) {
        rejected.push(`${file.name}: invalid type (allowed JPEG, PNG, WebP, GIF)`);
        continue;
      }
      valid.push(file);
    }

    if (rejected.length > 0) {
      setImageError(`Some files were rejected: ${rejected.join(", ")}`);
    } else {
      setImageError(null);
    }

    if (valid.length > 0) {
      setSelectedFiles((prev) => [...prev, ...valid]);
    }
  }

  function removeSelectedFile(index: number) {
    setSelectedFiles((prev) => prev.filter((_, i) => i !== index));
    setMainSelectedIndex((prev) => {
      if (prev === null) return null;
      if (prev === index) return null;
      if (prev > index) return prev - 1;
      return prev;
    });
    setImageError(null);
    setImageSuccess(null);
  }

  async function handleImageUpload() {
    if (!isEdit || productId === undefined) {
      setImageError("Save the product first before uploading images.");
      return;
    }
    if (selectedFiles.length === 0) {
      setImageError("Select at least one image to upload.");
      return;
    }

    const session = getAdminSession();
    if (session === null) {
      setImageError("Your session has expired. Please sign in again.");
      clearAdminSession();
      router.replace("/admin/login");
      return;
    }

    setImageUploading(true);
    setImageError(null);
    setImageSuccess(null);

    try {
      const csrfToken = await fetchCsrfToken();
      const formData = new FormData();
      selectedFiles.forEach((file) => {
        formData.append("images[]", file);
      });
      if (mainSelectedIndex !== null) {
        formData.append("is_main", String(mainSelectedIndex));
      }

      const response = await fetch(`${API_BASE}/api/admin/products/${productId}/images`, {
        method: "POST",
        headers: {
          Authorization: `Bearer ${session.token}`,
          "X-CSRF-Token": csrfToken,
        },
        body: formData,
      });

      if (response.status === 401) {
        setImageError("Your session has expired. Please sign in again.");
        clearAdminSession();
        router.replace("/admin/login");
        return;
      }

      const result = await response.json().catch(() => null);

      if (response.ok) {
        const returnedImages: ProductImage[] =
          (result?.data?.images as ProductImage[]) ?? [];
        if (returnedImages.length > 0) {
          setExistingImages((prev) => [...prev, ...returnedImages]);
        } else {
          // Fallback: re-fetch product to get fresh images
          try {
            const refetch = await adminRequest(`/api/admin/products/${productId}`, session);
            if (refetch.ok) {
              const detail: ProductDetailResponse = await refetch.json();
              if (Array.isArray(detail.data.images)) {
                setExistingImages(detail.data.images as ProductImage[]);
              }
            }
          } catch {
            // ignore
          }
        }
        setSelectedFiles([]);
        setMainSelectedIndex(null);
        setImageSuccess(
          `Uploaded ${returnedImages.length || selectedFiles.length} image(s) successfully.`
        );
      } else if (response.status === 422) {
        const errs = result?.errors as Record<string, string> | undefined;
        const firstErr = errs ? Object.values(errs)[0] : null;
        setImageError(firstErr || result?.error || "Validation failed. Check file types and sizes.");
      } else {
        setImageError(result?.error || "Failed to upload images.");
      }
    } catch (err) {
      setImageError(err instanceof Error ? err.message : "Failed to upload images.");
    } finally {
      setImageUploading(false);
    }
  }

  useEffect(() => {
    async function fetchCategories() {
      try {
        const response = await fetch(
          `${process.env.NEXT_PUBLIC_API_URL}/api/categories`
        );
        if (response.ok) {
          const result = await response.json();
          setCategories(result.data || []);
        }
      } catch (err) {
        console.error("Failed to fetch categories", err);
      }
    }
    async function fetchSizes() {
      try {
        const res = await fetch(`${process.env.NEXT_PUBLIC_API_URL}/api/sizes`);
        if (res.ok) {
          const data = await res.json();
          setAvailableSizes((data.data || []).map((s: { name: string }) => s.name));
        }
      } catch (err) {
        console.error("Failed to fetch sizes", err);
      }
    }
    async function fetchColors() {
      try {
        const res = await fetch(`${process.env.NEXT_PUBLIC_API_URL}/api/colors`);
        if (res.ok) {
          const data = await res.json();
          setAvailableColors((data.data || []).map((c: { name: string }) => c.name));
        }
      } catch (err) {
        console.error("Failed to fetch colors", err);
      }
    }
    fetchCategories();
    fetchSizes();
    fetchColors();
  }, []);

  useEffect(() => {
    if (productId === undefined) {
      return;
    }

    async function fetchProduct() {
      const session = getAdminSession();
      if (session === null) {
        setError("Your session has expired. Please sign in again.");
        clearAdminSession();
        router.replace("/admin/login");
        return;
      }

      try {
        const response = await adminRequest(
          `/api/admin/products/${productId}`,
          session
        );

        if (response.status === 401) {
          setError("Your session has expired. Please sign in again.");
          clearAdminSession();
          router.replace("/admin/login");
          return;
        }

        if (response.ok) {
          const result: ProductDetailResponse = await response.json();
          const product = result.data;
          setName(product.name || "");
          setSlug(product.slug || "");
          setDescription(product.description || "");
          setBasePrice(product.base_price || "");
          setSalePrice(product.sale_price || "");
          setCategoryId(product.category_id?.toString() || "");
          setIsActive(product.is_active === 1);
          setMetaTitle(product.meta_title || "");
          setMetaDescription(product.meta_description || "");
          if (Array.isArray(product.variants)) {
            setExistingVariants(product.variants as Variant[]);
          }
          if (Array.isArray(product.images)) {
            setExistingImages(product.images as ProductImage[]);
          }
        } else {
          // Fallback to list endpoint for backwards compatibility
          const listResponse = await adminRequest(
            "/api/admin/products?page=1&per_page=100",
            session
          );
          if (listResponse.ok) {
            const result: AdminProductsResponse = await listResponse.json();
            const product = result.data.find((p) => p.id === productId);
            if (product) {
              setName(product.name || "");
              setSlug(product.slug || "");
              setDescription(product.description || "");
              setBasePrice(product.base_price || "");
              setSalePrice(product.sale_price || "");
              setCategoryId(product.category_id?.toString() || "");
              setIsActive(product.is_active === 1);
              setMetaTitle(product.meta_title || "");
              setMetaDescription(product.meta_description || "");
            }
          }
        }
      } catch (err) {
        console.error("Failed to fetch product", err);
      }
    }

    fetchProduct();
  }, [productId, router]);

  function addVariant() {
    if (!isEdit) {
      setVariants([
        ...variants,
        { size: "", color: "", sku: "", price: "", stock_quantity: 0 },
      ]);
    } else {
      if (!showNewVariantForm) {
        setShowNewVariantForm(true);
        return;
      }
      // For edit mode, validate new variant before sending
      const newErrors: Record<string, string> = {};
      if (!newVariant.size.trim()) newErrors.size = "Size is required.";
      if (!newVariant.color.trim()) newErrors.color = "Color is required.";
      if (!newVariant.sku.trim()) newErrors.sku = "SKU is required.";
      // Check duplicates against existing + local
      const allVariants = [...existingVariants, ...variants];
      if (allVariants.some((v) => v.sku === newVariant.sku && newVariant.sku !== "")) {
        newErrors.sku = "Duplicate SKU.";
      }
      const combo = `${newVariant.size}|${newVariant.color}`;
      if (
        newVariant.size &&
        newVariant.color &&
        allVariants.some((v) => `${v.size}|${v.color}` === combo)
      ) {
        newErrors.color = "Duplicate size/color combination.";
      }
      if (Object.keys(newErrors).length > 0) {
        setVariantErrors(newErrors);
        return;
      }
      setVariantErrors({});
      handleCreateVariant();
    }
  }

  function cancelNewVariantForm() {
    setShowNewVariantForm(false);
    setNewVariant({ size: "", color: "", sku: "", price: "", stock_quantity: 0 });
    setVariantErrors({});
  }

  async function handleCreateVariant() {
    const session = getAdminSession();
    if (session === null) {
      setError("Your session has expired. Please sign in again.");
      clearAdminSession();
      router.replace("/admin/login");
      return;
    }
    try {
      const response = await adminRequest(
        `/api/admin/products/${productId}/variants`,
        session,
        {
          method: "POST",
          body: {
            size: newVariant.size,
            color: newVariant.color,
            sku: newVariant.sku,
            price: newVariant.price || null,
            stock_quantity: newVariant.stock_quantity,
          },
        }
      );
      if (response.status === 401) {
        setError("Your session has expired. Please sign in again.");
        clearAdminSession();
        router.replace("/admin/login");
        return;
      }
      if (response.ok) {
        const result = await response.json();
        setExistingVariants([...existingVariants, result.data]);
        setNewVariant({ size: "", color: "", sku: "", price: "", stock_quantity: 0 });
        setShowNewVariantForm(false);
        setVariantErrors({});
      } else if (response.status === 422) {
        const result = await response.json();
        setVariantErrors(result.errors || {});
        setError("Validation failed. Please check the variant fields.");
      } else if (response.status === 409) {
        setError("A variant SKU or size/color combination already exists.");
      } else {
        setError("Failed to create variant.");
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to create variant");
    }
  }

  function removeVariant(index: number) {
    setVariants(variants.filter((_, i) => i !== index));
  }

  function updateVariant(index: number, field: keyof Variant, value: string | number) {
    const updated = [...variants];
    updated[index] = { ...updated[index], [field]: value };
    setVariants(updated);
  }

  function updateExistingVariant(index: number, field: keyof Variant, value: string | number) {
    const updated = [...existingVariants];
    updated[index] = { ...updated[index], [field]: value };
    setExistingVariants(updated);
  }

  async function handleUpdateVariant(variant: Variant) {
    if (!variant.id) return;
    const session = getAdminSession();
    if (session === null) {
      setError("Your session has expired. Please sign in again.");
      clearAdminSession();
      router.replace("/admin/login");
      return;
    }
    // Client-side duplicate check
    const others = existingVariants.filter((v) => v.id !== variant.id);
    if (others.some((v) => v.sku === variant.sku)) {
      setError("Duplicate SKU.");
      return;
    }
    if (others.some((v) => v.size === variant.size && v.color === variant.color)) {
      setError("Duplicate size/color combination.");
      return;
    }
    try {
      const response = await adminRequest(
        `/api/admin/variants/${variant.id}`,
        session,
        {
          method: "PUT",
          body: {
            size: variant.size,
            color: variant.color,
            sku: variant.sku,
            price: variant.price || null,
            stock_quantity: variant.stock_quantity,
          },
        }
      );
      if (response.status === 401) {
        setError("Your session has expired. Please sign in again.");
        clearAdminSession();
        router.replace("/admin/login");
        return;
      }
      if (response.ok) {
        setError(null);
      } else if (response.status === 422) {
        const result = await response.json();
        setError("Validation failed. Please check the variant fields.");
        console.error(result.errors);
      } else if (response.status === 409) {
        setError("A variant SKU or size/color combination already exists.");
      } else {
        setError("Failed to update variant.");
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to update variant");
    }
  }

  async function handleDeleteVariant(variant: Variant) {
    if (!variant.id) {
      // Local-only variant (should not happen in edit mode)
      setExistingVariants(existingVariants.filter((v) => v.id !== variant.id));
      return;
    }
    if (!confirm(`Delete variant ${variant.size} / ${variant.color} (${variant.sku})?`)) return;
    const session = getAdminSession();
    if (session === null) {
      setError("Your session has expired. Please sign in again.");
      clearAdminSession();
      router.replace("/admin/login");
      return;
    }
    try {
      const response = await adminRequest(
        `/api/admin/variants/${variant.id}`,
        session,
        { method: "DELETE" }
      );
      if (response.status === 401) {
        setError("Your session has expired. Please sign in again.");
        clearAdminSession();
        router.replace("/admin/login");
        return;
      }
      if (response.status === 204 || response.ok) {
        setExistingVariants(existingVariants.filter((v) => v.id !== variant.id));
      } else if (response.status === 409) {
        setError("Variant has been ordered and cannot be deleted.");
      } else {
        setError("Failed to delete variant.");
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to delete variant");
    }
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setLoading(true);
    setError(null);
    setErrors({});

    const session = getAdminSession();
    if (session === null) {
      setError("Your session has expired. Please sign in again.");
      clearAdminSession();
      router.replace("/admin/login");
      setLoading(false);
      return;
    }

    const payload: {
      name: string;
      slug?: string;
      description: string | null;
      base_price: string;
      sale_price: string | null;
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
      sale_price: salePrice || null,
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
        ? `/api/admin/products/${productId}`
        : "/api/admin/products";

      const response = await adminRequest(url, session, {
        method: isEdit ? "PUT" : "POST",
        body: payload,
      });

      if (response.status === 401) {
        setError("Your session has expired. Please sign in again.");
        clearAdminSession();
        router.replace("/admin/login");
        return;
      }

      if (response.ok) {
        const result = await response.json().catch(() => null);
        if (!isEdit && result?.data?.id) {
          router.push(`/admin/products/${result.data.id}`);
        } else {
          router.push("/admin/products");
        }
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
    "w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm focus:border-neutral-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-neutral-900 focus-visible:ring-offset-1";
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
            <label htmlFor="sale_price" className={labelClass}>
              Sale Price (MAD) <span className="text-neutral-400 text-sm">(optional)</span>
            </label>
            <input
              id="sale_price"
              type="text"
              value={salePrice}
              onChange={(e) => setSalePrice(e.target.value)}
              className={inputClass}
              placeholder="Must be less than base price"
            />
            {errors.sale_price && (
              <p className="mt-1 text-xs text-red-600">{errors.sale_price}</p>
            )}
            <p className="mt-1 text-xs text-neutral-500">
              Must be greater than 0 and less than base price
            </p>
          </div>
        </div>

        <div>
          <label htmlFor="category_id" className={labelClass}>
            Category
          </label>
          <select
            id="category_id"
            value={categoryId}
            onChange={(e) => setCategoryId(e.target.value)}
            className="w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm focus:border-neutral-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-neutral-900 focus-visible:ring-offset-1"
          >
            <option value="">No category</option>
            {categories.map((category) => (
              <option key={category.id} value={category.id}>
                {category.name}
              </option>
            ))}
          </select>
          {errors.category_id && (
            <p className="mt-1 text-xs text-red-600">{errors.category_id}</p>
          )}
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

        {/* Images — upload after product exists; backend uses multipart POST /api/admin/products/{id}/images */}
        <div>
          <h2 className="text-lg font-medium">Images</h2>
          <p className="mt-1 text-xs text-neutral-500">
            JPEG, PNG, WebP, GIF — max 5 MB per image. First upload becomes the main image if none exists.
          </p>

          {!isEdit ? (
            <div className="mt-3 rounded-lg border border-dashed border-neutral-300 bg-neutral-50 p-6 text-center">
              <p className="text-sm font-medium text-neutral-700">Save the product first</p>
              <p className="mt-1 text-xs text-neutral-500">
                Product images can be uploaded after the product is created.
              </p>
              <div className="mt-4">
                <label
                  htmlFor="images-disabled"
                  className="inline-flex cursor-not-allowed items-center rounded-lg border border-neutral-200 bg-white px-4 py-2 text-sm text-neutral-400"
                >
                  Choose images
                </label>
                <input
                  id="images-disabled"
                  type="file"
                  accept="image/jpeg,image/png,image/webp,image/gif,.jpg,.jpeg,.png,.webp,.gif"
                  multiple
                  disabled
                  className="hidden"
                />
              </div>
            </div>
          ) : (
            <div className="mt-3 space-y-4 rounded-lg border border-neutral-200 p-4">
              {/* Existing product images */}
              {existingImages.length > 0 && (
                <div>
                  <p className="mb-2 text-sm font-medium text-neutral-700">
                    Existing images ({existingImages.length})
                  </p>
                  <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                    {existingImages.map((img) => (
                      <div
                        key={img.id}
                        className="relative overflow-hidden rounded-lg border border-neutral-200 bg-white"
                      >
                        {/* eslint-disable-next-line @next/next/no-img-element */}
                        <img
                          src={img.image_url}
                          alt="Product image"
                          className="h-32 w-full object-cover"
                        />
                        <div className="flex items-center justify-between px-2 py-1.5">
                          <span className="text-xs text-neutral-600">Order: {img.sort_order}</span>
                          {img.is_main === 1 && (
                            <span className="rounded bg-neutral-900 px-1.5 py-0.5 text-[10px] font-medium text-white">
                              Main
                            </span>
                          )}
                        </div>
                      </div>
                    ))}
                  </div>
                </div>
              )}
              {existingImages.length === 0 && (
                <p className="text-sm text-neutral-500">No images yet. Add some below.</p>
              )}

              {/* File input */}
              <div>
                <label htmlFor="images" className={labelClass}>
                  Add images
                </label>
                <input
                  id="images"
                  type="file"
                  accept="image/jpeg,image/png,image/webp,image/gif,.jpg,.jpeg,.png,.webp,.gif"
                  multiple
                  onChange={handleFilesSelected}
                  disabled={imageUploading}
                  className="block w-full cursor-pointer rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm file:mr-3 file:rounded file:border-0 file:bg-neutral-900 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-white hover:file:bg-neutral-700 disabled:cursor-not-allowed disabled:opacity-50"
                />
                <p className="mt-1 text-xs text-neutral-500">
                  Select one or multiple images. Invalid types or files over 5 MB are rejected before upload.
                </p>
              </div>

              {/* Selected-file previews before upload */}
              {selectedFiles.length > 0 && (
                <div>
                  <p className="mb-2 text-sm font-medium text-neutral-700">
                    Selected ({selectedFiles.length}) — previews
                  </p>
                  <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                    {selectedFiles.map((file, index) => (
                      <div
                        key={`${file.name}-${index}`}
                        className="relative overflow-hidden rounded-lg border border-neutral-200 bg-white"
                      >
                        {/* eslint-disable-next-line @next/next/no-img-element */}
                        <img
                          src={selectedPreviews[index]}
                          alt={`Preview ${file.name}`}
                          className="h-32 w-full object-cover"
                        />
                        <div className="space-y-1.5 px-2 py-2">
                          <p className="truncate text-xs text-neutral-600" title={file.name}>
                            {file.name}
                          </p>
                          <p className="text-xs text-neutral-500">
                            {(file.size / 1024).toFixed(0)} KB · {file.type || "unknown type"}
                          </p>
                          <label className="flex items-center gap-1.5 text-xs font-medium text-neutral-700">
                            <input
                              type="radio"
                              name="main-image"
                              checked={mainSelectedIndex === index}
                              onChange={() => setMainSelectedIndex(index)}
                              className="h-3.5 w-3.5"
                            />
                            Main image
                          </label>
                          <button
                            type="button"
                            onClick={() => removeSelectedFile(index)}
                            className="text-xs text-red-600 hover:underline"
                          >
                            Remove
                          </button>
                        </div>
                      </div>
                    ))}
                  </div>
                </div>
              )}

              {/* Upload controls & states */}
              <div className="flex flex-wrap items-center gap-3">
                <button
                  type="button"
                  onClick={handleImageUpload}
                  disabled={imageUploading || selectedFiles.length === 0}
                  className="rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-700 disabled:cursor-not-allowed disabled:opacity-50"
                >
                  {imageUploading ? "Uploading..." : `Upload ${selectedFiles.length > 0 ? `(${selectedFiles.length})` : ""}`}
                </button>
                {selectedFiles.length > 0 && (
                  <button
                    type="button"
                    onClick={() => {
                      setSelectedFiles([]);
                      setMainSelectedIndex(null);
                      setImageError(null);
                      setImageSuccess(null);
                    }}
                    disabled={imageUploading}
                    className="rounded-lg border border-neutral-300 px-4 py-2 text-sm font-medium hover:bg-neutral-50 disabled:opacity-50"
                  >
                    Clear selection
                  </button>
                )}
                {imageUploading && (
                  <span className="text-sm text-neutral-500">Upload in progress…</span>
                )}
              </div>

              {imageError && (
                <div className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                  {imageError}
                </div>
              )}
              {imageSuccess && (
                <div className="rounded-lg border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-700">
                  {imageSuccess}
                </div>
              )}
            </div>
          )}
        </div>

        {/* Variants section — shown in both create and edit, with different behavior */}
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

          {isEdit ? (
            <>
              {existingVariants.length === 0 && (
                <p className="mb-4 text-sm text-neutral-500">No variants yet.</p>
              )}
              {existingVariants.map((variant, index) => (
                <div
                  key={variant.id || index}
                  className="mb-4 rounded-lg border border-neutral-200 p-4"
                >
                  <div className="mb-2 flex items-center justify-between">
                    <p className="text-sm font-medium">Variant {index + 1} {variant.sku && `— ${variant.sku}`}</p>
                    <button
                      type="button"
                      onClick={() => handleDeleteVariant(variant)}
                      className="text-sm text-red-600 hover:underline"
                    >
                      Remove
                    </button>
                  </div>

                  <div className="grid grid-cols-2 gap-3">
                    <select
                      value={variant.size ?? ""}
                      onChange={(e) => updateExistingVariant(index, "size", e.target.value)}
                      className={inputClass}
                    >
                      <option value="">Select size</option>
                      {[...new Set([...availableSizes, variant.size].filter(Boolean) as string[])].map((opt) => (
                        <option key={opt} value={opt}>
                          {opt}
                        </option>
                      ))}
                    </select>
                    <select
                      value={variant.color ?? ""}
                      onChange={(e) => updateExistingVariant(index, "color", e.target.value)}
                      className={inputClass}
                    >
                      <option value="">Select color</option>
                      {[...new Set([...availableColors, variant.color].filter(Boolean) as string[])].map((opt) => (
                        <option key={opt} value={opt}>
                          {opt}
                        </option>
                      ))}
                    </select>
                    <input
                      type="text"
                      placeholder="SKU"
                      value={variant.sku ?? ""}
                      onChange={(e) => updateExistingVariant(index, "sku", e.target.value)}
                      className={inputClass}
                    />
                    <input
                      type="text"
                      placeholder="Price (optional)"
                      value={variant.price ?? ""}
                      onChange={(e) => updateExistingVariant(index, "price", e.target.value)}
                      className={inputClass}
                    />
                    <input
                      type="number"
                      placeholder="Stock"
                      value={variant.stock_quantity ?? 0}
                      onChange={(e) =>
                        updateExistingVariant(index, "stock_quantity", parseInt(e.target.value, 10) || 0)
                      }
                      className={inputClass}
                    />
                  </div>
                  <div className="mt-3 flex gap-2">
                    <button
                      type="button"
                      onClick={() => handleUpdateVariant(variant)}
                      className="rounded bg-neutral-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-neutral-700"
                    >
                      Save variant
                    </button>
                  </div>
                  {errors[`variants.${index}.size`] && (
                    <p className="mt-1 text-xs text-red-600">
                      {errors[`variants.${index}.size`]}
                    </p>
                  )}
                </div>
              ))}
              {/* New variant inline form for edit mode - hidden by default */}
              {showNewVariantForm && (
                <div className="rounded-lg border border-dashed border-neutral-300 p-4">
                  <p className="mb-2 text-sm font-medium">Add new variant</p>
                  <div className="grid grid-cols-2 gap-3">
                    <select
                      value={newVariant.size ?? ""}
                      onChange={(e) => setNewVariant({ ...newVariant, size: e.target.value })}
                      className={inputClass}
                    >
                      <option value="">Select size</option>
                      {availableSizes.map((opt) => (
                        <option key={opt} value={opt}>
                          {opt}
                        </option>
                      ))}
                      {newVariant.size && !availableSizes.includes(newVariant.size) && (
                        <option value={newVariant.size}>{newVariant.size}</option>
                      )}
                    </select>
                    <select
                      value={newVariant.color ?? ""}
                      onChange={(e) => setNewVariant({ ...newVariant, color: e.target.value })}
                      className={inputClass}
                    >
                      <option value="">Select color</option>
                      {availableColors.map((opt) => (
                        <option key={opt} value={opt}>
                          {opt}
                        </option>
                      ))}
                      {newVariant.color && !availableColors.includes(newVariant.color) && (
                        <option value={newVariant.color}>{newVariant.color}</option>
                      )}
                    </select>
                    <input
                      type="text"
                      placeholder="SKU"
                      value={newVariant.sku ?? ""}
                      onChange={(e) => setNewVariant({ ...newVariant, sku: e.target.value })}
                      className={inputClass}
                    />
                    <input
                      type="text"
                      placeholder="Price (optional)"
                      value={newVariant.price ?? ""}
                      onChange={(e) => setNewVariant({ ...newVariant, price: e.target.value })}
                      className={inputClass}
                    />
                    <input
                      type="number"
                      placeholder="Stock"
                      value={newVariant.stock_quantity ?? 0}
                      onChange={(e) =>
                        setNewVariant({ ...newVariant, stock_quantity: parseInt(e.target.value, 10) || 0 })
                      }
                      className={inputClass}
                    />
                  </div>
                  {variantErrors.size && (
                    <p className="mt-1 text-xs text-red-600">{variantErrors.size}</p>
                  )}
                  {variantErrors.sku && (
                    <p className="mt-1 text-xs text-red-600">{variantErrors.sku}</p>
                  )}
                  {variantErrors.color && (
                    <p className="mt-1 text-xs text-red-600">{variantErrors.color}</p>
                  )}
                  <div className="mt-3 flex gap-2">
                    <button
                      type="button"
                      onClick={addVariant}
                      className="rounded bg-neutral-900 px-4 py-1.5 text-sm font-medium text-white hover:bg-neutral-700"
                    >
                      Add
                    </button>
                    <button
                      type="button"
                      onClick={cancelNewVariantForm}
                      className="rounded border border-neutral-300 px-4 py-1.5 text-sm font-medium hover:bg-neutral-50"
                    >
                      Cancel
                    </button>
                  </div>
                </div>
              )}
            </>
          ) : (
            <>
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
                    <select
                      value={variant.size ?? ""}
                      onChange={(e) => updateVariant(index, "size", e.target.value)}
                      className={inputClass}
                    >
                      <option value="">Select size</option>
                      {[...new Set([...availableSizes, variant.size].filter(Boolean) as string[])].map((opt) => (
                        <option key={opt} value={opt}>
                          {opt}
                        </option>
                      ))}
                    </select>
                    <select
                      value={variant.color ?? ""}
                      onChange={(e) => updateVariant(index, "color", e.target.value)}
                      className={inputClass}
                    >
                      <option value="">Select color</option>
                      {[...new Set([...availableColors, variant.color].filter(Boolean) as string[])].map((opt) => (
                        <option key={opt} value={opt}>
                          {opt}
                        </option>
                      ))}
                    </select>
                    <input
                      type="text"
                      placeholder="SKU"
                      value={variant.sku ?? ""}
                      onChange={(e) => updateVariant(index, "sku", e.target.value)}
                      className={inputClass}
                    />
                    <input
                      type="text"
                      placeholder="Price (optional)"
                      value={variant.price ?? ""}
                      onChange={(e) => updateVariant(index, "price", e.target.value)}
                      className={inputClass}
                    />
                    <input
                      type="number"
                      placeholder="Stock"
                      value={variant.stock_quantity ?? 0}
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
            </>
          )}
        </div>

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
