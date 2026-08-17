"use client";

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
        <p className="text-xs uppercase tracking-[0.4em] text-[#C9A227]">
          Premium custom t-shirts · Casablanca
        </p>
        <h1 className="mt-6 max-w-3xl font-serif text-5xl leading-[1.05] tracking-tight sm:text-6xl lg:text-7xl">
          Casual luxury,
          <br />
          <span className="italic text-[#C9A227]">made to be worn in.</span>
        </h1>
        <p className="mt-6 max-w-xl text-base text-neutral-300 sm:text-lg">
          Classic fits, original artwork and fabrics you can feel the moment
          you put them on.
        </p>
        <div className="mt-10 flex flex-wrap items-center gap-4">
          <Link
            href="/shop"
            className="rounded-[2px] bg-white px-8 py-3.5 text-sm font-medium tracking-wide text-neutral-950 transition-colors hover:bg-[#C9A227] hover:text-white"
          >
            Shop now
          </Link>
        </div>
      </motion.div>

      <div
        aria-hidden="true"
        className="absolute bottom-0 left-1/2 z-10 hidden -translate-x-1/2 items-center flex-col pb-8 text-neutral-500 md:flex"
      >
        <span className="h-12 w-px bg-neutral-600" />
        <span className="mt-3 text-[10px] uppercase tracking-[0.35em]">
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
      <p className="pointer-events-none absolute -bottom-24 -right-10 select-none font-serif text-[16rem] leading-none text-white/[0.06] sm:text-[28rem]">
        Luce
      </p>
    </div>
  );
}