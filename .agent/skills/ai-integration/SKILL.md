---
name: ai-integration
description: Add AI capabilities with explicit boundaries, privacy controls, resilience, and verifiable output.
---

# AI Integration

Define the user value, the non-AI alternative, provider and model, connection path, prompt layers, context sources, storage, privacy requirements, costs, limits, and fallback behavior.

Use a provider abstraction when multiple providers or future replacement matter. Keep secrets out of source control, separate system and user instructions, validate inputs and outputs, cite retrieval sources when applicable, constrain token and request budgets, and handle timeouts and provider failures.

Test malformed input, unavailable providers, unsafe output, hallucination risk, missing sources, rate limits, and degraded behavior.
