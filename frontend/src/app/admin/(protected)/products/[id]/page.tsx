import type { Metadata } from "next";

import ProductForm from "../product-form";

export const metadata: Metadata = { title: "Edit Product" };

export default async function EditProductPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;
  return <ProductForm productId={parseInt(id, 10)} />;
}
