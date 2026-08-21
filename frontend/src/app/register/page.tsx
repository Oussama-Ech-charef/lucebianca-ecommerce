import type { Metadata } from "next";

import RegisterView from "./register-view";

export const metadata: Metadata = { title: "Create an account" };

export default function RegisterPage() {
  return <RegisterView />;
}