import type { Metadata } from "next";

import MessagesClient from "./messages-client";

export const metadata: Metadata = { title: "Messages" };

export default function MessagesPage() {
  return <MessagesClient />;
}
