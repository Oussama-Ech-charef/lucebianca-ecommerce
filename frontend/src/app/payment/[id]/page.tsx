import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Complete card payment",
  robots: { index: false, follow: false },
};

export default async function PaymentPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;
  const { default: PaymentView } = await import("./payment-view");
  return <PaymentView orderId={id} />;
}
