---
name: motion-design
description: Plan, implement, and verify purposeful interface motion without sacrificing accessibility, performance, lifecycle safety, or project scope.
---

# Motion Design

Use this skill when a project needs animation, transitions, scroll behavior, micro-interactions, animated state changes, or motion critique.

## Principles

- Motion must clarify hierarchy, state, cause and effect, continuity, or feedback.
- Inspect the existing stack and conventions before choosing a motion technology.
- Prefer the smallest mechanism that satisfies the interaction: CSS first for simple transitions, the Web Animations API or project-native tools when appropriate, and a library such as GSAP only when runtime control, sequencing, scroll linkage, or complex coordination justifies it.
- Do not introduce a motion library only for decorative effects.
- Keep content, controls, focus, and semantics usable when motion is disabled.

## Motion Specification

For each meaningful animation, define:

- trigger and user intent;
- target element and scope;
- initial and final states;
- duration, easing, and sequencing;
- interruption and repeated-trigger behavior;
- reduced-motion behavior;
- responsive and touch behavior;
- cleanup and lifecycle ownership;
- verification method.

## Accessibility

- Respect `prefers-reduced-motion` and provide an equivalent non-motion state.
- Never hide essential information only inside an animation.
- Keep focus visible and predictable through transitions.
- Avoid rapid flashing, disorienting parallax, and motion that blocks task completion.
- Verify keyboard, screen-reader, touch, and reduced-motion paths when relevant.

## Performance and Lifecycle

- Prefer transform and opacity for movement and fades when appropriate.
- Avoid layout-heavy animation when a transform can achieve the same result.
- Batch or reuse animations for repeated updates; do not create new tweens every frame.
- Scope selectors and references to the component or surface.
- Clean up listeners, timelines, observers, and scroll triggers when elements unmount or routes change.
- Recalculate scroll positions only after relevant layout changes.
- Remove development markers and debugging tools before production.

## Scope Gate

Do not add a new animation library, gesture system, smooth-scroll system, or visual effect without confirmation when it materially expands dependencies, behavior, or verification scope.

## Output

Report the selected mechanism, why it fits, the motion specification, accessibility behavior, performance risks, cleanup plan, verification status, and unverified runtime assumptions.
