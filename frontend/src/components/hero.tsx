"use client";

import Image from "next/image";
import Link from "next/link";
import { motion, useReducedMotion, useScroll, useTransform } from "framer-motion";
import { useRef } from "react";

/**
 * Hero — spec 3.6. The background media is a static, CSS-composed treatment
 * (no cliché gradients) rendered inside <HeroMedia />, positioned exactly
 * where a <video> element can later slot in (autoplay/muted/loop) without
 * touching the parallax or foreground markup.
 */
export default function Hero() {
  const sectionRef = useRef<HTMLElement>(null);
  const prefersReducedMotion = useReducedMotion();
  const { scrollYProgress } = useScroll({
    target: sectionRef,
    offset: ["start start", "end start"],
  });

  // Background drifts slower than the foreground text (light parallax).
  const backgroundY = useTransform(
    scrollYProgress,
    [0, 1],
    prefersReducedMotion ? [0, 0] : [0, 140],
  );
  const contentY = useTransform(
    scrollYProgress,
    [0, 1],
    prefersReducedMotion ? [0, 0] : [0, -80],
  );
  const contentOpacity = useTransform(
    scrollYProgress,
    [0, 0.6],
    prefersReducedMotion ? [1, 1] : [1, 0],
  );

  return (
    <section
      ref={sectionRef}
      className="relative flex min-h-[90vh] items-center overflow-hidden bg-neutral-950 text-white"
    >
      <motion.div style={{ y: backgroundY }} className="absolute inset-0">
        <HeroMedia />
      </motion.div>

      <motion.div
        style={{ y: contentY, opacity: contentOpacity }}
        className="relative z-10 mx-auto w-full max-w-6xl px-4 py-24 sm:px-6 lg:px-8"
      >
        <p className="bg-gradient-to-r from-white via-chrome-200 to-chrome-400 bg-clip-text text-xs uppercase tracking-[0.4em] text-transparent">
          Premium custom t-shirts · Casablanca
        </p>
        <h1 className="mt-6 max-w-3xl font-serif text-5xl leading-[1.05] tracking-tight sm:text-6xl lg:text-7xl">
          Casual luxury,
          <br />
          <span className="bg-gradient-to-br from-white via-chrome-200 to-chrome-500 bg-clip-text italic text-transparent">
            made to be worn in.
          </span>
        </h1>
        <p className="mt-6 max-w-xl text-base text-neutral-300 sm:text-lg">
          Classic fits, original artwork and fabrics you can feel the moment
          you put them on.
        </p>
        <div className="mt-10 flex flex-wrap items-center gap-4">
          <Link
            href="/shop"
            className="rounded-[2px] bg-white px-8 py-3.5 text-sm font-medium tracking-wide text-neutral-950 transition-colors hover:bg-gradient-to-b hover:from-chrome-200 hover:to-chrome-600 hover:text-white"
          >
            Shop now
          </Link>
        </div>
      </motion.div>

      <div
        aria-hidden="true"
        className="absolute bottom-0 left-1/2 z-10 hidden -translate-x-1/2 items-center flex-col pb-8 md:flex"
      >
        <span className="h-12 w-px bg-chrome-600" />
        <span className="mt-3 text-[10px] uppercase tracking-[0.35em] text-chrome-400">
          Scroll
        </span>
      </div>
    </section>
  );
}

function HeroMedia() {
  return (
    <div className="absolute inset-0">
      <div className="absolute inset-0 bg-neutral-950" />
      <span className="absolute left-6 top-14 hidden h-28 w-px bg-white/15 sm:block" />
      <span className="absolute right-6 top-14 hidden h-28 w-px bg-white/15 sm:block" />
      <span className="absolute bottom-16 left-6 hidden w-28 h-px bg-white/15 sm:block" />
      <Image
        src="/logo.png"
        alt=""
        width={1536}
        height={1024}
        sizes="(min-width: 640px) 72rem, 26rem"
        className="pointer-events-none absolute -bottom-24 -right-20 h-80 w-[26rem] select-none object-cover opacity-15 sm:-bottom-48 sm:-right-24 sm:h-[48rem] sm:w-[72rem]"
      />
    </div>
  );
}