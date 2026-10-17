# Motion Decision Matrix

Use the smallest mechanism that satisfies the confirmed interaction.

| Need | Prefer | Add only when justified |
|---|---|---|
| Hover, focus, color, opacity, or simple state transition | CSS transition | JavaScript when state coordination requires it |
| Keyframes or a repeating decorative effect | CSS animation | A runtime library when it must be controlled by application state |
| One-off runtime animation with no dependency | Web Animations API or project-native API | A library when browser support or coordination requires it |
| Sequenced, interruptible, reversible, or coordinated animation | Existing project animation system | GSAP or another library after confirming the dependency |
| Scroll-linked animation or pinning | Existing project scroll system | A scroll library after checking accessibility and performance |
| React animation | Existing React conventions and scoped lifecycle | `@gsap/react` or another library when the project uses it and the need is confirmed |
| Vue/Nuxt/Svelte animation | Framework lifecycle and scoped cleanup | A framework-compatible library when complexity justifies it |
| Dragging or gestures | Native pointer/touch handling when simple | A gesture library only when constraints, inertia, or cross-device behavior justify it |

## Decision Questions

1. Is motion required for comprehension, feedback, continuity, or task control?
2. Can the existing stack solve it without a new dependency?
3. What happens when motion is disabled or interrupted?
4. What are the keyboard, touch, focus, and screen-reader implications?
5. What cleanup and runtime verification are required?

Do not choose a library because it is fashionable or because a reference site used it. Record the evidence and tradeoff.
