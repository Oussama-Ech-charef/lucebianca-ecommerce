import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  // Dev-only: the E2E harness drives the dev server over http://127.0.0.1:3000
  // while Next serves dev resources for the localhost host by default. Next 16
  // blocks that as a cross-origin dev resource unless the origin is allowlisted.
  allowedDevOrigins: ["127.0.0.1"],
  images: {
    remotePatterns: [
      // Product images are Cloudinary URLs only (spec section 3: image storage).
      {
        protocol: "https",
        hostname: "res.cloudinary.com",
        pathname: "/**",
      },
    ],
  },
};

export default nextConfig;
