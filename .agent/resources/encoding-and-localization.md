# Encoding and Localization

Use this resource whenever the Agent reads, writes, audits, or reports multilingual project content.

## Encoding Integrity

- Treat UTF-8 as the default unless project evidence specifies another encoding.
- Preserve accents, Arabic letters, punctuation, symbols, filenames, and user-facing copy exactly.
- Detect and report mojibake such as `Ã©`, `â€™`, or replacement characters instead of treating it as valid project content.
- Verify the actual file or command output before claiming an encoding problem; terminal display alone can be misleading.
- Do not silently transliterate, translate, normalize, or replace user content.

## Localization Discovery

Inspect the project for:

- locale files and translation keys;
- supported languages and fallback behavior;
- date, time, number, currency, and timezone formatting;
- pluralization and gender rules when relevant;
- text direction and logical CSS properties;
- mixed-direction content such as names, URLs, codes, and medical terms;
- font coverage and rendering constraints.

## RTL and Mixed Content

- Use logical properties such as `margin-inline`, `padding-inline`, `inset-inline`, and `text-align: start` when the project supports RTL.
- Do not reverse icons, numbers, logos, or media unless their meaning requires it.
- Keep Latin identifiers, URLs, email addresses, codes, and numbers readable inside RTL content.
- Test navigation order, tables, forms, dialogs, focus movement, and animations in both directions when both are supported.

## Output

State the detected encoding and locale evidence, observed issues, affected files, unverified assumptions, and the exact verification method. Separate source encoding, terminal display, translation completeness, and visual rendering; they are different checks.
