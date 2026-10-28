# Frontend UI — Form Design System

> Single source of truth for form controls and buttons. Applies to every Vue page. Presentation only — components deliberately preserve the existing `useForm`/`v-model`/payload behaviour.

## Components (`resources/js/Components/Form/`)

| Component | Purpose |
|---|---|
| `TextInput` | Text/email/password/number/url/search inputs. Full field (label → control → description/error). Supports `v-model.number` via `modelModifiers`; `#trailing` slot (password visibility toggles); `#action` slot right of the label (copy buttons); `#label` slot for inline label hints. |
| `TextArea` | Multi-line text. Vertical resize only (`resize-y`), app-standard padding/leading. Same slots/props as `TextInput`. |
| `SelectInput` | Native `<select>` with a custom chevron, aligned heights with text inputs. Options passed as the default slot. |
| `FileInput` | Native file input with an app-styled choose-file button; `id`/`accept`/`@change` pass straight through. |
| `CheckboxInput` | Single checkbox (boolean or array model with the `value` prop). Label wraps the control (clickable). |
| `CheckboxGroup` | Fieldset + legend for a group of checkboxes bound to an array model (`options: [{value,label}]`). |
| `FormField` | Shared field layout (label row + control slot + description/error). Used internally by the controls; usable for custom fields. |
| `FieldError` | Validation message with a warning icon and `role="alert"` (not colour alone). |
| `FieldDescription` | Helper text below a control. |
| `BaseButton` | `<button>` with `variant` (`primary`, `secondary`, `outline`, `danger`, `ghost`) and `size` (`sm`, `md`); attrs fall through (`type="submit"`, `disabled`, `@click`, `title`). |
| `buttonVariants.js` | The same class tokens as a function, for `<Link>`/`<a>` elements styled as buttons. |

## Design tokens (`resources/css/app.css`, `@layer components`)

- `.field-control` — control base: `rounded-lg border border-gray-300 px-3.5 py-2.5 text-sm shadow-xs`, placeholder `text-gray-400`, hover `border-gray-400`, focus `border-indigo-500` + `ring-2 ring-indigo-500/25`, disabled `bg-gray-50 text-gray-500 cursor-not-allowed`. One control height everywhere.
- `.field-control--textarea` — `py-3 leading-6 resize-y`.
- `.field-control--select` — `appearance-none` + inline SVG chevron + `pr-10`.
- `.field-control--file` — styled `::file-selector-button` (indigo-50 → indigo-100 hover), `min-w-0` so long filenames never overflow.
- `.field-checkbox` — `h-4 w-4 accent-indigo-600` + visible keyboard focus ring.
- `.field-label` / `.field-check-label` / `.field-hint` / `.field-error` — label, checkbox-label row, helper text, error text tokens.

Do not style native controls with bare element selectors (this would restyle `<audio>`/media widgets and third-party markup); apply the classes via the components.

## Conventions

- Labels: `text-sm font-medium text-gray-900`; gap label→control `mt-1.5`; control→message `mt-2`.
- Required fields show a `*` (`aria-hidden`) in addition to the native `required` attribute.
- Numeric duration fields use `class="w-full sm:w-40"` (full width on mobile, fixed on `sm+`).
- Grids: one column by default, `md:grid-cols-*` for multi-column where width allows; long values live in `min-w-0` containers.
- Buttons: primary for the main action of a section, `outline` for secondary AI actions, `secondary` for navigation/cancel-as-link, `ghost` for in-form cancel, `danger` for destructive standalone buttons; inline row actions stay text links (`text-sm font-medium` + colour).
- Errors render via `FieldError` (icon + `role="alert"`) directly below their control; page-level error banners keep the existing `rounded-md border-red-200 bg-red-50` alert style.
- Dark mode is not supported (no theme tokens defined).
