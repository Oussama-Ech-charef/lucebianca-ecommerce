import type { Metadata } from "next";

import ForgotPasswordView from "./forgot-password-view";

export const metadata: Metadata = {
  title: "Forgot password",
  robots: { index: false, follow: false },
};

export default function ForgotPasswordPage() {
  return <ForgotPasswordView />;
}
