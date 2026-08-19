import type { Metadata } from "next";

import OrdersClient from "./orders-client";

export const metadata: Metadata = {
  title: "Orders",
};

export default function AdminOrdersPage() {
  return <OrdersClient />;
}