import type { Metadata } from "next";

import CategoriesClient from "./categories-client";

export const metadata: Metadata = { title: "Categories" };

export default function CategoriesPage() {
  return <CategoriesClient />;
}
